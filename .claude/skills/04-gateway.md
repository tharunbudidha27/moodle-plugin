# Skill 04 — Implement Gateway with Retry, Circuit Breaker, Idempotency

**Owner agent:** `@gateway-integration`.

**When to invoke:** Phase 2, step 5. Re-invoke for every new gateway method.

---

## Inputs

The list of FastPix endpoints from §3.2 of `01-local-fastpix.md`:

| Method | Path | Hot path? |
|---|---|---|
| `POST` | `/v1/on-demand/upload` | No |
| `POST` | `/v1/on-demand` | No |
| `GET` | `/v1/on-demand/{mediaId}` | **Yes** |
| `DELETE` | `/v1/on-demand/{mediaId}` | No |
| `POST` | `/v1/iam/signing-keys` | No |
| `DELETE` | `/v1/iam/signing-keys/{kid}` | No |

## Outputs

- `local/fastpix/classes/api/gateway.php` implementing the methods in §11.1 contract.

## Steps

### 1. Class shell

```php
namespace local_fastpix\api;

class gateway {
    private const PROFILE_HOT      = ['connect' => 3, 'read' => 3];
    private const PROFILE_STANDARD = ['connect' => 5, 'read' => 30];

    private const RETRY_DELAYS_MS = [200, 400, 800];
    private const RETRY_JITTER_MS = 25;
    private const RETRY_MAX_ATTEMPTS = 3;

    private const BREAKER_THRESHOLD = 5;
    private const BREAKER_OPEN_SECONDS = 30;

    public static function instance(): self { /* ... */ }

    private function __construct(
        private \core\http_client $http,
        private \cache_application $breaker_cache,
        private credential_service $credentials
    ) {}
}
```

### 2. The seven methods (signatures from §11.1)

- `input_video_direct_upload(string $owner_hash, array $metadata, string $access_policy, ?string $drm_config_id): \stdClass` — POST `/v1/on-demand/upload`, PROFILE_STANDARD, idempotency-key, structured log.
- `media_create_from_url(string $source_url, string $owner_hash, array $metadata, string $access_policy, ?string $drm_config_id): \stdClass` — POST `/v1/on-demand`, PROFILE_STANDARD.
- `get_media(string $fastpix_id): \stdClass` — GET `/v1/on-demand/{mediaId}`, **PROFILE_HOT**, no idempotency-key (read), 404 → `gateway_not_found` immediately.
- `delete_media(string $fastpix_id): void` — DELETE `/v1/on-demand/{mediaId}`, PROFILE_STANDARD, idempotency-key, 404 silent (idempotent).
- `create_signing_key(): \stdClass` — POST `/v1/iam/signing-keys`, PROFILE_STANDARD, no body, returns `{id, privateKey, createdAt}`.
- `delete_signing_key(string $kid): void` — DELETE `/v1/iam/signing-keys/{kid}`.
- `health_probe(): bool` — GET to a low-cost endpoint; returns false on any failure (NEVER throws).

### 3. Private `request()` helper

```php
private function request(
    string $method,
    string $path,
    ?array $body,
    array $profile,
    ?string $idempotency_key = null,
    array $query = []
): \stdClass {
    $endpoint_key = "{$method}:{$path}";

    // Circuit breaker check
    if ($this->breaker_is_open($endpoint_key)) {
        throw new \local_fastpix\exception\gateway_unavailable("circuit_open:{$endpoint_key}");
    }

    $start = microtime(true);
    $attempt = 0;
    $last_error = null;

    while ($attempt < self::RETRY_MAX_ATTEMPTS) {
        $attempt++;
        try {
            $response = $this->http->request($method, $this->base_url() . $path, [
                'connect_timeout' => $profile['connect'],
                'timeout'         => $profile['read'],
                'auth'            => [$this->credentials->apikey(), $this->credentials->apisecret()],
                'headers'         => $this->build_headers($idempotency_key),
                'json'            => $body,
                'query'           => $query,
                'http_errors'     => false,
            ]);

            $status = $response->getStatusCode();
            $latency_ms = (int)((microtime(true) - $start) * 1000);

            $this->log_call($endpoint_key, $latency_ms, $status, $attempt, $profile);

            if ($status >= 200 && $status < 300) {
                $this->breaker_record_success($endpoint_key);
                return $this->decode_body($response);
            }

            // 404 special-case: get_media throws immediately; delete is silent
            if ($status === 404) {
                if ($method === 'GET' && str_starts_with($path, '/v1/on-demand/')) {
                    throw new \local_fastpix\exception\gateway_not_found($path);
                }
                if ($method === 'DELETE') {
                    return new \stdClass(); // idempotent silent
                }
            }

            // Retryable?
            if (!$this->is_retryable($status)) {
                $this->breaker_record_failure($endpoint_key);
                throw new \local_fastpix\exception\gateway_unavailable("status_{$status}:{$endpoint_key}");
            }

            // 429: honor Retry-After (clamped)
            $delay_ms = $status === 429
                ? min(3000, $this->parse_retry_after($response) * 1000)
                : self::RETRY_DELAYS_MS[$attempt - 1] + random_int(-self::RETRY_JITTER_MS, self::RETRY_JITTER_MS);

            $last_error = "status_{$status}";
        } catch (\GuzzleHttp\Exception\ConnectException | \GuzzleHttp\Exception\TransferException $e) {
            $last_error = 'network_' . get_class($e);
            $delay_ms = self::RETRY_DELAYS_MS[$attempt - 1] + random_int(-self::RETRY_JITTER_MS, self::RETRY_JITTER_MS);
        }

        if ($attempt < self::RETRY_MAX_ATTEMPTS) {
            usleep($delay_ms * 1000);
        }
    }

    $this->breaker_record_failure($endpoint_key);
    throw new \local_fastpix\exception\gateway_unavailable("retries_exhausted:{$last_error}");
}

private function is_retryable(int $status): bool {
    return in_array($status, [500, 502, 503, 504, 429], true);
}
```

### 4. Circuit breaker (MUC-backed)

```php
private function breaker_is_open(string $key): bool {
    $state = $this->breaker_cache->get($key) ?: ['failures' => 0, 'open_until' => 0];
    if ($state['open_until'] > time()) {
        return true; // open
    }
    return false; // closed or half-open
}

private function breaker_record_failure(string $key): void {
    $state = $this->breaker_cache->get($key) ?: ['failures' => 0, 'open_until' => 0];
    $state['failures']++;
    if ($state['failures'] >= self::BREAKER_THRESHOLD) {
        $state['open_until'] = time() + self::BREAKER_OPEN_SECONDS;
    }
    $this->breaker_cache->set($key, $state);
}

private function breaker_record_success(string $key): void {
    $this->breaker_cache->delete($key); // reset
}
```

### 5. Idempotency-Key derivation

```php
private function idempotency_key(string $operation, string $owner_hash, ?array $body): string {
    $payload_hash = $body !== null ? hash('sha256', json_encode($body)) : '-';
    return hash('sha256', "{$operation}:{$owner_hash}:{$payload_hash}");
}
```

### 6. Structured logging

Every call logs: `event=gateway.call`, `endpoint`, `latency_ms`, `status_code`, `attempt`, `circuit_state`, `timeout_profile`. Via the logging helper from Skill 14.

## Constraints

- **Two profiles, caller doesn't choose.** Each method picks. PROFILE_HOT only for `get_media`.
- **Breaker state in MUC.** Multi-FPM correctness.
- **`\core\http_client`, never `curl_*`.** Rule M8.
- **Never log credentials, JWTs, signatures.** Redaction-canary test.
- **Static-analysis CI guard finds zero `fastpix.io` strings outside this file.** Rule A2.

## Verification

All 13 mandatory tests in §11.3 of `01-local-fastpix.md`:
- Successful call returns parsed body.
- 5xx retries 3 times then throws `gateway_unavailable`.
- 502/503/504 each retried.
- 429 respects `Retry-After`.
- 400 throws immediately, no retry.
- 404 on `get_media` throws `gateway_not_found` immediately.
- 404 on `delete_media` returns silently.
- Network timeout retries 3 times.
- Circuit breaker opens / half-opens / closes correctly.
- Idempotency-key deterministic.
- Credentials never appear in log output.
- Multi-worker breaker state shared via MUC.
- Hot-path timeout: 5s mock endpoint causes `get_media` fail at 3s but `input_video_direct_upload` succeeds at 5s.
