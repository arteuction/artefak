# Laravel Retirement Criteria

**Статус:** Активен checklist — не е изпълнен  
**Последна актуализация:** 2026-11-11  
**Референции:** `docs/ai/HARD_BLOCKER_STATUS.md`, `docs/ai/TRAFFIC_SWITCH_AND_ROLLBACK.md`, `docs/ai/INVARIANTS.md`

> Laravel се **архивира**, не изтрива. Последният commit на Laravel кода се тагва `laravel-final` преди премахване от активния build.

---

## Gate 1 — Схема и миграции (P0.1)

- [ ] `prisma migrate deploy` излиза с exit code 0 на `arteuction_test`
- [ ] `prisma migrate diff --from-config-datasource --to-schema=prisma/schema.prisma --exit-code` излиза с exit code 0 (нула дрейф)
- [ ] `schema-baseline.spec.ts` минава на живата DB
- [ ] FULLTEXT индексът на `artworks` е верифициран ръчно (не се покрива от Prisma diff)
- [ ] MariaDB ENUM полетата са верифицирани (Prisma ги map-ва като string)
- [ ] Решено е кой е migration authority след cutover — Prisma Migrate или продължава Laravel

---

## Gate 2 — Integration тестове (P0.2)

- [ ] Всички 83 `HAS_DB` теста минават на `arteuction_test`
- [ ] `financial-integrity.spec.ts` (I1–I10) зелен — три-ledger separation, idempotency, rollback atomicity
- [ ] `auth.spec.ts` (A1–A14, P1–P12) зелен — включително A10 (`$2y$` hash срещу реална DB)
- [ ] `bid-load.spec.ts` зелен — 50 concurrent бида, `SELECT FOR UPDATE NOWAIT` без deadlock
- [ ] `timezone-roundtrip.spec.ts` зелен — MariaDB datetime round-trip
- [ ] Нито един тест не използва SQLite или in-memory DB

---

## Gate 3 — Auth parity (задължителен преди всякакъв traffic switch)

- [ ] Real login с `$2y$` bcrypt hash от Laravel DB → NestJS връща валиден JWT
- [ ] Real login с грешна парола → 401, без информация за съществуването на потребителя
- [ ] Token rotation работи на staging (refresh → нов access token, стар се invalidira)
- [ ] Token reuse detection работи (replay на стар refresh token → 401)
- [ ] `password_reset` flow работи end-to-end през NestJS
- [ ] Email verification flow работи end-to-end
- [ ] Logout invalidира refresh token в DB

> **Правило:** Laravel authentication code не се докосва докато този gate не е 100% зелен и верифициран в production трафик минимум 2 седмици.

---

## Gate 4 — Stripe E2E (P0.4)

- [ ] `POST /api/v1/auctions/{id}/items/{itemId}/checkout-session` → Stripe създава сесия
- [ ] `checkout.session.completed` webhook → signature верифициран, записан в `webhook_events`
- [ ] Дублиран webhook event → втори запис отхвърлен от UNIQUE constraint (idempotency)
- [ ] `settlements` + `settlement_lines` записи са коректни след payment
- [ ] `ledger_entries` се записва само в `ledger_entries` (не в `donations` или `impact_events`)
- [ ] Transfer outbox → `StripeService.createTransfer()` с idempotency key
- [ ] Refund flow работи end-to-end

---

## Gate 5 — Traffic switch (P0.5)

Изпълнява се по реда от `docs/ai/TRAFFIC_SWITCH_AND_ROLLBACK.md`.

- [ ] `GET /api/health` → 200 на staging
- [ ] Nginx per-prefix switch за non-financial routes (`/api/artworks`, `/api/art-lots`, `/api/auctions`)
- [ ] 15 минути мониторинг без HTTP 5xx след всеки prefix switch
- [ ] Error rate < 0.1% за 24 часа на всеки switched prefix
- [ ] Scheduler ownership потвърден — само NestJS cron работи за switched домейни
- [ ] Laravel scheduler спрян за switched домейни

---

## Gate 6 — Financial domain cutover (последен)

- [ ] Settlement calculation произвежда идентични резултати спрямо Laravel за последните 30 дни тестови данни
- [ ] Втори engineer е прегледал settlement service diff
- [ ] Три-ledger invariant тест suite зелен (`ledger_entries` ≠ `donations` ≠ `impact_events`)
- [ ] `/api/admin/*` routes switched към NestJS
- [ ] 2 седмици production мониторинг на финансовите routes без инцидент
- [ ] Stripe payout reconciliation потвърдена ръчно за един пълен settlement цикъл

---

## Gate 7 — Laravel retirement

Само след Gate 1–6 са 100% изпълнени:

- [ ] Последният активен Laravel commit е тагнат: `git tag laravel-final`
- [ ] Laravel директорията е преместена в `archive/laravel/` (не изтрита)
- [ ] `composer.json`, `artisan`, `bootstrap/` са изключени от активния build
- [ ] CI/CD pipeline-ът за Laravel е деактивиран (не изтрит)
- [ ] `.env.laravel.example` е запазен за референция
- [ ] Shared hosting конфигурацията е документирана (за евентуален rollback)
- [ ] `docs/ai/INVARIANTS.md` е обновен — забраната "Do not delete Laravel authentication code" се заменя с "Laravel е архивиран в archive/laravel/"

---

## Timeline оценка (от момента на достъп до staging)

| Фаза | Работа | Оценка |
|---|---|---|
| G1 + G2 | Staging DB setup, migrate deploy, 83 HAS_DB теста | 1–2 седмици |
| G3 | Auth E2E верификация на staging | 3–5 дни |
| G4 | Stripe E2E rehearsal | 3–5 дни |
| G5 | Traffic switch (non-financial routes) + мониторинг | 1 седмица |
| G6 | Financial cutover + 2 седмици мониторинг | 3 седмици |
| G7 | Laravel archival | 1 ден |
| **Общо** | | **~6–8 седмици** |

Критичният path е **staging DB достъп → G2 (83 теста)** — всичко останало е блокирано от него.

---

## Дефиниция за "готово"

Laravel е пенсиониран когато:
1. Всеки Gate 1–7 е 100% изпълнен
2. Два последователни billing цикъла са обработени изцяло от NestJS без корекции
3. Нито един активен потребител не е контактувал support за проблем, произлязъл от migration
