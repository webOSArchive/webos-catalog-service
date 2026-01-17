<?php
/**
 * Log Repository - Database access for download and update check logs
 *
 * Replaces file-based logging (logs/downloadcount.log, logs/updatecheck.log)
 * with database storage for better querying and reporting.
 */
require_once __DIR__ . '/Database.php';

class LogRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ============ Download Logging ============

    /**
     * Log an app download - replaces countAppDownload.php file logging
     *
     * @param string $appIdentifier App ID or identifier string
     * @param string $source Download source (default: 'app')
     * @param string|null $ipAddress Client IP
     * @param string|null $userAgent User agent string
     * @return bool Success
     */
    public function logDownload($appIdentifier, $source = 'app', $ipAddress = null, $userAgent = null) {
        // Try to get numeric app_id if identifier is numeric
        $appId = is_numeric($appIdentifier) ? (int)$appIdentifier : null;

        $sql = "
            INSERT INTO download_logs (app_id, app_identifier, source, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$appId, $appIdentifier, $source, $ipAddress, $userAgent]);
    }

    /**
     * Get download statistics - for reports/getdownloaddata.php
     *
     * @return array Download statistics
     */
    public function getDownloadStats() {
        $stats = [
            'firstDate' => null,
            'lastDate' => null,
            'totalDownloads' => 0,
            'topApps' => [],
            'topClients' => []
        ];

        // Get date range
        $stmt = $this->db->query("
            SELECT
                MIN(created_at) as first_date,
                MAX(created_at) as last_date,
                COUNT(*) as total
            FROM download_logs
        ");
        $range = $stmt->fetch();
        $stats['firstDate'] = $range['first_date'];
        $stats['lastDate'] = $range['last_date'];
        $stats['totalDownloads'] = (int)$range['total'];

        // Get top apps (top 20)
        $stmt = $this->db->query("
            SELECT
                app_identifier AS appId,
                COUNT(*) as count
            FROM download_logs
            GROUP BY app_identifier
            ORDER BY count DESC
            LIMIT 20
        ");

        $rank = 1;
        while ($row = $stmt->fetch()) {
            $stats['topApps'][(string)$rank] = [
                'appId' => $row['appId'],
                'appName' => $row['appId'], // Will be enriched by caller
                'count' => (int)$row['count']
            ];
            $rank++;
        }

        // Get top clients (top 10) based on user agent
        $stmt = $this->db->query("
            SELECT
                CASE
                    WHEN user_agent LIKE '%Windows 10%' THEN 'Windows 10'
                    WHEN user_agent LIKE '%Windows 7%' THEN 'Windows 7'
                    WHEN user_agent LIKE '%Linux x86_64%' THEN 'Linux PC'
                    WHEN user_agent LIKE '%Smart TV%' THEN 'Linux Smart TV'
                    WHEN user_agent LIKE '%ChromeOS%' THEN 'ChromeOS'
                    WHEN user_agent LIKE '%Mac%' THEN 'Mac'
                    WHEN user_agent LIKE '%Android%' THEN 'Android'
                    WHEN user_agent LIKE '%iPhone%' THEN 'iPhone'
                    WHEN user_agent LIKE '%webOS%' OR user_agent LIKE '%hpwOS%' THEN 'webOS'
                    WHEN user_agent LIKE '%LuneOS%' THEN 'LuneOS'
                    ELSE 'Other'
                END as client_type,
                COUNT(*) as count
            FROM download_logs
            WHERE user_agent IS NOT NULL
            GROUP BY client_type
            ORDER BY count DESC
            LIMIT 10
        ");

        $rank = 1;
        while ($row = $stmt->fetch()) {
            $stats['topClients'][(string)$rank] = [
                'clientString' => $row['client_type'],
                'count' => (int)$row['count']
            ];
            $rank++;
        }

        return $stats;
    }

    /**
     * Get downloads by date range
     *
     * @param string $startDate Start date (Y-m-d)
     * @param string $endDate End date (Y-m-d)
     * @return array Daily download counts
     */
    public function getDownloadsByDateRange($startDate, $endDate) {
        $stmt = $this->db->prepare("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM download_logs
            WHERE created_at BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY date
        ");
        $stmt->execute([$startDate, $endDate . ' 23:59:59']);

        return $stmt->fetchAll();
    }

    // ============ Update Check Logging ============

    /**
     * Log an update check - replaces getLatestVersionInfo.php file logging
     *
     * @param string $appName App name or identifier
     * @param string|null $deviceData Device information
     * @param string|null $clientInfo Client information
     * @param string|null $clientId Unique client ID
     * @param string|null $ipAddress Client IP
     * @return bool Success
     */
    public function logUpdateCheck($appName, $deviceData = null, $clientInfo = null, $clientId = null, $ipAddress = null) {
        // Try to find app_id by name
        $appId = null;
        if (is_numeric($appName)) {
            $appId = (int)$appName;
        }

        $sql = "
            INSERT INTO update_check_logs (app_id, app_name, device_data, client_info, client_id, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$appId, $appName, $deviceData, $clientInfo, $clientId, $ipAddress]);
    }

    /**
     * Get update check statistics - for reports/getupdatedata.php
     *
     * @return array Update check statistics
     */
    public function getUpdateCheckStats() {
        $stats = [
            'firstDate' => null,
            'lastDate' => null,
            'totalChecks' => 0,
            'uniqueDevices' => 0,
            'topApps' => [],
            'topDevices' => [],
            'topOSVersions' => []
        ];

        // Get date range and totals
        $stmt = $this->db->query("
            SELECT
                MIN(created_at) as first_date,
                MAX(created_at) as last_date,
                COUNT(*) as total,
                COUNT(DISTINCT client_id) as unique_clients
            FROM update_check_logs
        ");
        $range = $stmt->fetch();
        $stats['firstDate'] = $range['first_date'];
        $stats['lastDate'] = $range['last_date'];
        $stats['totalChecks'] = (int)$range['total'];
        $stats['uniqueDevices'] = (int)$range['unique_clients'];

        // Get top apps (excluding specific apps like "family chat")
        $stmt = $this->db->query("
            SELECT
                app_name,
                COUNT(*) as count,
                COUNT(DISTINCT client_id) as unique_devices
            FROM update_check_logs
            WHERE LOWER(app_name) NOT IN ('family chat')
            GROUP BY app_name
            ORDER BY count DESC
            LIMIT 10
        ");

        $rank = 1;
        while ($row = $stmt->fetch()) {
            $stats['topApps'][(string)$rank] = [
                'appName' => $row['app_name'],
                'count' => (int)$row['count'],
                'uniqueDevices' => (int)$row['unique_devices']
            ];
            $rank++;
        }

        // Get top devices
        $stmt = $this->db->query("
            SELECT
                CASE
                    WHEN device_data LIKE '%Veer%' THEN 'Veer'
                    WHEN device_data LIKE '%TouchPad%' THEN 'TouchPad'
                    WHEN device_data LIKE '%Prē%' OR device_data LIKE '%Pre2%' THEN 'Pre2'
                    WHEN device_data LIKE '%Pre%' THEN 'Pre'
                    WHEN device_data LIKE '%Pixi%' THEN 'Pixi'
                    ELSE 'Unknown'
                END as device_type,
                COUNT(*) as count,
                COUNT(DISTINCT client_id) as unique_devices
            FROM update_check_logs
            WHERE device_data IS NOT NULL
            GROUP BY device_type
            ORDER BY count DESC
            LIMIT 10
        ");

        $rank = 1;
        while ($row = $stmt->fetch()) {
            $stats['topDevices'][(string)$rank] = [
                'deviceString' => $row['device_type'],
                'count' => (int)$row['count'],
                'uniqueDevices' => (int)$row['unique_devices']
            ];
            $rank++;
        }

        // Get top OS versions (extract from user agent in client_info)
        $stmt = $this->db->query("
            SELECT
                CASE
                    WHEN client_info LIKE '%webOS/2%' THEN 'webOS 2.x'
                    WHEN client_info LIKE '%webOS/1%' THEN 'webOS 1.x'
                    WHEN client_info LIKE '%webOS/3%' THEN 'webOS 3.x'
                    WHEN client_info LIKE '%hpwOS/3%' THEN 'hpwOS 3.x'
                    WHEN client_info LIKE '%LuneOS%' THEN 'LuneOS'
                    ELSE 'Unknown'
                END as os_version,
                COUNT(*) as count,
                COUNT(DISTINCT client_id) as unique_devices
            FROM update_check_logs
            WHERE client_info IS NOT NULL
            GROUP BY os_version
            ORDER BY count DESC
            LIMIT 10
        ");

        $rank = 1;
        while ($row = $stmt->fetch()) {
            $stats['topOSVersions'][(string)$rank] = [
                'osVersionString' => $row['os_version'],
                'count' => (int)$row['count'],
                'uniqueDevices' => (int)$row['unique_devices']
            ];
            $rank++;
        }

        return $stats;
    }

    // ============ Geographic Data ============

    /**
     * Get geographic statistics - for reports/getgeodata.php
     * Note: This requires IP geolocation data to be stored or looked up
     *
     * @return array Geographic statistics
     */
    public function getGeoStats() {
        // This is a placeholder - actual implementation would need
        // IP geolocation integration (either via external service or local database)
        $stats = [
            'firstDate' => null,
            'lastDate' => null,
            'regionRecords' => 0,
            'uniqueRegions' => 0,
            'topRegions' => []
        ];

        // Get date range
        $stmt = $this->db->query("
            SELECT
                MIN(created_at) as first_date,
                MAX(created_at) as last_date,
                COUNT(*) as total,
                COUNT(DISTINCT ip_address) as unique_ips
            FROM update_check_logs
            WHERE ip_address IS NOT NULL
        ");
        $range = $stmt->fetch();
        $stats['firstDate'] = $range['first_date'];
        $stats['lastDate'] = $range['last_date'];
        $stats['regionRecords'] = (int)$range['total'];

        return $stats;
    }

    // ============ Admin Methods ============

    /**
     * Get recent downloads for admin view
     *
     * @param int $limit Number of records
     * @return array Recent downloads
     */
    public function getRecentDownloads($limit = 50) {
        $stmt = $this->db->prepare("
            SELECT d.*, a.title as app_title
            FROM download_logs d
            LEFT JOIN apps a ON d.app_id = a.id
            ORDER BY d.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get recent update checks for admin view
     *
     * @param int $limit Number of records
     * @return array Recent update checks
     */
    public function getRecentUpdateChecks($limit = 50) {
        $stmt = $this->db->prepare("
            SELECT *
            FROM update_check_logs
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get download count for today
     *
     * @return int
     */
    public function getTodayDownloadCount() {
        $stmt = $this->db->query("
            SELECT COUNT(*)
            FROM download_logs
            WHERE DATE(created_at) = CURDATE()
        ");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get total download count
     *
     * @return int
     */
    public function getTotalDownloadCount() {
        $stmt = $this->db->query("SELECT COUNT(*) FROM download_logs");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get total update check count
     *
     * @return int
     */
    public function getTotalUpdateCheckCount() {
        $stmt = $this->db->query("SELECT COUNT(*) FROM update_check_logs");
        return (int)$stmt->fetchColumn();
    }
}
