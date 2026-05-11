<?php
namespace local_fastpix\webhook;

defined('MOODLE_INTERNAL') || die();

/**
 * Verify-record-enqueue pipeline extracted from webhook.php.
 *
 * Both the HTTP endpoint (webhook.php) and the admin "Send test event"
 * button (\local_fastpix\external\send_test_event) drive the same flow
 * through this processor so the projection contract is exercised by
 * both surfaces.
 *
 * Inputs:
 *   - $raw_body: the bytes of the POST body (must be read via
 *     file_get_contents('php://input') BEFORE any framework parsing).
 *   - $signature_header: FastPix-Signature header value.
 *
 * Outputs (array shape):
 *   [
 *     'result'    => RESULT_*,                  (one of the constants below)
 *     'ledger_id' => int|null,                  (row id when ACCEPTED/DUPLICATE)
 *     'event_id'  => string|null,               (provider_event_id when known)
 *     'error'     => string|null,               (human-readable reason on rejection)
 *   ]
 */
class processor {

    public const RESULT_ACCEPTED       = 'accepted';
    public const RESULT_DUPLICATE      = 'duplicate';
    public const RESULT_BAD_SIGNATURE  = 'bad_signature';
    public const RESULT_MALFORMED_BODY = 'malformed_body';
    public const RESULT_DB_ERROR       = 'db_error';

    private const LEDGER_TABLE = 'local_fastpix_webhook_event';

    public static function process(string $raw_body, string $signature_header): array {
        global $DB;

        // 1. Signature verification (rule S3 — hash_equals via verifier).
        if (!verifier::instance()->verify($raw_body, $signature_header)) {
            return [
                'result'    => self::RESULT_BAD_SIGNATURE,
                'ledger_id' => null,
                'event_id'  => null,
                'error'     => 'signature verification failed',
            ];
        }

        // 2. Parse JSON.
        $event = json_decode($raw_body);
        if (!($event instanceof \stdClass)) {
            return [
                'result'    => self::RESULT_MALFORMED_BODY,
                'ledger_id' => null,
                'event_id'  => null,
                'error'     => 'JSON decode failed',
            ];
        }

        $event_id   = isset($event->id) ? (string)$event->id : '';
        $event_type = isset($event->type) ? (string)$event->type : '';
        if ($event_id === '' || $event_type === '') {
            return [
                'result'    => self::RESULT_MALFORMED_BODY,
                'ledger_id' => null,
                'event_id'  => $event_id !== '' ? $event_id : null,
                'error'     => 'missing required field id/type',
            ];
        }

        // 3. Idempotent ledger insert. UNIQUE on provider_event_id catches
        // duplicates as dml_write_exception — duplicate is success (W1).
        $event_created_at = isset($event->occurredAt) ? (int)$event->occurredAt : time();

        try {
            $transaction = $DB->start_delegated_transaction();

            try {
                $ledger_id = $DB->insert_record(self::LEDGER_TABLE, (object)[
                    'provider_event_id'     => $event_id,
                    'event_type'            => $event_type,
                    'event_created_at'      => $event_created_at,
                    'payload'               => $raw_body,
                    'signature'             => $signature_header,
                    'status'                => 'pending',
                    'received_at'           => time(),
                    'processing_latency_ms' => 0,
                ]);
            } catch (\dml_write_exception $e) {
                // Duplicate — UNIQUE constraint hit. W1: duplicate is success.
                $transaction->allow_commit();
                $existing = $DB->get_record(
                    self::LEDGER_TABLE,
                    ['provider_event_id' => $event_id],
                    'id'
                );
                return [
                    'result'    => self::RESULT_DUPLICATE,
                    'ledger_id' => $existing ? (int)$existing->id : null,
                    'event_id'  => $event_id,
                    'error'     => null,
                ];
            }

            // 4. Enqueue adhoc task for asynchronous projection.
            $task = new \local_fastpix\task\process_webhook();
            $task->set_custom_data((object)['provider_event_id' => $event_id]);
            \core\task\manager::queue_adhoc_task($task);

            $transaction->allow_commit();

            return [
                'result'    => self::RESULT_ACCEPTED,
                'ledger_id' => (int)$ledger_id,
                'event_id'  => $event_id,
                'error'     => null,
            ];

        } catch (\Throwable $e) {
            return [
                'result'    => self::RESULT_DB_ERROR,
                'ledger_id' => null,
                'event_id'  => $event_id,
                'error'     => $e->getMessage(),
            ];
        }
    }
}
