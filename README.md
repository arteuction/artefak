# ArteUction — Phygital Art Auction Platform

[![CI](https://github.com/arteuction/artefak/actions/workflows/ci.yml/badge.svg)](https://github.com/arteuction/artefak/actions/workflows/ci.yml)
![PHP 8.3](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)
![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![MariaDB 11](https://img.shields.io/badge/MariaDB-11-003545?logo=mariadb&logoColor=white)

> **ArteUction** е phygital платформа за AR изкуство и благотворителен търг, изградена за
> METRO България в партньорство с ОББ и Лев Инс. Художници излагат произведения в METRO
> обектите; посетителите сканират QR код, преживяват AR слой и наддават в реално време.
> Всяка продажба се разпределя автоматично между художника, социален фонд и операционни разходи.

---

## Съдържание

- [Архитектура](#архитектура)
- [Домейни](#домейни)
- [API](#api)
- [Тест покритие](#тест-покритие)
- [CI / Deployment](#ci--deployment)
- [Текущо състояние](#текущо-състояние)
- [Пътна карта](#пътна-карта)

---

## Архитектура

```
Laravel 13 · PHP 8.3 · MariaDB 11 · Stripe PHP SDK v15 · Sanctum 4
```

Системата следва **domain-first** подход: чист домейн слой без DB/Stripe зависимости,
обслужван от HTTP контролери и Jobs. Всички финансови операции са идемпотентни и
транзакционни. MariaDB е единственият system of record за MVP.

```
app/
├── Domain/
│   ├── Artist/          # Регистрация, верификация, SDG claims
│   ├── Auction/         # PlaceBid, CloseAuctionItem, SettleAuction
│   ├── Artmetro/        # QR scan, Visit beacon, SDG tagging
│   └── Settlement/      # Разпределение 45/45/10, refunds, ledger
├── Models/              # 22 Eloquent модела
├── Http/Controllers/Api/
├── Jobs/                # Stripe transfers, reversals, webhook dispatch
├── Policies/            # ArtistApplication, ArtworkSdgClaim
└── Console/Commands/    # CloseExpiredLots
```

---

## Домейни

### Settlement (финансов двигател)
Разпределение `45 / 45 / 10` (художник / социален фонд / операции) с пени-точна
аритметика, идемпотентни Stripe transfers, пълен refund allocator и ledger.

### Auction
- `PlaceBid` — row-level lock, Stripe PaymentIntent на всяка оферта
- `CloseAuctionItem` — определя победителя, отменя останалите PI-та
- `SettleAuction` — capture → settlement → lines → ledger, идемпотентно

### Artist Onboarding *(P7)*
- `ArtistProfile` + `ArtistApplication` с version counter и document paths
- `SubmitApplication` / `ReviewApplication` (approve/reject) + immutable audit log
- `SubmitSdgClaim` / `ReviewSdgClaim` — обосновка + доказателства за всяко SDG 1–17
- Роли: `buyer | artist | admin` — прости enum без пакет за MVP

### ArtMetro
- `ArtmetroArtifact` — QR токен (auto-generated), AR model URL, polymorphic sellable
- `GET /api/artifacts/{qrToken}` — read-only; crawlers/prefetch безопасни
- `POST /api/artifacts/{qrToken}/scans` — записва сканирането (rate-limited 30/мин)
- Routes с difficulty, walking distance, accessibility; stop ordering

---

## API

| Метод | Endpoint | Auth | Описание |
|-------|----------|------|----------|
| GET | `/api/auctions` | публичен | Списък търгове |
| GET | `/api/auctions/{auction}` | публичен | Детайл търг |
| GET | `/api/auctions/{auction}/items/{item}` | публичен | Лот |
| POST | `/api/auctions/{auction}/items/{item}/bids` | Sanctum | Нова оферта |
| GET | `/api/artifacts/{qrToken}` | публичен | QR артефакт (без странични ефекти) |
| POST | `/api/artifacts/{qrToken}/scans` | публичен, throttle 30/мин | Запис на сканиране |
| POST | `/api/artifacts/{artifact}/visit` | публичен, throttle 60/мин | Visit beacon |
| GET | `/api/routes` | публичен | ArtMetro маршрути |
| GET | `/api/routes/{route}` | публичен | Маршрут с спирки |

---

## Тест покритие

**185 теста · 465 assertions** — всички зелени

| Suite | Файлове | Фокус |
|-------|---------|-------|
| Unit — Settlement | 4 | Money, SplitProfile, Calculator, PoolSplit |
| Feature — Settlement | 4 | CreateSettlement, ProcessRefund, конкурентност |
| Feature — Auction | 4 | PlaceBid, CloseItem, SettleAuction, CloseExpiredLots |
| Feature — ArtMetro | 4 | ArtifactApi, RecordScan, TagSdgs, RouteApi |
| Feature — Artist | 1 | SubmitApplication, ReviewApplication, SubmitSdgClaim, ReviewSdgClaim |
| Feature — Jobs | 2 | DispatchStripeTransfer, DispatchStripeReversal |
| Feature — Webhook | 1 | WebhookInbox |
| Feature — Migration | 1 | Schema assertions |
| Smoke | 1 | Boot, env, DB connection |

---

## CI / Deployment

Два паралелни job-а при всяко push:

**`test`** — `migrate:fresh` → пълен тест suite → `composer audit`

**`upgrade-path`** — миграции 001–011, seed на legacy данни, миграция 012,
assert backfill + UNIQUE constraint

```yaml
services:
  mariadb:
    image: mariadb:11
    options: --health-cmd="healthcheck.sh --connect --innodb_initialized"
```

> **Сигурност:** `arteuction_test` DB потребителят няма привилегии върху продукционната
> база. `.env` и `.env.testing` не се commit-ват никога.

---

## Текущо състояние

| Компонент | Готовност |
|-----------|-----------|
| Settlement / Refunds / Ledger | ✅ завършен |
| Stripe Webhook inbox | ✅ завършен |
| Auction flow (bid → close → settle) | ✅ завършен |
| ArtMetro QR / Scans / Visits / Routes | ✅ завършен |
| Artist registration + SDG claims domain | ✅ завършен |
| Immutable admin audit log | ✅ завършен |
| Payment flow (won → Stripe → paid) | 🔄 предстои |
| Admin control plane (UI) | 🔄 предстои |
| Blockchain provenance anchoring | 🔄 след стабилизиране на artwork schema |
| AR интеграция | 🔄 след MVP |
| Staging Basic Auth / SSL | 🔄 предстои |

---

## Пътна карта

### Следващ milestone — P8: Auction Payment Flow

> Затварянето на търга не означава автоматично settlement.

```
auction won
  → payment_pending          # победителят получава payment link
  → Stripe Checkout/PI       # плаща в рамките на deadline
  → webhook: payment_intent.succeeded
  → paid
  → settlement created       # само след потвърдено плащане
  → transfers/outbox
  → fulfillment
  → delivered
```

Ще добавим:
- `winner_payment_deadline` и failed/expired payment handling
- Idempotent Stripe webhook за `payment_intent.succeeded`
- Frozen split profile върху конкретната продажба
- Fulfillment и physical-delivery статуси
- Refund/chargeback връзка към поръчката

---

### P9: Admin Control Plane

Минимален панел с опашки за модерация:

- Художници (approve / reject applications)
- Произведения (approve / reject submissions)
- SDG claims (approve / reject с review note)
- Exhibitions / Artifacts / Routes

---

### P10: Blockchain Provenance

След финализиране на artwork + SDG схемите:

1. Каноничен JSON manifest на произведението
2. SHA-256 hash
3. Append-only provenance record в DB
4. Blockchain anchoring adapter (chain-агностичен)
5. Chain ID + transaction hash
6. Публична `/verify/{hash}` страница

> **Правило:** никакви лични данни или документи on-chain.
> Blockchain verification се стартира само след като artwork и SDG схемите са стабилни —
> всяка промяна на структурата инвалидира доказателството.

---

### P11: AR Integration

AR слоят се добавя последен — след като физическият и дигиталният поток са верифицирани
end-to-end. Изисква финализиран `ar_model_url` pipeline и device testing.

---

### Дългосрочна еволюция

| Фаза | Описание |
|------|----------|
| Realtime bidding | WebSockets / Laravel Reverb за live оферти |
| Supabase read layer | Realtime UI слой върху MariaDB (не втори source of truth) |
| Multi-currency | EUR → BGN, USD с курсова конверсия в settlement-а |
| Artist royalties | Secondary market royalty tracking |
| Public SDG dashboard | Агрегирани SDG данни по художник / изложба |
| Mobile QR app | Native scanner с AR preview |

---

### Интеграционен тест (целева дефиниция на MVP)

```
Художник се регистрира
  → подава документи
  → получава одобрение от администратор
  → добавя произведение и SDG обосновка
  → администраторът одобрява claim-а
  → произведението влиза в търг
  → купувач наддава
  → плаща през Stripe Checkout
  → settlement се създава автоматично
  → публичната страница верифицира provenance hash
```

Докато този поток не мине end-to-end, нови AR функции и допълнителни инфраструктурни
слоеве увеличават обема на системата, но не и MVP готовността.
