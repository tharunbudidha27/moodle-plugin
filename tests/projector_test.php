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

    public function test_project_warns_on_event_for_unknown_asset_non_insert_trigger(): void {
        global $DB;
        // `video.media.deleted` is not an insert trigger — must warn and drop.
        $event = $this->build_event('video.media.deleted', 'media-unknown');

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

    // ---- W2: asset key is event.object.id, NOT event.data.id -------------

    public function test_wrong_field_yields_wrong_asset(): void {
        global $DB;
        $correct  = $this->insert_asset(['fastpix_id' => 'media-correct', 'status' => 'created']);
        $decoy    = $this->insert_asset(['fastpix_id' => 'media-decoy',   'status' => 'created']);

        $event = (object)[
            'id'         => 'evt-w2-fixture',
            'type'       => 'video.media.ready',
            'occurredAt' => time() + 1000,
            'object'     => (object)['type' => 'video.media', 'id' => 'media-correct'],
            'data'       => (object)[
                'id'          => 'media-decoy', // wrong field; must NOT win
                'playbackIds' => [(object)['id' => 'pb-w2', 'accessPolicy' => 'public']],
            ],
        ];
        (new projector())->project($event);

        $correct_after = $DB->get_record(self::TABLE, ['fastpix_id' => 'media-correct']);
        $decoy_after   = $DB->get_record(self::TABLE, ['fastpix_id' => 'media-decoy']);

        $this->assertSame('ready', $correct_after->status);
        $this->assertSame('pb-w2', $correct_after->playback_id);
        $this->assertSame('created', $decoy_after->status);
        $this->assertNull($decoy_after->playback_id);
    }

    // ---- Real-payload regressions: apply_first_playback_id accepts public --

    public function test_real_video_media_created_with_public_playback_id_lands(): void {
        global $DB;
        $payload = json_decode(<<<'JSON'
{"type":"video.media.created","object":{"type":"media","id":"6061902b-public-1"},"id":"evt-pub-1","occurredAt":1778240000,"data":{"id":"6061902b-public-1","playbackIds":[{"id":"2da377aa-public-1","accessPolicy":"public"}],"status":"Created"}}
JSON);
        (new projector())->project($payload);

        $row = $DB->get_record(self::TABLE, ['fastpix_id' => '6061902b-public-1']);
        $this->assertNotFalse($row);
        $this->assertSame('2da377aa-public-1', $row->playback_id);
        $this->assertSame('public', $row->access_policy);
        $this->assertSame(0, (int)$row->drm_required);
    }

    public function test_real_video_media_ready_public_policy_applies_playback_and_status(): void {
        global $DB;
        $this->insert_asset(['fastpix_id' => 'media-r-pub', 'playback_id' => null, 'access_policy' => 'private']);
        $payload = json_decode(<<<'JSON'
{"type":"video.media.ready","object":{"type":"media","id":"media-r-pub"},"id":"evt-r-pub","occurredAt":1778240100,"data":{"id":"media-r-pub","playbackIds":[{"id":"pb-r-pub","accessPolicy":"public"}],"duration":"00:00:10"}}
JSON);
        (new projector())->project($payload);

        $row = $DB->get_record(self::TABLE, ['fastpix_id' => 'media-r-pub']);
        $this->assertSame('ready', $row->status);
        $this->assertSame('pb-r-pub', $row->playback_id);
        $this->assertSame('public', $row->access_policy);
        $this->assertEqualsWithDelta(10.0, (float)$row->duration, 0.001);
    }

    // ---- parse_duration HH:MM:SS branch -----------------------------------

    public function test_parse_duration_handles_hhmmss_with_fractional_seconds(): void {
        global $DB;
        $this->insert_asset(['fastpix_id' => 'media-dur-frac', 'status' => 'created']);
        $event = $this->build_event('video.media.ready', 'media-dur-frac', [
            'data' => (object)['duration' => '00:00:12.345'],
        ]);
        (new projector())->project($event);
        $row = $DB->get_record(self::TABLE, ['fastpix_id' => 'media-dur-frac']);
        $this->assertEqualsWithDelta(12.345, (float)$row->duration, 0.001);
    }

    public function test_parse_duration_leaves_existing_value_when_unparseable(): void {
        global $DB;
        $this->insert_asset([
            'fastpix_id' => 'media-dur-bad',
            'status'     => 'created',
            'duration'   => 42.0,
        ]);
        $event = $this->build_event('video.media.ready', 'media-dur-bad', [
            'data' => (object)['duration' => 'not-a-time'],
        ]);
        (new projector())->project($event);
        $row = $DB->get_record(self::TABLE, ['fastpix_id' => 'media-dur-bad']);
        $this->assertEqualsWithDelta(42.0, (float)$row->duration, 0.001);
    }

    // ---- Caption tracks --------------------------------------------------

    public function test_caption_tracks_set_has_captions_flag(): void {
        global $DB;
        $this->insert_asset(['fastpix_id' => 'media-cap', 'status' => 'created', 'has_captions' => 0]);
        $event = $this->build_event('video.media.ready', 'media-cap', [
            'data' => (object)[
                'tracks' => [
                    (object)['type' => 'subtitle'],
                    (object)['type' => 'video'],
                    (object)['type' => 'caption'],
                ],
            ],
        ]);
        (new projector())->project($event);
        $row = $DB->get_record(self::TABLE, ['fastpix_id' => 'media-cap']);
        $this->assertSame(1, (int)$row->has_captions);
    }

    // ---- Newer event types ------------------------------------------------

    public function test_video_upload_media_created_applies_playback_id_and_sets_created_status(): void {
        global $DB;
        $this->insert_asset(['fastpix_id' => 'media-upmc', 'status' => 'waiting']);
        $event = $this->build_event('video.upload.media_created', 'media-upmc', [
            'data' => (object)[
                'playbackIds' => [(object)['id' => 'pb-upmc', 'accessPolicy' => 'public']],
            ],
        ]);
        (new projector())->project($event);
        $row = $DB->get_record(self::TABLE, ['fastpix_id' => 'media-upmc']);
        $this->assertSame('pb-upmc', $row->playback_id);
        $this->assertSame('created', $row->status);
        $this->assertSame('public', $row->access_policy);
    }

    public function test_video_media_upload_sets_status_from_data(): void {
        global $DB;
        $this->insert_asset(['fastpix_id' => 'media-upload', 'status' => '']);
        $event = $this->build_event('video.media.upload', 'media-upload', [
            'data' => (object)['status' => 'waiting'],
        ]);
        (new projector())->project($event);
        $row = $DB->get_record(self::TABLE, ['fastpix_id' => 'media-upload']);
        $this->assertSame('waiting', $row->status);
    }

    public function test_video_media_updated_applies_status_and_duration(): void {
        global $DB;
        $this->insert_asset(['fastpix_id' => 'media-upd', 'status' => 'created']);
        $event = $this->build_event('video.media.updated', 'media-upd', [
            'data' => (object)['status' => 'ready', 'duration' => '00:01:30'],
        ]);
        (new projector())->project($event);
        $row = $DB->get_record(self::TABLE, ['fastpix_id' => 'media-upd']);
        $this->assertSame('ready', $row->status);
        $this->assertEqualsWithDelta(90.0, (float)$row->duration, 0.001);
    }

    // ---- Cold-start insert from non-`video.media.created` triggers --------

    public function test_video_upload_media_created_inserts_when_asset_absent(): void {
        global $DB;
        $fxid = 'media-cold-upmc';
        $event = $this->build_event('video.upload.media_created', $fxid, [
            'data' => (object)[
                'playbackIds' => [(object)['id' => 'pb-cold-upmc', 'accessPolicy' => 'public']],
            ],
        ]);
        (new projector())->project($event);
        $row = $DB->get_record(self::TABLE, ['fastpix_id' => $fxid]);
        $this->assertNotFalse($row, 'asset row must be inserted by video.upload.media_created');
        $this->assertSame('pb-cold-upmc', $row->playback_id);
        $this->assertSame('public', $row->access_policy);
    }

    public function test_video_media_ready_inserts_when_asset_absent(): void {
        global $DB;
        $fxid = 'media-cold-ready';
        $event = $this->ready_event($fxid, [(object)['id' => 'pb-cold-ready', 'accessPolicy' => 'public']]);
        (new projector())->project($event);
        $row = $DB->get_record(self::TABLE, ['fastpix_id' => $fxid]);
        $this->assertNotFalse($row);
        $this->assertSame('ready', $row->status);
        $this->assertSame('pb-cold-ready', $row->playback_id);
        $this->assertSame('public', $row->access_policy);
    }

    public function test_video_media_upload_for_unknown_asset_is_dropped(): void {
        global $DB;
        $event = $this->build_event('video.media.upload', 'media-cold-upload', [
            'data' => (object)['status' => 'waiting'],
        ]);
        (new projector())->project($event);
        $this->assertFalse($DB->get_record(self::TABLE, ['fastpix_id' => 'media-cold-upload']));
        $this->assertDebuggingCalled();
    }

    // ---- Redaction canary (S2) -------------------------------------------

    public function test_no_secret_in_log_on_unknown_event_type(): void {
        $this->insert_asset(['fastpix_id' => 'media-unknown-type']);
        $event = $this->build_event('video.media.bogus_type', 'media-unknown-type');

        $tmp = tempnam(sys_get_temp_dir(), 'projlog_');
        $original = ini_get('error_log');
        ini_set('error_log', $tmp);
        try {
            (new projector())->project($event);
            $log = (string)file_get_contents($tmp);
        } finally {
            ini_set('error_log', $original);
            @unlink($tmp);
        }
        $this->assertDoesNotMatchRegularExpression('/eyJ[A-Za-z0-9_-]{10,}/', $log);
        $this->assertDebuggingCalled();
    }
}
