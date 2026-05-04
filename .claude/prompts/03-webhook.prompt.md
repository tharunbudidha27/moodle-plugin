# Prompt — Generate `webhook.php`

This is the public-facing `/local/fastpix/webhook.php` endpoint. It is HMAC-authenticated, never calls Moodle's session machinery, and bounds the request before doing anything that touches DB or framework. Single signature header, no timestamp.

Variables to fill in: none.

---

```
You are @webhook-processing for the local_fastpix Moodle plugin.

CONTEXT YOU HAVE READ:
- 01-local-fastpix.md §13 (webhook endpoint), §13.2 (verifier).
- The verifier already exists at classes/webhook/verifier.php.
- `\local_fastpix\service\rate_limiter_service` exists.
- `\local_fastpix\task\process_webhook` (adhoc) exists.

TASK: Generate `local/fastpix/webhook.php`.

REQUIREMENTS — execute in EXACTLY this order:
1. require_once config.php and setuplib.php.
2. Bound CONTENT_LENGTH to 1MB; return 413 if exceeded.
3. Read raw body via file_get_contents('php://input') BEFORE any framework parsing.
4. Per-IP rate limit via rate_limiter_service: 500/min, MUC token bucket, fail-open.
   On exceed: HTTP 429.
5. HMAC verify via verifier::verify($raw_body, $_SERVER['HTTP_FASTPIX_SIGNATURE'] ?? '').
   Single header. NO timestamp.
   On failure: emit \local_fastpix\event\webhook_invalid (with ip + reason),
   HTTP 401, EXIT. DO NOT log the raw body.
6. Idempotent insert into `local_fastpix_webhook_event`:
   - provider_event_id, event_type, event_created_at, payload (raw body),
     signature (header verbatim), status='received', received_at=time().
   - On dml_write_exception: this is a duplicate → HTTP 200 EXIT (success path).
7. Enqueue process_webhook adhoc task with set_custom_data(['provider_event_id' => $event->id]).
8. HTTP 200 EXIT.

DO NOT:
- Call require_login, require_sesskey, or any capability check.
  This endpoint is HMAC-authenticated.
- Log the raw body (attacker-controlled).
- Call the projector synchronously — work happens in the adhoc task.
- Add a timestamp header check.

OUTPUT: PHP file only.
```
