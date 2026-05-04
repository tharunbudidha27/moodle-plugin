# Skill 09 — Implement Upload Service with Direct Upload, URL Pull, 60s Dedup

**Owner agent:** `@upload-service`.

**When to invoke:** Phase 4, step 1.

---

## Inputs

- `userid`, `metadata` (filename, size for direct upload; `source_url` for URL pull), `drm_required` boolean.

## Outputs

- `local/fastpix/classes/service/upload_service.php`.

## Steps — file upload path

```php
public function create_file_upload_session(
    int $userid,
    array $metadata,
    bool $drm_required = false
): \stdClass {
    // 1. Dedup key
    $filename_hash = hash('sha256',
        ($metadata['filename'] ?? '') . '|' . ($metadata['size'] ?? 0));
    $dedup_key = "upload:{$userid}:{$filename_hash}";

    // 2. Cache check
    $cache = \cache::make('local_fastpix', 'upload_dedup');
    $existing_session_id = $cache->get($dedup_key);
    if ($existing_session_id !== false) {
        global $DB;
        $existing = $DB->get_record('local_fastpix_upload_session', ['id' => $existing_session_id]);
        if ($existing && $existing->expires_at > time()) {
            return (object)[
                'upload_url' => $existing->upload_url,
                'upload_id'  => $existing->upload_id,
                'session_id' => $existing->id,
                'expires_at' => $existing->expires_at,
                'deduped'    => true,
            ];
        }
    }

    // 3. DRM gate (double-check: feature flag AND drm_configuration_id)
    $features = \local_fastpix\service\feature_flag_service::instance();
    if ($drm_required && !$features->drm_enabled()) {
        throw new \local_fastpix\exception\drm_not_configured();
    }
    $access_policy = $drm_required ? 'drm' : 'private';
    $drm_config_id = $drm_required ? $features->drm_configuration_id() : null;

    // 4. Owner hash
    $owner_hash = hash_hmac('sha256', (string)$userid,
        get_config('local_fastpix', 'user_hash_salt'));

    $fastpix_metadata = [
        'moodle_owner_userhash' => $owner_hash,
        'moodle_site_url'       => (new \moodle_url('/'))->out(false),
    ];

    // 5. Gateway call
    $remote = \local_fastpix\api\gateway::instance()->input_video_direct_upload(
        $owner_hash, $fastpix_metadata, $access_policy, $drm_config_id
    );

    // 6. Persist session
    global $DB;
    $now = time();
    $session_id = $DB->insert_record('local_fastpix_upload_session', (object)[
        'userid'      => $userid,
        'upload_id'   => $remote->uploadId,
        'upload_url'  => $remote->url,
        'fastpix_id'  => null,                  // arrives with video.media.created webhook
        'state'       => 'pending',
        'timecreated' => $now,
        'expires_at'  => $now + 86400,
    ]);

    $cache->set($dedup_key, $session_id);

    return (object)[
        'upload_url' => $remote->url,
        'upload_id'  => $remote->uploadId,
        'session_id' => $session_id,
        'expires_at' => $now + 86400,
        'deduped'    => false,
    ];
}
```

## Steps — URL pull path

```php
public function create_url_pull_session(int $userid, string $source_url, bool $drm_required = false): \stdClass {
    // SSRF guard FIRST
    $this->assert_ssrf_safe($source_url);

    // (Same DRM gate, owner_hash, metadata as above.)

    $remote = \local_fastpix\api\gateway::instance()->media_create_from_url(
        $source_url, $owner_hash, $fastpix_metadata, $access_policy, $drm_config_id
    );

    // Persist; key by returned Media ID since URL pull is async-ish
    // ... INSERT session row
    // Return result.
}

private function assert_ssrf_safe(string $url): void {
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https') {
        throw new \local_fastpix\exception\ssrf_blocked('non_https');
    }
    $host = $parts['host'] ?? '';
    if (in_array(strtolower($host), ['localhost'], true) || str_ends_with(strtolower($host), '.local')) {
        throw new \local_fastpix\exception\ssrf_blocked('local_host');
    }

    $ips = @gethostbynamel($host) ?: [];
    if (empty($ips)) {
        throw new \local_fastpix\exception\ssrf_blocked('unresolvable');
    }

    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new \local_fastpix\exception\ssrf_blocked("blocked_ip:{$ip}");
        }
    }
}
```

## Constraints

- **SSRF guard FIRST**, before any gateway call.
- **DRM double-gate**: flag AND `drm_configuration_id`.
- **Dedup key exactly `upload:<userid>:<sha256(filename|size)>`** — no extra dimensions.
- **60s dedup window** — match the test boundary.
- **Filename hashed in logs**, not logged verbatim.
- **Session row holds `upload_id` (transient)**, not `fastpix_id` (Media ID arrives via webhook).
- **No capability check in service** — endpoint's job.

## Verification

- Dedup boundary: 59s deduplicates; 61s creates new.
- SSRF rejects localhost / RFC1918 / link-local / AWS metadata / DNS-rebinding.
- DRM upload without `drm_configuration_id` throws `drm_not_configured`.
- DRM payload includes `drmConfigurationId`; private payload does not.
