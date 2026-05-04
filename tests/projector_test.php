<?php
namespace local_fastpix\webhook;

defined('MOODLE_INTERNAL') || die();

class projector_test extends \advanced_testcase {

    private const TABLE = 'local_fastpix_asset';

    public function setUp(): void {
        $this->resetAfterTest();
        \cache::make('local_fastpix', 'asset')->purge();
    }

    private function insert_asset(array $overrides = []): \stdClass {
        global $DB;
        $now = time();
        $row = (object)array_merge([
            'fastpix_id'             => 'media-' . random_string(8),
            'playback_id'            => null,
            'owner_userid'           => 0,
            'title'                  => 'Test',
            'duration'               => null,
            'status'                 => 'created',
            'access_policy'          => 'private',
            'drm_required'           => 0,
            'no_skip_required'       => 0,
            'has_captions'           => 0,
            'last_event_id'          => null,
            'last_event_at'          => null,
            'deleted_at'             => null,
            'gdpr_delete_pending_at' => null,
            'timecreated'            => $now,
            'timemodified'           => $now,
        ], $overrides);
        $row->id = $DB->insert_record(self::TABLE, $row);
        return $row;
    }

    private function build_event(string $type, string $fastpix_id, array $overrides = []): \stdClass {
        $defaults = [
            'id'         => 'evt-' . random_string(8),
            'type'       => $type,
            'occurredAt' => time(),
            'object'     => (object)['type' => 'video.media', 'id' => $fastpix_id],
            'data'       => new \stdClass(),
        ];
        foreach ($overrides as $k => $v) {
            $defaults[$k] = $v;
        }
        return (object)$defaults;
    }

    private function ready_event(string $fastpix_id, array $playback_ids, array $extra_data = [], array $overrides = []): \stdClass {
        $data = (object)array_merge(['playbackIds' => $playback_ids], $extra_data);
        return $this->build_event('video.media.ready', $fastpix_id, array_merge(['data' => $data], $overrides));
    }

    private function reflect_cache_key_fastpix(string $fastpix_id): string {
        $r = new \ReflectionClass(projector::class);
        $m = $r->getMethod('cache_key_fastpix');
        $m->setAccessible(true);
        return $m->invoke(new projector(), $fastpix_id);
    }

    private function reflect_cache_key_playback(string $playback_id): string {
        $r = new \ReflectionClass(projector::class);
        $m = $r->getMethod('cache_key_playback');
        $m->setAccessible(true);
        return $m->invoke(new projector(), $playback_id);
    }

    // ============ A. Basic dispatch =====================================

    public function test_project_video_media_created_inserts_new_row(): void {
        global $DB;
        $event = $this->build_event('video.media.created', 'media-new-1', [
            'data' => (object)['title' => 'Brand new', 'status' => 'created'],
        ]);

        (new projector())->project($event);

        $row = $DB->get_record(self::TABLE, ['fastpix_id' => 'media-new-1']);
        $this->assertNotFalse($row);
        $this->assertSame(0, (int)$row->owner_userid);
        $this->assertSame('Brand new', $row->title);
        $this->assertSame($event->id, $row->last_event_id);
        $this->assertSame((int)$event->occurredAt, (int)$row->last_event_at);
    }

    public function test_project_video_media_ready_updates_existing_row(): void {
        global $DB;
        $asset = $this->insert_asset(['fastpix_id' => 'media-r-1', 'status' => 'created']);

        $event = $this->ready_event('media-r-1', [
            (object)['id' => 'pb-1', 'accessPolicy' => 'private'],
        ]);
        (new projector())->project($event);

        $row = $DB->get_record(self::TABLE, ['id' => $asset->id]);
        $this->assertSame('ready', $row->status);
        $this->assertSame('pb-1', $row->playback_id);
        $this->assertSame('private', $row->access_policy);
        $this->assertSame(0, (int)$row->drm_required);
    }

    public function test_project_video_media_ready_with_drm_sets_drm_required(): void {
        global $DB;
        $asset = $this->insert_asset(['fastpix_id' => 'media-drm-1']);

        $event = $this->ready_event('media-drm-1', [
            (object)['id' => 'pb-drm', 'accessPolicy' => 'drm'],
        ]);
        (new projector())->project($event);

        $row = $DB->get_record(self::TABLE, ['id' => $asset->id]);
        $this->assertSame(1, (int)$row->drm_required);
        $this->assertSame('drm', $row->access_policy);
    }

    public function test_project_video_media_failed_sets_status_errored(): void {
        global $DB;
        $asset = $this->insert_asset(['fastpix_id' => 'media-fail']);
        $event = $this->build_event('video.media.failed', 'media-fail');

        (new projector())->project($event);

        $row = $DB->get_record(self::TABLE, ['id' => $asset->id]);
        $this->assertSame('errored', $row->status);
    }

    public function test_project_video_media_deleted_sets_deleted_at(): void {
        global $DB;
        $asset = $this->insert_asset(['fastpix_id' => 'media-del']);
        $event = $this->build_event('video.media.deleted', 'media-del');

        (new projector())->project($event);

        $row = $DB->get_record(self::TABLE, ['id' => $asset->id]);
        $this->assertNotEmpty($row->deleted_at);
    }

    // ============ B. Total ordering =====================================

    public function test_project_drops_older_event_when_last_event_at_is_newer(): void {
        global $DB;
        $asset = $this->insert_asset([
            'fastpix_id'    => 'media-ord-1',
            'last_event_at' => 2000,
            'last_event_id' => 'evt-100',
            'status'        => 'ready',
        ]);

        $event = $this->build_event('video.media.failed', 'media-ord-1', [
            'occurredAt' => 1500,
            'id'         => 'evt-50',
        ]);
        (new projector())->project($event);

        $row = $DB->get_record(self::TABLE, ['id' => $asset->id]);
        $this->assertSame('ready', $row->status);            // not flipped to errored
        $this->assertSame('evt-100', $row->last_event_id);   // not advanced
        $this->assertSame(2000, (int)$row->last_event_at);
    }

    public function test_project_applies_newer_event(): void {
        global $DB;
        $asset = $this->insert_asset([
            'fastpix_id'    => 'media-ord-2',
            'last_event_at' => 1000,
            'last_event_id' => 'evt-A',
        ]);

        $event = $this->build_event('video.media.failed', 'media-ord-2', [
            'occurredAt' => 2000,
            'id'         => 'evt-B',
        ]);
        (new projector())->project($event);

        $row = $DB->get_record(self::TABLE, ['id' => $asset->id]);
        $this->assertSame(2000, (int)$row->last_event_at);
        $this->assertSame('evt-B', $row->last_event_id);
        $this->assertSame('errored', $row->status);
    }

    public function test_project_drops_equal_timestamp_with_lex_smaller_event_id(): void {
        global $DB;
        $asset = $this->insert_asset([
            'fastpix_id'    => 'media-tie-1',
            'last_event_at' => 2000,
            'last_event_id' => 'evt-Z',
            'status'        => 'ready',
        ]);

        $event = $this->build_event('video.media.failed', 'media-tie-1', [
            'occurredAt' => 2000,
            'id'         => 'evt-A',
        ]);
        (new projector())->project($event);

        $row = $DB->get_record(self::TABLE, ['id' => $asset->id]);
        $this->assertSame('ready', $row->status);
        $this->assertSame('evt-Z', $row->last_event_id);
    }

    public function test_project_applies_equal_timestamp_with_lex_larger_event_id(): void {
        global $DB;
        $asset = $this->insert_asset([
            'fastpix_id'    => 'media-tie-2',
            'last_event_at' => 2000,
            'last_event_id' => 'evt-A',
        ]);

        $event = $this->build_event('video.media.failed', 'media-tie-2', [
            'occurredAt' => 2000,
            'id'         => 'evt-Z',
        ]);
        (new projector())->project($event);

        $row = $DB->get_record(self::TABLE, ['id' => $asset->id]);
        $this->assertSame('errored', $row->status);
        $this->assertSame('evt-Z', $row->last_event_id);
    }

    public function test_project_drops_same_event_id_idempotent(): void {
        global $DB;
        $asset = $this->insert_asset([
            'fastpix_id'    => 'media-tie-3',
            'last_event_at' => 2000,
            'last_event_id' => 'evt-X',
            'status'        => 'ready',
        ]);

        $event = $this->build_event('video.media.failed', 'media-tie-3', [
            'occurredAt' => 2000,
            'id'         => 'evt-X',
        ]);
        (new projector())->project($event);

        $row = $DB->get_record(self::TABLE, ['id' => $asset->id]);
        $this->assertSame('ready', $row->status); // unchanged
    }

    // ============ C. Locking ============================================

    public function test_project_acquires_and_releases_lock_on_success(): void {
        $this->insert_asset(['fastpix_id' => 'media-lock-1']);

        $event = $this->build_event('video.media.failed', 'media-lock-1');
        (new projector())->project($event);

        // After release, we can re-acquire the same lock immediately.
        $factory = \core\lock\lock_config::get_lock_factory('local_fastpix_projector');
        $lock = $factory->get_lock('asset_media-lock-1', 1);
        $this->assertNotFalse($lock);
        $lock->release();
    }

    public function test_project_releases_lock_when_handler_throws(): void {
        global $DB;
        // Pre-occupy a unique playback_id on a sibling row, so that when
        // project() tries to set the same value on this row the UNIQUE index
        // throws inside update_record.
        $this->insert_asset(['fastpix_id' => 'media-lock-sib', 'playback_id' => 'pb-collide']);
        $this->insert_asset(['fastpix_id' => 'media-lock-2',  'playback_id' => null]);

        $event = $this->ready_event('media-lock-2', [
            (object)['id' => 'pb-collide', 'accessPolicy' => 'private'],
        ]);

        $threw = false;
        try {
            (new projector())->project($event);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'expected an exception from update_record on UNIQUE collision');

        // Lock for media-lock-2 must be released — re-acquire to prove it.
        $factory = \core\lock\lock_config::get_lock_factory('local_fastpix_projector');
        $lock = $factory->get_lock('asset_media-lock-2', 1);
        $this->assertNotFalse($lock);
        $lock->release();
    }

    public function test_project_throws_lock_acquisition_failed_when_lock_unavailable(): void {
        $this->insert_asset(['fastpix_id' => 'media-lock-busy']);

        $mock_factory = $this->createMock(\core\lock\lock_factory::class);
        $mock_factory->method('get_lock')->willReturn(false);

        $this->expectException(\local_fastpix\exception\lock_acquisition_failed::class);

        $event = $this->build_event('video.media.failed', 'media-lock-busy');
        (new projector($mock_factory))->project($event);
    }

    // ============ D. Edge cases =========================================

    public function test_project_skips_account_level_events_no_lock_no_db(): void {
        global $DB;
        $event = $this->build_event('video.live_stream.created', 'whatever', [
            'object' => (object)['type' => 'account', 'id' => ''],
        ]);

        (new projector())->project($event);

        $this->assertSame(0, $DB->count_records(self::TABLE));
    }

    public function test_project_warns_on_event_for_unknown_asset_non_created_type(): void {
        global $DB;
        $event = $this->ready_event('media-unknown', [
            (object)['id' => 'pb-unknown', 'accessPolicy' => 'private'],
        ]);

        (new projector())->project($event);

        $this->assertDebuggingCalled();
        $this->assertFalse($DB->record_exists(self::TABLE, ['fastpix_id' => 'media-unknown']));
    }

    public function test_project_invalidates_both_cache_keys_after_apply(): void {
        $this->insert_asset(['fastpix_id' => 'media-cache-inv', 'playback_id' => 'pb-cache']);

        $cache = \cache::make('local_fastpix', 'asset');
        $fp_key = $this->reflect_cache_key_fastpix('media-cache-inv');
        $pb_key = $this->reflect_cache_key_playback('pb-cache');

        // Warm both keys.
        $cache->set($fp_key, (object)['stale' => true]);
        $cache->set($pb_key, (object)['stale' => true]);

        $event = $this->ready_event('media-cache-inv', [
            (object)['id' => 'pb-cache', 'accessPolicy' => 'private'],
        ]);
        (new projector())->project($event);

        $this->assertFalse($cache->get($fp_key));
        $this->assertFalse($cache->get($pb_key));
    }
}
