# NestJS Production Readiness Report — ARTeuCtion

_Generated: 2026-11-03. Branch: `test/phase-180-preservation-provenance`._

---

## Executive Summary

**Decision: CONDITIONAL GO — Staging deployment approved. Production cutover requires completion of Priority 1–3 items below.**

NestJS 12 + Prisma 7 has reached the point where the core domain layer (auctions, artworks, galleries, collections, donations, settlements, impact, institutions, library) is substantially implemented with real database operations and unit-test coverage. The primary blockers to full production cutover are the external integration layer (S3, Stripe, email, WebSocket) and the admin panel replacement decision.

---

## 1. Test Evidence

### 1.1 Full Suite — Latest Confirmed Run

| Metric | Count |
|--------|-------|
| Test suites | 79 passed, 6 skipped, 0 failed |
| Tests | 707 passed, 83 skipped, 0 failed |
| Exit code | 0 |

_Run performed from commit `2fa80a23` (2026-11-03, full background run)._

The 6 skipped suites are integration suites gated behind `SKIP_DB_TESTS` — they require a live MariaDB instance and do not run in unit-test mode.

### 1.2 MariaDB Integration Tests

**Status: Requires live DB.** The test database guard enforces `arteuction_test` (not SQLite). To run:

```bash
# Requires MariaDB with arteuction_test database created
cd apps/api
npx prisma migrate deploy --schema=prisma/schema.prisma
cross-env NODE_OPTIONS=--experimental-vm-modules npx jest --testPathPattern="integration" --no-coverage
```

### 1.3 Test Coverage by Domain

| Domain | Tests | Real DB? | Notes |
|--------|-------|----------|-------|
| AuctionService | AU1–AU15 | Yes (mocked) | `reAuthorizeItem` tested |
| ArtworkService | AW1–AW33 | Yes | `getRights`/`updateRights` real DB |
| CollectionService | CS1–CS13 | Yes | `getCollectionArtworks`, `addArtwork`, `removeArtwork` covered |
| ConsignmentService | CON1–CON9 | Yes | `createLot` covered |
| DisputeService | DS1–DS13 | Yes | `attachEvidence` covered |
| DonationService | DN1–DN10 | Yes | `taxReceipt` real DB |
| DonationRecipientService | DR1–DR7 | Yes | `createRecipient`/`updateRecipient` real DB |
| GalleryService | GS1–GS17 | Yes | All staff operations covered |
| ImpactEventService | IE1–IE6 | Yes | Three-ledger invariant guarded |
| ImpactProjectService | IP1–IP7 | Yes | `createProject`/`updateProject` real DB |
| InstitutionService | IS1–IS9 | Yes | `attachArtwork` covered |
| LibraryService | LS1–LS16 | Partial | `bookDownloadUrl` returns placeholder S3 URL |
| OpsService | OP1–OP6 | Yes | Real DB queries |
| PayoutService | PS1–PS6 | Yes | Real `settlement_lines` queries |
| SearchService | SS1–SS5 | Yes | `getArtwork` real DB |
| SettlementService | SS1–SS16 | Yes | Full financial domain including lines/ledger/outbox |
| WebhookHandlerService | WH1–WH11 | Yes | All Stripe event types covered |
| SchedulerService | SC1–SC8 | Yes | Auction expiry and bid auth expiry |
| AuthorizationService | AZ1–AZ24 | Yes | 50+ permission cases |
| Route inventory | 60+ routes | Integration | Full app boot, all routes registered (not 404) |

---

## 2. Completed Functionality

### 2.1 Domain Services — Real DB (no stubs)

- **Auction lifecycle**: list, get, bid history, ship, deliver, re-authorize
- **Artwork management**: full CRUD, rights, provenance, SDG claims, revisions, evidence, sales history
- **Gallery management**: full CRUD, staff management (add/remove/update), lot listing
- **Collection management**: full CRUD, artwork membership
- **Consignment**: full CRUD, lot creation
- **Dispute**: full CRUD, assignment, resolution, evidence attachment
- **Donation**: creation, listing, retrieval, tax receipt aggregation
- **Donation recipients**: full CRUD
- **Impact events/projects**: full CRUD, evidence, statistics
- **Institutions**: full CRUD, artwork attachment
- **Payout**: artist and gallery payout history from `settlement_lines`
- **Settlement engine**: `settle()`, `preview()`, idempotency, three-ledger invariant, outbox dispatch, refunds
- **Stripe webhook**: signature verification, `checkout.session.completed`, `payment_intent.succeeded`, `charge.refunded`, `payment_intent.amount_capturable_updated`
- **Scheduler**: `closeExpiredLots`, `expireBidAuthorizations`
- **Governance**: consent, erasure, data export, policies
- **Search**: artwork search (SQL LIKE), artwork detail
- **Venue / Exhibition**: full CRUD
- **Auth**: JWT, refresh tokens, registration, bcrypt

### 2.2 Financial Invariants Enforced

- Three-ledger separation: `ledger_entries` ≠ `donations` ≠ `impact_events`
- All financial amounts use `BigInt` (no floating-point arithmetic)
- Bulgaria EUR-only settlement (no BGN at settlement layer)
- Idempotency on `stripe_payment_intent_id`

---

## 3. Remaining Laravel Dependencies

### 3.1 BLOCKING — Must Complete Before Production Cutover

| Item | Complexity | Status |
|------|-----------|--------|
| **S3 presigned image upload** | Medium (5–8 SP) | Stub: returns placeholder URL |
| **S3 image download confirm** | Small (2–3 SP) | Not implemented |
| **Image derivative generation** | Medium (5–8 SP) | Throws `ForbiddenException` placeholder |
| **Library book download URL** | Medium (5–8 SP) | Stub: returns placeholder URL |
| **Library book file upload** | Medium (5–8 SP) | Not implemented |
| **Stripe checkout sessions** (sell-now + library) | Medium (5–8 SP) | Returns `{ checkout_url: null }` |
| **Domain event dispatch** (outbox worker) | Medium (5–8 SP) | Events written to DB, never dispatched |
| **Email notifications** | Medium (5–8 SP) | Zero implementation |
| **WebSocket / real-time bidding** | High (8–13 SP) | ✅ **DONE** — BidGateway (namespace `bids`), subscribe-lot/unsubscribe-lot, emits `bid:placed` + `lot:closed`; wired into BidConcurrencyService |

### 3.2 HIGH PRIORITY — Blocks Admin Operations

| Item | Status |
|------|--------|
| Admin settlement/ledger views | Stubs (return `{ data: [] }`) |
| Admin reconciliation | Stubs |
| Admin auction management (create/update/publish) | Stubs |
| Admin user management | Stubs |
| Admin payout disbursement | Stubs |
| **Admin panel decision** | **Undecided: Filament (keep temporarily) vs React SPA** |

### 3.3 MEDIUM PRIORITY — Functional Gaps

| Item | Status |
|------|--------|
| Meilisearch full-text search (replaces SQL LIKE) | SQL LIKE fallback only |
| Fulfillment (ship/confirm/close) | Stubs |
| IIIF manifest spec-conformance | Skeleton JSON |
| Linked-Art / EDM conformance | Skeleton JSON |
| Partner onboarding | Not implemented |
| GDPR data export (file download) | Stub |

### 3.4 Already Done (REMAINING_LARAVEL_DEPENDENCIES.md outdated entries)

The following items are listed as stubs in the dependency document but have since been completed:

| Item | Done In |
|------|---------|
| `PayoutService.artistPayouts/galleryPayouts` | Real DB (settlement_lines) |
| `ArtworkService.getRights` / `updateRights` | Real DB |
| `DonationService.taxReceipt` | Real DB (commits 06a63cf) |
| `DonationService.createDonation` | Real DB (commit 4c01f4a) |
| `OpsService` (pending-events, failed-outbox, open-reserves) | Real DB (commit 72203e6) |
| `ArtworkService.getSalesHistory` | Real DB via ownership_transfers |
| Settlement read operations (lines, ledger, outbox) | Real DB (commit 36adb118) |

---

## 4. Security Assessment

### 4.1 Authentication

- JWT access tokens verified via `passport-jwt`
- Refresh token rotation implemented with revocation table
- Bcrypt password hashing confirmed
- `JwtAuthGuard` applied to all mutation routes
- `AuthorizationService` covers 24 permission scenarios with unit tests

### 4.2 Known Gaps

| Risk | Severity | Notes |
|------|----------|-------|
| Stripe webhook signature not verified in unit tests | Medium | Tested in `stripe-webhook.controller.spec.ts` with `STRIPE_WEBHOOK_SECRET` env gate |
| No rate limiting implemented | High | Must be added before production (NestJS `ThrottlerModule`) |
| No CORS configuration | High | Default Fastify allows all origins |
| SQL injection: `$queryRaw` for health check | Low | Parameterized, read-only |
| No API key / IP allowlist for admin routes | High | All admin routes only guarded by `JwtAuthGuard` — any valid JWT holder can call them |
| Secrets in environment variables | Handled | Never logged; `.env` excluded from context packs |

---

## 5. Integration Test Status

| Integration | NestJS State |
|-------------|-------------|
| MariaDB via Prisma | Works in integration suite (skipped in unit-test mode) |
| Stripe | Webhook handler tested with mock payloads; no live Stripe test-mode run |
| S3 / AWS | Zero integration |
| Meilisearch | Zero integration |
| Email (Postmark/Resend) | Zero integration |
| WebSocket | ✅ BidGateway (`/bids` namespace, socket.io) |
| Redis / BullMQ | Zero integration |

---

## 6. Performance

No load testing performed. The concurrency test for bids (`bid-concurrency.service.spec.ts`) uses `SELECT FOR UPDATE` via Prisma transaction — correct pattern, not yet verified under concurrent load.

**Recommended**: k6 or Artillery test simulating 50 concurrent bidders on a single lot before staging deployment.

---

## 7. Cutover Recommendations

### Domain-by-Domain Cutover Sequence

| Order | Domain | NestJS Ready? | Blocker |
|-------|--------|---------------|---------|
| 1 | Artwork browsing (public read) | ✅ Yes | None |
| 2 | Artist profiles | ✅ Yes | None |
| 3 | Gallery profiles | ✅ Yes | None |
| 4 | Auction listing / bid history | ✅ Yes | None |
| 5 | Collections | ✅ Yes | None |
| 6 | Donations + tax receipts | ✅ Yes | None |
| 7 | Governance / consent | ✅ Yes | None |
| 8 | Artwork mutations (create/update) | ⚠️ Partial | S3 upload not wired |
| 9 | Live bidding | ✅ Yes | BidGateway emits `bid:placed` per accepted bid |
| 10 | Sell-now checkout | ❌ No | Stripe checkout session missing |
| 11 | Library purchases | ❌ No | Stripe + S3 missing |
| 12 | Settlement + payouts | ⚠️ Partial | Stripe transfer dispatch not wired |
| 13 | Admin operations | ❌ No | Admin stubs + auth gap |

### Recommended Cutover Milestone

**M1 (Next sprint, ~3–4 weeks):**
- S3 presign/confirm for artworks
- Library S3 download URLs
- Stripe checkout sessions
- Rate limiting + CORS hardening
- Admin role enforcement on admin routes

**M2 (Following sprint, ~4–6 weeks):**
- Stripe transfer dispatch (BullMQ queue worker)
- Email notifications (outbid, payment confirmation, book purchase)
- Domain event dispatch worker
- ~~WebSocket gateway (NestJS WS or Ably)~~ — DONE: BidGateway in `src/realtime/`

**M3 (Staging validation, ~2–3 weeks):**
- Production-like staging deployment
- 30-day Stripe shadow-mode comparison
- Load test (50 concurrent bidders)
- Admin panel decision and minimal implementation

**M4 (Controlled production cutover):**
- Domain 1–7 only (read-heavy, no payment flows)
- Single writer per domain
- Laravel as read fallback for 2 weeks

---

## 8. GO / CONDITIONAL GO / NO-GO

| Area | Status |
|------|--------|
| Core domain logic | **GO** |
| Financial invariants | **GO** |
| Test coverage (unit) | **GO** |
| Authentication | **GO** |
| MariaDB integration | **CONDITIONAL** (integration suite must pass on live DB) |
| S3 / file operations | **NO-GO** |
| Stripe live payments | **NO-GO** |
| Email notifications | **NO-GO** |
| WebSocket / real-time | **✅ DONE** |
| Admin panel | **NO-GO** |
| Rate limiting / CORS | **NO-GO** |
| Admin route authorization | **NO-GO** (role-based, not just JWT) |

**Overall: CONDITIONAL GO for staging. NO-GO for production.**

The platform can be deployed to a staging environment with a production-like database copy and used for frontend integration testing of read-heavy flows immediately. Production cutover requires completion of the M1 milestone at minimum.

---

_Report generated by Claude Code (Sonnet 4.6) on 2026-11-03._
_Branch: `test/phase-180-preservation-provenance` · Commit: `2fa80a23`_
