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
        ?string $access_policy = null,
        ?string $max_resolution = null,
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

        $owner_hash      = $this->owner_hash($userid);
        $access_policy   = $this->resolve_access_policy($drm_required, $access_policy);
        $max_resolution  = $this->resolve_max_resolution($max_resolution);
        $drm_config_id   = $access_policy === 'drm'
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
            $max_resolution,
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
        ?string $access_policy = null,
        ?string $max_resolution = null,
    ): \stdClass {
        // SSRF guard runs BEFORE any gateway call (rule S6).
        $this->assert_ssrf_safe($source_url);

        $this->assert_drm_gate($drm_required);

        // Dedup window: same (userid, source_url) within 60s returns the
        // existing session row. Mirrors the file-upload dedup contract (W11).
        $cache = \cache::make('local_fastpix', 'upload_dedup');
        $hash_key = $this->dedup_key_url($userid, $source_url);
        $existing_id = $cache->get($hash_key);
        if (is_int($existing_id) || (is_string($existing_id) && ctype_digit($existing_id))) {
            $existing = $this->lookup_session((int)$existing_id);
            if ($existing !== null && $existing->expires_at > time()) {
                return $this->build_response($existing, deduped: true);
            }
        }

        $owner_hash      = $this->owner_hash($userid);
        $access_policy   = $this->resolve_access_policy($drm_required, $access_policy);
        $max_resolution  = $this->resolve_max_resolution($max_resolution);
        $drm_config_id   = $access_policy === 'drm'
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
            $max_resolution,
        );

        $upload_id = (string)($response->data->id ?? $response->id ?? '');

        $session = $this->persist_session(
            userid:     $userid,
            upload_id:  $upload_id,
            upload_url: '',
            source_url: $source_url,
        );

        $cache->set($hash_key, $session->id);

        return $this->build_response($session, deduped: false);
    }

    // ---- Helpers ---------------------------------------------------------

    /**
     * Read-only lookup of an upload session, scoped to the calling user.
     *
     * Per @security-compliance: ownership check (userid) is enforced in the
     * SQL clause to prevent horizontal privilege escalation. Callers with
     * the :uploadmedia capability can read THEIR sessions only, not others'.
     *
     * @param int $session_id Local upload_session row id
     * @param int $userid     The user who must own the session
     * @return \stdClass
     * @throws \local_fastpix\exception\asset_not_found
     */
    public function get_status(int $session_id, int $userid): \stdClass {
        global $DB;
        $row = $DB->get_record(self::TABLE, [
            'id'     => $session_id,
            'userid' => $userid,
        ]);
        if (!$row) {
            throw new \local_fastpix\exception\asset_not_found(
                "upload_session id={$session_id} for userid={$userid}"
            );
        }
        return (object)[
            'session_id' => (int)$row->id,
            'upload_id'  => (string)$row->upload_id,
            'state'      => (string)$row->state,
            'fastpix_id' => $row->fastpix_id !== null ? (string)$row->fastpix_id : '',
            'expires_at' => (int)$row->expires_at,
        ];
    }

    /**
     * Resolve effective access_policy for an upload.
     *
     *   1. drm_required=true     → 'drm' (explicit DRM intent always wins)
     *   2. caller-passed value   → caller's choice (per-call override)
     *   3. admin config default  → default_access_policy (set in settings)
     *   4. hard-coded fallback   → 'private' (defensive — fail closed)
     *
     * Whitelist enforced: anything other than public/private/drm coming
     * from config or caller falls back to 'private'.
     */
    private function resolve_access_policy(bool $drm_required, ?string $caller_value): string {
        if ($drm_required) {
            return 'drm';
        }
        $allowed = ['public', 'private', 'drm'];
        if ($caller_value !== null && $caller_value !== '' && in_array($caller_value, $allowed, true)) {
            return $caller_value;
        }
        $configured = (string)get_config('local_fastpix', 'default_access_policy');
        if (in_array($configured, $allowed, true)) {
            return $configured;
        }
        return 'private';
    }

    /**
     * Resolve effective max_resolution for an upload.
     *
     *   1. caller-passed value   → caller's choice
     *   2. admin config default  → max_resolution (set in settings)
     *   3. hard-coded fallback   → '1080p'
     */
    private function resolve_max_resolution(?string $caller_value): string {
        $allowed = ['480p', '720p', '1080p', '1440p', '2160p'];
        if ($caller_value !== null && $caller_value !== '' && in_array($caller_value, $allowed, true)) {
            return $caller_value;
        }
        $configured = (string)get_config('local_fastpix', 'max_resolution');
        if (in_array($configured, $allowed, true)) {
            return $configured;
        }
        return '1080p';
    }

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
        return 'ud_' . substr(hash('sha256', $logical), 0, 32);
    }

    /**
     * Dedup key for URL-pull sessions. Same (userid, source_url) within the
     * 60-second window returns the existing session_id with deduped=true.
     */
    private function dedup_key_url(int $userid, string $source_url): string {
        $logical = "urlpull:{$userid}:" . hash('sha256', $source_url);
        return 'up_' . substr(hash('sha256', $logical), 0, 32);
    }

    private function owner_hash(int $userid): string {
        $salt = (string)get_config('local_fastpix', 'user_hash_salt');
        if ($salt === '') {
            // The previous fallback was: generate a random salt + set_config.
            // Removed per REVIEW-2026-05-04 §4 — concurrent first-uses produced
            // different salts, second worker's set_config overwrote first's,
            // and the first worker's emitted hash silently became orphaned.
            //
            // db/install.php bootstraps user_hash_salt at install time
            // (random_string(32)), so an empty salt at runtime indicates:
            //   - the install hook didn't run (broken install), or
            //   - someone deliberately nulled the config (operator error).
            // Both warrant failing loud so the operator notices.
            throw new \coding_exception(
                'local_fastpix: user_hash_salt config is empty; ' .
                'expected to be bootstrapped by db/install.php. ' .
                'Re-run plugin install or restore the config.'
            );
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

    /**
     * SSRF guard for user-supplied source URLs.
     *
     * Threat model: we filter URLs that resolve to private/loopback/link-local
     * IPs from Moodle's resolver at submission time. This is defense in depth.
     *
     * What this guard does NOT cover: FastPix-side DNS rebinding. Moodle
     * never directly fetches source_url — the gateway POSTs the URL inside
     * a JSON body to api.fastpix.io, and FastPix's backend fetches it later
     * with FastPix's own resolver. CURLOPT_RESOLVE pinning on our cURL
     * handle has zero effect on FastPix's later fetch. That residual risk
     * is FastPix's to mitigate on their infrastructure; we filter obvious
     * abuse here so stale or compromised resolvers on the Moodle side
     * can't be used to probe FastPix's internal network.
     *
     * Empirical audit 2026-05-06 (REVIEW DoD §31): zero direct-fetch sites
     * for source_url in the plugin source.
     */
    private function assert_ssrf_safe(string $url): void {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') {
            throw new ssrf_blocked('non_https');
        }
        // Reject embedded credentials (https://user:pass@host/...) — common
        // exfiltration vector via Referer headers and access logs, and
        // Moodle has no use case for credential-in-URL fetches against
        // FastPix.
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new ssrf_blocked('credentials_in_url');
        }
        $host = strtolower($parts['host'] ?? '');
        // Strip IPv6 literal brackets if parse_url left them in (varies by
        // PHP version / build): https://[fe80::1]/x -> host='[fe80::1]'.
        if (strlen($host) >= 2 && $host[0] === '[' && substr($host, -1) === ']') {
            $host = substr($host, 1, -1);
        }
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            throw new ssrf_blocked('local_host:' . $host);
        }

        // Direct IPv6 host literal? Validate without DNS.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $this->assert_ip_public($host);
            return;
        }
        // Direct IPv4 host literal? Validate without DNS.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->assert_ip_public($host);
            return;
        }

        // Hostname: resolve A + AAAA records. dns_get_record returns false on
        // failure; treat empty/false the same as gethostbynamel did. The
        // residual TOCTOU on FastPix's later fetch is documented at the top
        // of this method and is not a Moodle-side concern.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || empty($records)) {
            throw new ssrf_blocked('unresolvable:' . $host);
        }
        $ips = [];
        foreach ($records as $r) {
            if (isset($r['ip']))    { $ips[] = $r['ip']; }    // A
            if (isset($r['ipv6']))  { $ips[] = $r['ipv6']; }  // AAAA
        }
        if (empty($ips)) {
            throw new ssrf_blocked('unresolvable:' . $host);
        }
        foreach ($ips as $ip) {
            $this->assert_ip_public($ip);
        }
    }

    /**
     * Assert that an IP literal (v4 or v6) is publicly routable. Throws
     * ssrf_blocked with a tag describing the family and reason.
     *
     * Per @upload-service guardrail: explicit byte-pattern matching for
     * private IPv6 ranges, because PHP's FILTER_FLAG_NO_PRIV_RANGE /
     * NO_RES_RANGE flags do not reliably cover all IPv6 private ranges.
     */
    private function assert_ip_public(string $ip): void {
        // IPv4 path — preserves backward-compatible error tag 'blocked_ip:'
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (!filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new ssrf_blocked('blocked_ip:' . $ip);
            }
            // 169.254.0.0/16 is link-local; FILTER_FLAG_NO_RES_RANGE catches it,
            // but be explicit about the AWS metadata IP for log clarity.
            if ($ip === '169.254.169.254') {
                throw new ssrf_blocked('blocked_ip:' . $ip);
            }
            return;
        }

        // IPv6 path
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed === false || strlen($packed) !== 16) {
                throw new ssrf_blocked('blocked_ipv6:' . $ip);
            }
            // Loopback ::1
            if ($packed === inet_pton('::1')) {
                throw new ssrf_blocked('blocked_ipv6:' . $ip);
            }
            // Unspecified ::
            if ($packed === inet_pton('::')) {
                throw new ssrf_blocked('blocked_ipv6:' . $ip);
            }
            // ULA fc00::/7 — first byte top-7-bits = 1111110_
            if ((ord($packed[0]) & 0xfe) === 0xfc) {
                throw new ssrf_blocked('blocked_ipv6:' . $ip);
            }
            // Link-local fe80::/10 — first 10 bits = 1111111010
            if (ord($packed[0]) === 0xfe && (ord($packed[1]) & 0xc0) === 0x80) {
                throw new ssrf_blocked('blocked_ipv6:' . $ip);
            }
            // IPv4-mapped ::ffff:0:0/96 — first 80 bits = 0, next 16 = ffff
            $mapped_prefix = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
            if (substr($packed, 0, 12) === $mapped_prefix) {
                $unpacked = unpack('N', substr($packed, 12, 4));
                if ($unpacked === false) {
                    throw new ssrf_blocked('blocked_ipv6:' . $ip);
                }
                $v4 = long2ip($unpacked[1]);
                $this->assert_ip_public($v4); // recursively re-validate as IPv4
                return;
            }
            // NAT64 64:ff9b::/96 — common synthesis prefix; trust nothing here
            $nat64_prefix = "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00";
            if (substr($packed, 0, 12) === $nat64_prefix) {
                throw new ssrf_blocked('blocked_ipv6:' . $ip);
            }
            // AWS metadata over IPv6 (as documented for IMDSv2 dual-stack)
            if ($packed === inet_pton('fd00:ec2::254')) {
                throw new ssrf_blocked('blocked_ipv6:' . $ip);
            }
            return; // Public IPv6
        }

        // Neither IPv4 nor IPv6 — reject defensively.
        throw new ssrf_blocked('blocked_ip:' . $ip);
    }
}
