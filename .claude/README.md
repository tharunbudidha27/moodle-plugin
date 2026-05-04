# `.claude/` — AI-assisted development system for `local_fastpix`

This directory is a self-contained AI-dev system for the `local_fastpix` Moodle plugin. It ships **with** the plugin source in your monorepo, but it is **not** part of the Moodle plugin payload — exclude it from `make-zip`, the Moodle Plugins Directory upload, and any CI step that doesn't need it.

It encodes the architecture decisions in `00-system-overview.md` and `01-local-fastpix.md` as agents, skills, prompts, and rules that Claude can load and apply consistently across a 4-week build.

---

## Quick start

1. Drop this `.claude/` folder into the root of `local/fastpix/` in your Moodle monorepo.
2. Place the two architecture docs alongside it (or somewhere Claude can read them):
   - `.claude/docs/00-system-overview.md`
   - `.claude/docs/01-local-fastpix.md`
3. Open the repo in VS Code with the Claude extension, or run `claude` from the CLI in this directory.
4. Claude auto-loads `CLAUDE.md` (the project system prompt). Verify with: "Which agent owns the projector?" — expected answer: `@webhook-processing`.
5. Pick a phase from `WORKFLOW.md` and start. Each phase tells you which agent runs, which skill it invokes, and what the validation gate is.

---

## Layout

```
.claude/
├── CLAUDE.md                  # Project system prompt — Claude loads this first
├── README.md                  # You are here
├── WORKFLOW.md                # 7-phase execution plan (Week 1 → GA)
├── agents/                    # 10 specialist agents with clear ownership
│   ├── backend-architect.md
│   ├── gateway-integration.md
│   ├── jwt-signing.md
│   ├── webhook-processing.md
│   ├── asset-service.md
│   ├── upload-service.md
│   ├── tasks-cleanup.md
│   ├── security-compliance.md
│   ├── testing.md
│   └── pr-reviewer.md         # orchestrator + reject-list enforcer
├── skills/                    # 15 skills — atomic build instructions
│   ├── 01-create-skeleton.md
│   ├── 02-database-schema.md
│   ├── 03-vendor-php-jwt.md
│   ├── 04-gateway.md
│   ├── 05-jwt-signing.md
│   ├── 06-webhook-endpoint.md
│   ├── 07-projector-locking.md
│   ├── 08-asset-service.md
│   ├── 09-upload-service.md
│   ├── 10-scheduled-tasks.md
│   ├── 11-feature-flags.md
│   ├── 12-gdpr-flow.md
│   ├── 13-health-endpoint.md
│   ├── 14-structured-logging.md
│   └── 15-phpunit-tests.md
├── prompts/                   # 10 copy-pasteable code-generation prompts
│   ├── 01-gateway.prompt.md
│   ├── 02-jwt-signing.prompt.md
│   ├── 03-webhook.prompt.md
│   ├── 04-projector.prompt.md
│   ├── 05-asset-service.prompt.md
│   ├── 06-upload-service.prompt.md
│   ├── 07-scheduled-task.prompt.md
│   ├── 08-phpunit-tests.prompt.md
│   ├── 09-verifier.prompt.md
│   └── 10-privacy-provider.prompt.md
└── rules/                     # 50 grep-enforced rules, 5 categories
    ├── architecture.md        # A1–A6
    ├── security.md            # S1–S10
    ├── moodle.md              # M1–M12
    ├── webhook.md             # W1–W12
    └── pr-rejection.md        # PR-1..PR-20 reject list
```

---

## How the pieces interact

```
                   ┌──────────────────────────┐
                   │        CLAUDE.md         │  ← loaded first; sets scope, non-negotiables
                   └────────────┬─────────────┘
                                │
                ┌───────────────┼────────────────┐
                ▼               ▼                ▼
         agents/          rules/            WORKFLOW.md
       (who owns      (what is             (when each
        what)         non-negotiable)       agent runs)
                │               │                │
                └───────┬───────┴────────┬───────┘
                        ▼                ▼
                    skills/          prompts/
                  (the steps)     (copy-paste templates
                                   that enforce rules)
                        │
                        ▼
                  generated files in
                local/fastpix/classes/...
                        │
                        ▼
                   @pr-reviewer
                (gates merge with PR-1..PR-20)
```

- **CLAUDE.md** loads on every Claude invocation. Everything below is referenced from it.
- **Agents** are personas with focused scope. Each one knows its rules, its skills, its prompts, and the sections of `01-local-fastpix.md` it owns.
- **Rules** are the auditable, grep-enforceable constraints. They reference rule IDs (A4, S2, W7, PR-12) so any "no" has a citation.
- **Skills** describe *how* to do a thing. **Prompts** are the literal text you paste into Claude when you want code generated. Skills compose multiple prompts.
- **WORKFLOW.md** sequences the build. Don't get ahead of the phase gates.

---

## Authority precedence

When two sources disagree, the higher one wins:

1. `00-system-overview.md` and `01-local-fastpix.md` (the architecture docs)
2. `CLAUDE.md` (project system prompt)
3. `rules/*.md` (codified constraints)
4. `prompts/*.md` (generation templates)
5. `skills/*.md` (build steps)
6. `agents/*.md` (per-agent guidance)
7. `WORKFLOW.md` (phase plan)
8. `README.md` (this file — orientation only)

If a conflict surfaces, fix it at the highest source and let it propagate down.

---

## Iterative improvement loop

This system is meant to compound. As you build, you'll find rules that need to be tightened or skills that produce code with avoidable bugs. The loop:

1. **Find a defect** — in code review, a flaky test, or a production incident.
2. **Locate the rule** — it should already exist in `rules/`. If it does, tighten the wording, add a grep pattern, and add a PR-reject entry.
3. **If no rule exists** — write one. Cite the doc section it derives from. Add it to the relevant rules file. Update PR-1..PR-20 if it's reject-worthy.
4. **Update the agent** — the owning agent's file should mention the new rule in its constraint list.
5. **Update the skill / prompt** — if a generation template lets the bug through, harden the template.
6. **Re-run the affected phase's validation checklist** — confirm the gate still passes.

The system gets stronger with each pass. After three rounds, the prompts produce code that needs minor edits at most.

---

## Things this system does not include

- **CI scripts** to enforce the rules (e.g., `grep` checks for `fastpix.io` outside `classes/api/`). Add them under `.claude/ci-checks/` if you want them — the rule files contain the grep patterns.
- **Runbooks** for ops scenarios (signing-key rotation, webhook-secret rotation, stuck circuit breaker, stuck GDPR delete). Add them under `.claude/runbooks/`.
- **Pre-commit hooks** that wire CI checks into git. Easy to add once the CI scripts exist.

These are deliberately out of scope for the initial drop so the AI-dev system stays focused. They're separate work items.

---

## Compatibility

- Built for Moodle 4.5 LTS and 5.0 — no dependencies on either-or behavior.
- PHP 8.2 / 8.3 / 8.4 — no syntax that breaks on 8.2.
- MySQL 8.0+ / MariaDB 10.6+ / Postgres 13+ — no DB-specific SQL.
- No composer at runtime — `firebase/php-jwt` is vendored under `classes/vendor/`.

---

## Versioning

This `.claude/` directory is versioned alongside the plugin in the same monorepo. When the architecture docs change in a way that invalidates a rule or prompt, update the affected files and bump the plugin minor version. Keep a `CHANGELOG-claude.md` (separate from the plugin CHANGELOG) noting which rules / prompts changed and why — that history is invaluable when revisiting decisions a year later.

---

## Where to start reading

If this is your first time in the repo:

1. `CLAUDE.md` — the rules of the road (5 minutes).
2. `WORKFLOW.md` — what gets built when (10 minutes).
3. `rules/architecture.md` and `rules/security.md` — the seven non-negotiables in detail (15 minutes).
4. `agents/pr-reviewer.md` — the orchestrator's view of the system (5 minutes).
5. `01-local-fastpix.md` §1–§4 — the schema and the data model (20 minutes).

That's an hour. After that, pick a phase from `WORKFLOW.md` and start.
