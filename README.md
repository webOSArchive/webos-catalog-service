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

### CORS (nginx)

The nginx config adds a global `Access-Control-Allow-Origin: *` header for the web frontend and static content. `WebService/device.php` and `WebService/storage.php` manage their own CORS in PHP (they answer OPTIONS preflights and put headers on 4xx responses), so they must be **exempted** from the global header — a duplicated `Access-Control-Allow-Origin` makes browsers reject every request. Give each an exact-match location containing the same fastcgi directives as the generic `.php` location (copy them verbatim) and **no** `add_header`:

```nginx
location = /WebService/device.php  { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php-fpm.sock; }
location = /WebService/storage.php { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php-fpm.sock; }
```

Two pitfalls, both learned the hard way: an exact-match location *without* working fastcgi directives serves the PHP **source** as static text; and if the global `add_header` sits at `server {}` level, these locations still inherit it unless they declare an `add_header` of their own (e.g. `add_header Vary Origin;`). Any future endpoint that emits its own CORS headers needs the same exemption. Verify with:

```bash
curl -s -D - -o /dev/null -X OPTIONS https://<host>/WebService/storage.php | grep -i access-control
```

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
| `accounts` | User accounts (admin + legacy-client) |
| `roles` / `account_roles` | Roles and their assignment to accounts |
| `account_tokens` | Device auth tokens for legacy clients |

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

### User Accounts

Accounts exist only for **admin/staff** and **legacy-client support** — there is
no web sign-up, and web visitors never need an account to browse. Accounts are
**provisioned by an admin** — from the command line, or from the **Accounts**
page inside `/admin` (superadmins only).

**One-time setup** — apply the schema migration (matches your `config.php` DB
user/name; MariaDB — see the file header for MySQL 8):

```bash
mysql -u <db_user> -p <db_name> < sql/migrations/0001_accounts.sql
```

**Add a user:**

```bash
php scripts/create-account.php <username> [role]
```

You'll be prompted for an optional email and a password (entered hidden, never
passed on the command line). `role` defaults to `superadmin`. Examples:

```bash
php scripts/create-account.php jon                 # superadmin (use for the first account)
php scripts/create-account.php alice curator       # a curator
```

**Roles** (the capabilities behind each are defined in `includes/Capabilities.php`):

| Role | For |
|------|-----|
| `superadmin` | Everything, including managing other accounts |
| `admin` | Full catalog management (no account management) |
| `curator` | Edit apps / categories / authors, moderate reviews |
| `developer` | Submit and manage their own apps (no admin portal) |

**Signing in:** `/admin` now requires an app-level login *in addition to* the
nginx basic auth (basic auth stays as an outer gate for now). After the basic
auth prompt, you land on a sign-in page; log in with an account that has the
`admin.access` capability (superadmin/admin/curator). `developer` accounts have
no admin access. Sessions are isolated from the front-end site; "Log out" is in
the admin nav.

Bootstrap order on a fresh install: run the migration, create the first account
with `scripts/create-account.php` (make it a `superadmin`), then sign in.

Roadmap and status for the accounts system (done + planned phases) live in
[`docs/ACCOUNTS_ROADMAP.md`](docs/ACCOUNTS_ROADMAP.md).

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

Note: Package hosts should still support plain HTTP as a compatibility fallback for stock devices that haven't taken the community OTA; devices running the OTA have TLS 1.3 / full modern HTTPS support. The `downloadProxy.php` script proxies HTTP content to HTTPS web users.

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
