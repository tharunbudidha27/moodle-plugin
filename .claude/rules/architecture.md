# Architecture Rules (A1–A6)

These rules govern *where code goes*. Layering, dependency direction, hot-path constraints. Cited by `@backend-architect` and `@pr-reviewer`.

Rule format: `ID | Rule statement | Enforcement mechanism | Failure routing`.

---

## A1 — 3-layer separation is mandatory

**Rule.** Code lives in exactly one of three layers:

| Layer | Lives in | Does | Must NOT do |
|---|---|---|---|
| UI / endpoint | `*.php` at root, `classes/external/`, `templates/`, `amd/src/` | `require_login`, `require_capability`, `require_sesskey`, validate input, delegate to service, render | Contain business logic. Make HTTP calls. |
| Service | `classes/service/` | Business rules, idempotent operations, returns plain data | Touch `$_GET` / `$_POST` / `$OUTPUT`. Make HTTP calls (delegates to integration). |
| Integration | `classes/api/` | All external HTTP, retry, circuit breaker, idempotency keys | Contain business logic. Be called from anywhere except a service. |

**Enforcement.** PR review checklist; static check that any file under `classes/external/`, `classes/task/`, or root `*.php` containing `\core\http_client` is rejected.

**Failure routing.** `@backend-architect` for the design fix; the relevant specialist agent for the implementation fix.

---

## A2 — Gateway is the only place for external HTTP

**Rule.** All FastPix HTTP — and every other external HTTP — goes through `classes/api/gateway.php`. No `curl_*`, no other Guzzle, no `file_get_contents` against `http(s)://...`.

**Enforcement.** CI script `.claude/ci-checks/grep-no-fastpix-outside-gateway.sh` — `grep -rE 'fastpix\.io|api\.fastpix' --include=*.php local/fastpix/` outside `classes/api/` returns zero matches. CI script `.claude/ci-checks/grep-no-curl.sh` — `grep -rE 'curl_(init|exec|setopt)' local/fastpix/classes/` returns zero matches.

**Failure routing.** `@gateway-integration`.

---

## A3 — Services contain all business logic

**Rule.** No business logic in endpoints (`classes/external/*`, root `*.php`). Endpoints do the auth dance and delegate. Tasks delegate to services. CLI scripts delegate to services. The same service is callable from a web endpoint, a CLI script, a scheduled task, and an adhoc task — if you find yourself copying logic between endpoints, you skipped the service layer.

**Enforcement.** PR review.

**Failure routing.** Offending diffs routed to `@backend-architect` for redesign.

---

## A4 — No circular dependencies on surface plugins

**Rule.** `local_fastpix` MUST NOT reference `mod_fastpix`, `filter_fastpix`, or `tiny_fastpix`. Not in `composer.json` (there isn't one), not in `version.php` `requires`, not in namespaces, not in capability strings, not in lang strings. The dependency direction is fixed: surface plugins depend on `local_fastpix`, never the reverse.

**Enforcement.** CI script `.claude/ci-checks/grep-no-cross-plugin-refs.sh` — `grep -rE 'mod_fastpix|filter_fastpix|tiny_fastpix' local/fastpix/ --exclude-dir=tests` returns zero matches. (Test fixtures are exempt because they may simulate cross-plugin scenarios.)

**Failure routing.** `@backend-architect`.

---

## A5 — No HTTP from the webhook projector

**Rule.** The projector is a write-path. It must not call the gateway. No lazy fetch, no health probe, no token fetch, no caption fetch. Lazy fetch from a webhook projection during a FastPix outage creates a feedback loop that prolongs the outage.

**Enforcement.** PR review; `@webhook-processing` and `@asset-service` agent guardrails reject this. CI script `.claude/ci-checks/grep-no-lazy-fetch-on-write-path.sh` — `grep -E '_or_fetch|gateway' local/fastpix/classes/webhook/` returns zero matches outside comments.

**Failure routing.** `@asset-service` if the offender used `_or_fetch`; `@gateway-integration` if the offender called the gateway directly.

---

## A6 — Hot path is exactly one method (`get_media`)

**Rule.** New gateway methods default to PROFILE_STANDARD (5s connect, 30s read). Adding a hot-path method (PROFILE_HOT — 3s/3s) requires `@backend-architect` ADR and an explicit justification in the architecture doc.

**Enforcement.** Code review; agent guardrail; PR-reviewer cites this when reviewing gateway changes.

**Failure routing.** `@backend-architect` for the ADR; `@gateway-integration` for the implementation if approved.
