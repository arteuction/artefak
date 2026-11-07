# ARTeuCtion — MariaDB Integration Results

**Date:** 2026-11-03  
**Status:** PENDING — staging MariaDB not yet provisioned

---

## Skipped Test Count Reconciliation

The earlier report cited "83 skipped tests" across 6 suites. The actual count is:

| Suite | Tests | HAS_DB gated | Notes |
|---|---|---|---|
| `auction/bid-concurrency.spec.ts` | 10 | ✅ all | `SELECT FOR UPDATE` concurrency |
| `auction/bid-load.spec.ts` | 2 | ✅ all | 100 concurrent bids |
| `auth/auth.spec.ts` | 41 | ✅ all | Full auth flow |
| `financial/financial-integrity.spec.ts` | 10 | ✅ all | Three-ledger invariant |
| `schema-baseline.spec.ts` | 10 | ✅ all | `prisma migrate diff` parity |
| `timezone/timezone-roundtrip.spec.ts` | 10 | ✅ all | UTC/Sofia roundtrips |
| `guards.spec.ts` | 12 | ❌ none | NestJS + Passport unit mocks, no DB |

**HAS_DB-gated total: 83 tests across 6 suites.**  
`guards.spec.ts` (12 tests) runs without a DB and is currently passing.

The report's "approximately 43 tests" was an incorrect estimate. The actual count is 83.

---

## How to Run

```bash
# Staging MariaDB must be running and migrated
DATABASE_URL="mysql://arteuction_user:password@staging-db:3306/arteuction_staging" \
  HAS_DB=true \
  NODE_OPTIONS="--experimental-vm-modules" \
  npx jest --no-coverage --testPathPattern="bid-concurrency|bid-load|auth.spec|financial-integrity|schema-baseline|timezone-roundtrip"
```

Expected output: 83 tests, 0 failed.

---

## Pre-Run Requirements

1. `prisma migrate deploy` completed successfully
2. All 3 new columns present: `domain_events.lease_expires_at`, `transfer_outbox.lease_expires_at`, `transfer_outbox.external_ref`
3. All 4 new indexes present (verify with `SHOW INDEX FROM domain_events; SHOW INDEX FROM transfer_outbox;`)
4. Test database named exactly `arteuction_test` (HARD DB GUARD — SQLite rejected)
5. Seed data loaded per `prisma/seed.ts` or equivalent

---

## Individual Suite Acceptance Criteria

### `bid-concurrency.spec.ts` (10 tests)
- `SELECT FOR UPDATE` prevents two concurrent bids from winning the same lot
- Transaction rollback on constraint violation works
- Winner determination is deterministic

### `bid-load.spec.ts` (2 tests)
- 100 simultaneous bids against one auction item: exactly 1 accepted
- 100 simultaneous bids against independent items: each gets 1 winner (no cross-item interference)
- Record: total time, P50/P95/P99 latency, deadlock count

### `auth.spec.ts` (41 tests)
- JWT issue and verify
- Role-based access (buyer, artist, operator, admin)
- Policy enforcement on all protected resources
- Token expiry and refresh

### `financial-integrity.spec.ts` (10 tests)
- `ledger_entries` contains only `sale_split` and `refund_debit` types
- `donations` table never joined with `ledger_entries` on same foreign key
- `impact_events` table never mixed with financial records
- All monetary values stored as integers (no float arithmetic)
- Currency fields are 3-char ISO codes

### `schema-baseline.spec.ts` (10 tests)
- `prisma migrate diff` exits 0 (schema matches applied migrations)
- All new columns present with correct types and nullability
- All indexes present
- UTC timezone confirmed for `lease_expires_at` columns

### `timezone-roundtrip.spec.ts` (10 tests)
- Timestamps written as UTC survive a round-trip through MariaDB
- Sofia (Europe/Sofia, UTC+2/+3) sessions do not corrupt stored timestamps
- `UTC_TIMESTAMP()` comparison is consistent with application `new Date()`

---

## Results (To Be Filled After Staging Run)

| Suite | Tests | Passed | Failed | Duration | Notes |
|---|---|---|---|---|---|
| bid-concurrency | 10 | — | — | — | — |
| bid-load | 2 | — | — | — | — |
| auth | 41 | — | — | — | — |
| financial-integrity | 10 | — | — | — | — |
| schema-baseline | 10 | — | — | — | — |
| timezone-roundtrip | 10 | — | — | — | — |
| **Total** | **83** | — | — | — | — |
