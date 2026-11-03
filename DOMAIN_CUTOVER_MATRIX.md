# ARTeuCtion — Domain Cutover Matrix

**Date:** 2026-11-03  
**Principle:** Laravel is the production authority until each domain passes all acceptance gates.

---

## Strategic Architecture

Per Phase 0.7 recommendation, ARTeuCtion is three connected infrastructure layers:

| Layer | Domains | Data ownership |
|---|---|---|
| **ARTeuCtion Marketplace** | Artworks, Auctions, Bids, Sales (SellNow), Settlements, Payments, Artists, Galleries, Consignments, Disputes | `artworks`, `auctions`, `auction_items`, `bids`, `sell_now_offers`, `settlements`, `ledger_entries` |
| **Artefak Digital Library** | Books, Book files, Book categories, Library access, Authors, Provenance/preservation records | `books`, `book_files`, `book_categories`, `user_book_access` |
| **ArtMetro** | Cultural network, downstream data consumers, public institution/exhibition data | `institutions`, `exhibitions`, `collections` |

---

## Domain Cutover Status

### Layer 1 — ARTeuCtion Marketplace

| Domain | Laravel status | NestJS status | Cutover gate | Decision |
|---|---|---|---|---|
| Auth (register/login/logout) | Production | ✅ Parity (unit tests pass) | G1+G6 | After staging |
| Artworks (browse/publish) | Production | ✅ Parity + S3 upload | G1+G6 | After staging |
| Auctions (CRUD+lifecycle) | Production | ✅ Parity | G1+G6 | After staging |
| Bids (place/concurrent) | Production | ✅ + WebSocket | G1+G6+G8 | After staging |
| Settlements (calculate/pay) | Production | ✅ Parity | G1+G7 | After financial gate |
| Stripe payments | Production | ✅ + idempotency key | G1+G3+G6 | After staging |
| Stripe webhooks | Production | ✅ Idempotency | G1+G6 | After staging |
| SellNow offers | Production | ✅ Parity | G1+G6 | After staging |
| Consignments | Production | ✅ Parity | G1+G6 | After staging |
| Disputes | Production | ✅ Parity | G1+G6 | After staging |
| Artist profiles | Production | ✅ Parity | G1+G6 | After staging |
| Gallery ecosystem | Production | ✅ Parity | G1+G6 | After staging |
| Admin panel | Production | ✅ Core ops done; 10 stubs remain | G1+G6 | Partial migration |

### Layer 2 — Artefak Digital Library

| Domain | Laravel status | NestJS status | Cutover gate | Decision |
|---|---|---|---|---|
| Books (CRUD+browse) | Production | ✅ Parity | G1+G6 | After staging |
| Book access / entitlement | Production | ✅ Entitlement check | G1+G6 | After staging |
| Book file download (presign) | Production | ✅ S3 presign | G1+G4+G6 | After staging |
| Book file upload (PDF) | Production | ❌ Not implemented | — | Blocked |
| Book categories | Production | ✅ Parity | G1+G6 | After staging |
| Authors | Production | ✅ Parity | G1+G6 | After staging |

### Layer 3 — ArtMetro

| Domain | Laravel status | NestJS status | Cutover gate | Decision |
|---|---|---|---|---|
| Institutions | Production | ✅ Parity | G1+G6 | After staging |
| Exhibitions | Production | ✅ Parity | G1+G6 | After staging |
| Collections | Production | ✅ Parity | G1+G6 | After staging |
| Governance | Production | ✅ Parity | G1+G6 | After staging |
| ArtMetro legacy routes | Production | ✅ Stub forwarding | G1+G6 | After staging |

### Supporting Infrastructure

| Domain | Laravel status | NestJS status | Cutover gate | Decision |
|---|---|---|---|---|
| Donations + impact | Production | ✅ Parity | G1+G6 | After staging |
| Donation recipients | Production | ✅ Parity | G1+G6 | After staging |
| Impact events/projects | Production | ✅ Parity | G1+G6 | After staging |
| Search (Meilisearch + SQL fallback) | Production | ✅ Parity | G1+G6 | After staging |
| Email (SMTP) | Production | ✅ Parity | G1+G6 | After staging |
| WebSocket (real-time bids) | Reverb/Pusher | ✅ socket.io gateway | G1+G5+G6 | After staging |
| Realtime (frontend echo.ts) | laravel-echo | ✅ socket.io-client | G1+G5+G6 | After staging |

---

## Cutover Sequence

**Phase A — Parallel run (NestJS staging, Laravel production)**
1. Deploy NestJS to staging
2. Run all integration tests
3. Run smoke tests
4. Monitor for 72 hours

**Phase B — Read traffic split**
1. Route 10% of read traffic (GET only) to NestJS
2. Compare response shapes against Laravel (shadow mode)
3. Fix any divergences

**Phase C — Write traffic by domain (one domain at a time)**
1. Start with Library (lowest financial risk)
2. Then Artwork publishing
3. Then Auction browsing
4. Then Bid placement (highest risk — requires G8)
5. Finally Settlement/Payment (requires G7+G3)

**Phase D — Laravel deprecation**
1. Route all traffic to NestJS
2. Keep Laravel read-only for 30 days (safety net)
3. Disable Laravel after 30-day clean period

---

## Stripe Webhook Cutover

Stripe Dashboard allows multiple webhook endpoints simultaneously.
Add NestJS endpoint **before** removing Laravel endpoint.

```
Add:    https://api.arteuction.com/api/v1/stripe/webhook
Wait:   72 hours, verify NestJS processes all events
Remove: https://api.arteuction.com/api/webhooks/stripe (Laravel)
```

---

## Database Single-Writer Strategy

During parallel run, only one application writes to financial tables at a time.

| Table group | Writer | Enforcement |
|---|---|---|
| `ledger_entries` | Laravel (until Phase C.5) | DB-level: application user permission |
| `settlements` | Laravel (until Phase C.5) | DB-level: application user permission |
| `bids` | NestJS (after Phase C.4) | Stripe webhook must also move to NestJS first |
| `artworks`, `auctions` | NestJS (after Phase C.2–C.3) | — |
| `books`, `book_files` | NestJS (after Phase C.1) | — |

**Never allow both applications to write to `ledger_entries` simultaneously.**
