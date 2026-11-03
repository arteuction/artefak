# ARTeuCtion — Staging Smoke Test Report

**Date:** 2026-11-03  
**Status:** Template (staging not yet deployed)

---

## Prerequisites

Before this report can be completed, the following must be in place:

1. Staging Docker stack running (`docker-compose.nestjs.yml`)
2. MariaDB accessible with `arteuction_test` database seeded
3. Stripe test-mode keys configured (`sk_test_...`, `whsec_...`)
4. S3 bucket (or MinIO) configured
5. All 6 MariaDB integration suites passing (`HAS_DB=true npx jest`)

See `STAGING_DEPLOYMENT_GUIDE.md` for full setup.

---

## Journey Matrix

| # | Journey | Actor | Backend complete | Staging result |
|---|---|---|---|---|
| J1 | Register + email verify | Buyer | ✅ | ⏭ |
| J2 | Login + JWT issue | Buyer | ✅ | ⏭ |
| J3 | Artist creates artwork + S3 upload | Artist | ✅ | ⏭ |
| J4 | Auction created, lot published | Admin | ✅ | ⏭ |
| J5 | Buyer subscribes to lot (WS, no auth) | Public | ✅ | ⏭ |
| J6 | Buyer logs in, places bid | Buyer | ✅ | ⏭ |
| J7 | Second buyer outbids — WS update fires | Buyer 2 | ✅ | ⏭ |
| J8 | Auction closes — winner notified via WS private room | Winner | ✅ | ⏭ |
| J9 | Winner completes Stripe checkout | Winner | ✅ | ⏭ |
| J10 | Stripe webhook: `payment_intent.succeeded` → settlement | System | ✅ | ⏭ |
| J11 | Artist receives transfer (outbox dispatched) | Artist | ✅ | ⏭ |
| J12 | Book purchase + presigned download | Buyer | ✅ | ⏭ |
| J13 | Donation submitted + evidence linked | Donor | ✅ | ⏭ |
| J14 | Admin views ledger — three tables never mixed | Admin | ✅ | ⏭ |

---

## Acceptance Criteria Per Journey

### J5 — Public WebSocket (No Auth)
- [ ] Connect without `Authorization` header
- [ ] Emit `subscribe-lot` for an active lot
- [ ] Receive `subscribed` ack
- [ ] When J6 bids, receive `bid:placed` event
- [ ] `bid:placed` payload contains no `bidder_id`

### J8 — Winner Notification
- [ ] Winner connects with valid JWT
- [ ] Emits `subscribe-user`
- [ ] Lot closes → winner receives `lot:won` on private room
- [ ] Other connected clients receive `lot:closed` without `winner_user_id`

### J10 — Webhook Idempotency
- [ ] Replay `payment_intent.succeeded` event
- [ ] Settlement status remains `received` (not doubled)
- [ ] `ledger_entries` count unchanged after replay

### J11 — Transfer Dispatch
- [ ] Transfer outbox row created on settlement
- [ ] Scheduler dispatches within 60s
- [ ] `stripe_transfer_id` written to DB
- [ ] Row marked `completed`
- [ ] Verify no duplicate transfer in Stripe dashboard

### J14 — Ledger Integrity
- [ ] Query: `SELECT * FROM donations d JOIN ledger_entries l ON d.id = l.related_id` returns 0 rows
- [ ] Query: `SELECT * FROM impact_events i JOIN ledger_entries l ON i.id = l.related_id` returns 0 rows
- [ ] Only `sale_split` and `refund_debit` types appear in `ledger_entries`

---

## Config Validation Gate (Pre-Smoke)

Before any journey test, confirm startup validation passed:

```bash
docker-compose logs nestjs | grep "\[config\]"
# Expected:
# [config] INFO  All required environment variables validated ✓
# No [config] ERROR lines
```

If any `[config] ERROR` line appears, the server should have refused to start.

---

## MariaDB Integration Suite Results

Run before journey tests to confirm DB-level correctness:

```bash
cd apps/api
HAS_DB=true DATABASE_URL="mysql://..." npx jest --testPathPattern="integration" --no-coverage
```

Expected suites (6):
- [ ] `bid-concurrency.spec.ts` — all passing
- [ ] `financial-integrity.spec.ts` — all passing
- [ ] `timezone-roundtrip.spec.ts` — all passing
- [ ] `auth.spec.ts` — all passing
- [ ] `bid-load.spec.ts` — all passing
- [ ] `schema-baseline.spec.ts` — all passing (migrate diff exits 0)

---

## Current Unit Test Baseline

| Suite group | Tests | Status |
|---|---|---|
| Config validation (CV1–CV14) | 14 | ✅ |
| Bid gateway (BG1–BG5) | 5 | ✅ |
| Scheduler (SC1–SC16) | 16 | ✅ |
| Stripe webhook (WH1–WH9) | 9 | ✅ |
| All other unit suites | ~730 | ✅ |
| MariaDB integration suites | 83 skipped | ⏭ |

Total unit tests passing: ~774. Zero failures, zero unexpected skips.

---

## Verdict

**Status: NOT YET EXECUTED** — staging environment not yet provisioned.

This document serves as the test plan. Complete the checklist above and record pass/fail per journey after deployment. Update `PRODUCTION_ENVIRONMENT_CHECKLIST.md` gates G1 and G5 once all journeys pass.
