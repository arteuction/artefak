# Context Packs

Context packs are task-scoped Repomix bundles generated locally from `scripts/ai-context/`.
They reduce the amount of irrelevant source an agent reads at the start of a task.

## Profiles

| Profile | Script | What it includes |
|---|---|---|
| Overview | `overview.sh` | AGENTS.md, docs/ai/*, CI workflows, routes |
| Frontend | `frontend.sh` | React pages, components, CSS tokens, generated API types, e2e tests |
| Payments | `payments.sh` | Settlement/payment domain, jobs, webhook, ledger migrations, financial tests |
| Deployment | `deployment.sh` | Workflows, operations docs, release gates |

## Rules

- Generate locally; never upload the full repository to a third-party packing service.
- Inspect the output before use; verify no secrets are included.
- A task that changes subtle transactional behavior needs the real source files, not only a compressed bundle.
- Update this README when adding a new profile.
