# Prompt — Generate `asset_service.php`

The asset service is the **only** read API into the asset table. Its public methods are the STABLE contract consumed by `mod_fastpix` and `filter_fastpix` (cross-plugin imports through this class only — see rule A4). Lazy fetch is allowed exclusively in `get_by_fastpix_id_or_fetch`, never elsewhere.

Variables to fill in: none.

---

```
You are @asset-service for the local_fastpix Moodle plugin.

CONTEXT YOU HAVE READ:
- 01-local-fastpix.md §15.3 (asset service spec, including lazy fetch).
- 00-system-overview.md §13 (cross-plugin contracts — STABLE API).

TASK: Generate `local/fastpix/classes/service/asset_service.php`.

PUBLIC API (STABLE — used by mod_fastpix and filter_fastpix):
- get_by_fastpix_id(string $fastpix_id, bool $include_deleted = false): ?\stdClass
- get_by_playback_id(string $playback_id, bool $include_deleted = false): ?\stdClass
- get_by_fastpix_id_or_fetch(string $fastpix_id): \stdClass   // throws asset_not_found / gateway_unavailable
- get_by_id(int $id, bool $include_deleted = false): ?\stdClass
- list_for_owner(int $userid, ?string $status = 'ready', int $limit = 50): array
- list_for_owner_paginated(int $userid, ?string $status, int $offset, int $limit, string $search = ''): array
- soft_delete(int $id): void

REQUIREMENTS:
1. Cache definition: `local_fastpix/asset` (already in db/caches.php).
2. Two cache keys per asset: `<fastpix_id>` and `pb:<playback_id>`.
   Both populated on read; both invalidated on write.
3. Soft-delete filter: by default, rows with deleted_at != null are filtered out.
   $include_deleted=true bypasses (used by privacy provider, purge task).
4. get_by_fastpix_id_or_fetch:
   a. Try get_by_fastpix_id() first.
   b. On miss: gateway::instance()->get_media($fastpix_id).
   c. On gateway_not_found: throw asset_not_found($fastpix_id).
   d. On success: extract first private/drm playback_id from response.
   e. INSERT row with owner_userid=0 (sentinel), all fields populated from response.
   f. On dml_write_exception (UNIQUE race): re-read; return winner.
   g. Cache under both keys. Return row.
5. soft_delete: UPDATE deleted_at = time(); invalidate both cache keys.

DO NOT:
- Call gateway anywhere except get_by_fastpix_id_or_fetch.
- Use lazy fetch from any write path (projector, privacy, tasks).
- Forget to invalidate the pb:<playback_id> key on writes.
- Call require_capability — this is a service, capability is endpoint's job.

OUTPUT: PHP file only.
```
