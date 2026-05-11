<?php
namespace local_fastpix\service;

defined('MOODLE_INTERNAL') || die();

class asset_service {

    private const TABLE = 'local_fastpix_asset';

    // ---- Public read API -------------------------------------------------

    public static function get_by_fastpix_id(string $fastpix_id, bool $include_deleted = false): ?\stdClass {
        $cache = self::cache();
        $key = self::cache_key_fastpix($fastpix_id);

        $row = $cache->get($key);
        if ($row === false) {
            global $DB;
            $row = $DB->get_record(self::TABLE, ['fastpix_id' => $fastpix_id]);
            if ($row) {
                $cache->set($key, $row);
                if (!empty($row->playback_id)) {
                    $cache->set(self::cache_key_playback($row->playback_id), $row);
                }
            }
        }

        if (!$row) {
            return null;
        }
        if (!$include_deleted && !empty($row->deleted_at)) {
            return null;
        }
        return $row;
    }

    public static function get_by_playback_id(string $playback_id, bool $include_deleted = false): ?\stdClass {
        $cache = self::cache();
        $key = self::cache_key_playback($playback_id);

        $row = $cache->get($key);
        if ($row === false) {
            global $DB;
            $row = $DB->get_record(self::TABLE, ['playback_id' => $playback_id]);
            if ($row) {
                $cache->set($key, $row);
                $cache->set(self::cache_key_fastpix($row->fastpix_id), $row);
            }
        }

        if (!$row) {
            return null;
        }
        if (!$include_deleted && !empty($row->deleted_at)) {
            return null;
        }
        return $row;
    }

    public static function get_by_id(int $id, bool $include_deleted = false): ?\stdClass {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['id' => $id]);
        if (!$row) {
            return null;
        }
        if (!$include_deleted && !empty($row->deleted_at)) {
            return null;
        }
        return $row;
    }

    /**
     * Lookup an asset by upload_session id. ADR-013 §2 entry point.
     *
     * Returns null when the session row doesn't exist, has no fastpix_id
     * yet (webhook still in flight), or the linked asset row is
     * soft-deleted. Caching contract piggybacks on get_by_fastpix_id.
     */
    public static function get_by_upload_session_id(int $session_id): ?\stdClass {
        global $DB;
        $session = $DB->get_record(
            'local_fastpix_upload_session',
            ['id' => $session_id],
            'id, fastpix_id'
        );
        if (!$session || empty($session->fastpix_id)) {
            return null;
        }
        return self::get_by_fastpix_id((string)$session->fastpix_id);
    }

    /**
     * Read-path lazy fetch. May call the gateway exactly once on cold start.
     * Forbidden on write paths (rule W7).
     */
    public static function get_by_fastpix_id_or_fetch(string $fastpix_id): \stdClass {
        $asset = self::get_by_fastpix_id($fastpix_id);
        if ($asset !== null) {
            return $asset;
        }

        try {
            $remote = \local_fastpix\api\gateway::instance()->get_media($fastpix_id);
        } catch (\local_fastpix\exception\gateway_not_found $e) {
            throw new \local_fastpix\exception\asset_not_found($fastpix_id);
        }

        global $DB;

        $data = $remote->data ?? $remote;

        $playback_id = null;
        $access_policy = (string)($data->accessPolicy ?? 'private');
        if (!empty($data->playbackIds) && is_array($data->playbackIds)) {
            foreach ($data->playbackIds as $pb) {
                $policy = (string)($pb->accessPolicy ?? '');
                if (in_array($policy, ['private', 'drm'], true)) {
                    $playback_id = (string)$pb->id;
                    $access_policy = $policy;
                    break;
                }
            }
        }

        $now = time();
        $row = (object)[
            'fastpix_id'       => (string)$data->id,
            'playback_id'      => $playback_id,
            'owner_userid'     => 0,
            'title'            => (string)($data->title ?? "Imported {$data->id}"),
            'duration'         => $data->duration ?? null,
            'status'           => (string)($data->status ?? 'ready'),
            'access_policy'    => $access_policy,
            'drm_required'     => $access_policy === 'drm' ? 1 : 0,
            'no_skip_required' => 0,
            'has_captions'     => self::has_caption_track($data) ? 1 : 0,
            'last_event_id'    => null,
            'last_event_at'    => null,
            'deleted_at'       => null,
            'gdpr_delete_pending_at' => null,
            'timecreated'      => $now,
            'timemodified'     => $now,
        ];

        try {
            $row->id = $DB->insert_record(self::TABLE, $row);
        } catch (\dml_write_exception $e) {
            // UNIQUE race — another worker inserted first. Re-read the winner.
            $existing = self::get_by_fastpix_id($fastpix_id);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }

        $cache = self::cache();
        $cache->set(self::cache_key_fastpix($row->fastpix_id), $row);
        if (!empty($row->playback_id)) {
            $cache->set(self::cache_key_playback($row->playback_id), $row);
        }

        return $row;
    }

    public static function list_for_owner(int $userid, ?string $status = 'ready', int $limit = 50): array {
        global $DB;

        $conditions = ['owner_userid' => $userid];
        if ($status !== null) {
            $conditions['status'] = $status;
        }

        $rows = $DB->get_records(
            self::TABLE,
            $conditions,
            'timecreated DESC',
            '*',
            0,
            $limit,
        );

        return array_values(array_filter($rows, static fn($r) => empty($r->deleted_at)));
    }

    public static function list_for_owner_paginated(
        int $userid,
        ?string $status,
        int $offset,
        int $limit,
        string $search = '',
    ): array {
        global $DB;

        $where  = 'owner_userid = :userid AND deleted_at IS NULL';
        $params = ['userid' => $userid];

        if ($status !== null) {
            $where .= ' AND status = :status';
            $params['status'] = $status;
        }
        if ($search !== '') {
            $where .= ' AND ' . $DB->sql_like('title', ':search', false);
            $params['search'] = '%' . $DB->sql_like_escape($search) . '%';
        }

        $rows = $DB->get_records_select(
            self::TABLE,
            $where,
            $params,
            'timecreated DESC',
            '*',
            $offset,
            $limit,
        );

        return array_values($rows);
    }

    // ---- Public write API ------------------------------------------------

    public static function soft_delete(int $id): void {
        global $DB;

        $row = $DB->get_record(self::TABLE, ['id' => $id], 'id, fastpix_id, playback_id');
        if (!$row) {
            return;
        }

        $now = time();
        $DB->update_record(self::TABLE, (object)[
            'id'           => $id,
            'deleted_at'   => $now,
            'timemodified' => $now,
        ]);

        self::invalidate_cache((string)$row->fastpix_id, $row->playback_id ?? null);
    }

    // ---- Helpers ---------------------------------------------------------

    private static function cache(): \cache_application {
        return \cache::make('local_fastpix', 'asset');
    }

    /**
     * MUC area 'asset' is declared simplekeys=true, so cache keys must be
     * alphanumeric + underscore only. We hash the IDs and add a 2-char prefix
     * to keep the fastpix_id and playback_id namespaces disjoint.
     */
    private static function cache_key_fastpix(string $fastpix_id): string {
        return \local_fastpix\util\cache_keys::fastpix($fastpix_id);
    }

    private static function cache_key_playback(string $playback_id): string {
        return \local_fastpix\util\cache_keys::playback($playback_id);
    }

    private static function invalidate_cache(string $fastpix_id, ?string $playback_id): void {
        $cache = self::cache();
        $cache->delete(self::cache_key_fastpix($fastpix_id));
        if (!empty($playback_id)) {
            $cache->delete(self::cache_key_playback($playback_id));
        }
    }

    private static function has_caption_track(object $data): bool {
        if (empty($data->tracks) || !is_array($data->tracks)) {
            return false;
        }
        foreach ($data->tracks as $track) {
            if (($track->type ?? '') === 'subtitle' || ($track->type ?? '') === 'caption') {
                return true;
            }
        }
        return false;
    }
}
