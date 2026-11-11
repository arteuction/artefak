# Domain Ownership Matrix — ARTeuCtion

_Generated: 2026-11-03. **Statuses rebased 2026-11-11** against `apps/api/src` (`origin/main` `00d9505e`). Reflects current state; update after each cutover._

**Status caveat:** IN PROGRESS means code + unit tests exist. No domain is READY FOR CUTOVER until the 83 `HAS_DB` tests, staging parity diff and per-domain acceptance tests in `PRODUCTION_CUTOVER_PLAN.md` pass. **Current Owner is Laravel for every row** — NestJS serves no production traffic.

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
| **Fulfillment (ship, confirm-delivery, close)** | Laravel (primary) | NestJS | IN PROGRESS | Close step + settlement trigger; settlement engine parity | Ship/deliver status flips exist in `auction.service.ts`; no close/settlement trigger |
| **Settlement (calculate, disburse, statement)** | Laravel (primary) | NestJS | IN PROGRESS | Cent-for-cent parity vs Laravel vectors; statement PDF | `SettlementService.settle/preview` + `SplitProfile` exist; parity not demonstrated; statement missing — highest financial risk |
| **Payout (artist, gallery)** | Laravel (primary) | NestJS | IN PROGRESS | Settlement parity; Stripe connected-account E2E | `artistPayouts`/`galleryPayouts`/`pendingTransfers` are real Prisma queries |
| **Ledger (admin view, refunds)** | Laravel (primary) | NestJS | NOT STARTED | Settlement engine; three-ledger invariant tests | Admin read routes are stubs |
| **Reconciliation (runs, mismatches)** | Laravel (primary) | NestJS | NOT STARTED | RunNightlyReconciliationCommand ported | All stubs |
| **Stripe Webhooks** | Laravel | NestJS | IN PROGRESS | Shadow mode + 30-day comparison with zero discrepancies; event-type coverage audit vs Laravel | `WebhookHandlerService` handles 5 event types with `stripe_event_id` dedup; shadow comparison not started |
| **Stripe Transfers** | Laravel | NestJS | IN PROGRESS | Settlement parity; real Stripe idempotency test | `createTransfer` + `dispatch-transfer-outbox` cron with lease claims |
| **Stripe Refunds** | Laravel | NestJS | MISSING | Refund/reversal dispatch implemented | NestJS only *records* `charge.refunded`; nothing creates refunds. `DispatchStripeReversal` is Laravel-only |
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
| **Search (artworks)** | Laravel (primary) | NestJS | IN PROGRESS | Meilisearch connected; relevance parity | `MeilisearchService` exists (search/index/delete); index sync on write and relevance parity unverified |
| **Venue** | Laravel (primary) | NestJS | IN PROGRESS | Map endpoint tested | Partial |
| **Evidence (admin)** | Laravel (primary) | NestJS | IN PROGRESS | Verify flow tested | Partial |
| **Artist Applications** | Laravel (primary) | NestJS | IN PROGRESS | Approve/reject workflow tested | Partial |
| **Watchlist** | Laravel (primary) | NestJS | IN PROGRESS | Full CRUD parity | `GET watchlist` exists in `profile.controller.ts`; write routes unverified |
| **ArtMetro (artifacts, routes, QR)** | Laravel (primary) | NestJS | NOT STARTED | ArtMetro product decisions | Stubs only |
| **Admin Panel** | Laravel (Filament) | TBD | MISSING | Product decision: React admin, keep Filament, or new tool | 7 Filament resources; no replacement built |
| **Queue Workers** | Laravel | NestJS | IN PROGRESS | Retry/dead-letter/monitoring design (DB outbox + cron vs BullMQ — needs ADR); no double-processing guard vs Laravel workers | No BullMQ. Outbox + `@nestjs/schedule` crons with lease claims cover transfers and domain events; derivatives and reversal jobs absent |
| **Scheduled Tasks** | Laravel | NestJS | IN PROGRESS | Remaining commands ported; 14-day parallel run | 5 crons exist (close lots, expire bid auth, domain events, transfer outbox, stale-claim recovery). Reconciliation, governance health, ops alerts, settlement-create absent |
| **File Storage (S3)** | Laravel | NestJS | IN PROGRESS | Real-bucket run; book upload/complete flow | Presign upload/download + quarantine→approve implemented; book-files upload-intent still a stub |
| **Image Processing** | Laravel | NestJS | MISSING | Sharp or equivalent; GenerateArtworkDerivativesJob ported | Stub — no real image processing |
| **Realtime / WebSocket** | Laravel (Reverb) | NestJS | IN PROGRESS | Reconnect + API reconciliation (INVARIANT 14); frontend protocol compatibility with Reverb | Socket.io `BidGateway` exists |
| **Email / Notifications** | Laravel (Postmark/Resend) | NestJS | IN PROGRESS | Trigger wiring (outbid, payment) + deliverability | `EmailService` (nodemailer) with 5 templates |
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
