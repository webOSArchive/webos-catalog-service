<?php
/**
 * Claim Existing App (Phase 3-lite)
 *
 * Lets a developer (apps.own) claim an unowned catalog app as theirs — e.g. to
 * restore an app they originally published. The explanation is recorded in
 * app_claims; for now claims on unowned apps are granted immediately (a future
 * approval flow will gate this).
 */
$pageTitle = 'Claim Existing App';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/AppRepository.php';

admin_require_capability('apps.own');

$repo = new AppRepository();
$myAccountId = (int) current_account()['id'];

$errors = [];
$claimedApp = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $appId = (int)($_POST['app_id'] ?? 0);
        $explanation = trim($_POST['explanation'] ?? '');

        if ($appId <= 0) {
            $errors[] = 'Please enter a valid numeric App ID.';
        }
        if ($explanation === '') {
            $errors[] = 'Please explain why you should be allowed to claim this app.';
        }

        $app = null;
        if (empty($errors)) {
            $app = $repo->getById($appId);
            if (!$app) {
                $errors[] = "No app with ID $appId exists in the catalog.";
            } elseif ((int)($app['owner_account_id'] ?? 0) === $myAccountId) {
                $errors[] = 'You already own this app.';
            } elseif (!empty($app['owner_account_id'])) {
                $errors[] = 'This app already belongs to another account. Contact an administrator if you believe this is a mistake.';
            }
        }

        if (empty($errors)) {
            try {
                if ($repo->claimApp($appId, $myAccountId, $explanation)) {
                    $claimedApp = $app;
                } else {
                    $errors[] = 'This app already belongs to another account. Contact an administrator if you believe this is a mistake.';
                }
            } catch (Exception $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

include 'includes/header.php';
?>

<div class="page-header">
    <h1>Claim Existing App</h1>
    <div>
        <a href="apps.php" class="btn">Back to Apps</a>
    </div>
</div>

<?php if ($claimedApp): ?>
<div class="alert alert-success">
    You now own <strong><?php echo htmlspecialchars($claimedApp['title']); ?></strong> (ID: <?php echo (int)$claimedApp['id']; ?>).
    <a href="app-edit.php?id=<?php echo (int)$claimedApp['id']; ?>">Edit it now</a> or <a href="apps.php">view your apps</a>.
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
    <strong>Unable to claim app:</strong>
    <ul style="margin:10px 0 0 20px;">
        <?php foreach ($errors as $error): ?>
        <li><?php echo htmlspecialchars($error); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <p style="margin-bottom:20px;">
            If an app in the catalog is yours — for example, you originally published it and want to
            restore or maintain it — you can claim it here. Apps that already belong to another
            account cannot be claimed.
        </p>
        <form method="post" class="admin-form">
            <?php echo csrf_field(); ?>

            <div class="form-group">
                <label>App ID *</label>
                <input type="number" name="app_id" min="1" required style="width:150px;"
                       value="<?php echo $claimedApp ? '' : htmlspecialchars($_POST['app_id'] ?? ''); ?>">
                <small>The numeric catalog ID of the app (shown in the app list and in catalog URLs)</small>
            </div>

            <div class="form-group">
                <label>Why should you be allowed to claim this app? *</label>
                <textarea name="explanation" rows="4" required placeholder="e.g., I want to restore it; it was mine originally."><?php echo $claimedApp ? '' : htmlspecialchars($_POST['explanation'] ?? ''); ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Claim App</button>
                <a href="apps.php" class="btn">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
