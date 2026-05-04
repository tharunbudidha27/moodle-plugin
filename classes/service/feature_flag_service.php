<?php
namespace local_fastpix\service;

defined('MOODLE_INTERNAL') || die();

class feature_flag_service {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function drm_enabled(): bool {
        // DOUBLE GATE: flag AND configuration_id (rule W12 / S-DRM).
        $flag = (bool)get_config('local_fastpix', 'feature_drm_enabled');
        $config_id = (string)get_config('local_fastpix', 'drm_configuration_id');
        return $flag && $config_id !== '';
    }

    public function watermark_enabled(): bool {
        return (bool)get_config('local_fastpix', 'feature_watermark_enabled');
    }

    public function tracking_enabled(): bool {
        return (bool)get_config('local_fastpix', 'feature_tracking_enabled');
    }

    public function drm_configuration_id(): ?string {
        $id = (string)get_config('local_fastpix', 'drm_configuration_id');
        return $id !== '' ? $id : null;
    }

    public function snapshot(): array {
        return [
            'drm'       => $this->drm_enabled(),
            'watermark' => $this->watermark_enabled(),
            'tracking'  => $this->tracking_enabled(),
        ];
    }

    public static function reset(): void {
        self::$instance = null;
    }
}
