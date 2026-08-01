<?php
/**
 * Admin Security Bootstrap
 *
 * Include this FIRST on every admin page (before any output or POST handling).
 * It provides, in order:
 *   1. An isolated admin session.
 *   2. App-level authentication + capability gating (Phase 1). This runs
 *      *inside* the existing nginx basic auth, which stays as an outer gate.
 *   3. CSRF protection: the original Referer check (all POSTs) plus a
 *      token helper for sensitive forms (login, account management).
 *
 * login.php is exempt from the login requirement (so it can render the form);
 * every other admin script requires an active account with 'admin.access'.
 */

// Marker so partials (header/footer) can refuse to run if fetched directly.
define('ADMIN_BOOTSTRAP', true);

require_once __DIR__ . '/../../includes/AccountRepository.php';

// --- Isolated admin session -------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_name('WOSADMINSESS'); // distinct from the front-end site session
    // Behind Cloudflare/nginx the origin can see plain HTTP even when the user is
    // on HTTPS; honor X-Forwarded-Proto so the admin cookie is still Secure.
    $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime'  => 0,
        'path'      => '/',
        'httponly'  => true,
        'secure'    => $secure,
        'samesite'  => 'Lax',
    ]);
    session_start();
}

// --- Current account / capabilities (request-cached) ------------------------
function current_account() {
    static $acct = false; // false = not yet resolved
    if ($acct !== false) {
        return $acct;
    }
    if (empty($_SESSION['account_id'])) {
        return $acct = null;
    }
    $a = (new AccountRepository())->findById($_SESSION['account_id']);
    // Re-check status every request so a disabled account loses access immediately.
    return $acct = ($a && $a['status'] === 'active') ? $a : null;
}

function current_capabilities() {
    static $caps = null;
    if ($caps !== null) {
        return $caps;
    }
    $a = current_account();
    return $caps = $a ? (new AccountRepository())->getCapabilities($a['id']) : [];
}

function admin_is_logged_in() {
    return current_account() !== null;
}

function admin_has_capability($capability) {
    return in_array($capability, current_capabilities(), true);
}

/** Hard stop (403) if the logged-in account lacks a capability. */
function admin_require_capability($capability) {
    if (!admin_has_capability($capability)) {
        http_response_code(403);
        die('Forbidden: your account does not have the required permission (' .
            htmlspecialchars($capability) . ').');
    }
}

// --- CSRF token helpers (for login + account management forms) --------------
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field() {
    return '<input type="hidden" name="_csrf" value="' .
        htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

function csrf_validate() {
    $token = $_POST['_csrf'] ?? '';
    return is_string($token) && $token !== '' && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

// --- CSRF (Referer): same-origin check on all POST requests -----------------
function validateCsrfReferer() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (empty($referer)) {
        return false;
    }
    $refererHost = preg_replace('/:\d+$/', '', (string)parse_url($referer, PHP_URL_HOST));
    $serverHost  = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME']);
    return $refererHost === $serverHost;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCsrfReferer()) {
    http_response_code(403);
    die('Forbidden: Invalid request origin.');
}

// --- Enforce login + admin.access (all pages except the login screen) -------
$__adminScript = basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
$__adminPublic = ['login.php'];

if (!in_array($__adminScript, $__adminPublic, true)) {
    if (!admin_is_logged_in()) {
        $next = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: login.php?next=' . urlencode($next));
        exit;
    }
    admin_require_capability('admin.access');
}
