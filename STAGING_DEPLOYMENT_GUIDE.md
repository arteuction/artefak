# ARTeuCtion — Staging Deployment Guide

**Target:** Independent NestJS 12 + React 19/Vite stack alongside existing Laravel  
**Date:** 2026-11-03

---

## 1. Prerequisites

```
Docker ≥ 26 + Docker Compose v2
Node.js 22 (LTS)
Stripe CLI (for webhook forwarding)
A MariaDB 11 instance with arteuction_test database
```

---

## 2. Shared Infrastructure (MariaDB, Redis, MinIO, Meilisearch)

These services are defined in `docker-compose.yml` (the existing Laravel compose file).
Start only the infrastructure services — do not start the Laravel app:

```bash
docker compose -f docker-compose.yml up -d mariadb redis meilisearch minio minio-init
```

Wait for healthchecks:
```bash
docker compose -f docker-compose.yml ps
# mariadb, redis, meilisearch, minio should all show (healthy)
```

---

## 3. NestJS API

### 3.1 Environment

```bash
cp apps/api/.env.staging.example apps/api/.env.staging
# Edit apps/api/.env.staging — fill in:
#   JWT_SECRET (≥32 chars, not a placeholder)
#   STRIPE_SECRET_KEY (sk_test_... for staging)
#   STRIPE_WEBHOOK_SECRET (whsec_... from stripe listen output)
#   AWS_* (use MinIO defaults for staging: minioadmin / minioadmin)
#   MEILISEARCH_KEY (staging_master_key)
```

### 3.2 Start NestJS API

```bash
docker compose -f docker-compose.nestjs.yml build
docker compose -f docker-compose.nestjs.yml up -d
```

### 3.3 Verify Health

```bash
curl http://localhost:3000/api/v1/health
# Expected: {"status":"ok"}
```

### 3.4 Run Prisma Migrations

```bash
cd apps/api
DATABASE_URL=mysql://arteuction:secret@localhost:3306/arteuction \
  npx prisma migrate deploy
```

---

## 4. Frontend (React 19 + Vite)

### 4.1 Environment

```bash
cp resources/frontend/.env.example resources/frontend/.env.staging
# Set:
#   VITE_API_BASE_URL=http://localhost:3000/api/v1
#   VITE_STORAGE_URL=http://localhost:9000/arteuction-artworks
```

### 4.2 Build + Serve

```bash
cd resources/frontend
npm install
npm run build
# Serve the dist/ folder with any static server, e.g.:
npx serve dist -p 5173
```

Or for live development:
```bash
npm run dev
```

### 4.3 Verify Frontend Connects to NestJS

Open http://localhost:5173 — confirm network requests go to `:3000/api/v1`, not Laravel.

---

## 5. Running Integration Tests Against Real MariaDB

The 6 DB-gated suites require a live connection:

```bash
cd apps/api

# Create test database
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS arteuction_test; \
  GRANT ALL ON arteuction_test.* TO 'arteuction'@'%';"

# Run Prisma migrations on test DB
DATABASE_URL=mysql://arteuction:secret@localhost:3306/arteuction_test \
  npx prisma migrate deploy

# Run the integration suites
DATABASE_URL=mysql://arteuction:secret@localhost:3306/arteuction_test \
  NODE_OPTIONS=--experimental-vm-modules \
  npx jest --no-coverage \
    --testPathPattern="(bid-concurrency\.spec|financial-integrity|timezone-roundtrip|auth\.spec|bid-load|schema-baseline)"
```

Expected: 6 suites pass, ~83 previously-skipped tests now run.

---

## 6. Stripe Webhook Forwarding

```bash
# Install Stripe CLI: https://stripe.com/docs/stripe-cli
stripe listen \
  --api-key sk_test_... \
  --forward-to http://localhost:3000/api/v1/stripe/webhook \
  --events payment_intent.succeeded,payment_intent.payment_failed,charge.refunded,transfer.created

# Copy the webhook signing secret (whsec_...) to .env.staging STRIPE_WEBHOOK_SECRET
```

---

## 7. Smoke Test Checklist

Run these manually after staging is up:

- [ ] `GET /api/v1/health` returns `{"status":"ok"}`
- [ ] `POST /api/v1/auth/register` creates a user
- [ ] `POST /api/v1/auth/login` returns access token
- [ ] `GET /api/v1/artworks` returns artwork list
- [ ] `GET /api/v1/auctions` returns auction list
- [ ] `POST /api/v1/artworks/{id}/images/presign` returns S3 presigned URL
- [ ] `GET /api/v1/library/books` returns book list
- [ ] WebSocket connects to `ws://localhost:3000/bids` with valid JWT
- [ ] Stripe test webhook event processed (check logs)
- [ ] `GET /api/v1/api/docs` renders Swagger UI

---

## 8. Rollback

If NestJS staging causes issues, stop it without affecting Laravel:

```bash
docker compose -f docker-compose.nestjs.yml down
```

Laravel continues to run on its own compose stack. No shared state is modified.
