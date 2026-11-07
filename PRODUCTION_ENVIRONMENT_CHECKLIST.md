# ARTeuCtion — Production Environment Checklist

**Date:** 2026-11-03  
**Status:** Pre-production (staging validation in progress)

Use this checklist before authorizing any production traffic migration from Laravel to NestJS.

---

## Section 1 — Required Environment Variables

All validated at startup by `ConfigValidationService`. Process refuses to start if any are missing or invalid.

| Variable | Requirement | Status |
|---|---|---|
| `DATABASE_URL` | `mysql://` prefix; production MariaDB | ✅ Validated at startup |
| `JWT_SECRET` | ≥32 chars; not a placeholder string | ✅ Validated at startup |
| `STRIPE_SECRET_KEY` | `sk_live_` for production; `sk_test_` for staging | ✅ Validated at startup |
| `STRIPE_WEBHOOK_SECRET` | `whsec_` prefix | ✅ Validated at startup |
| `AWS_S3_BUCKET` | Production bucket name | ✅ Validated at startup |
| `AWS_REGION` | AWS region | ✅ Validated at startup |
| `AWS_ACCESS_KEY_ID` | Production credentials | ✅ Validated at startup |
| `AWS_SECRET_ACCESS_KEY` | Production credentials | ✅ Validated at startup |
| `CORS_ORIGINS` | Comma-separated production origins | ✅ Validated at startup |
| `MAIL_HOST` | SMTP host (warned if absent) | ⚠️ Warning only |
| `MEILISEARCH_HOST` | Meilisearch URL (warned if absent) | ⚠️ Warning only |
| `NODE_ENV` | Must be `production` | ✅ Required |
| `PORT` | Default 3000 | Optional |

---

## Section 2 — Pre-deploy Verification

### 2.1 Integration Tests
- [ ] All 6 DB-gated suites pass against production-equivalent MariaDB
- [ ] `npx prisma migrate status` shows no pending migrations
- [ ] Schema diff (`prisma migrate diff`) exits 0

### 2.2 Stripe
- [ ] Webhook endpoint registered in Stripe Dashboard for production domain
- [ ] Webhook signing secret matches `STRIPE_WEBHOOK_SECRET`
- [ ] `sk_live_` key confirmed (not `sk_test_`)
- [ ] Transfer idempotency keys verified in staging test

### 2.3 S3
- [ ] Production bucket exists and is accessible with production credentials
- [ ] Bucket CORS policy allows uploads from production frontend origin
- [ ] Presigned URL expiry is appropriate (default 1 hour)
- [ ] File-type validation confirmed: only jpeg/png/webp/tiff accepted

### 2.4 Auth
- [ ] `JWT_SECRET` rotated from any staging/development value
- [ ] JWT expiry configured appropriately
- [ ] Refresh token flow verified end-to-end

### 2.5 WebSocket
- [ ] JWT authentication on WS connection verified in staging
- [ ] CORS origins include production frontend domain
- [ ] Load balancer / proxy configured for WebSocket upgrade (sticky sessions or Redis adapter)

### 2.6 Database
- [ ] Production MariaDB backup taken immediately before migration
- [ ] Backup restore rehearsed
- [ ] Connection pool sizing reviewed for expected load
- [ ] `SELECT 1` startup connectivity check implemented (see Section 3 below)

---

## Section 3 — Outstanding Implementation Items

These must be completed before production migration:

| Item | Priority | Status |
|---|---|---|
| Startup DB connectivity check (`SELECT 1`) | HIGH | ❌ Not implemented |
| S3 startup reachability check | HIGH | ❌ Not implemented |
| `REFRESH_TOKEN_SECRET` validation at startup | HIGH | ❌ If used separately |
| Multi-instance domain event deduplication test | HIGH | ❌ Not validated |
| Multi-instance auction close deduplication test | HIGH | ❌ Not validated |
| WebSocket Redis adapter for multi-instance | MEDIUM | ❌ Not implemented |
| Library book file upload (PDF to S3) | MEDIUM | ❌ Not implemented |
| S3 upload size limit enforced server-side (not just presign) | MEDIUM | ❌ Not enforced |
| Dispute file attachment S3 validation | LOW | ❌ Not implemented |

---

## Section 4 — Monitoring Requirements

Before going live, confirm the following are in place:

- [ ] Application health endpoint monitored (`/api/v1/health`)
- [ ] Error rate alerting (Stripe webhook failures, payment errors)
- [ ] Outbox dead-letter monitoring (domain_events with status=failed, attempt≥5)
- [ ] Transfer outbox monitoring (transfer_outbox with status=failed)
- [ ] DB connection pool exhaustion alerting
- [ ] S3 upload failure rate monitoring
- [ ] WebSocket connection count monitoring

---

## Section 5 — Financial Safety

**These invariants must be verified before any financial traffic migrates:**

- [ ] Three-ledger invariant verified: `ledger_entries` ≠ `donations` ≠ `impact_events`
- [ ] No floating-point arithmetic in settlement calculations
- [ ] Settlement calculator spec passes: SC1–SC13
- [ ] Financial integrity spec passes against real MariaDB
- [ ] Stripe transfer idempotency key confirmed in production transfer tests
- [ ] Refund flow does not debit `donations` ledger
- [ ] Tax receipt generation uses `donations` table only

---

## Section 6 — Go/No-Go Decision Matrix

| Gate | Criteria | Status |
|---|---|---|
| G1 | All 88 suites pass (including 6 DB-gated) | ❌ Pending DB run |
| G2 | Config validation blocks startup without required vars | ✅ Done |
| G3 | Stripe transfer idempotency key in production | ✅ Done |
| G4 | S3 file-type validation at API boundary | ✅ Done |
| G5 | WebSocket auth verified | ✅ Done (JWT check) |
| G6 | Staging deployment running and smoke tested | ❌ Pending |
| G7 | Financial integrity suite passes on real DB | ❌ Pending DB run |
| G8 | No dual-write race conditions | ❌ Pending multi-instance test |

**Minimum gates for production: G1–G7 must all be ✅**
