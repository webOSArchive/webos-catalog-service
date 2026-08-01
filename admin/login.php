<?php
/**
 * Admin login (Phase 1).
 *
 * Runs inside the nginx basic auth. Authenticates against the accounts table
 * and requires the 'admin.access' capability. On success, stores the account id
 * in the isolated admin session.
 */
require_once __DIR__ . '/includes/security.php';

// Already signed in? Go to the dashboard.
if (admin_is_logged_in()) {
    header('Location: index.php');
    exit;
}

/** Only allow local admin destinations for ?next (prevents open redirects). */
function safe_next($n) {
    $n = (string)$n;
    if (preg_match('#^/admin/[A-Za-z0-9_./?=&%-]*$#', $n)) {
        return $n;
    }
    if (preg_match('#^[A-Za-z0-9_-]+\.php(\?[A-Za-z0-9_./?=&%-]*)?$#', $n)) {
        return $n;
    }
    return 'index.php';
}

$next  = safe_next($_POST['next'] ?? $_GET['next'] ?? 'index.php');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $repo = new AccountRepository();
        $acct = $repo->verifyLogin($username, $password);

        if ($acct && $repo->hasCapability($acct['id'], 'admin.access')) {
            session_regenerate_id(true); // prevent session fixation
            $_SESSION['account_id'] = $acct['id'];
            $repo->updateLastLogin($acct['id']);
            header('Location: ' . $next);
            exit;
        } elseif ($acct) {
            $error = 'That account does not have admin access.';
        } else {
            usleep(300000); // modest brute-force slowdown
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in - webOS Catalog Admin</title>
    <link rel="stylesheet" href="assets/admin.css">
    <style>
        .login-wrap { max-width: 360px; margin: 8vh auto; padding: 0 16px; font-family: Arial, Helvetica, sans-serif; }
        .login-card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 24px; }
        .login-card h1 { font-size: 20px; margin: 0 0 4px; }
        .login-card p.sub { color: #777; font-size: 13px; margin: 0 0 18px; }
        .login-card label { display: block; font-size: 13px; font-weight: bold; margin: 12px 0 4px; }
        .login-card input[type=text], .login-card input[type=password] {
            width: 100%; padding: 9px 10px; border: 1px solid #ccc; border-radius: 5px; font-size: 15px;
            -webkit-box-sizing: border-box; box-sizing: border-box;
        }
        .login-card button { margin-top: 18px; width: 100%; padding: 10px; font-size: 15px; font-weight: bold;
            color: #fff; background: #3e0bf9; border: 0; border-radius: 5px; cursor: pointer; }
        .login-err { background: #fdecec; border: 1px solid #f5b5b5; color: #a12; padding: 9px 12px;
            border-radius: 5px; font-size: 13px; margin-bottom: 4px; }
    </style>
</head>
<body>
    <div class="login-wrap">
        <div class="login-card">
            <h1>webOS Catalog Admin</h1>
            <p class="sub">Sign in to continue.</p>
            <?php if ($error): ?><div class="login-err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <form method="post" action="login.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="next" value="<?php echo htmlspecialchars($next, ENT_QUOTES); ?>">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" autofocus autocomplete="username"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES); ?>">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password">
                <button type="submit">Sign in</button>
            </form>
        </div>
    </div>
</body>
</html>
