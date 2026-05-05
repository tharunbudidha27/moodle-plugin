<?php
namespace local_fastpix\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Hard-deletes assets past their GDPR retention window (90 days from when
 * gdpr_delete_pending_at was set). Best-effort FastPix-side delete; local
 * row removal is authoritative regardless.
 */
class asset_cleanup extends \core\task\scheduled_task {

    private const TABLE = 'local_fastpix_asset';
    private const RETENTION_SECONDS = 7776000; // 90 days
    private const BATCH_SIZE = 200;

    public function get_name(): string {
        return get_string('task_asset_cleanup', 'local_fastpix');
    }

    public function execute(): void {
        global $DB;

        $cutoff = time() - self::RETENTION_SECONDS;
        $rows = $DB->get_records_select(
            self::TABLE,
            'gdpr_delete_pending_at IS NOT NULL AND gdpr_delete_pending_at < :cutoff',
            ['cutoff' => $cutoff],
            'gdpr_delete_pending_at ASC',
            '*',
            0,
            self::BATCH_SIZE,
        );

        $deleted = 0;
        foreach ($rows as $row) {
            try {
                if (!empty($row->fastpix_id)) {
                    try {
                        \local_fastpix\api\gateway::instance()->delete_media((string)$row->fastpix_id);
                    } catch (\Throwable $e) {
                        mtrace("asset_cleanup: gateway delete failed for {$row->fastpix_id}: "
                            . $e->getMessage());
                    }
                }

                $DB->delete_records(self::TABLE, ['id' => (int)$row->id]);
                $this->invalidate_cache((string)$row->fastpix_id, $row->playback_id ?? null);
                $deleted++;

            } catch (\Throwable $e) {
                // Per-row failure must not abort the batch.
                mtrace("asset_cleanup: row id={$row->id} failed: " . $e->getMessage());
            }
        }

        mtrace("asset_cleanup: hard-deleted {$deleted} asset(s) past 90-day GDPR retention");
    }

    private function invalidate_cache(string $fastpix_id, ?string $playback_id): void {
        $cache = \cache::make('local_fastpix', 'asset');
        if ($fastpix_id !== '') {
            $cache->delete('fp_' . substr(hash('sha256', $fastpix_id), 0, 32));
        }
        if (!empty($playback_id)) {
            $cache->delete('pb_' . substr(hash('sha256', $playback_id), 0, 32));
        }
    }
}
