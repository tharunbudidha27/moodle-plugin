<?php
namespace local_fastpix\api;

use GuzzleHttp\Psr7\Response;
use local_fastpix\service\credential_service;

defined('MOODLE_INTERNAL') || die();

class gateway_test extends \advanced_testcase {

    public function setUp(): void {
        $this->resetAfterTest();
        gateway::reset();
        \cache::make('local_fastpix', 'circuit_breaker')->purge();
        set_config('fastpix_base_url', 'https://api.fastpix.io', 'local_fastpix');
        set_config('version', '2026050401', 'local_fastpix');
    }

    public function tearDown(): void {
        gateway::reset();
    }

    /**
     * Build a gateway with mocked http_client and credential_service.
     * Constructor is private; use reflection to bypass it.
     */
    private function build_gateway($http_mock, $credential_mock = null): gateway {
        if ($credential_mock === null) {
            $credential_mock = $this->createMock(credential_service::class);
            $credential_mock->method('apikey')->willReturn('test-key');
            $credential_mock->method('apisecret')->willReturn('test-secret');
        }

        $reflection = new \ReflectionClass(gateway::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $http_prop = $reflection->getProperty('http');
        $http_prop->setAccessible(true);
        $http_prop->setValue($instance, $http_mock);

        $breaker_prop = $reflection->getProperty('breaker_cache');
        $breaker_prop->setAccessible(true);
        $breaker_prop->setValue($instance, \cache::make('local_fastpix', 'circuit_breaker'));

        $cred_prop = $reflection->getProperty('credentials');
        $cred_prop->setAccessible(true);
        $cred_prop->setValue($instance, $credential_mock);

        return $instance;
    }

    private function http_mock_returning(array $responses) {
        $mock = $this->createMock(\core\http_client::class);
        $mock->method('request')->willReturnOnConsecutiveCalls(...$responses);
        return $mock;
    }

    // ---- get_media -------------------------------------------------------

    public function test_get_media_happy_returns_decoded_body(): void {
        $http = $this->http_mock_returning([
            new Response(200, [], json_encode(['id' => 'abc', 'status' => 'ready'])),
        ]);
        $gateway = $this->build_gateway($http);

        $result = $gateway->get_media('abc');

        $this->assertSame('abc', $result->id);
        $this->assertSame('ready', $result->status);
    }

    public function test_get_media_404_throws_gateway_not_found_immediately_no_retry(): void {
        $http = $this->createMock(\core\http_client::class);
        $http->expects($this->once())
            ->method('request')
            ->willReturn(new Response(404, [], ''));

        $gateway = $this->build_gateway($http);

        $this->expectException(\local_fastpix\exception\gateway_not_found::class);
        $gateway->get_media('missing');
    }

    public function test_get_media_500_retries_three_times_then_throws_gateway_unavailable(): void {
        $http = $this->createMock(\core\http_client::class);
        $http->expects($this->exactly(3))
            ->method('request')
            ->willReturn(new Response(500, [], ''));

        $gateway = $this->build_gateway($http);

        $this->expectException(\local_fastpix\exception\gateway_unavailable::class);
        $gateway->get_media('abc');
    }

    // ---- delete_media ----------------------------------------------------

    public function test_delete_media_404_returns_silently(): void {
        $http = $this->createMock(\core\http_client::class);
        $http->expects($this->once())
            ->method('request')
            ->willReturn(new Response(404, [], ''));

        $gateway = $this->build_gateway($http);

        $gateway->delete_media('missing');
        $this->assertTrue(true); // no exception
    }

    public function test_delete_media_2xx_succeeds_with_idempotency_key_header(): void {
        $captured_options = null;
        $http = $this->createMock(\core\http_client::class);
        $http->expects($this->once())
            ->method('request')
            ->with(
                'DELETE',
                $this->stringContains('/v1/on-demand/abc'),
                $this->callback(function ($options) use (&$captured_options) {
                    $captured_options = $options;
                    return isset($options['headers']['Idempotency-Key'])
                        && strlen($options['headers']['Idempotency-Key']) === 64;
                })
            )
            ->willReturn(new Response(204, [], ''));

        $this->build_gateway($http)->delete_media('abc');
        $this->assertNotNull($captured_options);
    }

    // ---- input_video_direct_upload --------------------------------------

    public function test_input_video_direct_upload_includes_idempotency_key_on_post(): void {
        $http = $this->createMock(\core\http_client::class);
        $http->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->stringContains('/v1/on-demand/upload'),
                $this->callback(fn($o) => isset($o['headers']['Idempotency-Key']))
            )
            ->willReturn(new Response(200, [], json_encode(['data' => ['uploadId' => 'u1']])));

        $this->build_gateway($http)->input_video_direct_upload('owner-hash', [], 'private', null);
    }

    // ---- 429 retry-after -------------------------------------------------

    public function test_429_honors_retry_after_header_clamped_to_3000ms(): void {
        // Two responses: 429 with Retry-After=10 (clamp would be 3s; we just verify the
        // retry loop continues, not the exact wall-clock delay), then 200.
        $http = $this->createMock(\core\http_client::class);
        $http->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                new Response(429, ['Retry-After' => '10'], ''),
                new Response(200, [], json_encode(['ok' => true])),
            );

        $start = microtime(true);
        $result = $this->build_gateway($http)->get_media('abc');
        $elapsed_ms = (microtime(true) - $start) * 1000;

        $this->assertTrue($result->ok);
        // 10s would be ~10000ms; clamp must keep us well under 5s.
        $this->assertLessThan(5000, $elapsed_ms, 'Retry-After was not clamped');
    }

    // ---- 4xx non-retryable ----------------------------------------------

    public function test_400_throws_immediately_without_retry(): void {
        $http = $this->createMock(\core\http_client::class);
        $http->expects($this->once())
            ->method('request')
            ->willReturn(new Response(400, [], ''));

        $this->expectException(\local_fastpix\exception\gateway_unavailable::class);
        $this->build_gateway($http)->input_video_direct_upload('h', [], 'private', null);
    }

    // ---- Circuit breaker -------------------------------------------------

    public function test_circuit_breaker_opens_after_5_consecutive_failures(): void {
        // Use 400 (non-retryable) so each call is one HTTP request and breaker_record_failure fires.
        $http = $this->createMock(\core\http_client::class);
        $http->expects($this->exactly(5))
            ->method('request')
            ->willReturn(new Response(400, [], ''));

        $gateway = $this->build_gateway($http);

        for ($i = 0; $i < 5; $i++) {
            try {
                $gateway->input_video_direct_upload('owner', [], 'private', null);
                $this->fail('expected gateway_unavailable on attempt ' . $i);
            } catch (\local_fastpix\exception\gateway_unavailable $e) {
                // Expected.
            }
        }

        // Inspect breaker state: should be open. Cache key is the SHA-256-32
        // hash of method:path (MUC area uses simplekeys=true). Per T1.1
        // (REVIEW S-1) — was crc32b, now sha256/32 to avoid 32-bit collisions.
        $breaker = \cache::make('local_fastpix', 'circuit_breaker');
        $state = $breaker->get(substr(hash('sha256', 'POST:/v1/on-demand/upload'), 0, 32));
        $this->assertIsArray($state);
        $this->assertGreaterThanOrEqual(5, $state['failures']);
        $this->assertGreaterThan(time(), $state['open_until']);
    }

    public function test_circuit_breaker_open_short_circuits_with_gateway_unavailable(): void {
        // Pre-load breaker as open. Cache key matches the gateway's hashing scheme
        // (simplekeys=true on MUC area 'circuit_breaker'). Per T1.1 — sha256/32
        // replaced crc32b after REVIEW S-1.
        $breaker = \cache::make('local_fastpix', 'circuit_breaker');
        $key = substr(hash('sha256', 'GET:/v1/on-demand/' . rawurlencode('any')), 0, 32);
        $breaker->set($key, [
            'failures' => 5,
            'open_until' => time() + 30,
        ]);

        // Mock must NOT receive a request call.
        $http = $this->createMock(\core\http_client::class);
        $http->expects($this->never())->method('request');

        $gateway = $this->build_gateway($http);

        try {
            $gateway->get_media('any');
            $this->fail('expected gateway_unavailable');
        } catch (\local_fastpix\exception\gateway_unavailable $e) {
            $this->assertStringContainsString('circuit_open', $e->getMessage() . ' ' . (string)$e->a);
        }
    }

    // ---- health_probe ----------------------------------------------------

    public function test_health_probe_returns_true_on_2xx(): void {
        $http = $this->createMock(\core\http_client::class);
        $http->method('request')->willReturn(new Response(200, [], '{}'));
        $this->assertTrue($this->build_gateway($http)->health_probe());
    }

    public function test_health_probe_returns_false_on_5xx(): void {
        $http = $this->createMock(\core\http_client::class);
        $http->method('request')->willReturn(new Response(503, [], ''));
        $this->assertFalse($this->build_gateway($http)->health_probe());
    }

    public function test_health_probe_returns_false_on_network_exception_never_throws(): void {
        $http = $this->createMock(\core\http_client::class);
        $http->method('request')->willThrowException(new \RuntimeException('connect failed'));

        $result = $this->build_gateway($http)->health_probe();
        $this->assertFalse($result);
    }

    // ---- Timeout profiles ------------------------------------------------

    public function test_get_media_uses_profile_hot_3s_timeouts(): void {
        $http = $this->createMock(\core\http_client::class);
        $http->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $this->anything(),
                $this->callback(fn($o) => $o['connect_timeout'] === 3 && $o['timeout'] === 3)
            )
            ->willReturn(new Response(200, [], '{}'));

        $this->build_gateway($http)->get_media('abc');
    }

    public function test_input_video_direct_upload_uses_profile_standard_5s_30s_timeouts(): void {
        $http = $this->createMock(\core\http_client::class);
        $http->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(fn($o) => $o['connect_timeout'] === 5 && $o['timeout'] === 30)
            )
            ->willReturn(new Response(200, [], json_encode(['data' => []])));

        $this->build_gateway($http)->input_video_direct_upload('h', [], 'private', null);
    }

    // ---- Redaction canary ------------------------------------------------

    public function test_request_logs_no_apikey_apisecret_or_jwt_pattern(): void {
        $cred = $this->createMock(credential_service::class);
        $cred->method('apikey')->willReturn('apikey-VERY-SECRET-VALUE');
        $cred->method('apisecret')->willReturn('apisecret-EVEN-MORE-SECRET');

        $http = $this->createMock(\core\http_client::class);
        // Body could plausibly contain a JWT-shaped string; ensure it's not logged.
        $http->method('request')->willReturn(
            new Response(200, [], json_encode(['token' => 'eyJabcdefghijklmnopqr']))
        );

        $tmp = tempnam(sys_get_temp_dir(), 'gwlog_');
        $original = ini_get('error_log');
        ini_set('error_log', $tmp);

        try {
            $this->build_gateway($http, $cred)->get_media('abc');
            $log_buffer = (string)file_get_contents($tmp);
        } finally {
            ini_set('error_log', $original);
            @unlink($tmp);
        }

        $this->assertStringNotContainsString('apikey-VERY-SECRET-VALUE', $log_buffer);
        $this->assertStringNotContainsString('apisecret-EVEN-MORE-SECRET', $log_buffer);
        $this->assertDoesNotMatchRegularExpression('/eyJ[A-Za-z0-9_-]{10,}/', $log_buffer);
    }
}
