<?php
/**
 * Accounts Management (Phase 1)
 *
 * Create/provision accounts, assign roles, enable/disable, reset passwords.
 * Requires the 'accounts.manage' capability (superadmin). Accounts are
 * admin-provisioned only - there is no web self-signup.
 */
require_once __DIR__ . '/includes/security.php';
$pageTitle = 'Accounts';
admin_require_capability('accounts.manage');

$repo   = new AccountRepository();
$me     = current_account();
$roles  = Capabilities::roleNames();
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_validate()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = $_POST['action'];
        $targetId = (int)($_POST['account_id'] ?? 0);

        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $role     = $_POST['role'] ?? '';

            if ($username === '') {
                $errors[] = 'Username is required.';
            }
            if (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            }
            if (!in_array($role, $roles, true)) {
                $errors[] = 'Please choose a valid role.';
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Email address is not valid.';
            }
            if (empty($errors)) {
                try {
                    $id = $repo->create([
                        'username'     => $username,
                        'password'     => $password,
                        'email'        => $email !== '' ? $email : null,
                        'display_name' => $username,
                        'status'       => 'active',
                    ]);
                    $repo->assignRole($id, $role);
                    $success = "Created account '{$username}'.";
                } catch (PDOException $e) {
                    $errors[] = ($e->getCode() == 23000)
                        ? 'That username or email already exists.'
                        : 'Database error: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'set_status') {
            $status = $_POST['status'] ?? '';
            if (!in_array($status, ['active', 'disabled'], true)) {
                $errors[] = 'Invalid status.';
            } elseif ($targetId === (int)$me['id']) {
                $errors[] = 'You cannot change your own account status.';
            } else {
                $repo->setStatus($targetId, $status);
                $success = 'Account status updated.';
            }
        } elseif ($action === 'reset_password') {
            $password = (string)($_POST['password'] ?? '');
            if (strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            } else {
                $repo->setPassword($targetId, $password);
                $success = 'Password reset.';
            }
        } elseif ($action === 'add_role') {
            $role = $_POST['role'] ?? '';
            if (!in_array($role, $roles, true)) {
                $errors[] = 'Invalid role.';
            } else {
                $repo->assignRole($targetId, $role);
                $success = 'Role added.';
            }
        } elseif ($action === 'remove_role') {
            $role = $_POST['role'] ?? '';
            if ($targetId === (int)$me['id']) {
                $errors[] = 'You cannot change your own roles (ask another superadmin).';
            } else {
                $repo->removeRole($targetId, $role);
                $success = 'Role removed.';
            }
        }
    }
}

$accounts = $repo->listAccounts();

include 'includes/header.php';
?>

<div class="page-header">
    <h1>Accounts</h1>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
<div class="alert alert-error">
    <?php foreach ($errors as $e): ?><?php echo htmlspecialchars($e); ?><br><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>Create Account</h2></div>
    <div class="card-body">
        <p style="color:#777;font-size:13px;margin-top:0;">Accounts are provisioned here or via <code>scripts/create-account.php</code>. There is no public sign-up.</p>
        <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group" style="margin:0;flex:1;min-width:140px;">
                <label>Username</label>
                <input type="text" name="username" required autocomplete="off">
            </div>
            <div class="form-group" style="margin:0;flex:1;min-width:160px;">
                <label>Email (optional)</label>
                <input type="text" name="email" autocomplete="off">
            </div>
            <div class="form-group" style="margin:0;flex:1;min-width:140px;">
                <label>Password</label>
                <input type="password" name="password" autocomplete="new-password" required>
            </div>
            <div class="form-group" style="margin:0;width:150px;">
                <label>Role</label>
                <select name="role">
                    <?php foreach ($roles as $r): ?>
                    <option value="<?php echo htmlspecialchars($r); ?>"<?php echo $r === 'developer' ? ' selected' : ''; ?>><?php echo htmlspecialchars($r); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Create</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>All Accounts</h2></div>
    <div class="card-body" style="padding:0;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th><th>Username</th><th>Email</th><th>Status</th>
                    <th>Roles</th><th>Last login</th><th>Manage</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $a): ?>
                <?php $isMe = ((int)$a['id'] === (int)$me['id']); ?>
                <tr>
                    <td><?php echo (int)$a['id']; ?></td>
                    <td><?php echo htmlspecialchars($a['username']); ?><?php echo $isMe ? ' <small>(you)</small>' : ''; ?></td>
                    <td><?php echo htmlspecialchars($a['email'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($a['status']); ?></td>
                    <td>
                        <?php foreach ($a['roles'] as $r): ?>
                            <span style="white-space:nowrap;"><?php echo htmlspecialchars($r); ?>
                            <?php if (!$isMe): ?>
                            <form method="post" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="remove_role">
                                <input type="hidden" name="account_id" value="<?php echo (int)$a['id']; ?>">
                                <input type="hidden" name="role" value="<?php echo htmlspecialchars($r, ENT_QUOTES); ?>">
                                <button type="submit" class="btn btn-sm" title="Remove role">&times;</button>
                            </form>
                            <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                        <?php if (empty($a['roles'])): ?><em>none</em><?php endif; ?>
                    </td>
                    <td><?php echo $a['last_login_at'] ? htmlspecialchars($a['last_login_at']) : '<span style="color:#aaa;">never</span>'; ?></td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                            <!-- add role -->
                            <form method="post" style="display:flex;gap:3px;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="add_role">
                                <input type="hidden" name="account_id" value="<?php echo (int)$a['id']; ?>">
                                <select name="role" style="padding:3px;">
                                    <?php foreach ($roles as $r): ?><option value="<?php echo htmlspecialchars($r); ?>"><?php echo htmlspecialchars($r); ?></option><?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm">+ Role</button>
                            </form>
                            <?php if (!$isMe): ?>
                            <!-- enable/disable -->
                            <form method="post" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="account_id" value="<?php echo (int)$a['id']; ?>">
                                <input type="hidden" name="status" value="<?php echo $a['status'] === 'active' ? 'disabled' : 'active'; ?>">
                                <button type="submit" class="btn btn-sm"><?php echo $a['status'] === 'active' ? 'Disable' : 'Enable'; ?></button>
                            </form>
                            <?php endif; ?>
                            <!-- reset password -->
                            <form method="post" style="display:flex;gap:3px;" onsubmit="return this.password.value.length>=8 || (alert('Password must be at least 8 characters.'),false);">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="account_id" value="<?php echo (int)$a['id']; ?>">
                                <input type="password" name="password" placeholder="new password" style="width:120px;padding:3px;" autocomplete="new-password">
                                <button type="submit" class="btn btn-sm">Set PW</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
