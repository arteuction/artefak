# Domain Ownership Matrix — ARTeuCtion

_Generated: 2026-11-03. Reflects current state; update after each cutover._

**Ownership legend:**
- **Laravel** — Laravel is the sole authoritative writer. NestJS has no live implementation.
- **NestJS** — NestJS is the sole authoritative writer. Laravel is retired for this domain.
- **Laravel (primary)** — Laravel writes; NestJS has a read/stub implementation only.
- **Split** — Temporary state during cutover; must resolve to a single owner within the sprint.

**Status legend:**
- NOT STARTED — NestJS implementation is a stub or missing
- IN PROGRESS — NestJS partially implemented, not production-ready
- READY FOR CUTOVER — NestJS implementation complete, staging validated
- CUTOVER COMPLETE — Traffic moved, Laravel retired for this domain

---

| Domain | Current Owner | Target Owner | NestJS Status | Cutover Prerequisite | Notes |
|--------|--------------|-------------|---------------|---------------------|-------|
| **Auth (login/register/refresh/logout)** | Laravel (primary) | NestJS | IN PROGRESS | Token rotation parity test; bcrypt `$2y$` normalisation in prod | NestJS auth module complete; prod bcrypt prefix handling not verified |
| **Authorization / Policies** | Laravel (primary) | NestJS | IN PROGRESS | All 12 policy integration tests green against NestJS | 12 policies ported, needs production smoke test |
| **Profile (me, artist-profile, buyer-dashboard)** | Laravel (primary) | NestJS | IN PROGRESS | Frontend integration tests pass | Partial Prisma implementation |
| **Artist** | Laravel (primary) | NestJS | IN PROGRESS | Full CRUD parity + frontend integration | Partial |
| **Artwork (CRUD, evidence, rights, revisions)** | Laravel (primary) | NestJS | IN PROGRESS | S3 presign/confirm/derivatives working end-to-end | Core CRUD done; S3 operations are stubs |
| **Artwork (IIIF / Linked Art / EDM)** | Laravel (primary) | NestJS | IN PROGRESS | Conformance check command passes | Skeleton JSON responses; not conformant |
| **ArtLot** | Laravel (primary) | NestJS | IN PROGRESS | Purchase-now flow tested end-to-end | Core CRUD done; purchase-now needs Stripe |
| **Auction (read)** | Laravel (primary) | NestJS | IN PROGRESS | Parallel read comparison for 7 days | Prisma reads implemented |
| **Auction (admin: create/publish/open/close)** | Laravel (primary) | NestJS | NOT STARTED | Admin panel decision; settlement engine | Admin routes are stubs |
| **Bidding (place bid, max-bid, re-authorize)** | Laravel (primary) | NestJS | IN PROGRESS | Load test (50 concurrent, P95<200ms); BidConcurrencyService SELECT FOR UPDATE | Core bid placement done; max-bid/re-auth stubs |
| **Sell Now (offers, counter, accept, reject)** | Laravel (primary) | NestJS | IN PROGRESS | Checkout-session Stripe integration | Offer CRUD done; Stripe checkout stub |
| **Fulfillment (ship, confirm-delivery, close)** | Laravel (primary) | NestJS | NOT STARTED | Settlement engine; Stripe payout integration | All handlers are stubs |
| **Settlement (calculate, disburse, statement)** | Laravel (primary) | NestJS | NOT STARTED | Port SettlementCalculator + CoAuthorSplit + FundSplit; parity test vs historical transactions | Empty stub — highest financial risk |
| **Payout (artist, gallery)** | Laravel (primary) | NestJS | NOT STARTED | Settlement engine complete; Stripe connected account integration | Empty paged stub only |
| **Ledger (admin view, refunds)** | Laravel (primary) | NestJS | NOT STARTED | Settlement engine; three-ledger invariant tests | Admin read routes are stubs |
| **Reconciliation (runs, mismatches)** | Laravel (primary) | NestJS | NOT STARTED | RunNightlyReconciliationCommand ported | All stubs |
| **Stripe Webhooks** | Laravel | NestJS | MISSING | Shadow mode + 30-day comparison with zero discrepancies | Zero NestJS implementation; HandleStripeWebhook job in Laravel only |
| **Stripe Transfers** | Laravel | NestJS | MISSING | Settlement engine complete | DispatchStripeTransfer job in Laravel only |
| **Stripe Refunds** | Laravel | NestJS | MISSING | Settlement engine complete | DispatchStripeReversal job in Laravel only |
| **Collection** | Laravel (primary) | NestJS | IN PROGRESS | Full CRUD parity | Core CRUD done |
| **Consignment** | Laravel (primary) | NestJS | IN PROGRESS | Activate/approve/create-lot flows complete | Core done; lifecycle actions partial |
| **Dispute** | Laravel (primary) | NestJS | IN PROGRESS | Evidence attachment tested end-to-end | Core done |
| **Donation** | Laravel (primary) | NestJS | IN PROGRESS | Tax-receipt PDF generation; ZKPO compliance | Core CRUD done; tax-receipt is stub; no PDF |
| **Donation Recipients** | Laravel (primary) | NestJS | IN PROGRESS | Admin create/update tested | Partial |
| **Exhibition** | Laravel (primary) | NestJS | IN PROGRESS | SDG tagging tested | Partial |
| **Gallery** | Laravel (primary) | NestJS | IN PROGRESS | Staff management tested | Partial |
| **Governance (policies, consent, erasure)** | Laravel (primary) | NestJS | IN PROGRESS | GDPR erasure flow tested end-to-end | Partial; erasure stub |
| **Impact Projects** | Laravel (primary) | NestJS | IN PROGRESS | Evidence attachment tested | Partial |
| **Impact Events** | Laravel (primary) | NestJS | IN PROGRESS | Evidence attachment tested | Partial |
| **SDG Claims** | Laravel (primary) | NestJS | IN PROGRESS | Review workflow tested | Partial |
| **Institution** | Laravel (primary) | NestJS | IN PROGRESS | Artwork attachment tested | Partial |
| **Library (catalogue, entitlements, purchase)** | Laravel (primary) | NestJS | IN PROGRESS | Book checkout-session (Stripe) + download-url (S3) | Core done; Stripe + S3 stubs |
| **Book Files (upload, download)** | Laravel (primary) | NestJS | NOT STARTED | S3 presigned upload/download + entitlement gate | Stub — no real S3 |
| **Book Categories** | Laravel (primary) | NestJS | IN PROGRESS | Admin write tested | Partial |
| **Book Reviews** | Laravel (primary) | NestJS | IN PROGRESS | Moderation workflow tested | Partial |
| **Ownership Transfer** | Laravel (primary) | NestJS | IN PROGRESS | Settlement engine dependency | Partial |
| **Reserve (waive, counter-offer)** | Laravel (primary) | NestJS | IN PROGRESS | Auction state machine integration | Partial |
| **Search (artworks)** | Laravel (primary) | NestJS | IN PROGRESS | Meilisearch connected; relevance parity | Currently SQL LIKE only — no Meilisearch |
| **Venue** | Laravel (primary) | NestJS | IN PROGRESS | Map endpoint tested | Partial |
| **Evidence (admin)** | Laravel (primary) | NestJS | IN PROGRESS | Verify flow tested | Partial |
| **Artist Applications** | Laravel (primary) | NestJS | IN PROGRESS | Approve/reject workflow tested | Partial |
| **Watchlist** | Laravel (primary) | NestJS | NOT STARTED | Full CRUD | Stub |
| **ArtMetro (artifacts, routes, QR)** | Laravel (primary) | NestJS | NOT STARTED | ArtMetro product decisions | Stubs only |
| **Admin Panel** | Laravel (Filament) | TBD | MISSING | Product decision: React admin, keep Filament, or new tool | 7 Filament resources; no replacement built |
| **Queue Workers** | Laravel | NestJS | MISSING | BullMQ or equivalent; dead-letter queues; idempotency keys | 5 jobs with no NestJS equivalent |
| **Scheduled Tasks** | Laravel | NestJS | MISSING | NestJS scheduler; monitoring alerts | 7 critical commands; none ported |
| **File Storage (S3)** | Laravel | NestJS | NOT STARTED | AWS SDK integration; presign + confirm flow | All S3 calls are stubs returning placeholder URLs |
| **Image Processing** | Laravel | NestJS | MISSING | Sharp or equivalent; GenerateArtworkDerivativesJob ported | Stub — no real image processing |
| **Realtime / WebSocket** | Laravel (Reverb) | NestJS | MISSING | NestJS Gateway or Ably/Pusher; live bidding depends on this | Zero NestJS implementation |
| **Email / Notifications** | Laravel (Postmark/Resend) | NestJS | MISSING | Nodemailer or SDK; registration + bid + payment emails | Zero NestJS implementation |
| **PDF Generation** | Laravel (DomPDF) | NestJS | MISSING | Tax receipts, settlement statements | Zero NestJS implementation |
| **Activity Log** | Laravel (Spatie) | NestJS | MISSING | Audit trail for financial operations | Zero NestJS implementation |
| **Domain Events (outbox)** | Laravel | NestJS | NOT STARTED | DispatchDomainEventsCommand ported; outbox table | Stub |
| **Operations Console** | Laravel | NestJS | NOT STARTED | ZKPO report, fiscal year summaries, consumer lag | All stub |
| **Partner Onboarding / API Clients** | Laravel | NestJS | NOT STARTED | API key management | Stub |
| **GDPR Data Portability** | Laravel | NestJS | NOT STARTED | Export format specification | Stub |
| **Bid Re-authorization** | Laravel | NestJS | NOT STARTED | Stripe auth refresh flow | Stub |

---

## Cutover Sequence (Domain Order)

Recommended order (safest first, highest financial risk last):

1. Health check (already equivalent)
2. Public read-only routes: exhibitions, venues, impact projects, institutions, galleries, book catalogue
3. Authentication (NestJS auth complete — needs prod bcrypt fix first)
4. Profile / me
5. Artwork (read + create/update — after S3 presign works)
6. Artist applications, artist profiles
7. Collections, watchlist
8. Consignments, galleries, exhibitions, evidence
9. Dispute, ownership transfer, reserve
10. Donation, donation recipients, impact events/projects
11. Library (after Stripe checkout + S3 download work)
12. ArtLot, sell-now (after Stripe checkout works)
13. Auction (read, then bidding — after load test passes)
14. Queue workers, scheduled tasks (parallel run, then cutover)
15. Search (after Meilisearch connected)
16. Admin panel (after replacement decision and build)
17. Settlement, payout, reconciliation (last — after 30-day shadow comparison)
18. Stripe webhooks (last — after 30-day shadow)
