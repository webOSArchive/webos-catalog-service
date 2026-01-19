# webos-catalog-backend

PHP Back-end for webOS App Catalog restoration project. Front-end is here: [https://github.com/codepoet80/webos-catalog-frontend](https://github.com/codepoet80/webos-catalog-frontend)

![App Icon](assets/icon.png)

You can use this app on a Pre3 or Touchpad, or access the catalog in a browser at [https://appcatalog.webosarchive.org](https://appcatalog.webosarchive.org)

## Requirements

- nginx with PHP-FPM
- PHP with PDO MySQL extension and `mb_internal_encoding`
- MySQL/MariaDB database

## Setup

1. Copy `WebService/config-example.php` to `WebService/config.php`
2. Configure database credentials (db_host, db_name, db_user, db_pass)
3. Configure external hosts for images and packages
4. Secure the `/admin` path with nginx basic auth

### Upload Limits (for IPK management)

To allow larger file uploads via the admin interface, configure both PHP and nginx:

**PHP** (php.ini or pool config like `/etc/php-fpm.d/www.conf`):
```ini
upload_max_filesize = 200M
post_max_size = 210M
```

**nginx** (server or http block):
```nginx
client_max_body_size 200M;
```

Restart both services after changes. Adjust the size values as needed for your largest IPKs.

## Data

1. Museum Database is periodically backed-up in Releases on this GitHub repo
2. IPKs are backed-up at archive.org: https://archive.org/details/webosappcatalog
3. AppImages are backed-up at archive.org: https://archive.org/details/webosappcatalog-supplementary

## Architecture

### Database

All app data is stored in MySQL. Key tables:

| Table | Purpose |
|-------|---------|
| `apps` | Core app data (title, author, category, device flags) |
| `app_metadata` | Extended metadata (description, version, screenshots) |
| `app_images` | Screenshot and thumbnail paths |
| `app_relationships` | Bidirectional related apps links |
| `categories` | Category definitions |
| `authors` | Vendor/author information |
| `download_logs` | Download tracking |
| `update_check_logs` | Update check tracking |

### App Status

- `active` - Main catalog (all available apps)
- `missing` - Apps needing IPKs (community hunting list)
- `archived` - Historical reference only

The `post_shutdown` flag identifies community-created apps after platform EOL.

### API Endpoints (WebService/)

| Endpoint | Purpose |
|----------|---------|
| `getSearchResults.php` | App/author search |
| `getMuseumMaster.php` | Catalog listing with filtering |
| `getMuseumDetails.php` | App details with related apps |

### Admin UI (/admin)

CRUD interface for managing catalog data, secured via nginx basic auth.

### Web Interface

- `showMuseum.php` - Browsable catalog
- `showMuseumDetails.php` - App detail page with lightbox screenshots
- `author/index.php` - Author profile pages
- `downloadProxy.php` - HTTPS proxy for HTTP package downloads

## Historical Context

The original museum used JSON files to list apps from the HP/Palm App Catalog. As of March 2022, the archive was considered "frozen" with all known IPKs indexed. The system has since been migrated to a MySQL database for better management and admin capabilities.

### Legacy JSON Files (no longer used)

- **masterAppData.json** - Record of all apps from the HP/Palm catalog at shutdown (January 2015)
- **archivedAppData.json** - Apps with archived IPKs
- **missingAppData.json** - Apps without archived IPKs
- **newerAppData.json** - Post-freeze community submissions (now tracked via `post_shutdown` flag)

## External Content

The backend depends on archived content hosted by the community. Configure hosts in `config.php`:

- **AppImages**: Icons and screenshots - [archive](https://archive.org/download/webosappcatalog-supplementary)
- **AppPackages**: IPK files - [archive](https://archive.org/details/webosappcatalog)

Note: Package hosts must support HTTP (legacy webOS devices cannot handle modern HTTPS). The `downloadProxy.php` script proxies HTTP content to HTTPS web users.

## What is This?

This is the back-end of an app museum for the defunct mobile webOS platform, made by Palm and later acquired by HP. The platform ran on devices like the Palm Pre or Pixi, or the HP Pre3 or TouchPad.

webOS technology was acquired by LG and repurposed for TVs and IoT devices, but they made significant changes and this app will not run on those platforms.

Releases of this app, and many other new and restored apps, can be found in the [webOS Archive App Museum](https://appcatalog.webosarchive.org).

## Why?

Aside from being a fan of the platform, the author thinks consumers have lost out now that the smart phone ecosystem has devolved into a duopoly. Apple and Google take turns copying each other, and consumers line up to buy basically the same new phone every year. The era when webOS, Blackberry and Windows Phone were serious competitors was marked by creativity in form factor and software development, which has been lost. This app represents a (futile) attempt to keep webOS mobile devices useful for as long as possible.

The website [www.webosarchive.org](https://webosarchive.org) recovers, archives and maintains material related to development, and hosts services that restore functionality to webOS devices. A small but active [community](https://www.webosarchive.org/discord) of users take advantage of these services to keep their retro devices alive.

## Learn More

- [webOS Archive Documentation](https://www.webosarchive.org/docs/)
- [Restored SDK](https://sdk.webosarchive.org)
- [Discord Community](https://www.webosarchive.org/discord)
