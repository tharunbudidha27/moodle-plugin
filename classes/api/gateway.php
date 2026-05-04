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
    ): \stdClass {
        $body = [
            'corsOrigin'   => '*',
            'pushMediaSettings' => [
                'metadata'     => $metadata,
                'accessPolicy' => $access_policy,
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
    ): \stdClass {
        $body = [
            'inputs' => [['type' => 'video', 'url' => $source_url]],
            'metadata'     => $metadata,
            'accessPolicy' => $access_policy,
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
                'headers'         => $this->build_headers(null),
                'query'           => ['limit' => 1],
                'http_errors'     => false,
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

        if ($this->breaker_is_open($endpoint_key)) {
            $this->log_call($endpoint_key, 0, 0, 0, $profile, 'open');
            throw new gateway_unavailable("circuit_open:{$endpoint_key}");
        }

        $start = microtime(true);
        $attempt = 0;
        $last_error = null;
        $delay_ms = 0;

        while ($attempt < self::RETRY_MAX_ATTEMPTS) {
            $attempt++;

            try {
                $response = $this->http->request($method, $this->base_url() . $path, [
                    'connect_timeout' => $profile['connect'],
                    'timeout'         => $profile['read'],
                    'auth'            => [$this->credentials->apikey(), $this->credentials->apisecret()],
                    'headers'         => $this->build_headers($idempotency_key),
                    'json'            => $body,
                    'http_errors'     => false,
                ]);

                $status = $response->getStatusCode();
                $latency_ms = (int)((microtime(true) - $start) * 1000);
                $this->log_call($endpoint_key, $latency_ms, $status, $attempt, $profile, 'closed');

                if ($status >= 200 && $status < 300) {
                    $this->breaker_record_success($endpoint_key);
                    return $this->decode_body($response);
                }

                if ($status === 404) {
                    if ($method === 'GET' && str_starts_with($path, '/v1/on-demand/')) {
                        throw new gateway_not_found($path);
                    }
                    if ($method === 'DELETE') {
                        $this->breaker_record_success($endpoint_key);
                        return new \stdClass();
                    }
                }

                if (!$this->is_retryable($status)) {
                    $this->breaker_record_failure($endpoint_key);
                    throw new gateway_unavailable("status_{$status}:{$endpoint_key}");
                }

                $delay_ms = $status === 429
                    ? min(self::RETRY_AFTER_CAP_MS, $this->parse_retry_after($response) * 1000)
                    : self::RETRY_DELAYS_MS[$attempt - 1] + random_int(-self::RETRY_JITTER_MS, self::RETRY_JITTER_MS);
                $last_error = "status_{$status}";

            } catch (gateway_not_found $e) {
                throw $e;
            } catch (gateway_unavailable $e) {
                throw $e;
            } catch (\Throwable $e) {
                $last_error = 'network_' . (new \ReflectionClass($e))->getShortName();
                $delay_ms = self::RETRY_DELAYS_MS[$attempt - 1] + random_int(-self::RETRY_JITTER_MS, self::RETRY_JITTER_MS);
                $this->log_call($endpoint_key, (int)((microtime(true) - $start) * 1000), 0, $attempt, $profile, 'closed');
            }

            if ($attempt < self::RETRY_MAX_ATTEMPTS && $delay_ms > 0) {
                usleep($delay_ms * 1000);
            }
        }

        $this->breaker_record_failure($endpoint_key);
        throw new gateway_unavailable("retries_exhausted:{$last_error}:{$endpoint_key}");
    }

    private function is_retryable(int $status): bool {
        return in_array($status, [429, 500, 502, 503, 504], true);
    }

    private function parse_retry_after($response): int {
        $header = $response->getHeaderLine('Retry-After');
        if ($header === '' || !ctype_digit(trim($header))) {
            return 1;
        }
        return max(0, (int)$header);
    }

    private function decode_body($response): \stdClass {
        $raw = (string)$response->getBody();
        if ($raw === '') {
            return new \stdClass();
        }
        $decoded = json_decode($raw);
        if (!($decoded instanceof \stdClass) && !is_array($decoded)) {
            throw new gateway_invalid_response('json_decode_failed');
        }
        return is_array($decoded) ? (object)['data' => $decoded] : $decoded;
    }

    private function build_headers(?string $idempotency_key): array {
        $headers = [
            'Accept'     => 'application/json',
            'User-Agent' => 'local_fastpix/' . (string)get_config('local_fastpix', 'version'),
        ];
        if ($idempotency_key !== null) {
            $headers['Idempotency-Key'] = $idempotency_key;
        }
        return $headers;
    }

    private function idempotency_key(string $operation, string $owner_hash, ?array $body): string {
        $payload_hash = $body !== null ? hash('sha256', json_encode($body)) : '-';
        return hash('sha256', "{$operation}:{$owner_hash}:{$payload_hash}");
    }

    private function endpoint_key(string $method, string $path): string {
        // MUC area 'circuit_breaker' is declared simplekeys=true, so the key must be
        // alphanumeric + underscore only. crc32b yields an 8-char lowercase hex string,
        // deterministic and collision-resistant enough for our small endpoint set.
        return hash('crc32b', $method . ':' . $path);
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
    ): void {
        $profile_name = $profile === self::PROFILE_HOT
            ? 'hot'
            : ($profile === self::PROFILE_HEALTH ? 'health' : 'standard');

        error_log(json_encode([
            'event'           => 'gateway.call',
            'endpoint'        => $endpoint_key,
            'latency_ms'      => $latency_ms,
            'status_code'     => $status,
            'attempt'         => $attempt,
            'circuit_state'   => $circuit_state,
            'timeout_profile' => $profile_name,
        ]));
    }
}
