<?php
namespace local_fastpix\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Boundary test for the GDPR-delete retry cap (T3.5).
 *
 * The retry task increments gdpr_delete_attempts on each run. After
 * MAX_ATTEMPTS (10) the row is no longer selected by the task — a
 * stuck-at-cap row signals to ops that something is wrong on the FastPix
 * side, but the local soft-delete is still in effect, so user data is
 * not visible to anyone.
 */
class retry_gdpr_delete_test extends \advanced_testcase {

    private const TABLE = 'local_fastpix_asset';

    public function setUp(): void {
        $this->resetAfterTest();
        \local_fastpix\api\gateway::reset();
        \cache::make('local_fastpix', 'asset')->purge();
    }

    public function tearDown(): void {
        \local_fastpix\api\gateway::reset();
    }

    private function inject_failing_gateway(): void {
        $mock = $this->createMock(\local_fastpix\api\gateway::class);
        $mock->method('delete_media')
            ->willThrowException(new \local_fastpix\exception\gateway_unavailable('500:simulated'));

        $reflection = new \ReflectionClass(\local_fastpix\api\gateway::class);
        $prop = $reflection->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $mock);
    }

    private function insert_pending_asset(int $attempts = 0): \stdClass {
        global $DB;
        $now = time();
        $row = (object)[
            'fastpix_id'             => 'media-' . random_string(8),
            'playback_id'            => null,
            'owner_userid'           => 0,
            'title'                  => 'Stuck row',
            'duration'               => null,
            'status'                 => 'ready',
            'access_policy'          => 'private',
            'drm_required'           => 0,
            'no_skip_required'       => 0,
            'has_captions'           => 0,
            'last_event_id'          => null,
            'last_event_at'          => null,
            'deleted_at'             => $now,
            'gdpr_delete_pending_at' => $now,
            'gdpr_delete_attempts'   => $attempts,
            'timecreated'            => $now,
            'timemodified'           => $now,
        ];
        $row->id = $DB->insert_record(self::TABLE, $row);
        return $row;
    }

    public function test_attempts_increments_on_each_failure(): void {
        global $DB;
        $this->inject_failing_gateway();

        $asset = $this->insert_pending_asset(0);

        ob_start();
        (new retry_gdpr_delete())->execute();
        ob_end_clean();

        $stored = $DB->get_record(self::TABLE, ['id' => $asset->id]);
        $this->assertSame(1, (int)$stored->gdpr_delete_attempts);
        $this->assertNotEmpty($stored->gdpr_delete_pending_at,
            'pending flag must remain set on failure');
    }

    public function test_row_at_attempt_9_is_still_processed(): void {
        global $DB;
        $this->inject_failing_gateway();

        $asset = $this->insert_pending_asset(9);

        ob_start();
        (new retry_gdpr_delete())->execute();
        $output = ob_get_clean();

        $stored = $DB->get_record(self::TABLE, ['id' => $asset->id]);
        $this->assertSame(10, (int)$stored->gdpr_delete_attempts,
            'attempts must increment from 9 to 10');
        $this->assertStringContainsString('CRITICAL', $output,
            'reaching MAX_ATTEMPTS must emit a CRITICAL log line');
    }

    public function test_row_at_cap_is_not_reprocessed(): void {
        global $DB;
        $this->inject_failing_gateway();

        $asset = $this->insert_pending_asset(10); // already at cap

        ob_start();
        (new retry_gdpr_delete())->execute();
        ob_end_clean();

        $stored = $DB->get_record(self::TABLE, ['id' => $asset->id]);
        $this->assertSame(10, (int)$stored->gdpr_delete_attempts,
            'attempts must NOT increment past cap');
    }

    public function test_success_clears_pending_flag(): void {
        global $DB;

        // delete_media is declared : void — leave the mock without a
        // return spec so the default void return is used.
        $mock = $this->createMock(\local_fastpix\api\gateway::class);
        $reflection = new \ReflectionClass(\local_fastpix\api\gateway::class);
        $prop = $reflection->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $mock);

        $asset = $this->insert_pending_asset(3);

        ob_start();
        (new retry_gdpr_delete())->execute();
        ob_end_clean();

        $stored = $DB->get_record(self::TABLE, ['id' => $asset->id]);
        $this->assertNull($stored->gdpr_delete_pending_at,
            'pending flag must be cleared on remote-delete success');
    }
}
