<?php
/**
 * Download Author Icons from GitHub Archive
 *
 * This script downloads icon files from the archived webos-catalog-backend repo
 * and saves them locally. You can then upload them to your image host.
 *
 * Run from command line: php download-author-icons.php
 * Or access via browser (requires admin auth)
 *
 * Files are saved to: ../author-icons-export/{vendorId}/
 */

// Check if running from CLI or web
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    require_once __DIR__ . '/includes/security.php';
}

// Known vendor IDs and their icon files from the old AuthorMetadata folder
$authorIcons = [
    100 => ['author.json', 'favicon.ico', 'icon.jpg', 'iconBig.png'],
    101 => ['author.json', 'favicon.ico', 'icon.jpg', 'iconBig.png'],
    1099 => ['author.json', 'favicon.ico', 'icon.png', 'iconBig.jpg'],
    8609 => ['author.json', 'favicon.ico', 'icon.png', 'iconBig.jpg'],
];

// Base URL for fetching files from GitHub
$githubBaseUrl = 'https://raw.githubusercontent.com/webOSArchive/webos-catalog-backend/refs/heads/archive/AuthorMetadata/';

// Output directory
$outputDir = __DIR__ . '/../author-icons-export';

$results = [
    'success' => [],
    'errors' => []
];

function output($message, $isCli) {
    if ($isCli) {
        echo $message . "\n";
    } else {
        echo htmlspecialchars($message) . "<br>\n";
        flush();
        ob_flush();
    }
}

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><title>Download Author Icons</title>";
    echo "<style>body{font-family:monospace;padding:20px;} .success{color:green;} .error{color:red;}</style>";
    echo "</head><body>";
    echo "<h1>Download Author Icons from GitHub</h1><pre>";
    ob_start();
}

output("Downloading author icons from GitHub archive...", $isCli);
output("Output directory: $outputDir", $isCli);
output("", $isCli);

// Create output directory if it doesn't exist
if (!is_dir($outputDir)) {
    if (!mkdir($outputDir, 0755, true)) {
        output("ERROR: Could not create output directory", $isCli);
        exit(1);
    }
    output("Created output directory", $isCli);
}

foreach ($authorIcons as $vendorId => $files) {
    output("Processing vendor ID $vendorId...", $isCli);

    // Create vendor directory
    $vendorDir = $outputDir . '/' . $vendorId;
    if (!is_dir($vendorDir)) {
        mkdir($vendorDir, 0755, true);
    }

    foreach ($files as $filename) {
        $url = $githubBaseUrl . $vendorId . '/' . $filename;
        $localPath = $vendorDir . '/' . $filename;

        output("  Downloading $filename...", $isCli);

        // Create context with timeout
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'webOS-Catalog-Migration/1.0'
            ]
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            $results['errors'][] = "Failed to download: $url";
            output("    ERROR: Failed to download", $isCli);
            continue;
        }

        if (file_put_contents($localPath, $content) === false) {
            $results['errors'][] = "Failed to save: $localPath";
            output("    ERROR: Failed to save file", $isCli);
            continue;
        }

        $size = strlen($content);
        $results['success'][] = "$vendorId/$filename ($size bytes)";
        output("    OK: Saved $size bytes", $isCli);
    }

    output("", $isCli);
}

// Summary
output("========================================", $isCli);
output("Download Complete!", $isCli);
output("", $isCli);
output("Downloaded: " . count($results['success']) . " files", $isCli);

if (!empty($results['errors'])) {
    output("", $isCli);
    output("Errors: " . count($results['errors']), $isCli);
    foreach ($results['errors'] as $msg) {
        output("  - $msg", $isCli);
    }
}

output("", $isCli);
output("========================================", $isCli);
output("Files saved to: $outputDir", $isCli);
output("", $isCli);
output("Next steps:", $isCli);
output("1. Upload the icon folders to your image host at /authors/", $isCli);
output("2. Run migrate-author-metadata.php to import the JSON data", $isCli);
output("", $isCli);
output("Directory structure to upload:", $isCli);
output("  {image_host}/authors/100/icon.jpg", $isCli);
output("  {image_host}/authors/100/iconBig.png", $isCli);
output("  {image_host}/authors/100/favicon.ico", $isCli);
output("  ... etc for each vendor ID", $isCli);

if (!$isCli) {
    echo "</pre>";
    echo "<h2>Downloaded Files</h2>";
    echo "<p>Files are saved to: <code>" . htmlspecialchars(realpath($outputDir) ?: $outputDir) . "</code></p>";

    // List downloaded files
    if (is_dir($outputDir)) {
        echo "<ul>";
        foreach ($authorIcons as $vendorId => $files) {
            $vendorDir = $outputDir . '/' . $vendorId;
            if (is_dir($vendorDir)) {
                echo "<li><strong>Vendor $vendorId:</strong><ul>";
                foreach (scandir($vendorDir) as $file) {
                    if ($file !== '.' && $file !== '..') {
                        $size = filesize($vendorDir . '/' . $file);
                        echo "<li>$file (" . number_format($size) . " bytes)</li>";
                    }
                }
                echo "</ul></li>";
            }
        }
        echo "</ul>";
    }

    echo "<h2>Next Steps</h2>";
    echo "<ol>";
    echo "<li>Upload the <code>author-icons-export/</code> folder contents to your image host at <code>/authors/</code></li>";
    echo "<li><a href='migrate-author-metadata.php'>Run the metadata migration script</a></li>";
    echo "<li><a href='authors.php'>View Authors Admin</a> to verify the data</li>";
    echo "</ol>";
    echo "<p><a href='authors.php'>Return to Authors Admin</a></p>";
    echo "</body></html>";
}
