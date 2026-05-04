# WORKFLOW — local_fastpix Build Plan

This is the 7-phase execution plan that drives the plugin from empty repo to GA in roughly four weeks. Each phase names the agents that run, the skills they invoke, the artifacts they emit, and a hard validation checklist that **gates** entry into the next phase. The phase-to-week mapping matches §17 of `01-local-fastpix.md`.

A phase is not "done" until every checkbox is ticked. If a checkbox can't be ticked, the agent that owns the failing concern is the one to escalate to — see `agents/` for ownership.

---

## Phase 1 — Foundation (Week 1)

**Goal:** Plugin installs cleanly with empty schema, capability, and feature flags.

| Agent | Skill(s) | Output artifacts |
|---|---|---|
| `@backend-architect` | — | Approval to start; ADR for any open question |
| `@security-compliance` | Skill 1, Skill 2 | `version.php`, `lib.php` (auto-secrets), `lang/en/local_fastpix.php`, `db/install.xml`, `db/upgrade.php`, `db/access.php`, `db/caches.php`, `db/services.php` (skeleton), `db/tasks.php` (skeleton), `db/events.php` |
| `@asset-service` | — (review) | Confirms schema matches §4 |
| `@testing` | Skill 15 | `tests/feature_flag_service_test.php` |
| (then) `@backend-architect` | Skill 11 | `classes/service/feature_flag_service.php` — small enough to fit in foundation phase |

**Validation checklist (gate to Phase 2):**

- [ ] `moodle-plugin-ci install` passes on PHP 8.2/8.3/8.4 × Moodle 4.5/5.0 × MySQL/MariaDB/Postgres.
- [ ] All 5 tables created with all indexes (verify via `xmldb-editor`).
- [ ] Capability `local/fastpix:configurecredentials` registered; assignable to Manager.
- [ ] All four MUC cache definitions resolvable via `cache::make('local_fastpix', $area)`.
- [ ] Settings page renders (with empty values); admin can save credentials.
- [ ] First-install hook auto-generates `session_secret` and `user_hash_salt` (assert non-empty after install).
- [ ] Feature flags default to enabled; round-trip via `get_config`.
- [ ] `feature_flag_service` 85%+ coverage.
- [ ] Plugin uninstalls cleanly with zero orphan tables (`mdl_local_fastpix_*` count = 0 after uninstall).

---

## Phase 2 — Gateway, JWT Signing, Credential Bootstrap (Week 2)

**Goal:** Plugin can authenticate to FastPix, bootstrap a signing key, and sign JWTs locally — all under the gateway's resilience policies.

| Agent | Skill(s) | Output artifacts |
|---|---|---|
| `@security-compliance` | (review) | Sign-off on credential storage pattern (`passwordunmask`) |
| `@gateway-integration` | Skill 3 (vendor php-jwt), Skill 4 (gateway) | `classes/vendor/php-jwt/`, `VENDOR.md`, all 10 typed exceptions in `classes/exception/`, `classes/api/gateway.php` |
| `@jwt-signing` | Skill 5 | `classes/service/jwt_signing_service.php` |
| `@security-compliance` | — | `classes/service/credential_service.php` (auto-bootstrap signing key) |
| `@testing` | Skill 15 + Skill 8 prompt | `tests/gateway_test.php` (95%), `tests/jwt_signing_service_test.php` (95%), `tests/credential_service_test.php` (90%) |

**Validation checklist (gate to Phase 3):**

- [ ] `gateway::health_probe()` returns true against the FastPix mock.
- [ ] `gateway::create_signing_key()` returns valid `{id, privateKey, createdAt}`.
- [ ] `gateway::get_media('nonexistent')` throws `gateway_not_found` immediately (no retry).
- [ ] `gateway::delete_media('nonexistent')` returns silently (no exception).
- [ ] **Hot-path timeout:** a 5-second-responding mock endpoint causes `get_media` to fail at 3s but `input_video_direct_upload` succeeds.
- [ ] Circuit breaker opens after 5 consecutive 5xx, half-opens after 30s, closes on success probe — verified via two simulated FPM workers sharing MUC.
- [ ] Idempotency-Key derivation deterministic for same inputs; differs for different payloads.
- [ ] **Log redaction canary** — gateway log buffer never contains apikey, apisecret, JWT-shaped strings, or signatures (run grep over a 100-call buffer).
- [ ] JWT roundtrip: sign with private key → decode with public-key fixture → payload claims match (`kid`, `aud=media:<pb>`, `iss`, `iat`, `exp`).
- [ ] Missing/invalid signing key throws `signing_key_missing` with the right reason.
- [ ] CI guard: zero matches for `fastpix.io` outside `classes/api/`.
- [ ] Coverage gates met (gateway 95%, jwt_signing 95%, credential 90%).

---

## Phase 3 — Asset System (Week 3, first half — overlaps with Phase 4)

**Goal:** Asset reads work with cache + lazy fetch; cross-plugin contracts (`get_by_*`) are stable.

| Agent | Skill(s) | Output artifacts |
|---|---|---|
| `@asset-service` | Skill 8 | `classes/dto/playback_payload.php`, `classes/service/asset_service.php` |
| `@testing` | Skill 15 | `tests/asset_service_test.php` (85%, 90% on lazy-fetch paths) |

**Validation checklist (gate to Phase 4):**

- [ ] `get_by_fastpix_id` cache hit / miss / soft-deleted-filter all pass.
- [ ] `get_by_playback_id` same matrix passes.
- [ ] **Cold-start lazy fetch:** `get_by_fastpix_id_or_fetch` for an unknown ID issues exactly one `gateway::get_media` call, INSERTs with `owner_userid=0`, populates both cache keys.
- [ ] **Race:** two concurrent first-views → only one INSERT row; second call catches `dml_write_exception` and re-reads.
- [ ] FastPix 404 → `asset_not_found` (not `gateway_not_found`).
- [ ] `soft_delete` sets `deleted_at` and invalidates BOTH cache keys.
- [ ] `list_for_owner` excludes soft-deleted by default.
- [ ] CI guard: `_or_fetch` not used in `classes/webhook/`, `classes/task/`, `classes/privacy/`.

---

## Phase 4 — Upload System (Week 3, second half)

**Goal:** Teachers can initiate uploads (file path + URL pull) with deduplication and SSRF protection.

| Agent | Skill(s) | Output artifacts |
|---|---|---|
| `@upload-service` | Skill 9 | `classes/service/upload_service.php` |
| `@security-compliance` | (review) | SSRF allow-list audit |
| (UI/endpoint) | — | `classes/external/create_upload_session.php`, `classes/external/create_url_pull_session.php`, `classes/external/get_upload_status.php`, `upload_session.php` |
| `@testing` | Skill 15 | `tests/upload_service_test.php` (85%), Behat scenario draft |

**Validation checklist (gate to Phase 5):**

- [ ] **Dedup boundary:** same (userid, filename, size) within 59s → same `session_id`, `deduped=true`. After 61s → new session.
- [ ] **SSRF:** every test class rejected (localhost, RFC1918, link-local, AWS metadata, DNS-rebinding). Non-https rejected.
- [ ] **DRM activation gate:** `drm_required=true` with `drm_configuration_id=''` → `drm_not_configured` thrown.
- [ ] DRM payload includes `drmConfigurationId`; private payload does not.
- [ ] External functions enforce `mod/fastpix:uploadmedia` capability + `require_sesskey` (assert via integration test).
- [ ] Upload session row contains `upload_id` (transient FastPix ID), not `fastpix_id` (Media ID arrives later via webhook).

---

## Phase 5 — Webhook System (Week 3 → Week 4 transition)

**Goal:** Webhooks land idempotently, project asynchronously, survive concurrency, and tolerate dual-secret rotation.

| Agent | Skill(s) | Output artifacts |
|---|---|---|
| `@webhook-processing` | Skill 6, Skill 7 | `classes/webhook/verifier.php`, `classes/webhook/projector.php`, `classes/webhook/event_dispatcher.php`, `classes/task/process_webhook.php`, `webhook.php` |
| `@security-compliance` | — | `classes/service/rate_limiter_service.php` |
| `@testing` | Skill 15 | `tests/verifier_test.php` (90%), `tests/projector_test.php` (90%), `tests/integration/webhook_flood_test.php`, `tests/integration/secret_rotation_test.php`, `tests/integration/lock_contention_test.php` |

**Validation checklist (gate to Phase 6):**

- [ ] **Webhook flood:** 1000 events, 50% duplicates, 10% out-of-order → zero corruption; ledger has 1000 distinct `provider_event_id` rows; asset table is correct.
- [ ] **Single FastPix-Signature header** verified; no timestamp dependency.
- [ ] **Dual-secret rotation:** previous secret accepted within 30 min of `rotated_at`; rejected after.
- [ ] **`hash_equals` only** — grep CI shows zero `===` near "signature".
- [ ] **Asset key** extracted from `event.object.id` — fixture proving `event.data.id` would be wrong.
- [ ] **Per-asset lock contention:** two parallel projections of the same asset (event_at=110 vs 105) → final state is event_at=110 winner. No `media.failed` overwriting `media.ready`.
- [ ] **Lock release on exception:** `finally` proven to release.
- [ ] **Lock acquisition timeout:** 5s timeout throws `lock_acquisition_failed`; ledger NOT marked projected; adhoc retries.
- [ ] **Total-ordering tiebreak:** equal timestamps → lex-larger `provider_event_id` wins; same `provider_event_id` as `last_event_id` dropped.
- [ ] **Cache invalidation inside lock:** verified by interleaving a reader between project and end of project.
- [ ] **Circuit breaker** state shared across two simulated FPM workers via MUC.

---

## Phase 6 — Tasks & Cleanup (Week 4, first half)

**Goal:** Background hygiene runs idempotently, batched, time-boxed, observably.

| Agent | Skill(s) | Output artifacts |
|---|---|---|
| `@tasks-cleanup` | Skill 10 | `classes/task/orphan_sweeper.php`, `classes/task/prune_webhook_ledger.php`, `classes/task/purge_soft_deleted_assets.php`, `classes/task/retry_gdpr_delete.php`, registration in `db/tasks.php` |
| `@security-compliance` | Skill 12 | `classes/privacy/provider.php` |
| `@testing` | Skill 15 | `tests/<task>_test.php` for all four tasks; `tests/privacy_provider_test.php` (90%) |

**Validation checklist (gate to Phase 7):**

- [ ] All four tasks runnable via `php admin/cli/scheduled_task.php`.
- [ ] **Soft-deleted boundary:** 6d23h NOT purged, 7d1m IS purged; cascade to track table verified.
- [ ] **GDPR retry:** per-asset DELETE; `gdpr_delete_pending_at` set on failure; retry task picks up; clears on success.
- [ ] **Alert event** fires after 6 consecutive failures per asset.
- [ ] **Privacy export** round-trips for synthetic user with 3 assets and 2 upload sessions.
- [ ] **Privacy delete** for a user soft-deletes assets locally and queues per-asset FastPix delete.
- [ ] Tasks log `event=task.<name>`, `rows_scanned`, `rows_mutated`, `latency_ms` per batch.
- [ ] No task runs longer than 60s wall-clock per cron tick.

---

## Phase 7 — Hardening (Week 4, second half)

**Goal:** Feature flags, health endpoint, structured-log canary, degraded-banner UX, README, CHANGELOG.

| Agent | Skill(s) | Output artifacts |
|---|---|---|
| `@security-compliance` | Skill 13 | `classes/service/health_service.php`, `health.php` |
| `@backend-architect` | Skill 14 | Logging helper finalized + redaction canary integrated into CI |
| (UI) | — | `classes/output/degraded_banner_renderer.php`, mustache template |
| `@testing` | Skill 15 | `tests/health_service_test.php`, `tests/integration/redaction_canary_test.php`, `tests/behat/admin_setup.feature`, `tests/behat/upload_lifecycle.feature` |
| (docs) | — | `README.md` (with vendoring instructions, FastPix dependency), `CHANGELOG.md` (1.0.0 entry) |

**Validation checklist (Definition of Done — all 30 boxes from §19 of the architecture doc):**

Foundation:
- [ ] `moodle-plugin-ci` green on full PHP × Moodle × DB matrix.
- [ ] PHPUnit coverage: gateway 95%, verifier 90%, projector 90%, jwt_signing 95%, all other services 85%.
- [ ] Plugin installs/uninstalls cleanly; zero orphan tables.
- [ ] No raw `mysqli_*`, no `===` on signatures, all strings in lang file.
- [ ] All capability checks present on every privileged endpoint (webhook excepted).
- [ ] `firebase/php-jwt` vendored, version pinned in README.

Webhook ingestion:
- [ ] Flood test (1000 events, 50% dupes, 10% OOO) zero corruption.
- [ ] Dual-secret rotation 30-min window verified at boundary.
- [ ] Single `FastPix-Signature` header; no timestamp dependency.
- [ ] Asset key from `event.object.id`.

Production-grade hardening:
- [ ] Lock contention serializes correctly.
- [ ] Total-ordering tiebreak verified.
- [ ] Lock release on exception (finally tested).
- [ ] Lock acquisition timeout throws and re-queues.
- [ ] Hot-path timeout: `get_media` fails at 3s; `input_video_direct_upload` succeeds at 5s on same slow endpoint.
- [ ] Circuit breaker shares state across simulated FPM workers via MUC.

JWT signing:
- [ ] Sign roundtrip with public-key fixture matches.
- [ ] Missing key throws `signing_key_missing`.
- [ ] `aud=media:<playback_id>`.
- [ ] `alg=RS256`.

Lazy fetch:
- [ ] Cold-start playback inserts with sentinel owner=0.
- [ ] Two concurrent first-views: one INSERT, second re-reads.
- [ ] FastPix 404 → `asset_not_found` → "Video unavailable" UI.

Cleanup:
- [ ] Soft-deleted asset > 7d hard-deleted; cascade.
- [ ] Boundary 6d23h vs 7d1m correct.

Feature flags:
- [ ] DRM site-wide off → non-DRM playback even for `drm_required=1`.
- [ ] Watermark off → empty `watermark_html`.
- [ ] DRM flag depends on BOTH checkbox AND `drm_configuration_id`.

Upload dedup + SSRF:
- [ ] 60s dedup boundary correct.
- [ ] SSRF rejects all 5 attack classes.

Privacy:
- [ ] Export round-trip.
- [ ] Per-asset DELETE; on failure `gdpr_delete_pending_at` set; retries within 24h.
- [ ] Alert after 6 consecutive failures.

Health:
- [ ] Returns valid JSON; 503 when FastPix probe fails.

When all 30 boxes are checked, `local_fastpix` is GA-ready and Plugin 2 (`mod_fastpix`) can begin.

---

## How to use this in practice

1. **Open a phase as an issue / milestone.** Copy the validation checklist into the body. Don't merge to `main` until every box is ticked.
2. **Drive each row top to bottom.** The rows are ordered so each agent has its inputs ready.
3. **PR-1..PR-20 still apply.** Even mid-phase commits go through `@pr-reviewer`. The reject list (`rules/pr-rejection.md`) catches drift early.
4. **If you find yourself wanting to skip a checkbox, escalate to `@backend-architect`.** No phase ships partially done; that's how the seven non-negotiables get diluted.
