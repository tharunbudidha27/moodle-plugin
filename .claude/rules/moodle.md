# Moodle Rules (M1–M12)

These rules govern *Moodle conventions* — the things that Moodle Plugins Directory reviewers and `moodle-plugin-ci` will look for. Cited by every agent.

---

## M1 — Use `$DB` only

**Rule.** All database access via `$DB` (Moodle DML). No `mysqli_*`, no PDO, no raw `mysql_*`. Raw SQL only via `$DB->get_records_sql()` / `execute_sql()` with parameterized placeholders.

**Enforcement.** CI: `phpcs` Moodle ruleset. CI script `.claude/ci-checks/grep-no-curl.sh` covers PDO/`mysqli_*` along with HTTP.

**Failure routing.** Whichever specialist authored the offending diff.

---

## M2 — Frankenstyle naming

**Rule.**
- Plugin folder: `local/fastpix/`.
- Component name: `local_fastpix`.
- Tables: `local_fastpix_<entity>` (Moodle prefixes with `mdl_` automatically — never write `mdl_` literally).
- Lang file: `lang/en/local_fastpix.php`.
- Capability: `local/fastpix:<action>`.
- Config: `get_config('local_fastpix', '<key>')`.
- Web service: `local_fastpix_<action>`.
- Namespace: `\local_fastpix\...`.
- Event class: `\local_fastpix\event\<event_name>`.

**Enforcement.** `moodle-plugin-ci install` and `uninstall` steps fail if naming is wrong.

**Failure routing.** Whoever introduced the violation.

---

## M3 — Capabilities defined in `db/access.php`

**Rule.** This plugin defines exactly one capability: `local/fastpix:configurecredentials` with `RISK_CONFIG | RISK_PERSONAL`, `captype='write'`, `contextlevel=CONTEXT_SYSTEM`, archetype Manager. It reuses `mod/fastpix:view` and `mod/fastpix:uploadmedia` from `mod_fastpix` — but does NOT define them.

**Enforcement.** PR review; capability table reference.

**Failure routing.** `@security-compliance`.

---

## M4 — Strings in `lang/en/local_fastpix.php`

**Rule.** Every user-visible string lives in `lang/en/local_fastpix.php`. No English in PHP / Mustache / JS source. Strings are accessed via `get_string('key', 'local_fastpix', $a = null)`.

**Enforcement.** `phpcs` Moodle ruleset; `mustache lint`.

**Failure routing.** Whoever introduced the literal.

---

## M5 — `version.php` bumped on every `db/install.xml` or `db/upgrade.php` change

**Rule.** Schema changes require:
1. New element in `db/install.xml`.
2. Matching upgrade step in `db/upgrade.php` (with `if ($oldversion < $newversion) { ... upgrade_plugin_savepoint(true, $newversion, 'local', 'fastpix'); }`).
3. `version.php` `$plugin->version` bumped to `$newversion` (format `YYYYMMDDXX`).

**Enforcement.** Pre-commit hook; CI savepoint check.

**Failure routing.** `@asset-service` (or whoever changed schema); verified by `@backend-architect`.

---

## M6 — Every behavior change has a test

**Rule.** Code-only PRs are rejected. Every behavior change ships with PHPUnit (services) or Behat (user flows). Coverage gates: 85% normal, 90% security-critical (verifier, projector), 95% cryptographic (gateway, jwt_signing).

**Enforcement.** Coverage gate (`.claude/ci-checks/coverage-gate.sh`); PR review.

**Failure routing.** `@testing`.

---

## M7 — Adhoc vs scheduled task class hierarchy

**Rule.** `process_webhook` extends `\core\task\adhoc_task`. The four cleanup tasks (`orphan_sweeper`, `prune_webhook_ledger`, `purge_soft_deleted_assets`, `retry_gdpr_delete`) extend `\core\task\scheduled_task`. Mixing them up is a common mistake.

**Enforcement.** PR review; `@tasks-cleanup` agent guardrail.

**Failure routing.** `@tasks-cleanup`.

---

## M8 — Use `\core\http_client`

**Rule.** Moodle's Guzzle wrapper. Never `curl_*` directly, never raw Guzzle, never `file_get_contents` against URLs.

**Enforcement.** CI script `.claude/ci-checks/grep-no-curl.sh`.

**Failure routing.** `@gateway-integration`.

---

## M9 — `random_string(N)` for secret generation

**Rule.** Moodle's CSPRNG wrapper. Never `mt_rand`, never `rand`, never `random_int` (Moodle convention is `random_string`). Used for `session_secret`, `user_hash_salt`, any nonce.

**Enforcement.** PR review; agent guardrails. Spot-check CI grep `mt_rand` near "secret" / "salt" / "token".

**Failure routing.** `@security-compliance`.

---

## M10 — `format_string` / `s()` / `format_text`

**Rule.** Never `echo` user input.
- `format_string()` for plain strings (titles).
- `s()` for HTML attributes.
- `format_text()` for rich text.

**Enforcement.** `phpcs` Moodle ruleset.

**Failure routing.** Whoever introduced the bare echo.

---

## M11 — External API uses `external_api::validate_parameters()`

**Rule.** Web service functions (`classes/external/*`) call `self::validate_parameters(self::execute_parameters(), $params)`. No parallel validator layer.

**Enforcement.** PR review.

**Failure routing.** `@security-compliance` or `@backend-architect`.

---

## M12 — Vendored code only in `classes/vendor/`

**Rule.** No `composer.json` in the plugin (Moodle Plugins Directory disallows runtime Composer). External libraries vendored into `classes/vendor/<library>/` with a `VENDOR.md` recording version, source URL, license, and SHA256 of each file.

**Enforcement.** CI check that `local/fastpix/composer.json` does not exist.

**Failure routing.** `@security-compliance` (for the vendoring audit).
