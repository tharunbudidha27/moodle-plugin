<?php
namespace local_fastpix\health;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the public health endpoint runner (C2).
 *
 * The thin shim at health.php is not directly testable (procedural script
 * that emits headers + dies). Tests exercise \local_fastpix\health\runner
 * with the gateway and rate-limiter as injectable dependencies via the
 * static-instance reflection pattern used elsewhere in this suite.
 */
class health_endpoint_test extends \advanced_testcase {

    public function setUp(): void {
        $this->resetAfterTest();
        \local_fastpix\api\gateway::reset();
        \local_fastpix\service\rate_limiter_service::reset();
        \cache::make('local_fastpix', 'rate_limit')->purge();
    }

    public function tearDown(): void {
        \local_fastpix\api\gateway::reset();
        \local_fastpix\service\rate_limiter_service::reset();
    }

    private function inject_gateway_mock($mock): void {
        $reflection = new \ReflectionClass(\local_fastpix\api\gateway::class);
        $prop = $reflection->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $mock);
    }

    public function test_health_returns_200_and_ok_status_on_probe_success(): void {
        $mock = $this->createMock(\local_fastpix\api\gateway::class);
        $mock->method('health_probe')->willReturn(true);
        $this->inject_gateway_mock($mock);

        $result = runner::run('1.2.3.4');

        $this->assertSame(200, $result['http_code']);
        $this->assertSame('ok', $result['body']['status']);
        $this->assertTrue($result['body']['fastpix_reachable']);
        $this->assertIsInt($result['body']['latency_ms']);
        $this->assertGreaterThanOrEqual(0, $result['body']['latency_ms']);
        $this->assertIsInt($result['body']['timestamp']);
    }

    public function test_health_returns_503_when_probe_fails(): void {
        $mock = $this->createMock(\local_fastpix\api\gateway::class);
        $mock->method('health_probe')->willReturn(false);
        $this->inject_gateway_mock($mock);

        $result = runner::run('1.2.3.4');

        $this->assertSame(503, $result['http_code']);
        $this->assertSame('degraded', $result['body']['status']);
        $this->assertFalse($result['body']['fastpix_reachable']);
    }

    public function test_health_returns_503_and_does_not_throw_on_gateway_exception(): void {
        $mock = $this->createMock(\local_fastpix\api\gateway::class);
        $mock->method('health_probe')
            ->willThrowException(new \RuntimeException('simulated failure'));
        $this->inject_gateway_mock($mock);

        // Must not throw; must return a valid response.
        $result = runner::run('1.2.3.4');

        $this->assertSame(503, $result['http_code']);
        $this->assertSame('error', $result['body']['status']);
        $this->assertFalse($result['body']['fastpix_reachable']);

        // The catch block emits a debugging() call.
        $this->assertDebuggingCalled();
    }

    public function test_health_rate_limits_after_30_requests_per_minute(): void {
        $mock = $this->createMock(\local_fastpix\api\gateway::class);
        $mock->method('health_probe')->willReturn(true);
        $this->inject_gateway_mock($mock);

        // 30 successful requests.
        for ($i = 0; $i < 30; $i++) {
            $r = runner::run('5.5.5.5');
            $this->assertSame(200, $r['http_code'], "request {$i} should pass under 30/min cap");
        }

        // 31st triggers the rate limit.
        $r = runner::run('5.5.5.5');
        $this->assertSame(429, $r['http_code']);
        $this->assertSame('rate_limited', $r['body']['status']);
        $this->assertNull($r['body']['fastpix_reachable']);
    }

    public function test_health_response_body_shape_is_stable(): void {
        $mock = $this->createMock(\local_fastpix\api\gateway::class);
        $mock->method('health_probe')->willReturn(true);
        $this->inject_gateway_mock($mock);

        $result = runner::run('1.2.3.4');
        $body = $result['body'];

        // Exact key set — protects ops dashboards that grep specific keys.
        $this->assertSame(
            ['status', 'fastpix_reachable', 'latency_ms', 'timestamp'],
            array_keys($body),
        );
    }
}
