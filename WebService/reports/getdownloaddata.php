<?php
require_once __DIR__ . '/../../includes/LogRepository.php';
require_once __DIR__ . '/../../includes/AppRepository.php';

if (!isset($config))
    $config = include('../config.php');
if (!isset($mimeType))
    $mimeType = "application/json";
else
    error_reporting(E_ERROR | E_PARSE);

returnDownloadDataFormatted($config, $mimeType);

function returnDownloadDataFormatted($config, $mimeType) {
    $logRepo = new LogRepository();
    $appRepo = new AppRepository();
    $stats = $logRepo->getDownloadStats();

    // Enrich top apps with app names from database first, fallback to metadata host
    foreach ($stats['topApps'] as $rank => &$app) {
        $appName = null;

        // Try database first (faster and more reliable)
        if (is_numeric($app['appId'])) {
            $dbApp = $appRepo->getById((int)$app['appId']);
            if ($dbApp && !empty($dbApp['title'])) {
                $appName = $dbApp['title'];
            }
        }

        // Fallback to external metadata host if not in database
        if (!$appName) {
            $appDetail = getDetailData($config["metadata_host"], $app['appId']);
            if (is_array($appDetail) && isset($appDetail['publicApplicationId'])) {
                $appName = $appDetail['publicApplicationId'];
            }
        }

        if ($appName) {
            $app['appName'] = $appName;
        }
    }

    header("Content-Type: " . $mimeType);
    echo(json_encode($stats));
}

function getDetailData($host, $myIdx) {
    if (is_numeric($myIdx)) {
        $mypath = "http://{$host}/{$myIdx}.json";
        $myfile = @fopen($mypath, "rb");
        if ($myfile) {
            $content = stream_get_contents($myfile);
            fclose($myfile);
            return json_decode($content, true);
        }
    }
    return $myIdx;
}
?>
