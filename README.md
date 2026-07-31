# SUM Energy Network — Control Panel

LPG distribution management system for multi-branch operations. Built with Laravel 12 + Filament 3.3.

## Tech Stack

- **Backend:** Laravel 12.x, PHP 8.2+
- **Admin Panel:** Filament 3.3
- **Database:** MySQL 8.0
- **Cache:** Redis
- **API:** Laravel Sanctum (mobile app ready)
- **Frontend:** Tailwind CSS + Blade

## Features

- **Branch Management** — multi-branch hierarchy with role-based access
- **Stock Management** — real-time stock tracking, mutations, daily close
- **Delivery Orders** — full workflow: draft ? approval ? in-transit ? received
- **Sales** — daily sales entry with revenue tracking
- **Finance** — operational costs, P&L reports, receivables aging
- **Master Data** — branches, users, customers, vehicles, expeditions, LPG prices
- **Reports** — stock summary, shipment tracking, sales period, branch ranking, HPP
- **Excel Import** — bulk data seeding via downloadable templates
- **Document Scanner** — OCR via Claude Vision API (backend ready)
- **Push Notifications** — FCM integration for DO approvals and stock alerts

## Role Hierarchy

| Level | Role | Access |
|-------|------|--------|
| 1 | `owner_pusat` | Full access — all branches, all data |
| 2 | `regional_leader` | Regional access — can approve across branches |
| 3 | `owner_cabang` | Own branch — operational + finance read |
| 4 | `staff_gudang` | Own branch — operational input only |

## Installation

```bash
# 1. Clone repo
git clone <repo-url> && cd panelx

# 2. Install dependencies
composer install
npm install

# 3. Environment
cp .env.example .env
php artisan key:generate

# 4. Database
# Update .env with your MySQL credentials
php artisan migrate --force

# 5. Build assets
npm run build

# 6. Start server
php artisan serve
```

## Admin Panel

**URL:** `http://localhost:8000/admin`

Login with any active user account. Only `owner_pusat` and `regional_leader` can access the Import Data page.

## API

**Base URL:** `/api/v1` | **Auth:** Bearer token (Sanctum) | **Rate Limit:** 120 req/min

See `archived/docs/api_reference.md` for full endpoint documentation.

### Quick Start

```bash
# Login
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"secret"}'

# Use token
curl http://localhost:8000/api/v1/dashboard/main \
  -H "Authorization: Bearer {token}"
```

## Excel Import

Navigate to **Master Data ? Import Data** in the admin panel.

1. Click **Download All Templates (ZIP)** to get all 8 template files
2. Fill templates with your data in Excel
3. Select the matching table and upload each file
4. Review import results — errors show per-row with line numbers

**Supported tables:** branches, users, customers, vehicles, expeditions, lpg_prices, stock_items, sales_targets

## Project Structure

```
app/
+-- Filament/
¦   +-- Pages/          # Custom admin pages (ImportData, reports)
¦   +-- Resources/      # Filament CRUD resources
¦   +-- Widgets/        # Dashboard widgets
+-- Http/
¦   +-- Controllers/
¦   ¦   +-- Api/        # Mobile API controllers
¦   ¦   +-- ImportController.php
¦   +-- Resources/      # API JSON transformers
¦   +-- Traits/         # ApiResponse trait
+-- Models/             # Eloquent models
+-- Observers/          # Model observers (DO, StockItem)
+-- Providers/          # Service providers
¦   +-- Filament/       # Admin panel provider
+-- Support/            # XlsxReader, XlsxWriter
```

## Documentation

- `PROJECT_STATE.md` — **primary reference** — full project state, features, progress, schema, and pending work (read this first)
- `archived/docs/architecture.md` — project structure & API layer
- `archived/docs/database_schema.md` — all 15 tables
- `archived/docs/api_reference.md` — complete API endpoint reference
- `archived/docs/security_policies.md` — security implementation details
- `archived/plans/task4.md` — mobile app roadmap

## Security

- Sanctum token-based API auth (90-day expiry)
- Role-based access control on every endpoint
- Branch-scoped queries (IDOR prevention)
- HTML stripping + formula injection prevention on Excel imports
- Transactional imports (all-or-nothing per table)
- Rate limiting: 120 req/min per token

## License

MIT