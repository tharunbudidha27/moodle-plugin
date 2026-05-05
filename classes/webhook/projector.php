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
            if ($event_type === 'video.media.created') {
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
        $row->last_event_at = (int)$event->occurredAt;
        $row->timemodified  = time();
        $DB->update_record(self::TABLE, $row);

        // Cache invalidation MUST happen inside the lock (rule W5) so a
        // concurrent reader cannot repopulate stale data before this writer
        // releases.
        $this->invalidate_cache((string)$row->fastpix_id, $row->playback_id ?? null);
    }

    private function is_media_object(string $object_type): bool {
        return in_array($object_type, ['video.media', 'media'], true);
    }

    /**
     * Total ordering with lex tiebreak on event_id.
     */
    private function is_out_of_order(\stdClass $event, \stdClass $row): bool {
        if ($row->last_event_at === null) {
            return false;
        }

        $event_at = (int)$event->occurredAt;
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
                // The insert path already populated $row; nothing more to apply.
                return true;

            case 'video.media.ready':
                $row->status = 'ready';

                $access_policy = (string)($data->accessPolicy ?? $row->access_policy ?? 'private');
                if (!empty($data->playbackIds) && is_array($data->playbackIds)) {
                    foreach ($data->playbackIds as $pb) {
                        $policy = (string)($pb->accessPolicy ?? '');
                        if (in_array($policy, ['private', 'drm'], true)) {
                            $row->playback_id = (string)$pb->id;
                            $access_policy = $policy;
                            break;
                        }
                    }
                }
                $row->access_policy = $access_policy;
                $row->drm_required  = $access_policy === 'drm' ? 1 : 0;
                if (isset($data->duration)) {
                    $row->duration = $data->duration;
                }
                $row->has_captions = $this->count_caption_tracks($data) > 0 ? 1 : 0;
                return true;

            case 'video.media.updated':
                if (isset($data->status)) {
                    $row->status = (string)$data->status;
                }
                if (isset($data->duration)) {
                    $row->duration = $data->duration;
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
            'duration'               => $data->duration ?? null,
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
        $row->id = $DB->insert_record(self::TABLE, $row);
        return $row;
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
        $cache->delete($this->cache_key_fastpix($fastpix_id));
        if (!empty($playback_id)) {
            $cache->delete($this->cache_key_playback($playback_id));
        }
    }

    private function cache_key_fastpix(string $fastpix_id): string {
        return 'fp_' . substr(hash('sha256', $fastpix_id), 0, 32);
    }

    private function cache_key_playback(string $playback_id): string {
        return 'pb_' . substr(hash('sha256', $playback_id), 0, 32);
    }
}
