<?php
namespace local_fastpix\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task: retry FastPix-side deletion for soft-deleted assets where
 * the remote delete previously failed.
 *
 * Per architecture doc §16: local soft-delete is immediate; remote delete is
 * best-effort. When the remote call fails, gdpr_delete_pending_at is set
 * and this task retries on cron, incrementing gdpr_delete_attempts each
 * time. After MAX_ATTEMPTS the row stops being selected — a row stuck at
 * the cap signals a degraded state that ops must investigate; the local
 * soft-delete is still in effect, so users see no leakage.
 *
 * Per @tasks-cleanup guardrails: batched, time-boxed, idempotent, never
 * logs raw user IDs.
 */
class retry_gdpr_delete extends \core\task\scheduled_task {

    /** @var int Max rows processed per task run. */
    private const BATCH_SIZE = 50;

    /** @var int Wall-clock budget per task run, in seconds. */
    private const TIME_BUDGET_SECONDS = 60;

    /**
     * @var int Hard cap on retry attempts before a row is left as
     * permanently-stuck. Per T3.5 / agent-routing decision: 10 attempts at
     * 15-min cron cadence ≈ 2.5 hours of retries before we stop trying.
     */
    private const MAX_ATTEMPTS = 10;

    public function get_name(): string {
        return get_string('task_retry_gdpr_delete', 'local_fastpix');
    }

    public function execute(): void {
        global $DB;

        $sql = "SELECT id, fastpix_id, gdpr_delete_pending_at, gdpr_delete_attempts
                  FROM {local_fastpix_asset}
                 WHERE deleted_at IS NOT NULL
                   AND gdpr_delete_pending_at IS NOT NULL
                   AND gdpr_delete_attempts < :cap
              ORDER BY gdpr_delete_pending_at ASC";

        $rows = $DB->get_records_sql(
            $sql,
            ['cap' => self::MAX_ATTEMPTS],
            0,
            self::BATCH_SIZE,
        );

        if (empty($rows)) {
            mtrace('retry_gdpr_delete: no pending GDPR deletes.');
            return;
        }

        $start = microtime(true);
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $capped = 0;

        $gateway = \local_fastpix\api\gateway::instance();

        foreach ($rows as $asset) {
            // Time-box: stop processing if we've exceeded the budget.
            if ((microtime(true) - $start) > self::TIME_BUDGET_SECONDS) {
                $skipped = count($rows) - ($success + $failed);
                mtrace('retry_gdpr_delete: time budget hit, deferring remaining rows to next run.');
                break;
            }

            // Increment attempts BEFORE the gateway call so that network
            // exceptions (and even task crashes mid-call) count toward the
            // cap. Otherwise a failure mode that consistently kills the
            // PHP process would loop forever.
            $next_attempt = ((int)$asset->gdpr_delete_attempts) + 1;
            $DB->set_field(
                'local_fastpix_asset',
                'gdpr_delete_attempts',
                $next_attempt,
                ['id' => $asset->id],
            );

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
                $failed++;

                if ($next_attempt >= self::MAX_ATTEMPTS) {
                    $capped++;
                    // CRITICAL log line — ops audit signal. Row stays in DB
                    // with attempts at cap; future runs skip it via the
                    // SELECT filter. fastpix_id is safe to log (not PII);
                    // the asset's userid is NOT included.
                    mtrace(sprintf(
                        'retry_gdpr_delete: CRITICAL asset_row=%d fastpix_id=%s '
                        . 'reached MAX_ATTEMPTS=%d, will not retry: %s',
                        $asset->id,
                        $asset->fastpix_id,
                        self::MAX_ATTEMPTS,
                        $e->getMessage(),
                    ));
                } else {
                    mtrace(sprintf(
                        'retry_gdpr_delete: asset_row=%d attempt=%d/%d failed: %s',
                        $asset->id,
                        $next_attempt,
                        self::MAX_ATTEMPTS,
                        $e->getMessage(),
                    ));
                }
            }
        }

        $latency_ms = (int)((microtime(true) - $start) * 1000);
        mtrace(sprintf(
            'retry_gdpr_delete: success=%d failed=%d capped=%d skipped=%d latency_ms=%d',
            $success,
            $failed,
            $capped,
            $skipped,
            $latency_ms
        ));
    }
}
