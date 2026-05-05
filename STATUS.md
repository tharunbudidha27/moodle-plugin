# Status: v0.1.1 alpha

This plugin is **not production-ready** but Tier 0 install-blockers are resolved.
It is the first plugin (`local_fastpix`) of a planned 4-plugin FastPix × Moodle integration.

## What works
- Phases 1-7 complete: foundation, gateway+JWT, asset system, upload system, webhook system, scheduled tasks, final wiring
- 120 unit tests / 311 assertions passing
- Layer 2 integration verified against real FastPix sandbox: file upload, URL pull, dedup, SSRF guards, webhook endpoint reachability
- **Tier 0 (install-blockers) complete** as of 2026-05-05:
  - Plugin installs cleanly on a fresh Moodle, no `debugging()` warnings
  - All 3 webservices register: `local_fastpix_create_upload_session`, `local_fastpix_create_url_pull_session`, `local_fastpix_get_upload_status`
  - All 5 scheduled tasks register, including the previously-missing `retry_gdpr_delete`
  - Lang file complete (no `[[lang_key]]` placeholders in admin UI)
  - Version is a literal int per Moodle M5
  - Settings page renders, privacy provider visible

## What does not work / is not yet verified
See `docs/review/REVIEW-2026-05-04.md` for the senior-engineer review. Outstanding items:

### Tier 1 — correctness bugs (next)
- CRC32 cache key collisions (review §S-1, critical)
- IPv6 SSRF bypass (review §S-2, high)
- Race in `credential_service::ensure_signing_key` (review §4)
- Race in `upload_service::owner_hash` salt bootstrap (review §4)
- Webhook ledger insert + task enqueue not atomic (review §S-3)

### Tier 2 — documentation & ergonomics
- README.md (must include plaintext-secret-at-rest disclosure)
- Gateway exception messages discard FastPix response body (cost ~30min debugging on 2026-05-04)
- Webhook secret length validation
- `setting_apisecret_desc` lang string falsely claims "Stored encrypted at rest" — must be corrected

### Tier 3 — verification & CI
- Moodle plugin checker not yet run
- Real-FastPix-to-endpoint webhook delivery not yet end-to-end tested
- 30-checkbox Definition of Done not walked

## Architectural decisions
- ADR-012 (`docs/adr/ADR-012-capability-ownership.md`): `mod/fastpix:uploadmedia` stays in `mod_fastpix`. Empirical install test on 2026-05-05 disproved the senior reviewer's predicted failure.

## Do not use this in production.

Tracking work in `TODO.md` (Tier 1 next).
