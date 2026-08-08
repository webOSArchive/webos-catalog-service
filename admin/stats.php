<?php
/**
 * Read-only Stats view - the Dashboard's numbers without any editing
 * shortcuts, for accounts that can log into /admin but lack apps.edit.
 */
$pageTitle = 'Stats';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/dashboard-stats.php';

$dashboard = admin_get_dashboard_stats();
$stats = $dashboard['stats'];
$recentApps = $dashboard['recentApps'];

include 'includes/header.php';
?>

<div class="page-header">
    <h1>Stats</h1>
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
    <div class="stat-card warning">
        <h3><?php echo number_format($stats['missing_apps']); ?></h3>
        <p>Missing IPKs</p>
    </div>
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

<div class="card">
    <div class="card-header">
        <h2>Recently Updated Apps</h2>
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
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
