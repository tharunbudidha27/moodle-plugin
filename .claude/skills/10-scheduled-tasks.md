# Skill 10 — Implement Scheduled Tasks

**Owner agent:** `@tasks-cleanup`.

**When to invoke:** Phase 6, step 1.

---

## Inputs

- Task class name (one of: `orphan_sweeper`, `prune_webhook_ledger`, `purge_soft_deleted_assets`, `retry_gdpr_delete`).
- Default batch size: 1000. Default wall-clock budget: 60s.

## Outputs

- One task class per file in `local/fastpix/classes/task/`.

## Common task structure

```php
namespace local_fastpix\task;

class <task_name> extends \core\task\scheduled_task {

    private const BATCH_SIZE = 1000;
    private const WALL_CLOCK_BUDGET_SECONDS = 60;

    public function get_name(): string {
        return get_string('task_<task_name>', 'local_fastpix');
    }

    public function execute() {
        global $DB;
        $start = time();
        $rows_scanned_total = 0;
        $rows_mutated_total = 0;

        do {
            $batch = $this->select_batch();
            $rows_scanned_total += count($batch);

            foreach ($batch as $row) {
                $rows_mutated_total += $this->process($row) ? 1 : 0;
            }

            \local_fastpix\helper\logger::info('task.<task_name>.batch', [
                'rows_scanned' => count($batch),
                'rows_mutated' => $rows_mutated_total,
                'elapsed_ms'   => (time() - $start) * 1000,
            ]);

            if (count($batch) < self::BATCH_SIZE) {
                break;  // drained
            }
            if ((time() - $start) > self::WALL_CLOCK_BUDGET_SECONDS) {
                \local_fastpix\helper\logger::warn('task.<task_name>.budget_hit', [
                    'rows_scanned' => $rows_scanned_total,
                ]);
                break;
            }
        } while (true);
    }
}
```

## Per-task specifics

### `orphan_sweeper`

```sql
DELETE FROM mdl_local_fastpix_upload_session
WHERE expires_at < (UNIX_TIMESTAMP() - 86400)
  AND state IN ('pending', 'failed')
LIMIT 1000;
```
(via `$DB->delete_records_select` with parameterized SQL).

### `prune_webhook_ledger`

```sql
DELETE FROM mdl_local_fastpix_webhook_event
WHERE received_at < (UNIX_TIMESTAMP() - 90 * 86400)
LIMIT 1000;
```

### `purge_soft_deleted_assets`

Two-step: select asset IDs, cascade-delete tracks, then delete assets.

```php
$cutoff = time() - 7 * 86400;
$ids = $DB->get_fieldset_select('local_fastpix_asset', 'id',
    'deleted_at IS NOT NULL AND deleted_at < :cutoff',
    ['cutoff' => $cutoff], '', 0, self::BATCH_SIZE);
if (empty($ids)) return [];

[$insql, $inparams] = $DB->get_in_or_equal($ids);
$DB->delete_records_select('local_fastpix_track', "asset_id $insql", $inparams);
$DB->delete_records_select('local_fastpix_asset', "id $insql", $inparams);
```

**Boundary**: 6d23h NOT purged (deleted_at = now - 6d23h, cutoff = now - 7d → row NOT < cutoff). 7d1m IS purged. Test fixtures assert both.

### `retry_gdpr_delete`

```php
$cooldown_cutoff = time() - 900;  // 15 min
$rows = $DB->get_records_select('local_fastpix_asset',
    'gdpr_delete_pending_at IS NOT NULL AND gdpr_delete_pending_at < :cutoff
     AND (gdpr_retry_count IS NULL OR gdpr_retry_count < 6)',
    ['cutoff' => $cooldown_cutoff], '', '*', 0, self::BATCH_SIZE);

foreach ($rows as $row) {
    try {
        \local_fastpix\api\gateway::instance()->delete_media($row->fastpix_id);
        $DB->update_record('local_fastpix_asset', (object)[
            'id' => $row->id,
            'gdpr_delete_pending_at' => null,
            'gdpr_retry_count' => 0,
        ]);
    } catch (\local_fastpix\exception\gateway_unavailable $e) {
        $new_count = (int)$row->gdpr_retry_count + 1;
        $DB->update_record('local_fastpix_asset', (object)[
            'id' => $row->id,
            'gdpr_retry_count' => $new_count,
            'gdpr_delete_pending_at' => time(),  // reset cooldown
        ]);
        if ($new_count === 6) {
            // emit alert event ONCE (only on transition to 6)
            \local_fastpix\event\gdpr_delete_alert::create([
                'context' => \context_system::instance(),
                'other'   => ['asset_id' => $row->fastpix_id],
            ])->trigger();
        }
    }
}
```

## Constraints

- **Idempotent.** Re-running mid-execution is safe.
- **Batched + time-boxed.** No unbounded loops.
- **Adhoc extends `\core\task\adhoc_task`**, scheduled extends `\core\task\scheduled_task`. Don't mix.
- **No `require_capability`, no `$OUTPUT`.** CLI-context.
- **Structured log per batch.**
- **Alert event fires once per transition** (don't spam every retry tick).

## Verification

- All four tasks runnable via `php admin/cli/scheduled_task.php`.
- Boundary: 6d23h NOT, 7d1m IS for soft-delete purge.
- GDPR alert fires after exactly 6 consecutive failures per asset.
- Tasks log per-batch with row counts and elapsed_ms.
