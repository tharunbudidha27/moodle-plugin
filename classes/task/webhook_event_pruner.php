<?php
namespace local_fastpix\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Daily prune of processed webhook ledger rows older than 14 days.
 *
 * 14 days balances ops investigation needs against unbounded table growth.
 * Only rows with status='processed' are eligible — anything still pending or
 * marked malformed is preserved for forensics.
 */
class webhook_event_pruner extends \core\task\scheduled_task {

    private const TABLE = 'local_fastpix_webhook_event';
    private const RETENTION_SECONDS = 1209600; // 14 days

    public function get_name(): string {
        return get_string('task_webhook_event_pruner', 'local_fastpix');
    }

    public function execute(): void {
        global $DB;

        $cutoff = time() - self::RETENTION_SECONDS;

        try {
            $count = $DB->count_records_select(
                self::TABLE,
                "status = :status AND received_at < :cutoff",
                ['status' => 'processed', 'cutoff' => $cutoff],
            );

            $DB->delete_records_select(
                self::TABLE,
                "status = :status AND received_at < :cutoff",
                ['status' => 'processed', 'cutoff' => $cutoff],
            );

            mtrace("webhook_event_pruner: deleted {$count} processed event(s) older than 14 days");
        } catch (\Throwable $e) {
            mtrace('webhook_event_pruner: prune failed: ' . $e->getMessage());
        }
    }
}
