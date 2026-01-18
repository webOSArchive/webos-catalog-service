<?php
/**
 * App List / Search Page
 */
$pageTitle = 'Apps';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/AppRepository.php';

$db = Database::getInstance()->getConnection();
$repo = new AppRepository();

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$category = isset($_GET['category']) ? $_GET['category'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'title';
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$perPage = 50;

// Get apps with filters
$apps = $repo->adminSearch([
    'search' => $search,
    'status' => $status,
    'category' => $category,
    'sort' => $sort,
    'page' => $page,
    'perPage' => $perPage
]);

$totalCount = $repo->adminSearchCount([
    'search' => $search,
    'status' => $status,
    'category' => $category
]);

$totalPages = ceil($totalCount / $perPage);

// Get categories for filter dropdown
$categories = $repo->getCategories();

include 'includes/header.php';
?>

<div class="page-header">
    <h1>Apps <small style="color:#7f8c8d;font-size:0.6em">(<?php echo number_format($totalCount); ?> total)</small></h1>
    <a href="app-edit.php" class="btn btn-primary">Add New App</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="get" class="search-form">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by title, author, or ID...">

            <select name="status">
                <option value="">All Statuses</option>
                <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="post_eol" <?php echo $status === 'post_eol' ? 'selected' : ''; ?>>Post-EOL</option>
                <option value="missing" <?php echo $status === 'missing' ? 'selected' : ''; ?>>Missing</option>
                <option value="archived" <?php echo $status === 'archived' ? 'selected' : ''; ?>>Archived</option>
            </select>

            <select name="category">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat['name']); ?>" <?php echo $category === $cat['name'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="sort">
                <option value="title" <?php echo $sort === 'title' ? 'selected' : ''; ?>>Sort: Title</option>
                <option value="recommendation" <?php echo $sort === 'recommendation' ? 'selected' : ''; ?>>Sort: Recommendation</option>
                <option value="id" <?php echo $sort === 'id' ? 'selected' : ''; ?>>Sort: ID (newest)</option>
            </select>

            <button type="submit" class="btn">Search</button>
            <?php if ($search || $status || $category): ?>
            <a href="apps.php" class="btn">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding:0;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Icon</th>
                    <th>Title</th>
                    <th>Author</th>
                    <th>Category</th>
                    <th>Rec.</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($apps)): ?>
                <tr>
                    <td colspan="8" style="text-align:center;padding:40px;color:#7f8c8d;">
                        No apps found matching your criteria.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($apps as $app): ?>
                <tr>
                    <td><?php echo htmlspecialchars($app['id']); ?></td>
                    <td>
                        <?php if (!empty($app['appIcon'])): ?>
                        <img src="<?php echo htmlspecialchars($app['appIcon']); ?>" alt="" onerror="this.style.display='none'">
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($app['title']); ?></td>
                    <td><?php echo htmlspecialchars($app['author']); ?></td>
                    <td><?php echo htmlspecialchars($app['category'] ?? '-'); ?></td>
                    <td><?php echo (int)$app['recommendation_order']; ?></td>
                    <td><span class="status-badge status-<?php echo $app['status']; ?>"><?php echo $app['status']; ?></span></td>
                    <td>
                        <a href="app-edit.php?id=<?php echo $app['id']; ?>" class="btn btn-sm">Edit</a>
                        <a href="metadata-edit.php?id=<?php echo $app['id']; ?>" class="btn btn-sm">Metadata</a>
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
    // Build query string for pagination links
    $queryParams = [];
    if ($search) $queryParams['search'] = $search;
    if ($status) $queryParams['status'] = $status;
    if ($category) $queryParams['category'] = $category;
    if ($sort && $sort !== 'title') $queryParams['sort'] = $sort;
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
