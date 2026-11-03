# ARTeuCtion — Multi-Worker Validation

**Date:** 2026-11-03  
**Status:** PENDING — requires two running NestJS processes against live MariaDB

---

## Test Setup

```bash
# Terminal 1 — Worker A
DATABASE_URL="mysql://..." PORT=3001 node dist/main.js

# Terminal 2 — Worker B
DATABASE_URL="mysql://..." PORT=3002 node dist/main.js

# Both workers connect to the same MariaDB instance.
# Both run the same @Cron jobs concurrently.
```

---

## Test Matrix

### T1: Simultaneous domain_event claim

1. Insert a domain_event row with `status='pending'`, `next_attempt_at=past`
2. Both workers tick at the same second
3. **Expected:** Exactly one worker processes the event; the other skips (`rowsAffected=0`)
4. **Failure:** Event processed twice (duplicate notification sent)

**Verify:** `SELECT attempt FROM domain_events WHERE id=?` — should be `1` after both workers tick.

### T2: Simultaneous transfer_outbox claim

1. Insert a transfer_outbox row with `status='pending'`
2. Both workers tick at the same minute
3. **Expected:** Exactly one Stripe transfer created; `stripe_transfer_id` written once
4. **Failure:** Two transfers with identical `transfer_group` (financial duplicate)

**Verify via Stripe CLI:**
```bash
stripe transfers list --transfer-group="outbox:to-{id}-{ts}"
# Must return exactly 1 transfer
```

### T3: Lease expiry and recovery

1. Kill Worker A mid-processing (row stuck in `status='processing'`)
2. Wait 10+ minutes (transfer_outbox lease) or 5+ minutes (domain_events lease)
3. `recoverStaleClaims` runs on Worker B
4. **Expected:** Row reset to `status='pending'`; Worker B picks up and completes

**Verify:** `SELECT status FROM transfer_outbox WHERE id=?` — transitions: `pending → processing → pending → completed`

### T4: Duplicate scheduled execution prevention

Two workers both run `closeExpiredLots` at the same minute.

**Expected:** Auction items closed exactly once. `auction_items.status` transitions from `open` to `sold/unsold` only once.

**Verify:** `SELECT COUNT(*) FROM bids WHERE status='won' AND auction_item_id=?` — must be ≤ 1.

### T5: DB connection interruption

Simulate a brief MariaDB unavailability (restart DB, 10s downtime).

**Expected:** Workers retry with backoff; no data corruption; operations complete after DB returns.

**Failure:** Stuck `processing` rows, lost events.

---

## Checklist (To Be Completed After Staging Deployment)

| Test | Worker A result | Worker B result | Duplicate? | PASS/FAIL |
|---|---|---|---|---|
| T1: domain_event claim | — | — | — | — |
| T2: transfer claim | — | — | — | — |
| T3: lease expiry + recovery | — | — | — | — |
| T4: duplicate close prevention | — | — | — | — |
| T5: DB interruption recovery | — | — | — | — |

---

## Acceptance Criteria

- Zero duplicate financial operations (Stripe transfers, settlement completions)
- Zero duplicate auction closures (no item with >1 winning bid)
- All lease-expired rows recovered within 2× the lease window
- No data corruption under DB interruption
