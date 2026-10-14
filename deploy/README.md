# Production Deployment — euction.artefak.eu

**Stack:** FrankenPHP (Octane) · MariaDB 11 · Reverb · database queue  
**Server:** Ubuntu 24.04 LTS · 1 vCPU · 1GB RAM · 25GB NVMe  
**RAM budget:** ~700MB peak (300MB buffer + ZRAM overflow)

## Memory budget (targets, not guarantees)

The values below are engineering targets. Confirm with `systemctl show` and `free -h`
under real load before treating them as established facts.

| Service                    | `MemoryHigh` | `MemoryMax` | Notes |
|----------------------------|-------------|-------------|-------|
| FrankenPHP Octane (1 worker) | 280M      | 320M        | Raise to 2 workers only after measuring headroom |
| MariaDB 11 (tuned)         | 180M        | 220M        | `performance_schema=OFF`, 64MB pool |
| Reverb WebSocket           | 65M         | 90M         | Single-node, no Redis |
| artisan queue:work         | 80M         | 120M        | `--memory=96` self-terminates before MemoryMax |
| OS + kernel + systemd      | —           | ~180–200M   | Measured at idle |
| **Sum of MemoryMax values**| —           | **~750M**   | Leaves ~274M buffer to 1GiB |

`MemoryHigh` = cgroup pressure signal (kernel starts reclaiming). `MemoryMax` = hard OOM boundary.  
Sum of `MemoryMax` limits intentionally fits within 1GiB physical RAM, unlike a naive sum of peaks.  
ZRAM (320M `mem_limit`, ~640M usable at 2:1) + 2GB NVMe swap absorb short-lived bursts.

**If under sustained memory pressure:** reduce `innodb_buffer_pool_size` to 48M,
or move MariaDB to a separate small server — no code changes needed.

## Files in this directory

| File | Purpose |
|------|---------|
| `setup-server.sh` | One-time provisioning (run as root after first VPS login) |
| `Caddyfile` | FrankenPHP + Caddy web server config |
| `mariadb.cnf` | MariaDB 11 minimal memory config → `/etc/mysql/conf.d/` |
| `php.ini` | PHP 8.4 + OPcache tuning → `/etc/php/8.4/cli/conf.d/` |
| `zramswap` | ZRAM config → `/etc/default/zramswap` |
| `sysctl-arteuction.conf` | Kernel vm.* tuning → `/etc/sysctl.d/` |
| `systemd/arteuction-octane.service` | FrankenPHP Octane systemd unit |
| `systemd/arteuction-reverb.service` | Reverb WebSocket systemd unit |
| `systemd/arteuction-queue.service`  | Queue worker systemd unit |

## Initial server setup (once)

```bash
# 1. SSH into VPS as root
ssh root@94.156.92.48

# 2. Copy deploy files to server
scp -r deploy/ root@94.156.92.48:/etc/arteuction/deploy/

# 3. Set DB password and run setup
export DB_PASSWORD="your_secure_password"
bash /etc/arteuction/deploy/setup-server.sh

# 4. Clone repository
sudo -u arteuction git clone git@github.com:arteuction/artefak.git /var/www/arteuction

# 5. Configure production environment
sudo -u arteuction cp /var/www/arteuction/.env.production.example /var/www/arteuction/.env
# Edit .env — fill in all CHANGE_ME values

# 6. Initial bootstrap
sudo -u arteuction bash -c "
  cd /var/www/arteuction
  composer install --no-dev --optimize-autoloader
  php artisan key:generate
  php artisan migrate --force
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  php artisan event:cache
"

# 7. Start services
systemctl start arteuction-octane arteuction-reverb arteuction-queue
```

## GitHub Actions: adding required secrets

Go to https://github.com/arteuction/artefak/settings/secrets/actions and add:

| Secret | Value |
|--------|-------|
| `VPS_HOST` | `94.156.92.48` |
| `VPS_USER` | `arteuction` |
| `VPS_SSH_KEY` | Private SSH key (generate with `ssh-keygen -t ed25519`) |

Then add the public key to `/home/arteuction/.ssh/authorized_keys` on the VPS.

Also add the environment `production` at https://github.com/arteuction/artefak/settings/environments  
with required reviewers if you want manual approval before deploys fire.

## Deploying a new release

After merging to `main` and CI + staging checks pass:

1. GitHub → Actions → **Deploy to Production** → Run workflow
2. Type `deploy` in the confirmation field
3. Workflow runs: rsync → migrate → payment-rehearsal check → reload Octane → health check

The deploy **aborts** if `pilot:payment-rehearsal` exits non-zero (financial integrity broken).

## Checking service health

```bash
systemctl status arteuction-octane arteuction-reverb arteuction-queue
journalctl -u arteuction-octane -f
curl -s https://euction.artefak.eu/api/v1/health | jq .
php artisan pilot:payment-rehearsal
```

## Three architectural profiles

Choose after measuring idle + peak RAM. Do not migrate to Profile B before Profile A is measured.

### Profile A — everything on 1GB VPS (current)
PHP/Octane + MariaDB + Reverb + queue, all local.  
Best for: cheap pilot, minimal ops overhead.  
Main constraint: all services compete for the same physical RAM.

### Profile B — app VPS + external services
Move MariaDB to a managed DB host (Hetzner DB €5/mo, PlanetScale free tier, etc.).  
Move Reverb to a separate small VPS or managed WebSocket provider (Pusher, Ably).  
Run low-priority queue jobs on the same low-priority timer model but optionally on a separate host.  
Frees local RAM by the actual measured MariaDB footprint (~150–220MB) and Reverb footprint (~65–90MB).  
Cost: network latency for DB queries (~1–5ms LAN, ~20ms cross-DC). Measure before committing.  
**No code changes** — only `.env` DB host and Reverb host values change.

### Profile C — 2GB single VPS
Same stack, double RAM. Keeps Profile A simplicity.  
Cost: ~€3–5/mo more (Hetzner CX22 vs CX11).  
Best choice if Profile A measurement shows sustained memory pressure but ops budget allows it.

## A/B test: Octane vs PHP-FPM ondemand

Before committing to FrankenPHP+Octane permanently, compare against PHP-FPM ondemand
using the pool config in `deploy/php-fpm.conf`. Steps:

```bash
# On VPS — measure Octane baseline first
systemctl show arteuction-octane -p MemoryCurrent,MemoryPeak

# Switch to PHP-FPM (see php-fpm.conf for Caddyfile change required)
systemctl stop arteuction-octane
systemctl start php8.4-fpm
# ... run equivalent load test ...
systemctl show php8.4-fpm -p MemoryCurrent,MemoryPeak

# Compare:
# - idle RSS (no traffic)
# - peak RSS under 5-10 concurrent requests
# - p95 latency (Octane saves ~50-100ms cold-start per request)
# - successful requests/sec per GiB RAM used
```

Choose the profile with the lowest peak RSS that still handles pilot-level concurrency
without unacceptable queue depth on HTTP requests.

## Queue architecture

Two tiers — critical runs permanently, low-priority runs on a 5-minute timer:

| Tier | Service | Jobs | Policy |
|------|---------|------|--------|
| Critical | `arteuction-queue.service` | Payment webhooks, Stripe transfers, settlement writes, outbox dispatch, bid re-auth | Always running, `Restart=always` |
| Low-priority | `arteuction-queue-low.service` (timer) | PDF exports, CSV imports, GDPR ZIP, image processing, reconciliation batch, large reports | Fires every 5min, stops when empty |

The low-priority worker is **absent from RAM between timer fires**, freeing ~80–120MB when idle.  
Adjust `OnUnitActiveSec` in `arteuction-queue-low.timer` to change the interval.

## Optimization priority order

Apply in this sequence — each step needs measurement before the next begins:

| Priority | Change | Expected effect | Risk |
|----------|--------|-----------------|------|
| **P0** | Measure actual RAM per service and memory pressure | Establishes real bottleneck | None |
| **P1** | Explicit FrankenPHP `num_threads` + worker/max-requests sync | Controls peak concurrency | Medium |
| **P1** | Queue worker recycle (`--memory`, `--max-jobs`, `--max-time`) | Eliminates accumulated leaks | Low |
| **P2** | SQL query optimization + targeted indexes | Reduces result-set size in memory | Medium |
| **P2** | Current-state projections for Auction / Settlement | Fast hot-paths without loading full history | Medium |
| **P3** | MariaDB slow-query log diagnostic window | Identifies real problem queries | Low |
| **P3** | OPcache size confirmed against production codebase | Prevents silent cache-full thrashing | Low |

Do not apply all changes at once. One change per commit, measure before and after.

**If memory pressure persists after P1–P3:** upgrade to 2GB RAM (preserves all architecture),
or move MariaDB to a separate host (no code changes). Do not split into microservices.

## Measuring memory (run after each config change)

```bash
# 1. Overall system memory
free -h

# 2. Per-service cgroup memory
#    MemoryCurrent = live RSS, MemoryPeak = high-water mark since last start
for svc in arteuction-octane arteuction-reverb arteuction-queue mariadb; do
  echo -n "$svc: "
  systemctl show "$svc" -p MemoryCurrent -p MemoryPeak -p MemoryHigh -p MemoryMax | tr '\n' ' '
  echo
done

# 3. Memory pressure via PSI
#    "some" = tasks occasionally blocked on memory (acceptable at low traffic)
#    "full" = all tasks blocked simultaneously (bad — investigate immediately)
cat /proc/pressure/memory

# 4. OPcache status — if cache_full=true, raise opcache.memory_consumption
php -r "
  \$s = opcache_get_status(false);
  echo 'cache_full: '; var_dump(\$s['cache_full']);
  echo 'used: ' . round(\$s['memory_usage']['used_memory']/1024/1024, 1) . 'MB / ';
  echo round((\$s['memory_usage']['used_memory']+\$s['memory_usage']['free_memory'])/1024/1024, 1) . 'MB total\n';
"

# 5. FrankenPHP thread count (confirm num_threads is not auto-scaling beyond config)
ps aux | grep frankenphp | wc -l

# 6. ZRAM actual compression ratio and mem_limit enforcement
zramctl

# 7. MariaDB connection count (confirm max_connections headroom)
mysql -e "SHOW STATUS LIKE 'Threads_connected';"

# 8. Enable slow query log for a diagnostic window (re-disable after sample is collected)
mysql -e "SET GLOBAL slow_query_log = 1; SET GLOBAL long_query_time = 0.5;"
# ... run real traffic or load test ...
mysql -e "SET GLOBAL slow_query_log = 0;"
mysqldumpslow /var/log/mysql/mariadb-slow.log | head -20
```

## Upgrading to full stack later

When traffic justifies it, upgrade with `.env` changes only — no code changes needed:

| Feature | Current (pilot) | Future |
|---------|-----------------|--------|
| Search | `SCOUT_DRIVER=database` | `SCOUT_DRIVER=meilisearch` + add Meilisearch container |
| Cache | `CACHE_STORE=file` | `CACHE_STORE=redis` + add Redis |
| Queues | `QUEUE_CONNECTION=database` | `QUEUE_CONNECTION=redis` + add Horizon |
| WebSocket scaling | Single node | `REVERB_SCALING_ENABLED=true` + add Redis |
