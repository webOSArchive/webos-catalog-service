<?php
/**
 * Shared query logic for the Logs page (logs.php) and its IP-redacted
 * counterpart for Viewers (logs-basic.php), so the two can't drift apart.
 */

require_once __DIR__ . '/../../includes/Database.php';

function admin_get_logs_data($logType, $dateFrom, $dateTo, $appId, $page, $perPage) {
    $db = Database::getInstance()->getConnection();
    $offset = ($page - 1) * $perPage;

    if ($logType === 'downloads') {
        $table   = 'download_logs';
        $columns = 'dl.id, dl.app_id, dl.source, dl.ip_address, dl.created_at, a.title as app_title, m.public_application_id';
    } else {
        $table   = 'update_check_logs';
        $columns = 'dl.id, dl.app_id, dl.device_data, dl.client_info, dl.ip_address, dl.created_at, a.title as app_title, m.public_application_id';
    }
    $join  = 'LEFT JOIN apps a ON dl.app_id = a.id LEFT JOIN app_metadata m ON a.id = m.app_id';
    $alias = 'dl';

    $where  = ["$alias.created_at >= ?", "$alias.created_at < DATE_ADD(?, INTERVAL 1 DAY)"];
    $params = [$dateFrom, $dateTo];
    if ($appId) {
        $where[]  = "$alias.app_id = ?";
        $params[] = $appId;
    }
    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM $table $alias $whereClause");
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();

    $sql = "SELECT $columns FROM $table $alias $join $whereClause ORDER BY $alias.created_at DESC LIMIT $perPage OFFSET $offset";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT COUNT(*) FROM download_logs WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)");
    $stmt->execute([$dateFrom, $dateTo]);
    $downloadsInPeriod = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM update_check_logs WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)");
    $stmt->execute([$dateFrom, $dateTo]);
    $updateChecksInPeriod = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT dl.app_id, a.title, m.public_application_id, COUNT(*) as download_count
        FROM download_logs dl
        LEFT JOIN apps a ON dl.app_id = a.id
        LEFT JOIN app_metadata m ON a.id = m.app_id
        WHERE dl.created_at >= ? AND dl.created_at < DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY dl.app_id
        ORDER BY download_count DESC
        LIMIT 10
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $topDownloads = $stmt->fetchAll();

    return [
        'logs'        => $logs,
        'totalCount'  => $totalCount,
        'totalPages'  => ceil($totalCount / $perPage),
        'stats'       => [
            'downloads_in_period'      => $downloadsInPeriod,
            'update_checks_in_period'  => $updateChecksInPeriod,
        ],
        'topDownloads' => $topDownloads,
    ];
}
