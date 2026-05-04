<?php
namespace local_fastpix\webhook;

defined('MOODLE_INTERNAL') || die();

class verifier_test extends \advanced_testcase {

    private const CURRENT  = 'current-secret-value';
    private const PREVIOUS = 'previous-secret-value';
    private const BODY     = '{"type":"media.ready","object":{"id":"abc"}}';

    public function setUp(): void {
        $this->resetAfterTest();
        verifier::reset();
    }

    public function tearDown(): void {
        verifier::reset();
    }

    private function configure_current(): void {
        set_config('webhook_secret_current', self::CURRENT, 'local_fastpix');
        set_config('webhook_secret_previous', '', 'local_fastpix');
        set_config('webhook_secret_rotated_at', 0, 'local_fastpix');
    }

    private function sign(string $body, string $secret): string {
        return hash_hmac('sha256', $body, $secret);
    }

    // --- Happy / unhappy current secret ----------------------------------

    public function test_verify_with_valid_current_secret_returns_true(): void {
        $this->configure_current();
        $sig = $this->sign(self::BODY, self::CURRENT);
        $this->assertTrue(verifier::instance()->verify(self::BODY, $sig));
    }

    public function test_verify_with_invalid_signature_returns_false(): void {
        $this->configure_current();
        $this->assertFalse(verifier::instance()->verify(self::BODY, str_repeat('0', 64)));
    }

    public function test_verify_with_empty_signature_header_returns_false(): void {
        $this->configure_current();
        $this->assertFalse(verifier::instance()->verify(self::BODY, ''));
        $this->assertDebuggingCalled('webhook signature verify: empty body or signature');
    }

    public function test_verify_with_empty_body_returns_false(): void {
        $this->configure_current();
        $sig = $this->sign(self::BODY, self::CURRENT);
        $this->assertFalse(verifier::instance()->verify('', $sig));
        $this->assertDebuggingCalled('webhook signature verify: empty body or signature');
    }

    public function test_verify_with_no_current_secret_configured_returns_false(): void {
        set_config('webhook_secret_current', '', 'local_fastpix');
        $sig = $this->sign(self::BODY, self::CURRENT);
        $this->assertFalse(verifier::instance()->verify(self::BODY, $sig));
        $this->assertDebuggingCalled('webhook signature verify: current secret not configured');
    }

    // --- Rotation window -------------------------------------------------

    public function test_verify_with_previous_secret_within_30min_window_returns_true(): void {
        set_config('webhook_secret_current', 'new', 'local_fastpix');
        set_config('webhook_secret_previous', self::PREVIOUS, 'local_fastpix');
        set_config('webhook_secret_rotated_at', time() - 1500, 'local_fastpix'); // 25 min ago

        $sig = $this->sign(self::BODY, self::PREVIOUS);
        $this->assertTrue(verifier::instance()->verify(self::BODY, $sig));
    }

    public function test_verify_with_previous_secret_after_30min_window_returns_false(): void {
        set_config('webhook_secret_current', 'new', 'local_fastpix');
        set_config('webhook_secret_previous', self::PREVIOUS, 'local_fastpix');
        set_config('webhook_secret_rotated_at', time() - 1801, 'local_fastpix'); // just past window

        $sig = $this->sign(self::BODY, self::PREVIOUS);
        $this->assertFalse(verifier::instance()->verify(self::BODY, $sig));
    }

    public function test_verify_with_no_previous_secret_returns_false(): void {
        $this->configure_current();
        $garbage_sig = $this->sign(self::BODY, 'guessed-secret');
        $this->assertFalse(verifier::instance()->verify(self::BODY, $garbage_sig));
    }

    public function test_verify_with_rotated_at_zero_returns_false(): void {
        set_config('webhook_secret_current', self::CURRENT, 'local_fastpix');
        set_config('webhook_secret_previous', '', 'local_fastpix');
        set_config('webhook_secret_rotated_at', 0, 'local_fastpix');

        $garbage_sig = $this->sign(self::BODY, 'something-else');
        $this->assertFalse(verifier::instance()->verify(self::BODY, $garbage_sig));
    }

    // --- Robustness ------------------------------------------------------

    public function test_verify_does_not_throw_on_any_input(): void {
        $this->configure_current();

        $bad_inputs = [
            ['', ''],
            ['', str_repeat('a', 64)],
            ['body', ''],
            ['body', "\x00\x01\x02"],
            ['body', str_repeat('z', 10000)],
            [str_repeat('B', 1024 * 100), 'not-a-valid-sig'],
        ];

        foreach ($bad_inputs as [$body, $sig]) {
            $result = verifier::instance()->verify($body, $sig);
            $this->assertIsBool($result);
            // Discard any debug output triggered by this iteration; the test
            // only asserts that no exception escaped, not the specific notice.
            $this->resetDebugging();
        }
    }

    public function test_verify_signature_constant_time_via_hash_equals(): void {
        $this->configure_current();

        // Smoke check: the verifier must accept a signature computed identically
        // to its own hash_hmac invocation. If the wrong algo / wrong inputs were
        // used internally, this would diverge.
        $expected = hash_hmac('sha256', self::BODY, self::CURRENT);
        $this->assertTrue(verifier::instance()->verify(self::BODY, $expected));

        // And a one-byte tweak must reject — proving comparison is on full string.
        $tweaked = $expected;
        $tweaked[0] = $tweaked[0] === 'a' ? 'b' : 'a';
        $this->assertFalse(verifier::instance()->verify(self::BODY, $tweaked));
    }

    // --- Singleton -------------------------------------------------------

    public function test_singleton_returns_same_instance_across_calls(): void {
        $a = verifier::instance();
        $b = verifier::instance();
        $this->assertSame($a, $b);
    }

    public function test_reset_clears_singleton(): void {
        $first = verifier::instance();
        verifier::reset();
        $second = verifier::instance();
        $this->assertNotSame($first, $second);
    }
}
