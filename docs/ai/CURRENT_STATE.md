# ARTeuCtion — Current State and Release Truth

**Last verified:** 2026-10-15 from local git + GitHub remote fetch.

## Release candidate

| Field | Value |
|---|---|
| `main` SHA | `6e47acb8c7590c3d2736380c2cb81f06d9763a71` |
| Last merged PR | #14 `ops/queue-backpressure-and-deploy-recovery` — queue backpressure checks in CheckOperationalAlerts |
| PHP versions tested | 8.4 + 8.5 (CI matrix) |
| MariaDB | 11 |
| OpenAPI spec version | `3.1.2` (Scramble, verified by `ScrambleApiSpecTest`) |

## Branch map (verified 2026-10-15)

| Branch | HEAD | Status |
|---|---|---|
| `main` | `6e47acb` | Clean; PRs #1–14 merged |
| `design/phase-161-design-system-foundation` | `cfca6b7` | **NOT merged to main.** Contains Phases 161–164: design tokens, ArtworkCard, AuctionTimer, BidControl, AuctionRoomPage integration tests. Open PR needed. |
| `ops/queue-backpressure-and-deploy-recovery` | `4a33fd8` | Merged as PR #14 |
| `perf/cursor-pagination-public-catalog` | `1589d8f` | Merged as PR #13 |
| `test/stripe-outbox-failure-modes` | `1a8d401` | Merged as PR #11 |
| `ops/queue-retry-contracts-and-versioned-derivatives` | `3ba5ab5` | Merged as PR #10 |

## Key unverified items

- [ ] `cfca6b7` (Phase 164 — AuctionRoomPage) is on `design/phase-161-design-system-foundation` but **not in `main`**. Design system work is not shipped.
- [ ] CI results for `main` SHA `6e47acb` — link to GitHub Actions run not recorded here; verify via trusted CI interface.
- [ ] Staging environment deployed commit — not verified from this machine.
- [ ] Production VPS deployed commit — not verified.
- [ ] Stripe test-mode end-to-end rehearsal — not recorded.
- [ ] Backup restoration and rollback — not rehearsed and recorded.
- [ ] Frontend typecheck/build and Playwright — not run; no playwright.config in repo yet.
- [ ] Memory benchmark on 1 GB VPS profile — reported in Phase 158 docs; not independently re-verified against current code.

## Merged PRs and their scope

| PR | Branch | Key change |
|---|---|---|
| #1–3 | fix/full-suite-green | Initial green suite, security fixes (OSV, handlebars, openapi-ts), PHP 8.4 CI |
| #4 | feat/phase-158-deploy-infrastructure | Production VPS deploy, FrankenPHP, PHP-FPM profiles, memory benchmarks |
| #5 | fix/optimization-critical-high | Critical/high perf fixes, FULLTEXT async |
| #6 | fix/recipient-type-enum-guard | Schema guard for settlement/refund_lines enum downgrade |
| #7–9 | docs/architecture-* | Architecture docs, tech research, optimization roadmap |
| #10 | ops/queue-retry-contracts-and-versioned-derivatives | `$tries`/`$backoff` on DispatchStripeTransfer/Reversal; versioned derivative keys |
| #11 | test/stripe-outbox-failure-modes | Fault-injection tests for webhook processing; removed `final` from ProcessRefund |
| #12 | perf/public-catalog-n1-and-etag | N+1 fix, ETag + Cache-Control on artwork detail |
| #13 | perf/cursor-pagination-public-catalog | Cursor/keyset pagination for public catalog (newest sort) |
| #14 | ops/queue-backpressure-and-deploy-recovery | Queue backpressure + deploy recovery checks in CheckOperationalAlerts |

## Active security suppressions

- GHSA-hhx9-57xq-r5rw (`@hey-api/openapi-ts`) — dev-only, suppressed in OSV scanner; upgraded to 0.67.6 but suppression retained pending a patched 0.67.x. Review before next security audit.

## Update policy

Update this file in the same PR that changes branch/release readiness. Every statement about merge state, passing CI, deployment or rehearsal must link to a commit, workflow run, test report or runbook entry. Separate *reported*, *verified*, *blocked* and *not tested* status.
