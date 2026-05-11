<?php
// FastPix webhook endpoint. HMAC-authenticated; no session, no sesskey.
// Thin HTTP wrapper around \local_fastpix\webhook\processor::process()
// since 2026-05-06 — the verify-then-record-then-enqueue pipeline lives
// in the processor so the admin "Send test event" button can drive the
// same flow without HTTP, and integration tests can do the same.
//
// HTTP-specific concerns stay here: body-size guard, per-IP rate limit,
// status-code mapping. Everything else delegates.

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
if ($raw_body === false) {
    http_response_code(400);
    die();
}

// 2a. FastPix validation ping. When the admin configures the webhook URL
//     in FastPix's dashboard, FastPix POSTs an empty body (or '{}') to
//     verify reachability — there's no signature on these probes. Must
//     return 200 so FastPix accepts the URL configuration; rejecting
//     would mark the URL as invalid in their dashboard. Validation pings
//     are NOT real events and are NOT inserted into the ledger.
$trimmed_body = trim($raw_body);
if ($trimmed_body === '' || $trimmed_body === '{}') {
    error_log(json_encode([
        'event'       => 'webhook.validation_ping',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'time'        => time(),
        'shape'       => $trimmed_body === '' ? 'empty' : 'curly_braces',
    ]));
    http_response_code(200);
    die();
}

// 3. Per-IP rate limit (fail-open on cache failure inside the limiter).
$ip = getremoteaddr() ?: 'unknown';
if (!\local_fastpix\service\rate_limiter_service::instance()->allow($ip)) {
    http_response_code(429);
    die();
}

// 4. Delegate to the processor.
$signature = $_SERVER['HTTP_FASTPIX_SIGNATURE'] ?? '';
$result = \local_fastpix\webhook\processor::process($raw_body, $signature);

switch ($result['result']) {
    case \local_fastpix\webhook\processor::RESULT_ACCEPTED:
    case \local_fastpix\webhook\processor::RESULT_DUPLICATE:
        // Duplicate is success from FastPix's perspective — they already
        // got a 200 for this event_id once and our ledger has it.
        http_response_code(200);
        break;

    case \local_fastpix\webhook\processor::RESULT_BAD_SIGNATURE:
        http_response_code(401);
        break;

    case \local_fastpix\webhook\processor::RESULT_MALFORMED_BODY:
        http_response_code(400);
        break;

    case \local_fastpix\webhook\processor::RESULT_DB_ERROR:
    default:
        // Real DB bug surfaced (FK violation, NOT NULL, etc.). Return
        // 500 so FastPix retries on its normal schedule AND ops sees
        // it in error logs. Per I1: silently 200ing here would mask
        // schema bugs.
        http_response_code(500);
        break;
}

die();
