<?php
namespace local_fastpix\util;

defined('MOODLE_INTERNAL') || die();

class cache_keys_test extends \advanced_testcase {

    public function test_fastpix_key_is_prefixed_and_32_hex(): void {
        $key = cache_keys::fastpix('media-1');
        $this->assertStringStartsWith('fp_', $key);
        $this->assertSame(35, strlen($key)); // fp_ + 32 hex
        $this->assertMatchesRegularExpression('/^fp_[a-f0-9]{32}$/', $key);
    }

    public function test_playback_key_is_prefixed_and_32_hex(): void {
        $key = cache_keys::playback('pb-1');
        $this->assertStringStartsWith('pb_', $key);
        $this->assertMatchesRegularExpression('/^pb_[a-f0-9]{32}$/', $key);
    }

    public function test_fastpix_and_playback_with_same_input_differ(): void {
        $this->assertNotSame(
            cache_keys::fastpix('same-id'),
            cache_keys::playback('same-id')
        );
    }

    public function test_stable_across_calls(): void {
        $this->assertSame(cache_keys::fastpix('x'), cache_keys::fastpix('x'));
        $this->assertSame(cache_keys::playback('x'), cache_keys::playback('x'));
    }
}
