<?php
/**
 * export-archive.php - write a dated, public bundle of the catalog's app
 * records, for preservation (e.g. an archive.org upload).
 *
 * Usage:
 *   php scripts/export-archive.php <output-dir> [--no-sqlite]
 *
 * Writes <output-dir>/webos-app-museum-catalog-YYYY-MM-DD.tar.gz (UTC date; a
 * second run on the same day replaces it), holding apps/<id>.json, catalog.csv,
 * catalog.sqlite, README.md and manifest.json. The README explains the fields,
 * so update it here when the export changes.
 *
 * Safe for cron: nothing is printed but one summary line, warnings go to
 * stderr, a failure exits 1 and leaves no partial bundle, and the database is
 * only read. The account running it must be able to read WebService/config.php.
 *
 * Keeping user data out rests on $EXPORT below: only the tables and columns
 * named there can leave the database. A new column stays out, with a warning,
 * until it is added to $EXPORT or $EXCLUDED_COLUMNS. A new table stays out
 * silently, so a table that holds app records must be added here by hand.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../includes/Database.php';

// The whole catalog is held in memory while it is read and checked.
ini_set('memory_limit', '-1');

const BUNDLE_PREFIX = 'webos-app-museum-catalog';
const BUNDLE_FORMAT = 1;

/** table => [sort columns, exported columns] */
$EXPORT = [
    'apps' => [
        'order' => ['id'],
        'columns' => [
            'id', 'title', 'author', 'summary', 'app_icon', 'app_icon_big', 'category_id', 'vendor_id',
            'pixi', 'pre', 'pre2', 'pre3', 'veer', 'touchpad', 'touchpad_exclusive', 'luneos', 'adult',
            'recommendation_order', 'in_revisionist_history', 'in_curators_choice', 'post_shutdown',
            'status', 'review_count', 'created_at',
        ],
    ],
    'app_metadata' => [
        'order' => ['app_id'],
        'columns' => [
            'app_id', 'public_application_id', 'description', 'version', 'version_note', 'home_url',
            'support_url', 'cust_support_email', 'cust_support_phone', 'copyright', 'license_url', 'locale',
            'app_size', 'install_size', 'is_encrypted', 'adult_rating', 'web_suppressed', 'is_location_based',
            'dock_mode', 'universal_search', 'connectors', 'last_modified_time', 'last_historical_update',
            'media_link', 'media_icon', 'price', 'currency', 'free', 'is_advertized', 'filename',
            'original_filename', 'star_rating', 'attributes',
        ],
    ],
    'app_images' => [
        'order' => ['app_id', 'image_order', 'id'],
        'columns' => ['id', 'app_id', 'image_order', 'screenshot_path', 'thumbnail_path', 'orientation', 'device'],
    ],
    'app_relationships' => [
        'order' => ['app_id', 'related_app_id'],
        'columns' => ['app_id', 'related_app_id'],
    ],
    'categories' => [
        'order' => ['id'],
        'columns' => ['id', 'name', 'display_order'],
    ],
    'authors' => [
        'order' => ['vendor_id'],
        'columns' => [
            'vendor_id', 'author_name', 'summary', 'favicon', 'icon', 'icon_big',
            'sponsor_message', 'sponsor_link', 'social_links',
        ],
    ],
];

/** Columns left out on purpose, so they don't raise the unlisted-column warning. */
$EXCLUDED_COLUMNS = [
    'apps' => ['owner_account_id', 'updated_at'],      // an account link; a database write time
    'app_metadata' => ['created_at', 'updated_at'],
    'app_relationships' => ['id', 'created_at'],
    'categories' => ['created_at'],
    'authors' => ['created_at', 'updated_at'],
];

/** Text a developer wrote for the public, which may rightly hold their own address. */
$PUBLISHED_TEXT = [
    'apps' => ['summary'],
    'app_metadata' => [
        'description', 'version_note', 'home_url', 'support_url', 'cust_support_email', 'copyright', 'license_url',
    ],
    'authors' => ['summary', 'sponsor_message', 'sponsor_link', 'social_links'],
];

function warn($msg) {
    fwrite(STDERR, "warning: $msg\n");
}

function fail($msg) {
    fwrite(STDERR, "export-archive: $msg\n");
    exit(1);
}

function removeTree($path) {
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            removeTree("$path/$entry");
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

function writeFile($path, $contents) {
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("could not write $path");
    }
}

/** column => COLUMN_TYPE for one table, in database order. */
function columnTypes(PDO $db, $table) {
    $stmt = $db->prepare("
        SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION
    ");
    $stmt->execute([$table]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Give a database value its JSON type. Decimals stay strings, so 0.99 can't
 * pick up float noise. Values in JSON columns are decoded by the caller.
 */
function typedValue($value, $columnType) {
    if ($value === null) {
        return null;
    }
    if ($columnType === 'tinyint(1)') {
        return (bool)$value;
    }
    if (preg_match('/^(tiny|small|medium|big)?int\b/', $columnType)) {
        return (int)$value;
    }
    return (string)$value;
}

function loadTable(PDO $db, $table, array $spec, array $types) {
    $quote = function ($c) { return "`$c`"; };
    $sql = sprintf(
        'SELECT %s FROM `%s` ORDER BY %s',
        implode(', ', array_map($quote, $spec['columns'])),
        $table,
        implode(', ', array_map($quote, $spec['order']))
    );
    $rows = [];
    foreach ($db->query($sql) as $row) {
        foreach ($row as $column => $value) {
            $row[$column] = typedValue($value, $types[$column]);
        }
        $rows[] = $row;
    }
    return $rows;
}

/** A JSON text column as decoded data, or the raw text if it won't decode. */
function decodedJson($text) {
    if ($text === null || $text === '') {
        return null;
    }
    $data = json_decode($text, true);
    return json_last_error() === JSON_ERROR_NONE ? $data : $text;
}

function encodeJson($data) {
    return json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";
}

/**
 * Compare every exported value with the addresses of our accounts. Most
 * accounts belong to people who never published anything, so a hit outside
 * public developer text stops the export. A hit inside that text is reported
 * by app and field, never by address, because a developer's account may well
 * share the address on their own app page.
 *
 * @return array [problems that stop the export, hits in published text]
 */
function findAccountEmails(array $emails, array $data, array $publishedText) {
    $known = [];
    foreach ($emails as $email) {
        $known[strtolower(trim((string)$email))] = true;
    }
    unset($known['']);

    $problems = [];
    $published = [];
    foreach ($data as $table => $rows) {
        foreach ($rows as $row) {
            $id = $row['id'] ?? $row['app_id'] ?? $row['vendor_id'] ?? '?';
            foreach ($row as $column => $value) {
                if (!is_string($value) || strpos($value, '@') === false) {
                    continue;
                }
                preg_match_all('/[^\s<>()\[\]"\',;:]+@[^\s<>()\[\]"\',;:]+/', $value, $matches);
                foreach ($matches[0] as $candidate) {
                    if (!isset($known[strtolower(rtrim($candidate, '.'))])) {
                        continue;
                    }
                    if (in_array($column, $publishedText[$table] ?? [], true)) {
                        $published[] = "$table.$column ($id)";
                    } else {
                        $problems[] = "$table.$column ($id)";
                    }
                }
            }
        }
    }
    return [$problems, $published];
}

/** Yields one record per app, with its images, related apps and vendor nested in it. */
function buildRecords(array $data) {
    $metadata = array_column($data['app_metadata'], null, 'app_id');
    $categories = array_column($data['categories'], 'name', 'id');
    $vendors = array_column($data['authors'], null, 'vendor_id');

    $images = [];
    foreach ($data['app_images'] as $image) {
        $appId = $image['app_id'];
        unset($image['id'], $image['app_id']);
        $images[$appId][] = $image;
    }

    // Links are bidirectional but may be stored in only one direction.
    $related = [];
    foreach ($data['app_relationships'] as $link) {
        $related[$link['app_id']][$link['related_app_id']] = true;
        $related[$link['related_app_id']][$link['app_id']] = true;
    }

    foreach ($data['apps'] as $app) {
        $id = $app['id'];
        $meta = $metadata[$id] ?? null;
        if ($meta !== null) {
            unset($meta['app_id']);
            $meta['attributes'] = decodedJson($meta['attributes']);
        }
        $vendor = $vendors[$app['vendor_id'] ?? ''] ?? null;
        if ($vendor !== null) {
            $vendor['social_links'] = decodedJson($vendor['social_links']);
        }
        $relatedIds = array_keys($related[$id] ?? []);
        sort($relatedIds);

        $record = $app;
        $record['category'] = $categories[$app['category_id'] ?? 0] ?? null;
        $record['vendor'] = $vendor;
        $record['metadata'] = $meta;
        $record['images'] = $images[$id] ?? [];
        $record['related_app_ids'] = $relatedIds;
        yield $id => $record;
    }
}

function writeCsv($path, array $data, array $export) {
    $metadata = array_column($data['app_metadata'], null, 'app_id');
    $categories = array_column($data['categories'], 'name', 'id');

    $metaColumns = array_values(array_diff($export['app_metadata']['columns'], ['app_id']));
    $out = fopen($path, 'w');
    if ($out === false) {
        throw new RuntimeException("could not write $path");
    }
    $header = array_merge($export['apps']['columns'], ['category'], $metaColumns);
    fputcsv($out, $header, ',', '"', '');

    foreach ($data['apps'] as $app) {
        $meta = $metadata[$app['id']] ?? [];
        $line = [];
        foreach ($export['apps']['columns'] as $column) {
            $line[] = $app[$column];
        }
        $line[] = $categories[$app['category_id'] ?? 0] ?? '';
        foreach ($metaColumns as $column) {
            $line[] = $meta[$column] ?? '';
        }
        $line = array_map(function ($v) { return is_bool($v) ? (int)$v : $v; }, $line);
        fputcsv($out, $line, ',', '"', '');
    }
    fclose($out);
}

function sqliteType($columnType) {
    if (preg_match('/^(tiny|small|medium|big)?int\b/', $columnType)) {
        return 'INTEGER';
    }
    // Decimals are NUMERIC so a query can sum prices; everything else is text.
    return strpos($columnType, 'decimal') === 0 ? 'NUMERIC' : 'TEXT';
}

function writeSqlite($path, array $data, array $export, array $types) {
    $sqlite = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sqlite->exec('PRAGMA journal_mode = OFF');
    $sqlite->exec('PRAGMA synchronous = OFF');
    $sqlite->beginTransaction();

    foreach ($export as $table => $spec) {
        $defs = [];
        foreach ($spec['columns'] as $column) {
            $defs[] = sprintf('"%s" %s', $column, sqliteType($types[$table][$column]));
        }
        $defs[] = sprintf('PRIMARY KEY ("%s")', implode('", "', $spec['order']));
        $sqlite->exec(sprintf('CREATE TABLE "%s" (%s)', $table, implode(', ', $defs)));

        $placeholders = implode(', ', array_fill(0, count($spec['columns']), '?'));
        $insert = $sqlite->prepare(sprintf('INSERT INTO "%s" VALUES (%s)', $table, $placeholders));
        foreach ($data[$table] as $row) {
            $values = [];
            foreach ($spec['columns'] as $column) {
                $value = $row[$column];
                $values[] = is_bool($value) ? (int)$value : $value;
            }
            $insert->execute($values);
        }
    }

    $sqlite->exec('CREATE INDEX idx_apps_title ON apps (title)');
    $sqlite->exec('CREATE INDEX idx_apps_vendor ON apps (vendor_id)');
    $sqlite->exec('CREATE INDEX idx_metadata_public_id ON app_metadata (public_application_id)');
    $sqlite->exec('CREATE INDEX idx_images_app ON app_images (app_id)');
    $sqlite->commit();
    $sqlite = null;
}

/** Confirm the finished SQLite file holds exactly the tables and columns in $EXPORT. */
function checkSqliteShape($path, array $export) {
    $sqlite = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = $sqlite->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    if (array_diff($tables, array_keys($export)) || array_diff(array_keys($export), $tables)) {
        throw new RuntimeException('catalog.sqlite does not hold exactly the exported tables');
    }
    foreach ($export as $table => $spec) {
        $columns = $sqlite->query(sprintf('PRAGMA table_info("%s")', $table))->fetchAll(PDO::FETCH_COLUMN, 1);
        if ($columns !== $spec['columns']) {
            throw new RuntimeException("catalog.sqlite table $table has unexpected columns");
        }
    }
    $sqlite = null;
}

function readmeText($date, array $counts, $hasSqlite) {
    $sqliteRow = $hasSqlite
        ? "| `catalog.sqlite` | The same six tables as a database that needs no server. Open it with `sqlite3` or any SQLite browser |\n"
        : '';
    $sqliteTableNote = $hasSqlite ? ' These are the tables in `catalog.sqlite`.' : '';

    return <<<README
# webOS App Museum catalog, exported $date

The App Museum II (https://appcatalog.webosarchive.org) is a community archive
of applications for Palm and HP webOS devices. It grew out of the HP App
Catalog, which closed in January 2015, and it also lists apps made by the
community since then.

This bundle is a record of the catalog: what apps existed, who made them, what
they did and what each one is called. It holds $counts[apps] apps.

It does **not** hold the app packages (IPKs), the icons and screenshots, or the
archived HP user reviews, which are preserved separately. It holds no data
about users of the Museum: no accounts, app storage, download logs or reviews.

## Files

| File | What it is |
|---|---|
| `apps/<id>.json` | One file per app, with its metadata, vendor, category, image paths and related apps nested inside |
| `catalog.csv` | One row per app: the `apps` and `app_metadata` columns plus the category name. UTF-8, and `attributes` is JSON text |
$sqliteRow| `manifest.json` | Export date, row counts and SHA-256 checksums |

## Tables

Field names match across all the formats.$sqliteTableNote

| Table | Rows | Key | Holds |
|---|---|---|---|
| `apps` | $counts[apps] | `id`, the Museum ID | Title, author, device flags, status |
| `app_metadata` | $counts[app_metadata] | `app_id` | Description, version, support details, dates, price |
| `app_images` | $counts[app_images] | `app_id` + `image_order` | Paths of screenshots and thumbnails |
| `app_relationships` | $counts[app_relationships] | `app_id` + `related_app_id` | Curated links between apps. A link counts in both directions, however it is stored; the JSON files list both |
| `categories` | $counts[categories] | `id` | Category names |
| `authors` | $counts[authors] | `vendor_id` | Vendors and their public profiles |

Paths such as `app_icon`, `screenshot_path` and `filename` are relative to the
image and package archives. They are not full URLs.

## Reading the dates

There are two dates per app, and they mean different things.

- **`last_historical_update`** is the history. For an app from the HP or Palm
  catalog, it is when the developer last submitted that app to HP or Palm. For
  an app first added by the community, it is the earliest date we have for it.
- **`last_modified_time`** is the date of the app's latest change, including
  changes the Museum's own curators made. It is **not** a historical date for
  any app that has been touched since. Use it only to see what changed lately.

Some care is needed with both:

- **Empty means unknown.** No date was recoverable, or none ever existed. Don't
  read it as "never updated".
- **Treat them as dates, not times.** They are stored as UTC, but the sources
  didn't reliably carry a timezone, so a date may be a day out.
- **Times ending in `:04:48`** were made up by curators. In
  `last_modified_time` they mark an edit, not a real event.
- **Some community apps' dates may be late.** Where the older records had
  already stopped, the earliest date we have can be later than the real first
  release.

`apps.created_at` is when the row was added to this database, in UTC. For the
original catalog that is simply the date of the import, so it isn't history.

## Reading the flags

- **`status`:** `active` is in the main catalog with a package available,
  `missing` is known but its package is still wanted, `archived` is kept for
  reference only, and `newer` was a submission after the catalog's freeze with
  a package available. Apps of every status are included.
- **`post_shutdown`:** made by the community after the platform ended.
- **`pixi`, `pre`, `pre2`, `pre3`, `veer`, `touchpad`, `luneos`:** the devices
  an app runs on.
- **`adult`, `adult_rating`:** adult content, which the Museum hides by default.
- **`web_suppressed`:** hidden from listings on the Museum's website, though
  still served to devices.
- **`in_revisionist_history`, `in_curators_choice`, `recommendation_order`:**
  the Museum's own curation.
- **`star_rating`, `review_count`:** totals from the original catalog.
- **`dock_mode`, `universal_search`, `connectors`:** features curated by the
  Museum. They take priority over the same facts in `attributes`.
- **`cust_support_email`, `cust_support_phone`, `support_url`:** the support
  details published with the app, kept as history. Many of them are long out of
  date, and some have been changed to the Museum's own contacts for apps it now
  maintains.

## Rights

The apps' names, descriptions and other text belong to their developers. They
are shared here to preserve the history of the platform.


README;
}

// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
$withSqlite = !in_array('--no-sqlite', $args, true);
$positional = array_values(array_filter($args, function ($a) { return strpos($a, '--') !== 0; }));
$unknown = array_diff(array_filter($args, function ($a) { return strpos($a, '--') === 0; }), ['--no-sqlite']);

if (count($positional) !== 1 || $unknown) {
    fail('usage: php scripts/export-archive.php <output-dir> [--no-sqlite]');
}
$outputDir = realpath($positional[0]);
if ($outputDir === false || !is_dir($outputDir) || !is_writable($outputDir)) {
    fail("output directory does not exist or isn't writable: {$positional[0]}");
}
$siteRoot = realpath(__DIR__ . '/..');
if ($outputDir === $siteRoot || strpos($outputDir . '/', $siteRoot . '/') === 0) {
    fail('the output directory must be outside the site, so the bundle is not web-accessible');
}
if ($withSqlite && !extension_loaded('pdo_sqlite')) {
    fail('PHP has no pdo_sqlite. Install it (e.g. the php-sqlite3 package), or pass --no-sqlite to leave catalog.sqlite out');
}
foreach (['phar', 'zlib'] as $extension) {
    if (!extension_loaded($extension)) {
        fail("PHP has no $extension extension, which is needed to build the bundle");
    }
}

$date = gmdate('Y-m-d');
$bundleName = BUNDLE_PREFIX . '-' . $date;
$workDir = $outputDir . '/.' . $bundleName . '.' . bin2hex(random_bytes(4));

// Runs after fail() and after an uncaught error too, so no partial bundle stays.
register_shutdown_function(function () use ($workDir) {
    removeTree($workDir);
});

try {
    $db = Database::getInstance()->getConnection();
    $db->exec("SET time_zone = '+00:00'");
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    // One snapshot, so an edit made mid-export can't leave the bundle half updated.
    $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

    $types = [];
    $data = [];
    foreach ($EXPORT as $table => $spec) {
        $types[$table] = columnTypes($db, $table);
        $missing = array_diff($spec['columns'], array_keys($types[$table]));
        if ($missing) {
            throw new RuntimeException("$table has lost exported columns: " . implode(', ', $missing));
        }
        $unlisted = array_diff(array_keys($types[$table]), $spec['columns'], $EXCLUDED_COLUMNS[$table] ?? []);
        foreach ($unlisted as $column) {
            warn("$table.$column is not exported. Add it to \$EXPORT or \$EXCLUDED_COLUMNS in this script");
        }
        $data[$table] = loadTable($db, $table, $spec, $types[$table]);
    }
    $accountEmails = $db->query('SELECT email FROM accounts WHERE email IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    $db->exec('COMMIT');

    if (!$data['apps']) {
        throw new RuntimeException('the apps table is empty, so there is nothing to export');
    }

    [$problems, $published] = findAccountEmails($accountEmails, $data, $PUBLISHED_TEXT);
    if ($problems) {
        throw new RuntimeException(
            'an account email appears outside published developer text, so nothing was exported: '
            . implode(', ', array_unique($problems))
        );
    }
    if ($published) {
        warn('an account email is in public developer text, which is exported: '
            . implode(', ', array_unique($published)));
    }

    $bundleDir = "$workDir/$bundleName";
    if (!mkdir("$bundleDir/apps", 0755, true)) {
        throw new RuntimeException("could not create $bundleDir");
    }

    $appsHash = hash_init('sha256');
    foreach (buildRecords($data) as $id => $record) {
        $json = encodeJson($record);
        writeFile("$bundleDir/apps/$id.json", $json);
        hash_update($appsHash, "$id\n$json");
    }

    writeCsv("$bundleDir/catalog.csv", $data, $EXPORT);
    if ($withSqlite) {
        writeSqlite("$bundleDir/catalog.sqlite", $data, $EXPORT, $types);
        checkSqliteShape("$bundleDir/catalog.sqlite", $EXPORT);
    }

    $counts = array_map('count', $data);
    writeFile("$bundleDir/README.md", readmeText($date, $counts, $withSqlite));

    $checksums = [];
    foreach (['README.md', 'catalog.csv', 'catalog.sqlite'] as $file) {
        if (file_exists("$bundleDir/$file")) {
            $checksums[$file] = hash_file('sha256', "$bundleDir/$file");
        }
    }
    writeFile("$bundleDir/manifest.json", encodeJson([
        'name' => $bundleName,
        'format' => BUNDLE_FORMAT,
        'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'row_counts' => $counts,
        'sha256' => $checksums,
        // Over "<id>\n<contents>" of each app file in ID order. It changes only
        // when an app record changes, so it shows whether a release is needed.
        'apps_sha256' => hash_final($appsHash),
    ]));

    $tarPath = "$workDir/$bundleName.tar";
    $tar = new PharData($tarPath);
    $tar->buildFromDirectory($workDir, '/^' . preg_quote($bundleDir, '/') . '\//');
    $tar->compress(Phar::GZ);
    unset($tar);

    $finalPath = "$outputDir/$bundleName.tar.gz";
    if (!chmod("$tarPath.gz", 0644) || !rename("$tarPath.gz", $finalPath)) {
        throw new RuntimeException("could not move the bundle to $finalPath");
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}

printf("%s: %d apps, %.1f MB\n", $finalPath, $counts['apps'], filesize($finalPath) / 1048576);
