# Prompt — Generate PHPUnit Tests

Used after a service or class is generated. Pass it the path to the class under test; the testing agent reads that file plus the corresponding mandatory test list from the architecture doc and emits a test class that hits the documented coverage target.

Variables to fill in:
- `PATH_TO_CLASS` — required, e.g. `classes/api/gateway.php`.
- `CLASS_NAME` — required, e.g. `gateway`.

---

```
You are @testing for the local_fastpix Moodle plugin.

CONTEXT YOU HAVE READ:
- The class under test at [PATH_TO_CLASS].
- 01-local-fastpix.md mandatory test list for that class (e.g. §11.3 for gateway, §12 for jwt_signing, §13.3 for verifier, §14.3-4 for projector).

TASK: Generate `tests/[CLASS_NAME]_test.php`.

COVERAGE TARGET:
- Default: 85%.
- gateway: 95%.
- verifier, projector: 90%.
- jwt_signing_service: 95%.

REQUIREMENTS:
1. Class extends `\advanced_testcase`.
2. setUp(): $this->resetAfterTest(); reset any singletons (feature_flag_service::reset()).
3. Test names: test_<method>_<scenario>_<expected> — e.g.
   test_sign_for_playback_with_missing_kid_throws_signing_key_missing.
4. Generate AT LEAST these cases (drawn from the doc's mandatory list):
   - Happy path for every public method.
   - One test per documented exception throw site.
   - Boundary tests (off-by-one, empty, max).
   - Concurrency / race tests where applicable (use \core_phpunit advance_time).
   - For singletons: a test that asserts reset() restores state.
   - For services that hit the gateway: mock \core\http_client; never call real FastPix.
   - For services that read MUC: clear cache in setUp.
5. CANARY: at least one test that runs a happy path, captures the log buffer
   (via \core\log\manager mock or output capture), and asserts the buffer contains
   no JWT pattern, no apikey/apisecret, no raw user IDs. Use:
     $this->assertDoesNotMatchRegularExpression('/eyJ[A-Za-z0-9_-]{10,}/', $log_buffer);
6. NO skipped tests. NO sleep(). NO commented-out tests.

OUTPUT: PHP file only.
```
