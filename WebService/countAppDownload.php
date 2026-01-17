<?php
require_once __DIR__ . '/../includes/LogRepository.php';

if (isset($_GET["appid"]) && $_GET["appid"] != "") {
    $source = "app";
    if (isset($_GET["source"]) && $_GET["source"] != "") {
        $source = urldecode($_GET["source"]);
        $source = str_replace(",", "", $source);
    }

    $ipAddress = getVisitorIP();
    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;

    try {
        $logRepo = new LogRepository();
        $logRepo->logDownload($_GET["appid"], $source, $ipAddress, $userAgent);
    } catch (Exception $e) {
        error_log("Non-fatal error: " . $_SERVER['SCRIPT_NAME'] . " was unable to log download: " . $e->getMessage(), 0);
    }
}

function getVisitorIP() {
    // Get real visitor IP behind CloudFlare network
    if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
        return $_SERVER["HTTP_CF_CONNECTING_IP"];
    }

    $client = @$_SERVER['HTTP_CLIENT_IP'];
    $forward = @$_SERVER['HTTP_X_FORWARDED_FOR'];
    $remote = $_SERVER['REMOTE_ADDR'];

    if (filter_var($client, FILTER_VALIDATE_IP)) {
        return $client;
    } elseif (filter_var($forward, FILTER_VALIDATE_IP)) {
        return $forward;
    }
    return $remote;
}
?> 