<?php
namespace local_fastpix;

defined('MOODLE_INTERNAL') || die();

/**
 * Production-readiness static checks for local_fastpix.
 *
 * Stand-in for moodle-plugin-ci, which cannot be installed in the
 * dev-docker stack used to develop this plugin (no composer in the
 * webserver container). Covers the high-value checks moodle-plugin-ci
 * would otherwise enforce:
 *
 *   1. No debugging artefacts (var_dump, print_r, dd) in production source.
 *   2. No `composer.json` (rule M12 — Moodle Plugins Directory disallows
 *      runtime Composer dependencies).
 *   3. version.php is well-formed and self-consistent.
 *   4. db/install.xml is valid XML.
 *   5. Every classname referenced in db/services.php exists.
 *   6. Every classname referenced in db/tasks.php exists.
 *   7. Every callback referenced in db/hooks.php exists.
 *
 * Each check is a separate test so a failure points at a single rule.
 */
class plugin_health_test extends \advanced_testcase {

    private const ROOT_RELATIVE = '/..';

    private function plugin_root(): string {
        $root = realpath(__DIR__ . self::ROOT_RELATIVE);
        $this->assertNotFalse($root, 'plugin root not resolvable');
        return $root;
    }

    /**
     * Recursively yield production .php paths (skipping vendor + tests).
     *
     * @return iterable<string>
     */
    private function production_php_files(): iterable {
        $root = $this->plugin_root();
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $rel = ltrim(str_replace($root, '', $file->getPathname()), '/');
            if (str_starts_with($rel, 'classes/vendor/')) {
                continue;
            }
            if (str_starts_with($rel, 'tests/')) {
                continue;
            }
            yield $rel;
        }
    }

    // -- Check 1: no debug artefacts --------------------------------------

    public function test_no_debug_artefacts_in_production_source(): void {
        $patterns = [
            '/\bvar_dump\s*\(/',
            '/\bprint_r\s*\(/',
            '/\bdd\s*\(/',
        ];
        $offenders = [];
        $root = $this->plugin_root();

        foreach ($this->production_php_files() as $rel) {
            $contents = file_get_contents($root . '/' . $rel);
            if ($contents === false) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $contents, $m)) {
                    $offenders[] = "{$rel}: {$m[0]}";
                }
            }
        }

        $this->assertSame([], $offenders,
            "Debug artefacts found in production source:\n  - "
            . implode("\n  - ", $offenders),
        );
    }

    // -- Check 2: no composer.json (M12) ----------------------------------

    public function test_no_composer_json_in_plugin_root(): void {
        $this->assertFileDoesNotExist(
            $this->plugin_root() . '/composer.json',
            'composer.json in a Moodle plugin violates rule M12 ' .
            '(Moodle Plugins Directory disallows runtime Composer deps).',
        );
    }

    // -- Check 3: version.php sanity --------------------------------------

    public function test_version_php_is_well_formed(): void {
        $version_file = $this->plugin_root() . '/version.php';
        $this->assertFileExists($version_file);

        $plugin = new \stdClass();
        require $version_file;

        $this->assertSame('local_fastpix', $plugin->component ?? null,
            'version.php $plugin->component must be local_fastpix');
        $this->assertIsInt($plugin->version ?? null,
            'version.php $plugin->version must be an int (M5)');
        $this->assertIsInt($plugin->requires ?? null,
            'version.php $plugin->requires must be an int');
        $this->assertGreaterThanOrEqual(2024100100, $plugin->requires,
            'version.php $plugin->requires must be ≥ Moodle 4.5 (2024100100)');
        $this->assertNotEmpty($plugin->release ?? null,
            'version.php $plugin->release must be set');
        $this->assertNotEmpty($plugin->maturity ?? null,
            'version.php $plugin->maturity must be set');
    }

    // -- Check 4: db/install.xml is valid XML -----------------------------

    public function test_db_install_xml_is_valid(): void {
        $xml_path = $this->plugin_root() . '/db/install.xml';
        $this->assertFileExists($xml_path);

        $previous = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_file($xml_path);
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $this->assertNotFalse($doc, 'install.xml failed to parse');
            $this->assertSame([], $errors, 'install.xml has libxml errors');
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    // -- Check 5: db/services.php classnames exist ------------------------

    public function test_db_services_classnames_resolve(): void {
        $functions = $this->load_db_array('services.php', 'functions');
        $missing = [];
        foreach ($functions as $name => $cfg) {
            $cls = ltrim((string)($cfg['classname'] ?? ''), '\\');
            if ($cls === '') {
                $missing[] = "{$name}: empty classname";
                continue;
            }
            if (!class_exists($cls)) {
                $missing[] = "{$name}: class {$cls} not found";
            }
        }
        $this->assertSame([], $missing,
            "db/services.php references missing classes:\n  - "
            . implode("\n  - ", $missing),
        );
    }

    // -- Check 6: db/tasks.php classnames exist ---------------------------

    public function test_db_tasks_classnames_resolve(): void {
        $tasks = $this->load_db_array('tasks.php', 'tasks');
        $missing = [];
        foreach ($tasks as $i => $cfg) {
            $cls = ltrim((string)($cfg['classname'] ?? ''), '\\');
            if ($cls === '') {
                $missing[] = "tasks[{$i}]: empty classname";
                continue;
            }
            if (!class_exists($cls)) {
                $missing[] = "tasks[{$i}]: class {$cls} not found";
            }
        }
        $this->assertSame([], $missing,
            "db/tasks.php references missing classes:\n  - "
            . implode("\n  - ", $missing),
        );
    }

    // -- Check 7: db/hooks.php callbacks resolve --------------------------

    public function test_db_hooks_callbacks_resolve(): void {
        $callbacks = $this->load_db_array('hooks.php', 'callbacks');
        $missing = [];
        foreach ($callbacks as $i => $cfg) {
            $callback = (string)($cfg['callback'] ?? '');
            if ($callback === '') {
                $missing[] = "callbacks[{$i}]: empty callback";
                continue;
            }
            if (!is_callable($callback)) {
                $missing[] = "callbacks[{$i}]: {$callback} not callable";
            }
        }
        $this->assertSame([], $missing,
            "db/hooks.php callbacks not callable:\n  - "
            . implode("\n  - ", $missing),
        );
    }

    /**
     * Load a Moodle db/*.php definition file and return the named array.
     *
     * Each file declares ONE variable (e.g. $functions, $tasks, $callbacks)
     * which is captured and returned. Anonymous-scope require() ensures we
     * don't pollute test state with the loaded variable.
     */
    private function load_db_array(string $filename, string $varname): array {
        $path = $this->plugin_root() . '/db/' . $filename;
        $this->assertFileExists($path);
        $loader = static function () use ($path, $varname) {
            require $path;
            return ${$varname} ?? null;
        };
        $value = $loader();
        $this->assertIsArray($value,
            "db/{$filename} did not declare an array \${$varname}");
        return $value;
    }
}
