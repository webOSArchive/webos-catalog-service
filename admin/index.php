<?php
/**
 * Admin Dashboard
 */
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/AppRepository.php';
require_once __DIR__ . '/../includes/LogRepository.php';
require_once __DIR__ . '/../includes/SessionRepository.php';

$db = Database::getInstance()->getConnection();
$appRepo = new AppRepository();
$logRepo = new LogRepository();
$sessionRepo = new SessionRepository();

// Get statistics
$stats = [];

// App counts by status
$stmt = $db->query("SELECT status, COUNT(*) as count FROM apps GROUP BY status");
$statusCounts = ['active' => 0, 'newer' => 0, 'missing' => 0, 'archived' => 0];
while ($row = $stmt->fetch()) {
    $statusCounts[$row['status']] = (int)$row['count'];
}

$stats['total_apps'] = array_sum($statusCounts);
$stats['active_apps'] = $statusCounts['active'];
$stats['newer_apps'] = $statusCounts['newer'];
$stats['missing_apps'] = $statusCounts['missing'];

// Metadata count
$stmt = $db->query("SELECT COUNT(*) FROM app_metadata");
$stats['metadata_count'] = (int)$stmt->fetchColumn();

// Download stats
$stats['total_downloads'] = $logRepo->getTotalDownloadCount();
$stats['today_downloads'] = $logRepo->getTodayDownloadCount();

// Session stats
$stats['active_sessions'] = $sessionRepo->getActiveSessionCount();

// Recent apps (last 10 updated)
$stmt = $db->query("
    SELECT a.id, a.title, a.author, a.status, a.updated_at, c.name as category
    FROM apps a
    LEFT JOIN categories c ON a.category_id = c.id
    ORDER BY a.id DESC
    LIMIT 10
");
$recentApps = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="page-header">
    <h1>Dashboard</h1>
    <div class="quick-actions">
        <a href="app-edit.php" class="btn btn-primary">Add New App</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <h3><?php echo number_format($stats['total_apps']); ?></h3>
        <p>Total Apps</p>
    </div>
    <div class="stat-card success">
        <h3><?php echo number_format($stats['active_apps']); ?></h3>
        <p>Active Catalog</p>
    </div>
    <div class="stat-card">
        <h3><?php echo number_format($stats['newer_apps']); ?></h3>
        <p>Newer Apps</p>
    </div>
    <a href="generate-missing.php" class="stat-card warning" style="text-decoration:none;">
        <h3><?php echo number_format($stats['missing_apps']); ?></h3>
        <p>Missing IPKs</p>
        <small>Click to generate lists</small>
    </a>
    <div class="stat-card">
        <h3><?php echo number_format($stats['metadata_count']); ?></h3>
        <p>Metadata Records</p>
    </div>
    <div class="stat-card">
        <h3><?php echo number_format($stats['total_downloads']); ?></h3>
        <p>Total Downloads</p>
    </div>
    <div class="stat-card success">
        <h3><?php echo number_format($stats['today_downloads']); ?></h3>
        <p>Downloads Today</p>
    </div>
    <div class="stat-card">
        <h3><?php echo number_format($stats['active_sessions']); ?></h3>
        <p>Active Sessions</p>
    </div>
</div>

<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <h2>Utilities</h2>
    </div>
    <div class="card-body">
        <a href="generate-missing.php" class="btn">Generate Wanted Lists</a>
        <a href="export-json.php" class="btn">Export JSON</a>
        <a href="logs.php" class="btn">View Logs</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Recently Updated Apps</h2>
        <a href="apps.php" class="btn btn-sm">View All</a>
    </div>
    <div class="card-body">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Author</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentApps as $app): ?>
                <tr>
                    <td><?php echo htmlspecialchars($app['id']); ?></td>
                    <td><?php echo htmlspecialchars($app['title']); ?></td>
                    <td><?php echo htmlspecialchars($app['author']); ?></td>
                    <td><?php echo htmlspecialchars($app['category'] ?? '-'); ?></td>
                    <td><span class="status-badge status-<?php echo $app['status']; ?>"><?php echo $app['status']; ?></span></td>
                    <td><?php echo date('M j, Y', strtotime($app['updated_at'])); ?></td>
                    <td>
                        <a href="app-edit.php?id=<?php echo $app['id']; ?>" class="btn btn-sm">Edit</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Quick Actions</h2>
    </div>
    <div class="card-body">
        <div class="quick-actions">
            <a href="apps.php" class="btn">Manage Apps</a>
            <a href="app-edit.php" class="btn btn-primary">Add New App</a>
            <a href="categories.php" class="btn">Manage Categories</a>
            <a href="authors.php" class="btn">Manage Authors</a>
            <a href="logs.php" class="btn">View Logs</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
