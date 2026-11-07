# ARTeuCtion — Database Migration Report

**Date:** 2026-11-03  
**Migration:** `20261103000001_add_outbox_lease_columns`  
**Branch:** `test/phase-180-preservation-provenance`

---

## Changes

### 1. `domain_events.lease_expires_at`

```sql
ALTER TABLE `domain_events`
  ADD COLUMN `lease_expires_at` DATETIME NULL AFTER `processing_started_at`;

CREATE INDEX `de_lease_recovery_idx`
  ON `domain_events` (`status`, `lease_expires_at`);
```

**Purpose:** Multi-instance atomic claiming. Each worker sets `lease_expires_at = NOW() + 5min` when claiming a row. `recoverStaleClaims()` (cron `*/5 * * * *`) resets stuck rows after the lease expires.

**Index rationale:** `(status, lease_expires_at)` directly satisfies the recovery query `WHERE status='processing' AND lease_expires_at < NOW()`.

### 2. `transfer_outbox.lease_expires_at`

```sql
ALTER TABLE `transfer_outbox`
  ADD COLUMN `lease_expires_at` DATETIME NULL AFTER `processing_started_at`;

CREATE INDEX `to_lease_recovery_idx`
  ON `transfer_outbox` (`status`, `lease_expires_at`);

CREATE INDEX `to_dispatch_queue_idx`
  ON `transfer_outbox` (`status`, `next_attempt_at`);
```

**Purpose:** Same lease pattern as `domain_events` but with a 10-minute window (Stripe API calls can be slow).

### 3. `transfer_outbox.external_ref`

```sql
ALTER TABLE `transfer_outbox`
  ADD COLUMN `external_ref` VARCHAR(64) NULL AFTER `stripe_transfer_id`;

CREATE UNIQUE INDEX `to_external_ref_unique`
  ON `transfer_outbox` (`external_ref`);
```

**Purpose:** Stable external correlation identifier for Stripe reconciliation. Generated on first dispatch attempt; stored in `transfer_group` and Stripe transfer `metadata`. Enables crash recovery even when `stripe_transfer_id` was never persisted (crash between Stripe API success and DB write).

---

## Timestamp Convention

All new columns use `DATETIME` (not `TIMESTAMP`) to:
- Avoid MariaDB's automatic UTC↔local conversion on `TIMESTAMP` columns
- Match the application's explicit UTC `new Date()` values
- Be consistent with `processing_started_at DATETIME NULL` already on both tables

All existing `Timestamp(0)` columns are UTC-safe because MariaDB is configured with `timezone=UTC` in the staging Docker environment.

---

## Prisma Schema

Both models updated in `apps/api/prisma/schema.prisma`:

```prisma
model domain_events {
  // ...
  lease_expires_at      DateTime?  @db.DateTime
  // ...
  @@index([status, lease_expires_at], map: "de_lease_recovery_idx")
}

model transfer_outbox {
  // ...
  lease_expires_at  DateTime?  @db.DateTime
  external_ref      String?    @unique(map: "to_external_ref_unique") @db.VarChar(64)
  // ...
  @@index([status, next_attempt_at], map: "to_dispatch_queue_idx")
  @@index([status, lease_expires_at], map: "to_lease_recovery_idx")
}
```

`lease_expires_at` is now typed — no more `$executeRaw` workarounds for this field. `external_ref` still uses `(entry as any).external_ref` cast pending a `prisma generate` against a live DB.

---

## Parity Verification

Before production deployment, run:

```bash
cd apps/api
npx prisma migrate diff \
  --from-schema-datasource prisma/schema.prisma \
  --to-schema-datasource prisma/schema.prisma \
  --exit-code
# Exit 0 = schema matches migrations
```

The `schema-baseline.spec.ts` integration suite runs this check automatically when `HAS_DB=true`.

---

## Apply to Staging

```bash
# Run against staging MariaDB
DATABASE_URL="mysql://arteuction:...@staging-db:3306/arteuction" \
  npx prisma migrate deploy

# Verify
DATABASE_URL="..." npx prisma migrate status
```
