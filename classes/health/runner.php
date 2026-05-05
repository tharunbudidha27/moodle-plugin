<?php
namespace local_fastpix\health;

defined('MOODLE_INTERNAL') || die();

/**
 * Health-endpoint logic, extracted for testability.
 *
 * The thin script `health.php` at the plugin root delegates here so unit
 * tests can exercise the rate-limit, probe, and error-recovery paths
 * without driving an HTTP request.
 *
 * NEVER throws. Any exception from the gateway, the rate limiter, or
 * anything downstream is converted to a 503 response. The endpoint must
 * not 500 — that would prevent ops from distinguishing a hard failure
 * from a slow probe.
 */
class runner {

    /** Rate-limit cap per IP per minute. */
    public const RATE_LIMIT_PER_MIN = 30;

    /**
     * Run the health check for one request.
     *
     * @param string $client_ip Source IP for rate-limit keying.
     * @return array{http_code: int, body: array<string, mixed>}
     */
    public static function run(string $client_ip): array {
        try {
            $limiter = \local_fastpix\service\rate_limiter_service::instance();
            if (!$limiter->allow($client_ip, self::RATE_LIMIT_PER_MIN)) {
                return self::response(429, 'rate_limited', null, 0);
            }

            $start = microtime(true);
            $reachable = \local_fastpix\api\gateway::instance()->health_probe();
            $latency_ms = (int)((microtime(true) - $start) * 1000);

            return self::response(
                $reachable ? 200 : 503,
                $reachable ? 'ok' : 'degraded',
                $reachable,
                $latency_ms,
            );

        } catch (\Throwable $e) {
            // Defensive — health_probe is documented as never-throws, but
            // if anything upstream (rate limiter, MUC, gateway construction)
            // does, swallow it and report degraded. The exception class
            // name is logged via debugging() for ops visibility; the
            // message body is not (could contain sensitive context).
            debugging('local_fastpix health endpoint: ' . get_class($e), DEBUG_DEVELOPER);
            return self::response(503, 'error', false, 0);
        }
    }

    /**
     * @return array{http_code: int, body: array<string, mixed>}
     */
    private static function response(int $http_code, string $status, ?bool $reachable, int $latency_ms): array {
        return [
            'http_code' => $http_code,
            'body' => [
                'status'            => $status,
                'fastpix_reachable' => $reachable,
                'latency_ms'        => $latency_ms,
                'timestamp'         => time(),
            ],
        ];
    }
}
