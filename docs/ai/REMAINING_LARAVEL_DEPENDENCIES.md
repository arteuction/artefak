# Remaining Laravel Dependencies — ARTeuCtion

_Originally generated 2026-11-03 from route/composer inspection. **Rebased 2026-11-11** against `apps/api/src` at `origin/main` `00d9505e`._
_The 2026-11-03 version listed Stripe, webhooks, S3, scheduler, Meilisearch, realtime and email as absent. They now exist in code. This revision separates **absent**, **stub**, and **implemented-but-unverified**._

**Reading rule:** "implemented" means code + unit tests exist. It never means Laravel parity, and never means it has run against real MariaDB, Stripe or S3. Parity is proven only by the gates in `LARAVEL_EXIT_PLAN.md` and `PRODUCTION_CUTOVER_PLAN.md`.

---

## Section A: Laravel responsibilities with NO NestJS code

| Responsibility | Laravel implementation | Risk if missing |
|---|---|---|
| Stripe refund creation / reversal dispatch | `DispatchStripeReversal` | Refunds cannot be initiated by NestJS (it only *records* `charge.refunded` webhooks) |
| Stripe bid re-authorization flow | `BidReAuthorizationController` | Scheduler flags winners who must re-authorize, but no endpoint lets them do it |
| Artwork image derivatives | `GenerateArtworkDerivativesJob`, `intervention/image` | No `sharp`/image dependency; only originals |
| PDF generation | `barryvdh/laravel-dompdf` | Tax receipts, settlement statements |
| Activity log | `spatie/laravel-activitylog` | No audit trail on model mutations |
| Admin UI | Filament 4.0 (7 resources) | **No decision made** — hard blocker for retirement |
| Scheduled commands | 10 of 15 commands | Nightly reconciliation, governance health, operational alerts, settlement-create, others |
| Slack / ops alerting | `CheckOperationalAlerts`, Slack token | Silent production failures |

## Section B: NestJS stubs (route exists, returns placeholder)

| Area | Evidence |
|---|---|
| Admin: reconciliation runs/mismatches, split profiles, rulesets, books, book categories, reviews, connected payouts, ArtMetro routes, audit-log entry, statement | `admin/admin.controller.ts` — `stub()` / empty paged response |
| ArtMetro legacy routes | `admin/artmetro-legacy.controller.ts` |
| API clients, partners, domain events, evidence | `admin/misc.controller.ts` |
| Book authors store/update/destroy; book files store/update/destroy; upload-intent; complete | `library/book-authors.controller.ts`, `library/book-files.controller.ts` (`https://stub.example.com/upload`) |
| GDPR erasure | `governance.service.ts` — records intent, schedules nothing |
| Fulfillment | Only ship/deliver status flips in `auction.service.ts`; no close step, no settlement trigger |
| Operations console | `admin/ops.controller.ts` |

## Section C: Implemented in code, parity/real-service evidence MISSING

| Integration | What exists | What is still unproven |
|---|---|---|
| Stripe checkout | `createCheckoutSession` (stub session when key unset) | Test-mode E2E (P0.4) |
| Stripe webhooks | `WebhookHandlerService`: `checkout.session.completed`, `payment_intent.succeeded/.amount_capturable_updated/.payment_failed`, `charge.refunded`; dedup by `stripe_event_id` | 30-day shadow comparison; event types Laravel handles that NestJS does not (needs an audit) |
| Stripe transfers | `createTransfer` + `dispatch-transfer-outbox` cron with lease claims | Idempotency under real Stripe; payout reconciliation |
| Settlement | `settle`, `preview`, `SplitProfile` | Cent-for-cent parity vs Laravel vectors; three-ledger separation on a real DB |
| Payout | `artistPayouts`, `galleryPayouts`, `pendingTransfers` real Prisma queries | Parity of totals vs Laravel |
| Scheduler | 5 `@nestjs/schedule` crons (close lots, expire bid auth, dispatch domain events, dispatch transfer outbox, recover stale claims) | Behaviour under multi-instance + load; **no BullMQ** — design is DB outbox + cron, a deliberate difference from Laravel queues that needs an ADR |
| S3 | Presign upload/download, quarantine → approve flow, MinIO-compatible | Real bucket run; falls back to `stub-s3.local` when disabled (production startup validation requires `AWS_*`, so this is dev-only by construction — see Section E.5) |
| Search | `MeilisearchService` | Index sync on write; relevance parity |
| Realtime | `BidGateway` (Socket.io) | Reconnect/API-reconciliation per INVARIANT 14; Reverb protocol compatibility with the existing frontend |
| Email | `EmailService` with 5 templates | Triggers wired (outbid, payment); deliverability |
| Auth | JWT + rotation + `$2y$` shim | A1–A14/P1–P12 on real DB |

---

## Section D: Rebased effort estimate

**Methodology:** 1 SP ≈ 1 focused dev-day including tests. One experienced NestJS developer. **Confidence: low.** Items exist in code but their depth was not audited line by line, so the "verify/parity" rows may grow.

### Stage 2 — functional migration (remaining)

| Work item | Remaining SP | Basis |
|---|---|---|
| Settlement parity tests vs Laravel vectors | 3–5 | Service exists |
| Stripe checkout parity + E2E | 2–3 | Exists |
| Stripe webhook event-coverage audit + shadow tooling | 3–5 | Exists |
| Stripe transfer dispatch E2E | 2–3 | Exists |
| **Stripe refund/reversal dispatch** | 5–8 | Absent |
| **Stripe bid re-authorization** | 3–5 | Absent |
| S3 artwork presign/confirm vs real bucket | 1–2 | Exists |
| **Image derivatives (Sharp)** | 5–8 | Absent |
| S3 book upload/complete (replace stubs) | 3–5 | Stub |
| Retry/DLQ/monitoring design for cron + outbox (or adopt BullMQ) | 3–8 | Decision needed |
| Lot-closing / bid-expiry load verification | 2–3 | Exists |
| Remaining scheduled commands (governance health, alerts, settlement-create) | 3–5 | Absent |
| Meilisearch index sync + relevance parity | 2–3 | Exists |
| Realtime reconnect + frontend parity | 3–5 | Exists |
| Email trigger wiring + parity | 2–3 | Exists |
| **Admin panel (decision + build)** | 21–34 | Absent, no decision |
| Reconciliation engine + ledger views | 8–13 | Stub |
| Payout parity | 2–3 | Exists |
| GDPR export verification + erasure | 5–8 | Partial |
| Fulfillment close + settlement trigger | 5–8 | Partial |
| API clients / partner onboarding | 5–8 | Stub |
| Remaining stubs (rights, IIIF/Linked Art/EDM conformance, tax receipt) | 8–13 | Stub |
| **PDF generation** | 5–8 | Absent |
| **Activity log** | 5–8 | Absent |
| Prisma migration baseline (needs staging DB) | 2–3 | Partial |
| 50-concurrent-bid load test | 3–5 | Spec exists, never run on real DB |
| **Stage 2 total** | **≈114–185 SP** | was 154–236 |

### Stage 3 — production cutover

| Work item | SP |
|---|---|
| Staging environment | 5–8 |
| Laravel-vs-NestJS response diff tooling | 5–8 |
| Stripe shadow mode (elapsed time dominates) | 13–21 |
| Domain-by-domain cutover | 21–34 |
| Monitoring/alerting | 5–8 |
| **Stage 3 total** | **49–79 SP** |

### Total

| | SP |
|---|---|
| Stage 2 + Stage 3 | 163–264 |
| 20% buffer | 33–53 |
| **Grand total** | **≈196–317 SP** (was 244–378) |

### Calendar floor (independent of headcount)

Stripe shadow comparison (30 days) + post-cutover webhook monitoring (30 days) + Stage 4 stabilisation (30 days) are elapsed-time gates. **Laravel retirement cannot happen sooner than roughly three months after Stage 2 is complete**, however many developers work in parallel. Any estimate shorter than that ignores `LARAVEL_EXIT_PLAN.md` acceptance criteria 3 and Stage 4.

---

## Section E: Recommended next work (no staging needed)

1. **Stripe refund/reversal dispatch** — the largest absent financial capability; webhooks record refunds but nothing initiates them.
2. **Settlement parity vectors** — port Laravel test vectors into `financial-integrity`-style unit tests that need no DB.
3. **Webhook event-coverage audit** — list every event type Laravel's `HandleStripeWebhook` handles and diff against the five NestJS handles.
4. **Admin panel decision** (React admin vs keep Filament vs port) — blocks Stage 3 entry; needs a product decision, not code.
5. **Prove the stub fallbacks cannot activate in production.** `config-validation.service.ts` already throws at startup when `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` or `AWS_*` are missing in production, which mitigates this. Add a test that `S3Service.enabled`/`StripeService.enabled` are true whenever production validation passes, so the `stub-s3.local` URL and stub checkout session stay dev-only.

Needs staging DB: P0.1 (Prisma baseline), P0.2 (83 HAS_DB tests), P0.4 (Stripe E2E), load test.
