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
- `active` - Main catalog (includes all available apps)
- `missing` - Apps needing IPKs
- `archived` - Historical reference only

**Content Flags:**
- `post_shutdown` - Community-created apps after platform EOL
- `adult` - Adult content

### Repository Layer (includes/)

| File | Purpose |
|------|---------|
| `Database.php` | PDO singleton connection |
| `AppRepository.php` | App queries (search, filter, CRUD, related apps) |
| `MetadataRepository.php` | Detailed app metadata and images |
| `LogRepository.php` | Download/update logging and reports |
| `AccountRepository.php` | Accounts, roles, and device/web tokens (issue, verify, refresh, revoke) |
| `StorageRepository.php` | Per-account app KV storage backing `storage.php` (revisions, quota enforcement, account-deletion cascade via `deleteAccount()`) |

### API Endpoints (WebService/)

| Endpoint | Rate Limit | Purpose |
|----------|------------|---------|
| `getSearchResults.php` | 60/hour | App/author search |
| `getMuseumMaster.php` | 120/hour | Catalog listing |
| `getMuseumDetails.php` | 200/hour | App details with related apps |
| `countAppDownload.php` | — | Logs a download to `download_logs` (no body returned) |
| `getLatestVersionInfo.php` | — | Update check; museum app reads `0.json`, other apps read `getMuseumDetails.php` |
| `device.php` | 240/hour | webOS Account backend: HP-shaped device methods (`createDeviceAccount`, `authenticateFromDevice`, …), the token-authenticated profile-editor surface (`getAccountInfoAggregate`, `isUserValid`, `updateAccountInfo`, `changeEmailAddress`, `changePassword`, `assignDeviceName`, `updateUsername`) behind Settings → Accounts, plus plain-JSON web/PWA auth (`authenticateWeb` — takes an optional `device_name` so a browser session reads as `PWA-<name>` rather than a phantom device, `refreshToken`, `deauthenticate`). Sets its own CORS headers — see README "CORS" for the required nginx exemption |
| `storage.php` | 600/hour + per-account | Cloud app storage: per-account, per-app KV sync (`?m=get/getAll/list/set/setMany/delete/usage`). Auth via `Authorization: PalmAuth token=…` resolved by `AccountRepository::verifyDeviceToken()`; quotas from `storage_*` config keys; values are opaque client-scrambled blobs. Client SDK: `webos-common/AppStorage`. Sets its own CORS headers |

**`download_logs.source`** identifies the client: web frontend (unset/`app`), on-device Museum app (`webos` / `luneos`), and the patched HP first-party clients (`webos-appcatalog-enyo` for TouchPad, `webos-appcatalog-mojo` for phones). Use these to separate device installs from web in most-downloaded reports.

The patched HP clients also self-update from static manifests at the domain root — `appcatalog-touchpad.json` and `appcatalog-phones.json` (latest `version`, `versionNote`, IPK `filename`) — served over **HTTP** as a compatibility fallback for stock devices that haven't taken the community OTA. Devices running the OTA have TLS 1.3 / full modern HTTPS support.

**Sort Options** (for `getMuseumMaster.php` and web frontend):
- `recent` (default) - By `app_metadata.last_modified_time` descending
- `alpha` - Alphabetical by title
- `recommended` - By `apps.recommendation_order` descending

### Admin UI (admin/)

CRUD interface for managing catalog data. Secured via nginx basic auth + CSRF protection (Referer header validation in `includes/security.php`).

| Page | Purpose |
|------|---------|
| `index.php` | Dashboard with catalog stats + recently updated apps; requires `apps.edit`, otherwise redirects to `stats.php` |
| `stats.php` | Read-only version of the Dashboard (same numbers, no edit links) for logged-in accounts without `apps.edit`, e.g. `developer` or the read-only `viewer` role |
| `apps.php` | App list with search/filter/sort (by title, recommendation, or ID) |
| `app-edit.php` | Create/edit apps (includes recommendation_order) |
| `metadata-edit.php` | Edit extended metadata, screenshots, and lastModifiedTime |

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

Devices running the community OTA have TLS 1.3 / full modern HTTPS support; new APIs should be HTTPS-only. HTTP serving of packages/manifests is retained only as a compatibility fallback for stock devices without the OTA. Downloads are proxied through `downloadProxy.php` to serve HTTP-hosted content to HTTPS web users.

## External Dependencies (configured in config.php)

- **image_host** - Icons and screenshots
- **package_host** - IPK packages (served over HTTP for stock pre-OTA devices)
- **storage_host** - Base URL for the app-storage + web-auth endpoints (public via `getConfig.php`)
- **storage_**\* - App-storage quota/throttle keys enforced by `storage.php` (see `config-example.php`; omitted keys fall back to built-in defaults)
