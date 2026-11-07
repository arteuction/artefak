# P0.5 — Traffic Switch & Rollback Runbook

> **Status:** NOT STARTED (live staging/VPS required to execute)  
> **Prerequisite gates:** P0.1 (migrations deployed), P0.2 (integration tests green), P0.4 (Stripe E2E rehearsal)  
> **Owner:** Staff Engineer / DevOps lead  
> **Estimated execution time:** 30-45 min per phase

---

## 1. Route Ownership Matrix

The transition moves route ownership from **Laravel (PHP)** to **NestJS (Node)** incrementally.
The two stacks run on the same domain during transition; Nginx decides which upstream handles each prefix.

| Route prefix | Current owner | Target owner | Notes |
|---|---|---|---|
| `/api/auth/*` | Laravel | Laravel | **NEVER switch until auth parity proven** (see INVARIANTS.md) |
| `/api/artworks/*` | Laravel | NestJS | Switch only after AW spec + HAS_DB green |
| `/api/art-lots/*` | Laravel | NestJS | Depends on ArtLot domain parity |
| `/api/auctions/*` | Laravel | NestJS | Auction state machine must match |
| `/api/admin/*` | Laravel | NestJS | Admin routes last — most blast radius |
| `/api/library/*` | Laravel | NestJS | LS spec must be 100% green |
| `/api/disputes/*` | Laravel | NestJS | DS spec must be 100% green |
| `/api/donations/*` | Laravel | Laravel | Donation ledger — do NOT mix with `ledger_entries` |
| `/*` (web UI) | Shared hosting / CDN | CDN / Vercel | Outside NestJS scope |

**Single-writer rule:** At any moment, exactly one stack owns each route prefix.
Dual-write is explicitly forbidden (see INVARIANTS.md). There is no dual-write phase.

---

## 2. Pre-Switch Checklist

Run before switching ANY prefix to NestJS:

- [ ] `prisma migrate deploy` exits 0 on staging DB (P0.1)
- [ ] `prisma migrate diff --from-config-datasource --to-schema=prisma/schema.prisma --exit-code` exits 0 (no drift)
- [ ] All 83 HAS_DB integration tests green on `arteuction_test` MariaDB (P0.2)
- [ ] Stripe webhook test received and processed correctly (P0.4)
- [ ] NestJS health endpoint `GET /api/health` returns `{"status":"ok"}` on staging
- [ ] Laravel still responding on its own port (staging)
- [ ] Nginx config reviewed and reload tested in dry-run

---

## 3. Nginx Switch Procedure

### 3a. Current (Laravel-only) config skeleton

```nginx
upstream laravel {
    server 127.0.0.1:9000;  # PHP-FPM
}

location /api/ {
    proxy_pass http://laravel;
}
```

### 3b. Per-prefix switch (NestJS takes one domain)

For each prefix being switched, add a `location` block **above** the catch-all:

```nginx
upstream nestjs {
    server 127.0.0.1:3000;  # NestJS
    keepalive 32;
}

# Switch /api/artworks to NestJS
location /api/artworks {
    proxy_pass http://nestjs;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}

# All other /api/* routes remain on Laravel
location /api/ {
    proxy_pass http://laravel;
}
```

### 3c. Reload Nginx (zero-downtime)

```bash
nginx -t                  # validate — must print "syntax is ok"
systemctl reload nginx    # graceful reload, no dropped connections
```

### 3d. Verify after reload

```bash
curl -I https://staging.arteuction.com/api/artworks | grep x-powered-by
# Should return nothing (NestJS does not send X-Powered-By)
# Laravel returns: X-Powered-By: PHP/8.x
```

---

## 4. In-Flight Request Draining

NestJS handles draining automatically via `app.enableShutdownHooks()`.
Before reloading Nginx or stopping NestJS:

1. Send `SIGTERM` to the NestJS process — it stops accepting new connections
2. Wait for `onApplicationShutdown` hook to fire (up to 10 s by default)
3. All in-flight requests complete before process exits
4. Reload Nginx after NestJS confirms listening on port 3000 again

For PM2-managed NestJS:
```bash
pm2 reload nestjs-api --update-env  # zero-downtime reload
pm2 logs nestjs-api --lines 50      # verify no errors
```

---

## 5. Scheduler / Queue Worker Ownership

| Worker | Stack | Table written | Notes |
|---|---|---|---|
| Auction auto-close cron | Laravel | `auction` | Do NOT run NestJS cron concurrently |
| Settlement calculation | Laravel | `settlements`, `settlement_lines` | Financial — single writer mandatory |
| Transfer outbox processor | Laravel | `transfer_outbox` | Migrate only after full settlement parity |
| Email queue | Laravel | `jobs` | NestJS uses separate Bull/Redis queues |
| NestJS health-check cron | NestJS | — | Read-only, safe to run in parallel |

**Rule:** Scheduler ownership switches atomically with the route that owns that domain.
Never run both stacks' schedulers for the same domain simultaneously.

---

## 6. Rollback Procedure

### 6a. Instant rollback (< 2 minutes)

If NestJS is misbehaving after a prefix switch:

```bash
# Remove the NestJS location block from nginx config (comment it out)
vi /etc/nginx/sites-available/arteuction

# Reload Nginx — traffic returns to Laravel immediately
nginx -t && systemctl reload nginx
```

No database changes needed: Laravel owns the schema throughout transition.

### 6b. Rollback verification

```bash
curl https://staging.arteuction.com/api/artworks | grep -i "X-Powered-By"
# Should return PHP/8.x header confirming Laravel is responding
```

### 6c. Post-rollback actions

1. File incident report with exact timestamps and error signatures
2. Update `HARD_BLOCKER_STATUS.md` — mark the failed phase as BLOCKED
3. Do NOT re-attempt switch until root cause is identified and a new test covers it

---

## 7. Go/No-Go Decision Tree

```
Is prisma migrate diff exit code 0?
  NO  → STOP. Run migrations first (P0.1).
  YES ↓

Are all HAS_DB tests green?
  NO  → STOP. Fix integration test failures (P0.2).
  YES ↓

Is NestJS /api/health returning 200 on staging?
  NO  → STOP. Fix application startup.
  YES ↓

Does the target domain have 100% unit + integration coverage?
  NO  → STOP. Add missing tests first.
  YES ↓

Proceed with nginx switch for that prefix only.
Monitor error rates for 15 minutes before switching next prefix.
```

---

## 8. Monitoring During Switch

Watch these signals for 15 minutes after each prefix switch:

```bash
# NestJS application errors
pm2 logs nestjs-api --lines 100 --err

# Laravel error log (should be quiet for switched routes)
tail -f /var/log/nginx/error.log

# HTTP 5xx rate — should stay at 0
grep " 5[0-9][0-9] " /var/log/nginx/access.log | tail -20
```

**Automatic rollback trigger:** Any HTTP 5xx rate > 1% over 5 minutes → roll back immediately.

---

## 9. Financial Domain — Extra Gate

Routes touching `ledger_entries`, `settlements`, or `settlement_lines` require an **additional sign-off** before switch:

- [ ] Three-ledger invariant test suite green (`ledger_entries` ≠ `donations` ≠ `impact_events`)
- [ ] Settlement calculation produces identical output vs. Laravel for last 30 days of test data
- [ ] Stripe payout rehearsal successful (P0.4)
- [ ] A second engineer has reviewed the settlement service diff

The financial domain switch is the **last** switch, not the first.

---

*Last updated: 2026-11-07 | Branch: test/phase-180-preservation-provenance*
