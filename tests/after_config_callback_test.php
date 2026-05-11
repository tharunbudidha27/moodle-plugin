<?php
namespace local_fastpix\hook;

defined('MOODLE_INTERNAL') || die();

/**
 * Regression test for the page-render hot path contract on
 * \local_fastpix\hook\after_config_callback.
 *
 * Pins the invariant: the handler MUST NOT make synchronous HTTP calls
 * or touch the gateway. Injecting a trip-wire gateway whose every method
 * throws guarantees that any future change to the handler that invokes
 * the gateway directly OR indirectly will surface immediately.
 */
class after_config_callback_test extends \advanced_testcase {

    public function setUp(): void {
        $this->resetAfterTest();
        \local_fastpix\api\gateway::reset();
    }

    public function tearDown(): void {
        \local_fastpix\api\gateway::reset();
    }

    public function test_handle_makes_no_gateway_calls(): void {
        $tripwire = $this->createMock(\local_fastpix\api\gateway::class);
        $tripwire->method('health_probe')
            ->willThrowException(new \RuntimeException('FORBIDDEN: gateway from after_config'));
        $tripwire->method('get_media')
            ->willThrowException(new \RuntimeException('FORBIDDEN: gateway from after_config'));
        $tripwire->method('input_video_direct_upload')
            ->willThrowException(new \RuntimeException('FORBIDDEN: gateway from after_config'));
        $tripwire->method('media_create_from_url')
            ->willThrowException(new \RuntimeException('FORBIDDEN: gateway from after_config'));
        $tripwire->method('delete_media')
            ->willThrowException(new \RuntimeException('FORBIDDEN: gateway from after_config'));
        $tripwire->method('create_signing_key')
            ->willThrowException(new \RuntimeException('FORBIDDEN: gateway from after_config'));

        $reflect = new \ReflectionClass(\local_fastpix\api\gateway::class);
        $prop = $reflect->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $tripwire);

        $hook = $this->getMockBuilder(\core\hook\after_config::class)
            ->disableOriginalConstructor()
            ->getMock();

        after_config_callback::handle($hook);
        $this->addToAssertionCount(1);
    }

    public function test_handle_returns_in_under_10_milliseconds(): void {
        $hook = $this->getMockBuilder(\core\hook\after_config::class)
            ->disableOriginalConstructor()
            ->getMock();

        $start = microtime(true);
        after_config_callback::handle($hook);
        $elapsed_ms = (microtime(true) - $start) * 1000.0;

        $this->assertLessThan(
            10.0,
            $elapsed_ms,
            sprintf('after_config_callback::handle took %.2f ms; budget is 10 ms', $elapsed_ms)
        );
    }
}
