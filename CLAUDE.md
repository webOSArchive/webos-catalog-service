# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

webOS App Museum II - A PHP backend and web interface serving as a historical archive of Palm/HP webOS mobile applications. The project preserves apps from the defunct HP/Palm App Catalog (shutdown January 2015).

**Live site:** https://appcatalog.webosarchive.org

## Development Setup

**Requirements:**
- nginx with PHP-FPM
- PHP with PDO MySQL extension
- MySQL/MariaDB database

**Configuration:**
1. Copy `WebService/config-example.php` to `WebService/config.php`
2. Configure database credentials (db_host, db_name, db_user, db_pass)
3. Configure external hosts (image_host, package_host)

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
| `app_relationships` | Bidirectional related apps links |
| `categories` | Category definitions with display order |
| `authors` | Vendor/author information |
| `download_logs` | Download tracking |
| `update_check_logs` | Update check tracking |

**App Status Values:**
- `active` - Main catalog
- `newer` - Post-freeze submissions
- `missing` - Apps needing IPKs
- `archived` - Historical reference only

### Repository Layer (includes/)

| File | Purpose |
|------|---------|
| `Database.php` | PDO singleton connection |
| `AppRepository.php` | App queries (search, filter, CRUD, related apps) |
| `MetadataRepository.php` | Detailed app metadata and images |
| `LogRepository.php` | Download/update logging and reports |

### API Endpoints (WebService/)

| Endpoint | Rate Limit | Purpose |
|----------|------------|---------|
| `getSearchResults.php` | 60/hour | App/author search |
| `getMuseumMaster.php` | 120/hour | Catalog listing |
| `getMuseumDetails.php` | 200/hour | App details with related apps |

### Admin UI (admin/)

CRUD interface for managing catalog data. Secured via nginx basic auth.

| Page | Purpose |
|------|---------|
| `apps.php` | App list with search/filter |
| `app-edit.php` | Create/edit apps |
| `metadata-edit.php` | Edit extended metadata and screenshots |

### Web Interface

- `showMuseum.php` - Browsable catalog with categories/search
- `showMuseumDetails.php` - App detail page with lightbox screenshots
- `app/index.php` - `/app/<title>` search redirect
- `author/index.php` - `/author/<name>` profile page
- `downloadProxy.php` - HTTPS proxy for HTTP package downloads

### Rate Limiting

File-based tracking per IP in `__rateLimit/` directory.

```php
checkRateLimit(60, 3600);  // 60 requests per hour
```

### Protocol Handling

Legacy webOS devices cannot handle modern HTTPS. Downloads are proxied through `downloadProxy.php` to serve HTTP content to HTTPS users.

## External Dependencies (configured in config.php)

- **image_host** - Icons and screenshots
- **package_host** - IPK packages (HTTP only, no SSL)
