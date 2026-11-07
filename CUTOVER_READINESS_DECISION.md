# ARTeuCtion — Cutover Readiness Decision

**Date:** 2026-11-03 (updated continuously through Phase 0.7.3)

---

## Gate Status

| Gate | Description | Status |
|---|---|---|
| **P1** | `prisma migrate deploy` on staging MariaDB | ⏭ Pending |
| **P2** | 83 MariaDB integration tests passing | ⏭ Pending |
| **P3** | 100 concurrent bids: exactly 1 accepted | ⏭ Pending |
| **P4** | Two workers: no duplicate outbox processing | ⏭ Pending |
| **P5** | Stripe test-mode: all crash scenarios verified | ⏭ Pending |
| **P6** | 14 staging user journeys: all passing | ⏭ Pending |
| **P7** | Artwork uploads: originals not publicly served unverified | ✅ By policy |
| **P8** | Rollback rehearsal: successful recovery from staging state | ⏭ Pending |

---

## Current Verdict

| Domain | Decision | Rationale |
|---|---|---|
| **Continued NestJS development** | **GO** | Architecture sound; crash recovery complete |
| **Independent staging deployment** | **GO** | Infrastructure ready; `docker-compose.nestjs.yml` + migration exists |
| **Production traffic migration (selected domains)** | **CONDITIONAL** | After P1–P6 pass |
| **Laravel retirement** | **NO-GO** | Laravel remains production authority |

---

## What GO for Production Requires

1. All 8 gates above: ✅
2. Specific domains migrated in phases (see `DOMAIN_CUTOVER_MATRIX.md`):
   - Phase A: Read-only catalog endpoints (artworks, auctions listing)
   - Phase B: Authentication (new user registrations only)
   - Phase C: Bidding (after bid-concurrency suite passes)
   - Phase D: Payments (after Stripe test-mode end-to-end passes)
3. Dual-write off — no route writes to both Laravel and NestJS simultaneously
4. Laravel production traffic continues until NestJS staging acceptance complete

---

## What "First Convincing Demonstration" Means

Per the user's stated goal:

> Open ARTeuCtion's independent React frontend, connect it exclusively to NestJS, complete an actual auction, process a test payment and verify the corresponding settlement without Laravel participating in the workflow.

This is achieved when journeys J4 through J11 all pass in staging with:
- Frontend URL pointing at NestJS API (not Laravel)
- Stripe test-mode checkout completed
- Settlement recorded in NestJS DB
- Transfer dispatched and `stripe_transfer_id` written
- Laravel DB untouched

**That milestone is Phase 0.7.3's primary success criterion.**

---

## Financial Protection Commitments

Before any production cutover:

- [ ] `external_ref` unique constraint enforced in production DB
- [ ] `lease_expires_at` populated for all new outbox entries
- [ ] `recoverStaleClaims` running on all deployed instances
- [ ] Stripe dashboard monitoring configured for `transfer_group` anomalies
- [ ] Three-ledger invariant verified by `financial-integrity.spec.ts` suite
- [ ] No S3 quarantine bypass: `artworks.primary_image_status` never set to `active` until verified

---

## Laravel Retirement Criteria (Future)

1. All 14 staging journeys green in production-equivalent environment
2. 30 days of parallel operation: NestJS handles new transactions, Laravel serves existing
3. Zero financial discrepancies in NestJS ledger vs Laravel ledger
4. All 6 MariaDB integration suites passing in production DB replica
5. Redis WebSocket adapter deployed and tested under multi-instance load
6. S3 quarantine pipeline (M2) deployed and all existing artworks re-validated
7. Independent decision by user to proceed
