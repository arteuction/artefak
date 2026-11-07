# ARTeuCtion — Auction Load Test Results

**Date:** 2026-11-03  
**Status:** PENDING — staging MariaDB not yet provisioned

---

## Test Design

### Scenario 1: Concurrent bids, single lot

100 HTTP requests placed simultaneously against a single open auction item.

- All bids: same amount (ensures true concurrency on the winning condition)
- Expected: exactly 1 bid accepted, 99 rejected with appropriate error
- Failure condition: more than 1 bid accepted (oversell), or 0 accepted (starvation)

### Scenario 2: Concurrent bids, independent lots

100 requests, each targeting a different auction item.

- Expected: each item gets exactly 1 winning bid
- Failure condition: any item has 0 or >1 winning bids

### Scenario 3: Rapid anti-sniping extension

Bids placed within the anti-sniping window (default 120s before close).

- Expected: auction end time extended correctly per `auction_rulesets.extension_seconds`
- Failure condition: auction closes without extension, or extends more than once per bid

---

## Run Command

```bash
# Requires live MariaDB + seeded auction data
DATABASE_URL="mysql://..." HAS_DB=true NODE_OPTIONS="--experimental-vm-modules" \
  npx jest --no-coverage --testPathPattern="bid-concurrency|bid-load"
```

---

## Metrics To Record

| Metric | Scenario 1 | Scenario 2 |
|---|---|---|
| Total requests | 100 | 100 |
| Accepted bids | — | — |
| Rejected bids | — | — |
| Deadlocks observed | — | — |
| Deadlock retries | — | — |
| P50 latency (ms) | — | — |
| P95 latency (ms) | — | — |
| P99 latency (ms) | — | — |
| Duration (ms total) | — | — |

---

## `SELECT FOR UPDATE` Implementation

The concurrency protection in `closeAuctionItem` uses MariaDB's row-level lock:

```typescript
// Inside a $transaction
const item = await tx.auctionItem.findUnique({ where: { id: itemId } });
if (item.status !== 'open') return; // idempotent skip

// Set status first within the transaction — concurrent transactions block here
await tx.auctionItem.update({ where: { id: itemId }, data: { status: 'sold' } });
```

Prisma's `$transaction` uses `BEGIN; ... COMMIT;` with InnoDB row locking. Two concurrent transactions reading the same item will serialize at the UPDATE statement.

For direct bid acceptance (`POST /bids`), the bid service uses:

```sql
UPDATE auction_items SET winning_bid_id = ?, status = 'sold' WHERE id = ? AND status = 'open'
```

`rowsAffected = 0` means another bid won first — respond 409.

---

## Results (To Be Filled After Staging Run)

**Scenario 1 (single lot, 100 concurrent):**
- Accepted bids: —
- PASS / FAIL: —
- Notes: —

**Scenario 2 (independent lots, 100 concurrent):**
- All lots got exactly 1 winner: —
- PASS / FAIL: —
- Notes: —
