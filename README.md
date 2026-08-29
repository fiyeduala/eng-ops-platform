# EngOps — Enterprise Engineering & Operations Management Platform

A configurable, multi-tenant-ready **digital operating system** for engineering, construction,
energy, oil & gas, infrastructure, manufacturing and facilities-management organizations.

Built with **PHP 8.2 · Laravel 12 · MySQL/MariaDB · Blade · Tailwind CSS · Alpine.js**, targeting
**Laravel-compatible cPanel hosting** first and remaining VPS-ready.

---

## The Five Core Engines

Every module in the platform is built on five foundational engines rather than re-implementing the
same concerns in isolation.

| # | Engine | Answers |
|---|--------|---------|
| 1 | **Organization + Access** | Who is this person and what may they see and do? |
| 2 | **Workflow** | How does work move between people, departments and stages? |
| 3 | **Record + Document** | What information is created, reviewed, approved, signed, archived? |
| 4 | **Activity + Audit** | Who did what, when, and to which record? |
| 5 | **Configuration** | How does an administrator change how the company operates — without code? |

## Central Architectural Hierarchy

```
ORGANIZATION  →  DEPARTMENT / TEAM  →  PROJECT  →  ROLE / ASSIGNMENT
      →  RECORD  →  WORKFLOW  →  APPROVAL / SIGNATURE
      →  ACTIVITY / AUDIT  →  REPORTING / DASHBOARD
```

---

## Documentation

Architecture and design documents live in [`docs/`](docs/):

| Document | Contents |
|----------|----------|
| [01-system-architecture.md](docs/01-system-architecture.md) | System architecture & layering |
| [02-module-dependencies.md](docs/02-module-dependencies.md) | Module dependency map |
| [03-database-erd.md](docs/03-database-erd.md) | Entity relationship model |
| [04-access-model.md](docs/04-access-model.md) | Organization / access model |
| [05-project-visibility.md](docs/05-project-visibility.md) | Project visibility model |
| [06-rbac-matrix.md](docs/06-rbac-matrix.md) | RBAC & permission matrix |
| [07-workflow-architecture.md](docs/07-workflow-architecture.md) | Workflow engine design |
| [08-record-lifecycle.md](docs/08-record-lifecycle.md) | Record lifecycle architecture |
| [09-document-architecture.md](docs/09-document-architecture.md) | Document & version architecture |
| [10-notification-architecture.md](docs/10-notification-architecture.md) | Notification architecture |
| [11-reporting-architecture.md](docs/11-reporting-architecture.md) | Reporting & KPI architecture |
| [12-cpanel-deployment.md](docs/12-cpanel-deployment.md) | cPanel deployment architecture |
| [13-vps-migration.md](docs/13-vps-migration.md) | VPS migration architecture |
| [14-roadmap.md](docs/14-roadmap.md) | Phased development roadmap |

---

## Requirements

- PHP **8.2+** with `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`,
  `bcmath`, `fileinfo`, `curl`, `zip`, `gd`, `intl`
- MySQL **8.0+** / MariaDB **10.6+**
- Composer 2.x
- Node 18+ (build-time only — no Node runtime is required in production)

## Local Setup

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

# configure DB credentials in .env, then:
php artisan migrate --seed

npm run dev        # or: npm run build
php artisan serve
```

Seeded demo credentials are printed by the seeder; no insecure default production password is
hard-coded.

## Scheduler (cPanel Cron)

```
* * * * * /usr/local/bin/php /home/USER/engops/artisan schedule:run >> /dev/null 2>&1
```

## Queue Worker

V1 uses the **database** queue driver — no Redis or Supervisor required.

```
* * * * * /usr/local/bin/php /home/USER/engops/artisan queue:work --stop-when-empty --max-time=55
```

---

## License

Proprietary. All rights reserved.
