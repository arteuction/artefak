# Remaining Laravel Dependencies — ARTeuCtion

_Originally generated 2026-11-03 from route/composer inspection. **Rebased 2026-11-11** against `apps/api/src` at `origin/main` `00d9505e`._
_The 2026-11-03 version listed Stripe, webhooks, S3, scheduler, Meilisearch, realtime and email as absent. They now exist in code. This revision separates **absent**, **stub**, and **implemented-but-unverified**._

**Reading rule:** "implemented" means code + unit tests exist. It never means Laravel parity, and never means it has run against real MariaDB, Stripe or S3. Parity is proven only by the gates in `LARAVEL_EXIT_PLAN.md` and `PRODUCTION_CUTOVER_PLAN.md`.

---

## Section A: Laravel responsibilities with NO NestJS code

| Responsibility | Laravel implementation | Risk if missing |
|---|---|---|
| Stripe `payout.paid/failed/canceled` webhooks | `HandleStripeWebhook` → `HandleConnectedAccountPayout` | Connected-account payout status never recorded; NestJS ignores these events |
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
| Stripe webhooks | `WebhookHandlerService`: `checkout.session.completed`, `payment_intent.succeeded/.amount_capturable_updated/.payment_failed`, `charge.refunded` (delegates to `RefundService`, 2026-11-11); dedup by `stripe_event_id` | 30-day shadow comparison; event types Laravel handles that NestJS does not (needs an audit) |
| Stripe transfers / refunds / reversals | `createTransfer` + `dispatch-transfer-outbox` cron with lease claims; `RefundService` (port of `ProcessRefund`); `createTransferReversal` + `dispatch-transfer-reversals` cron (port of `DispatchStripeReversal`) — last two added 2026-11-11, mocked-Prisma tests only | Idempotency under real Stripe; payout reconciliation |
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
| Stripe refund/reversal real-DB + Stripe test-mode verification, plus `payout.*` webhook handler | 3–5 | Refund/reversal ported 2026-11-11 (unit-tested only); payout events absent |
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
| **Stage 2 total** | **≈112–182 SP** | was 154–236 |

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
| Stage 2 + Stage 3 | 161–261 |
| 20% buffer | 32–52 |
| **Grand total** | **≈193–313 SP** (was 244–378) |

### Calendar floor (independent of headcount)

Stripe shadow comparison (30 days) + post-cutover webhook monitoring (30 days) + Stage 4 stabilisation (30 days) are elapsed-time gates. **Laravel retirement cannot happen sooner than roughly three months after Stage 2 is complete**, however many developers work in parallel. Any estimate shorter than that ignores `LARAVEL_EXIT_PLAN.md` acceptance criteria 3 and Stage 4.

---

## Section E: Recommended next work (no staging needed)

1. ~~Stripe refund/reversal dispatch~~ — ported 2026-11-11 (`RefundService`, reversal cron). Next in the same area: `payout.*` webhook handling and the `reversal_pending` race (Section F).
2. **Settlement parity vectors** — port Laravel test vectors into `financial-integrity`-style unit tests that need no DB.
3. ~~Webhook event-coverage audit~~ — done 2026-11-11: Laravel routes 8 event types, NestJS handles 5. The only missing ones are `payout.paid/failed/canceled` (O1).
4. **Admin panel decision** (React admin vs keep Filament vs port) — blocks Stage 3 entry; needs a product decision, not code.
5. **Prove the stub fallbacks cannot activate in production.** `config-validation.service.ts` already throws at startup when `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` or `AWS_*` are missing in production, which mitigates this. Add a test that `S3Service.enabled`/`StripeService.enabled` are true whenever production validation passes, so the `stub-s3.local` URL and stub checkout session stay dev-only.

Needs staging DB: P0.1 (Prisma baseline), P0.2 (83 HAS_DB tests), P0.4 (Stripe E2E), load test.

---

## Section F: Defects found by this audit (2026-11-11)

Found by comparing NestJS against Laravel `ProcessRefund`, `DispatchStripeTransfer` and `DispatchStripeReversal`. Unit tests with mocked Prisma had not caught them; they would surface only on a real database.

**Fixed (mocked-Prisma tests only — still need real-DB and Stripe test-mode verification):**

| # | Defect | Fix |
|---|---|---|
| F1 | Successful transfers wrote `transfer_outbox.status = 'completed'`, which is not in the DB enum (`pending|processing|dispatched|canceled|failed`). Under strict SQL mode every successful transfer would fail after Stripe had already paid, then retry forever | Writes `dispatched`; test SC22 asserts only enum-valid statuses |
| F2 | Transfer dispatch never updated `settlement_lines` (`processing` lease, `transferred`, `stripe_transfer_id`). Refunds and reversals depend on those fields | Atomic outbox + line update in one transaction; lease released on failure |
| F3 | `charge.refunded` wrote one settlement-level debit with no refund lines, ignored refund status (a failed/pending refund was booked as a debit), had no over-refund or currency guard, never changed settlement status, never cancelled pending transfers (a refunded sale could still pay out), and never created reversal orders | `RefundService` ports `ProcessRefund`: row lock, status/currency/cumulative guards, per-line delta allocation, per-line debits (key `refund:{refundLineId}:debit`, same as Laravel so both stacks cannot double-book), cancel/`reversal_pending`/reversal order per line, settlement status |
| F4 | Nothing executed reversal orders | `dispatch-transfer-reversals` cron with claim, stable idempotency key, retry backoff, `failed` after 5 attempts, stale-claim recovery |
| F5 | Unknown settlement on `charge.refunded` was silently skipped and the webhook marked processed, losing the refund on out-of-order delivery | Now throws; the webhook is reset to `received` and retried (matches Laravel) |

**Open (not fixed):**

| # | Gap | Note |
|---|---|---|
| O1 | `payout.paid/failed/canceled` not handled | Laravel `HandleConnectedAccountPayout` |
| O2 | NestJS transfers never reach `failed` (Laravel stops after 5 attempts); backoff is capped, so a permanently rejected transfer retries forever | Needs a max-attempt rule |
| O3 | Race: a refund arriving while a line is `processing` sets `reversal_pending`, but if the transfer then succeeds no reversal order is created — in **both** stacks (Laravel comment says the worker creates it, but `DispatchStripeTransfer` does not) | Money transferred but not reversed; needs a design decision |
| O4 | `refund.created/updated` events not routed; a refund first seen as `pending` is never re-evaluated when it succeeds (idempotency returns early) — same in Laravel | Parity gap to decide on |
| O5 | `charge.refunded` handler reads `charge.refunds.data`. Newer Stripe API versions omit `refunds` from the event unless expanded | Unverified; check the webhook endpoint API version and test in Stripe test mode |
| O6 | `refund_lines.recipient_type` allows only `artist|fund|ops`; refunding a settlement with `author`/`co_author` lines throws (same DB constraint in Laravel) | Library book refunds may hit this |
