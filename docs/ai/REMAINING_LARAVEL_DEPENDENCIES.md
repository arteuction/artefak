# Remaining Laravel Dependencies — ARTeuCtion

_Generated: 2026-11-03. Based on direct inspection of routes/api.php, composer.json, app/Jobs/, app/Console/Commands/, app/Filament/, and NestJS service/controller source files._

---

## Section A: Laravel Responsibilities with NO NestJS Equivalent

These are not stubs with placeholder responses — they are entirely absent from the NestJS codebase.

### Financial Processing (Critical)

| Responsibility | Laravel Implementation | Why Critical |
|---------------|----------------------|-------------|
| Stripe payment processing | `stripe/stripe-php`, `DispatchStripeTransfer` job | Platform revenue depends on this |
| Stripe webhook routing | `HandleStripeWebhook` job | Missed webhooks = missed settlements, refunds |
| Stripe refund/reversal | `DispatchStripeReversal` job | Customer-facing financial correctness |
| Stripe bid re-authorization | `BidReAuthorizationController` | Expired Stripe authorizations on live bids |
| Stripe checkout sessions | `SellNowCheckoutController`, `LibraryController::checkoutSession` | Gate for purchases |
| Settlement calculation | `SettlementCalculator`, `CoAuthorSplit`, `FundSplit` | Core financial invariant |
| Payout dashboard | `PayoutController` | Artists/galleries cannot see earnings |
| Ledger view | `LedgerController` | Admin financial oversight |
| Reconciliation runs | `ReconciliationController`, `RunNightlyReconciliationCommand` | Financial integrity |
| PDF generation | `barryvdh/laravel-dompdf` | Tax receipts, settlement statements |

### Background Processing (Critical)

| Responsibility | Laravel Implementation | Risk if Missing |
|---------------|----------------------|----------------|
| Auction lot closing | `CloseAuctionItemJob`, `CloseExpiredLotsCommand` | Lots remain open past end time |
| Bid authorization expiry | `ExpireBidAuthorizations` | Expired Stripe auths pile up |
| Artwork derivative generation | `GenerateArtworkDerivativesJob` | Only original images served; thumbnails missing |
| Domain event dispatch | `DispatchDomainEventsCommand` | Outbox events never delivered |
| Nightly reconciliation | `RunNightlyReconciliationCommand` | Financial drift undetected |
| Operational alerts | `CheckOperationalAlerts` | Silent failures in production |

### Infrastructure (Critical)

| Responsibility | Laravel Component |
|---------------|------------------|
| WebSocket / realtime | `laravel/reverb` — live bid updates, lot status changes |
| Email notifications | Postmark/Resend via Laravel Mail — registration, bid outbid, payment receipt, book purchase |
| Admin panel | Filament 4.0 — 7 resources for artworks, users, consignments, auctions, disputes, reconciliation, transfer outbox |
| Image processing | `intervention/image` — resize, crop, watermark |
| Full-text search | `laravel/scout` + Meilisearch — relevance ranking, facets, typo tolerance |
| Activity log | `spatie/laravel-activitylog` — audit trail for all model mutations |

### Endpoints Not Implemented (Not Even Stubs)

| Route | Controller |
|-------|-----------|
| `GET /my/export` | DataPortabilityController |
| `POST /auction-items/:item/re-authorize` | BidReAuthorizationController |
| `POST /sell-now-offers/:offer/checkout-session` | SellNowCheckoutController |
| `POST /books/:book/checkout-session` | LibraryController |
| `GET /my-books/:book/download-url` | BookFileUploadController |
| `POST /books/:book/files/upload-intent` | BookFileUploadController |
| `POST /book-files/:file/complete` | BookFileUploadController |
| Watchlist (3 routes) | WatchlistController |
| Partner onboarding (2 routes) | PartnerOnboardingController, ApiClientController |
| GDPR erasure | GovernanceController::requestErasure |
| Fulfillment (5 routes) | FulfillmentController |

---

## Section B: NestJS Stubs That Need Real Implementation Before Cutover

These routes exist in NestJS and pass routing tests, but return hardcoded placeholder data.

### High Priority (Blocks core flows)

| Service | Method | What's Stubbed |
|---------|--------|---------------|
| `PayoutService` | `artistPayouts`, `galleryPayouts` | Returns `{ data: [], meta: { total: 0 } }` always |
| `ArtworkService` | `presignImage` | Returns placeholder string, no S3 call |
| `ArtworkService` | `generateDerivatives` | Throws ForbiddenException as placeholder |
| `LibraryService` | `bookDownloadUrl` | Returns placeholder string, no S3 call |
| `LibraryService` | `purchaseBook` | Returns `{ status: 'pending' }` always |
| `ArtworkService` | `getRights` | Returns `{ rights_statement: null }` always |
| `ArtworkService` | `updateRights` | Echoes input dto without DB write |
| `ArtworkService` | `getLinkedArt` | Skeleton JSON-LD, not spec-conformant |
| `ArtworkService` | `getEdm` | Skeleton EDM, not spec-conformant |
| `ArtworkService` | `getIiifManifest` | Skeleton IIIF, not spec-conformant |
| `DonationService` | `createDonation` | Not implemented (no `store` handler exists) |
| `DonationService` | `taxReceipt` | Not implemented |

### Medium Priority (Admin / ops flows)

| Domain | What's Stubbed |
|--------|---------------|
| All admin routes (35 routes) | Return empty arrays or hardcoded data |
| Operations console (9 routes) | Return empty objects |
| Reconciliation (6 routes) | Return empty arrays |
| Settlement (3 routes) | Return empty objects |
| Domain events (1 route) | Returns empty array |
| ArtMetro artifacts/routes (6 routes) | Return hardcoded sample data |

---

## Section C: External Integrations — Current State in Both Stacks

| Integration | Laravel State | NestJS State | Gap |
|-------------|--------------|-------------|-----|
| **Stripe Payments** | Fully implemented: checkout, transfer, refund, webhook, bid re-auth | Zero implementation | Entire payment layer missing |
| **AWS S3** | Fully implemented: presign, upload confirm, derivative storage, book files | Stub: returns placeholder URLs | All file operations missing |
| **Meilisearch** | Fully implemented via laravel/scout: artworks indexed, full-text search with relevance | SQL LIKE fallback only | Search quality and performance diverge significantly |
| **Reverb (WebSocket)** | Fully implemented: live bid events, lot status changes, auction state | Zero implementation | Real-time bidding UI will not work |
| **Postmark/Resend (Email)** | Fully implemented: registration, outbid, payment, book purchase emails | Zero implementation | All transactional emails missing |
| **Slack Notifications** | Configured via services.php; operational alerts | Zero implementation | Ops team won't receive alerts |
| **MariaDB** | Laravel Eloquent + Migrations | Prisma 7 + MariaDB adapter | Prisma migration baseline not yet generated (`prisma migrate diff` outstanding) |
| **Queue (Redis/DB)** | Laravel Queue with 5 job classes | Zero implementation | All background processing missing |
| **Scheduler** | Laravel Console Kernel with 15 commands | Zero implementation | All scheduled tasks missing |

---

## Section D: Updated Migration Effort Estimate

**Methodology:** Story points (1 SP ≈ 1 dev-day of focused work including tests). Assumes one experienced NestJS developer. Ranges reflect unknowns.

### Stage 2 — Functional Migration

| Work Item | Story Points | Confidence |
|-----------|-------------|-----------|
| Settlement engine port (SettlementCalculator + CoAuthorSplit + FundSplit + parity tests) | 8–13 | Medium — logic exists in Laravel, translation is well-defined but financial correctness requires careful test coverage |
| Stripe checkout sessions (sell-now + library) | 5–8 | Medium — Stripe SDK straightforward; session management needs care |
| Stripe webhook handler (routing, idempotency, shadow mode) | 8–13 | Low — webhook idempotency already solved in Laravel; NestJS port adds shadow comparison complexity |
| Stripe transfer dispatch (queue job) | 5–8 | Medium |
| Stripe refund/reversal (queue job) | 5–8 | Medium |
| Stripe bid re-authorization | 3–5 | Medium |
| S3 presigned upload/confirm (artworks) | 5–8 | Medium |
| S3 book file upload/download | 5–8 | Medium |
| Image derivative generation (Sharp + queue job) | 5–8 | Medium |
| Queue worker infrastructure (BullMQ, dead-letter, monitoring) | 8–13 | Low — BullMQ well-documented; setup takes time |
| Auction lot closing job | 3–5 | Medium |
| Bid authorization expiry job | 3–5 | Medium |
| Domain event dispatch (outbox) | 5–8 | Medium |
| NestJS scheduler (cron equivalent for 7 critical commands) | 5–8 | Medium |
| Meilisearch integration (replace SQL LIKE) | 5–8 | Medium |
| Realtime/WebSocket (NestJS Gateway or Ably) | 8–13 | Low — architectural decision needed first |
| Email notifications (registration, outbid, payment, book) | 5–8 | Medium |
| Admin panel replacement (decision + build) | 21–34 | Low — scope depends entirely on decision (React admin vs port Filament) |
| Reconciliation + ledger views (real implementation) | 8–13 | Medium |
| Payout dashboard (real implementation) | 8–13 | Medium |
| GDPR data portability + erasure | 5–8 | Medium |
| Fulfillment (ship, confirm, close) | 5–8 | Medium |
| Watchlist, partner onboarding, API clients | 5–8 | Medium |
| Remaining stubs (tax-receipt, rights, IIIF conformance, etc.) | 8–13 | High — well-defined scope |
| Prisma migration baseline | 2–3 | High |
| Concurrent bid load test (P95/P99 verification) | 3–5 | High |
| **Stage 2 Total** | **154–236 SP** | |

### Stage 3 — Production Cutover

| Work Item | Story Points |
|-----------|-------------|
| Staging environment setup | 5–8 |
| Automated response comparison tooling (Laravel vs NestJS diff) | 5–8 |
| Stripe shadow mode + 30-day comparison | 13–21 (elapsed time dominates) |
| Domain-by-domain cutover (30 domains × 1 SP per domain) | 21–34 |
| Monitoring and alerting setup | 5–8 |
| **Stage 3 Total** | **49–79 SP** |

### Total Remaining to Laravel Retirement

| Stage | Story Points |
|-------|-------------|
| Stage 2 | 154–236 |
| Stage 3 | 49–79 |
| Buffer (20%) | 41–63 |
| **Grand Total** | **244–378 SP** |

At 1 developer: **244–378 dev-days** (~49–76 weeks solo). With 2 developers working in parallel on independent domains: **~25–40 weeks**. The admin panel decision alone could consume 3–7 weeks.

**Honest note:** The 20-week estimate from earlier planning assumed more implementation completeness. The actual remaining work is larger. Do not commit to a timeline until Stage 2 scope is confirmed and the admin panel decision is made.

---

## Section E: Recommended Next Sprint

**Single highest-leverage action: Port the settlement engine.**

Why:
1. It unblocks payout, reconciliation, ledger, fulfillment, and sell-now checkout in one chain
2. It is purely financial domain logic — no external dependencies, no product decisions
3. The Laravel implementation already exists and is well-tested (`SettlementCalculator`, `CoAuthorSplit`, `FundSplit`)
4. It is the highest-risk domain (financial correctness, three-ledger invariant)
5. Completing it with parity tests proves NestJS can handle the most sensitive domain

**Sprint plan (estimated 8–13 dev-days):**
1. Port `SettlementCalculator` → `SettlementService.calculate(grossCents, splitProfile)`
2. Port `CoAuthorSplit` → co-author royalty split logic within SettlementService
3. Port `FundSplit` → platform/fund distribution with configurable percentages
4. Write comparison tests: 20 historical transaction vectors from Laravel test suite, assert identical output cent-for-cent
5. Add to `financial-integrity.spec.ts` — assert three-ledger separation is maintained
6. Wire into `PayoutService` so artist/gallery payout queries return real data
7. Wire into sell-now fulfillment (when an offer is accepted and paid, write to `ledger_entries`)

**After that (natural sequence):**
1. S3 presign/confirm (unblocks artwork creation and book file upload)
2. Stripe checkout session (unblocks sell-now and library purchases)
3. Queue infrastructure + CloseAuctionItemJob (unblocks auction lifecycle)
4. Admin panel decision (unblocks Stage 3 entry gate)
