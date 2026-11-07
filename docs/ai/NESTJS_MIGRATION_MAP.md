# Laravel → NestJS Migration Map

> **Status**: Reference document — do NOT start mass code conversion.  
> Migrate one domain at a time. Preserve all business rules, ledger separation, and financial guarantees.  
> See `AGENTS.md` and `docs/ai/INVARIANTS.md` before touching any domain or financial code.

---

## 1. Executive Summary

ARTeuCtion is a Laravel 13.8 / PHP 8.4 monolith with ~150 API routes, 63 Eloquent models, 20+ domain namespaces, Stripe Connect payments, MariaDB 11, Meilisearch, Laravel Reverb (WebSockets), and a Filament admin panel. The frontend is React 19 + Vite, already partially decoupled from Laravel serving (`resources/frontend/`).

**Migration approach**: build NestJS alongside Laravel, migrate one domain at a time behind a reverse proxy, retire Laravel only after full parity is confirmed with integration tests. NestJS is preferred over AdonisJS for domain-oriented modular architecture and TypeScript-first tooling.

**Hardest parts**:
- Three-ledger financial invariant (`ledger_entries` / `donations` / `impact_events`) must carry over with the same transactional guarantees.
- Stripe Connect manual-capture + transfer outbox lease pattern is stateful and difficult to port atomically.
- Eloquent polymorphic relations (ActivityLog, DomainEvent) need explicit TypeORM discriminators.
- Laravel Reverb (WebSocket) → NestJS Gateway + `@nestjs/websockets`.
- Filament admin panel has no NestJS equivalent — keep Laravel alive for admin until a replacement is built.

---

## 2. Route Inventory (grouped by domain)

### Auth / Session
| Method | Path | Laravel Controller | Notes |
|--------|------|--------------------|-------|
| POST | `/api/v1/login` | `AuthController@login` | Sanctum token issue |
| POST | `/api/v1/logout` | `AuthController@logout` | Token revocation |
| GET | `/api/v1/user` | `AuthController@user` | Current auth user |
| POST | `/api/v1/register` | `AuthController@register` | |
| POST | `/api/v1/password/email` | Password reset | |
| POST | `/api/v1/password/reset` | Password reset confirm | |

### User / Profile
| Method | Path | Laravel Controller | Notes |
|--------|------|--------------------|-------|
| GET | `/api/v1/users/{user}` | `UserController@show` | |
| PATCH | `/api/v1/users/{user}` | `UserController@update` | |
| GET | `/api/v1/profile` | `ProfileController@show` | Split profiles |
| PATCH | `/api/v1/profile` | `ProfileController@update` | |
| POST | `/api/v1/profile/terms` | `ProfileController@acceptTerms` | |
| GET/POST/DELETE | `/api/v1/gdpr/*` | `GdprController` | Export, erasure |

### Artwork
| Method | Path | Laravel Controller | Notes |
|--------|------|--------------------|-------|
| GET | `/api/v1/artworks` | `ArtworkController@index` | Meilisearch-backed |
| POST | `/api/v1/artworks` | `ArtworkController@store` | |
| GET | `/api/v1/artworks/{slug}` | `ArtworkController@show` | Route key = slug; ETag |
| PATCH | `/api/v1/artworks/{slug}` | `ArtworkController@update` | |
| DELETE | `/api/v1/artworks/{slug}` | `ArtworkController@destroy` | SoftDelete |
| POST | `/api/v1/artworks/{slug}/image` | `ArtworkImageController` | S3 presign phase 1 |
| PUT | `/api/v1/artworks/{slug}/image/confirm` | `ArtworkImageController` | S3 confirm phase 2 |
| GET | `/api/v1/artworks/{slug}/revisions` | `ArtworkRevisionController` | |
| GET | `/api/v1/artworks/{slug}/provenance` | `ProvenanceController` | |
| POST | `/api/v1/artworks/{slug}/provenance` | `ProvenanceController@store` | |

### ArtLot / Consignment
| Method | Path | Notes |
|--------|------|-------|
| GET/POST | `/api/v1/art-lots` | sale_mode: auction \| sell_now |
| GET/PATCH/DELETE | `/api/v1/art-lots/{artLot}` | |
| POST | `/api/v1/art-lots/{artLot}/submit` | State machine transition |
| GET/POST | `/api/v1/consignments` | Consignment workflow |
| GET/PATCH | `/api/v1/consignments/{consignment}` | |
| POST | `/api/v1/consignments/{consignment}/approve` | |
| POST | `/api/v1/consignments/{consignment}/reject` | |
| POST | `/api/v1/consignments/{consignment}/changes` | Request changes |

### Auction / Bidding
| Method | Path | Notes |
|--------|------|-------|
| GET/POST | `/api/v1/auctions` | |
| GET/PATCH | `/api/v1/auctions/{auction}` | |
| POST | `/api/v1/auctions/{auction}/start` | State machine |
| POST | `/api/v1/auctions/{auction}/end` | Triggers SettleAuction |
| GET/POST | `/api/v1/auctions/{auction}/items` | |
| POST | `/api/v1/auction-items/{item}/bids` | PlaceBid — Stripe PI |
| GET | `/api/v1/auction-items/{item}/bids` | |
| GET | `/api/v1/bids/{bid}` | |
| DELETE | `/api/v1/bids/{bid}` | Retract bid |

### Sell Now / Offers
| Method | Path | Notes |
|--------|------|-------|
| POST | `/api/v1/art-lots/{artLot}/buy-now` | Immediate purchase |
| GET/POST | `/api/v1/art-lots/{artLot}/offers` | Counter-offer flow |
| GET/PATCH | `/api/v1/offers/{offer}` | |
| POST | `/api/v1/offers/{offer}/accept` | |
| POST | `/api/v1/offers/{offer}/reject` | |
| POST | `/api/v1/offers/{offer}/counter` | |

### Settlement / Financial
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/v1/settlements` | Ledger read |
| GET | `/api/v1/settlements/{settlement}` | |
| GET | `/api/v1/ledger` | `ledger_entries` read |
| GET | `/api/v1/reconciliations` | |
| POST | `/api/v1/reconciliations` | RunReconciliation |
| GET | `/api/v1/reconciliations/{run}` | |

### Donations
| Method | Path | Notes |
|--------|------|-------|
| GET/POST | `/api/v1/donations` | ЗКПО чл.31 |
| GET | `/api/v1/donations/{donation}` | |
| GET | `/api/v1/donations/fiscal-year` | DonorFiscalYearAssessment |
| GET/POST | `/api/v1/impact-events` | Separate ledger |
| GET | `/api/v1/impact-events/{event}` | |

### Library (Books)
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/v1/books` | `?featured=1`, `?q=` search |
| POST | `/api/v1/books` | |
| GET/PATCH/DELETE | `/api/v1/books/{book}` | slug routing |
| POST | `/api/v1/books/{book}/purchase` | |
| GET | `/api/v1/books/{book}/file` | S3 presigned download |
| GET/POST | `/api/v1/books/{book}/reviews` | |
| GET/PATCH/DELETE | `/api/v1/reviews/{review}` | |

### Gallery / Institution / Venue
| Method | Path | Notes |
|--------|------|-------|
| GET/POST | `/api/v1/galleries` | |
| GET/PATCH/DELETE | `/api/v1/galleries/{gallery}` | |
| GET/POST | `/api/v1/institutions` | |
| GET/PATCH/DELETE | `/api/v1/institutions/{institution}` | |
| GET/POST | `/api/v1/venues` | |
| GET/PATCH/DELETE | `/api/v1/venues/{venue}` | |
| GET/POST | `/api/v1/exhibitions` | |
| GET/PATCH/DELETE | `/api/v1/exhibitions/{exhibition}` | |

### IIIF / Linked Art / Metadata
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/v1/iiif/{artwork}/manifest` | IIIF Presentation API 3.0 |
| GET | `/api/v1/iiif/{artwork}/info.json` | IIIF Image API 3.0 |
| GET | `/api/v1/linked-art/{artwork}` | Linked Art JSON-LD |
| GET | `/api/v1/europeana/{artwork}` | Europeana EDM |
| GET | `/api/v1/artworks/{artwork}/schema-org` | Schema.org JSON-LD |

### Stripe Webhooks / Internal
| Method | Path | Notes |
|--------|------|-------|
| POST | `/stripe/webhook` | `HandleStripeWebhook` job |
| POST | `/api/v1/stripe/connect/onboard` | Connect onboarding |
| GET | `/api/v1/stripe/connect/status` | |

### Notifications / Activity / Disputes
| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/v1/notifications` | |
| POST | `/api/v1/notifications/{n}/read` | |
| GET | `/api/v1/activity` | Spatie ActivityLog |
| GET/POST | `/api/v1/disputes` | |
| GET/PATCH | `/api/v1/disputes/{dispute}` | |
| POST | `/api/v1/disputes/{dispute}/assign` | Staff-only |
| POST | `/api/v1/disputes/{dispute}/resolve` | Staff-only |

---

## 3. Domain Actions Inventory

Each Laravel action class maps to a NestJS service method (or dedicated service class for complex actions).

### Artwork
| Action | Key behavior |
|--------|-------------|
| `CreateArtwork` | Validates + persists; dispatches domain event |
| `UpdateArtwork` | Creates `ArtworkRevision` snapshot before update |
| `DeleteArtwork` | SoftDelete; cascades to ArtLot check |
| `UploadArtworkImage` | Phase 1: generates S3 presigned URL + pending record |
| `ConfirmArtworkImage` | Phase 2: marks confirmed, triggers derivative generation |
| `GenerateImageDerivatives` | Dispatched as job; stores JSON in `image_derivatives` |

### ArtLot / Consignment
| Action | Key behavior |
|--------|-------------|
| `CreateArtLot` | Links Artwork + Consignment; sets sale_mode |
| `SubmitArtLot` | State: draft → submitted |
| `ApproveLot` | State: submitted → approved |
| `CreateConsignment` | Binds artist/consignor, snapshot of split_profile_key |
| `RequestConsignmentChanges` | Fires `ConsignmentChangesRequested` event |
| `ApproveConsignment` | Fires notification |

### Auction / Bidding
| Action | Key behavior |
|--------|-------------|
| `CreateAuction` | Sets schedule; validates venue |
| `StartAuction` | State machine; validates items present |
| `EndAuction` | Triggers `SettleAuction` |
| `PlaceBid` | DB TX + `lockForUpdate` on AuctionItem; Stripe PI (manual-capture); domain event outbox; fires `BidPlaced` + `BidOutbid` post-commit |
| `RetractBid` | Cancels PI; state check |
| `SettleAuction` | Captures winning PIs; `SettlementCalculator`; `RecipientLines`; idempotent via `CreateSettlement` |

### Sell Now / Offers
| Action | Key behavior |
|--------|-------------|
| `BuyNow` | Immediate Stripe Checkout Session; locks ArtLot |
| `CreateOffer` | Starts counter-offer chain |
| `AcceptOffer` | Fires `OfferAccepted`; captures payment |
| `RejectOffer` | |
| `CounterOffer` | Validates offer chain depth |

### Settlement / Financial
| Action | Key behavior |
|--------|-------------|
| `CreateSettlement` | Idempotent; writes `settlements` + `recipient_lines` |
| `SettlementCalculator` | Pure: `artist = floor(gross × bps / 10000)`; ops absorbs rounding; EUR only |
| `DispatchStripeTransfer` | Lease pattern: `lockForUpdate` claims outbox row; Stripe API outside TX; 5 max attempts |
| `RunReconciliation` | Reads `settlements` + `transfer_outbox`; accepts Stripe totals as params (no direct Stripe call) |
| `ReconciliationSubChecks` | Per-PI delta comparison |

### Donation
| Action | Key behavior |
|--------|-------------|
| `RecordDonation` | Writes `donations` ledger; uses `DonationCalculator` |
| `DonationCalculator` | Pure; stores provisional `max_deductible_cents = donated_cents`; annual assessment applies profit ceiling |
| `DonorFiscalYearAssessment` | Applies ЗКПО чл.31 profit-based ceiling; `floor(profit × bps / 10000)` |
| `RecordImpactEvent` | Writes `impact_events` (separate ledger) |

### Outbox / Domain Events
| Action | Key behavior |
|--------|-------------|
| `AppendDomainEvent` | Writes `domain_events` inside caller's TX; idempotency key = xxh3 hash |
| `DomainEventConsumer` | Laravel event subscriber; fans out to handlers |

### Library
| Action | Key behavior |
|--------|-------------|
| `CreateBook` | |
| `PurchaseBook` | Stripe Checkout; writes `book_purchases` |
| `GenerateBookDownloadUrl` | S3 presigned URL; time-limited |
| `ReviewBook` | Recalculates `avg_rating` + `review_count` on Book |

### Metadata / Standards
| Action | Key behavior |
|--------|-------------|
| `BuildIiifManifest` | Presentation API 3.0 JSON |
| `BuildLinkedArtRecord` | JSON-LD; maps Artwork fields |
| `BuildEuropeanaEdm` | EDM XML/JSON |
| `BuildSchemaOrg` | Schema.org JSON-LD embedded in artwork show response |

### GDPR
| Action | Key behavior |
|--------|-------------|
| `ExportUserData` | Collects all user records into portable archive |
| `RequestErasure` | Soft-anonymizes; queues hard-delete job |

---

## 4. Model Inventory

### Core Financial Models (NEVER mix ledgers)
| Model | Table | Ledger | Key fields |
|-------|-------|--------|------------|
| `LedgerEntry` | `ledger_entries` | Primary | amount_cents, currency, type, reference_type/id |
| `Donation` | `donations` | Donation | donated_cents, deduction_bps, max_deductible_cents, basis |
| `ImpactEvent` | `impact_events` | Impact | amount_cents, idempotency_key |

### Settlement Models
| Model | Table | Key fields |
|-------|-------|------------|
| `Settlement` | `settlements` | gross_cents, currency, stripe_payment_intent_id |
| `RecipientLine` | `recipient_lines` | settlement_id, role, amount_cents |
| `TransferOutbox` | `transfer_outbox` | status, amount_cents, stripe_transfer_id, lease fields |
| `ReconciliationRun` | `reconciliation_runs` | period_start/end, delta_received/transferred, status |
| `ReconciliationCheck` | `reconciliation_checks` | run_id, check_type, status, mismatch detail |

### Auction / Bidding Models
| Model | Table | Key fields |
|-------|-------|------------|
| `Auction` | `auctions` | status, starts_at, ends_at; LogsActivity |
| `AuctionItem` | `auction_items` | auction_id, art_lot_id, reserve_cents |
| `Bid` | `bids` | auction_item_id, user_id, amount_cents, bid_type, max_bid_id, payment_status, authorization_expires_at |

### Artwork / ArtLot Models
| Model | Table | Key fields |
|-------|-------|------------|
| `Artwork` | `artworks` | slug (route key), SoftDeletes, Searchable (Scout), LogsActivity, image_derivatives JSON, license/rights |
| `ArtLot` | `art_lots` | sale_mode, split_profile_key, buy_now_price_cents, estimate_low/high_cents; LogsActivity |
| `ArtworkRevision` | `artwork_revisions` | Snapshot before each update |
| `Consignment` | `consignments` | artist_id, consignor_id, split_profile_key |
| `ProvenanceRecord` | `provenance_records` | artwork_id, chain of custody |

### User / Profile Models
| Model | Table | Key fields |
|-------|-------|------------|
| `User` | `users` | roles (string column), HasApiTokens (Sanctum), implements FilamentUser |
| `SplitProfile` | `split_profiles` | key (canonical: `library_80_10_10`, `social_pilot_45_45_10`), artist_bps, fund_bps, ops_bps |
| `UserProfile` | `user_profiles` | terms_version, GDPR fields |
| `ApiClient` | `api_clients` | SoftDelete |

### Library Models
| Model | Table | Key fields |
|-------|-------|------------|
| `Book` | `books` | SoftDeletes, is_free, is_featured, avg_rating, review_count; BelongsToMany categories/authors |
| `BookPurchase` | `book_purchases` | user_id, book_id, stripe_checkout_session_id |
| `BookReview` | `book_reviews` | rating, body |
| `BookCategory` | `book_categories` | |
| `BookAuthor` | `book_authors` | |

### Gallery / Institution Models
| Model | Table | Key fields |
|-------|-------|------------|
| `Gallery` | `galleries` | |
| `Institution` | `institutions` | |
| `Venue` | `venues` | type enum |
| `Exhibition` | `exhibitions` | venue_id, start/end dates |

### Outbox / Events / Notifications
| Model | Table | Key fields |
|-------|-------|------------|
| `DomainEvent` | `domain_events` | aggregate_type, aggregate_id, event_type, event_version, payload JSON, idempotency_key, status |
| `ConsumerInbox` | `consumer_inbox` | domain_event_id, consumer, status |
| `Notification` | `notifications` | Laravel default polymorphic |
| `ActivityLog` | `activity_log` | Spatie; subject_type/id polymorphic |

### Disputes
| Model | Table | Key fields |
|-------|-------|------------|
| `Dispute` | `disputes` | opener_id, subject_type/id (polymorphic), status, assignee_id |

---

## 5. Policy Inventory

All policies are registered in `AppServiceProvider` via `Gate::policy()`.

| Policy | Model | Key rules |
|--------|-------|-----------|
| `ArtworkPolicy` | `Artwork` | Owner or staff (admin/operator) for all abilities |
| `ArtLotPolicy` | `ArtLot` | Consignor or staff |
| `ConsignmentPolicy` | `Consignment` | Artist/consignor or staff |
| `AuctionPolicy` | `Auction` | Staff manage; anyone view published |
| `BidPolicy` | `Bid` | Bidder or staff |
| `SellNowOfferPolicy` | `SellNowOffer` | Buyer or consignor (party) or staff |
| `DonationPolicy` | `Donation` | Donor or staff |
| `DisputePolicy` | `Dispute` | Opener or staff; viewAny/assign/resolve = staff only |
| `BookPolicy` | `Book` | Owner or staff for write; anyone for published read |
| `BookReviewPolicy` | `BookReview` | Author or staff |
| `GalleryPolicy` | `Gallery` | Owner or staff |
| `InstitutionPolicy` | `Institution` | Staff-managed |

**NestJS equivalent**: `@nestjs/casl` or custom Guards mirroring these rules. Map each policy method (viewAny, view, create, update, delete, restore, forceDelete) to CASL ability definitions.

---

## 6. Events, Listeners, Jobs

### Domain Events (Laravel Events)
| Event | Listener | Notes |
|-------|----------|-------|
| `BidPlaced` | `SendOutbidNotifications` | Fires post-commit in PlaceBid |
| `OfferAccepted` | `SendOfferAcceptedNotification` | |
| `ConsignmentChangesRequested` | `SendConsignmentChangesNotification` | |
| `DomainEventConsumer` | (subscriber) | Fans out `domain_events` rows to handlers |

### Jobs
| Job | Queue | Notes |
|-----|-------|-------|
| `HandleStripeWebhook` | default | Routes: charge.refunded, payment_intent.succeeded, payment_intent.amount_capturable_updated, checkout.session.completed, payout.paid/failed/canceled |
| `DispatchStripeTransfer` | default | Lease pattern; 5 max attempts; exponential backoff; `lockForUpdate` to claim outbox row; Stripe API outside TX |
| `GenerateImageDerivatives` | default | S3 image processing |
| `ExportUserData` | default | GDPR export |
| `ProcessErasureRequest` | default | GDPR erasure |

**NestJS equivalent**: BullMQ queues + processors. `HandleStripeWebhook` → webhook controller + event router service. `DispatchStripeTransfer` → BullMQ processor with Redis-based lease.

---

## 7. External Integrations

### Stripe Connect
- **Manual-capture PaymentIntents**: created in `PlaceBid`, captured in `SettleAuction`
- **Checkout Sessions**: used in `BuyNow` and `PurchaseBook`
- **Transfer outbox**: lease pattern in `DispatchStripeTransfer` — claim row with `lockForUpdate`, call Stripe outside transaction, mark dispatched
- **Webhooks**: `POST /stripe/webhook` → `HandleStripeWebhook` job
- **Connect onboarding**: account link creation for artist payout accounts
- **NestJS port**: use official `stripe` npm package; replicate lease pattern with `SELECT ... FOR UPDATE` in TypeORM transaction; use BullMQ for webhook processing

### AWS S3
- **Two-phase upload**: presign URL → client uploads direct → confirm endpoint marks confirmed
- **Presigned download**: `GenerateBookDownloadUrl`
- **NestJS port**: `@aws-sdk/client-s3` + `@aws-sdk/s3-request-presigner`

### Meilisearch (Laravel Scout)
- **Model**: `Artwork` has `Searchable` trait; `toSearchableArray()` defines index
- **NestJS port**: `meilisearch` npm package; build custom search service; no Scout equivalent — implement `syncToMeilisearch()` in afterInsert/afterUpdate TypeORM subscribers

### Laravel Reverb (WebSockets)
- **Channels**: auction room (bid events), user notifications
- **Events**: `BidPlaced`, auction state changes broadcast
- **NestJS port**: `@nestjs/websockets` + `socket.io`; replicate channel authorization logic

### Spatie ActivityLog
- **Models logged**: `Artwork`, `ArtLot`, `Auction`
- **NestJS port**: custom TypeORM subscriber or interceptor writing to `activity_log` table; preserve schema for Filament admin

### IIIF / Linked Art / Europeana
- **No external dependency** — pure response builders
- **NestJS port**: service classes, same logic

### Laravel Sanctum
- **Token-based auth** for SPA and mobile
- **NestJS port**: `@nestjs/passport` + `passport-http-bearer`; issue tokens on login, validate on each request

---

## 8. Migration Risks

### Critical Risks
| Risk | Impact | Mitigation |
|------|--------|------------|
| Three-ledger invariant broken | Financial data corruption | Port `ledger_entries`/`donations`/`impact_events` as separate NestJS modules with no shared service; enforce at type level |
| Stripe lease pattern race condition | Double transfers | Port `lockForUpdate` exactly; use TypeORM `QueryRunner` with serializable isolation for outbox claim |
| `SettlementCalculator` floating-point drift | Wrong payouts | Use integer arithmetic (`bigint` in TypeScript) throughout; never `Number` for cents |
| Idempotency key gaps | Duplicate financial records | Carry all `idempotency_key` unique constraints to new schema |
| SoftDelete + Searchable sync | Deleted artworks appear in search | Implement TypeORM soft-delete subscriber that deindexes from Meilisearch |

### Missing NestJS Equivalents
| Laravel feature | NestJS approach |
|-----------------|-----------------|
| Eloquent SoftDeletes | TypeORM `@DeleteDateColumn()` + `withDeleted()` |
| Laravel Scout (Meilisearch) | Custom subscriber + `meilisearch` SDK |
| Spatie ActivityLog | Custom TypeORM subscriber writing `activity_log` |
| Filament admin panel | **No equivalent** — keep Laravel for admin; consider React-Admin or Retool as long-term replacement |
| Laravel Reverb (WebSocket) | `@nestjs/websockets` + Socket.IO |
| Laravel Sanctum | `@nestjs/passport` + bearer strategy |
| Queue (Laravel Horizon) | BullMQ + Bull Dashboard |
| Laravel Schedule | NestJS `@nestjs/schedule` (cron) |
| Gate / Policies | `@nestjs/casl` or custom Guards |
| Model Observers | TypeORM Entity Subscribers |
| Polymorphic relations | TypeORM single-table inheritance or manual discriminator |
| Route model binding (slug) | NestJS pipe + repository lookup |
| `RefreshDatabase` in tests | TypeORM + `jest` + per-test transaction rollback or DB truncation |

### Schema Compatibility Notes
- MariaDB 11 is fully compatible with TypeORM and Prisma
- JSON columns (`image_derivatives`, `payload`) → TypeORM `@Column({ type: 'json' })`
- `ENUM` columns → TypeORM `@Column({ type: 'enum', enum: [...] })`
- `DECIMAL` for financial amounts → **do not use**; all amounts are stored as `INT` cents
- `xxh3` hashing (idempotency keys) → use `xxhash-addon` npm package or `crypto.createHash('sha256')` as fallback
- Existing migration files define authoritative schema — do not regenerate from models

---

## 9. Recommended Migration Order

Migrate in dependency order, lowest coupling first. Each phase: write parity integration tests → route traffic to NestJS → monitor → retire Laravel route.

### Phase 1 — Infrastructure & Auth (weeks 1–2)
1. NestJS project bootstrap: monorepo in `backend-nest/`, TypeORM connected to `arteuction_test` (same MariaDB)
2. Auth module: Sanctum-compatible bearer token issue + validation
3. User / Profile read endpoints
4. Middleware: `AttachTraceId` equivalent (W3C traceparent)
5. Health check + error filter + logging

### Phase 2 — Read-only Catalog (weeks 3–4)
6. Artwork index + show (with Meilisearch search)
7. ArtLot index + show
8. Book index + show + search
9. Gallery / Institution / Venue / Exhibition index + show
10. IIIF / Linked Art / Europeana / Schema.org endpoints

### Phase 3 — Library Transactions (weeks 5–6)
11. Book purchase (Stripe Checkout Session)
12. Book download presigned URL
13. Book reviews

### Phase 4 — Artwork Write + Consignment (weeks 7–9)
14. Artwork create/update/delete + image two-phase upload
15. ArtLot create + state transitions
16. Consignment workflow

### Phase 5 — Auction + Bidding (weeks 10–13) ⚠️ Highest risk
17. Auction CRUD + state machine
18. `PlaceBid` — replicate `lockForUpdate` + Stripe PI + domain event outbox exactly
19. `SettleAuction` + `SettlementCalculator` (integer arithmetic, EUR only)
20. `DispatchStripeTransfer` lease pattern
21. WebSocket gateway for real-time bid events

### Phase 6 — Sell Now / Offers (weeks 14–15)
22. Buy Now (Stripe Checkout)
23. Offer counter-offer chain

### Phase 7 — Financial Reconciliation + Donations (weeks 16–17)
24. Reconciliation runs + sub-checks
25. Donation + `DonationCalculator` + `DonorFiscalYearAssessment` (ЗКПО чл.31)
26. Impact events (separate ledger)

### Phase 8 — GDPR + Disputes + Notifications (weeks 18–19)
27. GDPR export + erasure
28. Disputes
29. Notifications

### Phase 9 — Admin + Stripe Webhooks (weeks 20–22)
30. Stripe webhook handler + BullMQ processing
31. Admin panel decision: keep Filament on Laravel, or build React-Admin
32. Stripe Connect onboarding

### Phase 10 — Laravel Retirement
33. Run full parity test suite (all domains)
34. Blue-green traffic switch
35. Monitor for 2 weeks
36. Archive Laravel app (do not delete — keep for reference)

---

## 10. Database Compatibility: TypeORM vs Prisma vs Drizzle with MariaDB 11

### TypeORM
**Recommended** for this project.

| Factor | Notes |
|--------|-------|
| MariaDB support | ✅ First-class; `type: 'mariadb'` driver |
| `SELECT FOR UPDATE` | ✅ `queryRunner.query('SELECT ... FOR UPDATE')` or `createQueryBuilder().setLock('pessimistic_write')` |
| Transactions | ✅ `QueryRunner` + `EntityManager.transaction()` |
| Soft deletes | ✅ `@DeleteDateColumn()` |
| JSON columns | ✅ `@Column({ type: 'json' })` |
| Polymorphic | ⚠️ Manual — no first-class polymorphic; use discriminator columns |
| Migrations | ✅ Code-first or SQL; keep existing MariaDB schema, generate from it |
| `lockForUpdate` in nested TX | ✅ Works with savepoints |

**Concern**: TypeORM's active-record pattern can blur domain boundaries. Use repository pattern (DataMapper mode) to keep domain actions pure.

### Prisma
| Factor | Notes |
|--------|-------|
| MariaDB support | ⚠️ Uses MySQL connector; works but not officially "MariaDB" — test JSON + `FOR UPDATE` behavior |
| `SELECT FOR UPDATE` | ⚠️ Requires raw `$queryRaw`; no first-class lock API |
| Transactions | ✅ `prisma.$transaction()` |
| Schema definition | ✅ Declarative schema.prisma |
| Polymorphic | ❌ No support — must implement manually with multiple FK columns |
| Generated client | ✅ Type-safe; good DX |

**Verdict**: Prisma is ergonomic for read-heavy services but the missing `FOR UPDATE` and polymorphic support make it risky for the auction/settlement domain.

### Drizzle
| Factor | Notes |
|--------|-------|
| MariaDB support | ✅ `drizzle-orm/mysql2`; MariaDB-compatible |
| `SELECT FOR UPDATE` | ✅ `.for('update')` on select |
| Transactions | ✅ `db.transaction()` |
| Migrations | ✅ `drizzle-kit` |
| Polymorphic | ❌ No support — manual |
| Maturity | ⚠️ Newer; smaller ecosystem than TypeORM |

**Verdict**: Drizzle is lightweight and has `FOR UPDATE` support, but is newer and has less NestJS integration than TypeORM. Viable for greenfield services; TypeORM preferred for the core financial domain.

### Recommendation
Use **TypeORM in DataMapper (repository) mode** for all financial and auction domains. Consider Drizzle for new read-heavy microservices (search proxy, metadata endpoints) if they are extracted later. Do not use Prisma for any code that requires `SELECT FOR UPDATE` (outbox lease, bid placement).

---

*Last updated: 2026-11-01. Generated from full repository inspection of `test/phase-180-preservation-provenance` branch.*
