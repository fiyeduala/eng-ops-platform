# 13 — VPS Migration Architecture

## 1. Principle

Every cPanel limitation is absorbed by a **Laravel driver**, never by business logic. Migrating to a
VPS is therefore a configuration and infrastructure exercise — no module is rewritten (§81).

| Concern | cPanel (V1) | VPS (V2) | Code change |
|---------|-------------|----------|-------------|
| Queue | `database` + cron worker | Redis + Supervisor | `.env` only |
| Cache | `database` | Redis | `.env` only |
| Session | `database` | Redis | `.env` only |
| Scheduler | cPanel cron | systemd timer | none |
| Realtime | poll + web push | Reverb WebSockets | additive listeners |
| Search | MySQL FULLTEXT | Meilisearch (Scout) | swap Scout driver |
| Files | local private disk | S3-compatible object storage | `.env` + disk config |
| Mail | shared SMTP | dedicated SMTP/SES | `.env` only |
| Deploy | git pull | atomic releases (Deployer) | none |
| TLS | cPanel AutoSSL | Caddy / certbot | none |

Because no engine ever names a path or a driver — the private disk is `config/filesystems.php`'s
`local`, rooted at `storage/app/private`, and every model that stores a file names its disk by
constant — and because the controllers always stream through a policy check rather than exposing a
URL, moving files to S3 is a disk-config change and does not weaken the authorization model.

## 2. Target topology

```
            ┌────────────┐
  Internet →│ Nginx/Caddy│ TLS, rate limit, static assets
            └─────┬──────┘
                  │
            ┌─────┴──────┐
            │  PHP-FPM   │  app pool (opcache + JIT off, preload on)
            └─────┬──────┘
        ┌─────────┼─────────┬──────────────┐
   ┌────┴───┐ ┌───┴────┐ ┌──┴───┐   ┌──────┴──────┐
   │ MySQL  │ │ Redis  │ │ S3   │   │ Supervisor  │
   │ 8.x    │ │ cache/ │ │files │   │ queue:work  │
   │ replica│ │ queue  │ │      │   │ reverb      │
   └────────┘ └────────┘ └──────┘   └─────────────┘
```

## 3. Migration sequence (no downtime beyond one cutover window)

1. **Provision** VPS, PHP 8.2+, MySQL 8, Redis, Nginx, Supervisor; harden SSH, enable firewall.
2. **Deploy** the same repository; `composer install --no-dev`; build assets in CI.
3. **Replicate data** — restore the latest dump, then run MySQL replication from cPanel as a
   replica to keep the delta small.
4. **Sync files** — `rclone`/`rsync` `storage/app/private` → object storage or the new disk;
   verify with the stored `sha256` per document version.
5. **Dry run** on a staging hostname: run the full test suite against the VPS, verify login,
   document download, workflow action, PDF generation, queue processing.
6. **Cutover** — maintenance mode on cPanel, final delta sync, DNS switch (TTL lowered 24h prior),
   maintenance off on VPS.
7. **Enable upgrades** — flip `.env` to Redis, start Supervisor workers, enable Reverb, switch
   Scout to Meilisearch and reindex.
8. **Decommission** cPanel after a verified retention window (keep it read-only for 30 days).

## 4. Supervisor

```ini
[program:engops-queue]
command=php /var/www/engops/artisan queue:work redis --sleep=1 --tries=3 --max-time=3600
numprocs=4
autostart=true
autorestart=true
stopwaitsecs=3600
user=www-data
```

Separate pools for `default`, `notifications` and `reports` so a slow PDF batch never delays an
approval notification.

## 5. Scheduler

```ini
# systemd timer, replacing cPanel cron
[Service]
ExecStart=/usr/bin/php /var/www/engops/artisan schedule:run
```

Guard scheduled commands with `onOneServer()` once more than one app node exists — this requires the
Redis or database lock store, both already configured.

## 6. Optional V2 capabilities unlocked

- **Realtime** — live approval inbox, presence on records, live dashboards (Reverb).
- **Full-text search** — typo-tolerant global search with the same authorization filter applied as a
  post-query mask *and* as index-time scoping.
- **Horizontal scale** — multiple FPM nodes behind the load balancer; sessions and cache already
  centralised in Redis.
- **Read replicas** — reporting and dashboards routed to a replica via Laravel's `read`/`write`
  connection split.
- **Object storage** — unbounded document growth, lifecycle rules, versioned buckets.
- **Observability** — OpenTelemetry traces, slow-query log, Sentry.

## 7. What must not change

- The five gates in the access engine.
- The single workflow engine.
- Append-only audit semantics.
- Private-by-default file serving through an authorizing controller.

If a migration step would compromise any of these, the step is wrong.
