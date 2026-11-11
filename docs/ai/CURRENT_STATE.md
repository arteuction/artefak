# ARTeuCtion — Current State and Release Truth

**Last verified:** 2026-11-11 from local git (after `git fetch`) and direct inspection of `apps/api/src`.
Items not re-verified on this date are marked **carried over**; do not treat them as current.

## Two stacks, one repository

| Stack | Role today | Detail |
|---|---|---|
| Laravel monolith (repo root) | **Production writer** for every domain | PHP 8.4 + 8.5 (CI matrix), MariaDB 11, Scramble OpenAPI 3.1.2 — *carried over from 2026-10-15* |
| NestJS 12 + Prisma 7 (`apps/api`) | Migration target, **not serving production traffic** | 95 spec files; route parity 258/258 reported by `route-inventory.spec.ts` |

Laravel owns the schema (116 migrations). The only Prisma migration is `20261103000001_add_outbox_lease_columns` and it requires the Laravel schema to exist first.
Domain-by-domain ownership: `DOMAIN_OWNERSHIP_MATRIX.md`. Migration scope and gates: `LARAVEL_EXIT_PLAN.md`. Remaining work: `REMAINING_LARAVEL_DEPENDENCIES.md`.

## Git facts (verified 2026-11-11)

| Field | Value |
|---|---|
| `origin/main` | `00d9505e` — merge of PR #31 (`test/phase-180-preservation-provenance`) |
| Working branch | `test/phase-180-preservation-provenance`, merged with `origin/main`; PRs #29–#31 came from this branch name |
| Workflows | `ci.yml`, `deploy.yml`, `security.yml`, `staging.yml` |
| Remote branches | ~20 (`git branch -r`); merge state of each is **not** recorded here — check Git, not this file |

PRs #1–#17 are itemised in Git history (phase 158 deploy infra, optimization fixes, Stripe outbox fault-injection tests, cursor pagination, design system 161–164, auction-room tests). PRs #18–#28 were merged after the previous version of this file and are not itemised here: use `git log --merges origin/main`.

## NestJS implementation snapshot (verified by code inspection, 2026-11-11)

"Exists" means code and unit tests are present. It does **not** mean parity with Laravel or that it has run against a real database, Stripe or S3.

| Capability | State in `apps/api/src` |
|---|---|
| Auth, 12 policies, `$2y$`→`$2b$` bcrypt shim | Implemented; real-DB auth tests not yet run |
| Stripe | `createCheckoutSession`, `constructEvent`, `retrieveSession`, `retrieveTransfer`, `createTransfer`, `createTransferReversal`; returns a stub session when `STRIPE_SECRET_KEY` is unset. `RefundService` (port of Laravel `ProcessRefund`) and a reversal cron were added 2026-11-11 — mocked-Prisma tests only. `payout.*` events not handled |
| Webhook handler | Handles `checkout.session.completed`, `payment_intent.succeeded`, `.amount_capturable_updated`, `.payment_failed`, `charge.refunded` |
| Settlement / payout | `SettlementService.settle/preview` and payout list queries exist; cent-for-cent parity vs Laravel vectors **not demonstrated** |
| Scheduler (`@nestjs/schedule`) | 6 crons: close-expired-lots, expire-bid-authorizations, dispatch-domain-events, dispatch-transfer-outbox, dispatch-transfer-reversals, recover-stale-claims. No BullMQ. No nightly reconciliation, governance health or ops alerts |
| S3 | Presign upload/download implemented; falls back to `stub-s3.local` URLs when disabled. Image derivatives (no `sharp`) not implemented |
| Search | Meilisearch service present (search/index/delete) |
| Realtime | Socket.io `BidGateway` present |
| Email | `nodemailer` service with 5 templates |
| Still stubs | Most of `admin.controller.ts` (reconciliation, split profiles, rulesets, books, connected payouts, ArtMetro), `artmetro-legacy`, `misc.controller` (api-clients, partners), book-files upload-intent, GDPR erasure (records intent only) |
| Not present at all | PDF generation, activity log, Filament/admin UI replacement, Slack alerts |

## Key unverified items

- [ ] CI result for `00d9505e` — not recorded; verify in GitHub Actions.
- [ ] 83 `HAS_DB` NestJS integration tests have never run against a real `arteuction_test` (see `HARD_BLOCKER_STATUS.md` P0.2).
- [ ] Staging/production deployed commit — not verified from this machine.
- [ ] Stripe test-mode end-to-end rehearsal (P0.4) — not recorded.
- [ ] Backup restoration and rollback — not rehearsed and recorded.
- [ ] Playwright buyer-journey against a live stack — needs SPA + backend running.
- [ ] 1 GB VPS memory benchmark (Phase 158) — reported, not independently re-verified.
- [ ] Laravel-side PHP/MariaDB/OpenAPI facts in the table above — carried over.

## Local verification done on 2026-11-11

- `artwork.service.spec.ts`: 52/52 passing locally.
- `npm audit` at repo root: 0 vulnerabilities after `follow-redirects` 1.16.1 → 1.16.2 (GHSA-mrwp-f3q8-3g9p). CI Security workflow result not yet observed.

## Active security suppressions

- GHSA-hhx9-57xq-r5rw (`@hey-api/openapi-ts`) — dev-only, suppressed in the OSV scanner pending a patched 0.67.x. Carried over; review before the next security audit.

## Documentation map

| Kind | Documents |
|---|---|
| Rules (stable) | `INVARIANTS.md`, `RELEASE_GATES.md`, `.claude/rules/*` |
| Status (must stay current) | this file, `HARD_BLOCKER_STATUS.md`, `DOMAIN_OWNERSHIP_MATRIX.md` |
| Plans | `LARAVEL_EXIT_PLAN.md`, `PRODUCTION_CUTOVER_PLAN.md`, `TRAFFIC_SWITCH_AND_ROLLBACK.md`, `docs/decisions/ADR-*` |
| Historical snapshots | `NESTJS_MIGRATION_MAP.md`, `PHASE_0_2_REPORT.md`, `docs/architecture/*` |

## Update policy

Update this file in the same PR that changes branch/release readiness. Every statement about merge state, passing CI, deployment or rehearsal must link to a commit, workflow run, test report or runbook entry. Separate *reported*, *verified*, *blocked* and *not tested* status. If "Last verified" is older than the newest merged PR, treat the file as stale.
