<?php
/**
 * App Images — upload/replace icons and screenshots for one app.
 *
 * Files are stored on disk under config['image_path']/<appId>/ (never in the DB);
 * the DB keeps the relative path ("<appId>/<file>"). Full managers (apps.edit)
 * can manage any app; owners (developers with apps.own) only their own.
 */
require_once __DIR__ . '/includes/security.php';
$pageTitle = 'App Images';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/AppRepository.php';
require_once __DIR__ . '/../includes/MetadataRepository.php';
require_once __DIR__ . '/../includes/ImageStorage.php';
$config = require __DIR__ . '/../WebService/config.php';

$repo     = new AppRepository();
$metaRepo = new MetadataRepository();
$storage  = ImageStorage::fromConfig($config);

$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$app = $id ? $repo->getById($id) : null;
if (!$app) {
    header('Location: apps.php');
    exit;
}

// Access: managers (apps.edit) anywhere; owners (apps.own) only their own apps.
admin_require_any(['apps.edit', 'apps.own']);
if (admin_is_owner_only() && (int)($app['owner_account_id'] ?? 0) !== (int) current_account()['id']) {
    http_response_code(403);
    die('Forbidden: you can only manage images for apps your account owns.');
}

$errors  = [];
$success = '';
$imgBase = '//' . rtrim($config['image_host'] ?? '', '/') . '/'; // for previews

if (!$storage->isConfigured()) {
    $errors[] = 'Image storage is not configured — set image_path in WebService/config.php (and make the folder writable by the web user).';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $storage->isConfigured()) {
    if (!csrf_validate()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'upload_icon' || $action === 'upload_icon_big') {
            $isBig = ($action === 'upload_icon_big');
            $file = $_FILES['image_file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
                $errors[] = 'Please choose a file to upload.';
            } else {
                $rel = $storage->saveUpload($id, $file['tmp_name'], $isBig ? 'icon-256' : 'icon');
                if ($rel === null) {
                    $errors[] = 'That file is not a supported image (PNG, JPG or GIF).';
                } else {
                    if ($isBig) {
                        $repo->updateIconPaths($id, $app['app_icon'], $rel);
                    } else {
                        $repo->updateIconPaths($id, $rel, $app['app_icon_big']);
                    }
                    $success = $isBig ? 'Large icon updated.' : 'Icon updated.';
                }
            }
        } elseif ($action === 'upload_screenshot') {
            $file = $_FILES['image_file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
                $errors[] = 'Please choose a screenshot to upload.';
            } else {
                $images   = $metaRepo->getImages($id);
                $baseName = $storage->nextScreenshotName(array_map(function ($x) { return $x['screenshot']; }, $images));
                $rel      = $storage->saveUpload($id, $file['tmp_name'], $baseName);
                if ($rel === null) {
                    $errors[] = 'That file is not a supported image (PNG, JPG or GIF).';
                } else {
                    $nextOrder = empty($images) ? 1 : (max(array_map('intval', array_keys($images))) + 1);
                    $images[$nextOrder] = ['screenshot' => $rel, 'thumbnail' => $rel, 'orientation' => 'P', 'device' => 'P'];
                    $metaRepo->updateImages($id, $images);
                    $success = 'Screenshot added. (Set its orientation on the Metadata page if it should be landscape.)';
                }
            }
        } elseif ($action === 'delete_screenshot') {
            $delPath = $_POST['path'] ?? '';
            $images  = $metaRepo->getImages($id);
            $kept    = [];
            foreach ($images as $order => $img) {
                if ($img['screenshot'] === $delPath) {
                    $storage->delete($img['screenshot']);
                    if (!empty($img['thumbnail']) && $img['thumbnail'] !== $img['screenshot']) {
                        $storage->delete($img['thumbnail']);
                    }
                    continue;
                }
                $kept[$order] = $img;
            }
            $metaRepo->updateImages($id, $kept);
            $success = 'Screenshot removed.';
        }

        $app = $repo->getById($id); // reflect icon changes
    }
}

$images = $metaRepo->getImages($id);
include 'includes/header.php';
?>

<div class="page-header">
    <h1>Images: <?php echo htmlspecialchars($app['title']); ?> <small style="color:#7f8c8d;font-size:0.6em;">#<?php echo (int)$id; ?></small></h1>
    <a href="app-edit.php?id=<?php echo (int)$id; ?>" class="btn">&larr; Back to App</a>
</div>

<?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if (!empty($errors)): ?>
<div class="alert alert-error"><?php foreach ($errors as $e): ?><?php echo htmlspecialchars($e); ?><br><?php endforeach; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>Icons</h2></div>
    <div class="card-body" style="display:flex;gap:32px;flex-wrap:wrap;">
        <?php
        $iconFields = [
            ['label' => 'Icon',       'path' => $app['app_icon'],     'action' => 'upload_icon'],
            ['label' => 'Large Icon', 'path' => $app['app_icon_big'], 'action' => 'upload_icon_big'],
        ];
        foreach ($iconFields as $ic):
        ?>
        <div>
            <p style="margin:0 0 6px;font-weight:bold;"><?php echo $ic['label']; ?></p>
            <?php if (!empty($ic['path'])): ?>
                <img src="<?php echo htmlspecialchars($imgBase . $ic['path'], ENT_QUOTES); ?>" alt="" style="width:96px;height:96px;object-fit:cover;border:1px solid #ddd;border-radius:8px;background:#f5f5f5;" onerror="this.style.opacity=0.3;">
                <p style="font-size:0.8em;color:#7f8c8d;margin:4px 0;"><?php echo htmlspecialchars($ic['path']); ?></p>
            <?php else: ?>
                <div style="width:96px;height:96px;border:1px dashed #ccc;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:0.8em;">none</div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" style="margin-top:8px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="<?php echo $ic['action']; ?>">
                <input type="file" name="image_file" accept="image/png,image/jpeg,image/gif" required>
                <button type="submit" class="btn btn-sm btn-primary">Upload / Replace</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Screenshots</h2></div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;margin-bottom:16px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="upload_screenshot">
            <input type="file" name="image_file" accept="image/png,image/jpeg,image/gif" required>
            <button type="submit" class="btn btn-primary">Add Screenshot</button>
        </form>

        <?php if (empty($images)): ?>
            <p style="color:#7f8c8d;">No screenshots yet.</p>
        <?php else: ?>
        <div style="display:flex;gap:14px;flex-wrap:wrap;">
            <?php foreach ($images as $img): ?>
            <div style="text-align:center;">
                <img src="<?php echo htmlspecialchars($imgBase . $img['screenshot'], ENT_QUOTES); ?>" alt="" style="width:120px;height:120px;object-fit:cover;border:1px solid #ddd;border-radius:8px;background:#f5f5f5;" onerror="this.style.opacity=0.3;">
                <p style="font-size:0.75em;color:#7f8c8d;margin:4px 0;"><?php echo htmlspecialchars(basename($img['screenshot'])); ?><?php echo ($img['orientation'] ?? 'P') === 'L' ? ' (L)' : ''; ?></p>
                <form method="post" onsubmit="return confirm('Remove this screenshot?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="delete_screenshot">
                    <input type="hidden" name="path" value="<?php echo htmlspecialchars($img['screenshot'], ENT_QUOTES); ?>">
                    <button type="submit" class="btn btn-sm" style="color:#a12;">Delete</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
