# Prompt — Generate `verifier.php`

The webhook signature verifier. Single header, no timestamp, RSA-free (HMAC-SHA256 base64), dual-secret 30-min rotation window. Uses `hash_equals`. The two `DO NOT` lines at the bottom are non-negotiable: this is the most security-sensitive class in the plugin.

Variables to fill in: none.

---

```
You are @webhook-processing for the local_fastpix Moodle plugin.

CONTEXT YOU HAVE READ:
- 01-local-fastpix.md §13.2 (verifier spec) and §13.3 (mandatory tests).
- ADR webhook signature scheme correction: SINGLE header, NO timestamp.

TASK: Generate `local/fastpix/classes/webhook/verifier.php`.

REQUIREMENTS:
1. Namespace: `local_fastpix\webhook`. Class: `verifier`.
2. Const ROTATION_WINDOW_SECONDS = 1800.
3. Public method: `verify(string $raw_body, string $signature_header): \stdClass`.
   Throws \local_fastpix\exception\hmac_invalid on any failure.
4. Steps:
   a. If $signature_header === '': throw hmac_invalid('missing_header').
   b. Read webhook_secret_current, webhook_secret_previous, webhook_secret_rotated_at from get_config.
   c. If $current === '': throw hmac_invalid('no_secret_configured').
   d. Try current secret; if matches, parse and return.
   e. Else if $previous !== '' AND (time() - $rotated_at) < ROTATION_WINDOW_SECONDS: try previous; if matches, return.
   f. Else: throw hmac_invalid('signature_mismatch').
5. signature_matches($body, $header, $secret):
     $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));
     return hash_equals($expected, $header);
   USE hash_equals. NEVER ===.
6. parse_event($raw_body):
     json_decode; if not object, throw hmac_invalid('invalid_json').
     Normalize to: { id, type, created_at (unix int from createdAt), object, data }.

DO NOT:
- Compare signatures with === or ==.
- Add a timestamp header check.
- Decode the body BEFORE verifying signature (verify on raw bytes).

OUTPUT: PHP file only.
```
