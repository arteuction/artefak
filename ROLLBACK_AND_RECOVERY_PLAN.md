# ARTeuCtion — Rollback and Recovery Plan

**Date:** 2026-11-03

---

## 1. Rollback Decision Tree

```
Issue detected in NestJS production
         │
         ├── Financial data corruption or loss
         │   └── IMMEDIATE: stop NestJS, restore from backup, alert team
         │
         ├── Payment processing failure (Stripe)
         │   └── Revert Stripe webhook endpoint to Laravel
         │       NestJS continues serving non-financial reads
         │
         ├── Auth failure (users cannot log in)
         │   └── Route all traffic back to Laravel via load balancer
         │
         ├── High error rate (>1% of requests)
         │   └── Route 100% of traffic back to Laravel
         │       Investigate NestJS in isolation
         │
         └── Single domain failure
            └── Route that domain's routes back to Laravel only
                Keep other domains on NestJS
```

---

## 2. Rollback Commands

### 2.1 Stop NestJS (all traffic returns to Laravel)

```bash
# Stop NestJS API
docker compose -f docker-compose.nestjs.yml down

# Verify Laravel is responding
curl https://arteuction.com/api/health
```

### 2.2 Revert Stripe Webhook Endpoint

```bash
# In Stripe Dashboard: Developers > Webhooks
# Disable NestJS endpoint:
#   https://api.arteuction.com/api/v1/stripe/webhook
# Ensure Laravel endpoint is active:
#   https://arteuction.com/api/webhooks/stripe
```

### 2.3 Database Restore

```bash
# List available backups
# (backup location depends on infrastructure configuration)

# Restore to point before NestJS migration
mysqldump arteuction > pre_nestjs_backup.sql

# To restore:
mysql arteuction < pre_nestjs_backup.sql
```

---

## 3. Recovery Scenarios

### 3.1 Duplicate Stripe Transfer Detected

**Symptom:** Two `transfer_outbox` entries show `completed` but Stripe shows two transfers

**Cause:** Outbox processed by two workers simultaneously before `idempotency_key` landed

**Recovery:**
1. Stop NestJS scheduler (`SCHEDULER_ENABLED=false` env var)
2. Query Stripe for duplicate transfers:
   ```
   stripe transfers list --limit 100 | grep 'transfer_outbox'
   ```
3. Issue Stripe reversal for the duplicate transfer
4. Manually reconcile `ledger_entries` — do NOT insert a second row; create a reversal entry
5. Audit `transfer_outbox` table — ensure each entry has exactly one `completed` row

**Prevention already in place:** Stripe `idempotency_key: transfer_outbox:${entry.id}` — same entry will not create two transfers at Stripe level even if called twice.

### 3.2 MariaDB Connection Lost During Auction Expiry

**Symptom:** Scheduler logs `Failed to close auction item N: ECONNREFUSED`

**Recovery:**
1. Auction items remain `open` — no data corruption
2. When DB connection restored, `closeExpiredLots` will pick up items on next CRON tick
3. Items are closed idempotently (`if status !== 'open' return`)

**Action:** No manual recovery needed. Monitor scheduler logs for successful close.

### 3.3 WebSocket Connections Drop (NestJS restart)

**Symptom:** Clients lose real-time bid updates

**Recovery:**
1. Clients should reconnect automatically (socket.io-client has built-in reconnection)
2. On reconnect, clients fetch current bid state via REST: `GET /api/v1/auctions/{id}/items`
3. No bids are lost — bids are persisted in DB before WebSocket emit

**Frontend implementation needed:** `onDisconnect` handler should trigger REST re-fetch.

### 3.4 JWT Secret Rotation

**If JWT_SECRET must be rotated (e.g., compromise suspected):**
1. Update `JWT_SECRET` in `.env.staging` / production secrets
2. Restart NestJS — all existing tokens are immediately invalid
3. Users must re-login — this is intentional
4. Monitor auth error rate spike then normalise over next 15 minutes

**Note:** There is no token revocation list currently. Rotation invalidates all tokens.

---

## 4. Database Backup Policy

| Backup type | Frequency | Retention | Location |
|---|---|---|---|
| Full dump | Daily | 30 days | (configure in infrastructure) |
| Incremental binlog | Continuous | 7 days | (configure in infrastructure) |
| Pre-migration snapshot | On demand | 90 days | Label: `pre_nestjs_YYYYMMDD` |

**Critical:** Take a manual backup immediately before each Phase C cutover step (write traffic migration per domain).

---

## 5. Monitoring Alerts

Configure the following alerts before Phase B:

| Alert | Threshold | Action |
|---|---|---|
| NestJS error rate | >1% of requests in 5 min | Page on-call |
| Stripe webhook failure | Any 5xx response | Page on-call |
| `transfer_outbox` failed entries | Count > 0 | Alert team |
| `domain_events` failed entries | Count > 5 | Alert team |
| DB connection pool exhausted | Queue depth > 10 | Alert team |
| NestJS health check fails | 3 consecutive failures | Auto-restart + alert |

---

## 6. Contact and Escalation

| Scenario | Primary action |
|---|---|
| Financial data inconsistency | Stop all writes, alert team immediately |
| Stripe payment failure | Revert webhook, investigate in test mode |
| Auth system failure | Route to Laravel, investigate NestJS separately |
| Search unavailable | Meilisearch down — SQL fallback is automatic |
| Email not delivering | Check MAIL_HOST config; emails are not retried |

---

## 7. Laravel Preservation Commitment

Per project invariants:
- Laravel code is **never deleted** during NestJS migration
- Laravel authentication code is **never modified**
- Laravel remains able to serve production traffic throughout Phase A–C
- Laravel is deprecated only after Phase D's 30-day clean period
