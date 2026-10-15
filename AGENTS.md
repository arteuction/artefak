# ARTeuCtion — Agent Instructions

## Read first
1. `docs/ai/CURRENT_STATE.md` — current branch/release facts and unverified items.
2. `docs/ai/INVARIANTS.md` — rules that must not be violated.
3. `docs/ai/DOMAIN_MAP.md` — subsystem entry points.
Read only the subsystem-specific rules and source files needed for the task.

## Working rules
- Treat repository source, migrations, tests, CI and deployed-revision evidence as authoritative. Architecture notes are navigation aids, not proof of implementation.
- Before coding, inspect the current branch, working tree, relevant callers, migrations, tests and current dependency versions. Do not assume a phase is merged because it exists on another branch.
- Keep changes narrow. Reuse existing domain actions, DTOs, API client, design tokens and testing conventions. Do not add a dependency without stating the problem it solves, its maintenance/licence status and why existing code is insufficient.
- For a behavior change, add or update the closest meaningful test. For financial or authorization changes, include failure, duplicate/retry and boundary cases.
- Never change production data, run destructive commands against non-test databases, or expose secrets. Do not read or include `.env` contents in context packs.
- Do not make financial records mutable to simplify a workflow. Corrections should follow the existing append-only/reversal model.
- Do not silently change currency, split profiles, tax/rights semantics, public cache policy, API contracts or event payload shape. Document compatibility impact and tests.
- Make claims only about commands and tests actually run. Report skipped tests and environmental blockers explicitly.
- Never treat a green unit suite as proof that production Stripe, queues, Reverb, storage, deployment or backups work end to end.

## Before finishing
Report: changed files; behavior change; tests/commands run with results; commands not run and why; migration/config/deployment implications; remaining risks; whether `docs/ai/CURRENT_STATE.md` requires an update.

## Project commands
Use the commands defined by `composer.json`, `package.json`, CI and the runbooks. Do not invent scripts. Confirm test database guard and environment before running tests.
