# GitHub Repository Consolidation Plan — ARTeuCtion

_Generated: 2026-11-03. Planning document only. No repositories will be deleted or modified during this planning phase._

---

## Current Repository Situation

The working directory `C:\Users\Chris23\Downloads\artefak-dev\` is a single Git repository that already contains both the Laravel monolith (root) and the NestJS PoC (`apps/api/`). This is effectively already a monorepo by directory structure, though the Laravel and NestJS portions were not originally designed as one. No separate `apps/api/.git` exists — all commits are in the root `.git`.

The repository is hosted at `https://github.com/arteuction/artefak.git` (confirmed from git remote).

Other related repositories (ArtMetro, infrastructure tooling, frontend) are presumed to exist separately on GitHub but were not accessible for inspection during this planning phase. **Do not consolidate or move those repositories until their boundaries are confirmed.**

---

## Recommended Long-Term Monorepo Structure

```
ARTeuCtion/                          # Root: git repository
│
├── apps/
│   ├── api/                         # NestJS 12 backend (already here)
│   │   ├── src/
│   │   ├── prisma/
│   │   └── package.json
│   │
│   ├── workers/                     # Background job workers (BullMQ or similar)
│   │   └── package.json
│   │
│   └── admin/                       # Admin panel (React or NestJS-backed)
│       └── package.json
│
├── resources/
│   └── frontend/                    # React + Vite (already partially here)
│       ├── src/
│       └── package.json
│
├── packages/                        # Shared, versioned packages
│   ├── api-contracts/               # OpenAPI spec / Zod schemas / tRPC defs
│   ├── shared-types/                # TypeScript types shared across apps
│   └── shared-validation/           # Validation schemas (Zod) used by API + frontend
│
├── infrastructure/                  # IaC (Terraform/Pulumi), Docker Compose, Nginx configs
│   ├── docker/
│   ├── nginx/
│   └── terraform/
│
├── docs/
│   ├── ai/                          # AI planning documents (this directory)
│   ├── architecture/                # Architecture decision records (ADRs)
│   └── api/                         # Generated OpenAPI docs
│
├── legacy/
│   └── laravel/                     # Laravel monolith — moved here at Stage 4 retirement
│       └── (entire Laravel codebase)
│
├── .github/
│   └── workflows/
│       ├── laravel-ci.yml           # Existing Laravel CI (keep until retirement)
│       ├── nestjs-ci.yml            # NestJS CI (already active)
│       └── shared-types-ci.yml
│
├── pnpm-workspace.yaml              # Workspace root (or npm workspaces)
├── turbo.json                       # Turborepo build orchestration (optional)
└── CLAUDE.md                        # AI agent entry point (already exists)
```

---

## What Moves Where

### Already in correct location
- `apps/api/` — NestJS PoC: **keep here**
- `resources/frontend/` — React frontend: **keep here**
- `docs/ai/` — AI planning documents: **keep here**

### To be created
- `apps/workers/` — Extract queue job implementations from NestJS API module into a dedicated workers app (optional; can start as a module within `apps/api/`)
- `apps/admin/` — Admin panel replacement (decision pending)
- `packages/api-contracts/` — Extract OpenAPI spec from NestJS Swagger output; add Zod schemas
- `packages/shared-types/` — Extract TypeScript interfaces used by both API and frontend
- `infrastructure/` — Docker Compose, Nginx config for local dev and staging

### Laravel (current root) → `legacy/laravel/`
- Move at Stage 4 only, after all production traffic has migrated
- Use `git subtree split` to preserve full git history for the Laravel code
- The `legacy/laravel/` directory will have its own package.json-equivalent (composer.json) and can still be run independently

---

## Git History Preservation Strategy

### Principle: No History is Lost

- **Never `git push --force` on the main branch**
- **Never `git filter-repo` without creating a backup branch first**
- **Never delete a branch that has not been merged or explicitly abandoned**
- Archive = GitHub "Archive this repository" feature. Archived repos are read-only, visible, searchable, and their history is intact.

### Moving Laravel to `legacy/laravel/`

When the time comes (Stage 4 only):

```bash
# 1. Create a backup tag before any history surgery
git tag v1-laravel-final HEAD

# 2. Use git subtree to move Laravel root files to legacy/laravel/
# This preserves commit history under the new path
git read-tree --prefix=legacy/laravel/ -u HEAD

# Alternatively, manually move files and commit
# (simpler, loses per-file history but keeps repo history)
```

The second option (simple `git mv` + commit) is recommended: it is safe, reversible, and does not require history rewriting. The commit message and git log preserve the full history of the root anyway.

### For Other Repositories (ArtMetro, infrastructure, etc.)

If any separate repositories need to be merged into the monorepo:

```bash
# From the monorepo root:
git remote add artmetro https://github.com/arteuction/artmetro.git
git fetch artmetro
git merge --allow-unrelated-histories artmetro/main \
    --strategy-option subtree=apps/artmetro
```

This preserves full commit history from the external repo, rebased under the monorepo subdirectory. Do this only after confirming the integration boundary is clear.

---

## CI/CD Pipeline Migration Checklist

### GitHub Actions

- [ ] Audit all workflow files in `.github/workflows/` for both repos
- [ ] Ensure workflow triggers use `paths:` filters so Laravel CI only runs on Laravel changes and NestJS CI only runs on `apps/api/` changes
- [ ] Add a `paths-ignore: ['legacy/laravel/**']` to NestJS workflows after Laravel is moved
- [ ] Add the NestJS workflow to the main branch protection rules

### Secrets Migration

For each secret in the legacy repository:

- [ ] Inventory all repository secrets and environment secrets in GitHub Settings
- [ ] Confirm which are still needed (Stripe test keys, AWS staging keys, DB credentials)
- [ ] Add required secrets to the monorepo repository (or organisation-level if shared)
- [ ] Remove secrets from the legacy repository after archiving (or leave — archived repos cannot use secrets anyway)
- [ ] **Never commit secrets to source code. Never include them in AI context packs.**

### Environment Variables

Current confirmed integrations (from `config/services.php`):
- `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`
- `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`
- `POSTMARK_API_KEY` (email)
- `RESEND_API_KEY` (email alternative)
- `SLACK_BOT_USER_OAUTH_TOKEN` (notifications)
- `MEILISEARCH_*` (search — not yet in config/services.php but used via laravel/scout)
- `REVERB_*` (WebSocket — laravel/reverb config)

Each of these will need a NestJS equivalent environment variable. Document the mapping in `infrastructure/env-migration.md` before cutover.

---

## Archive-Not-Delete Policy

### Rule

No GitHub repository related to ARTeuCtion is to be deleted. Ever. Repositories are archived after migration.

### Archive Procedure

1. Ensure all open PRs are merged or closed with a note
2. Create a final release tag: `vX.Y.Z-final` with release notes summarising what the repo contained
3. Update the repository description to: `[ARCHIVED — succeeded by arteuction/artefak monorepo]`
4. Use GitHub Settings → "Archive this repository"
5. Record the archive date and final commit SHA in `docs/ai/REPOSITORY_ARCHIVE_LOG.md`

### What Archiving Does

- Repository becomes read-only
- All history, tags, releases, issues, and wiki content are preserved
- Repository remains public (or private, matching original visibility)
- CI/CD stops running (no new workflow triggers)
- Repository is still cloneable and searchable

### What Archiving Does NOT Do

- Does not delete any data
- Does not break existing clone URLs
- Does not affect npm/composer packages published from the repo
- Is reversible: GitHub allows unarchiving

---

## Independent Repositories (Do Not Merge Until Boundaries Are Clear)

| Potential Repo | Relationship | Recommendation |
|---------------|-------------|----------------|
| ArtMetro | Downstream consumer of ARTeuCtion data | Keep separate until ARTeuCtion API is stable and ArtMetro integration contracts are defined |
| Infrastructure tooling | Terraform/Ansible for server provisioning | Move to `infrastructure/` in monorepo if owned by same team; keep separate if managed by ops team independently |
| Mobile app (if exists) | Frontend consumer | Keep separate; mobile release cycles differ from backend |
