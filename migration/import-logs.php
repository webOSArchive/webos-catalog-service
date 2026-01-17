<?php
/**
 * One-time importer for legacy CSV log files into database
 *
 * Usage: php import-logs.php [--dry-run] [--verbose]
 */

require_once __DIR__ . '/../includes/Database.php';

$dryRun = in_array('--dry-run', $argv);
$verbose = in_array('--verbose', $argv);

echo "Log File Importer\n";
echo "==================\n";
if ($dryRun) {
    echo "DRY RUN - No data will be written\n";
}
echo "\n";

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

// Import download logs
$downloadLogPath = __DIR__ . '/../WebService/logs/downloadcount.log';
if (file_exists($downloadLogPath)) {
    echo "Importing download logs from: $downloadLogPath\n";
    $imported = importDownloadLogs($db, $downloadLogPath, $dryRun, $verbose);
    echo "Download logs: $imported records imported\n\n";
} else {
    echo "Download log file not found: $downloadLogPath\n\n";
}

// Import update check logs
$updateLogPath = __DIR__ . '/../WebService/logs/updatecheck.log';
if (file_exists($updateLogPath)) {
    echo "Importing update check logs from: $updateLogPath\n";
    $imported = importUpdateCheckLogs($db, $updateLogPath, $dryRun, $verbose);
    echo "Update check logs: $imported records imported\n\n";
} else {
    echo "Update check log file not found: $updateLogPath\n\n";
}

echo "Done.\n";

function importDownloadLogs($db, $filePath, $dryRun, $verbose) {
    $file = fopen($filePath, 'r');
    if (!$file) {
        echo "  Error: Could not open file\n";
        return 0;
    }

    $sql = "INSERT INTO download_logs (app_id, app_identifier, source, user_agent, created_at) VALUES (?, ?, ?, ?, ?)";
    $stmt = $db->prepare($sql);

    $count = 0;
    $imported = 0;
    $errors = 0;

    while (($line = fgets($file)) !== false) {
        $line = trim($line);
        $count++;

        // Skip header row
        if ($count === 1 && strpos($line, 'TimeStamp') !== false) {
            continue;
        }

        // Parse CSV: TimeStamp,AppId,Source
        $parts = str_getcsv($line);
        if (count($parts) < 3) {
            if ($verbose) echo "  Skipping malformed line $count: $line\n";
            $errors++;
            continue;
        }

        $timestamp = $parts[0];
        $appIdentifier = $parts[1];
        $source = $parts[2];

        // Skip suspicious entries
        if ($appIdentifier === '.env') {
            continue;
        }

        // Convert timestamp format (Y/m/d H:i:s -> Y-m-d H:i:s)
        $timestamp = str_replace('/', '-', $timestamp);

        // Determine if app_id is numeric
        $appId = is_numeric($appIdentifier) ? (int)$appIdentifier : null;

        if ($verbose) {
            echo "  [$count] $timestamp | $appIdentifier | $source\n";
        }

        if (!$dryRun) {
            try {
                $stmt->execute([$appId, $appIdentifier, $source, null, $timestamp]);
                $imported++;
            } catch (PDOException $e) {
                if ($verbose) echo "  Error on line $count: " . $e->getMessage() . "\n";
                $errors++;
            }
        } else {
            $imported++;
        }
    }

    fclose($file);

    if ($errors > 0) {
        echo "  Errors: $errors\n";
    }

    return $imported;
}

function importUpdateCheckLogs($db, $filePath, $dryRun, $verbose) {
    $file = fopen($filePath, 'r');
    if (!$file) {
        echo "  Error: Could not open file\n";
        return 0;
    }

    $sql = "INSERT INTO update_check_logs (app_id, app_name, device_data, client_info, client_id, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $db->prepare($sql);

    $count = 0;
    $imported = 0;
    $errors = 0;

    while (($line = fgets($file)) !== false) {
        $line = trim($line);
        $count++;

        // Skip header row
        if ($count === 1 && strpos($line, 'TimeStamp') !== false) {
            continue;
        }

        // Parse CSV: TimeStamp,IP,AppChecked,DeviceData,ClientInfo
        $parts = str_getcsv($line);
        if (count($parts) < 5) {
            if ($verbose) echo "  Skipping malformed line $count: $line\n";
            $errors++;
            continue;
        }

        $timestamp = $parts[0];
        $ipAddress = $parts[1];
        $appName = $parts[2];
        $deviceData = $parts[3];
        $clientInfo = $parts[4];

        // Convert timestamp format (Y/m/d H:i:s -> Y-m-d H:i:s)
        $timestamp = str_replace('/', '-', $timestamp);

        // Strip version from app name for cleaner storage
        $appNameParts = explode('/', $appName);
        $cleanAppName = $appNameParts[0];

        // Determine if app_id is numeric
        $appId = is_numeric($cleanAppName) ? (int)$cleanAppName : null;

        // Use clientInfo as client_id (it's the unique identifier in the old format)
        $clientId = $clientInfo;

        if ($verbose) {
            echo "  [$count] $timestamp | $ipAddress | $cleanAppName | $deviceData\n";
        }

        if (!$dryRun) {
            try {
                $stmt->execute([$appId, $cleanAppName, $deviceData, $clientInfo, $clientId, $ipAddress, $timestamp]);
                $imported++;
            } catch (PDOException $e) {
                if ($verbose) echo "  Error on line $count: " . $e->getMessage() . "\n";
                $errors++;
            }
        } else {
            $imported++;
        }
    }

    fclose($file);

    if ($errors > 0) {
        echo "  Errors: $errors\n";
    }

    return $imported;
}
