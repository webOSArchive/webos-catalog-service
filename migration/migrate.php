<?php
/**
 * JSON to MySQL Migration Script
 *
 * Imports all catalog data from JSON files into the MySQL database.
 *
 * Usage: php migrate.php [options]
 *   --skip-apps      Skip app catalog migration
 *   --skip-metadata  Skip metadata file migration
 *   --skip-authors   Skip author metadata migration
 *   --dry-run        Show what would be done without making changes
 *   --verbose        Show detailed progress
 *
 * Run after creating the database with schema.sql:
 *   mysql -u root -p < schema.sql
 *   php migrate.php --verbose
 */

// Configuration
$backendPath = dirname(__DIR__);
$metadataPath = dirname(dirname(__DIR__)) . '/webos-catalog-metadata';

// Parse command line options
$options = getopt('', ['skip-apps', 'skip-metadata', 'skip-authors', 'dry-run', 'verbose']);
$skipApps = isset($options['skip-apps']);
$skipMetadata = isset($options['skip-metadata']);
$skipAuthors = isset($options['skip-authors']);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

function log_msg($msg, $forceShow = false) {
    global $verbose;
    if ($verbose || $forceShow) {
        echo $msg . "\n";
    }
}

function log_error($msg) {
    echo "\033[31mERROR: $msg\033[0m\n";
}

function log_success($msg) {
    echo "\033[32m$msg\033[0m\n";
}

function toBool($value) {
    // Handle empty strings, null, and various falsy values
    if ($value === '' || $value === null || $value === 'false' || $value === '0') {
        return 0;
    }
    return $value ? 1 : 0;
}

// Check paths
if (!file_exists($backendPath . '/WebService/config.php')) {
    log_error("Config file not found. Copy config-example.php to config.php and add database credentials.");
    exit(1);
}

if (!file_exists($metadataPath)) {
    log_error("Metadata path not found: $metadataPath");
    exit(1);
}

// Load config and connect to database
require_once $backendPath . '/includes/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    log_success("Connected to database successfully.");
} catch (Exception $e) {
    log_error("Database connection failed: " . $e->getMessage());
    exit(1);
}

if ($dryRun) {
    log_msg("DRY RUN MODE - No changes will be made", true);
}

// ============ Migration Functions ============

function loadJsonFile($path) {
    if (!file_exists($path)) {
        return null;
    }
    $content = file_get_contents($path);
    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        log_error("Invalid JSON in $path: " . json_last_error_msg());
        return null;
    }
    return $data;
}

function migrateCategories($db, $dryRun) {
    log_msg("Checking categories...", true);

    // Categories are seeded in schema.sql, just verify they exist
    $stmt = $db->query("SELECT COUNT(*) FROM categories");
    $count = $stmt->fetchColumn();

    if ($count == 0) {
        log_error("Categories table is empty. Run schema.sql first.");
        return false;
    }

    log_success("Found $count categories in database.");
    return true;
}

function migrateApps($db, $backendPath, $dryRun) {
    log_msg("\n=== Migrating App Catalog ===", true);

    // Build status map - later files override earlier ones
    $statusMap = [];
    $allApps = [];

    // 1. Load masterAppData.json (all historical - default to 'archived')
    $masterFile = $backendPath . '/masterAppData.json';
    $masterApps = loadJsonFile($masterFile);
    if ($masterApps) {
        foreach ($masterApps as $app) {
            $statusMap[$app['id']] = 'archived';
            $allApps[$app['id']] = $app;
        }
        log_msg("Loaded " . count($masterApps) . " apps from masterAppData.json");
    }

    // 2. Load missingAppData.json (override to 'missing')
    $missingFile = $backendPath . '/missingAppData.json';
    $missingApps = loadJsonFile($missingFile);
    if ($missingApps) {
        foreach ($missingApps as $app) {
            $statusMap[$app['id']] = 'missing';
            if (!isset($allApps[$app['id']])) {
                $allApps[$app['id']] = $app;
            }
        }
        log_msg("Loaded " . count($missingApps) . " apps from missingAppData.json");
    }

    // 3. Load archivedAppData.json (override to 'active' - main catalog)
    $archivedFile = $backendPath . '/archivedAppData.json';
    $archivedApps = loadJsonFile($archivedFile);
    if ($archivedApps) {
        foreach ($archivedApps as $app) {
            $statusMap[$app['id']] = 'active';
            // Archived apps have most complete data, so update
            $allApps[$app['id']] = $app;
        }
        log_msg("Loaded " . count($archivedApps) . " apps from archivedAppData.json");
    }

    // 4. Load newerAppData.json (override to 'newer')
    $newerFile = $backendPath . '/newerAppData.json';
    $newerApps = loadJsonFile($newerFile);
    if ($newerApps && !empty($newerApps)) {
        foreach ($newerApps as $app) {
            $statusMap[$app['id']] = 'newer';
            $allApps[$app['id']] = $app;
        }
        log_msg("Loaded " . count($newerApps) . " apps from newerAppData.json");
    }

    log_msg("Total unique apps to migrate: " . count($allApps), true);

    if ($dryRun) {
        log_msg("Would insert " . count($allApps) . " apps");
        return true;
    }

    // Get category ID map
    $stmt = $db->query("SELECT id, name FROM categories");
    $categoryMap = [];
    while ($row = $stmt->fetch()) {
        $categoryMap[$row['name']] = $row['id'];
    }

    // Prepare insert statement
    $sql = "
        INSERT INTO apps (
            id, title, author, summary, app_icon, app_icon_big,
            category_id, vendor_id, pixi, pre, pre2, pre3, veer,
            touchpad, touchpad_exclusive, luneos, adult, status
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            author = VALUES(author),
            summary = VALUES(summary),
            app_icon = VALUES(app_icon),
            app_icon_big = VALUES(app_icon_big),
            category_id = VALUES(category_id),
            vendor_id = VALUES(vendor_id),
            pixi = VALUES(pixi),
            pre = VALUES(pre),
            pre2 = VALUES(pre2),
            pre3 = VALUES(pre3),
            veer = VALUES(veer),
            touchpad = VALUES(touchpad),
            touchpad_exclusive = VALUES(touchpad_exclusive),
            luneos = VALUES(luneos),
            adult = VALUES(adult),
            status = VALUES(status)
    ";

    $stmt = $db->prepare($sql);

    $db->beginTransaction();
    $count = 0;
    $errors = 0;

    try {
        foreach ($allApps as $app) {
            $status = $statusMap[$app['id']] ?? 'archived';
            $categoryId = isset($app['category']) ? ($categoryMap[$app['category']] ?? null) : null;

            try {
                $stmt->execute([
                    $app['id'],
                    $app['title'] ?? '',
                    $app['author'] ?? '',
                    $app['summary'] ?? '',
                    $app['appIcon'] ?? '',
                    $app['appIconBig'] ?? '',
                    $categoryId,
                    $app['vendorId'] ?? null,
                    toBool($app['Pixi'] ?? false),
                    toBool($app['Pre'] ?? false),
                    toBool($app['Pre2'] ?? false),
                    toBool($app['Pre3'] ?? false),
                    toBool($app['Veer'] ?? false),
                    toBool($app['TouchPad'] ?? false),
                    toBool($app['touchpad_exclusive'] ?? false),
                    toBool($app['LuneOS'] ?? false),
                    toBool($app['Adult'] ?? false),
                    $status
                ]);
                $count++;

                if ($count % 500 == 0) {
                    log_msg("  Processed $count apps...");
                }
            } catch (PDOException $e) {
                log_error("Failed to insert app {$app['id']}: " . $e->getMessage());
                $errors++;
            }
        }

        $db->commit();
        log_success("Migrated $count apps successfully ($errors errors).");
        return true;

    } catch (Exception $e) {
        $db->rollBack();
        log_error("Migration failed: " . $e->getMessage());
        return false;
    }
}

function migrateMetadata($db, $metadataPath, $dryRun) {
    log_msg("\n=== Migrating App Metadata ===", true);

    $files = glob($metadataPath . '/*.json');
    $totalFiles = count($files);
    log_msg("Found $totalFiles metadata files", true);

    if ($dryRun) {
        log_msg("Would process $totalFiles metadata files");
        return true;
    }

    // Prepare statements
    $metaStmt = $db->prepare("
        INSERT INTO app_metadata (
            app_id, public_application_id, description, version, version_note,
            home_url, support_url, cust_support_email, cust_support_phone,
            copyright, license_url, locale, app_size, install_size,
            is_encrypted, adult_rating, is_location_based, last_modified_time,
            media_link, media_icon, price, currency, free, is_advertized,
            filename, original_filename, star_rating, attributes
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            public_application_id = VALUES(public_application_id),
            description = VALUES(description),
            version = VALUES(version),
            version_note = VALUES(version_note),
            home_url = VALUES(home_url),
            support_url = VALUES(support_url),
            filename = VALUES(filename),
            star_rating = VALUES(star_rating)
    ");

    $imgStmt = $db->prepare("
        INSERT INTO app_images (app_id, image_order, screenshot_path, thumbnail_path, orientation, device)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $imgDelStmt = $db->prepare("DELETE FROM app_images WHERE app_id = ?");

    // Get list of valid app IDs
    $stmt = $db->query("SELECT id FROM apps");
    $validAppIds = [];
    while ($row = $stmt->fetch()) {
        $validAppIds[$row['id']] = true;
    }

    $db->beginTransaction();
    $count = 0;
    $skipped = 0;
    $errors = 0;

    try {
        foreach ($files as $file) {
            $appId = basename($file, '.json');
            if (!is_numeric($appId)) {
                continue;
            }

            // Skip if app doesn't exist in apps table
            if (!isset($validAppIds[(int)$appId])) {
                $skipped++;
                continue;
            }

            $metadata = loadJsonFile($file);
            if (!$metadata) {
                $errors++;
                continue;
            }

            try {
                // Parse last modified time
                $lastModified = null;
                if (!empty($metadata['lastModifiedTime'])) {
                    $lastModified = date('Y-m-d H:i:s', strtotime($metadata['lastModifiedTime']));
                }

                // Insert metadata
                $metaStmt->execute([
                    $appId,
                    $metadata['publicApplicationId'] ?? null,
                    $metadata['description'] ?? null,
                    $metadata['version'] ?? null,
                    $metadata['versionNote'] ?? null,
                    $metadata['homeURL'] ?? null,
                    $metadata['supportURL'] ?? null,
                    $metadata['custsupportemail'] ?? null,
                    $metadata['custsupportphonenum'] ?? null,
                    $metadata['copyright'] ?? null,
                    $metadata['licenseURL'] ?? null,
                    $metadata['locale'] ?? 'en_US',
                    $metadata['appSize'] ?? null,
                    $metadata['installSize'] ?? null,
                    toBool($metadata['isEncrypted'] ?? false),
                    toBool($metadata['adultRating'] ?? false),
                    toBool($metadata['islocationbased'] ?? false),
                    $lastModified,
                    $metadata['mediaLink'] ?? null,
                    $metadata['mediaIcon'] ?? null,
                    $metadata['price'] ?? 0,
                    $metadata['currency'] ?? 'USD',
                    toBool($metadata['free'] ?? true),
                    toBool($metadata['isAdvertized'] ?? false),
                    $metadata['filename'] ?? null,
                    $metadata['originalFileName'] ?? null,
                    $metadata['starRating'] ?? null,
                    isset($metadata['attributes']) ? json_encode($metadata['attributes']) : null
                ]);

                // Handle images
                if (isset($metadata['images']) && !empty($metadata['images'])) {
                    $imgDelStmt->execute([$appId]);

                    foreach ($metadata['images'] as $order => $image) {
                        $imgStmt->execute([
                            $appId,
                            (int)$order,
                            $image['screenshot'] ?? null,
                            $image['thumbnail'] ?? null,
                            $image['orientation'] ?? null,
                            $image['device'] ?? null
                        ]);
                    }
                }

                $count++;

                if ($count % 500 == 0) {
                    log_msg("  Processed $count metadata files...");
                }
            } catch (PDOException $e) {
                log_error("Failed to insert metadata for app $appId: " . $e->getMessage());
                $errors++;
            }
        }

        $db->commit();
        log_success("Migrated $count metadata records ($skipped skipped, $errors errors).");
        return true;

    } catch (Exception $e) {
        $db->rollBack();
        log_error("Metadata migration failed: " . $e->getMessage());
        return false;
    }
}

function migrateAuthors($db, $backendPath, $dryRun) {
    log_msg("\n=== Migrating Author Metadata ===", true);

    $authorDirs = glob($backendPath . '/AuthorMetadata/*', GLOB_ONLYDIR);
    $totalAuthors = count($authorDirs);
    log_msg("Found $totalAuthors author directories", true);

    if ($dryRun) {
        log_msg("Would process $totalAuthors authors");
        return true;
    }

    $stmt = $db->prepare("
        INSERT INTO authors (vendor_id, author_name, summary, favicon, icon, icon_big, sponsor_message, sponsor_link, social_links)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            author_name = VALUES(author_name),
            summary = VALUES(summary),
            favicon = VALUES(favicon),
            icon = VALUES(icon),
            icon_big = VALUES(icon_big),
            sponsor_message = VALUES(sponsor_message),
            sponsor_link = VALUES(sponsor_link),
            social_links = VALUES(social_links)
    ");

    $count = 0;
    $errors = 0;

    foreach ($authorDirs as $dir) {
        $vendorId = basename($dir);
        $authorFile = $dir . '/author.json';

        if (!file_exists($authorFile)) {
            continue;
        }

        $author = loadJsonFile($authorFile);
        if (!$author) {
            $errors++;
            continue;
        }

        try {
            $stmt->execute([
                $vendorId,
                $author['author'] ?? '',
                $author['summary'] ?? null,
                $author['favicon'] ?? null,
                $author['icon'] ?? null,
                $author['iconBig'] ?? null,
                $author['sponsorMessage'] ?? null,
                $author['sponsorLink'] ?? null,
                isset($author['socialLinks']) ? json_encode($author['socialLinks']) : null
            ]);
            $count++;
        } catch (PDOException $e) {
            log_error("Failed to insert author $vendorId: " . $e->getMessage());
            $errors++;
        }
    }

    log_success("Migrated $count authors ($errors errors).");
    return true;
}

// ============ Run Migration ============

log_msg("\n========================================", true);
log_msg("   webOS Catalog JSON to MySQL Migration", true);
log_msg("========================================\n", true);

$success = true;

// 1. Verify categories
if (!migrateCategories($db, $dryRun)) {
    exit(1);
}

// 2. Migrate apps
if (!$skipApps) {
    if (!migrateApps($db, $backendPath, $dryRun)) {
        $success = false;
    }
} else {
    log_msg("Skipping app migration", true);
}

// 3. Migrate metadata
if (!$skipMetadata) {
    if (!migrateMetadata($db, $metadataPath, $dryRun)) {
        $success = false;
    }
} else {
    log_msg("Skipping metadata migration", true);
}

// 4. Migrate authors
if (!$skipAuthors) {
    if (!migrateAuthors($db, $backendPath, $dryRun)) {
        $success = false;
    }
} else {
    log_msg("Skipping author migration", true);
}

// Final summary
log_msg("\n========================================", true);
if ($success) {
    log_success("Migration completed successfully!");
} else {
    log_error("Migration completed with errors.");
}

// Show final counts
$stmt = $db->query("SELECT COUNT(*) FROM apps");
log_msg("Total apps in database: " . $stmt->fetchColumn(), true);

$stmt = $db->query("SELECT COUNT(*) FROM app_metadata");
log_msg("Total metadata records: " . $stmt->fetchColumn(), true);

$stmt = $db->query("SELECT COUNT(*) FROM app_images");
log_msg("Total image records: " . $stmt->fetchColumn(), true);

$stmt = $db->query("SELECT COUNT(*) FROM authors");
log_msg("Total authors: " . $stmt->fetchColumn(), true);

$stmt = $db->query("SELECT status, COUNT(*) as count FROM apps GROUP BY status");
log_msg("\nApps by status:", true);
while ($row = $stmt->fetch()) {
    log_msg("  {$row['status']}: {$row['count']}", true);
}

exit($success ? 0 : 1);
