<?php
/**
 * App Edit / Create Page
 */
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/AppRepository.php';
require_once __DIR__ . '/../includes/MetadataRepository.php';
require_once __DIR__ . '/../includes/AccountRepository.php';
require_once __DIR__ . '/../includes/ImageStorage.php';

$db = Database::getInstance()->getConnection();
$repo = new AppRepository();
$metaRepo = new MetadataRepository();
$accounts = (new AccountRepository())->listAccounts();
$imgConfig = require __DIR__ . '/../WebService/config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$app = $id ? $repo->getById($id) : null;
$isNew = !$app;

// Apps area gate. Full managers edit anything; owners only their own apps.
admin_require_any(['apps.edit', 'apps.own']);
$canEditAll  = admin_has_capability('apps.edit');
$ownOnly     = !$canEditAll;
$myAccountId = (int) current_account()['id'];
if ($ownOnly) {
    if ($isNew) {
        // Developers may create new apps; the app is owned by their account.
        admin_require_capability('apps.submit');
    } elseif ((int)($app['owner_account_id'] ?? 0) !== $myAccountId) {
        http_response_code(403);
        die('Forbidden: you can only edit apps your account owns.');
    }
}

// Get suggested next ID for new apps
$suggestedId = null;
if ($isNew) {
    $stmt = $db->query("SELECT MAX(id) + 1 AS next_id FROM apps");
    $result = $stmt->fetch();
    $suggestedId = $result['next_id'] ?? 1;
}

$pageTitle = $isNew ? 'Add New App' : 'Edit App';
$errors = [];
$success = false;

// Get categories
$categories = $repo->getCategories();

// Get vendors (for the Vendor ID picklist)
$vendors = $db->query("SELECT vendor_id, author_name FROM authors ORDER BY author_name")->fetchAll();

// Get related apps (for existing apps)
$relatedApps = [];
$relatedAppIds = [];
if ($id) {
    $relatedAppIds = $repo->getRelatedAppIds($id);
    if (!empty($relatedAppIds)) {
        $relatedApps = $repo->getByIds($relatedAppIds, true); // true = include adult
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'title' => trim($_POST['title'] ?? ''),
        'author' => trim($_POST['author'] ?? ''),
        'summary' => trim($_POST['summary'] ?? ''),
        'app_icon' => trim($_POST['app_icon'] ?? ''),
        'app_icon_big' => trim($_POST['app_icon_big'] ?? ''),
        'category' => $_POST['category'] ?? '',
        'vendor_id' => trim($_POST['vendor_id'] ?? '') ?: null,
        'owner_account_id' => ($_POST['owner_account_id'] ?? '') !== '' ? (int)$_POST['owner_account_id'] : null,
        'status' => $_POST['status'] ?? 'active',
        'pixi' => isset($_POST['pixi']),
        'pre' => isset($_POST['pre']),
        'pre2' => isset($_POST['pre2']),
        'pre3' => isset($_POST['pre3']),
        'veer' => isset($_POST['veer']),
        'touchpad' => isset($_POST['touchpad']),
        'touchpad_exclusive' => isset($_POST['touchpad_exclusive']),
        'luneos' => isset($_POST['luneos']),
        'adult' => isset($_POST['adult']),
        'in_revisionist_history' => isset($_POST['in_revisionist_history']),
        'in_curators_choice' => isset($_POST['in_curators_choice']),
        'recommendation_order' => (int)($_POST['recommendation_order'] ?? 0),
        'post_shutdown' => isset($_POST['post_shutdown'])
    ];

    if ($ownOnly) {
        $data['owner_account_id'] = $myAccountId;
        if ($isNew) {
            // New submissions belong to the submitter and start uncurated; the
            // developer chooses status and content flags themselves.
            $data['recommendation_order']   = 0;
            $data['in_curators_choice']     = false;
            $data['in_revisionist_history'] = false;
        } else {
            // Owners may only edit descriptive fields; curation/status/ownership
            // are forced back to the app's current values (defense-in-depth, since
            // the load check above already blocks non-owned apps).
            $data['status']                 = $app['status'];
            $data['recommendation_order']   = (int)$app['recommendation_order'];
            $data['in_curators_choice']     = (bool)$app['in_curators_choice'];
            $data['in_revisionist_history'] = (bool)$app['in_revisionist_history'];
            $data['post_shutdown']          = (bool)$app['post_shutdown'];
            $data['adult']                  = (bool)$app['adult'];
        }
    }

    // Validation
    if (empty($data['title'])) {
        $errors[] = 'Title is required';
    }
    if (empty($data['author'])) {
        $errors[] = 'Author is required';
    }

    // For new apps, require an ID
    if ($isNew) {
        $newId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($newId <= 0) {
            $errors[] = 'A valid App ID is required for new apps';
        } else {
            // Check if ID already exists
            $existing = $repo->getById($newId);
            if ($existing) {
                $errors[] = "App ID $newId already exists";
            }
            $data['id'] = $newId;
        }
    }

    // Save if no errors
    if (empty($errors)) {
        try {
            if ($isNew) {
                $repo->create($data);
                $id = $data['id'];
                // Auto-create the app's image folder (named by the numeric app id).
                $imgStore = ImageStorage::fromConfig($imgConfig);
                if ($imgStore->isConfigured()) {
                    $imgStore->ensureAppDir($id);
                }
                $success = true;
                // Redirect to edit page
                header("Location: app-edit.php?id=$id&saved=1");
                exit;
            } else {
                $repo->update($id, $data);
                $success = true;
            }
            // Reload app data
            $app = $repo->getById($id);
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Handle related app actions (add/remove)
if ($id && isset($_GET['action'])) {
    if ($_GET['action'] === 'add_related' && isset($_GET['related_id'])) {
        $relatedId = (int)$_GET['related_id'];
        if ($relatedId > 0 && $relatedId !== $id) {
            $repo->addRelatedApp($id, $relatedId);
        }
        header("Location: app-edit.php?id=$id#related-apps");
        exit;
    }
    if ($_GET['action'] === 'remove_related' && isset($_GET['related_id'])) {
        $relatedId = (int)$_GET['related_id'];
        $repo->removeRelatedApp($id, $relatedId);
        header("Location: app-edit.php?id=$id#related-apps");
        exit;
    }
}

// Refresh related apps after actions
if ($id) {
    $relatedAppIds = $repo->getRelatedAppIds($id);
    if (!empty($relatedAppIds)) {
        $relatedApps = $repo->getByIds($relatedAppIds, true);
    } else {
        $relatedApps = [];
    }
}

// Check for saved message from redirect
if (isset($_GET['saved'])) {
    $success = true;
}

// Application ID for the "View App" quick-action link, which must use
// ?appid= (not the numeric ?app=) so it still resolves for a Web Suppressed app.
$applicationId = $id ? ($metaRepo->getForAdmin($id)['public_application_id'] ?? '') : '';

// Shared quick-actions row, rendered above and below the form so they're
// reachable without scrolling either way.
function admin_app_quick_actions($id, $isNew, $applicationId) {
    if (!$isNew) {
        ?>
        <a href="app-images.php?id=<?php echo (int)$id; ?>" class="btn">Manage Images</a>
        <a href="metadata-edit.php?id=<?php echo (int)$id; ?>" class="btn">Edit Metadata</a>
        <a href="<?php echo htmlspecialchars('../showMuseumDetails.php?appid=' . urlencode($applicationId), ENT_QUOTES); ?>" class="btn" target="_blank" rel="noopener">View App</a>
        <?php
    }
    ?>
    <a href="apps.php" class="btn">Back to Apps</a>
    <?php
}

include 'includes/header.php';
?>

<div class="page-header">
    <h1><?php echo $pageTitle; ?><?php echo $id ? " (ID: $id)" : ''; ?></h1>
    <div>
        <?php admin_app_quick_actions($id, $isNew, $applicationId); ?>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success">App saved successfully!</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
    <strong>Please fix the following errors:</strong>
    <ul style="margin:10px 0 0 20px;">
        <?php foreach ($errors as $error): ?>
        <li><?php echo htmlspecialchars($error); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post" class="admin-form">
            <?php if ($isNew): ?>
            <div class="form-group">
                <label>App ID *</label>
                <input type="number" name="id" value="<?php echo htmlspecialchars($_POST['id'] ?? $suggestedId ?? ''); ?>" required min="1">
                <small>Suggested next ID: <?php echo $suggestedId; ?> (must not already exist)</small>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Title *</label>
                <input type="text" name="title" value="<?php echo htmlspecialchars($app['title'] ?? $_POST['title'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label>Author *</label>
                <input type="text" name="author" value="<?php echo htmlspecialchars($app['author'] ?? $_POST['author'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label>Summary</label>
                <textarea name="summary" rows="4"><?php echo htmlspecialchars($app['summary'] ?? $_POST['summary'] ?? ''); ?></textarea>
            </div>

            <div class="form-group">
                <label>Category</label>
                <select name="category">
                    <option value="">-- Select Category --</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat['name']); ?>"
                        <?php echo ($app['category'] ?? $_POST['category'] ?? '') === $cat['name'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <?php
                    $currentStatus = $app['status'] ?? $_POST['status'] ?? 'active';
                    $statuses = ['active' => 'Active', 'missing' => 'Missing', 'archived' => 'Archived'];
                    foreach ($statuses as $val => $label):
                    ?>
                    <option value="<?php echo $val; ?>" <?php echo $currentStatus === $val ? 'selected' : ''; ?>>
                        <?php echo $label; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small>Active = Main catalog, Missing = Needs IPK, Archived = Historical only. Use "Post-Shutdown" flag for community apps.</small>
            </div>

            <div class="form-group">
                <label>Vendor ID</label>
                <div style="display:flex;gap:10px;align-items:center;">
                    <?php $currentVendor = (string)($app['vendor_id'] ?? $_POST['vendor_id'] ?? ''); ?>
                    <select name="vendor_id" style="flex:1;">
                        <option value="">&mdash; none &mdash;</option>
                        <?php $vendorFound = false; ?>
                        <?php foreach ($vendors as $v): ?>
                        <?php if ($currentVendor === $v['vendor_id']) { $vendorFound = true; } ?>
                        <option value="<?php echo htmlspecialchars($v['vendor_id']); ?>"<?php echo $currentVendor === $v['vendor_id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($v['author_name']); ?> (<?php echo htmlspecialchars($v['vendor_id']); ?>)</option>
                        <?php endforeach; ?>
                        <?php if ($currentVendor !== '' && !$vendorFound): ?>
                        <option value="<?php echo htmlspecialchars($currentVendor); ?>" selected><?php echo htmlspecialchars($currentVendor); ?> (not in Vendors list)</option>
                        <?php endif; ?>
                    </select>
                    <a href="vendors.php" class="btn btn-sm" target="_blank" rel="noopener">Manage Vendors</a>
                </div>
                <small>Links to vendor metadata (optional)</small>
            </div>

            <?php if ($canEditAll): ?>
            <div class="form-group">
                <label>Owner Account</label>
                <select name="owner_account_id">
                    <option value="">&mdash; none &mdash;</option>
                    <?php $currentOwner = (string)($app['owner_account_id'] ?? $_POST['owner_account_id'] ?? ''); ?>
                    <?php foreach ($accounts as $acct): ?>
                    <option value="<?php echo (int)$acct['id']; ?>"<?php echo ($currentOwner === (string)$acct['id']) ? ' selected' : ''; ?>><?php echo htmlspecialchars($acct['username']); ?></option>
                    <?php endforeach; ?>
                </select>
                <small>The user account that owns/submitted this app (optional)</small>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>App Icon Path</label>
                <input type="text" name="app_icon" value="<?php echo htmlspecialchars($app['app_icon'] ?? $_POST['app_icon'] ?? ''); ?>">
                <small>Relative path to small icon (e.g., "123/icon.png")</small>
            </div>

            <div class="form-group">
                <label>App Icon Big Path</label>
                <input type="text" name="app_icon_big" value="<?php echo htmlspecialchars($app['app_icon_big'] ?? $_POST['app_icon_big'] ?? ''); ?>">
                <small>Relative path to large icon (e.g., "123/icon-256.png")</small>
            </div>

            <fieldset>
                <legend>Device Compatibility</legend>
                <?php
                $devices = [
                    'pixi' => 'Pixi',
                    'pre' => 'Pre',
                    'pre2' => 'Pre2',
                    'pre3' => 'Pre3',
                    'veer' => 'Veer',
                    'touchpad' => 'TouchPad',
                    'touchpad_exclusive' => 'TouchPad Exclusive',
                    'luneos' => 'LuneOS'
                ];
                foreach ($devices as $field => $label):
                    $checked = isset($_POST[$field]) ? $_POST[$field] : ($app[$field] ?? false);
                ?>
                <label>
                    <input type="checkbox" name="<?php echo $field; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                    <?php echo $label; ?>
                </label>
                <?php endforeach; ?>
            </fieldset>

            <fieldset>
                <legend>Content Flags</legend>
                <label>
                    <input type="checkbox" name="adult" <?php echo ($app['adult'] ?? $_POST['adult'] ?? false) ? 'checked' : ''; ?>>
                    Adult Content
                </label>
                <label>
                    <input type="checkbox" name="post_shutdown" <?php echo ($app['post_shutdown'] ?? $_POST['post_shutdown'] ?? false) ? 'checked' : ''; ?>>
                    Post-Shutdown
                </label>
                <br><small>Community-created app after platform EOL</small>
            </fieldset>

            <?php if ($canEditAll): // curation fields are ignored on save for owner-only accounts ?>
            <fieldset>
                <legend>Featured In (Virtual Categories)</legend>
                <label>
                    <input type="checkbox" name="in_revisionist_history" <?php echo ($app['in_revisionist_history'] ?? $_POST['in_revisionist_history'] ?? false) ? 'checked' : ''; ?>>
                    Revisionist History
                </label>
                <label>
                    <input type="checkbox" name="in_curators_choice" <?php echo ($app['in_curators_choice'] ?? $_POST['in_curators_choice'] ?? false) ? 'checked' : ''; ?>>
                    Curator's Choice
                </label>
                <br><small>Apps can appear in these AND their real category</small>
            </fieldset>

            <fieldset>
                <legend>Recommendation</legend>
                <div class="form-group" style="margin:0;">
                    <label>Recommendation Order</label>
                    <input type="number" name="recommendation_order" min="0" value="<?php echo htmlspecialchars($app['recommendation_order'] ?? $_POST['recommendation_order'] ?? '0'); ?>" style="width:120px;">
                    <small>Higher number = higher recommendation. 0 = not featured.</small>
                </div>
            </fieldset>
            <?php endif; ?>

            <?php if (!$isNew): ?>
            <fieldset id="related-apps">
                <legend>Related Apps</legend>
                <?php if (!empty($relatedApps)): ?>
                <table class="admin-table" style="margin-bottom:15px;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Author</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($relatedApps as $related): ?>
                        <tr>
                            <td><?php echo $related['id']; ?></td>
                            <td><a href="app-edit.php?id=<?php echo $related['id']; ?>"><?php echo htmlspecialchars($related['title']); ?></a></td>
                            <td><?php echo htmlspecialchars($related['author']); ?></td>
                            <td>
                                <a href="app-edit.php?id=<?php echo $id; ?>&action=remove_related&related_id=<?php echo $related['id']; ?>"
                                   class="btn btn-sm btn-danger"
                                   onclick="return confirm('Remove this related app?');">Remove</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p><em>No related apps linked yet.</em></p>
                <?php endif; ?>

                <div class="form-group" style="margin:0;">
                    <label>Add Related App by ID</label>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <input type="number" id="add_related_id" min="1" placeholder="Enter App ID" style="width:150px;">
                        <button type="button" class="btn btn-sm" onclick="addRelatedApp()">Add</button>
                    </div>
                    <small>Enter the ID of an app to link as related. Relationships are bidirectional.</small>
                </div>
                <script>
                function addRelatedApp() {
                    var relatedId = document.getElementById('add_related_id').value;
                    if (relatedId && relatedId > 0) {
                        window.location.href = 'app-edit.php?id=<?php echo $id; ?>&action=add_related&related_id=' + relatedId;
                    } else {
                        alert('Please enter a valid App ID');
                    }
                }
                </script>
            </fieldset>
            <?php endif; ?>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?php echo $isNew ? 'Create App' : 'Save Changes'; ?></button>
                <?php admin_app_quick_actions($id, $isNew, $applicationId); ?>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
