# FastPix × Moodle — System Architecture (Master Overview)

**Read this first. Every plugin document references this one.**

This document is the shared context for the FastPix × Moodle integration. Four Moodle plugins ship together. This file gives you the system, the conventions, the dependency order, and the rules that apply to all four. Each plugin then has its own architecture document (`01-local-fastpix.md`, `02-mod-fastpix.md`, `03-filter-fastpix.md`, `04-tiny-fastpix.md`) with the plugin-specific detail.

If you are an LLM about to write code for one of these plugins, read this file first, then read that plugin's architecture file. Together they are sufficient to implement the plugin — you should not need the original design document.

---

## 1. What we are building

A four-plugin Moodle integration that brings FastPix's video platform (DRM streaming, captions, webhooks, analytics) into Moodle as a native activity, an editor button, and a rich-text shortcode renderer. Target buyer: paid course creators on Moodle in India, MENA, and SEA who need anti-piracy (DRM + watermark) and watch-percentage completion tied to the gradebook.

**Headline trade-off:** FastPix mints DRM tokens server-side; the plugin holds no signing keys. Saves ~3–4 weeks of engineering at the cost of one API call per playback start (≤800ms p99) and a hard runtime dependency on FastPix's token service.

**Headline architectural decision (ADR-001):** Four plugins, not one or two. The cross-cutting backend (gateway, credentials, asset cache, webhook endpoint) is needed by three different surfaces (activity, editor button, filter) on day one. Putting it in the activity plugin would force the editor button and filter to depend on the activity being enabled. So: one `local` plugin owns infrastructure; three thin surface plugins consume it.

---

## 2. The four plugins, in dependency order

```
┌──────────────────────────────────────────────────────────────────┐
│                          MOODLE CORE                             │
│  (auth, DML, MUC, Events, Tasks, Gradebook, Completion, Backup)  │
└──────────────────────────────────────────────────────────────────┘
       ▲                ▲                 ▲                 ▲
       │                │                 │                 │
┌──────┴───────┐ ┌──────┴───────┐ ┌──────┴───────┐ ┌──────┴───────┐
│ local_fastpix│ │  mod_fastpix │ │filter_fastpix│ │ tiny_fastpix │
│ (BACKEND)    │ │  (ACTIVITY)  │ │  (SHORTCODE) │ │ (EDITOR BTN) │
│              │ │              │ │              │ │              │
│ • Gateway    │ │ • Activity   │ │ • {fastpix:} │ │ • Picker UI  │
│ • Webhook    │ │   table      │ │   render     │ │ • Insert     │
│ • Asset DB   │ │ • Attempts   │ │ • Cap check  │ │   shortcode  │
│ • Tasks      │ │ • Watch      │ │              │ │              │
│ • Privacy    │ │   tracker    │ │              │ │              │
│ • Settings   │ │ • Completion │ │              │ │              │
│ • Health     │ │ • Gradebook  │ │              │ │              │
└──────┬───────┘ └──────┬───────┘ └──────┬───────┘ └──────┬───────┘
       │                │                 │                 │
       │                │ depends on      │ depends on      │ depends on
       │                ▼                 ▼                 ▼
       │          local_fastpix     local_fastpix     local_fastpix
       │                            + mod_fastpix
       ▼                            (capability only)
┌──────────────┐
│   FASTPIX    │   ← only local_fastpix talks to FastPix.
│   PLATFORM   │     The other three call into local_fastpix.
└──────────────┘
```

**Build order is fixed by the dependency graph:**

| # | Plugin | Type | Path | Depends on | Build weeks |
|---|---|---|---|---|---|
| 1 | `local_fastpix` | local | `local/fastpix/` | Moodle core only | 1–4 |
| 2 | `mod_fastpix` | mod | `mod/fastpix/` | `local_fastpix` | 5–6 |
| 3 | `filter_fastpix` | filter | `filter/fastpix/` | `local_fastpix` + `mod_fastpix` (capability `mod/fastpix:view`) | 7 (first half) |
| 4 | `tiny_fastpix` | tiny | `lib/editor/tiny/plugins/fastpix/` | `local_fastpix` | 7 (second half) |

Cross-cutting work (privacy, backup, health, hardening, pilot) happens in weeks 8–14 as passes back over the four plugins, not as a fifth plugin.

---

## 3. Repository layout

**One Git repo, four plugin folders matching Moodle's filesystem layout 1:1.**

```
fastpix-moodle/                                 ← single Git repo
├── local/fastpix/                              ← becomes local_fastpix on Moodle
├── mod/fastpix/                                ← becomes mod_fastpix
├── filter/fastpix/                             ← becomes filter_fastpix
├── lib/editor/tiny/plugins/fastpix/            ← becomes tiny_fastpix
├── .github/workflows/ci.yml                    ← single CI matrix
├── docs/
│   ├── architecture/                           ← these five files
│   ├── runbooks/                               ← incident playbooks
│   └── adr/                                    ← ADR-001 .. ADR-011
├── tools/
│   └── release.sh                              ← packages 4 ZIPs from one tag
├── scripts/dev/
│   ├── docker-compose.yml                      ← Moodle + Postgres + Redis + FastPix mock
│   ├── fastpix-mock/                           ← stub FastPix API for local dev
│   └── seed.sh
├── README.md
└── CHANGELOG.md
```

In development, symlink the four plugin folders into a Moodle install at their canonical paths; in CI, the `moodle-plugin-ci` setup does this automatically. At release time, `tools/release.sh` produces four separate ZIPs from a single Git tag — they are submitted to the Moodle Plugins Directory as four listings.

---

## 4. Target environment

| Constraint | Value |
|---|---|
| Moodle | 4.5 LTS primary; 5.0 supported; 5.1 with TinyMCE 7 shim |
| PHP | 8.2 / 8.3 / 8.4 |
| Database | MySQL 8 / MariaDB 10.6 / PostgreSQL 13 |
| Cache (MUC) | Any Moodle-supported store; Redis recommended in production |
| Browsers | Chrome, Safari, Firefox, Edge, Moodle Mobile WebView |
| FastPix | Hard runtime dependency; no fallback host |

Plugin must pass `moodle-plugin-ci` (phpcs, phpmd, phpunit, behat, mustache lint, savepoints check, install/uninstall) on the full matrix on every PR.

---

## 5. Naming and code conventions (apply to all four plugins)

### 5.1 Frankenstyle naming

| Layer | Format | Example |
|---|---|---|
| Plugin folder | `<type>/<name>` | `local/fastpix`, `mod/fastpix` |
| Plugin component name | `<type>_<name>` | `local_fastpix`, `mod_fastpix` |
| Database tables | `<plugin>_<entity>` (Moodle prefixes with `mdl_` automatically) | `local_fastpix_asset`, `fastpix` (mod tables drop the type prefix), `fastpix_attempt` |
| Language string file | `lang/en/<plugin>.php` | `lang/en/local_fastpix.php`, `lang/en/fastpix.php` (mod) |
| Capability string | `<type>/<name>:<action>` | `mod/fastpix:view`, `local/fastpix:configurecredentials` |
| Config key (admin settings) | `<plugin>/<key>` read via `get_config('<plugin>', '<key>')` | `get_config('local_fastpix', 'apikey')` |
| Web service function name | `<plugin>_<action>` | `mod_fastpix_record_view_progress`, `tiny_fastpix_get_my_videos` |
| Event class | `\<plugin>\event\<event_name>` | `\mod_fastpix\event\completion_recorded` |

### 5.2 PHP namespace conventions

```
\local_fastpix\api\gateway              ← integration layer (FastPix HTTP)
\local_fastpix\service\<feature>_service ← business logic
\local_fastpix\external\<action>         ← external function (web service)
\local_fastpix\task\<task_name>          ← scheduled or adhoc task
\local_fastpix\event\<event_name>        ← custom event
\local_fastpix\webhook\verifier          ← HMAC verifier
\local_fastpix\webhook\projector         ← webhook → asset projection
\local_fastpix\privacy\provider          ← GDPR provider
\local_fastpix\output\<renderable>       ← output renderable
\local_fastpix\form\<form_name>          ← custom form
\local_fastpix\exception\<exception>     ← typed exceptions
```

Same shape applies to `mod_fastpix`, `filter_fastpix`, `tiny_fastpix`. Class names are lowercase with underscores (Moodle convention, not PSR). One class per file, file name matches class name.

### 5.3 Three-layer rule (no exceptions)

| Layer | Lives in | What it does | What it must NOT do |
|---|---|---|---|
| **UI / endpoint** | `*.php` at plugin root, `classes/external/`, `templates/`, `amd/src/` | require_login, require_capability, require_sesskey, validate input, delegate to service, render output | Contain business logic. Touch external HTTP. |
| **Service** | `classes/service/` | Business rules, idempotent operations, returns plain data | Read `$_GET` / `$_POST` / `$OUTPUT`. Make HTTP calls (delegates to integration layer). |
| **Integration** | `classes/api/`, `classes/gateway/` | All external HTTP, retry, circuit breaker, idempotency keys | Contain business logic. Be called from anywhere except a service. |

Rule: business logic in a service, not in an endpoint. The same service is callable from a web endpoint, a CLI script, a scheduled task, and an adhoc task. If you find yourself copying logic between endpoints, you have skipped the service layer.

### 5.4 The seven non-negotiables (every PR)

These are blocking rules. PR review rejects on any violation:

1. `require_capability($cap, $context)` on every privileged endpoint and every external function.
2. `require_sesskey()` on every state-changing endpoint (POST/PUT/DELETE).
3. `format_string()` for plain strings, `s()` for HTML attributes, `format_text()` for rich text — never `echo` raw user input.
4. `$DB` only — no `mysqli_*`, no `PDO`, no raw SQL except via `$DB->get_records_sql()` with parameterized placeholders.
5. `version.php` bumped if `db/install.xml` or `db/upgrade.php` changed.
6. Every user-visible string in `lang/en/<plugin>.php` — no English in PHP/Mustache/JS source.
7. Every behavior change has a test: PHPUnit for services, Behat for user flows.

### 5.5 Logging convention

Structured JSON, one line per event, written via Moodle's logstore. Required keys on every log entry:

```json
{
  "ts": "2026-04-30T14:23:45.123Z",
  "level": "info|warn|error",
  "event": "webhook.processed|drm.token.minted|fraud.detected|...",
  "plugin": "local_fastpix|mod_fastpix|filter_fastpix|tiny_fastpix",
  "trace_id": "uuid-v7",
  "user_hash": "<HMAC of user_id>",     // never raw user_id, email, or IP
  "asset_id": "...",                     // when applicable
  "activity_id": 0,                      // when applicable
  "processing_latency_ms": 0
}
```

`user_hash = HMAC-SHA256(user_id, get_config('local_fastpix', 'user_hash_salt'))`. Never log raw `user_id`, email, or IP — GDPR concern.

### 5.6 Error taxonomy

Every plugin defines typed exceptions. Endpoints catch by type, never `catch (\Throwable $e)`.

```
\local_fastpix\exception\gateway_unavailable    ← FastPix returned 5xx after retries
\local_fastpix\exception\gateway_invalid_response ← FastPix returned malformed body
\local_fastpix\exception\hmac_invalid            ← Webhook signature failed verification
\local_fastpix\exception\webhook_replay          ← Timestamp window exceeded
\local_fastpix\exception\rate_limited            ← Per-IP rate limit hit
\local_fastpix\exception\asset_not_found         ← fastpix_id has no row in asset table
\mod_fastpix\exception\session_token_invalid     ← Watch tracker received bad token
\mod_fastpix\exception\fraud_detected            ← Validation check failed
```

---

## 6. The data model (system-wide view)

Every table the system creates, by owner. Detailed XMLDB lives in each plugin's architecture file.

| Table | Owner plugin | Purpose | Retention |
|---|---|---|---|
| `local_fastpix_asset` | local | Cached FastPix asset metadata; projection target for `media.*` webhooks | Soft-delete, 7d hard delete |
| `local_fastpix_track` | local | Caption tracks per asset | With parent asset |
| `local_fastpix_upload_session` | local | In-flight uploads | TTL 24h, swept nightly |
| `local_fastpix_webhook_event` | local | Append-only webhook ledger; UNIQUE on `provider_event_id` | 90d, monthly partitions |
| `local_fastpix_sync_state` | local | Reconciler cursor (reserved for ADR-003, no code in v1.0) | Permanent |
| `fastpix` | mod | Activity instances | Course lifetime |
| `fastpix_attempt` | mod | Per-user watch attempt: session_token, watched_seconds, fraud_count | Course lifetime |

Credentials are stored in Moodle's `mdl_config_plugins` via `passwordunmask` admin setting type — not in a custom table. Read via `get_config('local_fastpix', 'apikey')`.

**Foreign-key direction:** `fastpix.fastpix_asset_id` → `local_fastpix_asset.id`. The activity plugin references the backend's asset table; the backend never references the activity plugin (that would be a circular dependency).

---

## 7. The capability model (system-wide view)

Defined in each plugin's `db/access.php`. Roles are Moodle's defaults: Student, Teacher (editingteacher), Manager.

| Capability | Defined by | Used by | Default roles | Purpose |
|---|---|---|---|---|
| `mod/fastpix:view` | mod_fastpix | view.php, watch_tracker, filter_fastpix | Student, Teacher, Manager | Render and watch a video |
| `mod/fastpix:addinstance` | mod_fastpix | mod_form gating | Editing Teacher, Manager | Add FastPix activity to a course |
| `mod/fastpix:uploadmedia` | mod_fastpix | upload endpoints, picker | Editing Teacher, Manager | Initiate an upload |
| `mod/fastpix:viewfraudreport` | mod_fastpix | gradebook badge renderer | Manager | See fraud_count in gradebook |
| `local/fastpix:configurecredentials` | local_fastpix | admin settings | Manager | Paste/edit FastPix credentials |

**Critical reuse pattern:** `filter_fastpix` and `tiny_fastpix` do NOT define their own capabilities. They reuse `mod/fastpix:view` and `mod/fastpix:uploadmedia` respectively. This is intentional — proliferating capabilities for cosmetically-different actions creates permission management burden for site admins.

---

## 8. External system contract — FastPix

`local_fastpix` is the only plugin that calls FastPix directly. All FastPix HTTP calls go through `\local_fastpix\api\gateway`. Static-analysis test enforces this: any non-gateway file containing `fastpix.io` or `api.fastpix` fails CI.

| Outbound call (gateway method) | Caller | Hot path? | Latency budget |
|---|---|---|---|
| `gateway->input_video_direct_upload(filename, owner_userid)` | `upload_service` | No (teacher-initiated) | ≤2s |
| `gateway->media_create_from_url(source_url, owner_userid)` | `upload_service` (URL pull) | No | ≤2s |
| `gateway->playback_create_token(playback_id, user_hash, ttl=300)` | `playback_service` | **YES** — every playback start + every 4.5min refresh | ≤800ms p99 |
| `gateway->managevideos_delete_owner_data(user_hash)` | `privacy_provider`, `retry_gdpr_delete` task | No | ≤5s |
| `gateway->health_probe()` | `health_service` | No (cron scrape) | ≤3s |

**Inbound:** `webhook.php` is the single ingress for FastPix webhooks. No other endpoint receives FastPix data.

**Failure handling rules (all in gateway):**
- Exponential backoff: 200ms → 400ms → 800ms, max 3 attempts.
- Circuit breaker: 5 consecutive 5xx → open for 30s → half-open with one probe → closed on success. **State stored in MUC, not in-process** (multi-FPM-worker correctness).
- Idempotency: every write has an `Idempotency-Key` header derived from `(operation, owner_userid, payload_hash)`.
- Timeout: 5s connect, 30s read.
- Logging: every call logged with `endpoint`, `latency_ms`, `status`, `attempt_number`.

**Webhook secret rotation (dual-secret window):** `credential_service` stores `webhook_secret_current` and `webhook_secret_previous` with timestamps. `verifier` accepts either for 30 minutes after a rotation; scheduled task purges previous after window.

---

## 9. Critical flows (the system-wide view)

### 9.1 Upload (file path)

```
Teacher (browser)
  → mod_form or TinyMCE picker
  → POST /local/fastpix/upload_session.php (sesskey + capability mod/fastpix:uploadmedia)
  → \local_fastpix\service\upload_service::create_session()
  → \local_fastpix\api\gateway->input_video_direct_upload()
  → returns signed URL
  → INSERT local_fastpix_upload_session row (state=pending, expires_at=now+24h)
  → return signed URL to browser
Browser PUTs chunks DIRECTLY to FastPix (Moodle web tier never sees bytes)
FastPix → POST /local/fastpix/webhook.php with media.created, media.ready, etc.
```

### 9.2 Webhook ingestion

```
FastPix → POST /local/fastpix/webhook.php
  → verify HMAC-SHA256 on raw body using current OR previous secret (30min window)
  → check timestamp ±5min
  → check per-IP rate limit (500/min, MUC token bucket, fail-open)
  → check body ≤ 1MB
  → INSERT local_fastpix_webhook_event ON CONFLICT DO NOTHING (UNIQUE provider_event_id)
  → enqueue \local_fastpix\task\process_webhook adhoc task
  → return 200 OK (target p99 ≤ 500ms)

Cron picks up adhoc task:
  → \local_fastpix\webhook\projector::project()
  → check event_created_at >= asset.last_event_at (ordering guard)
  → switch on event_type:
       media.ready → UPDATE asset SET status='ready', duration=...
       media.failed → UPDATE asset SET status='failed'
       track.ready → INSERT local_fastpix_track
       ... etc
  → emit \local_fastpix\event\asset_ready or relevant event
```

### 9.3 Playback (DRM)

```
Student (browser)
  → GET /mod/fastpix/view.php?id=42
  → require_login, require_capability('mod/fastpix:view')
  → \local_fastpix\service\playback_service::resolve($asset)
       → MUC lookup for asset metadata (60s TTL); fallback to DB
       → if drm_required: gateway->playback_create_token() → JWT
       → build session_token = HMAC(user_id || activity_id || now, session_secret)
       → INSERT or UPDATE fastpix_attempt with session_token, session_start
  → render player.mustache:
       <fastpix-player playback-id="..." playback-token="..." drm-token="...">
       <div class="fastpix-watermark">Name + Email + drift animation</div>
  → AMD module mod_fastpix/watch_tracker attaches:
       timeupdate → POST every 10s to mod_fastpix_record_view_progress
       seeked → increment client seek_count
       error encrypted → POST refresh_drm_token
```

### 9.4 Watch tracking + completion

```
AMD watch_tracker
  → every 10s: POST mod_fastpix_record_view_progress
       { activity_id, watched_seconds, session_token, sesskey }

Server (\mod_fastpix\service\watch_tracker_service):
  → require_capability('mod/fastpix:view')
  → require_sesskey
  → validate session_token against fastpix_attempt
  → run 5 fraud checks:
      1. watched_seconds ≤ asset.duration
      2. watched_seconds ≤ (now - session_start + 30s)
      3. watched_seconds ≥ attempt.watched_seconds (monotonic)
      4. (watched_seconds - last_watched_seconds) ≤ (now - last_callback_at + 30s)
      5. require_capability still holds
  → on fraud: increment fraud_count, log fraud.detected event, return 200 (don't tell client)
  → on success: UPDATE fastpix_attempt SET watched_seconds, last_callback_at
  → if watched_seconds / asset.duration ≥ activity.completion_watch_percent:
       → \core_completion\activity_custom_completion::update_state()
       → grade_update('mod_fastpix', courseid, 'mod', 'fastpix', instance, userid, grade)
       → emit \mod_fastpix\event\completion_recorded
```

### 9.5 Embed in rich text (filter)

```
Teacher in TinyMCE editor:
  → clicks "Insert FastPix Video" button (tiny_fastpix)
  → modal opens, calls tiny_fastpix_get_my_videos web service
  → list of teacher's uploaded ready assets
  → teacher picks one
  → modal inserts {fastpix:pb_<playback_id>} at cursor

Later, any user views the post:
  → Moodle calls every enabled filter on the rendered text
  → filter_fastpix matches /\{fastpix:pb_([a-zA-Z0-9_-]+)( [^}]*)?\}/
  → require_capability('mod/fastpix:view', $rendering_context)
       → if not held: return literal shortcode as escaped text (T6 mitigation)
  → \local_fastpix\service\playback_service::resolve()
  → emit <fastpix-player> + watermark
```

---

## 10. The five system-wide invariants

These are the properties the entire system depends on. If any test violates one, the test fails — the system is wrong, not the test.

1. **Webhook idempotency.** A duplicate webhook delivery (same `provider_event_id`) is a no-op. UNIQUE constraint + ON CONFLICT DO NOTHING in `local_fastpix_webhook_event`.
2. **Webhook ordering.** An out-of-order webhook (older `event_created_at` than asset's `last_event_at`) is dropped, not applied. Ordering guard in `projector`.
3. **Watch progress monotonicity.** `fastpix_attempt.watched_seconds` only ever increases. Fraud check #3.
4. **DRM tokens are never persisted.** Minted at view-time, passed to player, refreshed via short-lived endpoint. Persisting them is a security regression.
5. **Capability is checked on every privileged path.** Logged-in user ≠ authorized user. Defense-in-depth: even the filter checks capability before rendering.

---

## 11. Failure modes the system handles

Cross-plugin failure handling. Each plugin's architecture file has its own specific failure list; this is the system view.

| Failure | Detection | Handling | Blast radius |
|---|---|---|---|
| FastPix `createToken` 5xx | gateway exception | retry 3x, then error UI; no fallback to non-DRM | Single playback |
| FastPix sustained outage | circuit breaker opens | non-DRM: serve from MUC HLS cache; DRM: degraded banner sitewide | New playbacks fail |
| Webhook HMAC mismatch | verifier returns false | metric increment, HTTP 401, do NOT log payload | None (forensic only) |
| Webhook out-of-order | ordering guard | skip projection, structured warn log | None |
| Webhook flood | per-IP rate limit | 429 before HMAC verify | Bounded to offending IP |
| MUC unreachable | cache exception | fail-open (per ADR-006), DB fallback | Latency hit only |
| GDPR delete fails | gateway exception in privacy provider | local delete still completes; retry task hourly for 24h; alert if 6 consecutive fails | Compliance risk |
| DB read-only | DML write throws | webhook returns 5xx (FastPix retries); user actions show maintenance | Site-wide |
| Cross-account restore | asset lookup returns null | "Video unavailable" UI per ADR-010 | Per-activity |

---

## 12. The Moodle APIs we use (system-wide checklist)

Each plugin's architecture file specifies which of these it uses. This is the consolidated list.

| Moodle API | Purpose | Used by |
|---|---|---|
| DML (`$DB`) | All DB reads/writes | All four |
| DDL / XMLDB | Schema definition | local, mod |
| Access API (`db/access.php`, `require_capability`) | Capabilities | All four |
| Form API (`moodleform`, `moodleform_mod`) | Admin settings, mod_form, picker form | local, mod, tiny |
| Output API + Mustache | Templating | All four |
| String API (`get_string`, AMOS) | i18n | All four |
| Web Services / External Functions (`db/services.php`, `\external_api`) | AMD-callable endpoints | local, mod, tiny |
| Events API (`\core\event\base`) | Cross-plugin event emission | local, mod |
| Task API (`db/tasks.php`, scheduled + adhoc) | Cron + queued jobs | local |
| Completion API (`\core_completion\activity_custom_completion`) | Custom completion rule | mod |
| Gradebook API (`grade_update`) | Push grade to gradebook | mod |
| MUC (`db/caches.php`, `cache::make`) | Caching | local |
| Privacy API (`core_privacy\local\metadata\provider`, `core_privacy\local\request\plugin\provider`) | GDPR | local, mod |
| Backup API (`backup/moodle2/`) | Course backup/restore | mod |
| Filter API (`moodle_text_filter`) | Shortcode rendering | filter |
| TinyMCE Plugin API 4.1+ | Editor button | tiny |
| Admin Settings API (`admin_setting_*`, `passwordunmask`) | Credentials UI | local |

---

## 13. Cross-plugin contracts

These are the PHP namespaces that surface plugins (mod/filter/tiny) call into `local_fastpix`. Treat them as a stable API; breaking changes require a major version bump.

```php
// Asset lookup (used by mod, filter)
\local_fastpix\service\asset_service::get_by_fastpix_id(string $fastpix_id): ?\stdClass
\local_fastpix\service\asset_service::get_by_id(int $id): ?\stdClass
\local_fastpix\service\asset_service::list_for_owner(int $userid, ?string $status='ready'): array

// Playback resolution (used by mod, filter)
\local_fastpix\service\playback_service::resolve(\stdClass $asset, int $userid, \context $context): \local_fastpix\dto\playback_payload
// Returns: { playback_id, playback_token (JWT), drm_token (JWT|null), drm_required, has_captions }

// Watermark text generation (used by mod, filter)
\local_fastpix\service\watermark_service::build_for_user(int $userid): string
// Returns escaped HTML for the watermark <div>

// Gateway access (used by mod for token refresh, never anyone else)
\local_fastpix\api\gateway::instance(): \local_fastpix\api\gateway
$gateway->playback_create_token(string $playback_id, string $user_hash, int $ttl): string

// Asset event subscription (used by anyone interested in asset state changes)
// Observer pattern via Moodle Events API:
// observe \local_fastpix\event\asset_ready in your db/events.php
```

Anything not on this list is internal to `local_fastpix` and may change without notice.

---

## 14. Testing strategy (system-wide)

| Layer | Tool | Coverage gate | Lives in |
|---|---|---|---|
| Unit (services) | PHPUnit | 85% lines | `tests/<feature>_test.php` per plugin |
| Unit (security-critical: verifier, watch_tracker) | PHPUnit | 90% lines | `tests/` |
| Integration (webhook ordering, replay, duplicates) | PHPUnit + fixtures | 100% transitions | `tests/integration/` |
| End-to-end (user flows) | Behat | All flows in §9 happy + critical sad | `tests/behat/` |
| Load | k6 | 200 QPS sustained 10min, 500 QPS burst | `tests/load/` |
| Chaos | Custom harness against FastPix mock | Each external dep outage → designed degraded mode | `tests/chaos/` |
| Static analysis | phpcs (Moodle ruleset), phpmd, mustache lint | Zero violations | CI |
| Plugins Directory check | `moodle-plugin-ci` full pipeline | All steps green | CI |

**Required Behat scenarios (across all four plugins):**
1. Teacher uploads file → webhook ready → student watches 80% → completion + grade.
2. Teacher pastes URL → webhook ready → student plays.
3. Teacher uploads → no-skip enforced → seek attempt logs fraud.
4. Teacher uses TinyMCE picker → embeds in forum post → student views forum, sees player.
5. Student in course A cannot see embedded video for course B asset (T6 mitigation).
6. GDPR full export round-trips for a user with attempts and embedded shortcodes.
7. Course backup → restore in same workspace → activity plays.
8. Course backup → restore in different workspace → "Video unavailable" UI.

---

## 15. Plugins Directory submission requirements

Every plugin must, before submission:

- Pass `moodle-plugin-ci` on the full PHP × Moodle × DB matrix.
- Have a privacy provider (any plugin with PII or any plugin in a system that has PII).
- Have a `README.md` covering: install, admin setup, dependencies on the other three plugins, browser support, FastPix account requirement.
- Have a `CHANGELOG.md` with semver versions and breaking-change flags.
- Have all visible strings in `lang/en/<plugin>.php`.
- Have `version.php` with correct `version`, `requires` (Moodle version), `maturity` (`MATURITY_BETA` for first submission, `MATURITY_STABLE` after pilot).
- Have no Composer dependencies (vendor any external code into `classes/vendor/` if absolutely needed; prefer rewriting).
- Have committed `amd/build/` produced from a deterministic build (Grunt or rollup; document the build command).
- Have `setType()` on every `mod_form` field.
- Reference the other three plugins by `requires` in `version.php` where applicable.

The four plugins are submitted as four separate listings. Each listing's "Source URL" points at the same monorepo; this is acceptable and common.

---

## 16. How to use this document set

If you are an LLM about to implement one of the plugins:

1. Read this file (00-system-overview.md) first. You now know the system shape, conventions, contracts, and rules.
2. Read the architecture file for the plugin you are building (01, 02, 03, or 04).
3. Implement file by file, in the order specified in that plugin's "Build order" section.
4. After every file, run `moodle-plugin-ci` locally and ensure no regressions.
5. Write the test before the next file when working on security-critical code (gateway, verifier, watch_tracker_service, filter capability check).

If you find a contradiction between this file and a plugin file, this file is authoritative — flag the contradiction in your response and resolve it before writing code. If you find a gap in either file, name the gap explicitly and propose a resolution rather than guessing silently.
