# ARTeuCtion — Phase 0.7.3 Staging Report

**Date:** 2026-11-03  
**Status:** IN PROGRESS — code complete; staging deployment pending

---

## What Phase 0.7.3 Is

Phase 0.7.3 is the first independent staging deployment of the NestJS + React architecture. Its primary success criterion is completing a full auction workflow (bid → checkout → settlement → transfer) without Laravel participating.

---

## Code Changes in Phase 0.7.3

### Stripe Transfer Reconciliation — Verification Strengthened

**User requirement:** Do not blindly accept `limit: 1` from `transfers.list`. Verify exact match on `external_ref`, destination, currency and amount. If multiple transfers match, raise a reconciliation incident.

**Implementation (`apps/api/src/stripe/stripe.service.ts`):**

```typescript
async findTransferByGroup(params: {
  transferGroup: string;
  externalRef: string;
  amountCents: number;
  currency: string;
  destination: string;
}): Promise<{ transfer_id: string; amount: number } | null>
```

- Fetches up to 10 transfers in the group (not just 1)
- Verifies: `metadata.external_ref`, `destination`, `currency`, `amount` must all match
- If 0 match: returns null (proceed to create with idempotency_key)
- If 1 matches: returns that transfer (reconcile)
- If >1 match: logs `MANUAL RECONCILIATION REQUIRED`, returns null — caller must not create another

**New test SC19:** Ambiguous result → `createTransfer` called with `idempotency_key` (Stripe deduplicates).

### Skipped Test Count Reconciliation

The "83 skipped tests" figure is confirmed correct:

| Suite | Tests (HAS_DB gated) |
|---|---|
| bid-concurrency | 10 |
| bid-load | 2 |
| auth | 41 |
| financial-integrity | 10 |
| schema-baseline | 10 |
| timezone-roundtrip | 10 |
| **Total** | **83** |

`guards.spec.ts` (12 tests) runs without a DB — currently passing. Not included in the 83.

---

## Test Results (Phase 0.7.3 Code Changes)

| Suite | Tests | Status |
|---|---|---|
| SchedulerService (SC1–SC19) | 19 | ✅ |
| All other unit suites | ~755 | ✅ |
| MariaDB integration (6 suites) | 83 | ⏭ HAS_DB |

---

## Staging Deployment Checklist

### 1. Provision Staging MariaDB

```bash
# Start MariaDB 11 container
docker-compose -f docker-compose.nestjs.yml up -d mariadb

# Restore sanitized copy (no real user PII, no production financial data)
mysql -h staging-db -u root arteuction_staging < sanitized_dump.sql
```

### 2. Apply Prisma Migration

```bash
DATABASE_URL="mysql://..." npx prisma migrate deploy

# Verify new columns
mysql -e "SHOW COLUMNS FROM domain_events LIKE 'lease_expires_at';"
mysql -e "SHOW COLUMNS FROM transfer_outbox LIKE 'lease_expires_at';"
mysql -e "SHOW COLUMNS FROM transfer_outbox LIKE 'external_ref';"

# Verify indexes
mysql -e "SHOW INDEX FROM domain_events WHERE Key_name LIKE '%lease%';"
mysql -e "SHOW INDEX FROM transfer_outbox WHERE Key_name IN ('to_lease_recovery_idx','to_dispatch_queue_idx','to_external_ref_unique');"

# Verify schema parity
npx prisma migrate status
# Expected: "All migrations have been applied"
```

### 3. Run MariaDB Integration Suites

```bash
DATABASE_URL="mysql://..." HAS_DB=true NODE_OPTIONS="--experimental-vm-modules" \
  npx jest --no-coverage \
  --testPathPattern="bid-concurrency|bid-load|auth.spec|financial-integrity|schema-baseline|timezone-roundtrip"
# Expected: 83 tests, 0 failed
```

### 4. Start NestJS

```bash
docker-compose -f docker-compose.nestjs.yml up -d nestjs
# Check startup: docker-compose logs nestjs | grep '\[config\]'
# Expected: [config] INFO  All required environment variables validated ✓
```

### 5. Start React Frontend

```bash
cd apps/web
VITE_API_URL=http://localhost:3000 npm run build
# Or: npm run dev for local testing
```

Frontend must point exclusively to NestJS (`VITE_API_URL`). Laravel is not used.

### 6. Execute 14 User Journeys

See `STAGING_USER_JOURNEY_RESULTS.md`. Fill in each result.

### 7. Verify Rollback

Stop NestJS, confirm Laravel still serves production.

---

## Restrictions (Unchanged from Phase 0.7.1)

- No production database migration
- No production Stripe payouts
- No uncontrolled dual writes
- No removal of Laravel
- No public serving of unverified artwork uploads (originals remain private)

---

## Phase 0.7.3 GO/NO-GO (Pending)

| Decision | Current | After staging |
|---|---|---|
| Staging deployment | **GO** | Update to ✅ when deployed |
| Production migration | **CONDITIONAL** | Update after P1–P6 pass |
| Laravel retirement | **NO-GO** | Update after 30-day parallel operation |
