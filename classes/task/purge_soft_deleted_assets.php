<?php
namespace local_fastpix\task;

defined('MOODLE_INTERNAL') || die();

use local_fastpix\util\cache_keys;

/**
 * Daily hard-purge of assets that have been soft-deleted for ≥ 7 days
 * (rule W10).
 *
 * Soft-delete is the user-facing action: `asset_service::soft_delete()`
 * stamps `deleted_at` on the row and invalidates the asset cache. After
 * a 7-day grace window the row is hard-deleted by this task — caption
 * rows in `local_fastpix_track` are removed in the same loop because
 * the FK is declared without ON DELETE CASCADE.
 *
 * Distinct from `asset_cleanup`, which handles a different lifecycle:
 * GDPR-pending rows where the local delete succeeded but the FastPix
 * delete failed (gdpr_delete_pending_at).
 */
class purge_soft_deleted_assets extends \core\task\scheduled_task {

    private const ASSET_TABLE = 'local_fastpix_asset';
    private const TRACK_TABLE = 'local_fastpix_track';

    private const RETENTION_SECONDS = 604800; // 7 days (rule W10)
    private const BATCH_SIZE = 500;

    public function get_name(): string {
        return get_string('task_purge_soft_deleted_assets', 'local_fastpix');
    }

    public function execute(): void {
        global $DB;

        $start_ms = (int)(microtime(true) * 1000);
        $cutoff = time() - self::RETENTION_SECONDS;

        $rows = $DB->get_records_select(
            self::ASSET_TABLE,
            'deleted_at IS NOT NULL AND deleted_at < :cutoff',
            ['cutoff' => $cutoff],
            'deleted_at ASC',
            'id, fastpix_id, playback_id',
            0,
            self::BATCH_SIZE,
        );

        $purged = 0;
        $cache = \cache::make('local_fastpix', 'asset');

        foreach ($rows as $row) {
            try {
                $DB->delete_records(self::TRACK_TABLE, ['asset_id' => (int)$row->id]);
                $DB->delete_records(self::ASSET_TABLE, ['id' => (int)$row->id]);

                $cache->delete(cache_keys::fastpix((string)$row->fastpix_id));
                if (!empty($row->playback_id)) {
                    $cache->delete(cache_keys::playback((string)$row->playback_id));
                }
                $purged++;
            } catch (\Throwable $e) {
                mtrace("purge_soft_deleted_assets: failed to purge id={$row->id}: " . $e->getMessage());
            }
        }

        $remaining = $DB->count_records_select(
            self::ASSET_TABLE,
            'deleted_at IS NOT NULL AND deleted_at < :cutoff',
            ['cutoff' => $cutoff],
        );
        $elapsed_ms = (int)(microtime(true) * 1000) - $start_ms;

        mtrace(json_encode([
            'event'           => 'task.purge_soft_deleted_assets',
            'count_purged'    => $purged,
            'count_remaining' => $remaining,
            'elapsed_ms'      => $elapsed_ms,
            'batch_size'      => self::BATCH_SIZE,
        ]));
    }
}
