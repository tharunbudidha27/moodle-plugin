# Plugin 1 of 4 — `local_fastpix` (Backend) — Final Production-Grade Architecture

**Read `00-system-overview.md` first.** This file assumes you have read it.

This is the **backend** plugin. It owns every cross-cutting concern: FastPix gateway, credentials, asset metadata cache, webhook endpoint, scheduled tasks, privacy provider, admin settings, health endpoint, and **local JWT signing for playback authorization**. The other three plugins consume `local_fastpix` via PHP namespaces and web services. **Build this plugin first** — nothing else works until this one does.

This is the **final production-grade** architecture. It folds in three things:
1. The base architecture (file layout, schema, services).
2. Seven production-grade hardening additions (per-asset locking, lazy fetch, hot-path timeout differentiation, soft-delete cleanup, feature kill-switches, upload deduplication, total-ordering tiebreak).
3. **The FastPix API contract verified against `docs.fastpix.io` in May 2026.** This corrected one major architectural decision — the original design doc's ADR-002 was wrong about how DRM tokens work. The plugin DOES hold an RSA private key and signs JWTs locally; FastPix does not have a server-side `createToken` endpoint.

Time budget: 4 weeks (Phases 1, 2, 3 of the engineering plan).

If you find a discrepancy between this file and FastPix's live docs while implementing, trust FastPix's live docs and update this file. Don't ship code that disagrees with the live API.

---

## ⚠️ ADR-002 CORRECTION (read before anything else)

The original design doc's ADR-002 reads:

> "Decision: Plugin calls FastPix's createToken at view time. Holds no signing keys. Saves ~3–4 weeks of partner engineering."

**This is not how FastPix works.** Verified against `docs.fastpix.io/docs/generate-jwts-for-secure-media`:

1. The plugin calls `POST /v1/iam/signing-keys` **once at install/setup time** to obtain an RSA-2048 key pair.
2. FastPix returns a `kid` (key ID) and the **private key** (Base64-encoded PEM). FastPix retains the public key.
3. The plugin stores both in admin settings.
4. **At every playback start, the plugin signs a JWT locally** using the stored private key with `RS256` algorithm.
5. The JWT is appended to the stream URL: `https://stream.fastpix.io/{playback_id}.m3u8?token={JWT}`.
6. FastPix's stream endpoint validates the JWT against the public key it retains.

**Implications:**
- The plugin DOES hold cryptographic key material. Stored via `passwordunmask` in `mdl_config_plugins`.
- The "createToken" hot-path HTTP call from the original design **does not exist** — token signing is local CPU work (1–5ms with `firebase/php-jwt`).
- The 3-second hot-path HTTP timeout in the original architecture applies to `get_media` (lazy fetch), not to token signing.
- DRM and signed-URL playback both use the same JWT (per FastPix docs: "you can reuse the same token for both `token` and `drm-token`").
- The ~3–4 week savings claim from ADR-002 dissolves, but the actual implementation cost is hours, not weeks (vendoring `firebase/php-jwt` + ~30 lines of signing code).

The correct ADR-002 reads:

> **ADR-002 (corrected): Plugin signs JWTs locally with an RSA private key obtained from FastPix at setup time.**
>
> *Rationale.* FastPix does not provide a server-side token-minting endpoint. Their model is: the plugin requests a signing key pair from FastPix once; FastPix retains the public key; the plugin retains the private key and signs JWTs locally at every playback start.
>
> *Cost.* The plugin holds RSA private key material. Mitigated by: (a) `passwordunmask` storage, (b) `local/fastpix:configurecredentials` capability gate, (c) a documented key rotation procedure (create new, grace period, delete old via `DELETE /v1/iam/signing-keys/{kid}`).
>
> *Why not delegate.* FastPix doesn't offer a server-side token service.

---

## 1. Purpose and boundaries

### What this plugin DOES

- Provides the single PHP-level chokepoint for all FastPix HTTP calls (`\local_fastpix\api\gateway`).
- Stores and rotates FastPix credentials: `apikey` (Token ID), `apisecret` (Secret Key), webhook signing secrets, session secret, user-hash salt, **signing key ID and RSA private key**, optional DRM configuration ID.
- **Signs JWTs locally for playback authorization** (replaces the non-existent server-side createToken).
- Owns the asset metadata cache (`local_fastpix_asset`, `local_fastpix_track`).
- Owns the webhook ingestion endpoint and the append-only ledger (`local_fastpix_webhook_event`).
- Projects webhook events onto the asset table via an adhoc task, **with per-asset locking and total ordering**.
- Provides upload session creation (file path + URL pull) via web service endpoints, **with 60-second deduplication**.
- Exposes asset, playback, and watermark services to the other three plugins.
- **Lazy-fetches assets from FastPix on cold-start (DB miss) playbacks.**
- Runs four scheduled tasks: orphan upload sweeper, GDPR delete retry, webhook ledger prune, **soft-deleted asset purger**.
- Implements the GDPR privacy provider for backend-owned tables.
- Exposes a health endpoint for monitoring.
- **Honours three runtime feature kill-switches** (DRM, watermark, watch-tracking).

### What this plugin DOES NOT do

- Define an activity. That's `mod_fastpix`.
- Render the player or track watch progress. That's `mod_fastpix`.
- Render shortcodes in rich text. That's `filter_fastpix`.
- Add a button to the editor. That's `tiny_fastpix`.
- Define `mod/fastpix:view` — that lives in `mod_fastpix/db/access.php`. `local_fastpix` defines only `local/fastpix:configurecredentials`.
- Touch the gradebook or completion API.

---

## 2. Dependencies

| Depends on | Why |
|---|---|
| Moodle core ≥ 4.5 LTS | DML, MUC, Task API, Privacy API, Admin Settings API, Web Services, **Lock API** |
| PHP 8.2+ | Type declarations, readonly properties, enums |
| `firebase/php-jwt` ≥ v6.0 (vendored) | Local JWT signing for playback (RS256) |
| FastPix API (runtime) | Gateway calls; mocked in dev via `scripts/dev/fastpix-mock` |

**Composer note:** Moodle Plugins Directory does not allow runtime Composer dependencies. Vendor `firebase/php-jwt` source files into `local/fastpix/classes/vendor/php-jwt/` at build time. ~6 PHP files, ~1500 lines total, MIT-licensed. Document the vendoring step in `README.md`.

This plugin has NO dependencies on `mod_fastpix`, `filter_fastpix`, or `tiny_fastpix`. Those three depend on this one, not the reverse.

---

## 3. FastPix API contract (verified)

This section documents the FastPix endpoints the gateway calls. All verified against `docs.fastpix.io` in May 2026.

### 3.1 Authentication

HTTP Basic, with FastPix Access Token ID as username and Secret Key as password.

```
Authorization: Basic <base64(access_token_id:secret_key)>
```

The plugin's admin setting `local_fastpix/apikey` stores the Token ID; `local_fastpix/apisecret` stores the Secret Key. (The naming is slightly off — FastPix calls it a Token ID, not an API key — but the field labels in admin UI clarify this.)

### 3.2 Endpoints used

Base URL: `https://api.fastpix.io`

| Method | Path | Used for | Hot path? |
|---|---|---|---|
| `POST` | `/v1/on-demand/upload` | Direct upload (file path) | No |
| `POST` | `/v1/on-demand` | URL pull (async fetch) | No |
| `GET` | `/v1/on-demand/{mediaId}` | Lazy fetch on cold-start playback | **Yes** (3s timeout) |
| `DELETE` | `/v1/on-demand/{mediaId}` | GDPR delete (per-asset) | No |
| `POST` | `/v1/iam/signing-keys` | Bootstrap signing key (one-time) | No |
| `DELETE` | `/v1/iam/signing-keys/{kid}` | Rotate signing key | No |

**There is no `POST /v1/playback/createToken` or similar.** Token signing is local.

### 3.3 Request/response shapes (canonical)

#### Direct upload session

Request:
```json
POST /v1/on-demand/upload
{
  "corsOrigin": "*",
  "pushMediaSettings": {
    "accessPolicy": "private",
    "metadata": { "moodle_owner_userhash": "<hmac>", "moodle_site_url": "<url>" },
    "maxResolution": "1080p",
    "mediaQuality": "standard"
  }
}
```

When DRM enabled (admin has set `drm_configuration_id`):
```json
{
  "corsOrigin": "*",
  "pushMediaSettings": {
    "accessPolicy": "drm",
    "drmConfigurationId": "<UUID from admin settings>",
    "metadata": { ... },
    "maxResolution": "1080p"
  }
}
```

Response 200:
```json
{
  "success": true,
  "data": {
    "uploadId": "<unique upload session ID>",
    "url": "https://upload.fastpix.io/upload/<long signed token>",
    "timeout": 86400,
    "status": "waiting"
  }
}
```

Browser PUTs file chunks to `data.url` directly using a Tus-style resumable protocol (16MB default chunks, 5MB minimum).

#### URL pull

Request:
```json
POST /v1/on-demand
{
  "inputs": [
    { "type": "video", "url": "https://customer-cdn.example.com/video.mp4" }
  ],
  "metadata": { "moodle_owner_userhash": "<hmac>" },
  "accessPolicy": "private",
  "maxResolution": "1080p"
}
```

Response 200:
```json
{
  "success": true,
  "data": {
    "id": "<media UUID>",
    "createdAt": "2026-04-30T12:34:56Z",
    "status": "preparing",
    "playbackIds": [
      { "id": "<playback_id>", "accessPolicy": "private" }
    ],
    "metadata": { ... }
  }
}
```

#### Get media (lazy fetch)

Request: `GET /v1/on-demand/{mediaId}` with Basic auth.

Response 200:
```json
{
  "success": true,
  "data": {
    "id": "<media UUID>",
    "status": "ready",
    "duration": 1234.567,
    "aspectRatio": "16:9",
    "playbackIds": [
      { "id": "<playback_id>", "accessPolicy": "private" }
    ],
    "tracks": [
      { "type": "video", "maxWidth": 1920, "maxHeight": 1080 },
      { "type": "audio" },
      { "type": "text", "language": "en", "kind": "captions", "status": "ready" }
    ],
    "metadata": { ... },
    "accessPolicy": "private"
  }
}
```

Response 404 (asset doesn't exist on FastPix): translates to `\local_fastpix\exception\gateway_not_found`.

#### Create signing key (one-time)

Request: `POST /v1/iam/signing-keys` with Basic auth, no body.

Response 200:
```json
{
  "success": true,
  "data": {
    "id": "fc9d9368-6ee5-4b16-ae50-880a2374bdc4",
    "privateKey": "LS0tLS1CRUdJTiBQUklWQVRFIEtFWS0tLS0t...",
    "createdAt": "2024-01-11T10:00:06.618993Z"
  }
}
```

The `id` is the `kid` for JWT headers. The `privateKey` is Base64-encoded RSA PEM.

### 3.4 Three IDs, not one

FastPix uses three distinct identifiers. **This affects the schema.**

| FastPix term | Format | Lifetime | Used for |
|---|---|---|---|
| **Upload ID** | UUID-like | Until upload completes | Direct upload session reference (transient) |
| **Media ID** | UUID | Permanent | The asset itself; `local_fastpix_asset.fastpix_id` |
| **Playback ID** | UUID-like (e.g. `pb_xxxx`) | Permanent (revocable) | URLs, JWT `aud` claim, shortcodes |

The shortcode `{fastpix:pb_<playback_id>}` uses Playback ID. The webhook ledger and asset table key on Media ID. `local_fastpix_asset` needs both columns plus a unique index on `playback_id` for the filter's hot path.

### 3.5 Stream and DRM URLs

| URL | Use |
|---|---|
| `https://stream.fastpix.io/{playback_id}.m3u8` | HLS manifest, public asset |
| `https://stream.fastpix.io/{playback_id}.m3u8?token={JWT}` | HLS manifest, signed/private/DRM asset |
| `https://image.fastpix.io/{playback_id}/thumbnail.jpg?token={JWT}` | Thumbnail |
| `https://api.fastpix.io/v1/on-demand/drm/license/widevine/{playback_id}?token={JWT}` | Widevine license endpoint (consumed by player) |
| `https://api.fastpix.io/v1/on-demand/drm/license/fairplay/{playback_id}?token={JWT}` | FairPlay license endpoint |
| `https://api.fastpix.io/v1/on-demand/drm/cert/fairplay/{playback_id}?token={JWT}` | FairPlay cert endpoint |

The FastPix Web Player custom element takes attributes:
```html
<fastpix-player
    playback-id="{playback_id}"
    token="{JWT}"
    drm-token="{JWT}">
</fastpix-player>
```

The same JWT serves both `token` and `drm-token` attributes when generated with full claims.

### 3.6 Webhook signature verification

Per FastPix docs:

- **Single header**: `FastPix-Signature: <base64-encoded HMAC-SHA256 of raw body>`
- **No timestamp header.** The signing scheme does not include a timestamp.
- **No replay-window check at HMAC level.** Replay defense is via UNIQUE constraint on `provider_event_id` in the ledger.

Webhook payload shape:
```json
{
  "type": "video.media.created",
  "object": { "type": "video", "id": "<media_id>" },
  "createdAt": "2026-04-30T12:34:56Z",
  "data": { ... event-type-specific fields ... }
}
```

**The asset key is at `event.object.id`**, not `event.data.id`.

Event types we handle:

| Event type | Action |
|---|---|
| `video.media.created` | INSERT new asset row, status='created' |
| `video.media.ready` | UPDATE status='ready', duration, has_captions, primary playback_id; emit `asset_ready` |
| `video.media.updated` | UPDATE changed fields |
| `video.media.deleted` | UPDATE deleted_at |
| `video.media.failed` | UPDATE status='failed'; emit `asset_failed` |
| `video.track.created` | INSERT into local_fastpix_track |
| `video.track.ready` | UPDATE local_fastpix_track status='ready' |
| `video.track.deleted` | DELETE from local_fastpix_track |
| `*` (unknown) | Mark ledger projected (forward compatibility); no asset change |

### 3.7 DRM activation requirement

Per FastPix docs:

> "To enable the DRM feature, contact FastPix support to request DRM activation."

**Practical implications:**
- DRM is not available on a fresh FastPix account. The customer must contact FastPix support.
- After activation, FastPix provides a `drm_configuration_id` UUID. The plugin stores this in admin settings.
- For FairPlay (Apple devices), the customer must obtain an Apple FairPlay certificate and upload it to their FastPix dashboard.
- If `drm_required = true` for an activity but `drm_configuration_id` is empty, the plugin's `mod_form` validation rejects it.

---

## 4. Database schema

Five tables. Schema reflects the verified FastPix model with two extra columns over the v1 design.

```xml
<?xml version="1.0" encoding="UTF-8" ?>
<XMLDB PATH="local/fastpix/db" VERSION="20260501" COMMENT="FastPix backend tables">
  <TABLES>

    <!-- 1. Asset metadata cache; projection target for media.* webhooks -->
    <TABLE NAME="local_fastpix_asset" COMMENT="Cached FastPix asset metadata">
      <FIELDS>
        <FIELD NAME="id"                     TYPE="int"  LENGTH="10"  NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="fastpix_id"             TYPE="char" LENGTH="64"  NOTNULL="true"  COMMENT="FastPix Media ID (UUID)"/>
        <FIELD NAME="playback_id"            TYPE="char" LENGTH="64"  NOTNULL="false" COMMENT="Primary playback ID; populated when media.ready arrives"/>
        <FIELD NAME="owner_userid"           TYPE="int"  LENGTH="10"  NOTNULL="true"  COMMENT="0 = sentinel for cold-start lazy-fetched assets"/>
        <FIELD NAME="title"                  TYPE="char" LENGTH="255" NOTNULL="true"/>
        <FIELD NAME="duration"               TYPE="number" LENGTH="10" DECIMALS="3" NOTNULL="false" COMMENT="Seconds, fractional"/>
        <FIELD NAME="status"                 TYPE="char" LENGTH="24"  NOTNULL="true" DEFAULT="created"/>
        <FIELD NAME="access_policy"          TYPE="char" LENGTH="16"  NOTNULL="true" DEFAULT="private" COMMENT="public|private|drm"/>
        <FIELD NAME="drm_required"           TYPE="int"  LENGTH="1"   NOTNULL="true" DEFAULT="0"/>
        <FIELD NAME="no_skip_required"       TYPE="int"  LENGTH="1"   NOTNULL="true" DEFAULT="0"/>
        <FIELD NAME="has_captions"           TYPE="int"  LENGTH="1"   NOTNULL="true" DEFAULT="0"/>
        <FIELD NAME="last_event_id"          TYPE="char" LENGTH="64"  NOTNULL="false"/>
        <FIELD NAME="last_event_at"          TYPE="int"  LENGTH="10"  NOTNULL="false"/>
        <FIELD NAME="deleted_at"             TYPE="int"  LENGTH="10"  NOTNULL="false"/>
        <FIELD NAME="gdpr_delete_pending_at" TYPE="int"  LENGTH="10"  NOTNULL="false" COMMENT="Set when local delete done but FastPix delete failed"/>
        <FIELD NAME="timecreated"            TYPE="int"  LENGTH="10"  NOTNULL="true"/>
        <FIELD NAME="timemodified"           TYPE="int"  LENGTH="10"  NOTNULL="true"/>
      </FIELDS>
      <KEYS>
        <KEY NAME="primary"        TYPE="primary" FIELDS="id"/>
        <KEY NAME="uk_fastpix_id"  TYPE="unique"  FIELDS="fastpix_id"/>
        <KEY NAME="uk_playback_id" TYPE="unique"  FIELDS="playback_id"/>
        <KEY NAME="fk_owner"       TYPE="foreign" FIELDS="owner_userid" REFTABLE="user" REFFIELDS="id"/>
      </KEYS>
      <INDEXES>
        <INDEX NAME="idx_owner_deleted"       UNIQUE="false" FIELDS="owner_userid, deleted_at"/>
        <INDEX NAME="idx_status"              UNIQUE="false" FIELDS="status"/>
        <INDEX NAME="idx_deleted_at"          UNIQUE="false" FIELDS="deleted_at"/>
        <INDEX NAME="idx_gdpr_pending"        UNIQUE="false" FIELDS="gdpr_delete_pending_at"/>
      </INDEXES>
    </TABLE>

    <!-- 2. Caption tracks per asset (auto + manual) -->
    <TABLE NAME="local_fastpix_track" COMMENT="Caption tracks per asset">
      <FIELDS>
        <FIELD NAME="id"           TYPE="int"  LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="asset_id"     TYPE="int"  LENGTH="10" NOTNULL="true"/>
        <FIELD NAME="track_kind"   TYPE="char" LENGTH="32" NOTNULL="true"/>
        <FIELD NAME="lang"         TYPE="char" LENGTH="16" NOTNULL="true"/>
        <FIELD NAME="status"       TYPE="char" LENGTH="24" NOTNULL="true"/>
        <FIELD NAME="timemodified" TYPE="int"  LENGTH="10" NOTNULL="true"/>
      </FIELDS>
      <KEYS>
        <KEY NAME="primary"  TYPE="primary" FIELDS="id"/>
        <KEY NAME="fk_asset" TYPE="foreign" FIELDS="asset_id" REFTABLE="local_fastpix_asset" REFFIELDS="id"/>
      </KEYS>
    </TABLE>

    <!-- 3. In-flight uploads; TTL 24h, swept by orphan_sweeper -->
    <TABLE NAME="local_fastpix_upload_session" COMMENT="In-flight upload sessions">
      <FIELDS>
        <FIELD NAME="id"          TYPE="int"  LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="userid"      TYPE="int"  LENGTH="10" NOTNULL="true"/>
        <FIELD NAME="upload_id"   TYPE="char" LENGTH="64" NOTNULL="true"  COMMENT="FastPix Upload ID (transient)"/>
        <FIELD NAME="upload_url"  TYPE="text"             NOTNULL="true"/>
        <FIELD NAME="fastpix_id"  TYPE="char" LENGTH="64" NOTNULL="false" COMMENT="Filled when media.created webhook arrives"/>
        <FIELD NAME="source_url"  TYPE="text"             NOTNULL="false"/>
        <FIELD NAME="state"       TYPE="char" LENGTH="24" NOTNULL="true" DEFAULT="pending"/>
        <FIELD NAME="timecreated" TYPE="int"  LENGTH="10" NOTNULL="true"/>
        <FIELD NAME="expires_at"  TYPE="int"  LENGTH="10" NOTNULL="true"/>
      </FIELDS>
      <KEYS>
        <KEY NAME="primary"      TYPE="primary" FIELDS="id"/>
        <KEY NAME="uk_upload_id" TYPE="unique"  FIELDS="upload_id"/>
        <KEY NAME="fk_user"      TYPE="foreign" FIELDS="userid" REFTABLE="user" REFFIELDS="id"/>
      </KEYS>
      <INDEXES>
        <INDEX NAME="idx_expires"      UNIQUE="false" FIELDS="expires_at"/>
        <INDEX NAME="idx_user_created" UNIQUE="false" FIELDS="userid, timecreated"/>
      </INDEXES>
    </TABLE>

    <!-- 4. Webhook ingestion ledger; UNIQUE(provider_event_id) for idempotency; 90d retention -->
    <TABLE NAME="local_fastpix_webhook_event" COMMENT="Append-only webhook ledger">
      <FIELDS>
        <FIELD NAME="id"                    TYPE="int"  LENGTH="10"  NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="provider_event_id"     TYPE="char" LENGTH="64"  NOTNULL="true"/>
        <FIELD NAME="event_type"            TYPE="char" LENGTH="64"  NOTNULL="true"/>
        <FIELD NAME="event_created_at"      TYPE="int"  LENGTH="10"  NOTNULL="true"/>
        <FIELD NAME="payload"               TYPE="text"              NOTNULL="true"/>
        <FIELD NAME="signature"             TYPE="char" LENGTH="128" NOTNULL="true"/>
        <FIELD NAME="status"                TYPE="char" LENGTH="24"  NOTNULL="true" DEFAULT="received"/>
        <FIELD NAME="processing_latency_ms" TYPE="int"  LENGTH="10"  NOTNULL="false"/>
        <FIELD NAME="received_at"           TYPE="int"  LENGTH="10"  NOTNULL="true"/>
      </FIELDS>
      <KEYS>
        <KEY NAME="primary"           TYPE="primary" FIELDS="id"/>
        <KEY NAME="uk_provider_event" TYPE="unique"  FIELDS="provider_event_id"/>
      </KEYS>
      <INDEXES>
        <INDEX NAME="idx_status_received" UNIQUE="false" FIELDS="status, received_at"/>
      </INDEXES>
    </TABLE>

    <!-- 5. Reserved seam for ADR-003 reconciler. NO CODE IN V1.0; just the table. -->
    <TABLE NAME="local_fastpix_sync_state" COMMENT="Reconciler cursor (reserved for ADR-003)">
      <FIELDS>
        <FIELD NAME="id"           TYPE="int"  LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="cursor_key"   TYPE="char" LENGTH="64" NOTNULL="true"/>
        <FIELD NAME="cursor_value" TYPE="text"             NOTNULL="true"/>
        <FIELD NAME="updated_at"   TYPE="int"  LENGTH="10" NOTNULL="true"/>
      </FIELDS>
      <KEYS>
        <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
        <KEY NAME="uk_key"  TYPE="unique"  FIELDS="cursor_key"/>
      </KEYS>
    </TABLE>

  </TABLES>
</XMLDB>
```

**Schema additions versus the v1 architecture (none require migration on existing v1 data because there is no v1 production data):**
- `playback_id` column with unique index on `local_fastpix_asset` — needed because the filter looks up by playback_id, not media_id.
- `access_policy` column on `local_fastpix_asset` — tracks `public|private|drm`.
- `gdpr_delete_pending_at` column with index — for the per-asset GDPR retry pattern.
- `duration` is now `number(10,3)` (seconds with milliseconds) instead of `int` — FastPix returns fractional seconds.
- `upload_id` column with unique index on `local_fastpix_upload_session` — separates the transient FastPix Upload ID from the permanent Media ID.
- `idx_user_created` on upload sessions — supports 60-second deduplication lookup.

---

## 5. Capabilities

`db/access.php`:

```php
$capabilities = [
    'local/fastpix:configurecredentials' => [
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL,
        'captype'     => 'write',
        'contextlevel'=> CONTEXT_SYSTEM,
        'archetypes'  => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
```

The only capability this plugin defines. `mod/fastpix:view`, `mod/fastpix:uploadmedia`, etc. are reused from `mod_fastpix`.

---

## 6. Admin settings (`settings.php`)

```php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_fastpix', get_string('pluginname', 'local_fastpix'));

    // ─── FastPix API connection ────────────────────────────────────────
    $settings->add(new admin_setting_heading('local_fastpix/connection_heading',
        get_string('settings_connection', 'local_fastpix'), ''));

    $settings->add(new admin_setting_configpasswordunmask('local_fastpix/apikey',
        get_string('settings_apikey', 'local_fastpix'),                // "FastPix Access Token ID"
        get_string('settings_apikey_help', 'local_fastpix'), ''));

    $settings->add(new admin_setting_configpasswordunmask('local_fastpix/apisecret',
        get_string('settings_apisecret', 'local_fastpix'),             // "FastPix Secret Key"
        get_string('settings_apisecret_help', 'local_fastpix'), ''));

    // ─── Playback signing (auto-bootstrapped) ─────────────────────────
    $settings->add(new admin_setting_heading('local_fastpix/signing_heading',
        get_string('settings_signing', 'local_fastpix'), ''));

    $settings->add(new admin_setting_configpasswordunmask('local_fastpix/signing_key_id',
        get_string('settings_signing_key_id', 'local_fastpix'),
        get_string('settings_signing_key_id_help', 'local_fastpix'), ''));

    $settings->add(new admin_setting_configpasswordunmask('local_fastpix/signing_private_key',
        get_string('settings_signing_private_key', 'local_fastpix'),
        get_string('settings_signing_private_key_help', 'local_fastpix'), ''));

    $settings->add(new admin_setting_configtext('local_fastpix/signing_key_created_at',
        get_string('settings_signing_key_created_at', 'local_fastpix'),
        get_string('settings_signing_key_created_at_help', 'local_fastpix'),
        '0', PARAM_INT));

    // ─── DRM (optional, requires FastPix activation) ──────────────────
    $settings->add(new admin_setting_heading('local_fastpix/drm_heading',
        get_string('settings_drm', 'local_fastpix'),
        get_string('settings_drm_help', 'local_fastpix')));

    $settings->add(new admin_setting_configtext('local_fastpix/drm_configuration_id',
        get_string('settings_drm_configuration_id', 'local_fastpix'),
        get_string('settings_drm_configuration_id_help', 'local_fastpix'),
        '', PARAM_ALPHANUMEXT));

    // ─── Webhook signing (dual-secret rotation window) ─────────────────
    $settings->add(new admin_setting_heading('local_fastpix/webhook_heading',
        get_string('settings_webhook', 'local_fastpix'), ''));

    $settings->add(new admin_setting_configpasswordunmask('local_fastpix/webhook_secret_current',
        get_string('settings_webhook_secret_current', 'local_fastpix'),
        get_string('settings_webhook_secret_current_help', 'local_fastpix'), ''));

    $settings->add(new admin_setting_configpasswordunmask('local_fastpix/webhook_secret_previous',
        get_string('settings_webhook_secret_previous', 'local_fastpix'),
        get_string('settings_webhook_secret_previous_help', 'local_fastpix'), ''));

    $settings->add(new admin_setting_configtext('local_fastpix/webhook_secret_rotated_at',
        get_string('settings_webhook_rotated_at', 'local_fastpix'),
        get_string('settings_webhook_rotated_at_help', 'local_fastpix'),
        '0', PARAM_INT));

    // ─── Cryptographic secrets (auto-generated on install) ────────────
    $settings->add(new admin_setting_heading('local_fastpix/secrets_heading',
        get_string('settings_secrets', 'local_fastpix'), ''));

    $settings->add(new admin_setting_configpasswordunmask('local_fastpix/session_secret',
        get_string('settings_session_secret', 'local_fastpix'),
        get_string('settings_session_secret_help', 'local_fastpix'), ''));

    $settings->add(new admin_setting_configpasswordunmask('local_fastpix/user_hash_salt',
        get_string('settings_user_hash_salt', 'local_fastpix'),
        get_string('settings_user_hash_salt_help', 'local_fastpix'), ''));

    // ─── Defaults for new activities ──────────────────────────────────
    $settings->add(new admin_setting_heading('local_fastpix/defaults_heading',
        get_string('settings_defaults', 'local_fastpix'), ''));

    $settings->add(new admin_setting_configcheckbox('local_fastpix/default_drm_required',
        get_string('settings_default_drm', 'local_fastpix'),
        get_string('settings_default_drm_help', 'local_fastpix'), 0));

    $settings->add(new admin_setting_configcheckbox('local_fastpix/default_captions',
        get_string('settings_default_captions', 'local_fastpix'),
        get_string('settings_default_captions_help', 'local_fastpix'), 1));

    // ─── RUNTIME FEATURE KILL-SWITCHES (production-grade) ─────────────
    $settings->add(new admin_setting_heading('local_fastpix/feature_flags_heading',
        get_string('settings_feature_flags', 'local_fastpix'),
        get_string('settings_feature_flags_help', 'local_fastpix')));

    $settings->add(new admin_setting_configcheckbox('local_fastpix/feature_drm_enabled',
        get_string('settings_feature_drm_enabled', 'local_fastpix'),
        get_string('settings_feature_drm_enabled_help', 'local_fastpix'), 1));

    $settings->add(new admin_setting_configcheckbox('local_fastpix/feature_watermark_enabled',
        get_string('settings_feature_watermark_enabled', 'local_fastpix'),
        get_string('settings_feature_watermark_enabled_help', 'local_fastpix'), 1));

    $settings->add(new admin_setting_configcheckbox('local_fastpix/feature_tracking_enabled',
        get_string('settings_feature_tracking_enabled', 'local_fastpix'),
        get_string('settings_feature_tracking_enabled_help', 'local_fastpix'), 1));

    $ADMIN->add('localplugins', $settings);
}
```

**On first install:** `lib.php`'s `local_fastpix_after_config` callback:
1. Auto-generates `session_secret` and `user_hash_salt` with `random_string(64)` if unset.
2. Defaults the three feature flags to enabled if unset.
3. **Does not auto-bootstrap signing key** — that requires valid `apikey`/`apisecret` first. After admin saves credentials, the callback checks if `signing_key_id` is empty AND credentials are set; if so, calls `gateway::create_signing_key()` and stores the result. Logged at INFO level.

**Default DRM setting**: `default_drm_required = 0`. Customers who haven't activated DRM with FastPix would otherwise hit upload errors. They opt in once per-customer setup is complete.

---

## 7. Web services (`db/services.php`)

```php
$functions = [
    'local_fastpix_create_upload_session' => [
        'classname'    => 'local_fastpix\\external\\create_upload_session',
        'description'  => 'Create a FastPix direct-upload session (with 60s deduplication)',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/fastpix:uploadmedia',
        'loginrequired'=> true,
    ],
    'local_fastpix_create_url_pull_session' => [
        'classname'    => 'local_fastpix\\external\\create_url_pull_session',
        'description'  => 'Create a FastPix URL-pull session for an external source URL',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/fastpix:uploadmedia',
        'loginrequired'=> true,
    ],
    'local_fastpix_get_upload_status' => [
        'classname'    => 'local_fastpix\\external\\get_upload_status',
        'description'  => 'Poll for upload session status (used during processing UI)',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'mod/fastpix:uploadmedia',
        'loginrequired'=> true,
    ],
];
```

**There is no `refresh_drm_token` external function in this plugin.** Token signing is local CPU work; the AMD module asks `mod_fastpix` (not this plugin) to refresh — and `mod_fastpix` calls `\local_fastpix\service\playback_service::sign_playback_jwt()` directly in PHP. No HTTP round-trip is needed.

---

## 8. Scheduled tasks (`db/tasks.php`)

Four tasks. `process_webhook` is adhoc (enqueued from `webhook.php`), not scheduled.

```php
$tasks = [
    [
        'classname' => 'local_fastpix\\task\\orphan_sweeper',
        'blocking'  => 0,
        'minute'    => '17',
        'hour'      => '3',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        'classname' => 'local_fastpix\\task\\prune_webhook_ledger',
        'blocking'  => 0,
        'minute'    => '23',
        'hour'      => '4',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        'classname' => 'local_fastpix\\task\\purge_soft_deleted_assets',
        'blocking'  => 0,
        'minute'    => '47',
        'hour'      => '4',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        'classname' => 'local_fastpix\\task\\retry_gdpr_delete',
        'blocking'  => 0,
        'minute'    => '*/15',
        'hour'      => '*',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
];
```

---

## 9. MUC cache definitions (`db/caches.php`)

```php
$definitions = [
    // Asset metadata; 60s TTL; hot path
    'asset' => [
        'mode'                   => cache_store::MODE_APPLICATION,
        'simplekeys'              => true,
        'simpledata'              => false,
        'persistent'              => false,
        'staticacceleration'      => true,
        'staticaccelerationsize'  => 100,
        'ttl'                     => 60,
    ],
    // Per-IP webhook rate limiter token bucket
    'rate_limit' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl'        => 60,
    ],
    // Circuit breaker state. CRITICAL: must be in MUC, not in-process
    'circuit_breaker' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl'        => 60,
    ],
    // Upload session deduplication (60s)
    'upload_dedup' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl'        => 60,
    ],
];
```

`hls_fallback` cache from earlier draft is removed — the fallback path was an over-engineered optimization for an outage scenario. If FastPix is down, signed URLs don't work anyway because they point at FastPix's stream domain.

---

## 10. Complete file layout

Every file. Build order is given in §17.

```
local/fastpix/
├── version.php                                 [#1]  Plugin metadata
├── lib.php                                     [#2]  Legacy callbacks (auto-secret, signing-key bootstrap)
├── settings.php                                [#3]  Admin settings
├── webhook.php                                 [#4]  Public webhook endpoint
├── upload_session.php                          [#5]  Upload session endpoint
├── health.php                                  [#6]  Health probe endpoint
│
├── lang/en/
│   └── local_fastpix.php                       [#7]  All English strings
│
├── db/
│   ├── install.xml                             [#8]  Schema for 5 tables
│   ├── upgrade.php                             [#9]  Migrations (empty for v1.0)
│   ├── access.php                              [#10] local/fastpix:configurecredentials
│   ├── services.php                            [#11] 3 external functions
│   ├── tasks.php                               [#12] 4 scheduled tasks
│   ├── caches.php                              [#13] 4 MUC definitions
│   └── events.php                              [#14] Event observers (none in v1.0)
│
├── classes/
│   ├── api/
│   │   └── gateway.php                         [#15] FastPix HTTP chokepoint
│   │
│   ├── service/
│   │   ├── credential_service.php              [#16] Credentials + signing-key bootstrap
│   │   ├── feature_flag_service.php            [#17] Three runtime kill-switches
│   │   ├── jwt_signing_service.php             [#18] Local JWT signing (RS256)
│   │   ├── asset_service.php                   [#19] CRUD + MUC + lazy fetch
│   │   ├── upload_service.php                  [#20] Sessions with 60s dedup
│   │   ├── playback_service.php                [#21] Resolve playback (calls jwt_signing_service)
│   │   ├── watermark_service.php               [#22] Build watermark HTML
│   │   ├── health_service.php                  [#23] Aggregate checks
│   │   └── rate_limiter_service.php            [#24] MUC token-bucket
│   │
│   ├── webhook/
│   │   ├── verifier.php                        [#25] FastPix-Signature header verification
│   │   ├── projector.php                       [#26] Per-asset lock + total ordering
│   │   └── event_dispatcher.php                [#27] Switch on event_type
│   │
│   ├── external/
│   │   ├── create_upload_session.php           [#28]
│   │   ├── create_url_pull_session.php         [#29]
│   │   └── get_upload_status.php               [#30]
│   │
│   ├── task/
│   │   ├── process_webhook.php                 [#31] Adhoc
│   │   ├── orphan_sweeper.php                  [#32]
│   │   ├── prune_webhook_ledger.php            [#33]
│   │   ├── purge_soft_deleted_assets.php       [#34]
│   │   └── retry_gdpr_delete.php               [#35]
│   │
│   ├── event/
│   │   ├── asset_ready.php                     [#36]
│   │   ├── asset_failed.php                    [#37]
│   │   └── webhook_invalid.php                 [#38]
│   │
│   ├── privacy/
│   │   └── provider.php                        [#39] GDPR provider
│   │
│   ├── output/
│   │   └── degraded_banner_renderer.php        [#40]
│   │
│   ├── exception/
│   │   ├── gateway_unavailable.php             [#41]
│   │   ├── gateway_invalid_response.php        [#42]
│   │   ├── gateway_not_found.php               [#43]
│   │   ├── hmac_invalid.php                    [#44]
│   │   ├── rate_limited.php                    [#45]
│   │   ├── asset_not_found.php                 [#46]
│   │   ├── feature_disabled.php                [#47]
│   │   ├── lock_acquisition_failed.php         [#48]
│   │   ├── signing_key_missing.php             [#49]
│   │   └── drm_not_configured.php              [#50]
│   │
│   ├── dto/
│   │   └── playback_payload.php                [#51]
│   │
│   └── vendor/
│       └── php-jwt/                            [#52] Vendored firebase/php-jwt v6+
│
├── templates/
│   └── degraded_banner.mustache                [#53]
│
├── tests/
│   ├── gateway_test.php                        [#54] 95% coverage
│   ├── verifier_test.php                       [#55] 90% coverage
│   ├── projector_test.php                      [#56] 90% coverage incl. locking
│   ├── jwt_signing_service_test.php            [#57] 95% coverage; sign + verify roundtrip
│   ├── asset_service_test.php                  [#58] CRUD + MUC + lazy fetch
│   ├── playback_service_test.php               [#59] Flag-aware resolution
│   ├── watermark_service_test.php              [#60]
│   ├── upload_service_test.php                 [#61] Dedup + SSRF
│   ├── feature_flag_service_test.php           [#62]
│   ├── rate_limiter_test.php                   [#63]
│   ├── health_service_test.php                 [#64]
│   ├── credential_service_test.php             [#65] Signing-key bootstrap idempotent
│   ├── orphan_sweeper_test.php                 [#66]
│   ├── prune_webhook_ledger_test.php           [#67]
│   ├── purge_soft_deleted_assets_test.php      [#68] 7-day boundary
│   ├── retry_gdpr_delete_test.php              [#69]
│   ├── privacy_provider_test.php               [#70]
│   ├── integration/
│   │   ├── webhook_flood_test.php              [#71] 1000 events
│   │   ├── secret_rotation_test.php            [#72]
│   │   ├── circuit_breaker_test.php            [#73]
│   │   └── lock_contention_test.php            [#74] Two concurrent projectors
│   └── behat/
│       ├── webhook_ingestion.feature           [#75]
│       └── upload_url_pull.feature             [#76]
│
├── README.md                                   [#77]
└── CHANGELOG.md                                [#78]
```

78 files (one more than the previous draft because `jwt_signing_service.php` is its own file, separate from `playback_service.php`). The vendor directory adds ~6 files but they're third-party copy-paste, not code you write.

---

## 11. The gateway (`classes/api/gateway.php`)

The most-tested file in the entire system. 95% coverage required.

### 11.1 Public interface

```php
namespace local_fastpix\api;

class gateway {
    public static function instance(): self;

    /** Standard timeout (5s connect, 30s read). */
    /** @throws gateway_unavailable */
    public function input_video_direct_upload(
        string $owner_userid_hash,
        array $metadata,
        string $access_policy = 'private',
        ?string $drm_configuration_id = null
    ): \stdClass;
    // POST /v1/on-demand/upload
    // Returns: { uploadId, url, timeout, status }

    /** Standard timeout. */
    /** @throws gateway_unavailable */
    public function media_create_from_url(
        string $source_url,
        string $owner_userid_hash,
        array $metadata,
        string $access_policy = 'private',
        ?string $drm_configuration_id = null
    ): \stdClass;
    // POST /v1/on-demand
    // Returns: { id (Media ID), status, playbackIds, ... }

    /**
     * HOT-PATH timeout (3s connect, 3s read). Used by asset_service::get_by_fastpix_id_or_fetch.
     *
     * @throws gateway_not_found if FastPix returns 404 (no retry)
     * @throws gateway_unavailable on retry exhaustion
     */
    public function get_media(string $fastpix_id): \stdClass;
    // GET /v1/on-demand/{fastpix_id}
    // Returns full media object

    /** Standard timeout. */
    /** @throws gateway_unavailable on 5xx; returns silently on 404 (idempotent delete) */
    public function delete_media(string $fastpix_id): void;
    // DELETE /v1/on-demand/{fastpix_id}

    /** Standard timeout. Called once per workspace at install time. */
    /** @throws gateway_unavailable */
    public function create_signing_key(): \stdClass;
    // POST /v1/iam/signing-keys
    // Returns: { id (kid), privateKey (Base64 PEM), createdAt }

    /** Standard timeout. Called during key rotation. */
    public function delete_signing_key(string $kid): void;
    // DELETE /v1/iam/signing-keys/{kid}

    /** Returns false on failure; never throws. */
    public function health_probe(): bool;
}
```

### 11.2 Internal responsibilities

1. **HTTP client** — Moodle's `\core\http_client` (Guzzle wrapper). Headers: `User-Agent: local_fastpix/<version>`, `Authorization: Basic base64(apikey:apisecret)` from `credential_service`.

2. **Two timeout profiles** — the gateway method picks:
   - **Standard**: 5s connect, 30s read. Used for upload, URL pull, delete, signing-key ops.
   - **Hot path**: 3s connect, 3s read. Used by `get_media` only.
   - Hard rule: caller doesn't pass a timeout; gateway method decides.

3. **Idempotency keys** — every write request includes `Idempotency-Key: sha256(<operation>:<owner_hash>:<payload_hash>)`. FastPix dedupes server-side.

4. **Retry logic** — exponential backoff 200ms → 400ms → 800ms, max 3 attempts. Retryable: 500, 502, 503, 504, network errors, 429 (with `Retry-After` respect). Not retryable: 4xx (except 429), parse errors, **404 from `get_media` (immediate `gateway_not_found`)**. `usleep` jitter ±25ms.

5. **Circuit breaker** — keyed on `<method>:<endpoint>`. State in MUC `circuit_breaker`. 5 consecutive failures → open 30s → half-open with one probe → close on success.

6. **Structured logging** — every call: `event=gateway.call`, `endpoint`, `latency_ms`, `status_code`, `attempt`, `circuit_state`, `timeout_profile`.

7. **Metric emission**:
   - `fastpix_gateway_request_duration_seconds{endpoint, status, profile}` (histogram)
   - `fastpix_gateway_circuit_state{endpoint}` (gauge)
   - `fastpix_signing_key_created_total{result}` (counter)

8. **Never log credentials** — apikey, apisecret, JWTs minted, signatures all redacted. PHPUnit asserts log buffer never contains these.

### 11.3 Mandatory test cases (`gateway_test.php`)

- Successful call returns parsed body.
- 500 retries 3 times then throws `gateway_unavailable`.
- 502/503/504 each retried.
- 429 respects `Retry-After`.
- 400 throws immediately, no retry.
- **404 on `get_media` throws `gateway_not_found` immediately.**
- **404 on `delete_media` returns silently (idempotent delete).**
- Network timeout retries 3 times.
- Circuit breaker opens on 5 consecutive failures, stays open 30s, half-opens, closes on probe, re-opens on probe failure.
- Idempotency key identical for identical inputs, differs for different payloads.
- Credentials never appear in log output.
- Multi-worker circuit state shared via MUC.
- **Hot-path timeout: a 5-second-responding endpoint causes `get_media` to fail at 3s, but `input_video_direct_upload` succeeds at 5s.**
- **Standard-profile timeout: a 35-second-responding endpoint fails at 30s.**
- **`create_signing_key` returns `{id, privateKey, createdAt}` with valid Base64 in privateKey.**

---

## 12. JWT signing service (`classes/service/jwt_signing_service.php`)

Local CPU operation. **No HTTP, no retry, no circuit breaker.** Pure PHP.

```php
namespace local_fastpix\service;

use Firebase\JWT\JWT;

class jwt_signing_service {

    private const TOKEN_TTL_SECONDS = 300;
    private const ISS = 'fastpix.io';

    /**
     * Sign a playback JWT for a given playback_id.
     *
     * Used by playback_service. The same JWT serves both 'token' and 'drm-token'
     * attributes on <fastpix-player>.
     *
     * @throws \local_fastpix\exception\signing_key_missing if no signing key has been bootstrapped
     */
    public function sign_for_playback(string $playback_id, ?int $ttl = null): string {
        $kid = get_config('local_fastpix', 'signing_key_id');
        $private_key_b64 = get_config('local_fastpix', 'signing_private_key');

        if (empty($kid) || empty($private_key_b64)) {
            throw new \local_fastpix\exception\signing_key_missing();
        }

        $private_key_pem = base64_decode($private_key_b64, true);
        if ($private_key_pem === false) {
            throw new \local_fastpix\exception\signing_key_missing('invalid_base64');
        }

        $now = time();
        $payload = [
            'kid' => $kid,
            'aud' => 'media:' . $playback_id,
            'iss' => self::ISS,
            'sub' => '',
            'iat' => $now,
            'exp' => $now + ($ttl ?? self::TOKEN_TTL_SECONDS),
        ];

        // RS256 because FastPix /v1/iam/signing-keys generates RSA-2048 key pairs
        return JWT::encode($payload, $private_key_pem, 'RS256', $kid);
    }

    public function token_ttl_seconds(): int {
        return self::TOKEN_TTL_SECONDS;
    }
}
```

### Why this is its own service (not inlined in `playback_service`)

1. **Testability.** PHPUnit can verify roundtrip: sign with private key, decode with public key, assert payload.
2. **Reuse.** `mod_fastpix`'s view.php and the AMD module's refresh path both need a JWT; they call this service directly without going through `playback_service`'s broader resolution logic.
3. **Single point of cryptographic concern.** If you ever change algorithm (RS256 → ES256), one file changes.

### Mandatory tests (`jwt_signing_service_test.php`, 95%)

- Sign with valid key → returns three-segment JWT.
- Decode the produced JWT with the public key (test fixture) → payload matches expected.
- Missing `signing_key_id` config throws `signing_key_missing`.
- Missing `signing_private_key` config throws `signing_key_missing`.
- Malformed base64 in `signing_private_key` throws `signing_key_missing` with reason='invalid_base64'.
- Custom TTL is honored (e.g. `sign_for_playback($id, 60)` produces token with `exp = iat + 60`).
- `aud` claim is exactly `media:<playback_id>`, not `media:` or `playback_id` alone.
- `kid` in JWT header matches `kid` in payload.
- Signing the same payload twice produces different signatures **only if** a new `iat` is used (timestamp differs by 1+ seconds). Sanity test for the time-dependent claim.

---

## 13. The webhook endpoint (`webhook.php`)

Public HTTP endpoint. No login. Authenticated by HMAC.

### 13.1 Request lifecycle (must execute in this order)

```php
<?php
require_once(__DIR__.'/../../config.php');
require_once($CFG->libdir.'/setuplib.php');

// 1. Bound request size BEFORE reading body (DoS protection)
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1024 * 1024) {
    http_response_code(413);
    exit;
}

// 2. Read raw body BEFORE framework parsing
$raw_body = file_get_contents('php://input');

// 3. Per-IP rate limit (MUC token bucket; fail-open per ADR-006)
$ip = getremoteaddr();
$rate_limiter = new \local_fastpix\service\rate_limiter_service();
if (!$rate_limiter->allow("webhook:{$ip}", 500, 60)) {
    http_response_code(429);
    exit;
}

// 4. HMAC verification (single FastPix-Signature header; dual-secret rotation)
$verifier = new \local_fastpix\webhook\verifier();
try {
    $event = $verifier->verify(
        $raw_body,
        $_SERVER['HTTP_FASTPIX_SIGNATURE'] ?? ''
    );
} catch (\local_fastpix\exception\hmac_invalid $e) {
    \local_fastpix\event\webhook_invalid::create([
        'context' => \context_system::instance(),
        'other'   => ['ip' => $ip, 'reason' => $e->getMessage()],
    ])->trigger();
    // Do NOT log raw body (attacker-controlled)
    http_response_code(401);
    exit;
}

// 5. Idempotent insert into ledger
global $DB;
try {
    $DB->insert_record('local_fastpix_webhook_event', (object)[
        'provider_event_id' => $event->id,
        'event_type'        => $event->type,
        'event_created_at'  => $event->created_at,
        'payload'           => $raw_body,
        'signature'         => $_SERVER['HTTP_FASTPIX_SIGNATURE'],
        'status'            => 'received',
        'received_at'       => time(),
    ]);
} catch (\dml_write_exception $e) {
    // UNIQUE violation = duplicate delivery; SUCCESS path
    http_response_code(200);
    exit;
}

// 6. Enqueue adhoc task for async projection
$task = new \local_fastpix\task\process_webhook();
$task->set_custom_data(['provider_event_id' => $event->id]);
\core\task\manager::queue_adhoc_task($task);

// 7. Return 200 fast (target p99 ≤ 500ms)
http_response_code(200);
exit;
```

### 13.2 Verifier (`classes/webhook/verifier.php`)

```php
namespace local_fastpix\webhook;

class verifier {

    private const ROTATION_WINDOW_SECONDS = 1800;  // 30 minutes

    /**
     * Verifies FastPix's signature on the raw webhook body.
     *
     * Per FastPix docs: header is `FastPix-Signature` containing
     * base64(hmac_sha256(raw_body, secret)). No timestamp, no replay window
     * at HMAC level — replay defense is the UNIQUE constraint on
     * provider_event_id in the ledger.
     *
     * @throws hmac_invalid
     */
    public function verify(string $raw_body, string $signature_header): \stdClass {
        if ($signature_header === '') {
            throw new \local_fastpix\exception\hmac_invalid('missing_header');
        }

        $current = (string)get_config('local_fastpix', 'webhook_secret_current');
        $previous = (string)get_config('local_fastpix', 'webhook_secret_previous');
        $rotated_at = (int)get_config('local_fastpix', 'webhook_secret_rotated_at');

        if ($current === '') {
            throw new \local_fastpix\exception\hmac_invalid('no_secret_configured');
        }

        // Try current secret
        if ($this->signature_matches($raw_body, $signature_header, $current)) {
            return $this->parse_event($raw_body);
        }

        // Try previous within 30-minute rotation window
        if ($previous !== '' && (time() - $rotated_at) < self::ROTATION_WINDOW_SECONDS) {
            if ($this->signature_matches($raw_body, $signature_header, $previous)) {
                return $this->parse_event($raw_body);
            }
        }

        throw new \local_fastpix\exception\hmac_invalid('signature_mismatch');
    }

    private function signature_matches(string $body, string $header, string $secret): bool {
        $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));
        return hash_equals($expected, $header);
    }

    private function parse_event(string $raw_body): \stdClass {
        $payload = json_decode($raw_body);
        if (!is_object($payload)) {
            throw new \local_fastpix\exception\hmac_invalid('invalid_json');
        }

        // Normalize FastPix's payload into the internal shape used by projector
        return (object)[
            'id'         => $payload->id ?? null,
            'type'       => $payload->type ?? '',
            'created_at' => isset($payload->createdAt)
                ? strtotime($payload->createdAt)
                : time(),
            'object'     => $payload->object ?? null,
            'data'       => $payload->data ?? null,
        ];
    }
}
```

**Important changes from the v1 architecture:**
- **No timestamp header.** FastPix doesn't send one.
- **No replay-window check at HMAC level.** Defense is UNIQUE on provider_event_id.
- Header name is `HTTP_FASTPIX_SIGNATURE` (PHP normalizes `FastPix-Signature` → `HTTP_FASTPIX_SIGNATURE`).
- Signature is base64-encoded raw HMAC output, not hex.
- `hash_equals` for timing-safe comparison (still mandatory).

### 13.3 Mandatory tests (`verifier_test.php`, 90%)

- Valid signature with current secret → ok.
- Valid signature with previous secret within 30min → ok.
- Valid signature with previous secret after 30min → fails.
- Wrong signature → fails.
- Tampered body → fails.
- Empty signature header → fails.
- No secret configured → fails with `no_secret_configured`.
- Malformed JSON body → fails with `invalid_json`.
- Constant-time comparison verified by code review (no `===`, only `hash_equals`).
- Signature header lowercase/uppercase/mixed-case all accepted (PHP normalizes; verify with `strtolower` if needed for cross-version compat).

---

## 14. The webhook projector — production-grade with locking

This is the biggest production-grade improvement.

### 14.1 Why locking matters

A clustered Moodle install runs N parallel cron processes. Two webhooks for the same asset arrive close together. Both get inserted in the ledger and enqueued. Workers A and B each pick up one and read the asset row at the same moment:

```
Worker A: SELECT asset → status='processing', last_event_at=100
Worker B: SELECT asset → status='processing', last_event_at=100
Worker A: applies media.ready (event_at=110)  → status='ready', last_event_at=110
Worker B: applies media.failed (event_at=105) → status='failed', last_event_at=105 ← WRONG
```

The ordering guard sees `last_event_at=100` for both reads (both before either wrote) and lets both proceed. A `media.failed` overwrites a `media.ready`.

**Fix:** acquire a per-asset lock for the read-then-write critical section.

### 14.2 The projector

```php
namespace local_fastpix\webhook;

use core\lock\lock_config;

class projector {

    private const LOCK_TIMEOUT_SECONDS = 5;
    private const LOCK_RESOURCE_PREFIX = 'asset_';

    public function project(\stdClass $event): void {
        $asset_key = $this->extract_asset_key($event);
        if ($asset_key === null) {
            $this->project_unlocked($event);
            return;
        }

        $factory = lock_config::get_lock_factory('local_fastpix');
        $lock = $factory->get_lock(
            self::LOCK_RESOURCE_PREFIX . $asset_key,
            self::LOCK_TIMEOUT_SECONDS
        );

        if (!$lock) {
            // Another worker is projecting; throw so adhoc retry re-queues with backoff
            throw new \local_fastpix\exception\lock_acquisition_failed(
                "asset_lock:{$asset_key}"
            );
        }

        try {
            $this->project_inside_lock($event, $asset_key);
        } finally {
            $lock->release();
        }
    }

    private function project_inside_lock(\stdClass $event, string $asset_key): void {
        global $DB;

        $asset = $DB->get_record('local_fastpix_asset', ['fastpix_id' => $asset_key]);

        if (!$asset) {
            if ($event->type === 'video.media.created') {
                $this->insert_new_asset($event);
                $this->mark_ledger_projected($event->id);
                return;
            }
            mtrace_log_warn('webhook.asset_missing', [
                'event_id' => $event->id,
                'asset_id' => $asset_key,
                'event_type' => $event->type,
            ]);
            $this->mark_ledger_projected($event->id);
            return;
        }

        // TOTAL ordering guard: by event_created_at, then by provider_event_id lexicographic
        $is_out_of_order = $asset->last_event_at !== null && (
            $event->created_at < (int)$asset->last_event_at
            || (
                $event->created_at === (int)$asset->last_event_at
                && $event->id <= $asset->last_event_id
            )
        );

        if ($is_out_of_order) {
            mtrace_log_warn('webhook.out_of_order', [
                'event_id'         => $event->id,
                'event_created_at' => $event->created_at,
                'last_event_id'    => $asset->last_event_id,
                'last_event_at'    => $asset->last_event_at,
            ]);
            $this->mark_ledger_projected($event->id);
            return;
        }

        // Dispatch by type
        $dispatcher = new event_dispatcher();
        $dispatcher->apply($event, $asset);

        // Update last_event tracking
        $DB->update_record('local_fastpix_asset', (object)[
            'id'            => $asset->id,
            'last_event_at' => $event->created_at,
            'last_event_id' => $event->id,
            'timemodified'  => time(),
        ]);

        $this->mark_ledger_projected($event->id);

        // Invalidate caches (both fastpix_id and playback_id keys)
        $cache = \cache::make('local_fastpix', 'asset');
        $cache->delete($asset_key);
        if (!empty($asset->playback_id)) {
            $cache->delete('pb:' . $asset->playback_id);
        }
    }

    private function extract_asset_key(\stdClass $event): ?string {
        // Per FastPix docs, asset ID is at event.object.id, NOT event.data.id
        return $event->object->id ?? null;
    }

    private function insert_new_asset(\stdClass $event): void {
        global $DB;
        $now = time();
        $data = $event->data ?? new \stdClass();

        // Try to find owner from upload session metadata (the metadata we set
        // at upload time contained moodle_owner_userhash — match back to user).
        $owner_userid = $this->resolve_owner_from_upload_metadata($event) ?? 0;

        $DB->insert_record('local_fastpix_asset', (object)[
            'fastpix_id'     => $event->object->id,
            'playback_id'    => null,  // arrives with media.ready
            'owner_userid'   => $owner_userid,
            'title'          => $data->title ?? "Untitled {$event->object->id}",
            'duration'       => null,
            'status'         => 'created',
            'access_policy'  => $data->accessPolicy ?? 'private',
            'drm_required'   => ($data->accessPolicy ?? '') === 'drm' ? 1 : 0,
            'no_skip_required' => 0,
            'has_captions'   => 0,
            'last_event_id'  => $event->id,
            'last_event_at'  => $event->created_at,
            'deleted_at'     => null,
            'timecreated'    => $now,
            'timemodified'   => $now,
        ]);
    }

    private function project_unlocked(\stdClass $event): void {
        // For account-level events with no asset to lock.
        // Currently no events of this kind in v1.0; left as forward-compat seam.
        $this->mark_ledger_projected($event->id);
    }

    private function mark_ledger_projected(string $provider_event_id): void {
        global $DB;
        $DB->set_field('local_fastpix_webhook_event', 'status', 'projected',
            ['provider_event_id' => $provider_event_id]);
    }

    private function resolve_owner_from_upload_metadata(\stdClass $event): ?int {
        // The metadata we put on upload contained moodle_owner_userhash.
        // It's on event.data.metadata. Reverse-lookup via existing user iteration
        // is expensive; instead, we cache (upload_id → userid) at upload time.
        // TODO: full implementation in upload_service week 4. For week 3 stub: return null.
        return null;
    }
}
```

### 14.3 Mandatory tests (`projector_test.php`, 90%)

- Each event type produces correct DB state.
- Out-of-order by timestamp dropped.
- **Equal timestamp + lex-smaller provider_event_id dropped.**
- **Equal timestamp + lex-larger provider_event_id applied.**
- **Same provider_event_id as `last_event_id` dropped.**
- Duplicate event NO-OP (ledger UNIQUE catches; projector tolerates re-call).
- Unknown event_type stored with status='projected', no error.
- `asset_ready` emitted ONLY when transitioning to ready.
- **Lock released even when projection throws (finally tested).**
- **Lock acquisition timeout throws `lock_acquisition_failed`, ledger NOT marked projected.**
- **Two simultaneous calls on same asset_key serialize correctly; final state is the totally-ordered later event.**
- **Asset key extracted from `event.object.id`, NOT `event.data.id`.**

### 14.4 Lock contention integration test (`lock_contention_test.php`)

```php
public function test_concurrent_projection_of_same_asset() {
    // Spawn 2 PHP processes via proc_open
    //   A: project event_at=110 (media.ready)
    //   B: project event_at=105 (media.failed)
    // Assert: final asset.status === 'ready' (event_at=110 wins by total order)
    // Assert: both ledger rows status='projected'
    // Assert: no exception bubbled out
}

public function test_lock_held_too_long_causes_requeue() {
    // Process A holds lock for 10s (sleep inside project)
    // Process B tries to acquire 5s lock → fails → throws lock_acquisition_failed
    // Adhoc task runner re-queues
    // After A finishes, B retries successfully
}
```

---

## 15. The services

### 15.1 `feature_flag_service.php` (new in production-grade)

```php
namespace local_fastpix\service;

class feature_flag_service {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function drm_enabled(): bool {
        // Both the flag AND the configuration ID must be present
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

### 15.2 `playback_service.php`

Called by `mod_fastpix\view.php`, `filter_fastpix\filter.php`, and `mod_fastpix`'s DRM refresh AMD endpoint.

```php
namespace local_fastpix\service;

class playback_service {

    public static function instance(): self {
        return new self();
    }

    public function resolve(
        \stdClass $asset,
        int $userid,
        \context $context
    ): \local_fastpix\dto\playback_payload {

        if (empty($asset->playback_id)) {
            // Asset is not yet playable (still processing)
            throw new \local_fastpix\exception\asset_not_found(
                "asset {$asset->fastpix_id} has no playback_id yet"
            );
        }

        $features = feature_flag_service::instance();

        // Effective DRM = per-asset config AND site-wide flag both true
        $effective_drm_required = $asset->drm_required && $features->drm_enabled();

        // Sign JWT locally (1-5ms CPU work; not an HTTP call)
        $jwt_signer = new jwt_signing_service();
        $jwt = $jwt_signer->sign_for_playback($asset->playback_id);

        // Watermark
        $watermark_html = '';
        if ($features->watermark_enabled()) {
            $watermark_html = watermark_service::build_for_user($userid);
        }

        return new \local_fastpix\dto\playback_payload(
            playback_id:       $asset->playback_id,
            playback_token:    $jwt,
            drm_token:         $effective_drm_required ? $jwt : null,  // same JWT serves both
            drm_required:      $effective_drm_required,
            has_captions:      (bool)$asset->has_captions,
            no_skip_required:  (bool)$asset->no_skip_required,
            watermark_html:    $watermark_html,
            token_ttl_seconds: $jwt_signer->token_ttl_seconds(),
        );
    }
}
```

**Critical rules:**
- Per FastPix docs, the same JWT can serve both `token` and `drm-token` attributes when generated with full claims. We do that.
- `drm_token` is null when DRM is not effective; mod_fastpix's mustache template conditionally renders the attribute.
- Site-wide DRM kill-switch off + asset configured DRM → degraded non-DRM playback (intentional). Operator turned it off; honor it.

### 15.3 `asset_service.php` (with lazy fetch)

```php
namespace local_fastpix\service;

class asset_service {

    /**
     * Standard lookup. Returns null on miss. Use on the WRITE path
     * (webhook projection, GDPR delete) — never trigger outbound HTTP from projector.
     */
    public static function get_by_fastpix_id(
        string $fastpix_id,
        bool $include_deleted = false
    ): ?\stdClass {
        global $DB;

        $cache = \cache::make('local_fastpix', 'asset');
        $cached = $cache->get($fastpix_id);
        if ($cached !== false) {
            if (!$include_deleted && $cached->deleted_at) {
                return null;
            }
            return $cached;
        }

        $asset = $DB->get_record('local_fastpix_asset', ['fastpix_id' => $fastpix_id]);
        if (!$asset) {
            return null;
        }

        $cache->set($fastpix_id, $asset);

        if (!$include_deleted && $asset->deleted_at) {
            return null;
        }
        return $asset;
    }

    /**
     * Filter and tiny lookup by playback_id (the shortcode value).
     * Hot path; same caching strategy keyed on 'pb:<id>'.
     */
    public static function get_by_playback_id(
        string $playback_id,
        bool $include_deleted = false
    ): ?\stdClass {
        global $DB;

        $cache_key = 'pb:' . $playback_id;
        $cache = \cache::make('local_fastpix', 'asset');
        $cached = $cache->get($cache_key);
        if ($cached !== false) {
            if (!$include_deleted && $cached->deleted_at) {
                return null;
            }
            return $cached;
        }

        $asset = $DB->get_record('local_fastpix_asset', ['playback_id' => $playback_id]);
        if (!$asset) {
            return null;
        }

        $cache->set($cache_key, $asset);

        if (!$include_deleted && $asset->deleted_at) {
            return null;
        }
        return $asset;
    }

    /**
     * Read-path with lazy fetch. Used by playback_service's caller (mod_fastpix view).
     *
     * NEVER call this from a webhook projection path.
     *
     * @throws \local_fastpix\exception\asset_not_found if FastPix also says it doesn't exist
     * @throws \local_fastpix\exception\gateway_unavailable if FastPix is down
     */
    public static function get_by_fastpix_id_or_fetch(string $fastpix_id): \stdClass {
        $asset = self::get_by_fastpix_id($fastpix_id);
        if ($asset !== null) {
            return $asset;
        }

        // Cold start: fetch from FastPix
        try {
            $remote = \local_fastpix\api\gateway::instance()->get_media($fastpix_id);
        } catch (\local_fastpix\exception\gateway_not_found $e) {
            throw new \local_fastpix\exception\asset_not_found($fastpix_id);
        }

        global $DB;

        // Extract first private/drm playback_id from response
        $playback_id = null;
        $access_policy = $remote->data->accessPolicy ?? 'private';
        if (!empty($remote->data->playbackIds)) {
            foreach ($remote->data->playbackIds as $pb) {
                if (in_array($pb->accessPolicy ?? '', ['private', 'drm'])) {
                    $playback_id = $pb->id;
                    $access_policy = $pb->accessPolicy;
                    break;
                }
            }
        }

        $now = time();
        $row = (object)[
            'fastpix_id'     => $remote->data->id,
            'playback_id'    => $playback_id,
            'owner_userid'   => 0,  // sentinel: cold-start, owner unknown
            'title'          => $remote->data->title ?? "Imported {$remote->data->id}",
            'duration'       => $remote->data->duration ?? null,
            'status'         => $remote->data->status ?? 'ready',
            'access_policy'  => $access_policy,
            'drm_required'   => $access_policy === 'drm' ? 1 : 0,
            'no_skip_required' => 0,
            'has_captions'   => self::has_caption_track($remote->data),
            'last_event_id'  => null,
            'last_event_at'  => null,
            'deleted_at'     => null,
            'timecreated'    => $now,
            'timemodified'   => $now,
        ];

        try {
            $row->id = $DB->insert_record('local_fastpix_asset', $row);
        } catch (\dml_write_exception $e) {
            // Race: another request inserted in parallel; re-read
            $existing = self::get_by_fastpix_id($fastpix_id);
            if ($existing) {
                return $existing;
            }
            throw $e;
        }

        \cache::make('local_fastpix', 'asset')->set($fastpix_id, $row);
        if ($playback_id) {
            \cache::make('local_fastpix', 'asset')->set('pb:' . $playback_id, $row);
        }

        return $row;
    }

    private static function has_caption_track(\stdClass $media_data): int {
        if (empty($media_data->tracks)) {
            return 0;
        }
        foreach ($media_data->tracks as $track) {
            if (($track->type ?? '') === 'text' && ($track->kind ?? '') === 'captions') {
                return 1;
            }
        }
        return 0;
    }

    public static function get_by_id(int $id, bool $include_deleted = false): ?\stdClass;
    public static function list_for_owner(int $userid, ?string $status = 'ready', int $limit = 50): array;
    public static function list_for_owner_paginated(int $userid, ?string $status, int $offset, int $limit, string $search = ''): array;
    public static function soft_delete(int $id): void;
}
```

### 15.4 `upload_service.php` (with 60s dedup, SSRF guard, DRM payload)

```php
namespace local_fastpix\service;

class upload_service {

    public function create_file_upload_session(
        int $userid,
        array $metadata,
        bool $drm_required = false
    ): \stdClass {
        // Dedup on (userid, filename_hash) within 60s
        $filename_hash = hash('sha256',
            ($metadata['filename'] ?? '') . '|' . ($metadata['size'] ?? 0));
        $dedup_key = "upload:{$userid}:{$filename_hash}";

        $cache = \cache::make('local_fastpix', 'upload_dedup');
        $existing_session_id = $cache->get($dedup_key);
        if ($existing_session_id !== false) {
            global $DB;
            $existing = $DB->get_record('local_fastpix_upload_session',
                ['id' => $existing_session_id]);
            if ($existing && $existing->expires_at > time()) {
                return (object)[
                    'upload_url' => $existing->upload_url,
                    'upload_id'  => $existing->upload_id,
                    'session_id' => $existing->id,
                    'expires_at' => $existing->expires_at,
                    'deduped'    => true,
                ];
            }
        }

        // Determine access policy
        $features = feature_flag_service::instance();
        if ($drm_required && !$features->drm_enabled()) {
            throw new \local_fastpix\exception\drm_not_configured();
        }
        $access_policy = $drm_required ? 'drm' : 'private';
        $drm_config_id = $drm_required ? $features->drm_configuration_id() : null;

        // Build owner hash for FastPix metadata
        $owner_hash = hash_hmac('sha256', $userid,
            get_config('local_fastpix', 'user_hash_salt'));

        $fastpix_metadata = [
            'moodle_owner_userhash' => $owner_hash,
            'moodle_site_url'       => (new \moodle_url('/'))->out(false),
        ];

        // Call FastPix
        $remote = \local_fastpix\api\gateway::instance()->input_video_direct_upload(
            $owner_hash,
            $fastpix_metadata,
            $access_policy,
            $drm_config_id
        );

        // Persist
        global $DB;
        $now = time();
        $session_id = $DB->insert_record('local_fastpix_upload_session', (object)[
            'userid'      => $userid,
            'upload_id'   => $remote->uploadId,
            'upload_url'  => $remote->url,
            'fastpix_id'  => null,  // arrives with video.media.created webhook
            'state'       => 'pending',
            'timecreated' => $now,
            'expires_at'  => $now + 86400,
        ]);

        $cache->set($dedup_key, $session_id);

        return (object)[
            'upload_url' => $remote->url,
            'upload_id'  => $remote->uploadId,
            'session_id' => $session_id,
            'expires_at' => $now + 86400,
            'deduped'    => false,
        ];
    }

    public function create_url_pull_session(
        int $userid,
        string $source_url,
        array $metadata,
        bool $drm_required = false
    ): \stdClass {
        $this->reject_internal_url($source_url);

        // ... parallel structure to file path; uses gateway->media_create_from_url ...
    }

    /**
     * SSRF protection. Reject:
     * - non-http(s) schemes
     * - URLs whose host resolves to RFC1918, loopback, or link-local IPs
     * - DNS rebinding: re-check IP after curl HEAD (CURLINFO_PRIMARY_IP)
     */
    private function reject_internal_url(string $url): void {
        // ... ~30 lines of validation; mandatory test cases listed in §16 ...
    }
}
```

### 15.5 `credential_service.php` (handles signing-key bootstrap)

```php
namespace local_fastpix\service;

class credential_service {

    /**
     * Idempotent signing-key bootstrap.
     * Called from lib.php after admin saves credentials.
     */
    public function ensure_signing_key(): void {
        $kid = get_config('local_fastpix', 'signing_key_id');
        $private_key = get_config('local_fastpix', 'signing_private_key');

        if (!empty($kid) && !empty($private_key)) {
            return;  // already bootstrapped
        }

        $apikey = get_config('local_fastpix', 'apikey');
        $apisecret = get_config('local_fastpix', 'apisecret');
        if (empty($apikey) || empty($apisecret)) {
            // Credentials not yet set; can't bootstrap. Try again next time.
            return;
        }

        $result = \local_fastpix\api\gateway::instance()->create_signing_key();

        set_config('signing_key_id', $result->id, 'local_fastpix');
        set_config('signing_private_key', $result->privateKey, 'local_fastpix');
        set_config('signing_key_created_at', time(), 'local_fastpix');

        mtrace_log_info('credential_service.signing_key_bootstrapped', [
            'kid' => $result->id,
        ]);
    }

    public function rotate_signing_key(): void {
        // Create new key, store as current; keep old kid in 'signing_key_previous_id' for grace period
        // After 30-min grace, scheduled task calls gateway->delete_signing_key(old_kid)
        // ... full implementation ...
    }
}
```

### 15.6 `watermark_service`, `health_service`, `rate_limiter_service`

Unchanged from the v1 architecture. Already correct.

---

## 16. Privacy provider — per-asset GDPR delete

```php
namespace local_fastpix\privacy;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(\core_privacy\local\metadata\collection $collection): \core_privacy\local\metadata\collection {
        $collection->add_database_table('local_fastpix_asset', [
            'owner_userid' => 'privacy:metadata:asset:owner_userid',
            'title'        => 'privacy:metadata:asset:title',
        ], 'privacy:metadata:asset');

        $collection->add_database_table('local_fastpix_upload_session', [
            'userid' => 'privacy:metadata:upload_session:userid',
        ], 'privacy:metadata:upload_session');

        $collection->add_external_location_link('fastpix', [
            'media_id' => 'privacy:metadata:fastpix:media_id',
            'metadata' => 'privacy:metadata:fastpix:metadata',
        ], 'privacy:metadata:fastpix');

        return $collection;
    }

    public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;

        // 1. Soft-delete asset rows
        $assets = $DB->get_records('local_fastpix_asset', ['owner_userid' => $userid]);
        foreach ($assets as $asset) {
            $DB->update_record('local_fastpix_asset', (object)[
                'id' => $asset->id,
                'deleted_at' => time(),
                'gdpr_delete_pending_at' => time(),
                'timemodified' => time(),
            ]);

            // 2. Try to delete on FastPix; on failure, retry task picks up via gdpr_delete_pending_at
            try {
                \local_fastpix\api\gateway::instance()->delete_media($asset->fastpix_id);
                // Success: clear pending flag
                $DB->set_field('local_fastpix_asset', 'gdpr_delete_pending_at', null,
                    ['id' => $asset->id]);
            } catch (\local_fastpix\exception\gateway_unavailable $e) {
                // Stays pending; retry_gdpr_delete picks up
            }
        }

        // 3. Hard-delete upload sessions
        $DB->delete_records('local_fastpix_upload_session', ['userid' => $userid]);
    }

    // ... get_contexts_for_userid, get_users_in_context, export_user_data, delete_data_for_users ...
}
```

The `retry_gdpr_delete` task scans `WHERE gdpr_delete_pending_at IS NOT NULL AND gdpr_delete_pending_at < (NOW - 15 min)`, retries each, alerts after 6 consecutive failures per asset.

---

## 17. Build order (the file-by-file plan)

Execute in this order. Each step has a verification checkpoint.

### Week 1 — Skeleton + schema

1. `version.php` (#1) — minimal install.
2. `lang/en/local_fastpix.php` (#7) — `pluginname` and feature-flag strings.
3. `db/install.xml` (#8) — including all new columns and indexes.
4. `db/access.php` (#10).
5. `db/upgrade.php` (#9) — empty function returning true.
6. **VERIFY:** plugin installs on clean Moodle; all 5 tables created with all indexes; capability registered.
7. `lib.php` (#2) — auto-generate session_secret and user_hash_salt.
8. `settings.php` (#3) — including the three feature flags AND the signing-key fields AND the DRM configuration ID.
9. `classes/service/feature_flag_service.php` (#17).
10. `tests/feature_flag_service_test.php` (#62).
11. **VERIFY:** admin can paste credentials; toggle feature flags; flags round-trip via `get_config`.

### Week 2 — Gateway, JWT signing, credential bootstrap

12. `classes/exception/*` (#41–#50) — ten typed exceptions.
13. **Vendor `firebase/php-jwt` v6+ into `classes/vendor/php-jwt/`** (#52). Six PHP files; document the version pin in README.
14. `classes/service/jwt_signing_service.php` (#18).
15. `tests/jwt_signing_service_test.php` (#57) — 95% coverage including roundtrip with fixture key pair.
16. `classes/service/credential_service.php` (#16).
17. `tests/credential_service_test.php` (#65) — bootstrap is idempotent; missing credentials short-circuits.
18. `db/caches.php` (#13).
19. `classes/api/gateway.php` (#15) — full implementation with TWO timeout profiles + signing-key endpoints.
20. `tests/gateway_test.php` (#54) — 95% coverage with FastPix mock.
21. **VERIFY:** `gateway::health_probe()` true; `gateway::create_signing_key()` returns valid response; `gateway::get_media('nonexistent')` throws `gateway_not_found`.
22. **Configure FastPix mock to return realistic responses** matching the contract in §3.

### Week 3 — Webhook ingestion (with locking)

23. `db/services.php` (#11).
24. `classes/event/*` (#36–#38).
25. `classes/service/rate_limiter_service.php` (#24) + tests #63.
26. `classes/webhook/verifier.php` (#25) — single-header `FastPix-Signature` verification.
27. `tests/verifier_test.php` (#55) — 90% coverage.
28. `classes/webhook/event_dispatcher.php` (#27).
29. `classes/webhook/projector.php` (#26) — with per-asset locking and total-ordering.
30. `tests/projector_test.php` (#56) — 90% coverage including locking and total-order cases.
31. `classes/task/process_webhook.php` (#31).
32. `webhook.php` (#4).
33. `tests/integration/webhook_flood_test.php` (#71) — 1000 events.
34. `tests/integration/secret_rotation_test.php` (#72).
35. `tests/integration/circuit_breaker_test.php` (#73).
36. `tests/integration/lock_contention_test.php` (#74) — two parallel projectors.
37. **VERIFY:** Phase 2 exit. Flood passes; lock contention passes; webhook ledger has 1000 unique rows; asset table correctly projected.

### Week 4 — Services, tasks, privacy, Behat

38. `classes/dto/playback_payload.php` (#51).
39. `classes/service/asset_service.php` (#19) — including `get_by_fastpix_id_or_fetch` and `get_by_playback_id`.
40. `tests/asset_service_test.php` (#58) — including lazy-fetch happy + 404 + race.
41. `classes/service/watermark_service.php` (#22) + tests #60.
42. `classes/service/upload_service.php` (#20) — with 60s dedup + SSRF guard + DRM payload.
43. `tests/upload_service_test.php` (#61) — all dedup, SSRF, and DRM cases.
44. `classes/service/playback_service.php` (#21) — flag-aware, calls jwt_signing_service.
45. `tests/playback_service_test.php` (#59) — including feature-flag and DRM-degraded tests.
46. `classes/external/*` (#28–#30).
47. `upload_session.php` (#5).
48. `classes/task/orphan_sweeper.php` (#32) + tests #66.
49. `classes/task/prune_webhook_ledger.php` (#33) + tests #67.
50. `classes/task/purge_soft_deleted_assets.php` (#34) — daily, 7-day boundary + tests #68.
51. `classes/task/retry_gdpr_delete.php` (#35) + tests #69.
52. `db/tasks.php` (#12) — four tasks registered.
53. `classes/privacy/provider.php` (#39) + tests #70 — per-asset DELETE pattern.
54. `classes/service/health_service.php` (#23) + tests #64.
55. `health.php` (#6).
56. `classes/output/degraded_banner_renderer.php` (#40) + template (#53).
57. `tests/behat/*` (#75, #76).
58. `README.md` (#77), `CHANGELOG.md` (#78).
59. **VERIFY:** Phase 3 exit. End-to-end loop closes; all production-grade tests pass; install on clean Moodle plays through "admin saves credentials → signing key bootstrapped → upload via curl → webhook arrives → asset row in DB with playback_id populated".

---

## 18. Failure modes (production-grade view)

| Failure | Detection | Handling |
|---|---|---|
| FastPix `get_media` 5xx | gateway exception (3s timeout) | Retry 3x; throw to caller; UI shows "Video unavailable" |
| FastPix `get_media` 404 | `gateway_not_found` | Translate to `asset_not_found`; UI shows "Video unavailable" |
| FastPix sustained outage | circuit breaker opens | New uploads fail; degraded banner triggers |
| Webhook HMAC mismatch | verifier returns false | webhook_invalid event, HTTP 401, do NOT log payload |
| Webhook UNIQUE violation | `dml_write_exception` | SUCCESS path for duplicates; return 200 |
| Webhook out-of-order (strict) | ordering guard + lex tiebreak | Skip projection, mark ledger projected |
| **Two cron workers on same asset** | **per-asset lock contention** | **One waits 5s, the other proceeds; on timeout, throws and re-queues** |
| MUC unreachable | cache exception | Fail-open: rate limiter true, gateway no breaker memory but works |
| **Signing key not bootstrapped** | **`signing_key_missing` exception in playback** | **UI shows "Plugin misconfigured — contact administrator"; admin alert event fires** |
| **DRM requested but no `drm_configuration_id`** | **`drm_not_configured` exception in upload_service** | **mod_form validation error; teacher prompted to disable DRM or contact admin** |
| GDPR delete fails | gateway exception in privacy provider | Local soft-delete completes; `gdpr_delete_pending_at` set; retry task picks up |
| GDPR delete fails 6 consecutive times per asset | retry counter | Admin alert event |
| Adhoc `process_webhook` fails | task throws | Moodle re-runs with backoff; after 5 fails moves to DLQ |
| Upload session abandoned (24h) | `expires_at < now()` | `orphan_sweeper` deletes |
| **Upload double-click** | **dedup cache hit** | **Returns same `session_id`; `deduped=true` returned** |
| **Cold-start playback** | **DB miss in `get_by_fastpix_id_or_fetch`** | **Lazy fetch from FastPix; insert with sentinel owner=0** |
| **Soft-deleted row > 7d old** | **purge task scans `idx_deleted_at`** | **Hard DELETE; cascades to track table via FK** |
| **DRM kill-switch flipped off** | **`feature_flag_service::drm_enabled()` returns false** | **Existing JWTs continue to work until expiry; new playbacks serve non-DRM (degraded)** |
| **Watermark kill-switch off** | **`feature_flag_service::watermark_enabled()` returns false** | **`watermark_html` empty; player renders without watermark content** |

---

## 19. Definition of done

ALL of the following must pass:

**Foundation:**
- [ ] `moodle-plugin-ci` passes on the full PHP × Moodle × DB matrix.
- [ ] PHPUnit coverage: gateway 95%, verifier 90%, projector 90%, jwt_signing_service 95%, all other services 85%.
- [ ] Plugin installs/uninstalls cleanly with zero orphan tables.
- [ ] No raw `mysqli_*`, no `===` on signature comparison, all strings in lang file.
- [ ] All capability checks present on every privileged endpoint (except webhook.php which is HMAC-authenticated).
- [ ] `firebase/php-jwt` is vendored, version pinned in README.

**Webhook ingestion:**
- [ ] Webhook flood (1000 events, 50% duplicates, 10% out-of-order) projects with zero corruption.
- [ ] Dual-secret rotation: 30-min window for previous secret accepted; rejected after.
- [ ] Single `FastPix-Signature` header verified; no timestamp dependency.
- [ ] Asset key extracted from `event.object.id`, NOT `event.data.id`.

**Production-grade hardening:**
- [ ] **Lock contention:** two simultaneous projections of the same asset serialize correctly; final state matches the totally-ordered later event.
- [ ] **Total-ordering tiebreak:** equal timestamps ordered by `provider_event_id`; out-of-order-by-tiebreak dropped.
- [ ] **Lock release on exception:** projector lock released even when projection throws.
- [ ] **Lock acquisition timeout:** 5s timeout throws `lock_acquisition_failed`; Moodle re-queues.
- [ ] **Hot-path timeout:** `get_media` fails at 3s; `input_video_direct_upload` succeeds at 5s on the same slow endpoint.
- [ ] **Circuit breaker** correctly shares state across two simulated FPM workers via MUC.

**JWT signing:**
- [ ] **Sign roundtrip:** sign with private key, decode with public key (test fixture), payload claims match.
- [ ] **Missing key:** signing without bootstrapped key throws `signing_key_missing`.
- [ ] **`aud` claim format:** exactly `media:<playback_id>`.
- [ ] **`alg` is RS256** (matches the RSA-2048 key returned by FastPix).

**Lazy fetch:**
- [ ] Cold-start playback for asset not in DB triggers `get_media`, inserts with sentinel owner=0, plays correctly.
- [ ] Two concurrent first-views: only one INSERT occurs; UNIQUE catch and re-read works.
- [ ] FastPix 404 → `asset_not_found` → caller renders "Video unavailable".

**Cleanup:**
- [ ] Soft-deleted asset > 7 days hard-deleted; cascade to track table.
- [ ] Soft-deleted boundary: 6d 23h NOT purged; 7d 1m IS purged.

**Feature flags:**
- [ ] DRM site-wide flag false → playback non-DRM even for `drm_required=1` assets; existing JWTs continue.
- [ ] Watermark site-wide flag false → empty `watermark_html`.
- [ ] DRM flag depends on BOTH the checkbox AND `drm_configuration_id` being set.

**Upload dedup + SSRF:**
- [ ] Two `create_file_upload_session` calls within 60s for same (userid, filename_hash) return identical `session_id`, second has `deduped=true`.
- [ ] Dedup boundary: 59s deduplicates; 61s creates new.
- [ ] SSRF: URL pull rejects localhost, RFC1918, link-local, AWS metadata IP, DNS-rebinding.

**Privacy:**
- [ ] Privacy export round-trips for synthetic user with 3 assets and 2 upload sessions.
- [ ] Privacy delete: per-asset DELETE; on FastPix failure, `gdpr_delete_pending_at` set; retry task completes within 24h.
- [ ] Alert event fires after 6 consecutive failures per asset.

**Health:**
- [ ] Health endpoint returns valid JSON; HTTP 503 when FastPix probe fails.

When all 30 boxes are checked, `local_fastpix` is GA-ready and `mod_fastpix` (Plugin 2) can begin.

---

## 20. Moodle pitfalls cheat sheet (for the implementing engineer)

These are Moodle-specific gotchas that an LLM unfamiliar with Moodle will trip on. Keep this list visible during implementation.

| Pitfall | Correction |
|---|---|
| `$DB->insert_record()` returns the new ID | Capture the return value; don't assume void |
| `$DB->update_record()` returns void / true | Don't try to capture an ID from this |
| `$DB->set_field()` and `$DB->delete_records()` are simpler than full update_record when you only change one field | Use them; saves a round-trip read |
| `cache::make($component, $area)` is per-request memoized | Calling twice in one request returns same instance — fine |
| MUC backends: shared state requires Redis/Memcached configured | Document this in README; circuit breaker correctness depends on it |
| Mustache `{{var}}` escapes; `{{{var}}}` does not | Use `{{}}` for everything except pre-escaped HTML (watermark) |
| `format_string()` for plain strings | Use for asset titles |
| `s()` for HTML attributes | Use for shortcode parameters in attribute context |
| `format_text()` for rich text | Use for activity intros, descriptions |
| `passwordunmask` is UI-only; storage is plaintext in `mdl_config_plugins` | Don't pretend it's encrypted; document this |
| `external_api::validate_parameters()` throws `invalid_parameter_exception` | Don't add a parallel validator layer |
| Adhoc tasks extend `\core\task\adhoc_task`, not `scheduled_task` | Easy to get backwards |
| `\core\task\manager::queue_adhoc_task($task)` enqueues; the cron runs it | Don't call `execute()` directly except in tests |
| `\core\lock\lock_config::get_lock_factory()` returns a factory | Factory's `get_lock(resource, timeout)` returns a `\core\lock\lock` OR `false` (not null) |
| `hash_equals($expected, $provided)` is the only safe signature comparison | Never `===` on signatures |
| `require_capability()` is the security boundary; `has_capability()` is for branching UI | Don't substitute one for the other |
| `mtrace_log_*` is Moodle's logging helper | Not all sites have it; some use `error_log` — confirm at runtime |
| `random_string(N)` is Moodle's CSPRNG wrapper | Use for secret generation, not `mt_rand` |
| Moodle's plugin uninstall removes config and tables automatically | Don't write custom uninstall hooks for these |
| `\core\http_client` is Moodle's Guzzle wrapper | Use this, not `curl_*` directly |
| Moodle does NOT have a built-in JWT library | Vendor `firebase/php-jwt` |
| `$CFG->prefix` is auto-applied to all $DB calls | Never write `mdl_` literally; just use the table name |
| `lib.php` is for legacy callbacks ONLY | Business logic lives in `classes/`; keep lib.php thin |
| Static caching in services (`private static ?self $instance`) needs reset for tests | Provide a `reset()` method for PHPUnit `tearDown` |

---

## 21. The seven hardening additions, mapped (for reference)

| # | Change | Files |
|---|---|---|
| 1 | Per-asset locking | projector.php (#26), lock_acquisition_failed.php (#48), projector_test.php (#56), lock_contention_test.php (#74) |
| 2 | Total-ordering lex tiebreak | projector.php (#26), projector_test.php (#56) |
| 3 | Lazy fetch on cold-start | gateway.php (#15) `get_media`, gateway_not_found.php (#43), asset_service.php (#19) `get_by_fastpix_id_or_fetch`, asset_service_test.php (#58) |
| 4 | Hot-path timeout differentiation | gateway.php (#15), gateway_test.php (#54) |
| 5 | Soft-deleted purge task | purge_soft_deleted_assets.php (#34), test #68, db/tasks.php (#12), `idx_deleted_at` |
| 6 | Three feature kill-switches | feature_flag_service.php (#17), test #62, settings.php (#3), playback_service.php (#21) |
| 7 | Upload 60s dedup | upload_service.php (#20), test #61, db/caches.php (#13) `upload_dedup`, `idx_user_created` |

Plus the FastPix-API-driven corrections that were not in the v1 architecture:

| # | Correction | Files |
|---|---|---|
| A | Local JWT signing (replaces non-existent `createToken`) | jwt_signing_service.php (#18), test #57, vendored php-jwt (#52), playback_service.php (#21) |
| B | Signing-key bootstrap on first install | gateway.php (#15) `create_signing_key`/`delete_signing_key`, credential_service.php (#16), test #65, signing_key_missing.php (#49) |
| C | Three IDs (Upload, Media, Playback) clarity | Schema (#8) — `playback_id` column with unique index + `upload_id` column on session table |
| D | Webhook signature scheme correction | verifier.php (#25) — single header, no timestamp |
| E | Per-asset GDPR delete pattern | privacy/provider.php (#39), `gdpr_delete_pending_at` column, retry task (#35) |
| F | DRM activation gate | drm_not_configured.php (#50), feature_flag_service requires both flag AND config_id, mod_form validation (in mod_fastpix) |

These twelve changes together move the plugin from "matches the original design doc" (which had errors) to "matches FastPix's actual API and survives a real customer's first month".
