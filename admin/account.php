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
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

<?php if ($success): ?>
<div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
<div class="alert alert-error">
    <?php foreach ($errors as $e): ?><?php echo htmlspecialchars($e); ?><br><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card" style="max-width:480px;">
    <div class="card-header"><h2>Change Password</h2></div>
    <div class="card-body">
        <p style="color:#777;font-size:13px;margin-top:0;">Signed in as <strong><?php echo htmlspecialchars($me['username']); ?></strong>.</p>
        <form method="post" autocomplete="off">
            <?php echo csrf_field(); ?>
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
