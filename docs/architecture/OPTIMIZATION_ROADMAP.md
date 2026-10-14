# ARTEFAK — Optimization Roadmap

**Repository:** `arteuction/artefak`  
**Prepared:** 2026-10-14  
**Status:** Prioritized proposal based on the supplied ecosystem/repository summary and current upstream documentation. This is not a fresh source-code audit: direct repository access was unavailable in this session. Any code-level claim not explicitly identified as already present below must be verified in the repository before implementation.

## Executive recommendation

For the latest upstream-verified recommendations (PHP/FrankenPHP, Laravel queues, MariaDB DDL, Stripe retries, R2 caching, CI security and recovery objectives), see [`TECHNICAL_OPTIMIZATION_RESEARCH.md`](./TECHNICAL_OPTIMIZATION_RESEARCH.md).

Do not begin with a broad rewrite, Redis migration, extra Octane workers, a search-engine replacement, or a speculative index campaign. The highest-value strategy for the described 1-vCPU / 1-GB VPS is:

1. verify PR #5 is merged and its 10 reported CI checks pass on the current `main`;
2. merge/verify PR #6 for the remaining `refund_lines.recipient_type` / `author` + `co_author` contract defect before real refund traffic;
3. create a tiny, trustworthy performance baseline before tuning RAM/SQL/worker settings;
4. ensure images and other public immutable assets are served and cached at the edge, not through PHP;
5. harden payment/webhook/outbox idempotency and job retry semantics;
6. optimize only the measured slow SQL and cacheable public reads;
7. preserve the existing low-memory topology until metrics demonstrate that a change is needed.

The key optimization is reducing uncertainty and avoidable work first. Current architecture notes already describe several high-value changes as implemented: scoped TraceContext, background image derivatives, FULLTEXT search, removal of Scout writes for ArtLot, chunked expiry/closing commands, critical/low queue separation, and four additional indexes. Avoid redoing these; verify their impact and protect them with regression tests.

### Status correction — 2026-10-14

Per the latest operator update (not independently verified against GitHub in this session): PR #5 is merged and all 10 CI checks passed; PR #6 is open for the refund-lines enum/rollback fix; PR #7 is open for documentation. Migration 061 already includes `author` and `co_author` in `settlement_lines.recipient_type`. This report supersedes older references below that describe PR #5 as still open or call for a new `settlement_lines` expansion. The immediate schema issue is `ProcessRefund` copying `author`/`co_author` to `refund_lines`, whose enum has not yet been expanded, plus the migration rollback guard described in PR #6.

---

## 1. Priority matrix

| Priority | Modification | Expected payoff | Risk | Start when |
|---|---|---:|---:|---|
| P0 | Verify PR #5 merge in `main`; preserve its CI/deploy gates | High: protects already-applied fixes | Low | Before release tag |
| P0 | Merge/verify PR #6: `refund_lines` enum + guarded `down()` | Very high for author/co-author refund correctness; prevents strict-mode write failures/data-loss rollback | Low–medium | Before live book refunds / release tag |
| P0 | Baseline production-like runtime/DB/queue metrics | Very high for avoiding wasted optimization work | Low | Before changing tunables |
| P1 | Versioned R2 derivatives + edge cache for public images | High visitor latency / storage-read improvement; little VPS cost | Low–medium | If not already configured |
| P1 | Verify end-to-end payment and outbox idempotency | Very high financial integrity; also reduces expensive manual recovery | Medium | Before accepting real payments |
| P1 | Queue retry/timeout/overlap and backlog controls | High reliability under bursts/outages | Low–medium | Before growing traffic |
| P1 | MariaDB slow-query capture + `ANALYZE` top queries | High if SQL is currently a bottleneck; avoids blind indexes | Low | Once baseline exists |
| P2 | Cache public catalog/API responses and apply cursor pagination to suitable feeds | Medium–high at read-heavy scale | Low–medium | After cacheability/auth rules are explicit |
| P2 | Deployment hardening and GitHub Actions supply-chain controls | High risk reduction, modest latency impact | Low–medium | Before repeatable production releases |
| P3 | SettlementRuleset / CauseAllocation / Sale lifecycle refactors | High long-term changeability and accounting clarity; not an immediate throughput optimization | Medium | In separately tested PRs after P0 correctness fixes |
| Defer | Add Redis, more Octane workers, new search service, broad denormalization, DB sharding | Unknown until proven necessary; increases RAM/ops burden | Medium–high | Only after metrics identify the limiting resource |

“Expected payoff” is a reasoned estimate from the described topology, not a benchmark result.

## 2. P0 — Release readiness and known persistence defect

### 2.1 PR #5 — status reported merged

The latest operator update says PR #5 (`fix/optimization-critical-high`) is merged and all 10 CI checks passed. Before release, verify that `main` contains the expected merge commit and rerun the required release checks against the merged commit. Do not treat PR #5 as still open.

- ensure the full test suite uses MariaDB, not SQLite, as required by the project;
- retain `pilot:payment-rehearsal` as a deploy abort gate;
- do not tag `v1.0.0-rc.1` until the outstanding schema PR and documentation corrections are resolved and a clean reproducible run passes.

### 2.2 Remaining schema defect — `refund_lines.recipient_type`

Migration 061 already adds `author` and `co_author` to `settlement_lines.recipient_type` (operator-reported). Do not create a duplicate migration for that table. The remaining defect is that `ProcessRefund` copies the source `recipient_type` into `refund_lines`, whose enum reportedly still allows only `artist`, `fund`, and `ops`.

PR #6 reportedly handles this by expanding `refund_lines.recipient_type` and guarding migration 061's `down()` against rows that cannot be represented by the old enum. Verify the actual PR diff and tests. The new migration's own `down()` must also refuse to narrow the enum while `author` or `co_author` rows exist.

Tests must cover the complete book-settlement refund path for `author` and `co_author` on the production MariaDB version and SQL mode, including partial refunds, repeated jobs, rollback refusal with rows present, and legacy `artist`/`fund`/`ops` behavior.

### 2.3 Record a performance baseline before tuning

Capture a minimal baseline in staging, then on production after a successful deployment. Record at least:

- HTTP latency p50/p95/p99 for catalog, artwork detail, auction item detail, PlaceBid, payment webhook, settlement view;
- HTTP 5xx/timeouts and requests per minute;
- per-process RSS and cgroup `MemoryHigh`/`MemoryMax` events for FrankenPHP, MariaDB, Reverb, critical queue, low queue;
- PSI `some`/`full`, swap-in/swap-out rate, OOM/restart count;
- slowest SQL fingerprints, query count per request, rows examined vs returned;
- queue age and depth by queue, failed jobs, oldest pending outbox event, stuck `processing` events;
- image derivative queue age and failure/retry counts;
- payment/settlement rehearsal results and financial-conservation failures.

Do not set new hard targets until a real baseline exists. The current 1-GB server budget is tight enough that a change which improves request latency but creates memory pressure is not a win.

---

## 3. P1 — Image delivery and derivative safety

### 3.1 Serve public derivatives from an R2 custom domain with cacheable, versioned URLs

The design already creates thumb/medium/large WebP derivatives in R2. Verify whether the public derivative URLs use a Cloudflare R2 custom domain and benefit from edge caching. If not, configure that for public artwork imagery.

Recommended pattern:

- use versioned object keys, such as `artworks/{artwork_id}/{revision_or_hash}/medium.webp`;
- set long-lived cache headers on immutable derivatives, e.g. `public, max-age=31536000, immutable` where appropriate;
- never overwrite a supposedly immutable key; a new source image/revision produces new derivative keys;
- update the DB pointers only after all derivatives for that source revision have been written successfully;
- serve public derivative objects separately from private provenance, identity, ownership, and certificate documents;
- do not put private evidence behind a public R2 custom domain; use the private bucket/path and short-lived authorized URLs for private files.

Why versioned keys matter: Cloudflare documents that a cached object at an overwritten R2 path can continue serving the prior version until TTL expiry/eviction or explicit purge. Cached 404s can likewise outlive the upload. Unique keys avoid most purge choreography. A custom domain also makes the R2 bucket publicly accessible, so bucket/object access separation must be checked before enabling it.

### 3.2 Make `GenerateArtworkDerivativesJob` idempotent and stale-write-safe

The supplied architecture says derivative generation is already asynchronous and isolated on the low queue. Keep that design; harden it rather than moving image work back into HTTP.

Add or verify:

- idempotency identity = artwork ID + source image version/hash + derivative pipeline version;
- duplicate jobs for the same identity do not regenerate/upload unnecessarily;
- a job checks that its source version is still current before updating the Artwork's derivative pointers;
- older jobs cannot overwrite pointers created by a newer source upload;
- output uploads use temporary/versioned keys; DB pointer swap happens only after success;
- bounded retries with backoff, a defined timeout, and explicit failure alerting;
- stale derivative cleanup is a separate low-priority garbage-collection job, never part of the user request.

If Laravel's installed version supports the relevant queue middleware/features, a uniqueness lock and `WithoutOverlapping` may help; verify lock driver/timeout/retry interactions before relying on it. Keep correctness in the DB/source-version checks rather than relying on a cache lock alone.

### Acceptance checks

- a repeated job for the same source version is harmless;
- two jobs for old/new uploads executing out of order leave the new version selected;
- failed upload leaves the prior usable derivatives intact;
- private evidence cannot be fetched from the public image domain;
- browser/cache hit rates and median image response latency improve without PHP RSS increasing.

---

## 4. P1 — Payment, webhook, and transactional-outbox integrity

Before real transactions, audit these paths end to end: Stripe webhook → local payment state → settlement creation → payout/transfer outbox → Stripe transfer → reconciliation. The supplied notes establish that a webhook dispatcher and transactional outbox exist, but do not prove all these paths are idempotent.

### Required controls

1. Verify each webhook signature using the raw request body and endpoint secret before trusting payload fields.
2. Persist the Stripe event ID under a unique constraint before applying side effects, or use an equivalent atomic inbox/deduplication design. Duplicate delivery must not duplicate capture processing, settlement, payout, refund, or donation intent.
3. Give each local money-moving operation a stable idempotency key; use the same key on retry of the same logical Stripe POST. Do not generate a new key every retry.
4. Make `payment_intent_id`, settlement-per-capture identity, transfer/outbox operation identity, and refund identity unique where domain cardinality requires it.
5. Keep external Stripe network calls outside long-running DB transactions. Persist intent/outbox state in a transaction, commit, then perform the external call and persist the result. Reconcile ambiguous timeouts rather than assuming failure or blindly creating a second transfer.
6. Add an inbox/deduplication guard for domain-event consumers. `ConsumerInbox` already exists in the architecture; confirm all consumers actually use it and that the inbox insert plus local effect are atomic.
7. Preserve append-only financial evidence. Corrections should be compensating entries/state transitions, not destructive changes to confirmed ledger history.

### Must-pass fault-injection tests

- same webhook delivered twice and concurrently;
- worker crashes after local intent is committed but before Stripe request;
- Stripe request succeeds but worker loses the response;
- worker crashes after external success but before local success persistence;
- dispatch command sees the same outbox event twice;
- settlement/payout/refund job retries after timeout;
- a duplicate refund request reuses the same logical idempotency key;
- an outbox item remains `processing` after worker death and is reclaimed safely after a lease expires.

Do not confuse Stripe's API idempotency with local idempotency: Stripe may prune keys after at least 24 hours, so the application database still needs durable operation identity and reconciliation.

---

## 5. P1 — Queue behavior under a 1-GB memory budget

The existing split is sensible for this VPS: critical financial/webhook work stays available, while expensive image/PDF/CSV work is on a low queue that starts periodically and exits when empty. Do not move everything into one worker or keep adding worker processes.

### Verify and tune the following

- Critical and low queues have different, explicit latency budgets and alert thresholds.
- Worker `--timeout` is lower than the queue connection's `retry_after`/visibility timeout so the same job is not normally run concurrently after a timeout.
- Every external side-effect job is idempotent before it is retried.
- Jobs have bounded attempts, backoff, and a final failed-job policy; rate-limited/overlapping jobs have enough attempts to be useful rather than being discarded by default.
- Use overlap locks only for duplicate work where serialization is correct; choose lock key, release delay, and expiry deliberately.
- Low-priority jobs are bounded by count/size and cannot monopolize DB/IO when critical work is arriving.
- `chunkById` remains in all potentially unbounded batch commands; the established 100/200 chunk limits should be load-tested, not increased based on guesswork.
- Monitor `jobs` table growth, lock waits, and queue poll overhead because the database queue shares the database with application and financial work.

Keep the database queue until measurements show that its polling/locking or write contention is material. Introducing Redis on a 1-GB VPS adds memory and operational failure modes; it is not an automatic optimization.

### Queue metrics

Expose queue depth and oldest age by queue, failed/retried counts, job duration percentiles, low-queue runtime and idle time, DB lock wait time, and outbox processing age. Alert on a financial job being stale, not only on a worker being down.

---

## 6. P1/P2 — MariaDB query optimization based on evidence

### 6.1 Add temporary slow-query visibility

Use MariaDB's slow-query log on staging first, then a controlled production sampling window. A starting threshold such as 200–500 ms may be appropriate for diagnosis, but calibrate it to real traffic and volume. Protect logs: SQL text can contain sensitive values. If using `log_slow_verbosity=explain`, verify the installed MariaDB version and actual log size before enabling broadly.

For the top query fingerprints:

1. capture the real SQL and representative bind-value/selectivity patterns;
2. run `EXPLAIN` and `ANALYZE FORMAT=JSON`/the equivalent supported by the installed version on representative data;
3. compare estimated rows against actual rows, rows examined, sort/filesort, temporary tables, and join order;
4. change one index/query at a time;
5. rerun the same benchmark and regression tests;
6. retain only indexes with a measured read benefit that justifies added insert/update cost and storage.

Do not add guessed composite indexes for every status/date field. The schema already received four indexes according to the supplied notes; new indexes must be justified by measured plans.

### 6.2 Query candidates worth measuring first

Inspect these first because they sit on hot paths or can grow with history:

- public artwork catalog and artwork detail eager loading;
- active auction/auction-item listing and close-expired-lots JOIN;
- PlaceBid current-bid lookup and row-lock path;
- `ExpireBidAuthorizations` predicate on authorization expiry and payment status;
- settlement/payout history and statement generation;
- `ProcessRefund` relationship traversal and per-line allocator;
- transactional outbox claiming and inbox deduplication;
- Pulse/operations screens if they query entire histories.

These are query candidates, not confirmed bottlenecks; choose indexes only after seeing real plans. For feeds and admin history with large offsets, test cursor/keyset pagination where ordering is stable and unique. Keep full-text relevance pagination separate: relevance-ranked results may need a different strategy from chronological feeds.

### 6.3 MariaDB memory: do not increase the buffer pool blindly

The supplied production profile reports a 64-MiB InnoDB buffer pool and a strict process memory cap. That may be small, but increasing it without checking the active dataset, `innodb_buffer_pool_reads` vs `innodb_buffer_pool_read_requests`, disk I/O, and total RSS can worsen cgroup pressure. Measure cache effectiveness and working-set size first; change one memory knob at a time and re-check PSI/swap/OOM events.

Do not enable broad high-overhead instrumentation permanently just for curiosity. Performance Schema is currently off according to the supplied notes; choose targeted slow-log/OS metrics first, and enable deeper database instrumentation only for a bounded investigation when necessary.

---

## 7. P2 — Public catalog/API read path

The architecture already has a FULLTEXT-backed public catalog and removes Scout writes from ArtLot. Preserve that. Do not introduce another search engine until the existing FULLTEXT path is measured and shown inadequate.

### Recommended incremental improvements

- Inspect queries and serializers for N+1 loads; eager-load only relations actually used in the response.
- Select only columns needed by public API resources instead of hydrating full evidence/revision graphs by default.
- Cache anonymous/public artwork detail and catalog responses with explicit versioned keys or HTTP validators; invalidate by artwork revision/listing state rather than a global flush.
- Use edge caching for truly public, stable GET routes where authentication/cookies do not change output. Never cache private evidence, account-specific prices/offers, bid capacity, or authorization-sensitive responses publicly.
- For large chronological feeds, use cursor/keyset pagination with a unique tie-breaker; avoid unnecessary total-count queries where the UI does not need them.
- Keep the existing FULLTEXT index/query column order aligned; keep a MariaDB integration test that proves production FULLTEXT behavior. The test environment's `LIKE` fallback should not be mistaken for a production performance test.
- Add ETag/Last-Modified semantics only where the canonical version can be computed reliably.

Use cache hit ratio, DB queries/request, p95 latency and response size to decide whether each improvement pays for its invalidation complexity. If the current Laravel cache store is the database, avoid routing every hot public read through that same DB without measuring the net result; edge cache or a dedicated local file cache may be more suitable on a single-node deployment.

---

## 8. P2 — Octane/FrankenPHP and deployment profile

The current design—one Octane worker, `--max-requests=250`, memory caps, low queue off between runs—is conservative for the stated 1-vCPU / 1-GB server. Keep it until load tests and measurements show spare capacity. More workers generally mean more simultaneous memory and DB demand.

### Add a repeatable soak test

In staging or a production-like clone:

- warm the app, then run several hundred/thousand small requests through the same Octane worker;
- sample worker RSS at fixed intervals and after GC/restarts;
- repeat catalog/detail requests with worst-case relationship data;
- test image upload/confirmation while low queue is idle and while it is processing a backlog;
- submit concurrent bids against one AuctionItem and ensure latency does not grow without bound;
- simulate queue backlog and verify financial work still meets its latency budget;
- record p50/p95/p99 and cgroup memory/PSI, not just average response time.

Keep `TraceContext` scoped, avoid accumulating request-specific state in static properties/singletons, and preserve the max-request restart safety net. If the memory curve rises across requests, fix retention/leaks rather than simply lowering the worker limit until the cause is understood.

### Deployment

- Preserve the existing manual `workflow_dispatch` confirmation gate and payment rehearsal abort gate.
- Make migrations expand/contract and backward-compatible where possible so `artisan down` is needed for the shortest practical time.
- Ensure a failed rehearsal restores service (`artisan up`) through a guaranteed cleanup path; a failed deploy must not leave maintenance mode active.
- Pin GitHub Actions to verified full commit SHAs, declare minimal workflow `permissions`, and avoid exposing deploy secrets to untrusted pull-request workflows.
- Record the deployed commit SHA, migration batch, rehearsal result, and rollback action.
- Run `php artisan optimize` after code deployment as already described; verify `env()` is used only inside config files when config is cached.

Do not replace the deployment setup wholesale before the first successful measured release. First prove recovery from failure: migration failure, rehearsal failure, SSH loss, and worker reload failure.

---

## 9. Settlement and auction refactor order (correctness before abstraction)

These are worthwhile but should not be sold as immediate speedups.

1. **Freeze the canonical lifecycle spec**: Auction/Item/ArtLot/Sale are distinct; Payment, Settlement and Fulfillment own their own state. `Sale.CLOSED` is a projection over sale mode and the required subordinate facts—not an independent field mutated by arbitrary code.
2. **A0**: verify migration 061's existing `settlement_lines` values and guarded `down()`; merge the new `refund_lines.recipient_type` migration for `author`/`co_author` with its own protected `down()`.
3. **A1**: introduce `SettlementRuleset` and generic `SettlementResult`; keep canonical persisted role names (`artist`, `author`, `fund`, `ops`) rather than silently introducing both `ops` and `operations`.
4. **B0**: add `line_type` with legacy rows defaulting to `payout`; confirm whether the DB enum or a validated string is better for the actual migration policy.
5. **B1–B3**: extract `CauseAllocation`, update every payout/transfer query to filter payout lines, and explicitly change `ProcessRefund`/`RefundAllocator` so cause allocations are not accidentally treated as payouts.
6. **C**: add append-only `bid_events` alongside mutable `bids`, written atomically with each relevant state change; do not rewrite the lock/current-state path at the same time.
7. **D/E**: add Party/PartyRole for new recipient/financial records and perform naming cleanup separately.
8. **Annual Author Election** only after Legal Ruleset, recipient eligibility, actual Donation execution and tax reporting boundaries are explicit.

Keep the three ledgers separate: `ledger_entries` (financial accounting), `donations` (charitable transfers/tax evidence), and `impact_events` (impact claims). Do not optimize by collapsing them.

For CauseAllocation, explicitly decide:

- before a donation is executed: can an unexecuted allocation be canceled/reduced on refund?
- after a donation is recorded: who bears the refund shortfall, and is any legally valid recovery process available?
- are partial refunds supported and how are rounding differences allocated?

`RefundAllocator` must not silently decide those policies by distributing against every settlement line.

---

## 10. Acceptance criteria / measurable outcomes

Capture before/after numbers for each applicable change. Initially, use these as *measurement requirements*, not unverified numerical promises:

| Area | Measure | Success condition |
|---|---|---|
| Public catalog | p95 latency, DB queries/request, rows examined | Improvement without stale/private data exposure |
| Artwork images | cache-hit ratio, image TTFB/latency, R2 origin-read rate | Better repeat-view latency; PHP process unaffected by derivative generation |
| Octane | RSS per request batch, restarts, PSI/swap | No monotonic memory growth under soak test; no new OOM/PSI regression |
| MariaDB | top query latency, actual rows, reads, lock waits | Demonstrable improvement in target query, no material write regression |
| Critical queue | oldest job age, failed/retried jobs, webhook-to-state latency | Meets the agreed financial-job latency budget during a backlog test |
| Outbox/inbox | oldest pending age, duplicate suppression, stuck-processing count | Retried/duplicated delivery creates no duplicate financial side effect |
| Bidding | p95 under concurrent bids, rejected/accepted consistency | No double-winner or stale-price acceptance under contention |
| Settlement | payment rehearsal + conservation invariants | Zero unexplained cent drift; `author` writes pass on MariaDB strict mode |
| Deployment | maintenance duration, failed-deploy recovery | A failed deployment returns service to a known safe state |

Do not claim a performance win from a passing test suite alone. Publish a compact benchmark note with the commit, workload, environment, before/after metrics and any trade-off.

---

## 11. Suggested PR sequence for the next optimization cycle

### PR 0 — Close current critical/high work
`chore/release-gate-and-baseline`
- verify PR #5 is merged and its 10 reported checks are green on the current `main`;
- merge PR #6 only after inspecting the enum migration, both rollback guards, and MariaDB refund regression tests;
- refresh PR #7 docs so they no longer describe PR #5 as open or `settlement_lines` as still lacking `author`/`co_author`;
- capture initial memory/latency/queue/query baseline.

### PR 1 — Correct persistence contract (PR #6, as reported)
`fix/recipient-type-enum-guard`
- expand `refund_lines.recipient_type` for `author`/`co_author`;
- guard the migration 061 rollback and the new refund-lines migration rollback;
- add MariaDB schema + book settlement refund regression tests.

### PR 2 — Image asset lifecycle hardening
`perf/versioned-artwork-derivatives`
- idempotent source revision keys, stale-job protection, cache headers, public/private object separation.

### PR 3 — Payments/outbox idempotency audit
`test/payment-outbox-failure-modes`
- duplicate webhook, timeout ambiguity, duplicate dispatch and worker-crash integration tests;
- add only missing DB uniqueness/claims/reconciliation mechanisms.

### PR 4 — Targeted SQL improvements
`perf/measured-mariadb-hot-paths`
- slow-query evidence, query-plan captures and the smallest justified query/index changes.

### PR 5 — Public read-path caching
`perf/public-catalog-http-cache`
- safe anonymous cache/ETag/cursor pagination where measured and applicable.

### PR 6 — Queue and deployment recovery
`ops/queue-backpressure-and-deploy-recovery`
- bounded retries/leases, alerting, failure cleanup, action SHA pinning/minimal permissions.

### PR 7+ — Domain refactor slices
Proceed with canonical state specs, A1 rulesets, B CauseAllocation/refund segregation, C events, D parties, then annual tax election in separate PRs.

Each PR must state: problem, measured evidence or correctness invariant, schema impact, rollback approach, tests, before/after result, and explicit non-goals.

---

## 12. What not to do yet

- Do not add Redis just because a queue/cache store is database-backed; prove database-queue contention first.
- Do not add more Octane workers on a 1-vCPU/1-GB host without RAM and latency evidence.
- Do not increase `innodb_buffer_pool_size` from 64 MiB on intuition alone; check buffer-pool miss/read counters and cgroup pressure.
- Do not add indexes without query plans and representative data.
- Do not switch away from MariaDB FULLTEXT or restore Scout indexing without evidence that current search fails its product/performance requirements.
- Do not put R2 evidence/provenance documents behind a publicly accessible image domain.
- Do not rewrite `bids` as event sourcing before preserving the current lock/current-state implementation and proving concurrency tests.
- Do not merge Settlement, Donation and Impact into one ledger for convenience.
- Do not implement tax caps or the 65% aggregate ceiling as a generic fixed calculation without recipient-specific and taxpayer-regime rules.

---

## 13. Research sources

Official upstream references reviewed on 2026-10-14. Confirm the project's installed Laravel and MariaDB minor versions before applying version-specific APIs/options.

- Laravel Octane, worker count/max requests and persistent-memory cautions: https://laravel.com/docs/13.x/octane
- FrankenPHP worker mode and max-request restart guidance: https://frankenphp.dev/docs/worker/
- Laravel queues, `afterCommit`, overlap prevention and retry behavior: https://laravel.com/docs/13.x/queues
- Laravel deployment optimization/cache commands: https://laravel.com/docs/13.x/deployment
- Laravel cache locks/cache-store behavior: https://laravel.com/docs/13.x/cache
- MariaDB slow query log and selective query diagnostics: https://mariadb.com/docs/server/server-management/server-monitoring-logs/slow-query-log/slow-query-log-overview
- MariaDB `ANALYZE`/actual execution statistics: https://mariadb.com/docs/server/reference/sql-statements/administrative-sql-statements/analyze-and-explain-statements/analyze-statement
- MariaDB indexes and composite-index design: https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/optimization-and-indexes/building-the-best-index-for-a-given-select
- MariaDB InnoDB buffer pool: https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-buffer-pool
- Cloudflare R2 custom-domain cache: https://developers.cloudflare.com/cache/interaction-cloudflare-products/r2/
- Cloudflare R2 consistency/cache caveats: https://developers.cloudflare.com/r2/reference/consistency/
- Stripe idempotent requests: https://docs.stripe.com/api/idempotent_requests
- GitHub Actions supply-chain/security practices: https://docs.github.com/en/actions/how-tos/secure-your-work
