# Laravel Exit Plan — ARTeuCtion

_Generated: 2026-11-03. Planning document only. No code changes, no production modifications._

---

## Executive Summary

ARTeuCtion currently runs on a Laravel 13 monolith that owns all production responsibilities: API, authentication, Stripe webhooks, Reverb WebSocket, Meilisearch, S3 file storage, Filament admin, queue workers, and 15 CLI commands. A NestJS 12 PoC has reached 258/258 API route parity and 554 passing tests, but the majority of those routes are backed by Prisma CRUD stubs — not complete business implementations. The path to Laravel retirement is domain-by-domain, not a big-bang cutover. Laravel remains the authoritative production writer until each domain's NestJS implementation satisfies measurable acceptance criteria and has survived a staging stabilisation period.

---

## Four Migration Stages

### Stage 1 — Foundation Complete ✅ (Current)
**Entry criteria:** NestJS skeleton exists, auth is implemented, route parity is demonstrated.  
**Exit criteria:**
- [x] 258/258 route parity
- [x] JWT auth + 12 Laravel policies reproduced
- [x] 554 tests passing (exit 0)
- [ ] Prisma migration baseline (`prisma migrate diff` against production schema) — **outstanding**
- [ ] 50-concurrent-bid load test (P95 < 200ms, P99 < 500ms) — **outstanding**
- [ ] Admin panel decision documented (Filament replacement or keep Laravel for admin)

### Stage 2 — Functional Migration
**Entry criteria:** Stage 1 exit criteria all green.  
**Exit criteria:**
- [ ] All STUB handlers replaced with real Prisma + business logic implementations
- [ ] Stripe webhook handler implemented in NestJS (shadow mode, comparison parity)
- [ ] S3 presigned-URL flows implemented (artwork images, book files)
- [ ] Meilisearch (or equivalent) connected in NestJS; SQL LIKE search replaced
- [ ] Reverb/WebSocket equivalent implemented (NestJS Gateway or external service)
- [ ] Queue worker equivalent implemented (BullMQ or similar)
- [ ] All 15 Laravel CLI commands have NestJS equivalents or are documented as ops-only tools that stay in Laravel temporarily
- [ ] Settlement calculator and split-profile rules engine ported (financial-integrity spec passes against NestJS implementation)
- [ ] Reconciliation, ledger, and payout views implemented
- [ ] GDPR data-portability and erasure endpoints implemented
- [ ] Frontend integration tests pass against NestJS staging

### Stage 3 — Production Cutover (Domain-by-Domain)
**Entry criteria:** Stage 2 complete; staging environment mirrors production data.  
**Exit criteria:**
- [ ] Each domain has passed production acceptance tests listed in PRODUCTION_CUTOVER_PLAN.md
- [ ] Stripe webhook transitioned to NestJS with shadow comparison complete (zero discrepancies)
- [ ] Queue workers and scheduled tasks running in NestJS for 7 days without failure
- [ ] Admin panel available (Filament migrated or replaced)
- [ ] All Laravel routes returning 4xx/5xx have been triaged
- [ ] Zero dual-write states active

### Stage 4 — Laravel Retirement
**Entry criteria:** Stage 3 complete; 30-day production stabilisation period elapsed.  
**Exit criteria:**
- [ ] All production traffic served by NestJS
- [ ] No active queue workers, schedulers, or webhooks in Laravel
- [ ] Data reconciliation sign-off from finance
- [ ] Laravel repo archived (not deleted), tagged `v1-final`
- [ ] DNS, load balancer, and Stripe dashboard point only to NestJS endpoint

---

## Laravel Dependency Inventory

### API Routes

| Category | Controller(s) | Route Count (approx) | NestJS Status |
|----------|--------------|---------------------|---------------|
| Auth | AuthController | 4 | COMPLETE |
| Artwork | ArtworkController, PublicArtworkController, ArtistPortfolioController, RightsController | 18 | PARTIAL (missing presign/confirm/derivatives S3) |
| ArtLot | ArtLotController | 7 | PARTIAL |
| Auction | V1AuctionController, BidController, BidReAuthorizationController | 8 | PARTIAL (max-bid, re-auth stubs) |
| Sell Now | SellNowOfferController, SellNowCheckoutController | 8 | PARTIAL (checkout-session = Stripe, stub) |
| Library | LibraryController, BookAuthorController, BookFileController, BookFileUploadController, BookCategoryController, BookReviewController | 22 | PARTIAL (download-url = S3 stub; checkout-session = Stripe stub) |
| Galleries | GalleryController, GalleryStaffController | 8 | PARTIAL |
| Consignments | ConsignmentController | 7 | PARTIAL |
| Collections | CollectionController | 6 | PARTIAL |
| Donations | DonationController, DonationRecipientController | 8 | PARTIAL (tax-receipt = stub) |
| Dispute | DisputeController | 6 | PARTIAL |
| Impact | ImpactProjectController, ImpactEventController, ImpactRecordController, ImpactEvidenceController | 12 | PARTIAL |
| SDG | SdgClaimController | 4 | PARTIAL |
| Governance | GovernanceController | 5 | PARTIAL |
| Payout | PayoutController | 3 | **STUB** (empty paged response) |
| Settlement | SettlementController | 3 | **STUB** |
| Reconciliation | ReconciliationController, ReconciliationMismatchController | 6 | **STUB** |
| Admin | AdminAuditLogController, LedgerController, SplitProfileController, AdminPayoutController, AdminConnectedPayoutController, AdminRulesetController, AdminTransferOutboxController, AdminAuctionController, AdminBookController, UserAdminController, ArtmetroAdminController | 35 | **STUB** |
| Fulfillment | FulfillmentController | 5 | **STUB** |
| Ownership Transfer | OwnershipTransferController | 2 | PARTIAL |
| Reserve | ReserveController | 3 | PARTIAL |
| Search | PublicArtworkController | 2 | PARTIAL (SQL LIKE only, no Meilisearch) |
| Exhibition | ExhibitionController | 5 | PARTIAL |
| Venue | VenueController | 4 | PARTIAL |
| Evidence | EvidenceController | 3 | PARTIAL |
| Profile | ProfileController, BuyerDashboardController, WatchlistController | 8 | PARTIAL |
| Domain Events | DomainEventController | 1 | STUB |
| Operations | OperationsController | 9 | STUB |
| ArtMetro | ArtifactController, ArtmetroRouteController | 6 | STUB |
| IIIF/Linked Art/EDM | IiifController, LinkedArtController, EuropeanaEdmController, IiifManifestController | 5 | PARTIAL (skeleton responses only) |
| Partner / API Clients | PartnerOnboardingController, ApiClientController | 3 | STUB |
| QR Codes | QrCodeController | 2 | PARTIAL |
| Institutions | InstitutionController | 4 | PARTIAL |
| GDPR | DataPortabilityController | 1 | STUB |

### Infrastructure

| Responsibility | Laravel Component | NestJS Equivalent | Status |
|---------------|------------------|-------------------|--------|
| Authentication | Sanctum + custom token rotation | Passport JWT + opaque refresh | COMPLETE |
| Authorization | 12 Policies | AuthorizationService (12 methods) | COMPLETE |
| Stripe Payments | stripe/stripe-php, DispatchStripeTransfer | Not implemented | MISSING |
| Stripe Webhooks | HandleStripeWebhook job | Not implemented | MISSING |
| Stripe Refunds | DispatchStripeReversal | Not implemented | MISSING |
| Stripe Checkout Sessions | SellNowCheckoutController, LibraryController | Stub only | STUB |
| WebSocket/Realtime | laravel/reverb | Not implemented | MISSING |
| File Storage (S3) | AWS SDK via Laravel Storage | Stub (presign returns placeholder) | STUB |
| Image Processing | intervention/image + GenerateArtworkDerivativesJob | Stub | STUB |
| Full-text Search | laravel/scout + Meilisearch | SQL LIKE fallback only | PARTIAL |
| Queue Workers | Laravel Horizon / artisan queue:listen | Not implemented | MISSING |
| Scheduled Tasks | app/Console/Kernel.php | Not implemented | MISSING |
| Email/Notifications | Postmark/Resend via Laravel Mail | Not implemented | MISSING |
| Admin Panel | Filament 4.0 (5 resources) | Not implemented | MISSING |
| Activity Log | spatie/laravel-activitylog | Not implemented | MISSING |
| PDF Generation | barryvdh/laravel-dompdf | Not implemented | MISSING |
| Health Monitoring | spatie/laravel-health + laravel/pulse | Health endpoint only | PARTIAL |
| OpenAPI Docs | dedoc/scramble | @nestjs/swagger (wired) | PARTIAL |
| Rate Limiting | ThrottlerModule (routes) | ThrottlerModule (auth only) | PARTIAL |
| GDPR Erasure | GovernanceController + DataPortabilityController | STUB | STUB |

### Queue Jobs

| Job | Purpose | NestJS Equivalent |
|-----|---------|-------------------|
| CloseAuctionItemJob | Closes auction items at scheduled end time | MISSING |
| DispatchStripeTransfer | Sends payout to Stripe connected accounts | MISSING |
| DispatchStripeReversal | Processes refund via Stripe | MISSING |
| GenerateArtworkDerivativesJob | Generates image thumbnails/derivatives via S3 | MISSING |
| HandleStripeWebhook | Routes incoming Stripe events | MISSING |

### CLI Commands

| Command | Purpose | NestJS Equivalent |
|---------|---------|-------------------|
| SettlementCreateCommand | Manual settlement trigger | MISSING |
| RunNightlyReconciliationCommand | Nightly financial reconciliation | MISSING |
| DispatchDomainEventsCommand | Processes outbox events | MISSING |
| RefundCreateTestCommand | Dev/test refund rehearsal | Low priority |
| ImportArtworkCsvCommand | Bulk artwork import | Low priority |
| PilotReadinessCommand | Pre-launch gate check | Low priority |
| SecurityAuditCommand | Security posture scan | Low priority |
| GovernanceHealthCommand | GDPR consent health check | MISSING |
| SupplyChainAuditCommand | Provenance/evidence audit | Low priority |
| ReleaseGateCommand | CI/CD release gate check | Low priority |
| CloseExpiredLotsCommand | Closes expired art lots | MISSING |
| ExpireBidAuthorizations | Marks expired Stripe auths | MISSING |
| PaymentRehearsalCommand | Payment flow smoke test | Low priority |
| CheckOperationalAlerts | Ops monitoring alerts | MISSING |
| CulturalDataConformanceCommand | IIIF/EDM conformance check | Low priority |

### Admin Panel (Filament 4.0)

| Resource | Purpose | NestJS/Replacement |
|----------|---------|-------------------|
| ArtworkResource | View/manage artworks + evidence | Admin API routes (stub) |
| UserResource | View/manage users + roles | Admin API routes (stub) |
| ConsignmentResource | View/manage consignments | Admin API routes (stub) |
| AuctionResource | View/manage auctions | Admin API routes (stub) |
| DisputeResource | View/manage disputes | Admin API routes (stub) |
| ReconciliationMismatchResource | View/resolve mismatches | Admin API routes (stub) |
| TransferOutboxResource | View transfer outbox | Admin API routes (stub) |

No Filament replacement has been built or decided. This is a blocking dependency for retirement.

---

## NestJS Completion Gaps (Stage 2 Blockers)

In priority order:

1. **Settlement engine** — `PayoutService` is an empty stub. The Laravel settlement calculator (SettlementCalculator + CoAuthorSplit + FundSplit) must be fully ported with parity tests. This is a financial invariant: three-ledger separation must be preserved.
2. **Stripe integration** — Checkout sessions, webhook handling, transfer dispatch, refund/reversal. Zero NestJS implementation exists. Entire payment flow depends on this.
3. **S3 file operations** — Presigned upload/download for artwork images and book files. Both `presignImage` and `bookDownloadUrl` return hardcoded placeholder strings.
4. **Queue workers** — Five jobs have no NestJS equivalent. `CloseAuctionItemJob` and `ExpireBidAuthorizations` are time-critical for auction integrity.
5. **Search** — NestJS uses SQL LIKE. Laravel uses Meilisearch. Full-text relevance, faceting, and performance will diverge unless Meilisearch is connected.
6. **WebSocket/Realtime** — No Reverb equivalent. Live bidding UI depends on realtime events.
7. **Admin panel** — Seven Filament resources have no replacement. Operations cannot manage the platform without this.
8. **Scheduled tasks** — Seven critical commands (reconciliation, lot closing, bid expiry, alerts) have no NestJS scheduler equivalent.
9. **Email/notifications** — Zero implementation. Registration confirmation, bid outbid notifications, payment receipts are all absent.
10. **Reconciliation and ledger views** — Admin financial oversight routes are all stubs.

---

## Risk Register

| # | Risk | Likelihood | Impact | Mitigation |
|---|------|-----------|--------|-----------|
| 1 | Dual-write data corruption during cutover | Medium | Critical | Single-writer rule enforced: each domain has exactly one authoritative writer at any time. No NestJS domain goes live until Laravel for that domain is behind a proxy redirect, not running in parallel write mode. |
| 2 | Settlement calculation divergence | Medium | Critical | Port Laravel's SettlementCalculator to NestJS with identical test vectors. Run comparison in shadow mode (log both results, diff) for 30 days before cutover. |
| 3 | Stripe webhook missed during transition | High | High | Keep Laravel webhook endpoint active until NestJS has processed ≥30 days of production events without discrepancy. Do not switch Stripe dashboard endpoint until shadow comparison is complete. |
| 4 | Admin panel gap blocks operations | High | High | Decide on replacement before Stage 2 starts: (a) keep Laravel for admin indefinitely as internal tool, (b) build React admin in frontend, (c) port Filament resources to a NestJS-backed admin. This is a hard dependency — do not retire Laravel until resolved. |
| 5 | Queue job failure silently corrupts financial state | Low | Critical | Implement dead-letter queues, idempotency keys (already present in Laravel), and ops alert commands before any financial job is migrated. |

---

## Measurable Acceptance Criteria for Retirement

Laravel may be retired only when ALL of the following are true:

1. All 258+ API routes return identical responses from NestJS staging versus Laravel staging for a 2-week parallel-run sample.
2. Settlement calculations match Laravel output to the cent for 100 randomly sampled historical transactions.
3. Stripe webhook delivery has been running through NestJS for ≥30 days with zero discrepancies logged.
4. All five queue jobs are running in NestJS with no failed jobs for ≥7 days.
5. All scheduled commands have NestJS equivalents and have run successfully for ≥14 days.
6. Admin panel fully operational (all seven Filament resources replaced).
7. S3 presigned URLs work end-to-end for both artwork images and book file downloads.
8. Meilisearch (or equivalent) connected and search relevance verified against Laravel baseline.
9. Email notifications working for: registration, bid outbid, payment receipt, book purchase.
10. Data reconciliation report signed off by finance: zero unresolved mismatches.
11. Rollback procedure tested: switching traffic back to Laravel takes < 5 minutes.
12. Load test: 50 concurrent bids, P95 < 200ms, P99 < 500ms, zero lost bids.
13. GDPR data portability and erasure endpoints verified against GDPR requirements.
14. Laravel repo tagged `v1-final`, archived on GitHub, and all CI/CD pipelines disabled.
