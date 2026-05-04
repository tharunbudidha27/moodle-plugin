# Skill 06 — Implement Webhook Endpoint with HMAC + Idempotency + Adhoc Enqueue

**Owner agent:** `@webhook-processing`.

**When to invoke:** Phase 5, step 1. Also generates the verifier.

---

## Inputs

The webhook lifecycle from §13.1 of `01-local-fastpix.md`.

## Outputs

- `local/fastpix/webhook.php`
- `local/fastpix/classes/webhook/verifier.php`

## Steps (`webhook.php`)

Execute in EXACTLY this order:

1. `require_once config.php` and `setuplib.php`.
2. **Bound CONTENT_LENGTH to 1MB** before reading body. Return 413 if exceeded. (DoS protection.)
3. **Read raw body** via `file_get_contents('php://input')` BEFORE any framework parsing.
4. **Per-IP rate limit**: 500/min token bucket via `rate_limiter_service`. Fail-open on cache exception. Return 429 if exceeded.
5. **HMAC verify** via `verifier::verify($raw_body, $_SERVER['HTTP_FASTPIX_SIGNATURE'] ?? '')`. Single header. NO timestamp.
6. On invalid HMAC: emit `webhook_invalid` event with `ip` + `reason`, return 401, EXIT. **DO NOT log the raw body** (attacker-controlled).
7. **Idempotent insert** into `local_fastpix_webhook_event`. On `dml_write_exception` (duplicate provider_event_id) → return 200 EXIT (success path).
8. **Enqueue** `process_webhook` adhoc task with `set_custom_data(['provider_event_id' => $event->id])`.
9. Return 200 EXIT. Target p99 ≤ 500ms.

## Steps (`verifier.php`)

```php
namespace local_fastpix\webhook;

class verifier {

    private const ROTATION_WINDOW_SECONDS = 1800;

    public function verify(string $raw_body, string $signature_header): \stdClass {
        if ($signature_header === '') {
            throw new \local_fastpix\exception\hmac_invalid('missing_header');
        }

        $current = (string)get_config('local_fastpix', 'webhook_secret_current');
        $previous = (string)get_config('local_fastpix', 'webhook_secret_previous');
        $rotated_at = (int)get_config('local_fastpix', 'webhook_secret_rotated_at');

        if ($current === '') {
            throw new \local_fastpix\exception\hmac_invalid('no_secret_configured');
        }

        if ($this->signature_matches($raw_body, $signature_header, $current)) {
            return $this->parse_event($raw_body);
        }

        if ($previous !== '' && (time() - $rotated_at) < self::ROTATION_WINDOW_SECONDS) {
            if ($this->signature_matches($raw_body, $signature_header, $previous)) {
                return $this->parse_event($raw_body);
            }
        }

        throw new \local_fastpix\exception\hmac_invalid('signature_mismatch');
    }

    private function signature_matches(string $body, string $header, string $secret): bool {
        $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));
        return hash_equals($expected, $header);  // NEVER ===
    }

    private function parse_event(string $raw_body): \stdClass {
        $payload = json_decode($raw_body);
        if (!is_object($payload)) {
            throw new \local_fastpix\exception\hmac_invalid('invalid_json');
        }
        return (object)[
            'id'         => $payload->id ?? null,
            'type'       => $payload->type ?? '',
            'created_at' => isset($payload->createdAt) ? strtotime($payload->createdAt) : time(),
            'object'     => $payload->object ?? null,
            'data'       => $payload->data ?? null,
        ];
    }
}
```

## Constraints

- **Webhook is HMAC-authenticated.** No `require_login`, no `require_sesskey`, no capability check.
- **NO timestamp header.** FastPix doesn't send one. Reject any PR that adds a timestamp check.
- **`hash_equals`** for signature comparison. NEVER `===`.
- **DO NOT log the raw body** on invalid HMAC.
- **`signature` column** stores the header verbatim (forensic trail).
- **Endpoint MUST return 200 within 500ms p99.** Heavy work happens in the adhoc task.

## Verification

- All 10 mandatory verifier tests in §13.3 pass.
- Webhook flood integration test (1000 events, 50% duplicates, 10% out-of-order) projects with zero corruption.
- Boundary: previous secret accepted at 29m59s, rejected at 30m1s.
