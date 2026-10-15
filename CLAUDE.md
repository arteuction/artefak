# ARTeuCtion — Claude Code entry point

Follow the repository-wide guidance in `AGENTS.md` and read `docs/ai/INVARIANTS.md` before changing domain or financial code. For factual branch/release state, use `docs/ai/CURRENT_STATE.md`, but refresh its marked-unverified fields from Git and CI before making release decisions.

Load `.claude/rules/` files only for matching paths. Prefer a task-specific context pack from `scripts/ai-context/` over reading the entire repository. Never include secrets or production `.env` contents in a prompt or generated pack.
