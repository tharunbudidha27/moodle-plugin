<?php
namespace local_fastpix;

defined('MOODLE_INTERNAL') || die();

/**
 * Regression guard for REVIEW-2026-05-04 §S-1.
 *
 * T1.1 replaced CRC32 cache keys (32-bit, ~77K-asset birthday-collision
 * threshold → cross-asset metadata leak) with SHA-256-truncated-to-32
 * keys across 9 sites in 6 files. Two of those sites were not in the
 * original review and were caught only by audit, so a mechanical guard
 * exists to prevent any future commit from re-introducing the pattern.
 *
 * Forbidden in production source:
 *   - hash('crc32b', ...)
 *   - hash('crc32',  ...)
 *   - crc32(...) — the bare PHP builtin, also 32-bit
 *
 * Allowed:
 *   - This test file itself (it inspects forbidden strings as data).
 *   - Anything under classes/vendor/ (vendored third-party code).
 */
class no_crc32_regression_test extends \advanced_testcase {

    private const FORBIDDEN_PATTERNS = [
        "hash('crc32b'",
        'hash("crc32b"',
        "hash('crc32'",
        'hash("crc32"',
    ];

    /** Files explicitly exempted from the scan. */
    private const ALLOWLIST = [
        'tests/no_crc32_regression_test.php',
    ];

    public function test_no_crc32_in_production_source(): void {
        $root = realpath(__DIR__ . '/..');
        $this->assertNotFalse($root, 'plugin root not found');

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relpath = ltrim(str_replace($root, '', $file->getPathname()), '/');

            // Skip vendored code.
            if (str_starts_with($relpath, 'classes/vendor/')) {
                continue;
            }
            // Skip explicit allowlist (this test file).
            if (in_array($relpath, self::ALLOWLIST, true)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                if (str_contains($contents, $pattern)) {
                    $offenders[] = "{$relpath}: contains {$pattern}";
                }
            }

            // Bare crc32() builtin — match as a whole-word call to avoid
            // false positives on substrings like "crc32_table" in comments.
            if (preg_match('/\bcrc32\s*\(/', $contents)) {
                $offenders[] = "{$relpath}: contains bare crc32() call";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "CRC32 regression detected (REVIEW §S-1 / T1.1). "
            . "Use SHA-256 truncated to 32 chars instead. Offenders:\n  - "
            . implode("\n  - ", $offenders),
        );
    }
}
