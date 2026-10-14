# Rollback and Recovery Runbook (Phase 155)

## Decision criteria

Roll back when any of the following occur in production:
- Payment intent created but settlement not written within 5 minutes
- Reconciliation run produces mismatch_count > 0 on a freshly settled auction
- `failed_jobs` table accumulates > 10 rows in < 1 hour
- Error rate on `/api/v1/*` exceeds 1% for 5 consecutive minutes
- Any `ledger_entries` row found with `idempotency_key IS NULL`

---

## Deployment rollback (failed deploy)

```bash
# 1. Identify the previous release commit
git log --oneline -10

# 2. Roll back to previous tag
git checkout v<previous-tag>

# 3. Re-run composer install with the previous lockfile
composer install --no-dev --optimize-autoloader

# 4. Restart PHP-FPM / queue workers
supervisorctl restart arteuction-worker:*
php-fpm reload
```

If a migration was run during the failed deploy:

```bash
# Roll back only the last batch
php artisan migrate:rollback --step=1 --force

# Verify no ledger_entries were affected
mysql -u arteuction -p arteuction -e "
  SELECT COUNT(*) FROM ledger_entries WHERE idempotency_key IS NULL;"
# Must return 0. If not: STOP — do not roll forward. Contact DBA.
```

**Financial rows (ledger_entries, donations, impact_events) are append-only.**
Never run `migrate:rollback` past a migration that adds financial columns without
verifying that no financial rows reference that structure.

---

## Queue recovery

```bash
# View failed jobs
php artisan queue:failed

# Retry all failed jobs (safe to run — they are idempotent via idempotency_key)
php artisan queue:retry all

# If a specific job class is looping, release it
php artisan queue:forget <job-id>

# Nuclear option — flush failed jobs after investigation
php artisan queue:flush
```

---

## Database recovery from backup

```bash
# 1. Stop all workers to prevent new writes
supervisorctl stop arteuction-worker:*

# 2. Restore from last known-good backup
mariadb-dump --single-transaction arteuction > /tmp/arteuction_$(date +%Y%m%d_%H%M%S)_pre_restore.sql
mysql -u root -p arteuction < /backup/arteuction_latest.sql

# 3. Verify financial integrity
mysql -u arteuction -p arteuction -e "
  SELECT
    COUNT(*) AS total_ledger,
    SUM(CASE WHEN idempotency_key IS NULL THEN 1 ELSE 0 END) AS null_keys,
    SUM(CASE WHEN type = 'credit' THEN amount_cents ELSE -amount_cents END) AS net_cents
  FROM ledger_entries;"

# net_cents must equal sum of all settled gross amounts.
# null_keys must be 0.

# 4. Restart workers
supervisorctl start arteuction-worker:*
```

---

## Stripe webhook replay

If the app was down during a Stripe event window, replay events via the Stripe CLI:

```bash
stripe events resend <event_id>
```

Or replay the last N hours from the Dashboard:
Stripe Dashboard → Developers → Webhooks → [endpoint] → Recent deliveries → Filter by time → Resend.

All webhook handlers are idempotent (idempotency_key on ledger_entries, stripe_event_id UNIQUE on settlements). Replaying already-processed events is safe.

---

## Rollback decision log

Document every rollback in `docs/incidents/` with:
- Date/time UTC
- Trigger condition
- Steps taken
- Financial rows affected (count, amount range)
- Resolution
