<?php
/**
 * One-time script to backfill last_modified_time from archived JSON files
 *
 * Reads from: https://github.com/webOSArchive/webos-catalog-metadata/tree/archive
 *
 * DELETE THIS FILE AFTER USE
 */

// Allow running from CLI or browser
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain');
}

require_once __DIR__ . '/../includes/Database.php';

$db = Database::getInstance()->getConnection();

// Get all apps missing last_modified_time
$sql = "
    SELECT a.id, a.title
    FROM apps a
    LEFT JOIN app_metadata m ON a.id = m.app_id
    WHERE a.status = 'active'
      AND (m.last_modified_time IS NULL OR m.app_id IS NULL)
    ORDER BY a.id
";

$stmt = $db->query($sql);
$apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($apps) . " apps missing last_modified_time\n\n";

$updated = 0;
$skipped = 0;
$errors = 0;

// Prepare update statement
$updateStmt = $db->prepare("
    UPDATE app_metadata
    SET last_modified_time = ?
    WHERE app_id = ?
");

// Prepare insert statement for apps without metadata record
$checkStmt = $db->prepare("SELECT 1 FROM app_metadata WHERE app_id = ?");

foreach ($apps as $app) {
    $id = $app['id'];
    $title = $app['title'];

    // Fetch JSON from GitHub archive
    $url = "https://raw.githubusercontent.com/webOSArchive/webos-catalog-metadata/archive/{$id}.json";

    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'webOS-Catalog-Backfill/1.0'
        ]
    ]);

    $json = @file_get_contents($url, false, $context);

    if ($json === false) {
        echo "SKIP: #{$id} '{$title}' - JSON file not found\n";
        $skipped++;
        continue;
    }

    $data = json_decode($json, true);

    if (!$data || !isset($data['lastModifiedTime'])) {
        echo "SKIP: #{$id} '{$title}' - No lastModifiedTime in JSON\n";
        $skipped++;
        continue;
    }

    $lastModified = $data['lastModifiedTime'];

    // Convert to MySQL datetime format
    $timestamp = strtotime($lastModified);
    if ($timestamp === false) {
        echo "ERROR: #{$id} '{$title}' - Invalid date format: {$lastModified}\n";
        $errors++;
        continue;
    }

    $mysqlDate = date('Y-m-d H:i:s', $timestamp);

    // Check if metadata record exists
    $checkStmt->execute([$id]);
    $hasMetadata = $checkStmt->fetch();

    if (!$hasMetadata) {
        echo "SKIP: #{$id} '{$title}' - No metadata record exists\n";
        $skipped++;
        continue;
    }

    // Update the record
    try {
        $updateStmt->execute([$mysqlDate, $id]);
        echo "OK: #{$id} '{$title}' - Set to {$mysqlDate}\n";
        $updated++;
    } catch (Exception $e) {
        echo "ERROR: #{$id} '{$title}' - " . $e->getMessage() . "\n";
        $errors++;
    }

    // Small delay to be nice to GitHub
    usleep(100000); // 100ms
}

echo "\n";
echo "=== Summary ===\n";
echo "Updated: {$updated}\n";
echo "Skipped: {$skipped}\n";
echo "Errors: {$errors}\n";
echo "\n";
echo "Remember to DELETE this script after use!\n";
