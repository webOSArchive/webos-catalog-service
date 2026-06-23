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
    $appid = strtolower(trim($appid));

    // Empty or null-byte injected identifiers are never legitimate
    if ($appid === '' || strpos($appid, "\0") !== false) {
        return true;
    }

    // Legitimate app IDs never contain path separators. Scanners requesting
    // files/paths (e.g. app/config/parameters.yml) get filtered here.
    if (strpos($appid, '/') !== false || strpos($appid, '\\') !== false) {
        return true;
    }

    // Block path traversal attempts
    if (strpos($appid, '..') !== false) {
        return true;
    }

    // Block requests for files by extension. No legitimate app ID is a
    // filename, so anything ending in a known file extension is a scanner
    // probing for source/config/secret files (parameters.yml, .env, config.php,
    // settings.ini, backup.sql, .git, etc.).
    $blockedExtensions = [
        '.php', '.yml', '.yaml', '.env', '.ini', '.conf', '.config', '.json',
        '.xml', '.sql', '.bak', '.old', '.swp', '.lock', '.sh', '.git',
        '.asp', '.aspx', '.jsp', '.cgi', '.pl', '.py'
    ];
    foreach ($blockedExtensions as $ext) {
        if (substr($appid, -strlen($ext)) === $ext) {
            return true;
        }
    }

    // Block known probe filenames/keywords (extension-less variants)
    $blocked = [
        '.env', 'eval-stdin', 'wp-login', 'wp-admin', 'xmlrpc',
        'admin', 'shell', 'config', 'phpinfo', 'setup', 'parameters'
    ];
    if (in_array($appid, $blocked)) {
        return true;
    }

    // Block script injection attempts
    if (strpos($appid, '<script') !== false || strpos($appid, 'javascript:') !== false) {
        return true;
    }

    // Block SQL injection attempts
    $sqlPatterns = ['select', 'union', 'sleep', 'waitfor', 'drop', 'insert', 'update', '--', '/*', '*/'];
    foreach ($sqlPatterns as $pattern) {
        if (strpos($appid, $pattern) !== false) {
            return true;
        }
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