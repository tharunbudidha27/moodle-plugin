# Webhook & System Rules (W1–W12)

These rules govern the *runtime invariants* — webhook idempotency, ordering, locking, caching, retention. Cited by `@webhook-processing`, `@asset-service`, `@tasks-cleanup`, `@gateway-integration`.

---

## W1 — Webhook endpoint MUST be idempotent

**Rule.** UNIQUE constraint on `local_fastpix_webhook_event.provider_event_id`. Insertion catches `dml_write_exception` and returns 200 (duplicate is success). The ledger never has two rows for the same `provider_event_id`.

**Enforcement.** `tests/integration/webhook_flood_test.php` — 1000 events with 50% duplicates ends with exactly the unique-event-count rows.

**Failure routing.** `@webhook-processing`.

---

## W2 — Asset key extracted from `event.object.id`

**Rule.** The asset reference is at `event.object.id` in FastPix's payload, not `event.data.id`. Verified against FastPix docs in May 2026.

**Enforcement.** `projector_test.php` includes a fixture proving `event.data.id` would yield wrong-asset behavior.

**Failure routing.** `@webhook-processing`.

---

## W3 — Total ordering with lex tiebreak

**Rule.** Events out-of-order are dropped. "Out of order" means:
```
$is_out_of_order =
  $asset->last_event_at !== null && (
    $event->created_at < (int)$asset->last_event_at
    || (
      $event->created_at === (int)$asset->last_event_at
      && $event->id <= $asset->last_event_id
    )
  );
```
Equal timestamps tiebreak by `provider_event_id` lex compare; smaller IDs lose. Same `provider_event_id` as `last_event_id` drops (already projected).

**Enforcement.** `projector_test.php`.

**Failure routing.** `@webhook-processing`.

---

## W4 — Per-asset lock around projection

**Rule.** `\core\lock` factory `local_fastpix`, resource `asset_<fastpix_id>`, 5s timeout. Lock acquired BEFORE the SELECT; released in `finally`. On lock-acquisition failure, throw `lock_acquisition_failed` so the adhoc task re-queues with backoff.

**Enforcement.** `tests/integration/lock_contention_test.php`.

**Failure routing.** `@webhook-processing`.

---

## W5 — Cache invalidation inside the lock

**Rule.** The `asset` cache has two keys per row: `<fastpix_id>` and `pb:<playback_id>`. Both invalidated at the end of `project_inside_lock` — before the lock releases. A single-key invalidation is a bug.

**Enforcement.** Code review; integration test for stale-read elimination.

**Failure routing.** `@asset-service` or `@webhook-processing`.

---

## W6 — Asset lookup must support lazy fetch on read path

**Rule.** `asset_service::get_by_fastpix_id_or_fetch()` exists and is used by playback callers (`mod_fastpix\view.php`, `filter_fastpix\filter.php` — through `playback_service`). Cold-start playback for an asset not in DB triggers exactly one `gateway::get_media`, INSERTs with `owner_userid=0`, plays correctly.

**Enforcement.** API surface check; `tests/asset_service_test.php` cold-start cases.

**Failure routing.** `@asset-service`.

---

## W7 — Lazy fetch FORBIDDEN on write path

**Rule.** Projector, privacy provider, scheduled tasks must use `get_by_fastpix_id` (no `_or_fetch`). Lazy fetch from a write path during a FastPix outage creates a feedback loop that prolongs the outage.

**Enforcement.** CI script `.claude/ci-checks/grep-no-lazy-fetch-on-write-path.sh` — `_or_fetch` use outside `classes/service/playback_service.php` and the read endpoints flagged.

**Failure routing.** `@asset-service` or whoever misused it.

---

## W8 — Circuit breaker state in MUC, never in-process

**Rule.** Multi-FPM correctness. Worker A shouldn't see a closed breaker that Worker B saw open 200ms ago.

**Enforcement.** `tests/integration/circuit_breaker_test.php` simulating two FPM workers via separate process scopes; static check that `private static` properties don't carry breaker state.

**Failure routing.** `@gateway-integration`.

---

## W9 — Webhook ledger 90-day retention

**Rule.** `prune_webhook_ledger` runs daily, deletes rows with `received_at < (time() - 90*86400)`. Privacy provider declares this retention in `get_metadata` so admins see it.

**Enforcement.** Test boundary; runbook.

**Failure routing.** `@tasks-cleanup` and `@security-compliance`.

---

## W10 — Soft-delete + 7-day hard purge

**Rule.** `asset_service::soft_delete()` sets `deleted_at = time()` and invalidates both cache keys. `purge_soft_deleted_assets` runs daily; rows with `deleted_at < (time() - 7*86400)` are hard-deleted, cascading to `local_fastpix_track` via FK. Boundary: 6d23h NOT purged, 7d1m IS.

**Enforcement.** `purge_soft_deleted_assets_test.php` boundary fixtures.

**Failure routing.** `@tasks-cleanup`.

---

## W11 — Upload dedup window is exactly 60s

**Rule.** Same `(userid, sha256(filename|size))` within 60s returns the same `session_id` with `deduped=true`. After 60s, a new session is created. Boundary: 59s dedups, 61s creates new.

**Enforcement.** `upload_service_test.php` boundary fixtures.

**Failure routing.** `@upload-service`.

---

## W12 — DRM double-gate

**Rule.** `feature_flag_service::drm_enabled()` returns `true` ONLY if both:
1. The `feature_drm_enabled` checkbox is true.
2. `drm_configuration_id` is non-empty.

Either alone returns `false`. Default install with credentials but no DRM config → `drm_enabled()=false` → no DRM uploads possible (intentional — prevents customer hitting the FastPix activation requirement on first upload).

**Enforcement.** `feature_flag_service_test.php` — three matrix cases.

**Failure routing.** `@security-compliance`.
