# Skill 03 — Vendor `firebase/php-jwt`

**Owner agent:** `@security-compliance` (audit) + `@jwt-signing` (consumer).

**When to invoke:** Phase 2, step 1. Re-invoke for every version upgrade (rare — pin and leave).

---

## Inputs

| Param | Required | Default | Notes |
|---|---|---|---|
| `version` | yes | `v6.10.0` | MUST be a v6.x release. |
| `source` | yes | `https://github.com/firebase/php-jwt/releases` | Tarball download. |

## Outputs

- `local/fastpix/classes/vendor/php-jwt/JWT.php`
- `local/fastpix/classes/vendor/php-jwt/Key.php`
- `local/fastpix/classes/vendor/php-jwt/JWK.php`
- `local/fastpix/classes/vendor/php-jwt/BeforeValidException.php`
- `local/fastpix/classes/vendor/php-jwt/ExpiredException.php`
- `local/fastpix/classes/vendor/php-jwt/SignatureInvalidException.php`
- `local/fastpix/classes/vendor/php-jwt/VENDOR.md` — audit trail.

## Steps

1. Download release tarball for the pinned version from `github.com/firebase/php-jwt`.
2. Verify tarball SHA256 against the GitHub release notes.
3. Extract; copy ONLY the six PHP source files (no tests, no examples, no `composer.json`) into `classes/vendor/php-jwt/`.
4. Compute SHA256 of each extracted file.
5. Write `VENDOR.md`:
   ```markdown
   # firebase/php-jwt vendored

   - Version: v6.10.0
   - Source: https://github.com/firebase/php-jwt/releases/tag/v6.10.0
   - License: MIT
   - Vendored on: 2026-05-04 by @security-compliance
   - Consumed by: \local_fastpix\service\jwt_signing_service

   ## File SHA256

   - JWT.php: 9a2f...
   - Key.php: c1d8...
   - JWK.php: 3e44...
   - BeforeValidException.php: a911...
   - ExpiredException.php: 7c02...
   - SignatureInvalidException.php: bb4d...

   ## Re-vendoring

   1. Download the new release tarball.
   2. Compare diffs against this version.
   3. Update SHA256s here.
   4. Run JWT signing roundtrip test.
   5. Run redaction canary test.
   ```
6. Configure `phpcs.xml` to exclude `classes/vendor/` from the Moodle ruleset (vendored code is exempt).
7. Run smoke test:
   ```php
   require_once $CFG->dirroot . '/local/fastpix/classes/vendor/php-jwt/JWT.php';
   require_once $CFG->dirroot . '/local/fastpix/classes/vendor/php-jwt/Key.php';
   $jwt = \Firebase\JWT\JWT::encode(['test' => 1], 'secret', 'HS256');
   ```
   (HS256 here is the smoke-test default in php-jwt examples; production uses RS256.)
8. Document the vendoring in `README.md` so reviewers can re-verify.

## Constraints

- **NO Composer dependencies.** Moodle Plugins Directory disallows them at runtime.
- **Vendored code MUST stay unmodified.** No Moodle-style edits.
- **License (MIT) MUST be preserved** in the vendored files.
- **Re-vendoring on upgrade is gated** by `@security-compliance` audit + fresh SHA256 attestation.
- **Auto-loading via Moodle's class loader works** because Moodle scans `classes/` recursively. The namespace `Firebase\JWT\` resolves to `classes/vendor/php-jwt/`.

## Verification

- [ ] `Firebase\JWT\JWT::encode(...)` resolves and produces a 3-segment JWT.
- [ ] All six PHP files present.
- [ ] `VENDOR.md` SHA256s match a fresh hash.
- [ ] `phpcs` doesn't fail on the vendor folder (exclusion configured).
- [ ] `composer.json` does NOT exist anywhere in `local/fastpix/`.
