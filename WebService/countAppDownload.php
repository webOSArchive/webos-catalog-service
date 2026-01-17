<?php
require_once __DIR__ . '/../includes/LogRepository.php';

if (isset($_GET["appid"]) && $_GET["appid"] != "") {
    $appid = $_GET["appid"];

    // Skip vulnerability probes and invalid app IDs
    if (isProbeAttempt($appid)) {
        return;
    }

    $source = "app";
    if (isset($_GET["source"]) && $_GET["source"] != "") {
        $source = urldecode($_GET["source"]);
        $source = str_replace(",", "", $source);
    }

    $ipAddress = getVisitorIP();
    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;

    try {
        $logRepo = new LogRepository();
        $logRepo->logDownload($appid, $source, $ipAddress, $userAgent);
    } catch (Exception $e) {
        error_log("Non-fatal error: " . $_SERVER['SCRIPT_NAME'] . " was unable to log download: " . $e->getMessage(), 0);
    }
}

function isProbeAttempt($appid) {
    $appid = strtolower($appid);

    // Block common vulnerability probe patterns
    $blocked = [
        '.env', 'eval-stdin.php', 'wp-login.php', 'wp-admin', 'xmlrpc.php',
        'admin.php', 'shell.php', 'config.php', 'phpinfo.php', 'setup.php'
    ];
    if (in_array($appid, $blocked)) {
        return true;
    }

    // Block path traversal attempts
    if (strpos($appid, '../') !== false || strpos($appid, '..\\') !== false) {
        return true;
    }

    // Block requests ending in .php (no legitimate app ID ends in .php)
    if (substr($appid, -4) === '.php') {
        return true;
    }

    // Block script injection attempts
    if (strpos($appid, '<script') !== false || strpos($appid, 'javascript:') !== false) {
        return true;
    }

    return false;
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