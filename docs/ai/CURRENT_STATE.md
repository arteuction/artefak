# ARTeuCtion — Current State and Release Truth

**Last verified:** 2026-10-15 from local git + GitHub remote fetch.

## Release candidate

| Field | Value |
|---|---|
| `main` SHA | `7f6c60d` (merge of PR #17) |
| Last merged PR | #17 `test/phase-166-auction-room-coverage` — reconnect, reserve_not_met, double-submit, multi-lot isolation Vitest tests + CURRENT_STATE update |
| PHP versions tested | 8.4 + 8.5 (CI matrix) |
| MariaDB | 11 |
| OpenAPI spec version | `3.1.2` (Scramble, verified by `ScrambleApiSpecTest`) |

## Branch map (verified 2026-10-15)

| Branch | HEAD | Status |
|---|---|---|
| `main` | `7f6c60d` | Clean; PRs #1–17 merged |
| `test/phase-166-auction-room-coverage` | `e10b6c2` | Merged as PR #17 |
| `docs/phase-165-ai-context-engineering` | `1196115` | Merged as PR #15 |
| `design/phase-161-design-system-foundation` | `cfca6b7` | Merged as PR #16 |
| `ops/queue-backpressure-and-deploy-recovery` | `4a33fd8` | Merged as PR #14 |
| `fix/phase-168-eur-golden-path-buyer-journey` | active | Fix BGN→EUR in GoldenPathTest; add buyer-journey.spec.ts Playwright suite |

## Key unverified items

- [ ] CI results for `main` SHA `0f53810` — link to GitHub Actions run not recorded here; verify via trusted CI interface.
- [ ] Staging environment deployed commit — not verified from this machine.
- [ ] Production VPS deployed commit — not verified.
- [ ] Stripe test-mode end-to-end rehearsal — not recorded.
- [ ] Backup restoration and rollback — not rehearsed and recorded.
- [ ] Playwright buyer-journey.spec.ts run against a live stack — API contract tests pass without a server; UI journey tests need SPA + Laravel running.
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
| #15 | docs/phase-165-ai-context-engineering | AI context engineering: AGENTS.md, CLAUDE.md, docs/ai/, .claude/rules/, Repomix profiles |
| #16 | design/phase-161-design-system-foundation | Phases 161–164: design tokens, ArtworkCard, AuctionTimer, BidControl, AuctionRoomPage + Vitest suite |
| #17 | test/phase-166-auction-room-coverage | Reconnect, reserve_not_met, double-submit, multi-lot isolation Vitest tests |

## Active security suppressions

- GHSA-hhx9-57xq-r5rw (`@hey-api/openapi-ts`) — dev-only, suppressed in OSV scanner; upgraded to 0.67.6 but suppression retained pending a patched 0.67.x. Review before next security audit.

## Update policy

Update this file in the same PR that changes branch/release readiness. Every statement about merge state, passing CI, deployment or rehearsal must link to a commit, workflow run, test report or runbook entry. Separate *reported*, *verified*, *blocked* and *not tested* status.
