# local_fastpix

Foundation Moodle local plugin for FastPix video integration. Owns the
gateway, asset cache, webhook ingestion, scheduled cleanup, and JWT signing
that the surface plugins (`mod_fastpix`, `filter_fastpix`, `tinymce_fastpix`)
consume.

## Status

**BETA — production-grade.** Release `1.0.0-dev`,
`$plugin->maturity = MATURITY_BETA`. The codebase clears every gate
(PHPUnit 221/221, coverage gate ALL GREEN at architecture targets,
seven non-negotiables grep-clean). Tag `v1.0.0` (MATURITY_STABLE) is
pending operational verification on a production FastPix tenant —
see [STATUS.md](STATUS.md) for the full breakdown and the verification
queue.

The previous 2026-05-04 senior review's Tier 0/1/2/3 findings and the
2026-05-11 adversarial review's BLOCKERs, MAJORs, MINORs and NITs are
all closed. See [CHANGELOG.md](CHANGELOG.md) for the per-release
breakdown.

## What this plugin is

`local_fastpix` is the trusted boundary between Moodle and FastPix.io
(video CDN). It is the **foundation plugin** in a planned 4-plugin
integration:

| Plugin              | Role                                                       |
|---------------------|------------------------------------------------------------|
| `local_fastpix`     | Gateway, asset metadata, webhook ledger, JWT signing, cron |
| `mod_fastpix`       | Activity module — student-facing video player              |
| `filter_fastpix`    | Filter — embeds FastPix video by playback ID               |
| `tinymce_fastpix`   | TinyMCE plugin — upload widget in the editor               |

This plugin has **no UI of its own** beyond an admin settings page. It is
consumed via PHP namespaces and a small web-services surface
(`local_fastpix_create_upload_session`, `local_fastpix_create_url_pull_session`,
`local_fastpix_get_upload_status`).

The architecture, wire formats, capability model, and all 30
Definition-of-Done items live in
[.claude/docs/01-local-fastpix.md](.claude/docs/01-local-fastpix.md).

## Requirements

- Moodle 4.5 LTS or later
- PHP 8.1 or later
- A FastPix account with API credentials and a webhook signing secret
- A MUC backend that survives across PHP-FPM workers (Redis, Memcached, or
  the file store on a single-FPM dev install). The circuit breaker and
  rate-limiter rely on shared cache state.

### Vendored dependencies

Per Moodle Plugins Directory rules, no runtime Composer dependencies. The
plugin vendors a single library:

- `firebase/php-jwt` **v6.10.0** at `classes/vendor/php-jwt/`
  (six PHP files only, license MIT, SHA256 attestation in
  `classes/vendor/php-jwt/VENDOR.md`).

## Installation

1. Drop the plugin tree into your Moodle install at `local/fastpix/`. From
   the Moodle root:

   ```
   cp -r path/to/local_fastpix local/fastpix
   ```

2. Run the upgrade. From the Moodle root:

   ```
   php admin/cli/upgrade.php --non-interactive
   ```

   The install hook at `db/install.php` runs once and seeds:

   - `webhook_secret_current` — 64 hex chars from `random_bytes(32)`
   - `user_hash_salt` — 32 chars from `random_string(32)`, used to HMAC
     userids before sending to FastPix metadata
   - default feature flags (DRM off)

   The local RS256 signing key for playback JWTs is **not** minted at
   install. It is bootstrapped lazily on first use by
   `credential_service::ensure_signing_key()` once API credentials are
   saved.

3. The install command prints the webhook URL admins must paste into the
   FastPix dashboard. It looks like:

   ```
   https://your.moodle.example/local/fastpix/webhook.php
   ```

## Configuration

After install, log in as a site administrator and navigate to
**Site administration → Server → FastPix**. The page is gated by the
`local/fastpix:configurecredentials` capability, granted to managers by
default.

### API credentials

You will need a FastPix API key and API secret. Generate them in the
FastPix dashboard under **Settings → API Keys**.

- **API Key** — plain text input.
- **API Secret** — masked input (`admin_setting_configpasswordunmask`).

**Storage disclosure.** Both credentials are stored as plaintext rows in
Moodle's `mdl_config_plugins` table. The settings UI masks the secret
field, but the database does not encrypt it at rest. Operators should:

- treat database backups containing this table as sensitive material
- restrict read access to the Moodle DB user and DBA roles only
- avoid copying production credentials into dev or staging dumps without
  scrubbing

This is the standard Moodle pattern for `configpasswordunmask` settings;
the plugin does not introduce new exposure, but the disclosure matters
because a webhook signing key compromise lets an attacker forge events.

### DRM

DRM is disabled by default. To enable:

1. Tick **Enable DRM** on the settings page.
2. Paste your FastPix DRM Configuration ID into **DRM Configuration ID**.

The plugin enforces a **double gate** at upload time:
`feature_flag_service::drm_enabled()` returns `true` only when both the
checkbox is on AND the configuration ID is non-empty. Either alone fails
closed — a DRM upload requested under a half-configured DRM setup raises
`drm_not_configured` and never hits the gateway.

### Webhook URL

The settings page displays a read-only webhook URL. Copy it into the
FastPix dashboard's webhook configuration (next section).

## FastPix dashboard setup

In the FastPix dashboard:

1. Navigate to **Settings → Webhooks** (path may vary; consult FastPix
   documentation for the current UI).
2. Add a new webhook destination pointing at the URL displayed in
   Moodle's settings page (e.g.
   `https://your.moodle.example/local/fastpix/webhook.php`).
3. Set the signing secret to the value Moodle generated at install. Read
   it once with:

   ```
   php -r 'define("CLI_SCRIPT", true); require "config.php"; echo get_config("local_fastpix", "webhook_secret_current"), "\n";'
   ```

   Run this from the Moodle root, as a user with read access to your
   Moodle config and DB.

4. Subscribe at minimum to: `video.media.created`, `video.media.ready`,
   `video.media.updated`, `video.media.failed`, `video.media.deleted`.

### Webhook secret rotation

The webhook signing secret is bootstrapped at install (64 hex chars from
`random_bytes(32)`) and is **not editable through the admin UI**. This is
intentional — exposing it in the UI invites typos that break verification
without an obvious failure mode, and rotation requires synchronizing with
the FastPix dashboard anyway.

To rotate, operators should:

1. Generate a fresh 32-byte secret on the host: `openssl rand -hex 32`.
2. Update both sides in close succession, either via a direct DB update
   on `mdl_config_plugins` (key `webhook_secret_current`, plugin
   `local_fastpix`) or a one-shot CLI script that calls
   `set_config('webhook_secret_current', $new, 'local_fastpix')`.
3. Optionally populate `webhook_secret_previous` and
   `webhook_secret_rotated_at` (Unix seconds) to keep the previous secret
   live during the 30-minute rotation window the verifier honors.
4. Update the FastPix dashboard with the new value.

The verifier rejects any configured secret shorter than 32 bytes and
emits a `webhook.secret_too_short` log line on rejection — operators
see signature verification fail loudly rather than degrade to a weaker
check.

### Health endpoint

The plugin ships a small public health endpoint at:

```
https://your.moodle.example/local/fastpix/health.php
```

No authentication required. Per-IP rate-limited at 30 req/min so the
endpoint can't be used as a probing oracle. Returns JSON:

```
{
  "status": "ok" | "degraded" | "rate_limited" | "error",
  "fastpix_reachable": true | false | null,
  "latency_ms": <int>,
  "timestamp": <unix-seconds>
}
```

HTTP status codes:

| Code | Meaning                                                    |
|------|------------------------------------------------------------|
| 200  | FastPix probe succeeded                                    |
| 503  | FastPix probe failed, or an unexpected upstream error     |
| 429  | Per-IP rate limit hit; back off and retry                 |

The endpoint never returns 500. Any exception in the probe path is
caught and surfaced as a 503 with `status: "error"` so operators can
distinguish a hard upstream failure from a slow probe.

Suggested ops use:

- Wire it into your monitoring system (e.g. as a Pingdom / UptimeRobot
  / Prometheus blackbox check).
- Alert on either non-2xx for >2 consecutive checks, OR
  `fastpix_reachable=false` for >5 minutes.
- The `latency_ms` field is the FastPix probe round-trip; useful as a
  proxy signal for FastPix-side degradation.

## Architecture

The 3-layer rule, the seven non-negotiables, the FastPix wire contract,
the privacy provider, and the 30-checkbox Definition of Done live in the
architecture document:

- [.claude/docs/01-local-fastpix.md](.claude/docs/01-local-fastpix.md)

The plugin's agent-routing table, file-by-file review rules, and PR
auto-reject list live under [.claude/](.claude/).

## Known limitations

This is alpha. Outstanding items are tracked in [STATUS.md](STATUS.md)
under "What does not work / is not yet verified", broken into Tier 2
(documentation and ergonomics), Tier 3 (verification and CI), and Tier 4
(refactor and polish). Highlights as of the current commit:

- DNS-rebinding TOCTOU window between SSRF check and gateway fetch
  (deferred from Tier 1; needs `CURLOPT_RESOLVE` pinning through
  `\core\http_client`)
- Moodle plugin checker not yet run
- End-to-end webhook delivery against real FastPix not yet exercised on
  this branch

Refer to STATUS.md for the authoritative list. This README deliberately
does not duplicate it.

## Development

Run the test suite from the Moodle root:

```
vendor/bin/phpunit --testsuite=local_fastpix_testsuite
```

The current baseline is 132 tests / 200,332 assertions. The suite
includes a 100K-key empirical collision test for cache-key hashing
(SHA-256/32) that takes a few seconds; everything else runs fast.

## License

GNU General Public License v3.0 or later, matching Moodle core. See
`LICENSE` in your Moodle install or
<https://www.gnu.org/licenses/gpl-3.0.html>.
