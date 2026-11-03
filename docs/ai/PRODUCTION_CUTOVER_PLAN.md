# Production Cutover Plan — ARTeuCtion

_Generated: 2026-11-03. This is a planning document. Execute steps only after explicit user direction and Stage 2 exit criteria are met._

---

## Core Principle: Single Writer at All Times

At no point does any domain have two authoritative writers. Before NestJS goes live for a domain, Laravel for that domain is either:
- (a) routed past via reverse proxy to NestJS, or
- (b) deactivated (route returns 301/410)

Never operate in uncontrolled dual-write mode.

---

## Staging Environment Requirements

### Minimum Viable Staging

| Component | Requirement |
|-----------|-------------|
| Database | MariaDB snapshot of production, refreshed weekly. Named `arteuction_staging`, not `arteuction_test`. |
| NestJS API | Deployed on staging server, pointing to staging DB |
| Laravel API | Running in parallel on staging, pointing to same staging DB (for comparison) |
| Stripe | Test mode keys only. Use Stripe test cards. Never production keys in staging. |
| S3 | Separate staging bucket, not production. Prefixed `staging/`. |
| Meilisearch | Separate staging index. Seeded from production snapshot. |
| Reverb/WebSocket | Staging Reverb instance or Pusher sandbox. |
| Queue Workers | Both Laravel and NestJS workers running against staging queues. |
| Email | Sandbox mode only (Postmark test mode or Mailtrap). Never real recipient addresses. |

### Staging Acceptance Gate

Before any production cutover for a domain:
1. Feature must be exercised in staging via integration test suite
2. Laravel and NestJS responses compared for all affected routes (automated diff)
3. No data inconsistencies in staging DB after 48-hour soak

---

## Database Backup and Recovery

### Before Every Cutover Step

```
# On production host (run by operator, not automated)
mysqldump --single-transaction arteuction > arteuction_pre_cutover_YYYYMMDD_HHMMSS.sql
# Upload to backup S3 bucket with 90-day retention
# Record backup file name + SHA256 hash in cutover log
```

### Point-in-Time Recovery

- MariaDB binary logging must be enabled on production host
- PITR target: last known-good state before cutover step began
- Recovery time objective: < 30 minutes
- Recovery point objective: < 5 minutes of data loss

### Restore Test

Do not proceed to Stage 3 until a full restore from backup has been exercised in a test environment and verified against expected data. Record the restore time in the cutover log.

---

## Traffic Routing Strategy

### Recommended Approach: Reverse Proxy with Domain-Based Routing

Use Nginx (or equivalent) as the entry point. Route by prefix/domain:

```nginx
# Phase: NestJS handles auth; Laravel handles everything else
location /api/v1/auth {
    proxy_pass http://nestjs-upstream;
}

# Default: Laravel
location /api {
    proxy_pass http://laravel-upstream;
}
```

As each domain cuts over, add a more specific `location` block pointing to NestJS. This allows atomic per-domain switching with instant rollback (update Nginx config, reload — no restart).

### Alternatively: Feature Flag in API Gateway

Use a header-based feature flag (e.g., `X-Backend: nestjs`) for shadow testing without user impact, then flip the flag per-domain using environment variable or config service.

---

## Domain-by-Domain Cutover Procedure

For each domain, execute these steps in order. Do not skip steps.

### Pre-Cutover Checklist (all domains)
- [ ] Stage 2 exit criteria met for this domain
- [ ] Staging integration tests green for this domain
- [ ] Response comparison (Laravel vs NestJS) shows zero diffs for 48 hours
- [ ] Database backup taken and verified
- [ ] Rollback procedure tested in staging
- [ ] On-call engineer available
- [ ] Cutover window: low-traffic period (e.g., weeknight 02:00–04:00 local time)

### Cutover Steps
1. Take DB backup (documented above)
2. Add NestJS proxy route for this domain in Nginx config
3. Reload Nginx (zero-downtime: `nginx -s reload`)
4. Verify NestJS health endpoint responds correctly
5. Send 5 test requests through the new path; verify responses
6. Monitor error rates for 15 minutes
7. If error rate > 1%: rollback immediately (see below)
8. If clean: mark domain as CUTOVER COMPLETE in DOMAIN_OWNERSHIP_MATRIX.md
9. After 24 hours stable: disable Laravel routes for this domain

### Rollback (per domain)
1. Remove or comment out the NestJS proxy block for this domain in Nginx
2. Reload Nginx
3. Verify Laravel is responding for this domain
4. If data was written to NestJS during the cutover window: run reconciliation check

---

## Stripe Webhook Transition

### Shadow Mode (Weeks 1–4)
1. Keep Laravel `/stripe/webhook` endpoint as the production Stripe webhook target
2. Add a NestJS endpoint at `/api/v1/stripe/webhook`  
3. Configure Stripe to deliver to BOTH endpoints (Stripe supports multiple webhook endpoints)
4. NestJS endpoint logs every received event and its processing result
5. Laravel endpoint continues writing to DB
6. Compare: for every event ID, verify NestJS would have produced the same DB writes

### Comparison Gate
- Zero discrepancies required over 30 consecutive days
- Discrepancy: any event where NestJS output differs from Laravel output
- Track discrepancies in a reconciliation log; fix NestJS implementation for each

### Cutover
1. After 30-day clean comparison: switch Stripe dashboard primary webhook to NestJS endpoint
2. Keep Laravel endpoint as secondary for 7 days (Stripe will deliver to both)
3. After 7 days: remove Laravel endpoint from Stripe dashboard
4. Monitor NestJS webhook processing for 30 more days before decommissioning Laravel webhook handler

---

## Queue and Scheduled Task Transition

### Queue Workers

For each job (CloseAuctionItemJob, DispatchStripeTransfer, DispatchStripeReversal, GenerateArtworkDerivativesJob, HandleStripeWebhook):

1. Implement NestJS equivalent with identical business logic
2. Run NestJS worker against staging queue for 14 days
3. Monitor: no failed jobs, dead-letter queue empty
4. Switch production queue workers to NestJS
5. Keep Laravel worker running in shadow mode for 7 days (consuming from a mirrored queue)
6. After 7 days clean: stop Laravel worker for this job

**Critical:** Never run both Laravel and NestJS workers against the same production queue for the same job type. This would cause double-processing of financial events.

### Scheduled Commands

| Command | Transition Approach |
|---------|-------------------|
| RunNightlyReconciliationCommand | Run NestJS equivalent in parallel for 14 days, compare output. Cutover after zero discrepancies. |
| CloseExpiredLotsCommand | Same parallel approach. Time-critical: auction integrity depends on this. |
| ExpireBidAuthorizations | Same. Financial: must not double-expire or miss expirations. |
| DispatchDomainEventsCommand | Port NestJS outbox processor. Test with synthetic events before cutover. |
| GovernanceHealthCommand | Port and run; low risk. |
| CheckOperationalAlerts | Port and run; low risk. |
| SettlementCreateCommand | Manual command; port before settlement cutover. |

---

## Single-Writer Ownership During Transition

At any given time, the source of truth for each domain is exactly one of:

| State | Who Writes | Who Reads |
|-------|-----------|----------|
| Pre-cutover | Laravel | Laravel + NestJS (NestJS reads only, no writes) |
| Shadow mode | Laravel | NestJS (shadow write: writes to separate log, not DB) |
| Cutover window | Laravel → NestJS | — |
| Post-cutover | NestJS | NestJS |

NestJS is never permitted to write to a domain where Laravel is the current owner. Enforce this with a feature flag, not just code convention.

---

## Production Acceptance Tests

Before sign-off on any domain cutover:

### Functional
- [ ] All CRUD operations return HTTP 200/201/204 with expected response shape
- [ ] Authentication headers are accepted and rejected correctly
- [ ] Role-based access returns 401/403 as expected
- [ ] Pagination meta (current_page, last_page, total) matches Laravel output

### Financial (settlement / payout / donation domains only)
- [ ] 10 randomly selected historical transactions: NestJS output matches Laravel output to the cent
- [ ] Three-ledger invariant: no `ledger_entries` row has a corresponding `donations` or `impact_events` row for the same transaction
- [ ] No floating-point arithmetic in any amount field (verify via DB type: BIGINT only)

### Performance
- [ ] P95 response time < 200ms for list endpoints under 50 concurrent requests
- [ ] P95 response time < 100ms for health endpoint
- [ ] Bid placement under 50 concurrent bidders: P95 < 200ms, P99 < 500ms, zero lost bids

### Resilience
- [ ] Queue worker: 1 failed job → dead-letter queue, not silent loss
- [ ] Webhook: duplicate delivery (same event ID) → idempotent (no double-write)
- [ ] DB connection loss: NestJS returns 503, not 500 with stack trace
- [ ] Stripe timeout: NestJS returns 504 to client, retries via queue

### Rollback
- [ ] Rollback to Laravel for this domain completes within 5 minutes
- [ ] No data written by NestJS that cannot be reconciled with Laravel

---

## Post-Cutover Monitoring

For each domain, monitor for 24 hours after cutover:

- HTTP 5xx error rate < 0.1%
- P95 latency within 20% of Laravel baseline
- Queue depth stable (not growing)
- Zero reconciliation mismatches
- No Stripe webhook failures

If any threshold is breached: rollback and investigate before proceeding.
