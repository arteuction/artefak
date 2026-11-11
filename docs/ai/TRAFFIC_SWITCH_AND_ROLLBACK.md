# Traffic Switch and Rollback — NestJS Addendum (P0.5)

_Rewritten 2026-11-11. This file is an **addendum**. The authoritative procedure is `PRODUCTION_CUTOVER_PLAN.md` (backup, per-domain steps, Stripe shadow mode, acceptance tests); generic recovery is `docs/runbooks/rollback.md`. Where this file disagrees with those, they win._

**Status:** design only. Nothing here has been executed. No production host, Nginx config or deployed commit has been verified.

---

## 1. Facts that shape the switch

- NestJS uses the global prefix **`/api/v1`**. Nginx `location` blocks must match `/api/v1/<domain>`, not `/api/<domain>`.
- Laravel owns the schema; NestJS and Laravel share one database during transition. Rollback is therefore **routing only** — no schema change is reverted.
- Single-writer rule (`PRODUCTION_CUTOVER_PLAN.md`): per domain, exactly one authoritative writer. Enforce with a feature flag, not convention.
- Nothing below starts before the Staging Evidence Gates SG1–SG4 in `LARAVEL_EXIT_PLAN.md` are green.

## 2. Switch order

Follow the cutover sequence in `DOMAIN_OWNERSHIP_MATRIX.md` (public read-only → auth → profile → artwork → … → queues/schedulers → search → admin → settlement/payout/reconciliation → Stripe webhooks last). Auth moves only after SG3. Financial domains move last and need the extra gate in §5.

## 3. Nginx per-domain block (pattern)

```nginx
# More specific block above the Laravel catch-all; one domain per change.
location /api/v1/artworks {
    proxy_pass http://nestjs-upstream;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}
location /api { proxy_pass http://laravel-upstream; }
```

Validate with `nginx -t`, apply with `nginx -s reload` (graceful). Confirm which backend answered by a **response header you add per upstream** (e.g. `add_header X-Backend nestjs always;`). Do not infer the backend from `X-Powered-By`.

## 4. Draining and restarts

`main.ts` calls `app.enableShutdownHooks()` (added 2026-11-11; it was missing before), so `SIGTERM` triggers a graceful close. Draining behaviour under load is not yet tested. Reload NestJS through whatever process manager the target host actually uses (container restart or process manager reload); that choice is not yet decided or documented.

## 5. Scheduler, queue and webhook ownership

Never run Laravel and NestJS consumers for the same job type against the same production queue (double-processing of financial events).

| Concern | Laravel today | NestJS (exists in code) | Rule |
|---|---|---|---|
| Close expired lots | scheduler | `close-expired-lots` cron | Run NestJS in 14-day parallel on **staging**; switch production atomically with the auction domain |
| Bid-authorization expiry | scheduler | `expire-bid-authorizations` cron | Same |
| Domain-event dispatch | `DispatchDomainEventsCommand` | `dispatch-domain-events` cron (lease claims) | One consumer per environment |
| Transfer outbox | Laravel job | `dispatch-transfer-outbox` cron | Switch only with settlement/payout |
| Stale-claim recovery | — | `recover-stale-claims` cron | NestJS-only; leases are the multi-instance guard |
| Stripe webhooks | `/stripe/webhook` (primary) | `/api/v1/stripe/webhook` | Shadow mode: Stripe delivers to both, only Laravel writes, ≥30 clean days before switching (`PRODUCTION_CUTOVER_PLAN.md`) |

NestJS crons register at app start. A NestJS instance pointed at a shared DB while Laravel still owns those domains **would run its crons and write**. Start every such instance with `SCHEDULER_ENABLED=false` (supported since 2026-11-11; default is enabled, so the safe value must be set explicitly). Finer per-domain flags do not exist yet — the switch is all-or-nothing for the five crons.

## 6. Rollback

1. Remove/comment the NestJS `location` block for the domain; `nginx -t && nginx -s reload`.
2. Confirm the `X-Backend` header now reports Laravel.
3. If NestJS wrote during the window: run reconciliation (`ledger_entries` idempotency keys, settlement conservation) before declaring recovery; follow `docs/runbooks/rollback.md` financial checks.
4. Record the incident in `docs/incidents/`; mark the domain back in `DOMAIN_OWNERSHIP_MATRIX.md`.

Targets (from `PRODUCTION_CUTOVER_PLAN.md` / `LARAVEL_EXIT_PLAN.md`): rollback completes in **< 5 minutes**; roll back immediately if 5xx > **1%** in the 15-minute watch window; 24-hour post-cutover thresholds are 5xx < **0.1%**, P95 within 20% of Laravel baseline, queue depth stable, zero reconciliation mismatches, zero Stripe webhook failures.

## 7. Financial-domain extra gate

Settlement, payout, ledger, reconciliation, transfers and refunds additionally require: three-ledger invariant tests green (`ledger_entries` ≠ `donations` ≠ `impact_events`); cent-for-cent parity on sampled historical transactions; second-engineer review of the settlement diff; verified restore from backup (not just a taken backup); and, for refunds, the refund-dispatch capability, which does not exist in NestJS yet.
