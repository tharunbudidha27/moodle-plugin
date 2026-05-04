# Skill 11 — Implement Feature Flag Service

**Owner agent:** `@backend-architect` (initiates) + `@security-compliance` (review).

**When to invoke:** Phase 1, step 5 (small enough to fit in foundation).

---

## Inputs

The four config keys: `feature_drm_enabled`, `feature_watermark_enabled`, `feature_tracking_enabled`, plus `drm_configuration_id`.

## Outputs

- `local/fastpix/classes/service/feature_flag_service.php`.

## Steps

```php
namespace local_fastpix\service;

class feature_flag_service {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function drm_enabled(): bool {
        // DOUBLE GATE: flag AND configuration_id
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

    public static function reset(): void {  // for tests
        self::$instance = null;
    }
}
```

## Constraints

- **`drm_enabled()` is the double gate** — flag AND `drm_configuration_id`. Either alone is `false`.
- **Tests MUST call `reset()` in `tearDown`** — singleton state leaks otherwise.
- **Defaults are checkbox=1** (enabled), so a fresh install with credentials but no DRM config returns `drm_enabled()=false`. Intentional.

## Verification

Three matrix cases:
- Flag false + config_id set → false.
- Flag true + config_id empty → false.
- Flag true + config_id set → true.

Plus `reset()` restores singleton state across tests.
