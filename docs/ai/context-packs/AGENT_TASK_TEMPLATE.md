# Agent Task Template

Use this template when starting a new coding task with an agent.

---

Read `AGENTS.md`, `docs/ai/CURRENT_STATE.md` and `docs/ai/INVARIANTS.md`.
Then inspect only the relevant source files and tests for this task.
Generate a task-specific context snapshot if needed (`scripts/ai-context/<profile>.sh`).
Do not read or summarize the entire repository unless the task explicitly requires a whole-system audit.

**Task:** [describe the change]

**Affected domain:** [e.g. Settlement / Auction / Frontend / Deployment]

**Relevant files to inspect first:**
- [list key files]

**Constraints from INVARIANTS.md that apply:**
- [list relevant invariant numbers]

**Tests to run before and after:**
- [list test commands]

**Definition of done:**
- [ ] Changed files listed
- [ ] Behavior change described
- [ ] Tests run with results recorded
- [ ] Migration/config implications noted
- [ ] `docs/ai/CURRENT_STATE.md` updated if branch/release state changed
