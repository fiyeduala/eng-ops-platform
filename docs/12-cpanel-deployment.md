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

`storage/app/private` — documents, drawings, exports, daily briefings — is **never** symlinked into
`public_html`. It is reached only through controllers that ran a policy check first (§67). If a
deploy ever leaves a second symlink pointing at it, that is the whole authorization model gone.

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

# 3. Dependencies — production only, no dev packages.  CHECK §2.1 FIRST: this step
#    fails outright on a host with gd, zip or the XML extensions switched off.
composer install --no-dev --optimize-autoloader --no-interaction

# 4. Environment
cp .env.example .env && php artisan key:generate --force
#    edit .env: APP_ENV=production, APP_DEBUG=false, APP_URL=https://…, DB_*, MAIL_*

# 5. Schema + the permission catalogue
php artisan migrate --force
php artisan db:seed --class=PermissionSeeder --force   # NAME THE CLASS. See the warning below.

# 6. Storage
php artisan storage:link          # if the symlink is disallowed, create it in cPanel File Manager

# 7. Caches (re-run after every deploy)
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
```

Assets are built in CI or locally (`npm run build`) and the `public/build` output is deployed —
**no Node runtime is required on the server**.

> **Never run a bare `php artisan db:seed --force` on a production install.** `PermissionSeeder` is
> application structure — the resolver fails closed, so a system without the catalogue grants
> nothing to anybody — and it is the only seeder a live tenant should ever see. `DemoSeeder` is
> Meridian Engineering and its fictional staff, and it must not be run against a real company's
> database. Name the class explicitly, every time.

Everything else a company needs — organization, org units, statuses, numbering series, option lists,
roles, workflows, templates — is created **through the screens** by the first administrator (§73).
There is no configuration seeder to run, by design: anything a deploy script had to seed would be
something a company could not later change for itself.

### 2.1 PHP extensions (check before `composer install`)

`composer install` **fails outright** on a host missing any of these, so this is the first thing to
verify, not the last. On cPanel they are toggled per domain under **Select PHP Version →
Extensions** (or MultiPHP INI Editor).

| Extension | Required by | Missing means |
|-----------|-------------|---------------|
| `zip`, `xml`, `xmlreader`, `xmlwriter`, `simplexml`, `dom`, `libxml` | PhpSpreadsheet, PhpWord | no `.xlsx` or `.docx` exports |
| `gd` | PhpSpreadsheet, PhpWord | as above — both hard-require it even for text-only output |
| `mbstring` | Dompdf, PhpSpreadsheet | no PDFs, no daily executive report |
| `ctype`, `iconv`, `fileinfo`, `filter`, `zlib`, `json` | PhpSpreadsheet | `composer install` refuses to resolve |
| `pdo_mysql`, `openssl`, `tokenizer`, `bcmath` | Laravel | the framework will not boot |

`php -m` on the server, compared against that list, is the whole check.

## 3. Cron (§82)

cPanel → Cron Jobs → add **one** entry. Laravel's scheduler dispatches everything else.

```
* * * * * /usr/local/bin/php /home/USER/engops/artisan schedule:run >> /dev/null 2>&1
```

Verify the PHP binary path — many hosts use `/usr/local/bin/ea-php82`.

**That is the only cron entry this platform needs, including for the queue.** See §4.

Everything registered in `routes/console.php`, which is the authority — this table is a summary of
it and nothing else:

| Command | When | What it does |
|---------|------|--------------|
| `queue:work --stop-when-empty --max-time=55` | every minute | drains background work, then exits (§4) |
| `approvals:sweep` | hourly | escalates overdue approval stages one level (§25) |
| `visitors:close-out` | hourly | closes visits nobody signed out of |
| `exports:sweep` | hourly | deletes prepared export files past their retention (§67) |
| `report:daily-executive` | hourly | sends each company its briefing **at that company's own hour** (§55) |
| `movements:apply` | 00:30 | applies due stock movements |
| `kpi:snapshot` | 01:00 | freezes the day's KPI figures (§56) |
| `expiry:sweep` | 06:30 | certificate, insurance and document expiry notices |

Two of the hourly entries are hourly for a reason worth knowing before someone "optimises" them to
daily. `report:daily-executive` runs every hour and almost always decides it is not yet this
company's chosen hour — twenty-three cheap passes are what buy a per-tenant time setting out of one
shared cron. `exports:sweep` is hourly because export retention is configured in **hours**, and a
daily sweep would make a two-hour setting mean up to twenty-six.

Every entry is `withoutOverlapping()` and `runInBackground()`, so a slow run is never joined by the
next one, and a host that has disabled `proc_open`/`exec` will run them in series instead (§8).

## 4. Queue without Supervisor (§81)

V1 uses the `database` queue driver, and **the worker is already inside the scheduler** — do not add
a second cron entry for it. `routes/console.php` registers:

```
queue:work --stop-when-empty --max-time=55 --tries=1 --quiet   → everyMinute()->withoutOverlapping(2)
```

A raw second cron line would run a worker the scheduler's overlap lock cannot see, which is how a
shared-hosting account collects a process-count complaint.

`--max-time=55` guarantees the worker is dead before the next minute fires. `--stop-when-empty`
means an idle system consumes almost nothing — this is a bounded worker, not a daemon, and must not
become one: no Supervisor, no systemd unit, no `nohup`, nothing for a company to discover has been
dead for six weeks. `--tries=1` is deliberate: the queue's default of retrying is precisely wrong for
work that failed because it was too big, and the person has already been told it failed.

**What is on the queue is exports, and nothing else.** Notifications are sent inline on purpose, so
an account with no working cron at all still notifies people — it just leaves large exports sitting
at "Waiting" on a page that says so, which is a missing feature rather than a broken system. If the
host forbids background processes entirely, set `QUEUE_CONNECTION=sync` and exports run in-request.

## 5. Required `.env` (production)

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ops.example.com

DB_CONNECTION=mysql            # `.env.example` ships sqlite, and the four keys below
DB_HOST=127.0.0.1              # are COMMENTED OUT in it — uncomment them
DB_DATABASE=user_engops
DB_USERNAME=user_engops
DB_PASSWORD=…

SESSION_DRIVER=database        SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true           SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=database

FILESYSTEM_DISK=local

MAIL_MAILER=smtp
MAIL_HOST=mail.example.com     MAIL_PORT=587     MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=ops@example.com
MAIL_FROM_NAME="Your Company Operations"

LOG_CHANNEL=daily              LOG_LEVEL=warning
```

**There is no `PRIVATE_DISK` setting.** `config/filesystems.php` roots the `local` disk at
`storage/app/private`, so `local` **is** the private disk — everything an authorization check stands
between (documents, drawings, exports, daily briefings) is already outside the document root, with
`serve => true` so it is only ever delivered through a controller that checked (§67). `public` is
branding assets and nothing else. Leave `FILESYSTEM_DISK=local`.

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
| — for the cron PHP binary | ≥ 300s | the daily report composes a board and renders a PDF **per recipient**; the engine caps that at 40 and says so rather than truncating |
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
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan up
```

Migrations must be **backward compatible** (add columns before use, drop them a release later) so a
failed deploy can be rolled back by `git checkout` without a schema restore.

## 10. Backups (§85)

> **Status: operator procedure, not a platform feature.** There is no `BackupService`, no
> `backup_runs` table and no backup-age tile in this build. Backups are cPanel's scheduled
> `mysqldump` plus the host's own account backup, arranged by whoever installs the system. Phase 9
> owns the decision about whether a backup **record** belongs in the application at all — a platform
> that claims to be watching a backup it is not actually running is worse than one that says nothing.
> Until then, treat the table below as the runbook to follow by hand.

| Asset | Method | Retention |
|-------|--------|-----------|
| Database | scheduled `mysqldump` → gzip → offsite (S3/Drive/remote FTP) | 30 daily, 12 monthly |
| Private files | incremental archive of `storage/app/private` | 30 daily |
| `.env` + config | encrypted, stored separately from the DB dump | manual, versioned |
| cPanel full backup | weekly, hosting-provided | 4 weekly |

The private files line is the one people skip, and it is the one that cannot be reconstructed: the
database holds the `documents` and `exports` rows, but every drawing, certificate and signed PDF
lives in `storage/app/private` and exists nowhere else.

**Recovery drill (documented, quarterly):** restore dump to a scratch DB → point a staging `.env` at
it → `php artisan migrate:status` → smoke-test login, a project, a document download → record RTO.
An untested backup is not a backup.
