# Status: v0.2.0 alpha (Tier 0 + Tier 1 + Tier 2 + Tier 3 landed)

This plugin is **not production-ready**, but every numbered Tier item from
the 2026-05-04 senior review has now landed in some form. Twelve DoD
items remain DEFERRED per the audit in `docs/dod-walk-2026-05-05.md`;
the GA blockers are listed under "Outstanding work" below.

It is the first plugin (`local_fastpix`) of a planned 4-plugin FastPix ×
Moodle integration.

## What works

- Phases 1-7 complete: foundation, gateway + JWT, asset system, upload
  system, webhook system, scheduled tasks, final wiring
- **144 unit tests / 200,370 assertions** passing (was 132/200,332 at
  end of Tier 1; the gain came from T3.1 health checks, T3.4 CRC32
  regression guard, and T3.5 retry-cap boundary tests)
- Layer 2 integration verified against real FastPix sandbox: file upload,
  URL pull, dedup, webhook endpoint reachability
- DoD walk recorded at `docs/dod-walk-2026-05-05.md`: 19 PASS, 4 PARTIAL,
  12 DEFERRED, 0 FAIL across 35 items

### Tier 0 (install-blockers) — landed 2026-05-05

- Plugin installs cleanly on a fresh Moodle, no `debugging()` warnings
- 3 webservices register: `local_fastpix_create_upload_session`,
  `local_fastpix_create_url_pull_session`, `local_fastpix_get_upload_status`
- 5 scheduled tasks register, including the previously-missing
  `retry_gdpr_delete`
- Lang file complete (no `[[lang_key]]` placeholders in admin UI)
- Version is a literal int per Moodle M5

### Tier 1 (correctness bugs) — landed 2026-05-05

- **T1.1**: SHA-256/32 cache keys replace CRC32 in 9 sites across 6 files
  (REVIEW §S-1, critical — was cross-asset metadata leak at 77K-asset
  collision threshold)
- **T1.2**: 100K-key empirical collision test (200K+ assertions, <3s)
- **T1.3**: IPv6-aware SSRF guard with byte-pattern checks for ULA,
  link-local, NAT64, AWS metadata IPv6, and IPv4-mapped IPv6
  (REVIEW §S-2, high — was IPv6 bypass via dual-stack)
- **T1.4**: `\core\lock` serializes `ensure_signing_key`, double-check
  pattern (REVIEW §4 — was orphan-key leak under PHP-FPM concurrency)
- **T1.5**: `owner_hash` fails loud on missing salt instead of racy
  in-request bootstrap (REVIEW §4 — was hash divergence between
  concurrent first-users)
- **T1.6**: `start_delegated_transaction` wraps webhook ledger insert +
  task enqueue (REVIEW §S-3 — was permanently-stuck pending rows on
  enqueue failure)

### Tier 2 (documentation & ergonomics) — landed 2026-05-05

- **T2.1**: `setting_apisecret_desc` lang string corrected — plaintext
  storage disclosure replaces the false "encrypted at rest" claim
- **T2.2**: Gateway exceptions carry up to 500 chars of FastPix response
  body in `$a` context (was discarded; cost ~30 min debugging
  2026-05-04). Body never enters `error_log`.
- **T2.3**: `verifier::verify` rejects configured secrets shorter than
  32 bytes; structured `webhook.secret_too_short` log on rejection.
  Verify-time floor only — no UI rotation field added (avoids
  widening typo blast radius).
- **T2.4**: `db/services.php` description literals — closed as a
  documented no-op. Empirical audit of 23 mod/*/db/services.php files
  in Moodle 4.5 core found zero use of `get_string()` in description.
  Senior review's M9 flag was incorrect on this point.
- **T2.5**: `README.md` written — install order, configuration
  walkthrough, plaintext storage disclosure, FastPix dashboard setup,
  webhook secret rotation guidance (CLI / DB-direct, not UI),
  vendored-dependencies section pinning php-jwt v6.10.0.

### Tier 3 (verification & CI) — landed 2026-05-05

- **T3.1**: production-readiness static checks shipped as
  `tests/plugin_health_test.php` (debug-artefact scan, version.php
  sanity, install.xml validity, db/{services,tasks,hooks}.php class
  resolution). Stand-in for moodle-plugin-ci, which can't be installed
  in the dev container. Full toolchain still queued for CI provisioning.
- **T3.2**: webhook end-to-end loopback CLI at
  `cli/webhook_loopback_test.php` — fires synthetic signed events at
  the local webhook URL and reconciles ledger inserts. Substitute for
  real-FastPix-to-endpoint until sandbox creds + public URL are
  available; supports duplicate fraction so it partially covers
  DoD §7 (webhook flood).
- **T3.3**: 35-item DoD walk in `docs/dod-walk-2026-05-05.md`. Verdicts,
  evidence, remediation queue, two architecture-drift items needing
  ADR/doc resolution (cleanup-task semantics; GDPR alert threshold).
- **T3.4**: PHPUnit-form regression guard in
  `tests/no_crc32_regression_test.php` — fails the suite if any
  `hash('crc32b'`, `hash("crc32b"`, or bare `crc32(` is reintroduced
  in production source.
- **T3.5**: `gdpr_delete_attempts` column on `local_fastpix_asset`,
  10-attempt cap with CRITICAL-line ops signal at the cap. Schema
  bumped 2026050503 → 2026050504 with `db/upgrade.php` step.
  4 boundary tests in `tests/retry_gdpr_delete_test.php`.

### Tier 4 (refactor / polish) — not started

- Consolidate `cache_key_*` helpers (currently duplicated between
  `asset_service` and `projector`) into a shared util namespace
  (REVIEW §3 duplication)
- Standardize structured logging beyond gateway

## Outstanding work (DoD-driven)

The DoD walk identified 8 work units to reach GA. Highest priority:

1. **Install moodle-plugin-ci in CI** (DoD §1, §2). Unblocks coverage
   gating at architecture targets (gateway 95%, verifier 90%, projector
   90%, jwt_signing 95%, others 85%).
2. **Resolve cleanup-task drift** (DoD §24, §25). Architecture says
   7-day soft-purge as a separate task; current code has 90-day GDPR
   retention only. ADR or doc update.
3. **DNS-rebinding TOCTOU mitigation** (DoD §31). `CURLOPT_RESOLVE`
   pinning through `\core\http_client`. Deferred from T1.3.
4. **Health endpoint** at `/local/fastpix/health.php` (DoD §35) —
   wraps `gateway::health_probe()`, returns JSON, 503 on probe failure.

Medium priority:

5. Webhook flood integration test (1000 events, 50% dup, 10% out-of-order)
   (DoD §7) — partially addressed by T3.2 loopback CLI.
6. Two-worker concurrency tests for lock contention and breaker MUC
   sharing (DoD §11, §16).
7. Privacy export round-trip test (DoD §32).
8. Reconcile GDPR alert semantics (DoD §34) — architecture says 6
   failures + Moodle event; T3.5 ships 10-attempt cap + mtrace.

Low priority:

9. Real-endpoint hot-path timeout test (DoD §15).

See `docs/dod-walk-2026-05-05.md` for the full per-item audit and
remediation queue.

## Architectural decisions

- **ADR-012** (`docs/adr/ADR-012-capability-ownership.md`):
  `mod/fastpix:uploadmedia` stays in `mod_fastpix`. Empirical install
  test on 2026-05-05 disproved the senior reviewer's predicted failure.

## Do not use this in production.

The plugin is internally consistent (132+ green tests on every commit;
no FAIL findings in the DoD walk) but the missing surface area
(health endpoint, plugin-checker CI, DNS-rebinding mitigation, cleanup
task semantics) is enough to keep the maturity at alpha.
