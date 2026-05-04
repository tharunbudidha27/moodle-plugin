# Skill 02 — Implement Database Schema

**Owner agent:** `@asset-service` (asset / track / upload_session) + `@webhook-processing` (webhook_event ledger) + `@backend-architect` (approves indexes).

**When to invoke:** Phase 1, step 3. Re-invoke for every schema change.

---

## Inputs

The XMLDB shape from §4 of `01-local-fastpix.md`. Five tables.

## Outputs

- `local/fastpix/db/install.xml`
- `local/fastpix/db/upgrade.php`
- `local/fastpix/db/access.php`
- `local/fastpix/db/caches.php`
- `local/fastpix/db/services.php` (skeleton)
- `local/fastpix/db/tasks.php` (skeleton)
- `local/fastpix/db/events.php` (empty for v1.0)

## Steps

### 1. `install.xml` — five tables

| Table | Key fields | Indexes that matter |
|---|---|---|
| `local_fastpix_asset` | `fastpix_id` UNIQUE, `playback_id` UNIQUE, `owner_userid`, `status`, `deleted_at`, `gdpr_delete_pending_at`, `last_event_at`, `last_event_id` | `idx_owner_status`, `idx_deleted_at`, `idx_gdpr_pending`, UNIQUE on `playback_id` |
| `local_fastpix_track` | `asset_id` FK, `language`, `kind`, `status` | `fk_asset` FK to asset.id |
| `local_fastpix_upload_session` | `userid`, `upload_id` UNIQUE, `expires_at` | `idx_user_created` (for 60s dedup), `idx_expires` |
| `local_fastpix_webhook_event` | `provider_event_id` UNIQUE, `event_type`, `received_at`, `status` | `uk_provider_event` UNIQUE, `idx_status_received` |
| `local_fastpix_sync_state` | `cursor_key` UNIQUE | reserved for ADR-003; no code in v1.0 |

**Type rules:**
- `duration` is `number(10,3)` (FastPix returns fractional seconds), NOT `int`.
- All timestamps are `int` Unix seconds.
- `deleted_at`, `gdpr_delete_pending_at`, `last_event_at`, `last_event_id` are NULLABLE.
- `payload` (webhook event) is `text` — webhook bodies up to 1MB.

### 2. `upgrade.php`

```php
<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_fastpix_upgrade($oldversion) {
    global $DB;
    // No upgrade steps for v1.0 (fresh install).
    return true;
}
```

For every future schema change, add an `if ($oldversion < $newversion)` block ending with `upgrade_plugin_savepoint(true, $newversion, 'local', 'fastpix');`.

### 3. `access.php` — exactly one capability

```php
$capabilities = [
    'local/fastpix:configurecredentials' => [
        'riskbitmask'  => RISK_CONFIG | RISK_PERSONAL,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => ['manager' => CAP_ALLOW],
    ],
];
```

### 4. `caches.php` — four MUC areas

`asset` (60s, persistent, static accel 100), `rate_limit` (60s simpledata), `circuit_breaker` (60s — CRITICAL: must be MUC for multi-FPM), `upload_dedup` (60s simpledata).

(Exact shape in §9 of `01-local-fastpix.md`.)

### 5. `services.php` — three external functions

Skeleton only. `local_fastpix_create_upload_session`, `local_fastpix_create_url_pull_session`, `local_fastpix_get_upload_status`. Each maps to `\local_fastpix\external\<name>` class to be implemented in Phase 4.

### 6. `tasks.php` — four scheduled

`orphan_sweeper` (daily 03:17), `prune_webhook_ledger` (daily 04:23), `purge_soft_deleted_assets` (daily 04:47), `retry_gdpr_delete` (every 15 min).

### 7. `events.php`

Empty array — no observers in v1.0. (`asset_ready` and `asset_failed` are emitted by this plugin but observed by `mod_fastpix`.)

## Constraints

- **Three-IDs rule.** `fastpix_id` (Media ID) on asset table; `playback_id` separate UNIQUE column; `upload_id` on session table. Never conflate.
- **Soft-delete via `deleted_at` timestamp**, never a boolean. Allows for "soft-deleted in the last 7 days" queries.
- **`provider_event_id` UNIQUE** is the idempotency contract — never relax it.
- **`circuit_breaker` MUC area** must be a shared store (Redis or similar in production). Document this requirement in README.

## Verification

- [ ] Plugin uninstalls cleanly with zero orphan tables (`mdl_local_fastpix_*` count = 0 after uninstall).
- [ ] `xmldb-editor` validates `install.xml`.
- [ ] Capability `local/fastpix:configurecredentials` registered, assignable to Manager only.
- [ ] All four MUC areas resolvable: `cache::make('local_fastpix', 'asset')` etc. don't error.
- [ ] Three-IDs separation: `playback_id` is a UNIQUE column distinct from `fastpix_id`.
