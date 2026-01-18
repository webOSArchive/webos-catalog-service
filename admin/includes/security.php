<?php
/**
 * Admin Security Functions
 *
 * Include this at the top of admin pages before processing any POST data.
 */

/**
 * CSRF Protection via Referer header validation
 * Checks that POST requests originate from the same host
 */
function validateCsrfReferer() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }

    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (empty($referer)) {
        return false;
    }

    $refererHost = parse_url($referer, PHP_URL_HOST);
    $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];

    // Strip port from both if present
    $refererHost = preg_replace('/:\d+$/', '', $refererHost);
    $serverHost = preg_replace('/:\d+$/', '', $serverHost);

    return $refererHost === $serverHost;
}

// Validate CSRF on POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCsrfReferer()) {
    http_response_code(403);
    die('Forbidden: Invalid request origin.');
}
