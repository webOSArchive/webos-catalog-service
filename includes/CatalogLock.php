<?php
/**
 * CatalogLock - superadmin switch that makes the catalog read-only during
 * maintenance. Covers apps, metadata, images, categories, vendors, claims and
 * IPKs; accounts, app storage and logs keep working.
 *
 * The switch is an empty flag file, not a database row, so it holds across a
 * database restore. It lives in WebService/__rateLimit because that folder is
 * already writable by the web user and gitignored. Removing the file by hand
 * turns the mode off.
 *
 * Enforcement is admin_block_catalog_writes() in admin/includes/security.php.
 */
class CatalogLock {

    private static function flagPath(): string {
        return __DIR__ . '/../WebService/__rateLimit/catalog-read-only';
    }

    public static function isActive(): bool {
        return file_exists(self::flagPath());
    }

    /** @return int|null Unix time read-only mode was turned on, or null if off. */
    public static function activeSince(): ?int {
        $time = @filemtime(self::flagPath());
        return $time === false ? null : $time;
    }

    /** @return bool false if the flag could not be written. */
    public static function enable(): bool {
        return @touch(self::flagPath());
    }

    /** @return bool false if the flag could not be removed. */
    public static function disable(): bool {
        return !self::isActive() || @unlink(self::flagPath());
    }
}
