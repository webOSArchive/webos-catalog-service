<?php
/**
 * Categories Management Page
 */
require_once __DIR__ . '/includes/security.php';
$pageTitle = 'Categories';
require_once __DIR__ . '/../includes/Database.php';

$db = Database::getInstance()->getConnection();
$errors = [];
$success = false;

// Handle add category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $name = trim($_POST['name'] ?? '');
        $order = (int)($_POST['display_order'] ?? 0);

        if (empty($name)) {
            $errors[] = 'Category name is required';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO categories (name, display_order) VALUES (?, ?)");
                $stmt->execute([$name, $order]);
                $success = true;
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $errors[] = 'Category already exists';
                } else {
                    $errors[] = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }

    if ($_POST['action'] === 'update') {
        $id = (int)$_POST['id'];
        $order = (int)$_POST['display_order'];
        try {
            $stmt = $db->prepare("UPDATE categories SET display_order = ? WHERE id = ?");
            $stmt->execute([$order, $id]);
            $success = true;
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Get categories with app counts
$stmt = $db->query("
    SELECT c.*, COUNT(a.id) as app_count
    FROM categories c
    LEFT JOIN apps a ON a.category_id = c.id
    GROUP BY c.id
    ORDER BY c.display_order, c.name
");
$categories = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="page-header">
    <h1>Categories</h1>
</div>

<?php if ($success): ?>
<div class="alert alert-success">Category saved successfully!</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
    <?php foreach ($errors as $error): ?>
    <?php echo htmlspecialchars($error); ?><br>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Add New Category</h2>
    </div>
    <div class="card-body">
        <form method="post" style="display:flex;gap:10px;align-items:flex-end;">
            <input type="hidden" name="action" value="add">
            <div class="form-group" style="margin:0;flex:1;">
                <label>Category Name</label>
                <input type="text" name="name" required>
            </div>
            <div class="form-group" style="margin:0;width:120px;">
                <label>Display Order</label>
                <input type="number" name="display_order" value="0">
            </div>
            <button type="submit" class="btn btn-primary">Add Category</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>All Categories</h2>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>App Count</th>
                    <th>Display Order</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $cat): ?>
                <tr>
                    <td><?php echo $cat['id']; ?></td>
                    <td><?php echo htmlspecialchars($cat['name']); ?></td>
                    <td><?php echo number_format($cat['app_count']); ?></td>
                    <td>
                        <form method="post" style="display:flex;gap:5px;">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                            <input type="number" name="display_order" value="<?php echo $cat['display_order']; ?>" style="width:60px;padding:4px;">
                            <button type="submit" class="btn btn-sm">Update</button>
                        </form>
                    </td>
                    <td>
                        <a href="apps.php?category=<?php echo urlencode($cat['name']); ?>" class="btn btn-sm">View Apps</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
