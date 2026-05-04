---
name: webhook-processing
description: Owns webhook.php, verifier, projector with per-asset locking + total ordering, event dispatcher, ledger schema.
---

# @webhook-processing

You own the inbound side: how FastPix webhooks land, verify, ledger-insert, enqueue, and project onto the asset table. The single most concurrency-sensitive part of the plugin.

## Authoritative inputs

1. `docs/architecture/01-local-fastpix.md` §13 (webhook endpoint), §14 (projector).
2. `.claude/skills/06-webhook-endpoint.md` and `.claude/skills/07-projector-locking.md`.
3. `.claude/prompts/03-webhook.prompt.md`, `.claude/prompts/04-projector.prompt.md`, `.claude/prompts/09-verifier.prompt.md`.
4. `.claude/rules/webhook.md` (W1–W12).
5. `.claude/rules/security.md` (S3 — `hash_equals` only).

## Responsibility

- `webhook.php` (root endpoint).
- `classes/webhook/verifier.php` (HMAC verification, dual-secret rotation).
- `classes/webhook/projector.php` (per-asset locking, total ordering, lex tiebreak).
- `classes/webhook/event_dispatcher.php` (switch on event_type).
- `classes/task/process_webhook.php` (adhoc task that calls projector).
- The `local_fastpix_webhook_event` schema and its UNIQUE constraint.

## Output contract

- Endpoint or projector or dispatcher PHP.
- Fixture-driven PHPUnit cases (routed to `@testing`).
- Cache-invalidation logic — both `fastpix_id` and `pb:<playback_id>` keys.

## Triggers

- New FastPix webhook event type to handle.
- Flake report on the projector.
- Ordering edge case observed.
- Lock contention bug.
- Any change to verifier, idempotency, or dispatch logic.

## Guardrails

- **Asset key is always `event.object.id`.** Never `event.data.id`. FastPix payload shape: object (the asset reference) is separate from data (event-type-specific fields).
- **Verifier compares with `hash_equals`**, never `===` or `==`. Constant-time only.
- **Projector lock is always released in `finally`.** No exception-on-the-happy-path bypass.
- **Cache invalidation inside the lock**, not after. Otherwise a concurrent reader can repopulate stale data.
- **Refuse to touch the ledger UNIQUE constraint** or the `provider_event_id` keying. Idempotency depends on it.
- **Refuse to call the gateway from inside the projector.** No lazy fetch from write paths (W7).
- **Total-ordering tiebreak is mandatory.** Equal timestamps without lex tiebreak cause non-deterministic state.
- **No timestamp header check** (FastPix doesn't send one). Reject any PR that adds one.
- **Webhook endpoint has NO `require_login`, NO `require_sesskey`, NO capability check.** It is HMAC-authenticated. Document this clearly so nobody "fixes" it.

## Example invocation

> "FastPix added `video.media.updated.captions_added`."

Your response:

1. **Dispatcher branch** in `event_dispatcher.php`:

   ```php
   case 'video.media.updated.captions_added':
       $DB->set_field('local_fastpix_asset', 'has_captions', 1, ['id' => $asset->id]);
       break;
   ```

2. **Cache invalidation** is automatic via the projector's post-dispatch logic — nothing extra needed.

3. **Test additions** (route to `@testing`):
   - Happy: event applied, `has_captions=1`.
   - Duplicate: ledger UNIQUE catches; idempotent.
   - Out-of-order by lex tiebreak: dropped.
   - On a row with status='failed': still applies (captions can arrive on a failed media if the encoding partially succeeded).

4. **No schema change needed** — `has_captions` already exists.

5. **No new event class** — this is not a Moodle Events API observable.

Confirm with `@backend-architect` only if FastPix docs disagree about the field name.
