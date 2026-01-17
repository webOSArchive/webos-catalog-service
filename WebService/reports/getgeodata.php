<?php
/**
 * Geographic distribution report
 *
 * Shows where update checks are coming from (last 12 months)
 */
require_once __DIR__ . '/../../includes/Database.php';

if (!isset($config))
    $config = include('../config.php');
if (!isset($mimeType))
    $mimeType = "application/json";
else
    error_reporting(E_ERROR | E_PARSE);

returnGeoDataFormatted($config, $mimeType);

function returnGeoDataFormatted($config, $mimeType) {
    $db = Database::getInstance()->getConnection();
    $topGeoCount = 15;

    // Get unique IPs from last 12 months with their request counts
    $stmt = $db->query("
        SELECT
            ip_address,
            COUNT(*) as request_count,
            MIN(created_at) as first_seen,
            MAX(created_at) as last_seen
        FROM update_check_logs
        WHERE ip_address IS NOT NULL
          AND ip_address != ''
          AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY ip_address
        ORDER BY request_count DESC
    ");

    $ipData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get date range
    $dateStmt = $db->query("
        SELECT
            MIN(created_at) as first_date,
            MAX(created_at) as last_date,
            COUNT(*) as total
        FROM update_check_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    ");
    $dateRange = $dateStmt->fetch(PDO::FETCH_ASSOC);

    // Geolocate unique IPs and aggregate by region
    $geoRegions = [];
    $ipCache = [];
    $totalRecords = 0;

    foreach ($ipData as $row) {
        $ip = $row['ip_address'];
        $count = (int)$row['request_count'];
        $totalRecords += $count;

        // Get region for this IP
        $useCode = "??";
        $useName = "Unknown Region";

        if (!isset($ipCache[$ip])) {
            $regionData = getRegionForIP($ip);
            if ($regionData) {
                $regionObj = json_decode($regionData);
                if (is_object($regionObj) && isset($regionObj->country)) {
                    $useCode = $regionObj->country->iso_code ?? "??";
                    $useName = $regionObj->country->names->en ?? "Unknown Region";
                }
            }
            $ipCache[$ip] = ['code' => $useCode, 'name' => $useName];
        } else {
            $useCode = $ipCache[$ip]['code'];
            $useName = $ipCache[$ip]['name'];
        }

        // Aggregate by region
        if (!isset($geoRegions[$useCode])) {
            $geoRegions[$useCode] = [
                'regionCode' => $useCode,
                'regionName' => $useName,
                'count' => 0
            ];
        }
        $geoRegions[$useCode]['count'] += $count;
    }

    // Sort by count descending
    usort($geoRegions, function($a, $b) {
        return $b['count'] - $a['count'];
    });

    // Build response
    $topRegions = [];
    $i = 1;
    foreach ($geoRegions as $region) {
        if ($i > $topGeoCount) break;
        $topRegions[$i] = $region;
        $i++;
    }

    $response = [
        'firstDate' => $dateRange['first_date'],
        'lastDate' => $dateRange['last_date'],
        'regionRecords' => $totalRecords,
        'uniqueRegions' => count($geoRegions),
        'uniqueIPs' => count($ipData),
        'topRegions' => $topRegions
    ];

    header("Content-Type: " . $mimeType);
    echo json_encode($response);
}

function getRegionForIP($ip) {
    // Skip private/local IPs
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return null;
    }

    $serviceURL = "http://museum.weboslives.eu/dqidqsrwpnhotjldxljdhkxubidheffi/fhlyggephfhwaljgtxwqxmyhuvdexcjr.php?ip=" . urlencode($ip);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $serviceURL);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout per request
    $regionData = curl_exec($ch);
    curl_close($ch);
    return $regionData;
}
?>
