<?php
namespace local_fastpix\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Adhoc task: project a single verified webhook event onto its asset row.
 *
 * Enqueued by webhook.php after signature verification + ledger insert.
 * Custom data: ['provider_event_id' => <FastPix event UUID>].
 *
 * Failures (other than malformed-at-enqueue) propagate so Moodle's adhoc-task
 * retry/backoff mechanism reschedules — including lock_acquisition_failed.
 */
class process_webhook extends \core\task\adhoc_task {

    private const LEDGER_TABLE = 'local_fastpix_webhook_event';

    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $event_id = (string)($data->provider_event_id ?? '');

        if ($event_id === '') {
            mtrace('process_webhook: missing provider_event_id in custom_data; dropping');
            return;
        }

        $row = $DB->get_record(self::LEDGER_TABLE, ['provider_event_id' => $event_id]);
        if ($row === false) {
            mtrace("process_webhook: ledger row not found for {$event_id}; dropping");
            return;
        }

        $event = json_decode((string)$row->payload);
        if (!($event instanceof \stdClass)) {
            mtrace("process_webhook: malformed payload for {$event_id}; dropping");
            $DB->set_field(self::LEDGER_TABLE, 'status', 'malformed', ['id' => $row->id]);
            return;
        }

        $event_type = (string)($event->type ?? '');
        $projector  = new \local_fastpix\webhook\projector();

        // lock_acquisition_failed and any other exception bubble up so the
        // adhoc-task system retries with backoff.
        $projector->project($event);

        $DB->update_record(self::LEDGER_TABLE, (object)[
            'id'                    => $row->id,
            'status'                => 'processed',
            'processing_latency_ms' => max(0, (int)((microtime(true) * 1000) - ((int)$row->received_at * 1000))),
        ]);

        mtrace("process_webhook: processed event_id={$event_id} type={$event_type}");
    }
}
