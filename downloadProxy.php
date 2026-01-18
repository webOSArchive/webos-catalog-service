<?php
/**
 * Download Proxy Script
 *
 * Proxies downloads from HTTP storage to HTTPS users.
 * Used by web UI only - API clients download directly.
 */

// Get encoded URL from request
$encodedUrl = $_GET['url'] ?? '';
$appId = $_GET['appid'] ?? '';

if (empty($encodedUrl)) {
    http_response_code(400);
    die('Missing URL parameter');
}

// Load config and get the encoding secret
$config = include('WebService/config.php');
$secret = $config['download_secret'] ?? 'webos_archive_default_secret';

// Decode the URL (remove secret and base64 decode)
$encodedUrl = str_replace($secret, '', $encodedUrl);
$downloadUrl = base64_decode($encodedUrl);

if (!$downloadUrl) {
    http_response_code(400);
    die('Invalid URL encoding');
}

// Validate URL is from allowed hosts
$config = include('WebService/config.php');
$allowedHosts = [
    $config['package_host'],
    $config['package_host_secure'] ?? $config['package_host']
];

$parsedUrl = parse_url($downloadUrl);
$urlHost = $parsedUrl['host'] ?? '';

// Check if host is allowed (or if it's a full external URL we trust)
$isAllowed = false;
foreach ($allowedHosts as $allowed) {
    if (stripos($urlHost, $allowed) !== false || stripos($allowed, $urlHost) !== false) {
        $isAllowed = true;
        break;
    }
}

// Also allow URLs that were stored as absolute paths (legacy data)
if (!$isAllowed && (strpos($downloadUrl, 'http://') === 0 || strpos($downloadUrl, 'https://') === 0)) {
    // Allow any URL that ends with .ipk (package file)
    if (preg_match('/\.ipk$/i', $parsedUrl['path'] ?? '')) {
        $isAllowed = true;
    }
}

if (!$isAllowed) {
    http_response_code(403);
    die('Download host not allowed');
}

// Ensure URL uses HTTP (our storage doesn't have SSL)
$downloadUrl = str_replace('https://', 'http://', $downloadUrl);

// Get filename for Content-Disposition header
$filename = basename($parsedUrl['path'] ?? 'download.ipk');

// Open remote file
$context = stream_context_create([
    'http' => [
        'timeout' => 30,
        'follow_location' => true,
        'max_redirects' => 5
    ]
]);

$remoteFile = @fopen($downloadUrl, 'rb', false, $context);

if (!$remoteFile) {
    http_response_code(404);
    die('File not found or unavailable');
}

// Get file size from headers if available
$meta = stream_get_meta_data($remoteFile);
$contentLength = null;
if (isset($meta['wrapper_data'])) {
    foreach ($meta['wrapper_data'] as $header) {
        if (stripos($header, 'Content-Length:') === 0) {
            $contentLength = trim(substr($header, 15));
            break;
        }
    }
}

// Set headers for download
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
if ($contentLength) {
    header('Content-Length: ' . $contentLength);
}
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// Stream the file to the client
while (!feof($remoteFile)) {
    echo fread($remoteFile, 8192);
    flush();
}

fclose($remoteFile);
