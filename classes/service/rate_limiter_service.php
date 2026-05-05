<?php
namespace local_fastpix\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Per-IP token-bucket rate limiter backed by MUC area 'rate_limit'.
 * Fail-open: any cache failure returns true so legitimate traffic is never
 * blocked by infrastructure hiccups (rule: fail-closed on auth, fail-open on
 * infra).
 */
class rate_limiter_service {

    private const CACHE_AREA = 'rate_limit';

    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public static function reset(): void {
        self::$instance = null;
    }

    public function allow(string $ip, int $limit_per_minute = 60): bool {
        try {
            $cache       = \cache::make('local_fastpix', self::CACHE_AREA);
            $key         = 'rl_' . substr(hash('sha256', $ip), 0, 32);
            $capacity    = (float)$limit_per_minute;
            $refill_rate = $capacity / 60.0;
            $now         = time();

            $bucket = $cache->get($key);
            if (!is_object($bucket) || !isset($bucket->tokens, $bucket->refilled_at)) {
                $bucket = (object)['tokens' => $capacity, 'refilled_at' => $now];
            }

            $elapsed = max(0, $now - (int)$bucket->refilled_at);
            $bucket->tokens = min($capacity, (float)$bucket->tokens + ($elapsed * $refill_rate));
            $bucket->refilled_at = $now;

            if ($bucket->tokens >= 1.0) {
                $bucket->tokens -= 1.0;
                $cache->set($key, $bucket);
                return true;
            }

            $cache->set($key, $bucket);
            return false;

        } catch (\Throwable $e) {
            // Fail-open: never block legitimate traffic on cache failure.
            debugging('rate_limiter: cache failure, failing open: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
            return true;
        }
    }
}
