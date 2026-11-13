<?php
/**
 * Admin logout - clears the admin session and returns to the login screen.
 */
require_once __DIR__ . '/includes/security.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

// Secrets vault wrapping key (admin/secrets.php); useless without the session,
// but don't leave it lying around.
setcookie('WOSVAULT', '', ['expires' => time() - 3600, 'path' => '/admin/', 'httponly' => true, 'samesite' => 'Strict']);

header('Location: login.php');
exit;
