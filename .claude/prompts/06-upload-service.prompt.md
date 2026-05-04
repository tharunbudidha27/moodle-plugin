# Prompt — Generate `upload_service.php`

Two flows: direct browser upload and URL pull. URL pull MUST run the SSRF guard before any other work. Both flows route through the 60s dedup boundary and the DRM feature gate.

Variables to fill in: none.

---

```
You are @upload-service for the local_fastpix Moodle plugin.

CONTEXT YOU HAVE READ:
- 01-local-fastpix.md §15.4 (upload service spec).

TASK: Generate `local/fastpix/classes/service/upload_service.php`.

REQUIREMENTS:

A) create_file_upload_session(int $userid, array $metadata, bool $drm_required = false): \stdClass
   1. dedup_key = "upload:{$userid}:" . hash('sha256', $metadata['filename'] . '|' . $metadata['size']).
   2. Check MUC `upload_dedup` (60s TTL). On hit, look up the session row by id;
      if expires_at > time(), return same session_id with deduped=true.
   3. DRM gate: if $drm_required && !feature_flag_service::instance()->drm_enabled(),
      throw drm_not_configured.
   4. owner_hash = hash_hmac('sha256', $userid, get_config('local_fastpix', 'user_hash_salt')).
   5. fastpix_metadata = ['moodle_owner_userhash' => $owner_hash,
                           'moodle_site_url' => (new \moodle_url('/'))->out(false)].
   6. access_policy = $drm_required ? 'drm' : 'private'.
      drm_config_id = $drm_required ? feature_flag_service::instance()->drm_configuration_id() : null.
   7. Call gateway::instance()->input_video_direct_upload($owner_hash, $fastpix_metadata, $access_policy, $drm_config_id).
   8. INSERT local_fastpix_upload_session: userid, upload_id, upload_url, fastpix_id=null,
      state='pending', timecreated=now, expires_at=now+86400.
   9. Cache the session_id under dedup_key.
   10. Return: { upload_url, upload_id, session_id, expires_at, deduped: false }.

B) create_url_pull_session(int $userid, string $source_url, bool $drm_required = false): \stdClass
   1. SSRF GUARD FIRST:
      - parse_url; require scheme === 'https'.
      - Resolve host via gethostbynamel; reject if any IP is:
          loopback (127.0.0.0/8), RFC1918 (10/8, 172.16/12, 192.168/16),
          link-local (169.254/16), AWS metadata (169.254.169.254 — covered by link-local),
          unspecified (0.0.0.0).
      - Reject domains 'localhost', '*.local'.
   2. (Same DRM gate as above.)
   3. (Same owner_hash + metadata as above.)
   4. Call gateway::instance()->media_create_from_url($source_url, $owner_hash, $fastpix_metadata, $access_policy, $drm_config_id).
   5. INSERT session row keyed by returned Media ID.
   6. Return result.

DO NOT:
- Skip the SSRF guard.
- Skip the DRM gate.
- Add the user's filename to logs verbatim — hash it.
- Call require_capability — endpoint's job.

OUTPUT: PHP file only.
```
