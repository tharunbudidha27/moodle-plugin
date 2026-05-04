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

// 6. Idempotent ledger insert. UNIQUE(provider_event_id) is the contract.
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
try {
    $row->id = $DB->insert_record('local_fastpix_webhook_event', $row);
} catch (\dml_write_exception $e) {
    // Duplicate event_id — FastPix retried; we already have it. 200 so they stop.
    http_response_code(200);
    die();
}

// 7. Enqueue the adhoc task; projection happens out-of-band.
$task = new \local_fastpix\task\process_webhook();
$task->set_custom_data(['provider_event_id' => (string)$event->id]);
\core\task\manager::queue_adhoc_task($task);

// 8. Done — return fast so FastPix doesn't retry.
http_response_code(200);
die();
