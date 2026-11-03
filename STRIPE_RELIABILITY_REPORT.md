# ARTeuCtion — Stripe Reliability Report

**Date:** 2026-11-03

---

## Transfer Idempotency Protocol

### The Problem

```
1. Worker claims transfer_outbox row (marks 'processing')
2. Stripe accepts transfer (tr_abc123 created)
3. Worker crashes — never writes 'completed' to DB
4. Lease expires, recoverStaleClaims resets row to 'pending'
5. New worker picks up the row
   → Without reconciliation: creates a SECOND transfer at Stripe
   → With reconciliation: discovers tr_abc123 still exists, marks completed
```

### Implementation

**File:** `apps/api/src/scheduler/scheduler.service.ts`

```typescript
// Step 1: Atomic DB claim
UPDATE transfer_outbox
SET status='processing', attempt=attempt+1, lease_expires_at=...
WHERE id=? AND status='pending'
-- rowsAffected=0 → another instance claimed it; skip

// Step 2: Reconcile if stripe_transfer_id already persisted
if (existingTransferId) {
  const reconciled = await stripe.retrieveTransfer(existingTransferId)
  if (reconciled) → mark completed, skip new transfer

// Step 3: Create transfer with stable idempotency key
await stripe.createTransfer({
  idempotency_key: `transfer_outbox:${entry.id}`,  // same key on retry
  ...
})
// Stripe deduplicates calls with same key for 24 hours

// Step 4: Persist stripe_transfer_id IMMEDIATELY
UPDATE transfer_outbox SET stripe_transfer_id=? WHERE id=?

// Step 5: Mark completed
UPDATE transfer_outbox SET status='completed' WHERE id=?
```

### Edge Cases Handled

| Scenario | Handling |
|---|---|
| Crash before Stripe call | Row resets via lease expiry; next attempt creates transfer with same idempotency_key |
| Crash after Stripe, before DB write | `stripe_transfer_id` not persisted; next attempt creates transfer with same idempotency_key (Stripe deduplicates within 24h) |
| Crash after DB write of transfer_id, before completed | `reconcileTransfer` finds existing transfer; marks completed without new transfer |
| Delayed retry after 24h Stripe idempotency window | `retrieveTransfer` finds existing transfer by ID; reconciles regardless of idempotency window |
| Two instances claiming simultaneously | Atomic UPDATE WHERE prevents double-claim; one gets rowsAffected=1, other gets 0 |

### Required DB Schema Change

```sql
ALTER TABLE transfer_outbox 
  ADD COLUMN stripe_transfer_id VARCHAR(255) NULL,
  ADD COLUMN lease_expires_at DATETIME NULL;
```

Add to Prisma schema and create migration before production.

---

## Webhook Idempotency

**File:** `apps/api/src/settlement/webhook-handler.service.ts`

All webhook handlers check for an existing settlement/record before processing:
- `payment_intent.succeeded` → skip if settlement already `received`
- `charge.refunded` → skip if no matching settlement
- `payment_intent.payment_failed` → skip if bid not in expected state

**Test coverage:** WH1–WH9 (9 tests)

---

## Refund Consistency

- Refunds write to `refunds` table, not `ledger_entries`
- Ledger reversal entries created separately to maintain three-ledger invariant
- Settlement status transitions: `received → refunded`

---

## Test Coverage Summary

| Scenario | Test | Status |
|---|---|---|
| Duplicate webhook idempotency | WH1 | ✅ |
| Transfer atomic claim | SC13 | ✅ |
| Transfer idempotency_key passed | SC13 | ✅ |
| Transfer reconciliation on retry | SC15 | ✅ |
| Stale claim recovery | SC16 | ✅ |
| Duplicate transfer (two instances) | SC14 (claim skipped) | ✅ |

---

## Outstanding (Require Live Stripe Test Mode)

- [ ] End-to-end checkout session creation and completion
- [ ] Real webhook delivery and processing
- [ ] Refund → ledger reversal via real Stripe refund event
- [ ] Transfer dispatch to real Stripe Connect account
- [ ] Delayed retry after 24h idempotency window expiry (manual test)
- [ ] Stripe rate limiting behaviour under load
