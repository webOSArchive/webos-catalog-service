<?php
/**
 * App Edit / Create Page
 */
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/AppRepository.php';

$db = Database::getInstance()->getConnection();
$repo = new AppRepository();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$app = $id ? $repo->getById($id) : null;
$isNew = !$app;

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

include 'includes/header.php';
?>

<div class="page-header">
    <h1><?php echo $pageTitle; ?><?php echo $id ? " (ID: $id)" : ''; ?></h1>
    <a href="apps.php" class="btn">Back to Apps</a>
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
                <input type="text" name="vendor_id" value="<?php echo htmlspecialchars($app['vendor_id'] ?? $_POST['vendor_id'] ?? ''); ?>">
                <small>Links to author metadata (optional)</small>
            </div>

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
                <a href="apps.php" class="btn">Cancel</a>
                <?php if (!$isNew): ?>
                <a href="metadata-edit.php?id=<?php echo $id; ?>" class="btn">Edit Metadata</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
