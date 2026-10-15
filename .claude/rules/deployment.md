---
paths:
  - ".github/workflows/**"
  - "scripts/deploy/**"
  - "deploy/**"
  - "docker-compose*.yml"
  - "compose*.yaml"
  - "config/queue.php"
  - "config/broadcasting.php"
  - "config/services.php"
  - "docs/operations/**"
---
# Deployment and operations rules

- Read `docs/ai/CURRENT_STATE.md` and `docs/ai/RELEASE_GATES.md`; refresh branch, candidate SHA and CI evidence first.
- Never print or pack `.env`, SSH private keys, tokens, Stripe secrets or production credentials.
- Prefer reproducible, pinned runtime and build inputs. Do not modify production services or execute a deploy without explicit user direction and a documented target.
- Consider Laravel config/route/view caching, worker restart, Reverb restart, scheduler, queue backpressure, migration compatibility, asset manifest and rollback behavior.
- Do not assume a backup is valid until a restore has been exercised. Record the backup location class, retention, restore time and evidence without including secrets.
- A health-check command is useful only if it detects the relevant failure. Distinguish test-mode payment rehearsal from live payment behavior.
- Never claim deployment success unless the target host and deployed commit are verified from trusted evidence.
