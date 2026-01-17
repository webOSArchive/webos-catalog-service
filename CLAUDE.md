# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PHP backend for webOS App Museum II - a historical archive of Palm/HP webOS apps. Provides search, browsing, and download capabilities for archived applications.

**Live site:** http://appcatalog.webosarchive.org

## Development Setup

**Requirements:**
- nginx with PHP-FPM
- PHP with PDO MySQL extension
- MySQL/MariaDB database

**Configuration:**
1. Copy `WebService/config-example.php` to `WebService/config.php`
2. Configure database credentials (db_host, db_name, db_user, db_pass)
3. Configure external hosts (image_host, package_host)

**Database Setup:**
1. Create MySQL database
2. Run `migration/schema.sql` to create tables
3. Run `php migration/migrate.php` to import JSON data (if migrating)

**No build system or package manager** - Pure PHP.

**No automated tests** - Manual testing via browser or direct API calls.

## Architecture

### Database Schema

App data is stored in MySQL. Key tables:

| Table | Purpose |
|-------|---------|
| `apps` | Core app data (title, author, category, device flags) |
| `app_metadata` | Extended metadata (description, version, pricing) |
| `app_images` | Screenshots and thumbnails |
| `categories` | Category definitions with display order |
| `authors` | Vendor/author information |
| `museum_sessions` | Session tracking for pagination |
| `download_logs` | Download tracking |
| `update_check_logs` | Update check tracking |

**App Status Values:**
- `active` - Main catalog (formerly archivedAppData.json)
- `newer` - Post-freeze submissions (formerly newerAppData.json)
- `missing` - Apps needing IPKs (formerly missingAppData.json)
- `archived` - Historical reference only (masterAppData.json exclusives)

### Repository Layer (includes/)

| File | Purpose |
|------|---------|
| `Database.php` | PDO singleton connection |
| `AppRepository.php` | App queries (search, filter, CRUD) |
| `MetadataRepository.php` | Detailed app metadata |
| `SessionRepository.php` | Museum session management |
| `LogRepository.php` | Download/update logging |

### API Endpoints (WebService/)

| Endpoint | Rate Limit | Purpose |
|----------|------------|---------|
| `getSearchResults.php` | 60/hour | App/author search |
| `getMuseumMaster.php` | 120/hour | Catalog listing |
| `getMuseumDetails.php` | 200/hour | App detail proxy |

### Admin UI (admin/)

CRUD interface for managing catalog data. Secured via nginx basic auth.

| Page | Purpose |
|------|---------|
| `index.php` | Dashboard with stats |
| `apps.php` | App list with search/filter |
| `app-edit.php` | Create/edit apps |
| `metadata-edit.php` | Edit extended metadata |
| `categories.php` | Category management |
| `authors.php` | Author/vendor management |
| `logs.php` | View download/update logs |

**nginx basic auth configuration:**
```nginx
location /admin {
    auth_basic "Admin Area";
    auth_basic_user_file /etc/nginx/.htpasswd;
}
```

### Rate Limiting

File-based tracking per IP in `__rateLimit/` directory.

```php
checkRateLimit(60, 3600);  // 60 requests per hour
```

**Critical pattern:** Internal PHP files must NOT make HTTP requests to rate-limited endpoints. Use repository classes or common.php wrapper functions instead.

### Web Interface

- `showMuseum.php` - Browsable catalog with categories/search
- `showMuseumDetails.php` - App detail page
- `app/index.php` - `/app/<title>` search redirect
- `author/index.php` - `/author/<name>` profile page

### Protocol Handling

Legacy webOS devices cannot handle modern HTTPS. The system serves HTTP to legacy devices and uses `Upgrade-Insecure-Requests` header for modern clients.

### Download Security

Links are base64-encoded with session salt and decoded client-side via `downloadHelper.php` to prevent direct scraping.

## Migration

The `migration/` folder contains tools for initial data import:

- `schema.sql` - Full database schema
- `migrate.php` - Imports JSON files into database

```bash
# Full migration
php migration/migrate.php --verbose

# Partial migration options
php migration/migrate.php --skip-apps --skip-metadata --skip-authors
php migration/migrate.php --dry-run
```

## External Dependencies (configured in config.php)

- **image_host** - Icons and screenshots (Internet Archive)
- **package_host** - IPK packages (Internet Archive + mirrors)

**Note:** Database credentials in config.php are filtered from the public `getConfig.php` endpoint.
