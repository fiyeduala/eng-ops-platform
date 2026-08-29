# 12 — cPanel Deployment Architecture

## 1. Layout

The application root must sit **outside** `public_html`, with only the web root exposed.

```
/home/USER/
├── engops/                  ← application root (NOT web accessible)
│   ├── app/ bootstrap/ config/ database/ resources/ routes/ storage/ vendor/
│   ├── .env                 ← chmod 600
│   └── artisan
└── public_html/             ← document root
    ├── index.php            ← edited to point at ../engops
    ├── .htaccess
    ├── build/               ← Vite output
    └── storage → /home/USER/engops/storage/app/public   (branding only)
```

`public_html/index.php` — two edited lines:

```php
require __DIR__.'/../engops/vendor/autoload.php';
$app = require_once __DIR__.'/../engops/bootstrap/app.php';
```

## 2. Deployment steps

```bash
# 1. Create the MySQL database + user in cPanel, grant ALL PRIVILEGES.
# 2. Clone (cPanel → Git Version Control, or SSH):
cd ~ && git clone git@github.com:fiyeduala/eng-ops-platform.git engops && cd engops

# 3. Dependencies — production only, no dev packages
composer install --no-dev --optimize-autoloader --no-interaction

# 4. Environment
cp .env.example .env && php artisan key:generate --force
#    edit .env: APP_ENV=production, APP_DEBUG=false, APP_URL=https://…, DB_*, MAIL_*

# 5. Schema + configuration data
php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force   # permissions, statuses, templates

# 6. Storage
php artisan storage:link          # if the symlink is disallowed, create it in cPanel File Manager

# 7. Caches (re-run after every deploy)
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
```

Assets are built in CI or locally (`npm run build`) and the `public/build` output is deployed —
**no Node runtime is required on the server**.

## 3. Cron (§82)

cPanel → Cron Jobs → add **one** entry. Laravel's scheduler dispatches everything else.

```
* * * * * /usr/local/bin/php /home/USER/engops/artisan schedule:run >> /dev/null 2>&1
```

Verify the PHP binary path — many hosts use `/usr/local/bin/ea-php82`.

Scheduled work: daily executive report · escalation sweep · expiry sweep · KPI snapshots · overdue
task notifications · workflow SLA checks · stock reconciliation · backup · log/session/token pruning
· failed-job retry.

## 4. Queue without Supervisor (§81)

V1 uses the `database` queue driver. A second cron runs a bounded worker:

```
* * * * * /usr/local/bin/php /home/USER/engops/artisan queue:work --stop-when-empty --max-time=55 --tries=3 >> /dev/null 2>&1
```

`--max-time=55` guarantees the worker exits before the next minute's invocation, so workers never
pile up. `--stop-when-empty` means an idle system consumes almost nothing. If the host forbids
long-running processes entirely, set `QUEUE_CONNECTION=sync` — everything still works, just
synchronously.

## 5. Required `.env` (production)

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ops.example.com

DB_CONNECTION=mysql
DB_DATABASE=user_engops
DB_USERNAME=user_engops
DB_PASSWORD=…

SESSION_DRIVER=database        SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true           SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=database

FILESYSTEM_DISK=local
PRIVATE_DISK=local

MAIL_MAILER=smtp
MAIL_HOST=mail.example.com     MAIL_PORT=587     MAIL_ENCRYPTION=tls

LOG_CHANNEL=daily              LOG_LEVEL=warning
```

## 6. `.htaccess` hardening

Laravel's default `public/.htaccess` plus:

```apache
# HTTPS + HSTS
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set Referrer-Policy "strict-origin-when-cross-origin"

# Never serve dotfiles or backups
<FilesMatch "^\.|\.(env|log|sql|bak|ini|yml|json|lock)$">
  Require all denied
</FilesMatch>
```

Also place a `deny from all` `.htaccess` in `~/engops/` as defence in depth in case the application
root is ever mis-mapped under the document root.

## 7. Permissions

```
find ~/engops -type f -exec chmod 644 {} \;
find ~/engops -type d -exec chmod 755 {} \;
chmod -R 775 ~/engops/storage ~/engops/bootstrap/cache
chmod 600 ~/engops/.env
chmod 755 ~/engops/artisan
```

## 8. Host limits to check before committing (§1)

| Limit | Needed | Symptom if too low |
|-------|--------|--------------------|
| `memory_limit` | ≥ 256M | PDF/Excel generation fails |
| `max_execution_time` | ≥ 120s | large exports time out (mitigated by queueing) |
| `upload_max_filesize` / `post_max_size` | ≥ 64M | drawing uploads rejected |
| `max_input_vars` | ≥ 3000 | large custom forms truncate silently |
| Process/entry limits | moderate | queue cron killed |
| SSH access | preferred | forces File Manager deploys |
| `proc_open`/`exec` | enabled | `queue:work` and some artisan commands fail |

## 9. Zero-downtime-ish updates

cPanel rarely supports atomic symlink releases, so:

```bash
php artisan down --secret=…      # maintenance, with a bypass link for the deployer
git pull && composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

Migrations must be **backward compatible** (add columns before use, drop them a release later) so a
failed deploy can be rolled back by `git checkout` without a schema restore.

## 10. Backups (§85)

| Asset | Method | Retention |
|-------|--------|-----------|
| Database | scheduled `mysqldump` → gzip → offsite (S3/Drive/remote FTP) | 30 daily, 12 monthly |
| Private files | incremental archive of `storage/app/private` | 30 daily |
| `.env` + config | encrypted, stored separately from the DB dump | manual, versioned |
| cPanel full backup | weekly, hosting-provided | 4 weekly |

`BackupService` writes a `backup_runs` row (status, size, duration, destination, checksum) and the
admin dashboard surfaces **backup age** — a silent backup failure is itself an alert.

**Recovery drill (documented, quarterly):** restore dump to a scratch DB → point a staging `.env` at
it → `php artisan migrate:status` → smoke-test login, a project, a document download → record RTO.
An untested backup is not a backup.
