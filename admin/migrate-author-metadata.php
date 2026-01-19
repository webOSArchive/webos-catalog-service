<?php
/**
 * Migration Script: Import AuthorMetadata from old GitHub repo
 *
 * This script fetches author metadata JSON files from the archived
 * webos-catalog-backend repo and imports them into the database.
 *
 * Run once from command line: php migrate-author-metadata.php
 * Or access via browser (requires admin auth)
 */

// Check if running from CLI or web
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    require_once __DIR__ . '/includes/security.php';
}

require_once __DIR__ . '/../includes/Database.php';

// Known vendor IDs from the old AuthorMetadata folder
$vendorIds = [100, 101, 1099, 8609];

// Base URL for fetching JSON from GitHub
$githubBaseUrl = 'https://raw.githubusercontent.com/webOSArchive/webos-catalog-backend/refs/heads/archive/AuthorMetadata/';

$db = Database::getInstance()->getConnection();

$results = [
    'success' => [],
    'errors' => [],
    'skipped' => []
];

function output($message, $isCli) {
    if ($isCli) {
        echo $message . "\n";
    } else {
        echo htmlspecialchars($message) . "<br>\n";
        flush();
    }
}

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><title>Author Metadata Migration</title>";
    echo "<style>body{font-family:monospace;padding:20px;} .success{color:green;} .error{color:red;} .skip{color:orange;}</style>";
    echo "</head><body>";
    echo "<h1>Author Metadata Migration</h1><pre>";
}

output("Starting migration of AuthorMetadata from GitHub archive...", $isCli);
output("", $isCli);

foreach ($vendorIds as $vendorId) {
    $jsonUrl = $githubBaseUrl . $vendorId . '/author.json';
    output("Fetching vendor ID $vendorId from $jsonUrl", $isCli);

    // Fetch JSON from GitHub
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'webOS-Catalog-Migration/1.0'
        ]
    ]);

    $json = @file_get_contents($jsonUrl, false, $context);

    if ($json === false) {
        $results['errors'][] = "Failed to fetch JSON for vendor ID $vendorId";
        output("  ERROR: Failed to fetch JSON", $isCli);
        continue;
    }

    $data = json_decode($json, true);
    if ($data === null) {
        $results['errors'][] = "Failed to parse JSON for vendor ID $vendorId";
        output("  ERROR: Failed to parse JSON", $isCli);
        continue;
    }

    output("  Found author: " . ($data['author'] ?? 'unknown'), $isCli);

    // Check if author already exists
    $checkStmt = $db->prepare("SELECT vendor_id FROM authors WHERE vendor_id = ?");
    $checkStmt->execute([$vendorId]);
    $exists = $checkStmt->fetch();

    // Prepare social links as JSON array
    $socialLinks = null;
    if (!empty($data['socialLinks']) && is_array($data['socialLinks'])) {
        $socialLinks = json_encode($data['socialLinks']);
    }

    try {
        if ($exists) {
            // Update existing record
            $stmt = $db->prepare("
                UPDATE authors SET
                    author_name = ?,
                    summary = ?,
                    icon = ?,
                    icon_big = ?,
                    favicon = ?,
                    sponsor_message = ?,
                    sponsor_link = ?,
                    social_links = ?
                WHERE vendor_id = ?
            ");
            $stmt->execute([
                $data['author'] ?? null,
                $data['summary'] ?? null,
                $data['icon'] ?? null,
                $data['iconBig'] ?? null,
                $data['favicon'] ?? null,
                $data['sponsorMessage'] ?? null,
                $data['sponsorLink'] ?? null,
                $socialLinks,
                $vendorId
            ]);
            $results['success'][] = "Updated vendor ID $vendorId ({$data['author']})";
            output("  SUCCESS: Updated existing record", $isCli);
        } else {
            // Insert new record
            $stmt = $db->prepare("
                INSERT INTO authors (vendor_id, author_name, summary, icon, icon_big, favicon, sponsor_message, sponsor_link, social_links)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $vendorId,
                $data['author'] ?? null,
                $data['summary'] ?? null,
                $data['icon'] ?? null,
                $data['iconBig'] ?? null,
                $data['favicon'] ?? null,
                $data['sponsorMessage'] ?? null,
                $data['sponsorLink'] ?? null,
                $socialLinks
            ]);
            $results['success'][] = "Inserted vendor ID $vendorId ({$data['author']})";
            output("  SUCCESS: Inserted new record", $isCli);
        }
    } catch (PDOException $e) {
        $results['errors'][] = "Database error for vendor ID $vendorId: " . $e->getMessage();
        output("  ERROR: " . $e->getMessage(), $isCli);
    }

    output("", $isCli);
}

// Summary
output("========================================", $isCli);
output("Migration Complete!", $isCli);
output("", $isCli);
output("Successful: " . count($results['success']), $isCli);
foreach ($results['success'] as $msg) {
    output("  - $msg", $isCli);
}

if (!empty($results['errors'])) {
    output("", $isCli);
    output("Errors: " . count($results['errors']), $isCli);
    foreach ($results['errors'] as $msg) {
        output("  - $msg", $isCli);
    }
}

output("", $isCli);
output("========================================", $isCli);
output("IMPORTANT: Icon files need to be manually copied!", $isCli);
output("", $isCli);
output("Copy icon files from:", $isCli);
output("  https://github.com/webOSArchive/webos-catalog-backend/tree/archive/AuthorMetadata/{vendorId}/", $isCli);
output("", $isCli);
output("To your image host at:", $isCli);
output("  {image_host}/authors/{vendorId}/", $isCli);
output("", $isCli);
output("Files to copy for each vendor:", $isCli);
output("  - icon.jpg (or icon.png)", $isCli);
output("  - iconBig.png (or iconBig.jpg)", $isCli);
output("  - favicon.ico", $isCli);

if (!$isCli) {
    echo "</pre>";
    echo "<h2>Next Steps</h2>";
    echo "<p>Download and upload icon files from the old repo to your image host:</p>";
    echo "<ul>";
    foreach ($vendorIds as $vendorId) {
        echo "<li><a href='https://github.com/webOSArchive/webos-catalog-backend/tree/archive/AuthorMetadata/$vendorId' target='_blank'>Vendor $vendorId icons</a></li>";
    }
    echo "</ul>";
    echo "<p><a href='authors.php'>Return to Authors Admin</a></p>";
    echo "</body></html>";
}
