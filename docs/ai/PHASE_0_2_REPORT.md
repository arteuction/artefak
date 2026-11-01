# ARTeuCtion — Phase 0.2 Backend Replacement Validation Report

**Date:** 2026-11-01  
**Branch:** `test/phase-180-preservation-provenance`  
**Database:** MariaDB 11 — `arteuction_test` (local)  
**Stack under test:** NestJS 10 + Fastify + Prisma 6.19 + MariaDB (mysql connector)

---

## Executive Summary

**Recommendation: CONDITIONAL GO**

Phase 0.2 has validated that NestJS + Prisma + MariaDB can safely replace Laravel as the primary backend for ARTeuCtion, subject to the remaining gates listed in Section 5.

All 20 tests pass against the real database engine.  
Three critical bugs were found and fixed during testing (timezone skew, cross-item deadlock, supremum lock). These are documented below as architectural findings.

---

## 1. Auction Concurrency — 10/10 PASS

| # | Test | Result | Notes |
|---|------|--------|-------|
| 1 | First bid accepted, second at same amount rejected | PASS | |
| 2 | Two simultaneous identical bids — exactly one accepted | PASS | FOR UPDATE serializes correctly |
| 3 | Five simultaneous identical bids — exactly one accepted | PASS | All 4 losers throw BidRejected |
| 4 | Bids on different items execute independently | PASS | After fix — see Finding A |
| 5 | Bid on canceled item rejected | PASS | |
| 6 | Same bidder submitting same amount twice | PASS | |
| 7 | Failed bid leaves no partial rows (rollback) | PASS | |
| 8 | Correct outbid chain across three bidders | PASS | |
| 9 | Bid below starting_bid_cents rejected | PASS | starting_bid_cents from art_lots, not auction_items |
| 10 | Bid on auction with ends_at in the past rejected | PASS | After fix — see Finding B |

### Finding A — Cross-item deadlock under SERIALIZABLE

**Problem:** `SERIALIZABLE` isolation caused deadlocks between two concurrent transactions on *different* auction items. This happened because `SERIALIZABLE` promotes every read into a shared lock; the `$queryRaw ... FOR UPDATE` query on `auction_items` plus the subsequent `MAX(amount_cents)` on `bids` created overlapping lock ranges even for unrelated items.

**Fix:** Downgraded to `RepeatableRead` (MariaDB default for InnoDB). `SELECT ... FOR UPDATE` inside a `RepeatableRead` transaction still provides full row-level exclusive locking for the target row. This matches how Laravel's `lockForUpdate()` works in practice. Independent items now run without contention.

**Implication for production:** The Laravel PlaceBid job runs at the default `REPEATABLE READ` isolation. The NestJS service now matches that behaviour exactly.

### Finding B — Timezone skew in ends_at comparison

**Problem:** Comparing `new Date() >= new Date(item.ends_at)` in Node.js produced incorrect results. MariaDB returned timestamps in a timezone that differed from the Node.js process clock by ~2 hours (UTC vs local). A bid on an already-ended auction was accepted.

**Fix:** The `ends_at` comparison is now done server-side: `(NOW() >= a.ends_at) AS auction_ended`. The DB server evaluates whether the auction has ended using its own clock — the same clock that wrote the `ends_at` value. This matches how Laravel's `isOpenForBidding()` works (`$this->auction->ends_at->isFuture()`).

**Implication for production:** Any time-sensitive comparison that involves DB-stored timestamps must be evaluated on the DB server, not in the application process. This is especially important in deployments where the application server and DB server are in different time zones.

### Finding C — Supremum lock on empty bids range

**Problem:** When two concurrent transactions each called `bid.updateMany({ where: { auction_item_id: X } })` with no prior rows (first bids on each item), InnoDB acquired a supremum pseudo-record lock on the entire `bids` table. Even though the two items were different, the lock ranges overlapped, causing a deadlock.

**Fix:** `updateMany` is now skipped when `currentBest === null` (no prior accepted bids). This avoids touching the `bids` table for update when there is nothing to update, eliminating the supremum lock.

**Implication for production:** Avoid unconditional `updateMany` on sparse tables in hot transaction paths. Wrap with an existence check or use a conditional update.

---

## 2. Financial Integrity — 10/10 PASS

| # | Invariant | Result |
|---|-----------|--------|
| I1 | Three-ledger separation (ledger_entries ≠ donations ≠ impact_events) | PASS — verified at schema level |
| I2 | Stripe webhook idempotency (UNIQUE stripe_event_id) | PASS — DB constraint enforced |
| I3 | Split profile bps sum = 10 000 | PASS — both canonical profiles verified |
| I4 | Donation idempotency_key uniqueness | PASS — DB UNIQUE constraint |
| I5 | Impact event idempotency_key uniqueness | PASS — DB UNIQUE constraint |
| I6 | Ledger entry idempotency_key uniqueness | PASS — DB UNIQUE constraint |
| I7 | Settlement integer arithmetic (no floating-point drift) | PASS — verified across 5 edge amounts |
| I8 | Canonical split profiles library_80_10_10 and social_pilot_45_45_10 seeded | PASS |
| I9 | Donation reversal: paired row, original status immutable | PASS |
| I10 | Transaction rollback: mid-write failure leaves no partial ledger rows | PASS |

---

## 3. Database Compatibility

### Schema introspection

- **89 models** introspected via `prisma db pull` from the real `arteuction_test` database.
- Prisma client generated successfully with no warnings.
- TypeScript compiles cleanly against the generated client.

### ENUM coverage verified

The following ENUM columns were confirmed compatible with Prisma's String mapping (Prisma maps MySQL ENUMs to `String` by default; the app layer enforces valid values):

| Table | Column | Values (sample) |
|-------|--------|----------------|
| auctions | status | draft, published, live, closed, canceled |
| auction_items | status | pending, open, reserve_not_met, sold, passed, canceled |
| bids | status | pending, accepted, outbid, retracted, won |
| bids | bid_type | live, pre_bid, proxy, kiosk, mobile, admin_override |
| donations | type | donation, reversal |
| donations | status | pending, confirmed, reversed |
| impact_events | metric | sale_amount_eur, donation_amount_eur, audience_reach, artworks_exhibited, artworks_sold, books_sold, donors_count |
| ledger_entries | type | credit, debit |
| settlements | status | pending, completed, refunded, partially_refunded |
| settlement_lines | recipient_type | artist, fund, ops, author, co_author |
| users | role | buyer, artist, admin |

Full ENUM list: 62 enum columns across 37 tables. All confirmed present in introspected schema.

### BIGINT handling

- `auction_items.id`, `bids.id`, `settlements.id` — all `BIGINT UNSIGNED` → Prisma `BigInt`
- `bids.amount_cents`, `bids.auction_item_id` — `BIGINT` → `BigInt`
- Return type of `placeBid` corrected from `number` to `bigint` after first typecheck

### Critical FK discovery

`auction_items.art_lot_id` is **NOT NULL** in the real schema (the PoC Prisma schema had it nullable). This means every auction item must reference an `art_lots` row. The `starting_bid_cents` that the bidding logic reads comes from `art_lots`, never from `auction_items`.

### Timestamp handling

See Finding B above. All timestamp comparisons involving auction state must be evaluated on the DB server.

---

## 4. Remaining Gates (from the Phase 0.2 specification)

### Gate 1 — Auction reliability: COMPLETE
All 10 tests pass. The three critical bugs found and fixed strengthen confidence in the approach.

### Gate 2 — Financial integrity: COMPLETE  
All 10 invariant tests pass. The three-ledger separation, idempotency, and rollback guarantees are enforced at the DB level.

### Gate 3 — Database parity: PARTIALLY COMPLETE
- ✅ Full schema introspection (89 models)
- ✅ ENUM values verified
- ✅ FK constraints verified
- ✅ BIGINT handling corrected
- ⏳ Migration generation (baseline) — not yet run (`prisma migrate diff` needed)
- ⏳ Index verification — introspected but not audited against Laravel migrations

### Gate 4 — Platform compatibility: NOT STARTED
- ⏳ Authentication (Sanctum → NestJS JWT/Passport)
- ⏳ Authorization (Policies → Guards + Decorators)
- ⏳ Reverb WebSocket equivalent
- ⏳ Meilisearch integration
- ⏳ S3 file storage
- ⏳ Background jobs (Queue → BullMQ)
- ⏳ Health checks
- ⏳ Structured logging (Pino)
- ⏳ Admin panel

---

## 5. Risks and Open Questions

| Risk | Severity | Status |
|------|----------|--------|
| Timezone: any remaining timestamp comparisons in app layer | HIGH | Mitigated by Finding B fix; audit needed |
| Prisma ENUM: Prisma maps all MySQL ENUMs to `String`; invalid values bypass compile-time check | MEDIUM | Mitigate with Zod validation on inputs |
| Deadlock under high concurrency: repro'd with 5 simultaneous bids; production may see more | MEDIUM | Finding A/C fixes applied; load test at 50 concurrent bids needed |
| Migration baseline: `prisma migrate diff` vs real schema may reveal columns Prisma generated differently | MEDIUM | Run before any migration |
| Advisory locks: not present in current PoC; check if Laravel uses `getlock()`/`releaselock()` elsewhere | LOW | Grep Laravel codebase for `getPdo()->query("SELECT GET_LOCK")` |
| art_lot_id NOT NULL: PoC Prisma schema had it nullable; mismatch caught — other nullable mismatches may exist | LOW | Full diff needed |

---

## 6. Updated Migration Estimate

The original estimate was 22 weeks. Based on Phase 0.2 findings:

| Phase | Scope | Revised Estimate |
|-------|-------|-----------------|
| 0.3 Authentication + Authorization | JWT/Passport, Guards, all 12 policies | 2 weeks |
| 1. Library domain | Books, authors, entitlements, search | 2 weeks |
| 2. Auction domain | Full PlaceBid, AuctionItem, Bids, Stripe Payments | 3 weeks |
| 3. Financial domain | Settlements, Ledger, Donations, Impact | 3 weeks |
| 4. Artwork + Provenance | Artworks, Evidence, Revisions, IIIF | 2 weeks |
| 5. Gallery + Artmetro | Galleries, Exhibitions, Artmetro routes | 2 weeks |
| 6. Platform services | Reverb, Meilisearch, S3, Jobs, Admin | 3 weeks |
| 7. Parallel run + cutover | Both stacks live, traffic migration, retirement | 3 weeks |
| **Total** | | **~20 weeks** |

The 20-week figure assumes: one engineer, no scope changes, Laravel remains in parallel read-only mode during phases 1–6.

---

## 7. GO / CONDITIONAL GO / NO-GO

**CONDITIONAL GO** for the full backend migration.

Conditions:
1. Gate 3 completion: `prisma migrate diff` baseline must show zero destructive changes to the production schema.
2. Gate 4 start: Authentication and authorization must pass parity tests before any domain beyond Library is migrated.
3. Finding B protocol: A linting rule or test helper must enforce that all timestamp comparisons involving DB-stored values use DB-side evaluation (no `new Date(row.timestamp)` comparisons in service code).
4. Load test: 50 concurrent bids on one auction item, sustained for 30 seconds, must show zero double-accepts and an acceptable deadlock retry rate (< 5%).

Laravel must remain operational and serving production traffic until Gate 4 is fully complete and a parallel-run period has passed.
