<?php
namespace local_fastpix\webhook;

use local_fastpix\exception\lock_acquisition_failed;

defined('MOODLE_INTERNAL') || die();

class projector {

    private const TABLE = 'local_fastpix_asset';
    private const LOCK_FACTORY = 'local_fastpix_projector';
    private const LOCK_WAIT_SECONDS = 5;

    private \core\lock\lock_factory $lock_factory;

    public function __construct(?\core\lock\lock_factory $lock_factory = null) {
        $this->lock_factory = $lock_factory
            ?? \core\lock\lock_config::get_lock_factory(self::LOCK_FACTORY);
    }

    /**
     * Project a verified webhook event onto the asset row.
     *
     * Acquires a per-asset lock to serialize concurrent webhooks for the same
     * asset, applies total-ordering with lex tiebreak on event_id, and
     * invalidates the asset cache (both keys) inside the lock.
     */
    public function project(\stdClass $event): void {
        $object_type = (string)($event->object->type ?? '');
        $fastpix_id  = (string)($event->object->id ?? '');

        // Account-level / non-media events are not our concern.
        if ($fastpix_id === '' || !$this->is_media_object($object_type)) {
            return;
        }

        $resource = 'asset_' . $fastpix_id;
        $lock     = $this->lock_factory->get_lock($resource, self::LOCK_WAIT_SECONDS);

        if ($lock === false) {
            throw new lock_acquisition_failed('asset_' . $fastpix_id);
        }

        try {
            $this->project_inside_lock($event, $fastpix_id);
        } finally {
            $lock->release();
        }
    }

    private function project_inside_lock(\stdClass $event, string $fastpix_id): void {
        global $DB;

        $row = $DB->get_record(self::TABLE, ['fastpix_id' => $fastpix_id]);
        $event_type = (string)($event->type ?? '');

        if ($row === false) {
            // Real FastPix direct uploads never emit `video.media.created`;
            // they go straight from `video.media.upload` (no asset yet) to
            // `video.upload.media_created` (asset exists, has playbackIds)
            // to `video.media.ready`. Out-of-order delivery may also drop the
            // earlier of those two. Accept any of these as a row-insert trigger
            // — `video.media.upload` is the only one that does NOT carry the
            // asset shape and is skipped (the next event will create the row).
            $insert_triggers = [
                'video.media.created',
                'video.upload.media_created',
                'video.media.ready',
                'video.media.updated',
            ];
            if (in_array($event_type, $insert_triggers, true)) {
                $row = $this->insert_from_created_event($event, $fastpix_id);
            } else {
                debugging(
                    "projector: event {$event_type} for unknown asset {$fastpix_id}",
                    DEBUG_DEVELOPER,
                );
                return;
            }
        }

        if ($this->is_out_of_order($event, $row)) {
            return;
        }

        if (!$this->handle_event($event, $row)) {
            return;
        }

        $row->last_event_id = (string)$event->id;
        $row->last_event_at = $this->event_timestamp($event);
        $row->timemodified  = time();
        $DB->update_record(self::TABLE, $row);

        // Cache invalidation MUST happen inside the lock (rule W5) so a
        // concurrent reader cannot repopulate stale data before this writer
        // releases.
        $this->invalidate_cache((string)$row->fastpix_id, $row->playback_id ?? null);

        // After projecting media events, link any matching upload_session
        // row by upload_id == fastpix_id (URL pulls + direct uploads).
        $this->link_upload_session((string)$row->fastpix_id);
    }

    private function is_media_object(string $object_type): bool {
        return in_array($object_type, ['video.media', 'media'], true);
    }

    /**
     * Resolve the event's wall-clock timestamp.
     *
     * Synthetic test fixtures use `occurredAt` (epoch int).
     * Real FastPix deliveries use `createdAt` (ISO 8601 with nanoseconds,
     * e.g. "2026-05-11T20:42:16.361817248Z"). PHP's strtotime() rejects
     * nanosecond precision on some builds and returns false; we strip
     * the fractional component before parsing to be safe.
     *
     * Returns 0 only when no parseable timestamp is present.
     */
    private function event_timestamp(\stdClass $event): int {
        if (isset($event->occurredAt) && is_numeric($event->occurredAt)) {
            return (int)$event->occurredAt;
        }
        if (isset($event->createdAt)) {
            $iso = preg_replace('/\.\d+/', '', (string)$event->createdAt);
            $ts = strtotime($iso);
            return $ts !== false ? $ts : 0;
        }
        return 0;
    }

    /**
     * Total ordering with lex tiebreak on event_id.
     */
    private function is_out_of_order(\stdClass $event, \stdClass $row): bool {
        if ($row->last_event_at === null) {
            return false;
        }

        $event_at = $this->event_timestamp($event);
        $last_at  = (int)$row->last_event_at;

        if ($event_at < $last_at) {
            return true;
        }
        if ($event_at > $last_at) {
            return false;
        }
        // Equal timestamps — tiebreak by event_id; smaller-or-equal IDs lose.
        return strcmp((string)$event->id, (string)$row->last_event_id) <= 0;
    }

    /**
     * Apply the event's data onto $row. Returns true if applied, false if the
     * event type was unhandled (caller still records last_event_* on truthy).
     */
    private function handle_event(\stdClass $event, \stdClass $row): bool {
        $type = (string)($event->type ?? '');
        $data = $event->data ?? new \stdClass();

        switch ($type) {
            case 'video.media.created':
                // Real FastPix `video.media.created` carries data.playbackIds
                // (observed 2026-05-08). Insert path also reads them, but if
                // the row was inserted from an earlier `.upload` event the
                // playback id needs to be applied here.
                $this->apply_first_playback_id($data, $row);
                return true;

            case 'video.media.upload':
                if (isset($data->status)) {
                    $row->status = (string)$data->status;
                }
                return true;

            case 'video.upload.media_created':
                $this->apply_first_playback_id($data, $row);
                if ($row->status === 'waiting' || $row->status === '') {
                    $row->status = 'created';
                }
                return true;

            case 'video.media.ready':
                $row->status = 'ready';
                $this->apply_first_playback_id($data, $row);
                $duration = $this->parse_duration($data->duration ?? null);
                if ($duration !== null) {
                    $row->duration = $duration;
                }
                $row->has_captions = $this->count_caption_tracks($data) > 0 ? 1 : 0;
                return true;

            case 'video.media.updated':
                if (isset($data->status)) {
                    $row->status = (string)$data->status;
                }
                $duration = $this->parse_duration($data->duration ?? null);
                if ($duration !== null) {
                    $row->duration = $duration;
                }
                return true;

            case 'video.media.failed':
                $row->status = 'errored';
                return true;

            case 'video.media.deleted':
                $row->deleted_at = time();
                return true;

            default:
                // Unhandled type — let event_dispatcher (Phase 4) take over.
                debugging("projector: no handler for {$type}", DEBUG_DEVELOPER);
                return false;
        }
    }

    private function insert_from_created_event(\stdClass $event, string $fastpix_id): \stdClass {
        global $DB;

        $data = $event->data ?? new \stdClass();
        $now = time();

        $row = (object)[
            'fastpix_id'             => $fastpix_id,
            'playback_id'            => null,
            'owner_userid'           => 0, // sentinel
            'title'                  => (string)($data->title ?? "Asset {$fastpix_id}"),
            'duration'               => $this->parse_duration($data->duration ?? null),
            'status'                 => (string)($data->status ?? 'created'),
            'access_policy'          => (string)($data->accessPolicy ?? 'private'),
            'drm_required'           => 0,
            'no_skip_required'       => 0,
            'has_captions'           => 0,
            'last_event_id'          => null,
            'last_event_at'          => null,
            'deleted_at'             => null,
            'gdpr_delete_pending_at' => null,
            'timecreated'            => $now,
            'timemodified'           => $now,
        ];
        $this->apply_first_playback_id($data, $row);
        $row->id = $DB->insert_record(self::TABLE, $row);
        return $row;
    }

    /**
     * Extract the first entry from data.playbackIds and write it onto $row.
     * Accepts public/private/drm. No-op when the event carries no
     * playbackIds. FastPix's real video.media.created and video.media.ready
     * payloads carry playbackIds with accessPolicy "public" by default
     * (observed 2026-05-08).
     */
    private function apply_first_playback_id(\stdClass $data, \stdClass $row): void {
        if (empty($data->playbackIds) || !is_array($data->playbackIds)) {
            return;
        }
        $pb = (object)$data->playbackIds[0];
        $id = (string)($pb->id ?? '');
        if ($id === '') {
            return;
        }
        $row->playback_id = $id;
        $policy = (string)($pb->accessPolicy ?? '');
        if (in_array($policy, ['public', 'private', 'drm'], true)) {
            $row->access_policy = $policy;
            $row->drm_required  = $policy === 'drm' ? 1 : 0;
        }
    }

    /**
     * FastPix sends duration as either a numeric (legacy test fixtures) or
     * "HH:MM:SS[.fff]" (real direct-upload deliveries). The DB column is
     * numeric(10,3); a raw "HH:MM:SS" write throws dml_write_exception.
     * Returns null when value is missing/unparseable so caller leaves the
     * existing column value alone.
     */
    private function parse_duration($value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }
        if (is_string($value) && preg_match('/^(\d+):(\d+):(\d+(?:\.\d+)?)$/', $value, $m)) {
            return ((int)$m[1]) * 3600 + ((int)$m[2]) * 60 + (float)$m[3];
        }
        return null;
    }

    /**
     * FastPix uses one UUID for both upload-session and media on URL pulls.
     * Link any matching upload_session row so consumers can navigate
     * session_id → fastpix_id → asset. Idempotent — only touches rows
     * whose fastpix_id is still null.
     */
    private function link_upload_session(string $fastpix_id): void {
        global $DB;
        $DB->execute(
            "UPDATE {local_fastpix_upload_session}
                SET fastpix_id = :fpid, state = 'created'
              WHERE upload_id = :upid AND fastpix_id IS NULL",
            ['fpid' => $fastpix_id, 'upid' => $fastpix_id]
        );
    }

    private function count_caption_tracks(\stdClass $data): int {
        if (empty($data->tracks) || !is_array($data->tracks)) {
            return 0;
        }
        $count = 0;
        foreach ($data->tracks as $track) {
            $kind = (string)($track->type ?? '');
            if ($kind === 'subtitle' || $kind === 'caption') {
                $count++;
            }
        }
        return $count;
    }

    // ---- Cache invalidation (mirrors asset_service helpers) -------------

    private function invalidate_cache(string $fastpix_id, ?string $playback_id): void {
        $cache = \cache::make('local_fastpix', 'asset');
        $cache->delete(\local_fastpix\util\cache_keys::fastpix($fastpix_id));
        if (!empty($playback_id)) {
            $cache->delete(\local_fastpix\util\cache_keys::playback($playback_id));
        }
    }

    /**
     * Reflection seam for tests that verify the projector targets the same
     * keys as asset_service. Formula lives in
     * \local_fastpix\util\cache_keys.
     */
    private function cache_key_fastpix(string $fastpix_id): string {
        return \local_fastpix\util\cache_keys::fastpix($fastpix_id);
    }

    private function cache_key_playback(string $playback_id): string {
        return \local_fastpix\util\cache_keys::playback($playback_id);
    }
}
