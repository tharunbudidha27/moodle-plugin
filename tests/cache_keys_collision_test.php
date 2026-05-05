<?php
namespace local_fastpix;

defined('MOODLE_INTERNAL') || die();

/**
 * Empirical collision-resistance test for cache-key hashing.
 *
 * Per T1.1 (REVIEW-2026-05-04 §S-1): all cache keys use
 * substr(hash('sha256', $x), 0, 32) — 128 bits of collision resistance.
 * Birthday-paradox 50% collision threshold: ~2^64 keys (~18 quintillion).
 *
 * This test proves no collisions at 100K keys — the realistic upper bound
 * for a busy Moodle site over several years.
 *
 * Per @testing agent: deterministic (uses fixed-seed RNG), no real FastPix,
 * runs in <1s.
 */
class cache_keys_collision_test extends \advanced_testcase {

    /** Number of synthetic IDs to hash. */
    private const KEY_COUNT = 100000;

    /**
     * Replicates the production hash pattern for cache keys.
     * Same algorithm used by asset_service, projector, gateway,
     * upload_service, rate_limiter_service, and asset_cleanup.
     */
    private function cache_key(string $prefix, string $input): string {
        return $prefix . substr(hash('sha256', $input), 0, 32);
    }

    /**
     * Generate a deterministic-but-uniform synthetic UUID.
     * Uses a counter + a small constant to avoid PRNG seed quirks.
     */
    private function synthetic_uuid(int $i): string {
        // Format: aaaabbbb-cccc-dddd-eeee-ffffffffffff with $i mixed in.
        $hex = sha1((string)$i);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    public function test_no_collisions_at_100k_synthetic_uuids(): void {
        $keys = [];
        for ($i = 0; $i < self::KEY_COUNT; $i++) {
            $uuid = $this->synthetic_uuid($i);
            $key = $this->cache_key('fp_', $uuid);
            $this->assertArrayNotHasKey(
                $key,
                $keys,
                "Collision at i={$i}: UUID {$uuid} produced key {$key}, " .
                "which already maps to UUID " . ($keys[$key] ?? 'unknown')
            );
            $keys[$key] = $uuid;
        }
        $this->assertCount(
            self::KEY_COUNT,
            $keys,
            'Expected ' . self::KEY_COUNT . ' unique keys, got ' . count($keys)
        );
    }

    /**
     * Same test on a different prefix to confirm the result isn't prefix-dependent.
     */
    public function test_no_collisions_at_100k_with_pb_prefix(): void {
        $keys = [];
        for ($i = 0; $i < self::KEY_COUNT; $i++) {
            $uuid = $this->synthetic_uuid($i + 1000000); // Offset to avoid same input
            $key = $this->cache_key('pb_', $uuid);
            $this->assertArrayNotHasKey($key, $keys);
            $keys[$key] = $uuid;
        }
        $this->assertCount(self::KEY_COUNT, $keys);
    }

    /**
     * Sanity check: confirm key length is exactly 32 hex chars + prefix.
     */
    public function test_key_length_is_32_plus_prefix(): void {
        $key = $this->cache_key('fp_', 'any-input-string');
        $this->assertSame(35, strlen($key)); // 'fp_' (3) + 32 hex chars
        $this->assertMatchesRegularExpression('/^fp_[0-9a-f]{32}$/', $key);
    }

    /**
     * Sanity check: confirm same input always hashes to same key (determinism).
     */
    public function test_hash_is_deterministic(): void {
        $input = 'd2188e1c-0000-4000-a000-000000000001';
        $key1 = $this->cache_key('fp_', $input);
        $key2 = $this->cache_key('fp_', $input);
        $this->assertSame($key1, $key2);
    }
}
