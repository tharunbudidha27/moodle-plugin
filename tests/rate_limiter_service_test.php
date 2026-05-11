<?php
namespace local_fastpix\service;

defined('MOODLE_INTERNAL') || die();

class rate_limiter_service_test extends \advanced_testcase {

    public function setUp(): void {
        $this->resetAfterTest();
        rate_limiter_service::reset();
        \cache::make('local_fastpix', 'rate_limit')->purge();
    }

    public function tearDown(): void {
        rate_limiter_service::reset();
    }

    private function bucket_key(string $ip): string {
        return 'rl_' . substr(hash('sha256', $ip), 0, 32);
    }

    public function test_allow_first_call_for_new_ip_returns_true(): void {
        $this->assertTrue(rate_limiter_service::instance()->allow('1.2.3.4'));

        $bucket = \cache::make('local_fastpix', 'rate_limit')->get($this->bucket_key('1.2.3.4'));
        $this->assertIsObject($bucket);
        $this->assertObjectHasProperty('tokens', $bucket);
    }

    public function test_allow_60_calls_in_a_burst_all_pass_at_default_limit(): void {
        $svc = rate_limiter_service::instance();
        for ($i = 0; $i < 60; $i++) {
            $this->assertTrue($svc->allow('5.5.5.5'), "burst call {$i} should pass");
        }
    }

    public function test_allow_61st_call_in_burst_returns_false(): void {
        $svc = rate_limiter_service::instance();
        for ($i = 0; $i < 60; $i++) {
            $svc->allow('6.6.6.6');
        }
        $this->assertFalse($svc->allow('6.6.6.6'));
    }

    public function test_allow_with_custom_limit_5_burst_at_5_passes_6th_fails(): void {
        $svc = rate_limiter_service::instance();
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($svc->allow('7.7.7.7', 5), "call {$i} should pass under limit=5");
        }
        $this->assertFalse($svc->allow('7.7.7.7', 5));
    }

    public function test_allow_returns_true_after_refill_via_clock_advance(): void {
        $ip = '8.8.8.8';
        $svc = rate_limiter_service::instance();

        // Drain the bucket.
        for ($i = 0; $i < 60; $i++) {
            $svc->allow($ip);
        }
        $this->assertFalse($svc->allow($ip));

        // Pretend it's 60 seconds later — full bucket of refill arrives.
        $cache = \cache::make('local_fastpix', 'rate_limit');
        $key = $this->bucket_key($ip);
        $bucket = $cache->get($key);
        $bucket->refilled_at = time() - 60;
        $cache->set($key, $bucket);

        $this->assertTrue($svc->allow($ip));
    }

    public function test_allow_separate_ips_have_separate_buckets(): void {
        $svc = rate_limiter_service::instance();

        for ($i = 0; $i < 5; $i++) {
            $svc->allow('10.0.0.1', 5);
        }
        $this->assertFalse($svc->allow('10.0.0.1', 5));

        // ip2 untouched — its bucket starts full.
        $this->assertTrue($svc->allow('10.0.0.2', 5));
    }

    public function test_allow_uses_simplekey_compliant_cache_key(): void {
        rate_limiter_service::instance()->allow('11.11.11.11');

        $key = $this->bucket_key('11.11.11.11');
        // Validate shape: 'rl_' + 32 lowercase hex chars from sha256 prefix (per T1.1).
        $this->assertMatchesRegularExpression('/^rl_[0-9a-f]{32}$/', $key);

        $bucket = \cache::make('local_fastpix', 'rate_limit')->get($key);
        $this->assertNotFalse($bucket);
    }

    public function test_allow_does_not_throw_on_any_input(): void {
        $svc = rate_limiter_service::instance();

        $bad_inputs = [
            '',
            '0.0.0.0',
            '::1',
            '2001:db8::1',
            str_repeat('a', 1024),
            "with\x00nulbyte",
            "; DROP TABLE x; --",
        ];

        foreach ($bad_inputs as $ip) {
            $result = $svc->allow($ip);
            $this->assertIsBool($result);
        }
    }

    public function test_singleton_returns_same_instance(): void {
        $a = rate_limiter_service::instance();
        $b = rate_limiter_service::instance();
        $this->assertSame($a, $b);
    }

    public function test_reset_clears_singleton(): void {
        $first = rate_limiter_service::instance();
        rate_limiter_service::reset();
        $this->assertNotSame($first, rate_limiter_service::instance());
    }

    public function test_allow_replaces_corrupt_cached_bucket_with_fresh_one(): void {
        $ip = '203.0.113.99';
        $key = 'rl_' . substr(hash('sha256', $ip), 0, 32);
        \cache::make('local_fastpix', 'rate_limit')->set($key, 'not-an-object');
        $this->assertTrue(rate_limiter_service::instance()->allow($ip));
    }

    public function test_allow_replaces_object_missing_fields_with_fresh_bucket(): void {
        $ip = '203.0.113.100';
        $key = 'rl_' . substr(hash('sha256', $ip), 0, 32);
        \cache::make('local_fastpix', 'rate_limit')->set($key, (object)['foo' => 1]);
        $this->assertTrue(rate_limiter_service::instance()->allow($ip));
    }
}
