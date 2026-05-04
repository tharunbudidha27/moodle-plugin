<?php
namespace local_fastpix\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Daily sweep of expired upload sessions. Marks state='orphaned' on rows
 * past their TTL; best-effort DELETE on the FastPix side. Auditability is
 * preserved — rows are not removed.
 */
class orphan_sweeper extends \core\task\scheduled_task {

    private const TABLE = 'local_fastpix_upload_session';
    private const BATCH_SIZE = 500;

    public function get_name(): string {
        return get_string('task_orphan_sweeper', 'local_fastpix');
    }

    public function execute(): void {
        global $DB;

        $now = time();
        $rows = $DB->get_records_select(
            self::TABLE,
            "state = :state AND expires_at < :now",
            ['state' => 'pending', 'now' => $now],
            'expires_at ASC',
            '*',
            0,
            self::BATCH_SIZE,
        );

        $orphaned = 0;
        foreach ($rows as $row) {
            if (!empty($row->upload_id)) {
                try {
                    \local_fastpix\api\gateway::instance()->delete_media($row->upload_id);
                } catch (\Throwable $e) {
                    mtrace("orphan_sweeper: gateway delete failed for upload_id={$row->upload_id}: "
                        . $e->getMessage());
                }
            }

            $DB->set_field(self::TABLE, 'state', 'orphaned', ['id' => $row->id]);
            $orphaned++;
        }

        mtrace("orphan_sweeper: orphaned {$orphaned} expired session(s)");
    }
}
