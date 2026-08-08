<?php
/**
 * My Account — self-service password change for the signed-in user.
 * Any logged-in account may change its own password (no special capability).
 */
require_once __DIR__ . '/includes/security.php';
$pageTitle = 'My Account';

$repo    = new AccountRepository();
$me      = current_account();
$errors  = [];
$usernameErrors = [];
$success = '';
$usernameSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_username') {
    if (!csrf_validate()) {
        $usernameErrors[] = 'Your session expired. Please try again.';
    } else {
        $newUsername = trim((string)($_POST['username'] ?? ''));

        if ($newUsername === (string)$me['username']) {
            $usernameErrors[] = 'That is already your username.';
        } elseif ($err = AccountRepository::usernameError($newUsername)) {
            $usernameErrors[] = ($err === 'USERNAME_RESERVED')
                ? 'That username is reserved.'
                : 'Usernames must be 3-32 characters: letters, numbers, ".", "_" or "-", starting with a letter or number.';
        } elseif ($repo->usernameTaken($newUsername, $me['id'])) {
            $usernameErrors[] = 'That username is already taken.';
        } else {
            $repo->updateProfile($me['id'], ['username' => $newUsername]);
            // Redirect (rather than re-render) so header.php's separate
            // current_account() call re-reads the new username instead of
            // returning its already-cached (stale) copy for this request.
            header('Location: account.php?username_changed=1');
            exit;
        }
    }
}

if (isset($_GET['username_changed'])) {
    $usernameSuccess = 'Your username has been changed.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    if (!csrf_validate()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!$repo->verifyLogin($me['username'], $current)) {
            $errors[] = 'Your current password is incorrect.';
        }
        if (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        }
        if ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        }
        if ($new === $current && empty($errors)) {
            $errors[] = 'New password must be different from your current one.';
        }
        if (empty($errors)) {
            $repo->setPassword($me['id'], $new);
            $success = 'Your password has been changed.';
        }
    }
}

include 'includes/header.php';
?>

<div class="page-header">
    <h1>My Account</h1>
</div>

<p style="color:#777;font-size:13px;">Signed in as <strong><?php echo htmlspecialchars($me['username']); ?></strong>.</p>

<div class="card" style="max-width:480px;">
    <div class="card-header"><h2>Change Username</h2></div>
    <div class="card-body">
        <?php if ($usernameSuccess): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($usernameSuccess); ?></div>
        <?php endif; ?>
        <?php if (!empty($usernameErrors)): ?>
        <div class="alert alert-error">
            <?php foreach ($usernameErrors as $e): ?><?php echo htmlspecialchars($e); ?><br><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="change_username">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" value="<?php echo htmlspecialchars($me['username']); ?>" required minlength="3" maxlength="32">
                <small>3-32 characters: letters, numbers, ".", "_" or "-", starting with a letter or number.</small>
            </div>
            <button type="submit" class="btn btn-primary">Change Username</button>
        </form>
    </div>
</div>

<div class="card" style="max-width:480px;">
    <div class="card-header"><h2>Change Password</h2></div>
    <div class="card-body">
        <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $e): ?><?php echo htmlspecialchars($e); ?><br><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="change_password">
            <div class="form-group">
                <label>Current Password</label>
                <input type="password" name="current_password" autocomplete="current-password" required>
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" autocomplete="new-password" required minlength="8">
                <small>At least 8 characters.</small>
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" autocomplete="new-password" required minlength="8">
            </div>
            <button type="submit" class="btn btn-primary">Change Password</button>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
