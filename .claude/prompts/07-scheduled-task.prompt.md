# Prompt — Generate Scheduled Task

Parameterized prompt for any of the four scheduled tasks. Replace `[TASK_NAME]` with one of `orphan_sweeper`, `prune_webhook_ledger`, `purge_soft_deleted_assets`, `retry_gdpr_delete`. The agent will instantiate the task-specific logic from the per-task spec block below.

Variables to fill in:
- `TASK_NAME` — required, one of the four above.
- `BATCH_SIZE` — optional, defaults to 1000.
- `WALL_CLOCK_BUDGET` — optional, defaults to 60.

---

```
You are @tasks-cleanup for the local_fastpix Moodle plugin.

TASK: Generate `local/fastpix/classes/task/[TASK_NAME].php`.

PARAMETERS:
- TASK_NAME: one of [orphan_sweeper | prune_webhook_ledger | purge_soft_deleted_assets | retry_gdpr_delete]
- BATCH_SIZE: 1000 rows / iteration default
- WALL_CLOCK_BUDGET: 60 seconds default

REQUIREMENTS:
1. Namespace: `local_fastpix\task`. Class: TASK_NAME. Extends `\core\task\scheduled_task`.
2. `get_name()` returns get_string('task_TASK_NAME', 'local_fastpix').
3. `execute()`:
   - Records start time.
   - Loops: SELECT batch ORDER BY id LIMIT BATCH_SIZE.
   - Processes rows.
   - Breaks if (time() - start) > WALL_CLOCK_BUDGET or rows fewer than BATCH_SIZE.
   - Structured log per batch: event="task.<task_name>", rows_scanned, rows_mutated, latency_ms.
4. Idempotent: re-running mid-execution is safe.
5. Specific logic per task:

   - orphan_sweeper:
     DELETE FROM local_fastpix_upload_session
     WHERE expires_at < (time() - 86400) AND state IN ('pending', 'failed').

   - prune_webhook_ledger:
     DELETE FROM local_fastpix_webhook_event
     WHERE received_at < (time() - 90 * 86400).

   - purge_soft_deleted_assets:
     SELECT id FROM local_fastpix_asset
     WHERE deleted_at IS NOT NULL AND deleted_at < (time() - 7 * 86400).
     For each batch: DELETE FROM local_fastpix_track WHERE asset_id IN (...);
                    DELETE FROM local_fastpix_asset WHERE id IN (...).
     BOUNDARY: 6d23h NOT purged, 7d1m IS.

   - retry_gdpr_delete:
     SELECT id, fastpix_id, gdpr_retry_count FROM local_fastpix_asset
     WHERE gdpr_delete_pending_at IS NOT NULL
       AND gdpr_delete_pending_at < (time() - 900).
     For each: try gateway->delete_media(fastpix_id).
       On success: SET gdpr_delete_pending_at=NULL, gdpr_retry_count=0.
       On gateway_unavailable: increment gdpr_retry_count.
         If gdpr_retry_count >= 6: emit admin_alert event ONCE; stop retrying for this row.

DO NOT:
- Use unbounded loops.
- Call require_capability or any user-facing API.
- Use raw SQL — use $DB methods.

OUTPUT: PHP file only.
```
