<?php
/**
 * Shared stat queries for the admin Dashboard (index.php) and the read-only
 * Stats view (stats.php), so the two pages can't drift out of sync.
 */

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/LogRepository.php';
require_once __DIR__ . '/../../includes/SessionRepository.php';

function admin_get_dashboard_stats() {
    $db = Database::getInstance()->getConnection();
    $logRepo = new LogRepository();
    $sessionRepo = new SessionRepository();

    $stats = [];

    // App counts by status
    $stmt = $db->query("SELECT status, COUNT(*) as count FROM apps GROUP BY status");
    $statusCounts = ['active' => 0, 'missing' => 0, 'archived' => 0];
    while ($row = $stmt->fetch()) {
        if (isset($statusCounts[$row['status']])) {
            $statusCounts[$row['status']] = (int)$row['count'];
        }
    }

    // Post-shutdown (community) apps count
    $stmt = $db->query("SELECT COUNT(*) FROM apps WHERE post_shutdown = 1");
    $postShutdownCount = (int)$stmt->fetchColumn();

    $stats['total_apps'] = array_sum($statusCounts);
    $stats['active_apps'] = $statusCounts['active'];
    $stats['post_shutdown_apps'] = $postShutdownCount;
    $stats['missing_apps'] = $statusCounts['missing'];

    // Metadata count
    $stmt = $db->query("SELECT COUNT(*) FROM app_metadata");
    $stats['metadata_count'] = (int)$stmt->fetchColumn();

    // Download stats
    $stats['total_downloads'] = $logRepo->getTotalDownloadCount();
    $stats['today_downloads'] = $logRepo->getTodayDownloadCount();

    // Session stats
    $stats['active_sessions'] = $sessionRepo->getActiveSessionCount();

    // Recent apps (last 10 updated, by app_metadata.last_modified_time - the
    // catalog's "recent" sort field - not apps.updated_at, which just tracks
    // when the row itself was last written)
    $stmt = $db->query("
        SELECT a.id, a.title, a.author, a.status, m.last_modified_time, c.name as category
        FROM apps a
        LEFT JOIN categories c ON a.category_id = c.id
        LEFT JOIN app_metadata m ON a.id = m.app_id
        ORDER BY m.last_modified_time IS NULL, m.last_modified_time DESC
        LIMIT 10
    ");
    $recentApps = $stmt->fetchAll();

    return ['stats' => $stats, 'recentApps' => $recentApps];
}
