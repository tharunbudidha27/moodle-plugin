<?php
namespace local_fastpix\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Boundary test for the 90-day webhook ledger retention (rule W9).
 */
class webhook_event_pruner_test extends \advanced_testcase {

    private const TABLE = 'local_fastpix_webhook_event';
    private const DAY = 86400;

    public function setUp(): void {
        $this->resetAfterTest();
    }

    private function insert_event(int $received_at, string $status = 'processed'): int {
        global $DB;
        return (int)$DB->insert_record(self::TABLE, (object)[
            'provider_event_id'     => 'evt-' . random_string(8),
            'event_type'            => 'video.media.created',
            'event_created_at'      => $received_at,
            'payload'               => '{}',
            'signature'             => 'test-sig',
            'received_at'           => $received_at,
            'status'                => $status,
            'processing_latency_ms' => 0,
        ]);
    }

    public function test_prunes_at_91_days(): void {
        global $DB;
        $id = $this->insert_event(time() - 91 * self::DAY);
        ob_start(); (new webhook_event_pruner())->execute(); ob_end_clean();
        $this->assertFalse($DB->record_exists(self::TABLE, ['id' => $id]));
    }

    public function test_keeps_at_89_days(): void {
        global $DB;
        $id = $this->insert_event(time() - 89 * self::DAY);
        ob_start(); (new webhook_event_pruner())->execute(); ob_end_clean();
        $this->assertTrue($DB->record_exists(self::TABLE, ['id' => $id]));
    }

    public function test_boundary_at_90_days_minus_1s_kept(): void {
        global $DB;
        $id = $this->insert_event(time() - 90 * self::DAY + 1);
        ob_start(); (new webhook_event_pruner())->execute(); ob_end_clean();
        $this->assertTrue($DB->record_exists(self::TABLE, ['id' => $id]));
    }

    public function test_boundary_at_90_days_plus_1s_pruned(): void {
        global $DB;
        $id = $this->insert_event(time() - 90 * self::DAY - 1);
        ob_start(); (new webhook_event_pruner())->execute(); ob_end_clean();
        $this->assertFalse($DB->record_exists(self::TABLE, ['id' => $id]));
    }

    public function test_pending_events_never_pruned_even_when_old(): void {
        global $DB;
        $id = $this->insert_event(time() - 365 * self::DAY, 'pending');
        ob_start(); (new webhook_event_pruner())->execute(); ob_end_clean();
        $this->assertTrue($DB->record_exists(self::TABLE, ['id' => $id]));
    }

    public function test_malformed_events_never_pruned_even_when_old(): void {
        global $DB;
        $id = $this->insert_event(time() - 365 * self::DAY, 'malformed');
        ob_start(); (new webhook_event_pruner())->execute(); ob_end_clean();
        $this->assertTrue($DB->record_exists(self::TABLE, ['id' => $id]));
    }

    public function test_get_name_returns_lang_string(): void {
        $this->assertSame('Prune old processed webhook events',
            (new webhook_event_pruner())->get_name());
    }
}
