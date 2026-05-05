<?php
// FastPix webhook endpoint. HMAC-authenticated; no session, no sesskey.
// Receives a verified event, idempotently records it in the ledger, and
// enqueues an adhoc task for asynchronous projection.

define('NO_DEBUG_DISPLAY', true);
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');

// 1. Body size guard (1 MiB cap). CONTENT_LENGTH may be missing on chunked
//    transfer; treat absence as 0 (we still bail on empty body below).
$content_length = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
if ($content_length > 1048576) {
    http_response_code(413);
    die();
}

// 2. Read raw body BEFORE any framework parsing.
$raw_body = file_get_contents('php://input');
if ($raw_body === false || strlen($raw_body) === 0) {
    http_response_code(400);
    die();
}

// 3. Per-IP rate limit (fail-open on cache failure inside the limiter).
$ip = getremoteaddr() ?: 'unknown';
if (!\local_fastpix\service\rate_limiter_service::instance()->allow($ip)) {
    http_response_code(429);
    die();
}

// 4. HMAC verification (constant-time, dual-secret rotation aware).
$signature = $_SERVER['HTTP_FASTPIX_SIGNATURE'] ?? '';
if (!\local_fastpix\webhook\verifier::instance()->verify($raw_body, $signature)) {
    http_response_code(401);
    die();
}

// 5. Parse the verified payload.
$event = json_decode($raw_body);
if (!($event instanceof \stdClass) || empty($event->id) || empty($event->type)) {
    http_response_code(400);
    die();
}

// 6 + 7. Atomic ledger insert + adhoc task enqueue.
// Per REVIEW-2026-05-04 §S-3 / T1.6 — previously the insert and the
// queue_adhoc_task call were independent. If the task enqueue failed
// (DB drop, OOM, lock timeout) the ledger row was committed with
// status=pending but no task to project it; FastPix saw 200 and stopped
// retrying. Now both happen in a single transaction so a queue failure
// rolls the row back and FastPix's retry path kicks in.
global $DB;
$row = (object)[
    'provider_event_id'     => (string)$event->id,
    'event_type'            => (string)$event->type,
    'event_created_at'      => (int)($event->occurredAt ?? time()),
    'payload'               => $raw_body,
    'signature'             => $signature,
    'received_at'           => time(),
    'status'                => 'pending',
    'processing_latency_ms' => null,
];

$transaction = $DB->start_delegated_transaction();
try {
    $row->id = $DB->insert_record('local_fastpix_webhook_event', $row);

    $task = new \local_fastpix\task\process_webhook();
    $task->set_custom_data(['provider_event_id' => (string)$event->id]);
    \core\task\manager::queue_adhoc_task($task);

    $transaction->allow_commit();
} catch (\dml_write_exception $e) {
    // UNIQUE(provider_event_id) violated — FastPix retried before we
    // ack'd, the row already exists from the earlier request. Roll back
    // this attempt (Moodle's rollback re-throws the original, which we
    // swallow) and 200 so FastPix stops retrying.
    try {
        $transaction->rollback($e);
    } catch (\dml_write_exception $rethrown) {
        // Expected: Moodle's rollback re-throws.
    }
    http_response_code(200);
    die();
} catch (\Throwable $e) {
    // Any other failure (queue_adhoc_task threw, DB went away mid-insert,
    // etc.) — rollback re-throws and Moodle's error handler returns 5xx.
    // FastPix will retry; idempotent insert wins next time.
    $transaction->rollback($e);
}

// 8. Done — return fast so FastPix doesn't retry.
http_response_code(200);
die();
