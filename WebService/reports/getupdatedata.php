<?php
require_once __DIR__ . '/../../includes/LogRepository.php';

if (!isset($config))
    $config = include('../config.php');
if (!isset($mimeType))
    $mimeType = "application/json";
else
    error_reporting(E_ERROR | E_PARSE);

returnUpdateDataFormatted($config, $mimeType);

function returnUpdateDataFormatted($config, $mimeType) {
    $logRepo = new LogRepository();
    $stats = $logRepo->getUpdateCheckStats();

    header("Content-Type: " . $mimeType);
    echo(json_encode($stats));
}
?>