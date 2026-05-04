# Skill 15 — Generate PHPUnit Tests for a Service

**Owner agent:** `@testing`.

**When to invoke:** After every code-producing skill.

---

## Inputs

Path to a service class.

## Outputs

- `local/fastpix/tests/<service_name>_test.php` extending `\advanced_testcase`.

## Steps

1. Read the service class and its public method signatures.
2. For each public method, generate:
   - Happy-path test.
   - Error-condition tests (one per typed exception).
   - Boundary tests (off-by-one, empty, max).
3. For singletons: add `tearDown` calling `reset()`.
4. For services that read MUC: clear cache in `setUp`.
5. For services that hit the gateway: wire `\core\http_client` mock; never call real FastPix.
6. Add a redaction canary: run one happy path; capture log buffer; assert it contains no JWT pattern, no `apikey` / `apisecret`, no signature header value.

## Coverage targets

| Service | Target |
|---|---|
| `gateway` | 95% |
| `jwt_signing_service` | 95% |
| `verifier` | 90% |
| `projector` | 90% |
| All other services | 85% |

## Test name convention

```
test_<method>_<scenario>_<expected>
```

Examples:
- `test_sign_for_playback_with_missing_kid_throws_signing_key_missing`
- `test_get_by_fastpix_id_or_fetch_on_404_throws_asset_not_found`
- `test_create_file_upload_session_within_60s_returns_deduped_true`

## Setup template

```php
namespace local_fastpix\service;

class my_service_test extends \advanced_testcase {

    public function setUp(): void {
        $this->resetAfterTest();
        feature_flag_service::reset();  // for any singleton
        \cache::make('local_fastpix', 'asset')->purge();
    }

    public function test_method_happy_returns_expected(): void {
        // Arrange
        $service = new my_service();
        // Act
        $result = $service->method('input');
        // Assert
        $this->assertSame('expected', $result);
    }

    public function test_redaction_canary(): void {
        // Capture log via shim
        $log = $this->capture_log_buffer(function() {
            (new my_service())->method('input');
        });
        $this->assertDoesNotMatchRegularExpression('/eyJ[A-Za-z0-9_-]{10,}/', $log);
        $this->assertStringNotContainsString('apikey', $log);
        $this->assertStringNotContainsString('apisecret', $log);
    }
}
```

## Constraints

- **No skipped tests.** `markTestSkipped` requires JIRA reference + expiry date + `@security-compliance` sign-off.
- **No `sleep()` for races.** Use `\core_phpunit` advance-time or `lock_factory` mocks.
- **Mock the gateway** for unit tests; integration tests use the FastPix mock under `scripts/dev/fastpix-mock/`.
- **Boundary tests mandatory** for time-based features: 60s dedup, 7d purge, 30min secret rotation, 90d ledger prune.

## Verification

Coverage report meets the gate. CI passes on full PHP × Moodle × DB matrix. Redaction canary asserts zero matches across all "noisy" paths.
