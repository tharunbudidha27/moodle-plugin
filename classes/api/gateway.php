<?php
namespace local_fastpix\api;

use local_fastpix\exception\gateway_unavailable;
use local_fastpix\exception\gateway_not_found;
use local_fastpix\exception\gateway_invalid_response;
use local_fastpix\service\credential_service;

defined('MOODLE_INTERNAL') || die();

/**
 * FastPix HTTP gateway.
 *
 * The single trusted boundary between local_fastpix and api.fastpix.io.
 * Owns retry, circuit breaker, idempotency keys, two timeout profiles,
 * structured logging, and credential injection. Rule A2: no other class
 * may make HTTP calls to FastPix.
 */
class gateway {

    private const PROFILE_HOT      = ['connect' => 3, 'read' => 3];
    private const PROFILE_STANDARD = ['connect' => 5, 'read' => 30];
    private const PROFILE_HEALTH   = ['connect' => 1, 'read' => 1];

    private const RETRY_DELAYS_MS    = [200, 400, 800];
    private const RETRY_JITTER_MS    = 25;
    private const RETRY_MAX_ATTEMPTS = 3;
    private const RETRY_AFTER_CAP_MS = 3000;

    private const BREAKER_THRESHOLD    = 5;
    private const BREAKER_OPEN_SECONDS = 30;

    private const DEFAULT_BASE_URL = 'https://api.fastpix.io';

    /** Max response body length the gateway will decode (defensive). */
    private const MAX_RESPONSE_BYTES = 5242880; // 5 MiB

    private static ?self $instance = null;

    private function __construct(
        private \core\http_client $http,
        private \cache_application $breaker_cache,
        private credential_service $credentials,
    ) {}

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self(
                new \core\http_client(),
                \cache::make('local_fastpix', 'circuit_breaker'),
                credential_service::instance(),
            );
        }
        return self::$instance;
    }

    public static function reset(): void {
        self::$instance = null;
    }

    // ---- Public API -------------------------------------------------------

    /**
     * POST /v1/on-demand/upload — create a direct file-upload session.
     */
    public function input_video_direct_upload(
        string $owner_hash,
        array $metadata,
        string $access_policy,
        ?string $drm_config_id,
        string $max_resolution = '1080p',
    ): \stdClass {
        $body = [
            'corsOrigin'   => '*',
            'pushMediaSettings' => [
                'metadata'      => $metadata,
                'accessPolicy'  => $access_policy,
                'maxResolution' => $max_resolution,
            ],
        ];
        if ($drm_config_id !== null && $drm_config_id !== '') {
            $body['pushMediaSettings']['drmConfigurationId'] = $drm_config_id;
        }

        return $this->request(
            'POST',
            '/v1/on-demand/upload',
            $body,
            self::PROFILE_STANDARD,
            $this->idempotency_key('input_video_direct_upload', $owner_hash, $body),
        );
    }

    /**
     * POST /v1/on-demand — create a media asset from a remote URL.
     */
    public function media_create_from_url(
        string $source_url,
        string $owner_hash,
        array $metadata,
        string $access_policy,
        ?string $drm_config_id,
        string $max_resolution = '1080p',
    ): \stdClass {
        $body = [
            'inputs'        => [['type' => 'video', 'url' => $source_url]],
            'metadata'      => $metadata,
            'accessPolicy'  => $access_policy,
            'maxResolution' => $max_resolution,
        ];
        if ($drm_config_id !== null && $drm_config_id !== '') {
            $body['drmConfigurationId'] = $drm_config_id;
        }

        return $this->request(
            'POST',
            '/v1/on-demand',
            $body,
            self::PROFILE_STANDARD,
            $this->idempotency_key('media_create_from_url', $owner_hash, $body),
        );
    }

    /**
     * GET /v1/on-demand/{mediaId} — hot path. PROFILE_HOT (3s/3s).
     * 404 → gateway_not_found IMMEDIATELY, no retry.
     */
    public function get_media(string $fastpix_id): \stdClass {
        return $this->request(
            'GET',
            '/v1/on-demand/' . rawurlencode($fastpix_id),
            null,
            self::PROFILE_HOT,
            null,
        );
    }

    /**
     * DELETE /v1/on-demand/{mediaId}. 404 returns silently (idempotent).
     */
    public function delete_media(string $fastpix_id): void {
        $this->request(
            'DELETE',
            '/v1/on-demand/' . rawurlencode($fastpix_id),
            null,
            self::PROFILE_STANDARD,
            $this->idempotency_key('delete_media', $fastpix_id, null),
        );
    }

    /**
     * POST /v1/iam/signing-keys — provision a new RSA signing key.
     * Returns object with id (kid), privateKey (base64 PEM), createdAt.
     */
    public function create_signing_key(): \stdClass {
        return $this->request(
            'POST',
            '/v1/iam/signing-keys',
            null,
            self::PROFILE_STANDARD,
            $this->idempotency_key('create_signing_key', '', null),
        );
    }

    /**
     * DELETE /v1/iam/signing-keys/{kid}. 404 silent.
     */
    public function delete_signing_key(string $kid): void {
        $this->request(
            'DELETE',
            '/v1/iam/signing-keys/' . rawurlencode($kid),
            null,
            self::PROFILE_STANDARD,
            $this->idempotency_key('delete_signing_key', $kid, null),
        );
    }

    /**
     * Health probe. Returns false on any failure. NEVER throws.
     */
    public function health_probe(): bool {
        try {
            $response = $this->http->request('GET', $this->base_url() . '/v1/on-demand', [
                'connect_timeout' => self::PROFILE_HEALTH['connect'],
                'timeout'         => self::PROFILE_HEALTH['read'],
                'auth'            => [$this->credentials->apikey(), $this->credentials->apisecret()],
                'headers'          => $this->build_headers(null),
                'query'            => ['limit' => 1],
                'http_errors'      => false,
                'force_ip_resolve' => 'v4',
            ]);
            $status = $response->getStatusCode();
            return $status >= 200 && $status < 300;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ---- Private helpers --------------------------------------------------

    private function request(
        string $method,
        string $path,
        ?array $body,
        array $profile,
        ?string $idempotency_key,
    ): \stdClass {
        $endpoint_key = $this->endpoint_key($method, $path);
        $request_id   = 'req_' . random_string(12);
        $host         = $this->host_from_base();

        if ($this->breaker_is_open($endpoint_key)) {
            $this->log_call($endpoint_key, 0, 0, 0, $profile, 'open', $method, $host, $path, $request_id);
            throw new gateway_unavailable("circuit_open:{$endpoint_key}");
        }

        $start = microtime(true);
        $attempt = 0;
        $last_error = null;
        $last_body  = '';
        $delay_ms = 0;

        while ($attempt < self::RETRY_MAX_ATTEMPTS) {
            $attempt++;

            try {
                $response = $this->http->request($method, $this->base_url() . $path, [
                    'connect_timeout'  => $profile['connect'],
                    'timeout'          => $profile['read'],
                    'auth'             => [$this->credentials->apikey(), $this->credentials->apisecret()],
                    'headers'          => $this->build_headers($idempotency_key, $request_id),
                    'json'             => $body,
                    'http_errors'      => false,
                    // FastPix's CDN advertises AAAA records; many container
                    // bridge networks have no IPv6 route. Pin to v4 so
                    // Happy-Eyeballs doesn't intermittently pick a route
                    // that immediately fails with ConnectException.
                    'force_ip_resolve' => 'v4',
                ]);

                $status = $response->getStatusCode();
                $latency_ms = (int)((microtime(true) - $start) * 1000);
                $this->log_call($endpoint_key, $latency_ms, $status, $attempt, $profile, 'closed', $method, $host, $path, $request_id);

                if ($status >= 200 && $status < 300) {
                    $this->breaker_record_success($endpoint_key);
                    return $this->decode_body($response);
                }

                // Capture the body once per response so retries_exhausted carries
                // the LAST upstream diagnostic. Body is NOT logged anywhere — only
                // attached to the thrown exception's $a context for the caller.
                $last_body = $this->body_snippet($response);

                if ($status === 404) {
                    if ($method === 'GET' && str_starts_with($path, '/v1/on-demand/')) {
                        throw new gateway_not_found("{$path} body={$last_body}");
                    }
                    if ($method === 'DELETE') {
                        $this->breaker_record_success($endpoint_key);
                        return new \stdClass();
                    }
                }

                if (!$this->is_retryable($status)) {
                    $this->breaker_record_failure($endpoint_key);
                    throw new gateway_unavailable("status_{$status}:{$endpoint_key} body={$last_body}");
                }

                $delay_ms = $status === 429
                    ? min(self::RETRY_AFTER_CAP_MS, $this->parse_retry_after($response) * 1000)
                    : self::RETRY_DELAYS_MS[$attempt - 1] + random_int(-self::RETRY_JITTER_MS, self::RETRY_JITTER_MS);
                $last_error = "status_{$status}";

            } catch (gateway_not_found $e) {
                throw $e;
            } catch (gateway_unavailable $e) {
                throw $e;
            } catch (gateway_invalid_response $e) {
                // response_too_large / json_decode_failed — not transient, no retry.
                throw $e;
            } catch (\Throwable $e) {
                $last_error = 'network_' . (new \ReflectionClass($e))->getShortName();
                $delay_ms = self::RETRY_DELAYS_MS[$attempt - 1] + random_int(-self::RETRY_JITTER_MS, self::RETRY_JITTER_MS);
                $this->log_call($endpoint_key, (int)((microtime(true) - $start) * 1000), 0, $attempt, $profile, 'closed', $method, $host, $path, $request_id);
            }

            if ($attempt < self::RETRY_MAX_ATTEMPTS && $delay_ms > 0) {
                usleep($delay_ms * 1000);
            }
        }

        $this->breaker_record_failure($endpoint_key);
        $body_tag = $last_body !== '' ? " body={$last_body}" : '';
        throw new gateway_unavailable("retries_exhausted:{$last_error}:{$endpoint_key}{$body_tag}");
    }

    /**
     * Return up to 500 chars of an HTTP response body, with an ellipsis when
     * truncated. Used to attach upstream diagnostics to gateway_unavailable /
     * gateway_not_found exceptions. The body is NEVER passed to error_log —
     * only carried on the exception so callers can surface it in their own
     * logging or admin UI. Cost ~30 min debugging on 2026-05-04 (REVIEW T2.2).
     */
    private function body_snippet($response): string {
        $raw = (string)$response->getBody();
        if (strlen($raw) <= 500) {
            return $raw;
        }
        return substr($raw, 0, 500) . '...';
    }

    private function is_retryable(int $status): bool {
        // 408 (Request Timeout) is transient under sustained load; retry per
        // FastPix docs.
        return in_array($status, [408, 429, 500, 502, 503, 504], true);
    }

    private function parse_retry_after($response): int {
        $header = $response->getHeaderLine('Retry-After');
        if ($header === '' || !ctype_digit(trim($header))) {
            return 1;
        }
        return max(0, (int)$header);
    }

    private function decode_body($response): \stdClass {
        // Defensive bound against malicious upstream returning unbounded bytes.
        $advertised = (int)$response->getHeaderLine('Content-Length');
        if ($advertised > self::MAX_RESPONSE_BYTES) {
            throw new gateway_invalid_response('response_too_large');
        }
        $raw = (string)$response->getBody();
        if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
            throw new gateway_invalid_response('response_too_large');
        }
        if ($raw === '') {
            return new \stdClass();
        }
        $decoded = json_decode($raw);
        if (!($decoded instanceof \stdClass) && !is_array($decoded)) {
            throw new gateway_invalid_response('json_decode_failed');
        }
        return is_array($decoded) ? (object)['data' => $decoded] : $decoded;
    }

    private function build_headers(?string $idempotency_key, ?string $request_id = null): array {
        $headers = [
            'Accept'     => 'application/json',
            'User-Agent' => 'local_fastpix/' . (string)get_config('local_fastpix', 'version'),
        ];
        if ($idempotency_key !== null) {
            $headers['Idempotency-Key'] = $idempotency_key;
        }
        if ($request_id !== null && $request_id !== '') {
            $headers['X-Request-Id'] = $request_id;
        }
        return $headers;
    }

    /**
     * Extract the host portion of base_url() for structured logging.
     */
    private function host_from_base(): string {
        $host = parse_url($this->base_url(), PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    private function idempotency_key(string $operation, string $owner_hash, ?array $body): string {
        $payload_hash = $body !== null ? hash('sha256', json_encode($body)) : '-';
        return hash('sha256', "{$operation}:{$owner_hash}:{$payload_hash}");
    }

    private function endpoint_key(string $method, string $path): string {
        // MUC area 'circuit_breaker' is declared simplekeys=true, so the key must be
        // alphanumeric only. SHA-256 prefix (32 hex chars) gives 128 bits of collision
        // resistance — replacing CRC32 (32-bit) per REVIEW-2026-05-04 §S-1.
        return substr(hash('sha256', $method . ':' . $path), 0, 32);
    }

    private function base_url(): string {
        $configured = (string)get_config('local_fastpix', 'fastpix_base_url');
        return $configured !== '' ? rtrim($configured, '/') : self::DEFAULT_BASE_URL;
    }

    // ---- Circuit breaker (MUC-backed; multi-FPM correctness) --------------

    private function breaker_is_open(string $key): bool {
        $state = $this->breaker_cache->get($key);
        if (!is_array($state)) {
            return false;
        }
        return ($state['open_until'] ?? 0) > time();
    }

    private function breaker_record_failure(string $key): void {
        $state = $this->breaker_cache->get($key);
        if (!is_array($state)) {
            $state = ['failures' => 0, 'open_until' => 0];
        }
        $state['failures'] = ($state['failures'] ?? 0) + 1;
        if ($state['failures'] >= self::BREAKER_THRESHOLD) {
            $state['open_until'] = time() + self::BREAKER_OPEN_SECONDS;
        }
        $this->breaker_cache->set($key, $state);
    }

    private function breaker_record_success(string $key): void {
        $this->breaker_cache->delete($key);
    }

    // ---- Structured logging (no secrets) ----------------------------------

    private function log_call(
        string $endpoint_key,
        int $latency_ms,
        int $status,
        int $attempt,
        array $profile,
        string $circuit_state,
        string $method = '',
        string $host = '',
        string $path = '',
        string $request_id = '',
    ): void {
        $profile_name = $profile === self::PROFILE_HOT
            ? 'hot'
            : ($profile === self::PROFILE_HEALTH ? 'health' : 'standard');

        $path_logged = $path === '' ? '' : strtok($path, '?');

        error_log(json_encode([
            'event'           => 'gateway.call',
            'request_id'      => $request_id,
            'method'          => $method,
            'host'            => $host,
            'path'            => $path_logged,
            'endpoint'        => $endpoint_key,
            'latency_ms'      => $latency_ms,
            'status_code'     => $status,
            'attempt'         => $attempt,
            'circuit_state'   => $circuit_state,
            'timeout_profile' => $profile_name,
        ]));
    }
}
