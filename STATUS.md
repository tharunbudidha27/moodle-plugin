# Status: v0.1.0 pre-alpha

This plugin is **not production-ready**. It is the first plugin (`local_fastpix`) of a planned 4-plugin FastPix × Moodle integration.

## What works
- Phases 1-7 complete: foundation, gateway+JWT, asset system, upload system, webhook system, scheduled tasks, final wiring
- 120 unit tests / 311 assertions passing
- Layer 2 integration verified against real FastPix sandbox: file upload, URL pull, dedup, SSRF guards, webhook endpoint reachability

## What does not work
See `docs/review/REVIEW-2026-05-04.md` for a full senior-engineer review. Summary:
- Install-blockers: missing `classes/external/*`, missing `retry_gdpr_delete` task, missing lang strings, dynamic version.php
- Correctness bugs: CRC32 cache key collisions, IPv6-bypass in SSRF guard
- Untested in production: real FastPix → webhook → projector end-to-end
- Plugin checker has not been run

## Do not use this in production.

Tracking work in `docs/review/REVIEW-2026-05-04.md` Phase 0 + Phase 1.
