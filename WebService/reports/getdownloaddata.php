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

    // Enrich top apps with app names from database
    foreach ($stats['topApps'] as $rank => &$app) {
        if (is_numeric($app['appId'])) {
            $dbApp = $appRepo->getById((int)$app['appId']);
            if ($dbApp && !empty($dbApp['title'])) {
                $app['appName'] = $dbApp['title'];
            }
        }
    }

    header("Content-Type: " . $mimeType);
    echo(json_encode($stats));
}

?>
