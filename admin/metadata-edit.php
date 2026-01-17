<?php
/**
 * App Metadata Edit Page
 */
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/AppRepository.php';
require_once __DIR__ . '/../includes/MetadataRepository.php';

$db = Database::getInstance()->getConnection();
$appRepo = new AppRepository();
$metaRepo = new MetadataRepository();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$id) {
    header('Location: apps.php');
    exit;
}

$app = $appRepo->getById($id);
if (!$app) {
    header('Location: apps.php');
    exit;
}

$metadata = $metaRepo->getForAdmin($id);
$pageTitle = 'Edit Metadata: ' . $app['title'];
$errors = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'publicApplicationId' => trim($_POST['publicApplicationId'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'version' => trim($_POST['version'] ?? ''),
        'versionNote' => trim($_POST['versionNote'] ?? ''),
        'homeURL' => trim($_POST['homeURL'] ?? ''),
        'supportURL' => trim($_POST['supportURL'] ?? ''),
        'custsupportemail' => trim($_POST['custsupportemail'] ?? ''),
        'custsupportphonenum' => trim($_POST['custsupportphonenum'] ?? ''),
        'copyright' => trim($_POST['copyright'] ?? ''),
        'licenseURL' => trim($_POST['licenseURL'] ?? ''),
        'locale' => trim($_POST['locale'] ?? 'en_US'),
        'appSize' => $_POST['appSize'] ? (int)$_POST['appSize'] : null,
        'installSize' => $_POST['installSize'] ? (int)$_POST['installSize'] : null,
        'price' => (float)($_POST['price'] ?? 0),
        'currency' => trim($_POST['currency'] ?? 'USD'),
        'free' => isset($_POST['free']),
        'filename' => trim($_POST['filename'] ?? ''),
        'originalFileName' => trim($_POST['originalFileName'] ?? ''),
        'starRating' => $_POST['starRating'] ? (int)$_POST['starRating'] : null,
        'isEncrypted' => isset($_POST['isEncrypted']),
        'adultRating' => isset($_POST['adultRating']),
        'islocationbased' => isset($_POST['islocationbased']),
        'isAdvertized' => isset($_POST['isAdvertized']),
        'mediaLink' => trim($_POST['mediaLink'] ?? ''),
        'mediaIcon' => trim($_POST['mediaIcon'] ?? '')
    ];

    try {
        $metaRepo->upsert($id, $data);
        $success = true;
        $metadata = $metaRepo->getForAdmin($id);
    } catch (Exception $e) {
        $errors[] = 'Database error: ' . $e->getMessage();
    }
}

include 'includes/header.php';
?>

<div class="page-header">
    <h1>Edit Metadata</h1>
    <div>
        <a href="app-edit.php?id=<?php echo $id; ?>" class="btn">Edit App</a>
        <a href="apps.php" class="btn">Back to Apps</a>
    </div>
</div>

<div class="alert alert-warning" style="margin-bottom:20px;">
    <strong>App:</strong> <?php echo htmlspecialchars($app['title']); ?> (ID: <?php echo $id; ?>)
</div>

<?php if ($success): ?>
<div class="alert alert-success">Metadata saved successfully!</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
    <strong>Error:</strong>
    <?php foreach ($errors as $error): ?>
    <?php echo htmlspecialchars($error); ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post" class="admin-form">
            <div class="form-group">
                <label>Package ID (publicApplicationId)</label>
                <input type="text" name="publicApplicationId" value="<?php echo htmlspecialchars($metadata['public_application_id'] ?? ''); ?>">
                <small>e.g., com.example.myapp</small>
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="6"><?php echo htmlspecialchars($metadata['description'] ?? ''); ?></textarea>
            </div>

            <div class="form-group">
                <label>Version</label>
                <input type="text" name="version" value="<?php echo htmlspecialchars($metadata['version'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label>Version Notes / Changelog</label>
                <textarea name="versionNote" rows="4"><?php echo htmlspecialchars($metadata['version_note'] ?? ''); ?></textarea>
            </div>

            <div class="form-group">
                <label>Download Filename</label>
                <input type="text" name="filename" value="<?php echo htmlspecialchars($metadata['filename'] ?? ''); ?>">
                <small>Path to IPK file or full URL (e.g., "myapp_1.0.0_all.ipk" or "http://...")</small>
            </div>

            <div class="form-group">
                <label>Original Filename</label>
                <input type="text" name="originalFileName" value="<?php echo htmlspecialchars($metadata['original_filename'] ?? ''); ?>">
            </div>

            <fieldset>
                <legend>Pricing</legend>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:15px;">
                    <div class="form-group" style="margin:0;">
                        <label>Price</label>
                        <input type="number" step="0.01" name="price" value="<?php echo htmlspecialchars($metadata['price'] ?? '0'); ?>">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Currency</label>
                        <input type="text" name="currency" value="<?php echo htmlspecialchars($metadata['currency'] ?? 'USD'); ?>">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>&nbsp;</label>
                        <label style="display:block;padding-top:10px;">
                            <input type="checkbox" name="free" <?php echo ($metadata['free'] ?? true) ? 'checked' : ''; ?>>
                            Free App
                        </label>
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>Size & Rating</legend>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:15px;">
                    <div class="form-group" style="margin:0;">
                        <label>App Size (bytes)</label>
                        <input type="number" name="appSize" value="<?php echo htmlspecialchars($metadata['app_size'] ?? ''); ?>">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Install Size (bytes)</label>
                        <input type="number" name="installSize" value="<?php echo htmlspecialchars($metadata['install_size'] ?? ''); ?>">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Star Rating (1-5)</label>
                        <input type="number" min="1" max="5" name="starRating" value="<?php echo htmlspecialchars($metadata['star_rating'] ?? ''); ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>URLs & Contact</legend>
                <div class="form-group">
                    <label>Home URL</label>
                    <input type="url" name="homeURL" value="<?php echo htmlspecialchars($metadata['home_url'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Support URL</label>
                    <input type="url" name="supportURL" value="<?php echo htmlspecialchars($metadata['support_url'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Support Email</label>
                    <input type="email" name="custsupportemail" value="<?php echo htmlspecialchars($metadata['cust_support_email'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Support Phone</label>
                    <input type="text" name="custsupportphonenum" value="<?php echo htmlspecialchars($metadata['cust_support_phone'] ?? ''); ?>">
                </div>
            </fieldset>

            <fieldset>
                <legend>Legal</legend>
                <div class="form-group">
                    <label>Copyright</label>
                    <input type="text" name="copyright" value="<?php echo htmlspecialchars($metadata['copyright'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>License URL</label>
                    <input type="text" name="licenseURL" value="<?php echo htmlspecialchars($metadata['license_url'] ?? ''); ?>">
                </div>
            </fieldset>

            <fieldset>
                <legend>Media</legend>
                <div class="form-group">
                    <label>Media Link (e.g., YouTube)</label>
                    <input type="url" name="mediaLink" value="<?php echo htmlspecialchars($metadata['media_link'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Media Icon</label>
                    <input type="text" name="mediaIcon" value="<?php echo htmlspecialchars($metadata['media_icon'] ?? ''); ?>">
                </div>
            </fieldset>

            <fieldset>
                <legend>Locale</legend>
                <div class="form-group" style="margin:0;">
                    <label>Locale</label>
                    <input type="text" name="locale" value="<?php echo htmlspecialchars($metadata['locale'] ?? 'en_US'); ?>">
                    <small>e.g., en_US, de_DE</small>
                </div>
            </fieldset>

            <fieldset>
                <legend>Flags</legend>
                <label>
                    <input type="checkbox" name="isEncrypted" <?php echo ($metadata['is_encrypted'] ?? false) ? 'checked' : ''; ?>>
                    Encrypted
                </label>
                <label>
                    <input type="checkbox" name="adultRating" <?php echo ($metadata['adult_rating'] ?? false) ? 'checked' : ''; ?>>
                    Adult Rating
                </label>
                <label>
                    <input type="checkbox" name="islocationbased" <?php echo ($metadata['is_location_based'] ?? false) ? 'checked' : ''; ?>>
                    Location Based
                </label>
                <label>
                    <input type="checkbox" name="isAdvertized" <?php echo ($metadata['is_advertized'] ?? false) ? 'checked' : ''; ?>>
                    Advertized
                </label>
            </fieldset>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Metadata</button>
                <a href="app-edit.php?id=<?php echo $id; ?>" class="btn">Edit App</a>
                <a href="apps.php" class="btn">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
