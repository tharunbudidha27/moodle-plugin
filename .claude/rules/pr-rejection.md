# PR Rejection Rules (PR-1..PR-20)

These are the **auto-reject** conditions. `@pr-reviewer` rejects the PR — no human discretion, no "we'll fix it next sprint." Each one references the underlying rule it enforces.

When a PR fails any of these, `@pr-reviewer` returns BLOCK with the rule ID and the routing target.

---

| ID | Reject if… | Underlying rule | Route fix to |
|---|---|---|---|
| **PR-1** | Any non-`classes/api/` file contains `fastpix.io` or `api.fastpix`. | A2 | `@gateway-integration` |
| **PR-2** | Any file uses `curl_*`, `file_get_contents('http...')`, raw Guzzle, or PDO/`mysqli_*`. | A2 / M1 / M8 | `@gateway-integration` (HTTP) or `@asset-service` (DB) |
| **PR-3** | Any signature comparison uses `===` or `==` instead of `hash_equals`. | S3 | `@security-compliance` or `@webhook-processing` |
| **PR-4** | Any `external_*` write endpoint omits `require_sesskey()` (webhook excepted). | S4 | `@security-compliance` |
| **PR-5** | Any privileged endpoint omits `require_capability` or `require_login`. | S5 | `@security-compliance` |
| **PR-6** | Any log line includes `apikey`, `apisecret`, JWT-pattern string, signature header value, or raw `userid`. | S2 / S9 | `@security-compliance` |
| **PR-7** | A new gateway method without retry, breaker, idempotency-key (writes), or structured log. | A2 | `@gateway-integration` |
| **PR-8** | A projector change without lock acquisition, total-ordering tiebreak, or finally-release. | W3 / W4 | `@webhook-processing` |
| **PR-9** | An asset write without dual-key cache invalidation. | W5 | `@asset-service` |
| **PR-10** | A new column or table without `db/upgrade.php` step + `version.php` bump. | M5 | `@asset-service` (or whoever changed schema); verified by `@backend-architect` |
| **PR-11** | A new behavior without test coverage. | M6 | `@testing` |
| **PR-12** | Any reference to `mod_fastpix`, `filter_fastpix`, `tiny_fastpix` namespaces / capabilities / tables in `local_fastpix` source (test fixtures excepted). | A4 | `@backend-architect` |
| **PR-13** | A new dependency added via `composer.json`. | M12 | `@security-compliance` |
| **PR-14** | A "createToken" call to FastPix re-introduced. | ADR-002 corrected | `@jwt-signing` |
| **PR-15** | A signing algorithm other than `RS256`. | S1 | `@jwt-signing` |
| **PR-16** | Lazy fetch (`_or_fetch`) used inside the projector or any webhook-task path. | W7 | `@asset-service` |
| **PR-17** | A capability defined other than `local/fastpix:configurecredentials`. | M3 | `@security-compliance` |
| **PR-18** | A scheduled task without batching, time-box, and structured log. | M7 | `@tasks-cleanup` |
| **PR-19** | A skipped or commented-out test (`markTestSkipped` without ticket reference, `/* test_... */`). | M6 | `@testing` |
| **PR-20** | A vendored library change without updated `VENDOR.md` SHA256s. | M12 / S2 | `@security-compliance` |

---

## How `@pr-reviewer` runs these

For each PR:

1. Run every grep / static-analysis script in `.claude/ci-checks/`.
2. Map each non-zero exit to a PR-rule ID.
3. Run the coverage gate; map any failure to PR-11.
4. Output a structured report (see `@pr-reviewer` example invocation).
5. Verdict: PASS if all checks pass, BLOCK otherwise.

---

## Adding a new PR-rule

A new auto-reject condition requires:

1. An ADR by `@backend-architect`.
2. A new CI script under `.claude/ci-checks/` that mechanically detects the violation.
3. A failing-test fixture proving the script catches the intended pattern AND a passing-test fixture proving it doesn't false-positive on legitimate code.
4. Sign-off from at least one other agent (typically `@security-compliance`).
5. A retro on the recent bug or near-miss that motivated the new rule.

Rules can only be added via this process. They cannot be added by `@pr-reviewer` alone (no self-modification).

## Removing a PR-rule

A rule can only be removed if:

1. It hasn't fired in 6 months across the codebase.
2. It's mechanically subsumed by another rule.
3. The architecture doc no longer requires it.

`@backend-architect` proposes removal; another agent approves; `@pr-reviewer` continues running it for one release cycle marked `STATUS: DEPRECATED` before final removal.
