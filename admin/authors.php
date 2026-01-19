<?php
/**
 * Authors/Vendors Management Page
 */
require_once __DIR__ . '/includes/security.php';
$pageTitle = 'Authors';
require_once __DIR__ . '/../includes/Database.php';

$db = Database::getInstance()->getConnection();
$errors = [];
$success = false;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $vendorId = trim($_POST['vendor_id'] ?? '');
        $authorName = trim($_POST['author_name'] ?? '');
        $summary = trim($_POST['summary'] ?? '');
        $icon = trim($_POST['icon'] ?? '');
        $iconBig = trim($_POST['icon_big'] ?? '');
        $favicon = trim($_POST['favicon'] ?? '');
        $sponsorMessage = trim($_POST['sponsor_message'] ?? '');
        $sponsorLink = trim($_POST['sponsor_link'] ?? '');
        $socialLinks = trim($_POST['social_links'] ?? '');

        if (empty($vendorId)) {
            $errors[] = 'Vendor ID is required';
        }
        if (empty($authorName)) {
            $errors[] = 'Author name is required';
        }

        if (empty($errors)) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO authors (vendor_id, author_name, summary, icon, icon_big, favicon, sponsor_message, sponsor_link, social_links)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $vendorId,
                    $authorName,
                    $summary ?: null,
                    $icon ?: null,
                    $iconBig ?: null,
                    $favicon ?: null,
                    $sponsorMessage ?: null,
                    $sponsorLink ?: null,
                    $socialLinks ?: null
                ]);
                $success = true;
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $errors[] = 'Vendor ID already exists';
                } else {
                    $errors[] = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }

    if ($_POST['action'] === 'update') {
        $vendorId = $_POST['vendor_id'];
        $authorName = trim($_POST['author_name'] ?? '');
        $summary = trim($_POST['summary'] ?? '');
        $icon = trim($_POST['icon'] ?? '');
        $iconBig = trim($_POST['icon_big'] ?? '');
        $favicon = trim($_POST['favicon'] ?? '');
        $sponsorMessage = trim($_POST['sponsor_message'] ?? '');
        $sponsorLink = trim($_POST['sponsor_link'] ?? '');
        $socialLinks = trim($_POST['social_links'] ?? '');

        if (empty($authorName)) {
            $errors[] = 'Author name is required';
        }

        if (empty($errors)) {
            try {
                $stmt = $db->prepare("
                    UPDATE authors
                    SET author_name = ?, summary = ?, icon = ?, icon_big = ?, favicon = ?, sponsor_message = ?, sponsor_link = ?, social_links = ?
                    WHERE vendor_id = ?
                ");
                $stmt->execute([
                    $authorName,
                    $summary ?: null,
                    $icon ?: null,
                    $iconBig ?: null,
                    $favicon ?: null,
                    $sponsorMessage ?: null,
                    $sponsorLink ?: null,
                    $socialLinks ?: null,
                    $vendorId
                ]);
                $success = true;
            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }

    if ($_POST['action'] === 'delete') {
        $vendorId = $_POST['vendor_id'];
        try {
            $stmt = $db->prepare("DELETE FROM authors WHERE vendor_id = ?");
            $stmt->execute([$vendorId]);
            $success = true;
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Get search/filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Build query
$where = [];
$params = [];

if ($search) {
    $where[] = "(vendor_id LIKE ? OR author_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM authors $whereClause");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

// Get authors with app counts
$sql = "
    SELECT a.*,
           (SELECT COUNT(*) FROM apps WHERE vendor_id = a.vendor_id) as app_count
    FROM authors a
    $whereClause
    ORDER BY a.author_name
    LIMIT $perPage OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$authors = $stmt->fetchAll();

// Get editing author if specified
$editAuthor = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM authors WHERE vendor_id = ?");
    $stmt->execute([$_GET['edit']]);
    $editAuthor = $stmt->fetch();
}

include 'includes/header.php';
?>

<div class="page-header">
    <h1>Authors <small style="color:#7f8c8d;font-size:0.6em">(<?php echo number_format($totalCount); ?> total)</small></h1>
</div>

<?php if ($success): ?>
<div class="alert alert-success">Author saved successfully!</div>
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
        <h2><?php echo $editAuthor ? 'Edit Author' : 'Add New Author'; ?></h2>
    </div>
    <div class="card-body">
        <form method="post" class="admin-form">
            <input type="hidden" name="action" value="<?php echo $editAuthor ? 'update' : 'add'; ?>">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                <div class="form-group" style="margin:0;">
                    <label>Vendor ID *</label>
                    <input type="text" name="vendor_id" value="<?php echo htmlspecialchars($editAuthor['vendor_id'] ?? ''); ?>"
                           <?php echo $editAuthor ? 'readonly' : 'required'; ?>>
                    <?php if ($editAuthor): ?>
                    <small>Vendor ID cannot be changed</small>
                    <?php endif; ?>
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Author Name *</label>
                    <input type="text" name="author_name" value="<?php echo htmlspecialchars($editAuthor['author_name'] ?? ''); ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label>Summary</label>
                <textarea name="summary" rows="3"><?php echo htmlspecialchars($editAuthor['summary'] ?? ''); ?></textarea>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:15px;">
                <div class="form-group" style="margin:0;">
                    <label>Icon Path</label>
                    <input type="text" name="icon" value="<?php echo htmlspecialchars($editAuthor['icon'] ?? ''); ?>" placeholder="icon.jpg">
                    <small>Filename in /authors/{vendor_id}/</small>
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Icon Big Path</label>
                    <input type="text" name="icon_big" value="<?php echo htmlspecialchars($editAuthor['icon_big'] ?? ''); ?>" placeholder="iconBig.png">
                    <small>Filename in /authors/{vendor_id}/</small>
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Favicon Path</label>
                    <input type="text" name="favicon" value="<?php echo htmlspecialchars($editAuthor['favicon'] ?? ''); ?>" placeholder="favicon.ico">
                    <small>Filename in /authors/{vendor_id}/</small>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                <div class="form-group" style="margin:0;">
                    <label>Sponsor Message</label>
                    <input type="text" name="sponsor_message" value="<?php echo htmlspecialchars($editAuthor['sponsor_message'] ?? ''); ?>" placeholder="Like my apps? Buy me a coffee!">
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Sponsor Link</label>
                    <input type="text" name="sponsor_link" value="<?php echo htmlspecialchars($editAuthor['sponsor_link'] ?? ''); ?>" placeholder="https://...">
                </div>
            </div>

            <div class="form-group">
                <label>Social Links (JSON)</label>
                <textarea name="social_links" rows="3" placeholder='["https://github.com/...", "https://twitter.com/..."]'><?php echo htmlspecialchars($editAuthor['social_links'] ?? ''); ?></textarea>
                <small>JSON array of social media URLs</small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?php echo $editAuthor ? 'Update Author' : 'Add Author'; ?></button>
                <?php if ($editAuthor): ?>
                <a href="authors.php" class="btn">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>All Authors</h2>
    </div>
    <div class="card-body">
        <form method="get" class="search-form" style="margin-bottom:15px;">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by vendor ID or name...">
            <button type="submit" class="btn">Search</button>
            <?php if ($search): ?>
            <a href="authors.php" class="btn">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Vendor ID</th>
                    <th>Author Name</th>
                    <th>Apps</th>
                    <th>Icon</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($authors)): ?>
                <tr>
                    <td colspan="5" style="text-align:center;padding:40px;color:#7f8c8d;">
                        No authors found.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($authors as $author): ?>
                <tr>
                    <td><?php echo htmlspecialchars($author['vendor_id']); ?></td>
                    <td><?php echo htmlspecialchars($author['author_name']); ?></td>
                    <td><?php echo number_format($author['app_count']); ?></td>
                    <td>
                        <?php if (!empty($author['icon'])): ?>
                        <img src="<?php echo htmlspecialchars($author['icon']); ?>" alt="" style="max-height:24px;" onerror="this.style.display='none'">
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="?edit=<?php echo urlencode($author['vendor_id']); ?>" class="btn btn-sm">Edit</a>
                        <a href="apps.php?search=<?php echo urlencode($author['author_name']); ?>" class="btn btn-sm">View Apps</a>
                        <?php if ($author['app_count'] == 0): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this author?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="vendor_id" value="<?php echo htmlspecialchars($author['vendor_id']); ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php
    $queryParams = [];
    if ($search) $queryParams['search'] = $search;
    $queryString = http_build_query($queryParams);
    ?>

    <?php if ($page > 1): ?>
    <a href="?page=1&<?php echo $queryString; ?>">&laquo; First</a>
    <a href="?page=<?php echo $page - 1; ?>&<?php echo $queryString; ?>">&lsaquo; Prev</a>
    <?php endif; ?>

    <?php
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    for ($i = $start; $i <= $end; $i++):
    ?>
    <?php if ($i == $page): ?>
    <span class="current"><?php echo $i; ?></span>
    <?php else: ?>
    <a href="?page=<?php echo $i; ?>&<?php echo $queryString; ?>"><?php echo $i; ?></a>
    <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page < $totalPages): ?>
    <a href="?page=<?php echo $page + 1; ?>&<?php echo $queryString; ?>">Next &rsaquo;</a>
    <a href="?page=<?php echo $totalPages; ?>&<?php echo $queryString; ?>">Last &raquo;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
