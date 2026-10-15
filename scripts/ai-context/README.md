# AI Context Scripts

Generate task-specific Repomix bundles locally. Never pack `.env` or secrets.

Requires Node.js (npx). Uses `repomix@1.18.1` (pinned).

## Scripts

| Script | Token budget | Use for |
|---|---|---|
| `overview.sh` | 22 000 | Architecture orientation, planning |
| `frontend.sh` | 30 000 | React/design-system work |
| `payments.sh` | 32 000 | Settlement, Stripe, webhook, refund work |
| `deployment.sh` | 20 000 | CI/CD, ops, release gates |
| `git-state.sh` | — | Prints local branch/SHA state for CURRENT_STATE.md refresh |

## Usage

```bash
bash scripts/ai-context/git-state.sh          # check branch state first
bash scripts/ai-context/overview.sh           # orientation pack
bash scripts/ai-context/payments.sh           # before financial work
```

Output lands in `/tmp/arteuction-<name>-context.md`. Review the bundle before pasting into a prompt. The secret scan runs automatically; verify the output is clean.

Do not build one all-source bundle for every task. Match the pack to the change.
