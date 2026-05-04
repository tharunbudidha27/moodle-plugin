# Prompt — Generate `projector.php`

The projector is the only place where webhook events become asset state. It runs inside the `process_webhook` adhoc task, holds a per-asset lock, applies total ordering with lex tiebreak, and never calls the gateway.

Variables to fill in: none.

---

```
You are @webhook-processing for the local_fastpix Moodle plugin.

CONTEXT YOU HAVE READ:
- 01-local-fastpix.md §14 (projector spec).

TASK: Generate `local/fastpix/classes/webhook/projector.php`.

REQUIREMENTS:
1. Namespace: `local_fastpix\webhook`. Class: `projector`.
2. Public method: `project(\stdClass $event): void`.
3. Extract asset_key from `event.object.id` (NOT event.data.id).
   If null, project_unlocked() (some FastPix events aren't asset-scoped).
4. Acquire lock:
     $factory = lock_config::get_lock_factory('local_fastpix');
     $lock = $factory->get_lock("asset_{$asset_key}", 5);
   If false: throw \local_fastpix\exception\lock_acquisition_failed("asset_lock:{$asset_key}").
   This causes the adhoc task to re-queue with backoff.
5. try { project_inside_lock($event, $asset_key); } finally { $lock->release(); }
6. Inside lock:
   a. Read asset row by fastpix_id.
   b. If row absent AND event_type === 'video.media.created': insert_new_asset, mark ledger projected, return.
   c. If row absent AND any other event_type: warn-log + mark ledger projected (forward compatibility), return.
   d. TOTAL ORDERING with lex tiebreak:
        $is_out_of_order =
          $asset->last_event_at !== null &&
          ( $event->created_at < (int)$asset->last_event_at
            || ( $event->created_at === (int)$asset->last_event_at
                 && $event->id <= $asset->last_event_id ) );
      If true: warn-log + mark ledger projected, return.
   e. Dispatch via event_dispatcher->apply($event, $asset).
   f. UPDATE local_fastpix_asset SET last_event_at, last_event_id, timemodified.
   g. Mark ledger projected (status='projected', processing_latency_ms calc).
   h. INVALIDATE both cache keys: $cache->delete($asset_key) AND
      if (!empty($asset->playback_id)) $cache->delete('pb:' . $asset->playback_id).

DO NOT:
- Call gateway anywhere (no lazy fetch from write path).
- Use === on signatures (but you don't compare signatures here; verifier does).
- Skip the finally clause.
- Use $asset->last_event_at as a string — cast to int.

OUTPUT: PHP file only. Include the private helpers (insert_new_asset, mark_ledger_projected, extract_asset_key).
```
