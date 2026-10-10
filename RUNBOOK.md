# ARTeuCtion Pilot Runbook

## Go-Live Checklist

Run before every deployment to production:

```bash
php artisan pilot:readiness
php artisan pilot:readiness --format=json   # for CI/CD gates
```

All checks must pass (exit 0) before cutover.

---

## Deployment Procedure

### 1. Pre-deploy
```bash
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci --legacy-peer-deps
npm run build
```

### 2. Maintenance mode
```bash
php artisan down --secret="YOUR_BYPASS_TOKEN"
```

### 3. Apply migrations
```bash
php artisan migrate --force
```

### 4. Clear caches
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### 5. Readiness check
```bash
php artisan pilot:readiness
```

### 6. Bring up
```bash
php artisan up
```

### 7. Smoke test
```bash
curl -s https://your-domain.com/api/v1/health | jq .status
```

---

## Rollback Procedure

```bash
# Put into maintenance mode
php artisan down

# Revert to previous release (adjust path for your deployment tool)
git checkout <previous-tag>

# Roll back last migration batch if schema changed
php artisan migrate:rollback --step=1 --force

# Rebuild caches from previous code
php artisan config:cache && php artisan route:cache

# Restore
php artisan up
```

---

## Incident Response

### Database full / slow queries
1. Check `php artisan pulse` dashboard → Slow Queries card
2. Run `php artisan db:show` to inspect connection pool
3. Scale read replica or add index as needed

### Stripe webhook failures
1. Check `transfer_outbox` table for stuck rows: `SELECT * FROM transfer_outbox WHERE status='pending' ORDER BY created_at LIMIT 10`
2. Re-dispatch: `php artisan stripe:retry-outbox`
3. Verify webhook secret: `STRIPE_WEBHOOK_SECRET` env var matches Stripe Dashboard

### Activity log / Pulse growing too large
- Prune activity log: `php artisan activitylog:clean --days=90`
- Prune Pulse: `php artisan pulse:purge`

### Failed domain events
- Check health: `GET /api/v1/health`
- Inspect: `SELECT * FROM domain_events WHERE processed_at IS NULL ORDER BY created_at LIMIT 20`

---

## Key Endpoints

| Purpose | URL |
|---------|-----|
| Health check | `GET /api/v1/health` |
| OpenAPI spec | `GET /docs/api.json` |
| Pulse dashboard | `GET /pulse` (admin only) |
| Search artworks | `GET /api/v1/search/artworks?q=` |
| Linked Art | `GET /api/v1/artworks/{id}/linked-art` |
| IIIF manifest | `GET /api/v1/artworks/{id}/iiif/manifest` |
| QR code | `GET /api/v1/artworks/{id}/qr` |

---

## Three-Ledger Integrity

**Never mix these tables:**

| Ledger | Table | Purpose |
|--------|-------|---------|
| Financial | `ledger_entries` | Sale proceeds, commissions |
| Donations | `donations` | SDG-linked charitable giving |
| Impact | `impact_events` | SDG impact tracking |

All financial records are **append-only**. Corrections must be new offsetting entries, never UPDATEs to confirmed rows.

---

## Currency

Bulgaria adopted EUR on **2026-01-01**. All settlements are EUR-only. BGN no longer exists in any payment flow.

---

## Contacts

| Role | Responsibility |
|------|---------------|
| Pilot lead | Go/no-go decisions |
| Backend on-call | API, DB, Stripe |
| Stripe support | `https://support.stripe.com` |
