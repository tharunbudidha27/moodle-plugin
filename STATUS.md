# Status: v0.2.0 alpha (Tier 0 + Tier 1 complete)

This plugin is **not production-ready**, but Tier 0 install-blockers and Tier 1 correctness bugs from the senior review are all resolved.
It is the first plugin (`local_fastpix`) of a planned 4-plugin FastPix × Moodle integration.

## What works
- Phases 1-7 complete: foundation, gateway+JWT, asset system, upload system, webhook system, scheduled tasks, final wiring
- 132 unit tests / 200,332 assertions passing
- Layer 2 integration verified against real FastPix sandbox: file upload, URL pull, dedup, webhook endpoint reachability
- **Tier 0 (install-blockers) complete** as of 2026-05-05:
  - Plugin installs cleanly on a fresh Moodle, no `debugging()` warnings
  - 3 webservices register: `local_fastpix_create_upload_session`, `local_fastpix_create_url_pull_session`, `local_fastpix_get_upload_status`
  - 5 scheduled tasks register, including the previously-missing `retry_gdpr_delete`
  - Lang file complete (no `[[lang_key]]` placeholders in admin UI)
  - Version is a literal int per Moodle M5
- **Tier 1 (correctness bugs) complete** as of 2026-05-05:
  - T1.1: SHA-256/32 cache keys replace CRC32 in 9 sites across 6 files (REVIEW §S-1, critical — was cross-asset metadata leak at 77K-asset collision threshold)
  - T1.2: 100K-key empirical collision test (200K+ assertions, runs <3s)
  - T1.3: IPv6-aware SSRF guard with byte-pattern checks for ULA, link-local, NAT64, AWS metadata IPv6, and IPv4-mapped IPv6 (REVIEW §S-2, high — was IPv6 bypass via dual-stack)
  - T1.4: `\core\lock` serializes `ensure_signing_key`, double-check pattern (REVIEW §4 — was orphan-key leak under PHP-FPM concurrency)
  - T1.5: `owner_hash` fails loud on missing salt instead of racy in-request bootstrap (REVIEW §4 — was hash divergence between concurrent first-users)
  - T1.6: `start_delegated_transaction` wraps webhook ledger insert + task enqueue (REVIEW §S-3 — was permanently-stuck pending rows on enqueue failure)

## What does not work / is not yet verified
See `docs/review/REVIEW-2026-05-04.md` for the senior-engineer review. Outstanding items:

### Tier 2 — documentation & ergonomics
- README.md (must include plaintext-secret-at-rest disclosure)
- Gateway exception messages discard FastPix response body (cost ~30min debugging on 2026-05-04)
- Webhook secret length validation
- `setting_apisecret_desc` lang string falsely claims "Stored encrypted at rest" — must be corrected
- `db/services.php` description fields are hardcoded English (M9 violation) — convert to lang string keys
- DNS rebinding TOCTOU window between SSRF check and gateway fetch (deferred from T1.3 — needs `CURLOPT_RESOLVE` pinning through `\core\http_client`)

### Tier 3 — verification & CI
- Moodle plugin checker not yet run
- Real-FastPix-to-endpoint webhook delivery not yet end-to-end tested
- 30-checkbox Definition of Done not walked
- Pre-commit grep guard against `hash..crc32b` regression (caught 2 sites in T1.1 that weren't in the review)
- GDPR retry-counter column (`gdpr_delete_attempts`) — schema migration deferred from T0.8

### Tier 4 — refactor / polish
- Consolidate `cache_key_*` helpers (currently duplicated between `asset_service` and `projector`) into `\local_fastpix\util\cache_keys` (REVIEW §3 duplication)
- Standardize structured logging beyond gateway

## Architectural decisions
- ADR-012 (`docs/adr/ADR-012-capability-ownership.md`): `mod/fastpix:uploadmedia` stays in `mod_fastpix`. Empirical install test on 2026-05-05 disproved the senior reviewer's predicted failure.

## Do not use this in production.

Tracking work in `TODO.md` (Tier 2 next).
