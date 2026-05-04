<?php
namespace local_fastpix\service;

use local_fastpix\exception\drm_not_configured;
use local_fastpix\exception\ssrf_blocked;

defined('MOODLE_INTERNAL') || die();

class upload_service {

    private const TABLE = 'local_fastpix_upload_session';
    private const SESSION_TTL_SECONDS = 86400;
    private const DEDUP_TTL_SECONDS   = 60;

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public static function reset(): void {
        self::$instance = null;
    }

    public function create_file_upload_session(
        int $userid,
        array $metadata,
        bool $drm_required = false,
    ): \stdClass {
        $this->assert_drm_gate($drm_required);

        $cache = \cache::make('local_fastpix', 'upload_dedup');
        $hash_key = $this->dedup_key($userid, $metadata);

        // Dedup window: same (userid, filename, size) within 60s returns the
        // existing session.
        $existing_id = $cache->get($hash_key);
        if (is_int($existing_id) || (is_string($existing_id) && ctype_digit($existing_id))) {
            $existing = $this->lookup_session((int)$existing_id);
            if ($existing !== null && $existing->expires_at > time()) {
                return $this->build_response($existing, deduped: true);
            }
        }

        $owner_hash = $this->owner_hash($userid);
        $access_policy = $drm_required ? 'drm' : 'private';
        $drm_config_id = $drm_required
            ? feature_flag_service::instance()->drm_configuration_id()
            : null;

        $fastpix_metadata = [
            'moodle_owner_userhash' => $owner_hash,
            'moodle_site_url'       => (new \moodle_url('/'))->out(false),
        ];

        $response = \local_fastpix\api\gateway::instance()->input_video_direct_upload(
            $owner_hash,
            $fastpix_metadata,
            $access_policy,
            $drm_config_id,
        );

        $upload_id = (string)($response->data->uploadId ?? $response->uploadId ?? '');
        $upload_url = (string)($response->data->url ?? $response->url ?? '');

        $session = $this->persist_session(
            userid:     $userid,
            upload_id:  $upload_id,
            upload_url: $upload_url,
            source_url: null,
        );

        $cache->set($hash_key, $session->id);

        return $this->build_response($session, deduped: false);
    }

    public function create_url_pull_session(
        int $userid,
        string $source_url,
        bool $drm_required = false,
    ): \stdClass {
        // SSRF guard runs BEFORE any gateway call (rule S6).
        $this->assert_ssrf_safe($source_url);

        $this->assert_drm_gate($drm_required);

        $owner_hash = $this->owner_hash($userid);
        $access_policy = $drm_required ? 'drm' : 'private';
        $drm_config_id = $drm_required
            ? feature_flag_service::instance()->drm_configuration_id()
            : null;

        $fastpix_metadata = [
            'moodle_owner_userhash' => $owner_hash,
            'moodle_site_url'       => (new \moodle_url('/'))->out(false),
        ];

        $response = \local_fastpix\api\gateway::instance()->media_create_from_url(
            $source_url,
            $owner_hash,
            $fastpix_metadata,
            $access_policy,
            $drm_config_id,
        );

        $upload_id = (string)($response->data->id ?? $response->id ?? '');

        $session = $this->persist_session(
            userid:     $userid,
            upload_id:  $upload_id,
            upload_url: '',
            source_url: $source_url,
        );

        return $this->build_response($session, deduped: false);
    }

    // ---- Helpers ---------------------------------------------------------

    private function assert_drm_gate(bool $drm_required): void {
        if ($drm_required && !feature_flag_service::instance()->drm_enabled()) {
            throw new drm_not_configured('drm_required_but_not_configured');
        }
    }

    private function dedup_key(int $userid, array $metadata): string {
        $filename = (string)($metadata['filename'] ?? '');
        $size     = (int)($metadata['size'] ?? 0);
        $logical  = "upload:{$userid}:" . hash('sha256', $filename . '|' . $size);
        // 'upload_dedup' MUC area uses simplekeys=true; hash to alphanumeric.
        return 'ud_' . hash('crc32b', $logical);
    }

    private function owner_hash(int $userid): string {
        $salt = (string)get_config('local_fastpix', 'user_hash_salt');
        if ($salt === '') {
            $salt = random_string(32);
            set_config('user_hash_salt', $salt, 'local_fastpix');
        }
        return hash_hmac('sha256', (string)$userid, $salt);
    }

    private function lookup_session(int $id): ?\stdClass {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['id' => $id]);
        return $row ?: null;
    }

    private function persist_session(
        int $userid,
        string $upload_id,
        string $upload_url,
        ?string $source_url,
    ): \stdClass {
        global $DB;
        $now = time();
        $row = (object)[
            'userid'      => $userid,
            'upload_id'   => $upload_id,
            'upload_url'  => $upload_url,
            'fastpix_id'  => null,
            'source_url'  => $source_url,
            'state'       => 'pending',
            'timecreated' => $now,
            'expires_at'  => $now + self::SESSION_TTL_SECONDS,
        ];
        $row->id = $DB->insert_record(self::TABLE, $row);
        return $row;
    }

    private function build_response(\stdClass $session, bool $deduped): \stdClass {
        return (object)[
            'session_id' => (int)$session->id,
            'upload_id'  => (string)$session->upload_id,
            'upload_url' => (string)$session->upload_url,
            'expires_at' => (int)$session->expires_at,
            'deduped'    => $deduped,
        ];
    }

    private function assert_ssrf_safe(string $url): void {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') {
            throw new ssrf_blocked('non_https');
        }
        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            throw new ssrf_blocked('local_host:' . $host);
        }

        $ips = @gethostbynamel($host);
        if (empty($ips)) {
            throw new ssrf_blocked('unresolvable:' . $host);
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new ssrf_blocked('blocked_ip:' . $ip);
            }
        }
    }
}
