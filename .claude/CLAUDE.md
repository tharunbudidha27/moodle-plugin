# CLAUDE.md — Project System Prompt for `local_fastpix`

> Authoritative, immutable. Read this first. Re-read it before any non-trivial change.

This file is the project-level system prompt that Claude (CLI, Code, or chat) loads when working in this repository. It tells Claude what the project is, what scope it operates in, what rules are non-negotiable, and which agent to delegate to for any given task.

---

## What this project is

`local_fastpix` is the **local plugin** of a four-plugin Moodle integration with FastPix (a video CDN with FastPix-compatible APIs). It is the single trusted boundary between Moodle and FastPix. Everything in `mod_fastpix`, `filter_fastpix`, and `tinymce_fastpix` calls through this plugin's services. **No other plugin talks to `fastpix.io` directly.**

This repo / directory contains **only** `local_fastpix`. Sibling plugins live in their own monorepo subdirectories. Cross-plugin code references in this plugin are forbidden (see rule **A4**).

---

## Authoritative inputs

Two architecture docs are the source of truth. Every rule in `rules/`, every prompt in `prompts/`, and every skill in `skills/` derives from these. If something here contradicts them, the docs win.

- `00-system-overview.md` — system-wide architecture (4 plugins, 7 non-negotiables, FastPix external contract, cross-plugin invariants).
- `01-local-fastpix.md` — production-grade architecture for **this** plugin (XMLDB schema, gateway spec, JWT signing, webhook system, projector, services, scheduled tasks, privacy provider, 30-checkbox Definition of Done).

Anywhere a file or prompt cites "§N", that section number refers to `01-local-fastpix.md` unless `00-system-overview.md` is named explicitly.

---

## Scope (in / out)

**In scope:**
- Files under `local/fastpix/` of the Moodle install.
- Plugin-internal contracts (services, gateway, webhook system, tasks, privacy).
- Stable cross-plugin API exposed by `asset_service`, `upload_service`, `jwt_signing_service`, `feature_flag_service` — but only the **callable surface**, not the consumers.

**Out of scope (do not write code for):**
- `mod/fastpix/`, `filter/fastpix/`, `lib/editor/tiny/plugins/fastpix/` — those are sibling plugins with their own AI-dev systems.
- FastPix-side changes (the external API is a fixed contract; we adapt to it, never the reverse).
- Moodle core changes.

If a request asks for cross-plugin code, decline and route the user to the sibling plugin's `.claude/` system.

---

## The seven non-negotiables

These are baked into `rules/architecture.md` (A1–A6) and `rules/security.md` (S1–S10). They exist because each one corresponds to a class of bug that has shipped in similar integrations and is expensive to find post-deploy.

1. **The 3-layer rule.** Endpoint → Service → Gateway. No layer skipping. (A1)
2. **No cross-plugin imports.** `mod_fastpix`, `filter_fastpix`, `tinymce_fastpix` consume only the documented service API. (A4)
3. **Gateway is the only HTTP boundary.** The literal string `fastpix.io` and the use of `\core\http_client` for FastPix calls live exclusively in `classes/api/`. (A2)
4. **JWT signing is local, RS256 only.** No `createToken` endpoint exists on FastPix. The plugin holds an RSA private key (auto-bootstrapped), and signs with `firebase/php-jwt`. HS256 is forbidden. (S6)
5. **Webhook signature uses `hash_equals`.** Single header `FastPix-Signature`. No timestamp. Dual-secret 30-min rotation. `===` on signatures fails CI. (S2, W1, W2, W3)
6. **Projector holds a per-asset lock with total ordering.** Lex tiebreak on `provider_event_id`. Lock release in `finally`. Cache invalidation **inside** the lock. (W6, W7, W8)
7. **No lazy fetch on the write path.** Only `asset_service::get_by_fastpix_id_or_fetch` may call the gateway, and only from read paths. The projector, privacy provider, and scheduled tasks must never trigger lazy fetch. (A6)

---

## Hard "do not" list

These are auto-rejected by `@pr-reviewer` (see `rules/pr-rejection.md`). Don't even draft code that violates them — fix the design first.

- Do not introduce a `createToken` / `playback_create_token` method on the gateway. JWT signing is local.
- Do not use `curl_*` or any HTTP client other than `\core\http_client`.
- Do not use `===` or `==` to compare signatures or HMACs. `hash_equals` only.
- Do not store circuit-breaker state in static class properties. MUC only.
- Do not log raw API keys, API secrets, JWT-shaped strings, signatures, raw user IDs, or webhook bodies.
- Do not read the webhook body via framework parsing. Use `file_get_contents('php://input')` **before** any framework call.
- Do not skip the SSRF guard on URL pull. Every URL pull must reject loopback, RFC1918, link-local, AWS metadata, and non-https before touching the gateway.
- Do not bulk-delete via FastPix API. Per-asset DELETE only.
- Do not gate DRM on a single check. The double-gate (`drm_enabled` checkbox **and** `drm_configuration_id` set) is mandatory.
- Do not extract asset keys from `event.data.id`. The correct field is `event.object.id`.
- Do not compare timestamps without a lex tiebreak in the projector.
- Do not omit the `finally` block on lock release.
- Do not modify the public surface of `asset_service`, `upload_service`, `jwt_signing_service`, or `feature_flag_service` without a major version bump and ADR.

---

## Agent routing table

When Claude receives a task, it delegates to one of the agents in `agents/`. The orchestrator agent for review and routing is `@pr-reviewer`. Claude should pick the most specific agent — generic "code editor" routing is wrong; every change has an owner.

| Task pattern | Agent |
|---|---|
| Architectural decision, ADR, design tradeoff | `@backend-architect` |
| `classes/api/gateway.php` changes; FastPix HTTP behavior; retry / breaker / idempotency | `@gateway-integration` |
| `classes/service/jwt_signing_service.php`; signing key bootstrap; key rotation | `@jwt-signing` |
| `classes/webhook/*`, `webhook.php`, `process_webhook` adhoc, verifier, projector | `@webhook-processing` |
| `classes/service/asset_service.php`; cache key invalidation; lazy fetch | `@asset-service` |
| `classes/service/upload_service.php`; SSRF guard; dedup; URL pull | `@upload-service` |
| `classes/task/*`; cron hygiene; GDPR retry; soft-delete purge | `@tasks-cleanup` |
| Privacy provider; capability audit; credential storage; secrets handling | `@security-compliance` |
| `tests/**`; coverage gates; redaction canary; Behat scenarios | `@testing` |
| Reviewing a diff or PR; orchestrating a multi-file change | `@pr-reviewer` |

A task that touches two areas (e.g., gateway + tests) involves both agents in sequence: the implementing agent first, then `@testing`. `@pr-reviewer` is the final gate.

---

## How to use this

- **Before any code change:** re-read the relevant section of `01-local-fastpix.md` and skim `rules/`. Don't trust memory.
- **When generating a file from scratch:** use the matching prompt in `prompts/`. The prompt is the source of truth for what the file must contain. Don't paraphrase the requirements; copy them.
- **When reviewing a PR or diff:** load `@pr-reviewer` and walk the PR-1..PR-20 list in `rules/pr-rejection.md`. If any item triggers, the PR is rejected with a routing pointer to the relevant agent.
- **When adding a new capability:** check `WORKFLOW.md` for the phase you're in. Phases gate each other; don't get ahead of yourself.

---

## Tone

- Senior backend engineer voice. Concise, opinionated, citation-heavy.
- Reference section numbers (`§11`, `§13.2`, `§14`) so reviewers can audit decisions against the doc.
- When you say "no", give the rule ID (`A4`, `S2`, `W7`, `PR-12`) so the user can find the rationale themselves.
- When something is genuinely uncertain, say "this needs an ADR" and route to `@backend-architect`. Don't guess.

---

## What this is not

- Not a tutorial. Don't add explanatory comments that re-state what the code does.
- Not a place for clever abstractions. Every layer in the 3-layer rule exists because someone got it wrong before. Don't collapse them.
- Not a personal style canvas. The shape of files is fixed by the prompts. Deviation needs justification.

---

When in doubt: re-read the doc, follow the rules, route to the right agent, and let `@pr-reviewer` catch what slips through.
