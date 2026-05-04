# Security Rules (S1–S10)

These rules govern *how the plugin handles secrets, signatures, and user data*. Cited by `@security-compliance`, `@jwt-signing`, `@webhook-processing`, and `@pr-reviewer`.

---

## S1 — JWT signed locally with RSA private key (RS256)

**Rule.** Playback authorization JWTs use `RS256` only. No `HS256` or any symmetric algorithm. No remote token-mint endpoint (FastPix doesn't have one — see ADR-002 corrected).

**Enforcement.** Code review; test fixture asserts `alg=RS256` in every signed JWT header. CI script `.claude/ci-checks/grep-no-hs256.sh` — `grep -rE "'HS256'|\"HS256\"" local/fastpix/` returns zero matches.

**Failure routing.** `@jwt-signing`.

---

## S2 — No secret leakage in logs

**Rule.** `apikey`, `apisecret`, JWTs, signatures, signing private keys, raw user IDs, emails, IPs MUST NOT appear in any log line — including error paths, debug output, and stack traces.

**Enforcement.** PHPUnit redaction-canary tests run every "noisy" path (gateway calls, verifier, projector, jwt_signing_service) and assert the captured log buffer matches none of:
- `/eyJ[A-Za-z0-9_-]{10,}/` (JWT pattern)
- The `apikey` / `apisecret` config values
- Any signature header value used in the test
- Email / IP regexes

**Failure routing.** `@security-compliance` (for the redaction filter); the responsible agent for the offending log call.

---

## S3 — HMAC verification with `hash_equals`, never `===`

**Rule.** All signature comparisons use `hash_equals($expected, $provided)`. Never `===`, never `==`, never `strcmp`. Constant-time comparison is mandatory.

**Enforcement.** CI script `.claude/ci-checks/grep-no-strict-equals-on-signature.sh` — `grep -rE '(===|==).*[Ss]ig(nature)?|[Ss]ig(nature)?.*(===|==)' local/fastpix/classes/` returns zero matches.

**Failure routing.** `@security-compliance` or `@webhook-processing`.

---

## S4 — Every state-changing endpoint has `require_sesskey()`

**Rule.** POST / PUT / DELETE endpoints, including `external_api` write functions, call `require_sesskey()`. The webhook endpoint is the documented exception (HMAC-authenticated).

**Enforcement.** PR review; static check that every file under `classes/external/` declared as `'type' => 'write'` in `db/services.php` either calls `require_sesskey()` directly or is invoked through the framework's auto-sesskey flow.

**Failure routing.** `@security-compliance`.

---

## S5 — Every privileged endpoint has `require_capability($cap, $context)`

**Rule.** Any endpoint that reveals or mutates user-specific or admin-specific data calls `require_capability` with the appropriate capability string and context.

**Enforcement.** PR review; capability table reference in §7 of system overview.

**Failure routing.** `@security-compliance`.

---

## S6 — SSRF allow-list on URL pull

**Rule.** `upload_service::create_url_pull_session()` validates the source URL BEFORE invoking the gateway. Reject:
- Schemes other than `https`.
- Hosts that resolve to: loopback (`127.0.0.0/8`), RFC1918 (`10/8`, `172.16/12`, `192.168/16`), link-local (`169.254/16`, includes AWS metadata `169.254.169.254`), unspecified (`0.0.0.0`).
- Domains: `localhost`, `*.local`.

**Enforcement.** `upload_service_test.php` includes one rejection case per attack class.

**Failure routing.** `@upload-service` or `@security-compliance`.

---

## S7 — Dual-secret webhook rotation, 30-min window

**Rule.** Verifier accepts current secret OR previous secret if `(time() - rotated_at) < 1800`. After 30 min, only current.

**Enforcement.** `verifier_test.php` boundary cases at 29m59s (accepted) and 30m1s (rejected).

**Failure routing.** `@webhook-processing`.

---

## S8 — Credentials stored via `passwordunmask`

**Rule.** All admin credentials use Moodle's `admin_setting_configpasswordunmask`. Documented in `README.md` as plaintext in `mdl_config_plugins` — NOT encrypted at rest. The UI hides the value; the storage does not.

**Enforcement.** PR review.

**Failure routing.** `@security-compliance`.

---

## S9 — `user_hash = HMAC(userid, salt)`, never raw `userid`

**Rule.** Logged user references use `user_hash = hash_hmac('sha256', $userid, get_config('local_fastpix', 'user_hash_salt'))`. Raw `userid` never appears in any log line. The salt is auto-generated on first install (`random_string(64)`).

**Enforcement.** Logging helper enforces; redaction canary asserts.

**Failure routing.** `@security-compliance`.

---

## S10 — No JWT caching

**Rule.** Each playback start gets a fresh sign. Never cache JWTs in MUC, in static class properties, or in DB. The cost of caching exceeds the benefit (signing is 1–5ms; cache lookups are not free).

**Enforcement.** Code review; agent guardrail in `@jwt-signing`.

**Failure routing.** `@jwt-signing`.
