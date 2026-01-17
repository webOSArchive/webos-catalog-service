<?php
/**
 * Logs Viewer Page
 */
$pageTitle = 'Logs';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/LogRepository.php';

$db = Database::getInstance()->getConnection();
$logRepo = new LogRepository();

// Get filter parameters
$logType = isset($_GET['type']) ? $_GET['type'] : 'downloads';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-7 days'));
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$appId = isset($_GET['app_id']) ? (int)$_GET['app_id'] : null;
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$perPage = 100;
$offset = ($page - 1) * $perPage;

// Build query based on log type
if ($logType === 'downloads') {
    $table = 'download_logs';
    $columns = 'dl.id, dl.app_id, dl.source, dl.ip_address, dl.created_at, a.title as app_title';
    $join = 'LEFT JOIN apps a ON dl.app_id = a.id';
    $alias = 'dl';
} else {
    $table = 'update_check_logs';
    $columns = 'dl.id, dl.app_id, dl.device_data, dl.client_info, dl.ip_address, dl.created_at, a.title as app_title';
    $join = 'LEFT JOIN apps a ON dl.app_id = a.id';
    $alias = 'dl';
}

$where = ["$alias.created_at >= ?", "$alias.created_at < DATE_ADD(?, INTERVAL 1 DAY)"];
$params = [$dateFrom, $dateTo];

if ($appId) {
    $where[] = "$alias.app_id = ?";
    $params[] = $appId;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// Get total count
$countSql = "SELECT COUNT(*) FROM $table $alias $whereClause";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

// Get logs
$sql = "SELECT $columns FROM $table $alias $join $whereClause ORDER BY $alias.created_at DESC LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get summary stats
$stats = [];

// Total downloads in period
$stmt = $db->prepare("SELECT COUNT(*) FROM download_logs WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)");
$stmt->execute([$dateFrom, $dateTo]);
$stats['downloads_in_period'] = (int)$stmt->fetchColumn();

// Total update checks in period
$stmt = $db->prepare("SELECT COUNT(*) FROM update_check_logs WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)");
$stmt->execute([$dateFrom, $dateTo]);
$stats['update_checks_in_period'] = (int)$stmt->fetchColumn();

// Top downloaded apps in period
$stmt = $db->prepare("
    SELECT dl.app_id, a.title, COUNT(*) as download_count
    FROM download_logs dl
    LEFT JOIN apps a ON dl.app_id = a.id
    WHERE dl.created_at >= ? AND dl.created_at < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY dl.app_id
    ORDER BY download_count DESC
    LIMIT 10
");
$stmt->execute([$dateFrom, $dateTo]);
$topDownloads = $stmt->fetchAll();

include 'includes/header.php';
?>

<div class="page-header">
    <h1>Logs</h1>
</div>

<div class="card">
    <div class="card-header">
        <h2>Filter Logs</h2>
    </div>
    <div class="card-body">
        <form method="get" class="search-form">
            <select name="type">
                <option value="downloads" <?php echo $logType === 'downloads' ? 'selected' : ''; ?>>Downloads</option>
                <option value="update_checks" <?php echo $logType === 'update_checks' ? 'selected' : ''; ?>>Update Checks</option>
            </select>

            <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
            <span style="align-self:center;">to</span>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">

            <input type="number" name="app_id" value="<?php echo $appId ?: ''; ?>" placeholder="App ID (optional)" style="width:120px;">

            <button type="submit" class="btn">Filter</button>
            <a href="logs.php" class="btn">Reset</a>
        </form>
    </div>
</div>

<div class="stats-grid" style="margin-bottom:20px;">
    <div class="stat-card">
        <h3><?php echo number_format($stats['downloads_in_period']); ?></h3>
        <p>Downloads in Period</p>
    </div>
    <div class="stat-card">
        <h3><?php echo number_format($stats['update_checks_in_period']); ?></h3>
        <p>Update Checks in Period</p>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:20px;">
    <div class="card">
        <div class="card-header">
            <h2><?php echo $logType === 'downloads' ? 'Download Logs' : 'Update Check Logs'; ?>
                <small style="color:#7f8c8d;font-size:0.7em">(<?php echo number_format($totalCount); ?> records)</small>
            </h2>
        </div>
        <div class="card-body" style="padding:0;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>App</th>
                        <?php if ($logType === 'downloads'): ?>
                        <th>Source</th>
                        <?php else: ?>
                        <th>Device</th>
                        <th>Client</th>
                        <?php endif; ?>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="<?php echo $logType === 'downloads' ? 4 : 5; ?>" style="text-align:center;padding:40px;color:#7f8c8d;">
                            No logs found for this period.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td style="white-space:nowrap;"><?php echo date('M j, H:i', strtotime($log['created_at'])); ?></td>
                        <td>
                            <?php if ($log['app_id']): ?>
                            <a href="app-edit.php?id=<?php echo $log['app_id']; ?>">
                                <?php echo htmlspecialchars($log['app_title'] ?? "ID: {$log['app_id']}"); ?>
                            </a>
                            <?php else: ?>
                            -
                            <?php endif; ?>
                        </td>
                        <?php if ($logType === 'downloads'): ?>
                        <td><?php echo htmlspecialchars($log['source'] ?? '-'); ?></td>
                        <?php else: ?>
                        <td><?php echo htmlspecialchars($log['device_data'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($log['client_info'] ?? '-'); ?></td>
                        <?php endif; ?>
                        <td><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Top Downloads</h2>
        </div>
        <div class="card-body" style="padding:0;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>App</th>
                        <th>Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($topDownloads)): ?>
                    <tr>
                        <td colspan="2" style="text-align:center;padding:20px;color:#7f8c8d;">
                            No downloads in period.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($topDownloads as $row): ?>
                    <tr>
                        <td>
                            <a href="app-edit.php?id=<?php echo $row['app_id']; ?>">
                                <?php echo htmlspecialchars($row['title'] ?? "ID: {$row['app_id']}"); ?>
                            </a>
                        </td>
                        <td><?php echo number_format($row['download_count']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php
    $queryParams = ['type' => $logType, 'date_from' => $dateFrom, 'date_to' => $dateTo];
    if ($appId) $queryParams['app_id'] = $appId;
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
