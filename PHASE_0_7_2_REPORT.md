# ARTeuCtion — Phase 0.7.2 Report

**Date:** 2026-11-03  
**Branch:** `test/phase-180-preservation-provenance`  
**Objective:** Close critical reliability gaps before staging deployment

---

## Summary

Phase 0.7.2 addressed the four issues raised in the Phase 0.7.1 assessment:

| Issue | Status |
|---|---|
| A. Stripe reconciliation logical gap (`external_ref`) | ✅ Implemented |
| B. DB schema changes version-controlled | ✅ Migration file created |
| C. S3 quarantine architecture | ⏭ Documented; M2 infrastructure |
| D. WebSocket token lifecycle + Redis | ⏭ Documented; requires Redis infrastructure |

---

## 1. Database Migrations

**File:** `apps/api/prisma/migrations/20261103000001_add_outbox_lease_columns/migration.sql`

| Column | Table | Type | Purpose |
|---|---|---|---|
| `lease_expires_at` | `domain_events` | `DATETIME NULL` | Multi-instance atomic claiming |
| `lease_expires_at` | `transfer_outbox` | `DATETIME NULL` | Transfer dispatch lease |
| `external_ref` | `transfer_outbox` | `VARCHAR(64) UNIQUE NULL` | Stable Stripe correlation ID |

**Indexes added:**
- `de_lease_recovery_idx` on `domain_events(status, lease_expires_at)` — recovery query
- `to_lease_recovery_idx` on `transfer_outbox(status, lease_expires_at)` — recovery query
- `to_dispatch_queue_idx` on `transfer_outbox(status, next_attempt_at)` — dispatch queue
- `to_external_ref_unique` on `transfer_outbox(external_ref)` — uniqueness enforcement

**Timestamp convention:** All new columns use `DATETIME` (not `TIMESTAMP`) to avoid MariaDB's automatic UTC↔local conversion. UTC throughout.

**Prisma schema:** Both models updated. `prisma generate` required after `migrate deploy`.

---

## 2. Stripe Crash Recovery

Three crash scenarios now covered end-to-end:

| Scenario | Detection | Prevention |
|---|---|---|
| Crash before Stripe call | Lease expiry + retry | `idempotency_key` deduplicates at Stripe |
| Crash after Stripe, before `stripe_transfer_id` saved | `external_ref` → `findTransferByGroup` | Orphaned transfer found and claimed |
| Crash after `stripe_transfer_id` saved | `retrieveTransfer(id)` | Direct retrieval, even after 24h |

**Key addition:** `external_ref` is a stable UUID written to the DB _before_ the Stripe API call. It is passed as `transfer_group = outbox:{external_ref}` and in `metadata`. This enables crash-scenario B recovery regardless of Stripe's 24h idempotency window.

**New method:** `StripeService.findTransferByGroup(group)` calls `stripe.transfers.list({ transfer_group, limit: 1 })`.

**Tests:** SC13, SC15, SC17, SC18 — 18 scheduler tests total, all passing.

---

## 3. Multi-Instance Operations

### What is Implemented

| Scenario | Implementation | Tests |
|---|---|---|
| Two instances claim same domain_event | `UPDATE WHERE status='pending'` | SC14 ✅ |
| Two instances claim same transfer | Same atomic claim | SC13 ✅ |
| Worker crash — event stuck | Lease expiry recovery | SC16 ✅ |
| Duplicate Stripe transfer | `external_ref` + `findTransferByGroup` | SC17 ✅ |

### What Requires Live Infrastructure

| Scenario | Status |
|---|---|
| Two actual processes against real MariaDB | ⏭ Staging deployment |
| Simultaneous bid acceptance race | ⏭ `bid-concurrency.spec.ts` (HAS_DB) |
| 100 concurrent bids | ⏭ `bid-load.spec.ts` (HAS_DB) |
| WebSocket cross-instance rooms | ⏭ Redis adapter not implemented |

---

## 4. S3 Quarantine

Documented in `S3_SECURITY_VALIDATION.md`. Architecture defined; infrastructure (Lambda/worker) classified as M2.

**Current production risk:** Presigned PUT URLs authorize client uploads. Declared `Content-Type` is validated; actual file bytes are not. An adversarial upload could store a non-image file at an artwork key.

**Mitigation until M2:** Image derivatives are not yet auto-generated from uploaded files in production (the `artwork.derivatives_requested` domain event handler logs and exits). The actual S3 key is not served as a public image URL in the current staging config — it requires a presigned GET, which limits the attack surface.

---

## 5. WebSocket Token Lifecycle

Documented in `WEBSOCKET_SECURITY_REPORT.md`.

| Gap | Status |
|---|---|
| Private subscription revalidation on JWT expiry | ⏭ Client must reconnect |
| Explicit revocation (compromised token) | ⏭ Not implemented; key rotation invalidates all |
| Redis adapter for cross-instance rooms | ⏭ Required before multi-instance WS |
| Test: user cannot subscribe to another user's room | ⏭ Not yet |

---

## Test Results

| Suite | Tests | Status |
|---|---|---|
| SchedulerService (SC1–SC18) | 18 | ✅ |
| Config validation (CV1–CV14) | 14 | ✅ |
| Bid gateway (BG1–BG5) | 5 | ✅ |
| Stripe webhook (WH1–WH9) | 9 | ✅ |
| All other unit suites | ~728 | ✅ |
| MariaDB integration suites (6) | 83 skipped | ⏭ HAS_DB |

---

## Decisions

| Decision | Verdict |
|---|---|
| DB schema version-controlled | ✅ Migration file created |
| Stripe crash recovery (unit level) | ✅ All three scenarios covered |
| Staging deployment | **GO** — infrastructure + migrations ready |
| DB integration tests | **GO** after `migrate deploy` on staging MariaDB |
| Production traffic migration | **CONDITIONAL** — pending integration test results |
| Laravel retirement | **Not yet** |

---

## Remaining Phase 0.7.2 Gates (Require Staging Environment)

| Gate | Description |
|---|---|
| P1 | Run `prisma migrate deploy` on staging MariaDB |
| P2 | Run 6 MariaDB integration suites (`HAS_DB=true`) |
| P3 | Run 100 concurrent bid test against real MariaDB |
| P4 | Two-worker simultaneous claim test (manual integration) |
| P5 | Stripe test-mode transfer dispatch end-to-end |
| P6 | Complete 14 staging user journeys (STAGING_SMOKE_TEST_REPORT.md) |
| P7 | Redis adapter + multi-instance WS broadcast verification |
| P8 | S3 quarantine worker (M2) |
