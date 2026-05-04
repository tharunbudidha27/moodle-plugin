# Prompt — Generate `gateway.php`

Use this prompt to ask Claude (acting as `@gateway-integration`) to produce the FastPix HTTP gateway. The prompt is fully self-contained and enforces every gateway rule in the architecture doc.

Variables to fill in: none. The agent reads its inputs from `01-local-fastpix.md` §3 and §11.

---

```
You are @gateway-integration for the local_fastpix Moodle plugin.

CONTEXT YOU HAVE READ:
- 00-system-overview.md §5.3 (3-layer rule), §8 (FastPix contract), §11 (failure handling)
- 01-local-fastpix.md §3 (FastPix endpoints), §11 (gateway spec)

TASK: Generate `local/fastpix/classes/api/gateway.php`.

REQUIREMENTS (NON-NEGOTIABLE):
1. Namespace: `local_fastpix\api`. Class name: `gateway`.
2. Singleton via `gateway::instance()`. Constructor takes (credential_service, http_client, cache_factory) for DI.
3. Two timeout profiles:
   - PROFILE_HOT (3s connect, 3s read) used ONLY by `get_media`.
   - PROFILE_STANDARD (5s connect, 30s read) used by everything else.
   - Caller never passes a timeout.
4. Methods to implement: `input_video_direct_upload`, `media_create_from_url`,
   `get_media`, `delete_media`, `create_signing_key`, `delete_signing_key`,
   `health_probe`. Exact signatures from §11.1.
5. Retry: exponential 200ms / 400ms / 800ms ±25ms jitter, max 3 attempts.
   Retryable: 500, 502, 503, 504, network errors, 429 (honor Retry-After, clamp to 3s).
   NOT retryable: 4xx (except 429), parse errors.
   404 from `get_media` → `gateway_not_found` IMMEDIATELY (no retry).
   404 from `delete_media` → return silently (idempotent delete).
6. Idempotency-Key header on every WRITE: `sha256(<operation>:<owner_hash>:<payload_hash>)`.
7. Circuit breaker: keyed on `<method>:<endpoint>`, state in MUC `circuit_breaker`,
   5 consecutive failures → open 30s → half-open with one probe → close on success.
   NEVER store breaker state in static class properties — must be MUC.
8. Auth: `Authorization: Basic base64(apikey:apisecret)` from credential_service.
9. User-Agent: `local_fastpix/<version>`.
10. Structured log every call: event, endpoint, latency_ms, status_code, attempt,
    circuit_state, timeout_profile. Use the logging helper, not `error_log` directly.
11. NEVER log apikey, apisecret, JWT-shaped strings, or signatures.
12. Use `\core\http_client`, NEVER `curl_*` or any other HTTP client.
13. health_probe returns false on failure, NEVER throws.

EXCEPTIONS TO USE (already exist in `classes/exception/`):
- gateway_unavailable (5xx after retries)
- gateway_invalid_response (malformed body)
- gateway_not_found (404 from get_media)

DO NOT:
- Add a "createToken" or "playback_create_token" method — that endpoint does not exist
  on FastPix. JWT signing is local; see jwt_signing_service.
- Cache responses in this layer — that's asset_service's job.
- Make any method synchronous-blocking longer than its profile's read timeout.

OUTPUT: Just the PHP file, no commentary. Include full doc comments matching §11.1.
```
