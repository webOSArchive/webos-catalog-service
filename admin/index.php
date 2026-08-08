<?php
/**
 * Admin Dashboard
 */
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/security.php';

// This dashboard has editing shortcuts (Add New App, per-row Edit links, etc.);
// accounts without apps.edit get the read-only Stats view instead.
if (!admin_has_capability('apps.edit')) {
    header('Location: stats.php');
    exit;
}

require_once __DIR__ . '/includes/dashboard-stats.php';
$dashboard = admin_get_dashboard_stats();
$stats = $dashboard['stats'];
$recentApps = $dashboard['recentApps'];

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
        <h3><?php echo number_format($stats['post_shutdown_apps']); ?></h3>
        <p>Post-Shutdown</p>
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
                    <td><?php echo $app['last_modified_time'] ? date('M j, Y', strtotime($app['last_modified_time'])) : '-'; ?></td>
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
            <a href="vendors.php" class="btn">Manage Vendors</a>
            <a href="logs.php" class="btn">View Logs</a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
