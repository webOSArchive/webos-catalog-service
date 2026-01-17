<?php
require_once __DIR__ . '/../../includes/LogRepository.php';

if (!isset($config))
    $config = include('../config.php');
if (!isset($mimeType))
    $mimeType = "application/json";
else
    error_reporting(E_ERROR | E_PARSE);

returnDownloadDataFormatted($config, $mimeType);

function returnDownloadDataFormatted($config, $mimeType) {
    $logRepo = new LogRepository();
    $stats = $logRepo->getDownloadStats();

    // Enrich top apps with app names from metadata
    foreach ($stats['topApps'] as $rank => &$app) {
        $appDetail = getDetailData($config["metadata_host"], $app['appId']);
        if (is_array($appDetail) && isset($appDetail['publicApplicationId'])) {
            $app['appName'] = $appDetail['publicApplicationId'];
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
