<?php
namespace local_fastpix\task;

defined('MOODLE_INTERNAL') || die();

use local_fastpix\util\cache_keys;

/**
 * Boundary test for the 7-day soft-delete hard-purge (rule W10).
 */
class purge_soft_deleted_assets_test extends \advanced_testcase {

    private const ASSET_TABLE = 'local_fastpix_asset';
    private const TRACK_TABLE = 'local_fastpix_track';
    private const DAY = 86400;
    private const HOUR = 3600;
    private const MINUTE = 60;

    public function setUp(): void {
        $this->resetAfterTest();
        \cache::make('local_fastpix', 'asset')->purge();
    }

    private function insert_asset(?int $deleted_at, ?string $playback_id = null): \stdClass {
        global $DB;
        $now = time();
        $row = (object)[
            'fastpix_id'             => 'media-' . random_string(8),
            'playback_id'            => $playback_id,
            'owner_userid'           => 0,
            'title'                  => 'Test',
            'duration'               => null,
            'status'                 => 'ready',
            'access_policy'          => 'private',
            'drm_required'           => 0,
            'no_skip_required'       => 0,
            'has_captions'           => 0,
            'last_event_id'          => null,
            'last_event_at'          => null,
            'deleted_at'             => $deleted_at,
            'gdpr_delete_pending_at' => null,
            'gdpr_delete_attempts'   => 0,
            'timecreated'            => $now - 30 * self::DAY,
            'timemodified'           => $now,
        ];
        $row->id = $DB->insert_record(self::ASSET_TABLE, $row);
        return $row;
    }

    private function insert_track(int $asset_id): int {
        global $DB;
        return (int)$DB->insert_record(self::TRACK_TABLE, (object)[
            'asset_id'     => $asset_id,
            'track_kind'   => 'subtitle',
            'lang'         => 'en',
            'status'       => 'ready',
            'timemodified' => time(),
        ]);
    }

    private function run_task(): void {
        ob_start();
        (new purge_soft_deleted_assets())->execute();
        ob_end_clean();
    }

    public function test_purges_after_7_days_1_minute(): void {
        global $DB;
        $row = $this->insert_asset(time() - 7 * self::DAY - self::MINUTE);
        $this->run_task();
        $this->assertFalse($DB->record_exists(self::ASSET_TABLE, ['id' => $row->id]));
    }

    public function test_keeps_at_6_days_23_hours(): void {
        global $DB;
        $row = $this->insert_asset(time() - 6 * self::DAY - 23 * self::HOUR);
        $this->run_task();
        $this->assertTrue($DB->record_exists(self::ASSET_TABLE, ['id' => $row->id]));
    }

    public function test_keeps_active_assets_with_null_deleted_at(): void {
        global $DB;
        $row = $this->insert_asset(null);
        $this->run_task();
        $this->assertTrue($DB->record_exists(self::ASSET_TABLE, ['id' => $row->id]));
    }

    public function test_cascade_deletes_local_fastpix_track_rows(): void {
        global $DB;
        $asset = $this->insert_asset(time() - 8 * self::DAY);
        $this->insert_track((int)$asset->id);
        $this->insert_track((int)$asset->id);

        $this->run_task();

        $this->assertSame(0, $DB->count_records(self::TRACK_TABLE, ['asset_id' => (int)$asset->id]));
        $this->assertFalse($DB->record_exists(self::ASSET_TABLE, ['id' => $asset->id]));
    }

    public function test_invalidates_both_cache_keys_on_purge(): void {
        $asset = $this->insert_asset(time() - 8 * self::DAY, 'pb-purge-' . random_string(6));

        $cache = \cache::make('local_fastpix', 'asset');
        $fp_key = cache_keys::fastpix($asset->fastpix_id);
        $pb_key = cache_keys::playback($asset->playback_id);
        $cache->set($fp_key, (object)['stale' => true]);
        $cache->set($pb_key, (object)['stale' => true]);

        $this->run_task();

        $this->assertFalse($cache->get($fp_key));
        $this->assertFalse($cache->get($pb_key));
    }

    public function test_batch_caps_per_run_and_leaves_remaining(): void {
        global $DB;
        $reflect = new \ReflectionClass(purge_soft_deleted_assets::class);
        $batch = $reflect->getConstant('BATCH_SIZE');

        for ($i = 0; $i < $batch + 1; $i++) {
            $this->insert_asset(time() - 8 * self::DAY);
        }

        $this->run_task();

        $remaining = $DB->count_records_select(
            self::ASSET_TABLE,
            'deleted_at IS NOT NULL AND deleted_at < :cutoff',
            ['cutoff' => time() - 7 * self::DAY],
        );
        $this->assertSame(1, $remaining);
    }
}
