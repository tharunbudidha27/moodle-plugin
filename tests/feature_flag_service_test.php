<?php
namespace local_fastpix\service;

defined('MOODLE_INTERNAL') || die();

class feature_flag_service_test extends \advanced_testcase {

    public function setUp(): void {
        $this->resetAfterTest();
        feature_flag_service::reset();
    }

    public function tearDown(): void {
        feature_flag_service::reset();
    }

    public function test_drm_enabled_with_flag_off_returns_false(): void {
        set_config('feature_drm_enabled', 0, 'local_fastpix');
        set_config('drm_configuration_id', 'abc', 'local_fastpix');

        $this->assertFalse(feature_flag_service::instance()->drm_enabled());
    }

    public function test_drm_enabled_with_empty_config_id_returns_false(): void {
        set_config('feature_drm_enabled', 1, 'local_fastpix');
        set_config('drm_configuration_id', '', 'local_fastpix');

        $this->assertFalse(feature_flag_service::instance()->drm_enabled());
    }

    public function test_drm_enabled_with_both_set_returns_true(): void {
        set_config('feature_drm_enabled', 1, 'local_fastpix');
        set_config('drm_configuration_id', 'abc', 'local_fastpix');

        $this->assertTrue(feature_flag_service::instance()->drm_enabled());
    }

    public function test_watermark_enabled_returns_config_value(): void {
        set_config('feature_watermark_enabled', 1, 'local_fastpix');
        $this->assertTrue(feature_flag_service::instance()->watermark_enabled());

        set_config('feature_watermark_enabled', 0, 'local_fastpix');
        $this->assertFalse(feature_flag_service::instance()->watermark_enabled());
    }

    public function test_tracking_enabled_returns_config_value(): void {
        set_config('feature_tracking_enabled', 1, 'local_fastpix');
        $this->assertTrue(feature_flag_service::instance()->tracking_enabled());

        set_config('feature_tracking_enabled', 0, 'local_fastpix');
        $this->assertFalse(feature_flag_service::instance()->tracking_enabled());
    }

    public function test_drm_configuration_id_returns_null_when_empty(): void {
        set_config('drm_configuration_id', '', 'local_fastpix');
        $this->assertNull(feature_flag_service::instance()->drm_configuration_id());

        set_config('drm_configuration_id', 'cfg-xyz', 'local_fastpix');
        $this->assertSame('cfg-xyz', feature_flag_service::instance()->drm_configuration_id());
    }

    public function test_snapshot_returns_full_state(): void {
        set_config('feature_drm_enabled', 1, 'local_fastpix');
        set_config('drm_configuration_id', 'abc', 'local_fastpix');
        set_config('feature_watermark_enabled', 1, 'local_fastpix');
        set_config('feature_tracking_enabled', 0, 'local_fastpix');

        $snapshot = feature_flag_service::instance()->snapshot();

        $this->assertSame(
            ['drm' => true, 'watermark' => true, 'tracking' => false],
            $snapshot
        );
    }

    public function test_reset_clears_singleton_instance(): void {
        $first = feature_flag_service::instance();
        $this->assertSame($first, feature_flag_service::instance());

        feature_flag_service::reset();

        $second = feature_flag_service::instance();
        $this->assertNotSame($first, $second);
    }
}
