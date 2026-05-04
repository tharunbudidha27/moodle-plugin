# Skill 01 — Create Moodle Local Plugin Skeleton

**Owner agent:** `@security-compliance` (initiates) + `@backend-architect` (approves layout).

**When to invoke:** Phase 1, step 1. Once per plugin lifetime.

---

## Inputs

| Param | Required | Default | Notes |
|---|---|---|---|
| `plugin_component` | yes | — | Must be `local_fastpix`. |
| `moodle_version_requires` | yes | `2024100100` | Moodle 4.5 LTS. |
| `maturity` | no | `MATURITY_BETA` | Bumps to `MATURITY_STABLE` after pilot. |
| `release` | no | `1.0.0-dev` | Semver. |

## Outputs

- `local/fastpix/version.php` — plugin metadata.
- `local/fastpix/lib.php` — empty `local_fastpix_after_config()` callback.
- `local/fastpix/lang/en/local_fastpix.php` — `$string['pluginname']` only.
- (Stops here — schema, settings, capabilities come from Skill 02.)

## Steps

1. Create `version.php`:
   ```php
   <?php
   defined('MOODLE_INTERNAL') || die();

   $plugin->component = 'local_fastpix';
   $plugin->version   = (int)date('Ymd') . '00';
   $plugin->requires  = 2024100100;        // Moodle 4.5 LTS
   $plugin->maturity  = MATURITY_BETA;
   $plugin->release   = '1.0.0-dev';
   ```
2. Create `lib.php`:
   ```php
   <?php
   defined('MOODLE_INTERNAL') || die();

   /**
    * Auto-bootstrap secrets and signing key on first install or admin save.
    * Stub here; populated by Phase 2.
    */
   function local_fastpix_after_config() {
       // intentionally empty until Phase 2
   }
   ```
3. Create `lang/en/local_fastpix.php`:
   ```php
   <?php
   defined('MOODLE_INTERNAL') || die();

   $string['pluginname'] = 'FastPix';
   ```
4. Run `php admin/cli/install_database.php` (or equivalent) and verify the plugin shows up at Site administration → Plugins → Local plugins.

## Constraints

- Frankenstyle name MUST be `local_fastpix`.
- MUST install on Moodle 4.5 / 5.0 / 5.1 (TinyMCE 7 shim) on PHP 8.2 / 8.3 / 8.4 against MySQL / MariaDB / PostgreSQL.
- DO NOT add Composer dependencies — vendoring happens in Skill 03.

## Verification

- [ ] Plugin appears in admin UI with display name "FastPix".
- [ ] `moodle-plugin-ci install` step passes.
- [ ] Uninstall is clean (zero orphan tables — there are no tables yet).
