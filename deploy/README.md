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

## Measuring memory (run after each config change)

```bash
# Overall system memory
free -h

# Per-service cgroup memory (MemoryCurrent = live RSS, MemoryPeak = high-water mark)
for svc in arteuction-octane arteuction-reverb arteuction-queue mariadb; do
  echo -n "$svc: "
  systemctl show "$svc" -p MemoryCurrent -p MemoryPeak -p MemoryHigh -p MemoryMax | tr '\n' ' '
  echo
done

# Memory pressure (some = occasional, full = sustained — bad for live auctions)
cat /proc/pressure/memory

# OPcache cache_full check (if true, raise opcache.memory_consumption)
php -r "var_dump(opcache_get_status()['cache_full']);"

# ZRAM actual compression ratio
zramctl
```

## Upgrading to full stack later

When traffic justifies it, upgrade with `.env` changes only — no code changes needed:

| Feature | Current (pilot) | Future |
|---------|-----------------|--------|
| Search | `SCOUT_DRIVER=database` | `SCOUT_DRIVER=meilisearch` + add Meilisearch container |
| Cache | `CACHE_STORE=file` | `CACHE_STORE=redis` + add Redis |
| Queues | `QUEUE_CONNECTION=database` | `QUEUE_CONNECTION=redis` + add Horizon |
| WebSocket scaling | Single node | `REVERB_SCALING_ENABLED=true` + add Redis |
