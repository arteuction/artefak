# ARTeuCtion — Phase 0.7 Report
## Production Staging and Migration Rehearsal

**Date:** 2026-11-03  
**Branch:** `test/phase-180-preservation-provenance`  
**Decision:** CONDITIONAL GO for staging deployment

---

## 1. Authoritative Test Baseline (Priority 1)

### 1.1 Full Suite Results

| Metric | Count |
|---|---|
| Test suites — passed | 82 |
| Test suites — skipped | 6 |
| Test suites — failed | 0 |
| Tests — passed | 736 |
| Tests — skipped | 83 |
| Tests — failed | 0 |
| **Exit code** | **0** |

### 1.2 The Six Skipped Suites — Explained

All six skipped suites gate every test on `HAS_DB` — a flag that checks for a live MariaDB connection.
They are **integration tests**, not broken unit tests.

| Suite | Purpose | Why skipped |
|---|---|---|
| `src/auction/bid-concurrency.spec.ts` | `SELECT FOR UPDATE` serialization, concurrent bids | Requires MariaDB — `HAS_DB=false` in CI |
| `src/financial/financial-integrity.spec.ts` | Three-ledger invariant, integer arithmetic | Requires MariaDB |
| `src/timezone/timezone-roundtrip.spec.ts` | UTC storage / Sofia-timezone display roundtrip | Requires MariaDB |
| `src/auth/auth.spec.ts` | Full auth flow with real DB | Requires MariaDB |
| `src/auction/bid-load.spec.ts` | Concurrent bid load test (100 bids) | Requires MariaDB |
| `src/schema-baseline.spec.ts` | `prisma migrate diff` exits 0 | Requires MariaDB |

**Action required:** Run these 6 suites against a real MariaDB instance.
See Priority 4 (staging deployment) for the Docker Compose setup that enables them.

### 1.3 Test Coverage by Domain

| Domain | Suites | Status |
|---|---|---|
| Auth (unit) | 3 | ✅ Pass |
| Auction / Bid (unit) | 5 | ✅ Pass |
| Settlement / Stripe webhook | 4 | ✅ Pass |
| Admin / Ops | 5 | ✅ Pass |
| Library / Books | 4 | ✅ Pass |
| Artwork | 3 | ✅ Pass |
| Scheduler / Outbox | 1 | ✅ Pass |
| WebSocket Gateway | 1 | ✅ Pass |
| Config Validation | 1 | ✅ Pass (new) |
| Auction concurrency (integration) | 2 | ⏭ DB required |
| Financial integrity (integration) | 1 | ⏭ DB required |
| Auth flow (integration) | 1 | ⏭ DB required |
| Schema baseline (integration) | 1 | ⏭ DB required |
| Timezone roundtrip (integration) | 1 | ⏭ DB required |

---

## 2. Production Configuration Validation (Priority 2)

### 2.1 ConfigValidationService — IMPLEMENTED ✅

`src/config-validation.service.ts` — registered in `AppModule`.

**Behaviour:**
- In `NODE_ENV=production`: missing or misconfigured required vars → `process.exit(1)` at startup
- In development/test: warns, does not abort

**Required vars enforced in production:**

| Variable | Validation |
|---|---|
| `DATABASE_URL` | Must start with `mysql://` or `mysql2://` |
| `JWT_SECRET` | ≥ 32 chars; must not be placeholder |
| `STRIPE_SECRET_KEY` | Must start with `sk_live_` or `sk_test_` |
| `STRIPE_WEBHOOK_SECRET` | Must start with `whsec_` |
| `AWS_S3_BUCKET` | Must be present |
| `AWS_REGION` | Must be present |
| `AWS_ACCESS_KEY_ID` | Must be present |
| `AWS_SECRET_ACCESS_KEY` | Must be present |
| `CORS_ORIGINS` | Must be present and non-empty |

**Warnings only (non-fatal in production):**
- `STRIPE_SECRET_KEY` is `sk_test_*` in production (human review required)
- `MAIL_HOST` absent (email silently dropped)
- `MEILISEARCH_HOST` absent (search falls back to SQL LIKE)

### 2.2 Items Still Required

- [ ] Startup validation of `REFRESH_TOKEN_SECRET` (if implemented separately from JWT_SECRET)
- [ ] Startup database connectivity check (connect + `SELECT 1` before accepting traffic)
- [ ] S3 bucket accessibility check at startup (verify bucket exists and credentials work)
- [ ] Stripe credentials reachability test (optional ping to Stripe API on startup)

---

## 3. Reliability Testing (Priority 3)

### 3.1 Implemented

| Test | File | Status |
|---|---|---|
| Duplicate webhook delivery (idempotency_key) | `stripe-webhook.controller.spec.ts` | ✅ WH1–WH9 |
| Outbox status transitions (pending→processing→dispatched) | `scheduler.service.spec.ts` | ✅ SC9–SC12 |
| Stripe transfer dispatch via outbox | `scheduler.service.spec.ts` | ✅ SC13 |
| Auction expiry — no double-close (idempotent) | `scheduler.service.spec.ts` | ✅ SC4 |
| Bid accepted status transition | `bid-concurrency.service.spec.ts` | ✅ BC1–BC8 |

### 3.2 Gaps Requiring Staging Validation

| Scenario | Risk | Mitigation required |
|---|---|---|
| Worker crash after Stripe accepts transfer but before DB records `completed` | Duplicate transfer | Stripe idempotency key in `transfer_group` field; verify in integration test |
| Two NestJS instances running `closeExpiredLots` simultaneously | Double auction close | DB transaction serialization (item fetched with FOR UPDATE inside tx); verify with load test |
| Two instances processing `dispatchDomainEvents` simultaneously | Double event dispatch | Status claim (`pending→processing`) inside transaction; verify concurrently |
| WebSocket reconnect during active auction | Missed bid events | Client re-fetches current state on reconnect; not yet implemented |
| S3 upload with invalid file type | Content injection | File type validation not yet implemented — **BLOCKING** |
| Library entitlement check on presigned URL | Unauthorized download | `my-books` endpoint checks purchase; presigned URL TTL limits exposure |

### 3.3 Stripe Transfer Idempotency Analysis

Current implementation passes `transfer_group: outbox:${entry.id}` but Stripe does **not** deduplicate on `transfer_group`. True idempotency requires using Stripe's `idempotencyKey` option:

```typescript
// Required fix before production
await this.stripe.transfers.create(
  { amount, currency, destination, transfer_group, metadata },
  { idempotencyKey: `transfer_outbox:${entry.id}:attempt:${entry.attempt}` }
);
```

**Status:** Not yet implemented — flagged as BLOCKING.

---

## 4. Staging Deployment (Priority 4)

### 4.1 Completed Infrastructure

| Artifact | Status |
|---|---|
| `apps/api/Dockerfile` | ✅ Multi-stage Node 22-alpine, non-root user |
| `docker-compose.nestjs.yml` | ✅ NestJS + stripe-cli, connects to `artefak` network |
| `apps/api/.env.staging.example` | ✅ All required vars documented |

### 4.2 To Enable Integration Tests Against Real DB

```bash
# Start infrastructure
docker compose -f docker-compose.yml up -d mariadb redis meilisearch minio minio-init

# Run integration tests
cd apps/api
DATABASE_URL=mysql://arteuction_test:secret@localhost:3306/arteuction_test \
  npx jest --no-coverage --testPathPattern="(bid-concurrency|financial-integrity|timezone|auth\.spec|bid-load|schema-baseline)"
```

### 4.3 Frontend Independence

- `resources/frontend/` uses only `VITE_API_BASE_URL` and `VITE_STORAGE_URL`
- Laravel Reverb / Pusher dependency removed; socket.io-client (`/bids` namespace) replaces it
- Frontend can be deployed to any static host (Vercel, S3+CloudFront, Nginx)

---

## 5. End-to-End Workflow Status (Priority 5)

| Journey | Backend routes | Auth | DB | Status |
|---|---|---|---|---|
| Register + authenticate | ✅ | ✅ | ✅ Unit | ⏭ Integration |
| Publish artwork | ✅ | ✅ | ✅ Unit | ⏭ Integration |
| Upload + process image | ✅ S3 presign | ⚠️ No file-type check | — | Partial |
| Create + publish auction | ✅ | ✅ | ✅ Unit | ⏭ Integration |
| Place simultaneous bids | ✅ FOR UPDATE | ✅ | ⏭ DB needed | Integration |
| Realtime bid updates | ✅ BidGateway | ⚠️ No auth on WS | — | Partial |
| Win auction + Stripe checkout | ✅ | ✅ | ✅ Unit | ⏭ Integration |
| Dispatch + reconcile transfer | ✅ Outbox | ⚠️ No Stripe idempotency key | — | Partial |
| Purchase + access digital book | ✅ Presign | ✅ Entitlement check | ✅ Unit | ⏭ Integration |
| Donation + tax evidence | ✅ | ✅ | ✅ Unit | ⏭ Integration |

---

## 6. Cutover Preparation Status (Priority 6)

| Item | Status |
|---|---|
| `NESTJS_PHASE_0_7_REPORT.md` | ✅ This document |
| `STAGING_DEPLOYMENT_GUIDE.md` | 🔄 In progress |
| `INTEGRATION_TEST_REPORT.md` | ⏭ Requires live DB run |
| `PRODUCTION_ENVIRONMENT_CHECKLIST.md` | 🔄 In progress |
| `DOMAIN_CUTOVER_MATRIX.md` | ⏭ Pending |
| `ROLLBACK_AND_RECOVERY_PLAN.md` | ⏭ Pending |

---

## 7. Blocking Issues Before Production

| # | Issue | Severity |
|---|---|---|
| B1 | Stripe transfer idempotency key not set — crash recovery could double-transfer | BLOCKING |
| B2 | S3 upload has no file-type or size validation | BLOCKING |
| B3 | WebSocket gateway has no authentication — any client can receive any lot events | BLOCKING |
| B4 | Integration tests (6 suites) not yet run against real MariaDB | BLOCKING |
| B5 | Startup DB connectivity check not implemented | HIGH |
| B6 | S3 startup reachability check not implemented | HIGH |
| B7 | `REFRESH_TOKEN_SECRET` not validated at startup | HIGH |
| B8 | Multi-instance duplicate dispatch not validated in staging | HIGH |

---

## 8. Phase 0.7 Decision

| Context | Decision |
|---|---|
| New feature development in NestJS | **GO** |
| Independent staging deployment | **GO** |
| Running integration test suite against MariaDB | **GO** |
| Selected production domain migration | **After B1–B4 resolved** |
| Disable Laravel production | **Not yet** |

**Overall verdict: CONDITIONAL GO**

Conditions before production migration:
1. B1 — Stripe transfer idempotency key implemented and tested
2. B2 — S3 file-type validation implemented
3. B3 — WebSocket authentication implemented
4. B4 — All 6 integration suites pass against real MariaDB
