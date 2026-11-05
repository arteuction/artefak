# ARTeuCtion — Phase 0.8 Hard Blocker Status

**Branch:** `test/phase-180-preservation-provenance`
**Last updated:** 2026-11-05

---

## Summary

| # | Blocker | Status | Resolvable without staging |
|---|---|---|---|
| P0.1 | Migration and schema ownership | PARTIAL — see below | Partially |
| P0.2 | Real MariaDB integration (83 tests) | BLOCKED — needs live DB | No |
| P0.3 | Legacy bcrypt compatibility | **RESOLVED** (Phase 0.8) | Yes |
| P0.4 | Stripe end-to-end rehearsal | BLOCKED — needs Stripe test mode + staging | No |
| P0.5 | Traffic switch and rollback design | NOT STARTED | Yes (design only) |

---

## P0.1 — Migration and schema ownership

### Actual state (verified from Git HEAD)

Contrary to the initial checklist claim, `apps/api/prisma/migrations/` **does exist**:

```
apps/api/prisma/migrations/
  20261103000001_add_outbox_lease_columns/
    migration.sql
apps/api/prisma/schema.prisma
```

One NestJS-originated migration exists: `20261103000001_add_outbox_lease_columns` (adds lease columns to the transfer outbox table).

### Migration authority decision

**During transition: Laravel remains the migration authority.**

- 116 Laravel migrations define the entire schema
- Prisma introspects the resulting schema via `prisma db pull`
- The one NestJS migration (`add_outbox_lease_columns`) must be applied to the Laravel-managed DB **before** NestJS starts, then tracked in both systems

### What must be done before staging

1. Apply the NestJS outbox migration to `arteuction_test`:
   ```bash
   DATABASE_URL="mysql://root:...@127.0.0.1:3306/arteuction_test" \
     npx prisma migrate deploy
   ```

2. Verify Prisma schema matches the live DB (Prisma 7 syntax — `--from-schema-datasource` was removed):
   ```bash
   DATABASE_URL="mysql://root:...@127.0.0.1:3306/arteuction_test" \
     npx prisma migrate diff \
     --from-config-datasource \
     --to-schema=prisma/schema.prisma \
     --exit-code
   ```
   - Exit 0: in sync
   - Exit 2: differences detected — schema must be regenerated with `prisma db pull`
   - Exit 1: command error

3. Run `schema-baseline.spec.ts` against `arteuction_test` to verify all critical columns.

### Note on `prisma migrate diff` coverage

Prisma's schema comparison does **not** cover all database features. After `migrate diff`, separately verify:
- Triggers (none currently expected)
- Views (none currently expected)
- Full-text indexes (`artworks` FULLTEXT index — created in migration 000077)
- ENUMs (MariaDB ENUMs are defined in migrations, Prisma maps them as strings)

---

## P0.2 — Real MariaDB integration (83 tests)

### Status: BLOCKED

All 83 tests are gated on `HAS_DB = Boolean(process.env.DATABASE_URL)` and skip without a live connection.

### What to run

```bash
DATABASE_URL="mysql://root:...@127.0.0.1:3306/arteuction_test" \
  npx cross-env NODE_OPTIONS=--experimental-vm-modules \
  npx jest --no-coverage 2>&1
```

### Critical suites

| Suite | Tests | What breaks if it fails |
|---|---|---|
| `financial-integrity.spec.ts` | I1–I10 | Three-ledger separation, idempotency, rollback atomicity |
| `auth.spec.ts` | A1–A14 + P1–P12 | Real login/register/rotation/reuse-detection |
| `schema-baseline.spec.ts` | column assertions | Prisma/MariaDB drift detection |
| `bid-load.spec.ts` | 50-concurrent | `SELECT FOR UPDATE NOWAIT` under real contention |
| `timezone-roundtrip.spec.ts` | datetime | MariaDB timezone handling |

**None of these have run against `arteuction_test`.** This is the most significant unknown before production.

---

## P0.3 — Legacy bcrypt compatibility

### Status: RESOLVED (Phase 0.8, commit on this branch)

**Problem:** PHP's `password_hash()` produces `$2y$` prefixed bcrypt hashes. Node's `bcrypt` package
silently returns `false` for `$2y$` hashes. Without a fix, every existing Laravel user gets a silent
401 when logging in through the NestJS auth layer.

**Proof (run interactively):**
```
bcrypt.compare('password123', '$2y$12$...')  → false   (broken)
bcrypt.compare('password123', '$2b$12$...')  → true    (works)
```

**Fix implemented:** `normalizeBcryptHash()` in `auth.service.ts` — rewrites `$2y$` → `$2b$`
in memory before comparison. The stored hash in MariaDB is **never modified**.

```typescript
function normalizeBcryptHash(hash: string): string {
  return hash.startsWith('$2y$') ? '$2b$' + hash.slice(4) : hash;
}

// Used at line of comparison:
const valid = await bcrypt.compare(password, normalizeBcryptHash(user.password));
```

**Tests proving the fix (AS18–AS20 in `auth.service.spec.ts`):**
- AS18: PHP `$2y$` hash + correct password → authenticates ✓
- AS19: PHP `$2y$` hash + wrong password → UnauthorizedException ✓
- AS20: Standard `$2b$` hash + correct password → still authenticates ✓

**What still needs staging verification:** A10 in `auth.spec.ts` tests `$2y$` hash compatibility
against the real DB with an actual PHP-generated hash. This requires `DATABASE_URL` to be set.

---

## P0.4 — Stripe end-to-end rehearsal

### Status: BLOCKED — needs Stripe test mode + staging DB

The full path to verify:

1. POST `/api/v1/auctions/{id}/items/{itemId}/checkout-session` → Stripe creates session
2. Customer completes payment → Stripe sends `checkout.session.completed` to webhook endpoint
3. `StripeWebhookController` verifies signature (`STRIPE_WEBHOOK_SECRET`)
4. `WebhookHandlerService` deduplicates by `stripe_event_id` (unique constraint in `webhook_events`)
5. `SettlementService.createSettlement()` writes to `settlements` + `settlement_lines`
6. `LedgerEntryService` writes to `ledger_entries` (not `donations` or `impact_events`)
7. `SchedulerService` picks up transfer outbox item and dispatches via `StripeService.createTransfer()`
8. Stripe transfer created with idempotency key
9. Test: send duplicate `checkout.session.completed` → second write rejected by UNIQUE constraint

**Stripe CLI command for local webhook replay:**
```bash
stripe listen --forward-to localhost:3000/api/v1/webhooks/stripe
stripe trigger checkout.session.completed
```

---

## P0.5 — Traffic switch and rollback design

### Status: NOT STARTED

See separate `TRAFFIC_SWITCH_AND_ROLLBACK.md` (to be written).

Key decisions needed:
- Route ownership matrix (which routes NestJS owns vs Laravel)
- Single-writer guarantee per business operation
- Scheduler and webhook handler ownership (must not run in both simultaneously)
- In-flight request draining
- Database compatibility during rollback (NestJS-written records must be readable by Laravel)

---

## Other fixes in Phase 0.8

| Item | Status |
|---|---|
| `APP_URL` missing in production now warns at startup (CV16) | Done — `config-validation.service.ts` |
| `MAIL_HOST`, `MEILISEARCH_HOST`, `APP_URL` all warn in production | Done |
