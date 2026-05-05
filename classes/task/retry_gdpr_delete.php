<?php
namespace local_fastpix\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task: retry FastPix-side deletion for soft-deleted assets where
 * the remote delete previously failed.
 *
 * Per architecture doc §16: local soft-delete is immediate; remote delete is
 * best-effort. When the remote call fails, gdpr_delete_pending_at is set
 * and this task retries on cron.
 *
 * Per @tasks-cleanup guardrails: batched, time-boxed, idempotent, never logs
 * raw user IDs.
 */
class retry_gdpr_delete extends \core\task\scheduled_task {

    /** @var int Max rows processed per task run. */
    private const BATCH_SIZE = 50;

    /** @var int Wall-clock budget per task run, in seconds. */
    private const TIME_BUDGET_SECONDS = 60;

    public function get_name(): string {
        return get_string('task_retry_gdpr_delete', 'local_fastpix');
    }

    public function execute(): void {
        global $DB;

        $sql = "SELECT id, fastpix_id, gdpr_delete_pending_at
                  FROM {local_fastpix_asset}
                 WHERE deleted_at IS NOT NULL
                   AND gdpr_delete_pending_at IS NOT NULL
              ORDER BY gdpr_delete_pending_at ASC";

        $rows = $DB->get_records_sql($sql, [], 0, self::BATCH_SIZE);

        if (empty($rows)) {
            mtrace('retry_gdpr_delete: no pending GDPR deletes.');
            return;
        }

        $start = microtime(true);
        $success = 0;
        $failed = 0;
        $skipped = 0;

        $gateway = \local_fastpix\api\gateway::instance();

        foreach ($rows as $asset) {
            // Time-box: stop processing if we've exceeded the budget.
            if ((microtime(true) - $start) > self::TIME_BUDGET_SECONDS) {
                $skipped = count($rows) - ($success + $failed);
                mtrace('retry_gdpr_delete: time budget hit, deferring remaining rows to next run.');
                break;
            }

            try {
                $gateway->delete_media($asset->fastpix_id);
                // Success: clear the pending flag. Local row stays soft-deleted
                // (cleanup task purges after retention window).
                $DB->set_field(
                    'local_fastpix_asset',
                    'gdpr_delete_pending_at',
                    null,
                    ['id' => $asset->id]
                );
                $success++;
            } catch (\local_fastpix\exception\gateway_not_found $e) {
                // 404 from FastPix means the asset is already gone there.
                // Treat as success — nothing to retry.
                $DB->set_field(
                    'local_fastpix_asset',
                    'gdpr_delete_pending_at',
                    null,
                    ['id' => $asset->id]
                );
                $success++;
            } catch (\Throwable $e) {
                // Leave gdpr_delete_pending_at set; next cron run will retry.
                $failed++;
                mtrace(sprintf(
                    'retry_gdpr_delete: asset_row=%d failed: %s',
                    $asset->id,
                    $e->getMessage()
                ));
            }
        }

        $latency_ms = (int)((microtime(true) - $start) * 1000);
        mtrace(sprintf(
            'retry_gdpr_delete: success=%d failed=%d skipped=%d latency_ms=%d',
            $success,
            $failed,
            $skipped,
            $latency_ms
        ));
    }
}
