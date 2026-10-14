# ARTeuCtion / artefak — Technical Optimization Research

**Research date:** 2026-10-14  
**Repository:** `arteuction/artefak`  
**Purpose:** Identify high-value, current engineering strategies for a Laravel / FrankenPHP / MariaDB / Cloudflare R2 / Stripe application operating on a small VPS.

## Scope and confidence

This report combines (1) the ecosystem and infrastructure summary supplied by the repository operator, (2) the operator's latest status update for PRs #5–#7 and migrations 061, and (3) current official upstream documentation. GitHub itself was not readable from this session, so PR states and repository-specific implementation claims below are **operator-reported**, not independently verified against the live source tree. Before implementing a change, confirm the exact installed Laravel/PHP/FrankenPHP/MariaDB minor versions and inspect the relevant code and migrations.

The current described deployment has 1 vCPU, 1 GB RAM, a 25 GB NVMe disk, MariaDB 11, FrankenPHP in Octane/worker mode, a database queue driver, a critical queue worker, a low-priority queue timer, Cloudflare R2, Stripe, Laravel Pulse, and a GitHub Actions workflow that gates production deployment behind a manual confirmation and `pilot:payment-rehearsal`.

## Executive recommendation

Do not start by adding Redis, another search engine, more workers, or a broad domain rewrite. The highest expected payoff comes from five areas:

1. Fix the remaining refund persistence mismatch and prove payment/refund idempotency.
2. Add lightweight production measurements before tuning CPU, RAM, DB, or queue settings.
3. Serve public immutable artwork derivatives through the edge cache, and keep large image work away from request workers.
4. Tune only measured MariaDB hot paths and job retry/concurrency settings.
5. Harden deploy, backup/restore, and release compatibility so a failed change is recoverable.

These priorities reflect expected value under the described footprint, not measured benchmark results.

## Current issue/status corrections

Per the latest operator-provided status (not independently checked against GitHub):

- PR #5 is merged and all 10 reported CI checks passed.
- Migration 061 already expands `settlement_lines.recipient_type` to include `author` and `co_author`.
- PR #6 is open to guard migration 061's `down()` and expand `refund_lines.recipient_type` to carry `author` and `co_author`.
- PR #7 is open for architecture/optimization documentation.

The remaining known defect is downstream: `ProcessRefund` copies `recipient_type` from `settlement_lines` into `refund_lines`, which reportedly still allows only `artist`, `fund`, and `ops`. Do not add a duplicate migration for `settlement_lines`. PR #7's earlier roadmap draft should be refreshed so it does not say PR #5 is open or call for the already-existing settlement-lines expansion.

### PR #6 acceptance criteria

- The new `refund_lines` migration includes `author` and `co_author` while preserving the existing column attributes (nullability, default, ordering, and exact supported values).
- The `down()` method for that migration refuses to narrow the enum while rows using `author` or `co_author` exist.
- Migration 061's `down()` refuses to narrow `settlement_lines.recipient_type` while `author` or `co_author` rows exist; it must not map these values to `artist` or silently depend on MySQL/MariaDB enum coercion.
- MariaDB integration tests cover author and co-author settlement -> full refund and partial refund, repeated processing, exact cent conservation, legacy artist/fund/ops flows, clean rollback, and rollback refusal when incompatible rows exist.
- Run the tests against the production MariaDB major/minor family and relevant SQL mode. SQLite is not an acceptable substitute for these enum behavior tests.

Important migration discipline: editing an already-applied migration's `up()` will not repair databases where it has already run. A forward schema correction belongs in a new migration. A guarded `down()` can still protect a future rollback when the deployed source contains that updated method, but verify which code version actually executes during rollback.

---

## Prioritized roadmap

| Priority | Change | Why it matters here | Effort / risk |
|---|---|---|---|
| P0 | Merge/verify PR #6 after reviewing migration and tests | Prevent author/co-author refunds from failing or being coerced | Low–medium |
| P0 | Establish recovery objectives and test restore | Financial records and provenance need recoverability; `skip_log_bin` reportedly disables point-in-time recovery | Medium |
| P0 | Record a small baseline for requests, memory, SQL, queues, and Stripe processing | Prevents tuning the wrong bottleneck | Low |
| P1 | End-to-end webhook/outbox idempotency and reconciliation audit | External providers retry and can deliver events out of order | Medium |
| P1 | Correct queue timeout/retry/overlap configuration by job class | Prevent duplicate execution and runaway retry storms | Low–medium |
| P1 | Version image derivative keys and serve eligible assets through Cloudflare cache | Reduces origin work and stale-object surprises | Low–medium |
| P1 | Slow-query analysis and measured index/query changes | Largest plausible origin-side latency gains without adding services | Low–medium |
| P1 | Harden the production deployment workflow | Avoid concurrent or half-finished deployments and unverified releases | Low–medium |
| P1 | Confirm PHP support window and test the next runtime lane | If using PHP 8.4, its active bug-fix support ends 2026-12-31 | Medium |
| P2 | Cache eligible anonymous catalog responses with correct invalidation | Reduces repeated DB/PHP work on read-heavy pages | Low–medium |
| P2 | Direct-to-R2 presigned uploads, if uploads currently pass through PHP | Reduces request time, app bandwidth and memory pressure | Medium |
| P2 | Add sampled OpenTelemetry traces when basic measurements are insufficient | Connects slow HTTP requests, SQL and jobs; external exporter avoids hosting a telemetry stack on 1 GB | Medium |
| P3 | A1 SettlementRuleset, CauseAllocation and Sale lifecycle refactors | Important domain correctness/changeability, but not initial throughput optimizations | Medium |
| Defer | Local Redis, more Octane workers, search-service replacement, broad denormalization, sharding | Add operational/memory cost before the bottleneck is established | High relative to current scale |

## 1. Runtime: PHP, Laravel, FrankenPHP, Octane

### 1.1 Confirm the actual runtime lane

PHP 8.5 was released on 2025-11-20. The current PHP supported-versions table lists PHP 8.4 active support through 2026-12-31 and security support through 2028-12-31; PHP 8.5 active support runs through 2027-12-31 and security support through 2029-12-31. If the production runtime is 8.4, add an 8.5 compatibility lane in CI now, but do not switch production until the locked Laravel version, native extensions, FrankenPHP build and Composer dependencies all pass. If the project uses a framework release whose minimum/maximum PHP constraints differ, obey the lockfile and that release's official documentation rather than blindly following the newest Laravel docs.

Source: <https://www.php.net/supported-versions.php> and <https://www.php.net/releases/8.5/en.php>.

### 1.2 Tune FrankenPHP from representative load tests

The current description says one vCPU, four FrankenPHP threads, worker mode, one worker, `--max-requests=250`, and strict per-service memory limits. Keep the process-count discipline. The newest FrankenPHP performance guide explicitly advises tuning `num_threads`/worker count for the application and hardware and using a representative load test; it also warns that worker mode requires eliminating request-to-request memory/state leakage.

Recommended actions:

- capture RSS after warmup and across a soak test, not just at process startup;
- measure catalog/detail traffic, concurrent PlaceBid requests, Stripe webhook acknowledgement, and slow external dependencies as distinct workload classes;
- keep `TraceContext` and request-scoped data scoped to the request/job lifecycle; add a regression test proving trace IDs and identity data never leak from one request to the next;
- preserve `max-requests` worker recycling unless evidence shows a safe alternative;
- confirm OPcache is enabled and sized sensibly, Composer autoload optimization is used in production, Laravel optimization caches are rebuilt during deploy, and realpath cache is sufficient;
- prefer a glibc-based production build if currently using Alpine/musl, but measure it as a controlled platform change instead of combining it with application refactors;
- only consider a dedicated thread pool for a slow external endpoint if measurements show it occupies request capacity long enough to degrade ordinary traffic.

Do not increase threads or workers because CPU utilization looks low in isolation: DB waits, memory pressure, swap, and external API latency can be the real limits. FrankenPHP recommendations: <https://frankenphp.dev/docs/performance/> and worker recycling guidance: <https://frankenphp.dev/docs/worker/>. Laravel's `scoped` binding is reset for a new request/job lifecycle: <https://laravel.com/framework/docs/container>.

### 1.3 Safe framework build optimizations

For the exact Laravel version in `composer.lock`, use its supported deployment optimization command (`php artisan optimize` in current Laravel documentation) after configuration and code have been deployed. Composer's `--optimize-autoloader` is recommended for production; `--classmap-authoritative` is a stronger option and should be enabled only after testing libraries or generated classes that rely on runtime autoload discovery.

Sources: <https://laravel.com/framework/docs/deployment>, <https://getcomposer.org/doc/articles/autoloader-optimization.md>.

## 2. MariaDB: tune the hot paths, not the schema by intuition

### 2.1 Start with a small slow-query evidence set

Enable slow-query logging with a threshold suitable for the workload and consider logging materially costly queries that do not use indexes. Protect these logs because SQL may contain sensitive data. Capture the top normalized query fingerprints for:

- public catalog and artwork detail;
- auction item reads and PlaceBid locking/validation;
- expiry/closing commands;
- `ProcessRefund` and `RefundAllocator`;
- settlement statements and transfer/outbox claiming;
- outbox/inbox and queue-table polling.

For each candidate, save `EXPLAIN` and `ANALYZE`/`ANALYZE FORMAT=JSON` output. Change one query or index at a time and compare latency, actual rows, rows examined, lock waits, and write cost. Do not add every plausible composite index: indexes increase storage and write amplification, and can worsen insert/update paths.

Sources: <https://mariadb.com/docs/server/server-management/server-monitoring-logs/slow-query-log/slow-query-log-overview> and <https://mariadb.com/docs/server/reference/sql-statements/administrative-sql-statements/analyze-and-explain-statements/explain>.

### 2.2 Be careful with schema migrations on a 25 GB disk

MariaDB 11.2+ documents online table alteration capabilities, including operations that can use an online copy. “Online” does not mean zero resource cost: a copy and temporary online-change buffer can consume substantial disk, and a brief exclusive metadata lock may still be required. Before enum/index/column changes on populated tables:

1. test the exact DDL on a production-like copy;
2. check table size, free disk and temporary directory location;
3. specify/verify an algorithm and lock strategy where appropriate, allowing the migration to fail rather than unexpectedly taking a costly copy when that is important;
4. perform the change during low traffic and verify no warnings, lock-time spike or disk pressure;
5. preserve a recovery path.

Source: <https://mariadb.com/docs/server/reference/sql-statements/data-definition/alter/alter-table/online-schema-change>.

### 2.3 Decide the database recovery objective

The supplied VPS configuration says `skip_log_bin`. If that is the active MariaDB configuration, binary logging is disabled and standard MariaDB point-in-time recovery using binary logs is not available. This is not automatically wrong on a tiny disk; it is an explicit RPO trade-off that should be decided rather than inherited accidentally.

Minimum safe programme:

- encrypted off-server backups of the database and required application configuration;
- a separate object-storage recovery plan for R2 metadata/objects, not merely the database dump;
- scheduled restore drills into an isolated MariaDB instance;
- after restore, run schema verification and `pilot:payment-rehearsal`;
- set documented RPO/RTO and alert when backups exceed the objective.

If the desired RPO is materially shorter than the last full backup interval, evaluate enabling binary logs with rotation, disk budgeting and off-server log retention. MariaDB states binary logs are required for PITR and that they do add some server overhead, so decide from the required recovery objective and storage budget.

Sources: <https://mariadb.com/docs/server/server-usage/backup-and-restore/mariadb-backup/point-in-time-recovery-pitr-mariadb-backup> and <https://mariadb.com/docs/server/server-management/server-monitoring-logs/binary-log/overview-of-the-binary-log>.

## 3. Payments, webhooks, outbox and refunds

### 3.1 Webhook receiver pattern

Stripe's current webhook guidance says to verify the signature using the unmodified raw request body, respond quickly with a 2xx response before complex processing, use asynchronous processing, track event IDs to deduplicate deliveries, and not assume event ordering. Stripe live-mode delivery attempts can be retried for up to three days.

For this project, the appropriate flow is:

1. Verify `Stripe-Signature` against the raw body and correct endpoint secret.
2. Persist an inbox/receipt row keyed uniquely by Stripe event ID (and optionally a content/hash or object/type guard where separate Event objects can represent the same business action).
3. In the same DB transaction, persist a durable work/outbox record; then return 2xx once durable acceptance has succeeded.
4. Process the event asynchronously and idempotently; fetch the current Stripe object when needed rather than relying on event arrival order.
5. Reconcile unresolved PaymentIntent/transfer/payout states against Stripe data and make stuck/failed states observable.
6. Subscribe only to event types the product actually needs.

Do not return 2xx after merely putting an in-memory task in a process. The acknowledgment should mean the app durably recorded responsibility for the event.

Source: <https://docs.stripe.com/webhooks>.

### 3.2 Stripe idempotency is not a permanent local ledger

Stripe stores idempotent POST results for at least 24 hours and may prune keys after that. Keep a stable local operation identity for each real side effect (for example, a transfer outbox ID or settlement-line transfer identity) and unique database constraints where semantics permit. Replays of an old outbox job must reuse the same logical key; retries after Stripe's idempotency retention period must still be blocked by local state/reconciliation rather than generating a fresh operation.

Source: <https://docs.stripe.com/api/idempotent_requests>.

### 3.3 Failure-mode tests that are more valuable than a generic happy path

Test the ambiguous boundaries explicitly:

- Stripe accepts a transfer, but the HTTP response is lost;
- worker dies after external success but before local success is recorded;
- same event arrives twice, and two distinct events imply the same business transition;
- out-of-order payment, refund, dispute or connected-account events;
- partial refund, repeated refund job, rounding residue and a cause-allocation line that has already become a real donation;
- database transaction rolls back before job dispatch, and queue job is dispatched only after commit;
- unknown/unsupported event type is acknowledged after durable capture but does not mutate financial state.

Keep the current row locks for bid/auction correctness unless a tested replacement proves equivalent behavior. Do not call Stripe while holding long database locks; commit local intent/operation identity first, call the external system with a stable idempotency key, then persist outcome and reconcile ambiguity.

## 4. Queue and scheduler tuning

The split between a continuously available critical queue and a low queue that wakes periodically is well suited to the described memory ceiling. Optimize job behavior before adding a broker.

- For database-dependent dispatches inside transactions, use Laravel's `afterCommit` behavior to avoid workers seeing uncommitted/missing rows.
- Ensure queue `--timeout` is shorter than `retry_after` by several seconds. Laravel warns that the reverse ordering can cause a job to be processed twice.
- Set explicit connection/read timeouts on all external HTTP requests; Laravel/PHP job timeout alone does not necessarily stop a blocked socket promptly.
- Use bounded tries and backoff (prefer increasing delays for transient provider failures); avoid immediate retry storms.
- Use unique jobs or `WithoutOverlapping` for expensive idempotent work where appropriate, but do not mistake a lock for a replacement for DB constraints and idempotency.
- Scheduler commands whose overlap is unsafe should use `withoutOverlapping`; if the same scheduler runs on multiple machines, `onOneServer` needs a shared lock-capable cache. Do not add a local Redis deployment just for this on a single VPS.
- Measure queue age, not just queue length; alert on the oldest critical job and oldest outbox event.
- Keep `low` queue latency an explicit product SLO. A five-minute image derivative timer is acceptable only if the UI tolerates that delay; if not, change the timer or trigger mechanism based on observed job volume and memory cost.

Sources: <https://laravel.com/framework/docs/13.x/queues> and <https://laravel.com/framework/docs/scheduling>.

## 5. Cloudflare R2 and image pipeline

### 5.1 Immutable, revision-keyed derivatives

Use object keys that change when the source image or transformation version changes, for example an artwork ID plus source hash/revision and derivative profile. Do not overwrite `/artwork/123/medium.webp` with new bytes if the edge cache might continue serving the old object.

When `GenerateArtworkDerivativesJob` completes, it should update DB pointers only if the job's source revision still matches the current artwork source. This prevents an old/retried job from overwriting metadata for a newer upload.

Set long cache TTL plus `immutable` only for versioned public keys. Purge or invalidate mutable URLs deliberately. Never publicly cache private provenance, ownership evidence, certificates, signed access URLs or user-specific API responses.

Cloudflare warns that with R2 custom-domain caching an overwritten/deleted object may continue to be served from cache until expiry/eviction or purge; R2's development `r2.dev` domain does not provide the same caching/WAF/bot-management path as a custom domain.

Sources: <https://developers.cloudflare.com/r2/reference/consistency/> and <https://developers.cloudflare.com/cache/interaction-cloudflare-products/r2/>.

### 5.2 Browser-to-R2 presigned uploads — conditional, not mandatory

If large original images currently travel through the Laravel/FrankenPHP worker, evaluate short-lived presigned PUT URLs so the browser uploads directly to R2. This removes image bytes and upload waiting from the 1 GB application server. Do it only with a deliberate upload state machine:

- the server authenticates the user and creates a single-purpose, unpredictable staging key;
- short URL expiry, CORS limited to the actual site, expected size/type metadata and upload ownership are enforced;
- the confirmation endpoint performs a server-side `HEAD`/metadata check and validates the uploaded object before making it the primary asset;
- originals are quarantined/staged until validation; never trust the filename or browser MIME header alone;
- derivatives are generated from the validated source and public derivative keys use revisions;
- presigned URLs are considered reusable until expiry, not one-use tokens.

Sources: <https://developers.cloudflare.com/r2/api/s3/presigned-urls/> and OWASP's file-upload security guidance: <https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html>.

### 5.3 Bound image resource use

Validate maximum upload size, decoded pixel dimensions and accepted formats before expensive processing; keep image processing in the low queue, one job at a time initially, with worker restart/memory ceilings. Add metrics for derivative wait time, failure count, peak RSS and time to confirmation. Avoid raising worker concurrency without load tests using large, worst-case images.

## 6. HTTP caching and API reads

Apply cache rules only to explicit allowlisted anonymous GET endpoints such as public artwork metadata and stable public catalog responses. Use appropriate `ETag`/`Last-Modified` and short freshness for mutable catalog data; invalidate/version responses after ArtworkRevision, listing status or price changes. Bidding, authenticated portfolios, draft artwork, settlement, ownership evidence and buyer-specific state must not be shared-cacheable.

Cloudflare's current docs support asynchronous `stale-while-revalidate` and explain that `s-maxage` implies revalidation semantics that disable stale-while-revalidate. Avoid mixing directives mechanically; choose one tested policy and verify real `CF-Cache-Status` values (`HIT`, `MISS`, `DYNAMIC`, `BYPASS`, `UPDATING`). A stale catalog card is acceptable only if its freshness contract is clear; stale current-bid or payment state is not.

Sources: <https://developers.cloudflare.com/cache/concepts/cache-control/> and <https://developers.cloudflare.com/cache/concepts/revalidation/>.

Keep MariaDB FULLTEXT if its measured behavior meets needs; do not reintroduce Scout writes or a standalone search service without evidence that search is the bottleneck.

## 7. GitHub Actions and deployment hardening

- Keep a manual production approval / `workflow_dispatch` confirmation gate.
- Set a `production` environment with restricted branches and deployment protection; use a concurrency group so two deploy workflows cannot deploy simultaneously. Avoid `cancel-in-progress: true` if a running deploy must finish or cleanly roll back.
- Pin third-party Actions to verified full-length commit SHAs; declare minimum `GITHUB_TOKEN` permissions; do not expose production secrets to untrusted pull-request code.
- Enable Dependabot alerts/security updates and dependency review where the repository plan supports it; use `composer audit` as part of dependency-risk review, without blindly auto-merging major upgrades.
- Prefer building a release artifact once in CI and deploying the same artifact identified by commit SHA/hash. Avoid a production install that resolves a different dependency graph from CI.
- For the existing maintenance-mode workflow, explicitly handle failure at every stage. `artisan up` should not happen blindly when the schema/app is known broken, but a failed workflow must produce a clear recovery path and alert rather than accidentally leaving the service in maintenance mode indefinitely.
- Consider versioned release directories plus an atomic symlink switch only after accounting for disk headroom, shared storage and backward-compatible migrations. On the current small disk, this is not automatically the first change.
- Keep migrations expand/contract: add compatible schema first, deploy code that reads/writes it, backfill if needed, then remove obsolete schema in a later release.
- Run app health/readiness checks and `pilot:payment-rehearsal` before re-enabling traffic; record the deployed commit SHA, migration result, rehearsal result and restart outcome.

Sources: <https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/control-deployments>, <https://docs.github.com/en/actions/reference/security/secure-use>, <https://docs.github.com/en/code-security/concepts/supply-chain-security>.

## 8. Backup, recovery and operational visibility

The described `skip_log_bin` setting deserves an explicit decision. On a transactional art and payment platform, a successful daily backup is not enough unless the restoration time and possible data loss are acceptable.

Deliverables:

- define RPO and RTO for DB, image assets, and payment/settlement records;
- encrypted off-server backup, monitored completion and retention;
- periodic restore drill on isolated infrastructure;
- restore checks for `ledger_entries`, `settlements`, `settlement_lines`, `refund_lines`, outbox/inbox and ownership/provenance data;
- compare local payment/settlement state with Stripe using a reconciliation report after a restore;
- alert on queue backlog, oldest pending outbox event, failed jobs, webhook delivery failures, disk free space, backup age, PSI/OOM, and failed payment rehearsal.

Add OpenTelemetry PHP tracing only when the existing TraceContext/logs/Pulse measurements do not locate bottlenecks. PHP's OpenTelemetry documentation currently describes traces, metrics and logs as stable. Prefer sampling and an external/managed exporter rather than hosting a full telemetry stack on the 1 GB VPS. Keep metric labels low-cardinality; never label metrics with raw user IDs, bid IDs or payment intents.

Source: <https://opentelemetry.io/docs/languages/php/>.

## 9. Minimal benchmark plan

Do not use averages alone. Capture p50/p95/p99 latency and error rate under a repeatable workload. Use a staging clone or controlled test data; do not run aggressive load tests against real bidding/payment traffic.

Workloads:

1. 70% public catalog/artwork GET, 20% artwork detail, 10% authenticated reads;
2. concurrent bids against the same AuctionItem and across different items;
3. Stripe webhook burst with duplicate and out-of-order events;
4. critical queue steady-state and backlog drain;
5. one large image upload and derivative generation while ordinary requests continue;
6. deployment on a production-like schema with the payment rehearsal gate.

Record:

- response p50/p95/p99 and 5xx;
- PHP RSS after warmup and after a multi-hundred-request soak;
- cgroup memory events, PSI and swap-in/out;
- MariaDB query latency, actual rows, lock waits, connections and buffer pool activity;
- queue age/depth/failures, outbox oldest age, webhook acknowledgement latency;
- R2 cache hit ratio and origin reads;
- exact commit, PHP/framework/DB versions, workload and config.

Only accept an optimization if its metric improves without a material regression in another critical metric or correctness invariant.

## 10. Recommended sequence

### Now

1. Review and merge PR #6 only after checking the actual new migration, both rollback guards and MariaDB refund tests.
2. Refresh PR #7 docs: reflect PR #5 merged, migration 061 already adding settlement-line `author`/`co_author`, and PR #6 addressing `refund_lines`.
3. Capture the production runtime versions and baseline configuration; inspect the current `composer.lock`, PHP version, MariaDB minor version, FrankenPHP Caddyfile, queue `timeout`/`retry_after`, and actual backup settings.
4. Define RPO/RTO and execute a restore drill before the first real payment release.

### Next high-value PRs

5. `test/stripe-webhook-failure-modes`: duplicate/out-of-order deliveries, external success with lost response, worker crash, durable event receipt, and reconciliation.
6. `ops/queue-retry-and-overlap-contracts`: explicit timeout/retry_after alignment, HTTP client timeouts, backoff, overlap rules and oldest-job alerts.
7. `perf/versioned-artwork-derivatives`: source revision checks, unique object keys, cache headers, upload resource limits; evaluate presigned uploads only if current app traffic proxies large files.
8. `perf/measured-mariadb-hot-paths`: slow query report plus before/after query plans, only targeted changes.
9. `ops/deploy-recovery-and-supply-chain`: workflow concurrency, SHA-pinned Actions, minimal permissions, explicit failure/recovery path and build artifact identity.
10. `perf/public-catalog-edge-cache`: narrow cache rules, ETags, invalidation and checks against accidental caching of active auction/buyer state.
11. Only then continue A1/B/C/D/E refactor PRs from `REFACTORING_PLAN.md`.

## 11. Deliberately deferred

Do not introduce these without measurable evidence:

- local Redis/Horizon solely to modernize the stack;
- larger PHP worker counts or a larger MariaDB buffer pool without memory/latency measurements;
- a new search engine while MariaDB FULLTEXT is adequate;
- caching mutable or authenticated data at the CDN;
- a generic event-sourcing rewrite of existing mutable bid/auction tables;
- a broad schema migration from ENUM to a new generic role table as part of the immediate refund fix;
- a no-downtime release framework before a tested backup and failure recovery route exists.

## Official references consulted

- PHP supported versions: <https://www.php.net/supported-versions.php>
- PHP 8.5 release: <https://www.php.net/releases/8.5/en.php>
- Laravel deployment optimization: <https://laravel.com/framework/docs/deployment>
- Laravel queues: <https://laravel.com/framework/docs/13.x/queues>
- Laravel scheduler: <https://laravel.com/framework/docs/scheduling>
- Laravel container scoped bindings: <https://laravel.com/framework/docs/container>
- Composer autoloader optimization: <https://getcomposer.org/doc/articles/autoloader-optimization.md>
- FrankenPHP performance: <https://frankenphp.dev/docs/performance/>
- FrankenPHP worker mode: <https://frankenphp.dev/docs/worker/>
- MariaDB slow query log: <https://mariadb.com/docs/server/server-management/server-monitoring-logs/slow-query-log/slow-query-log-overview>
- MariaDB EXPLAIN/ANALYZE: <https://mariadb.com/docs/server/reference/sql-statements/administrative-sql-statements/analyze-and-explain-statements/explain>
- MariaDB online schema change: <https://mariadb.com/docs/server/reference/sql-statements/data-definition/alter/alter-table/online-schema-change>
- MariaDB point-in-time recovery: <https://mariadb.com/docs/server/server-usage/backup-and-restore/mariadb-backup/point-in-time-recovery-pitr-mariadb-backup>
- Stripe webhooks: <https://docs.stripe.com/webhooks>
- Stripe idempotent requests: <https://docs.stripe.com/api/idempotent_requests>
- Cloudflare R2 consistency/cache: <https://developers.cloudflare.com/r2/reference/consistency/>
- Cloudflare R2 cache integration: <https://developers.cloudflare.com/cache/interaction-cloudflare-products/r2/>
- Cloudflare Cache-Control and revalidation: <https://developers.cloudflare.com/cache/concepts/cache-control/> and <https://developers.cloudflare.com/cache/concepts/revalidation/>
- Cloudflare R2 presigned URLs: <https://developers.cloudflare.com/r2/api/s3/presigned-urls/>
- GitHub Actions deployment control: <https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/control-deployments>
- GitHub Actions secure use: <https://docs.github.com/en/actions/reference/security/secure-use>
- GitHub supply-chain security: <https://docs.github.com/en/code-security/concepts/supply-chain-security>
- OpenTelemetry PHP: <https://opentelemetry.io/docs/languages/php/>
- OWASP File Upload Cheat Sheet: <https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html>
