<?php
/**
 * Session Repository - Database access for museum client sessions
 *
 * Replaces file-based session storage (__museumSessions/*.json)
 * with database storage for better scalability.
 */
require_once __DIR__ . '/Database.php';

class SessionRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get session data by key - replaces getClientRetrievedDataFromKey()
     *
     * @param string $key Session key
     * @return array Session data with 'knownIdx' array
     */
    public function getSession($key) {
        if (empty($key)) {
            return ['knownIdx' => []];
        }

        $stmt = $this->db->prepare("
            SELECT known_indices
            FROM museum_sessions
            WHERE session_key = ?
        ");
        $stmt->execute([$key]);
        $result = $stmt->fetch();

        if (!$result) {
            return ['knownIdx' => []];
        }

        $knownIdx = json_decode($result['known_indices'], true);
        return ['knownIdx' => is_array($knownIdx) ? $knownIdx : []];
    }

    /**
     * Store session data - replaces storeClientRetrievedDataByKey()
     *
     * @param string $key Session key
     * @param array $data Session data with 'knownIdx' array
     * @return bool Success
     */
    public function storeSession($key, $data) {
        if (empty($key)) {
            return false;
        }

        $knownIdx = isset($data['knownIdx']) ? $data['knownIdx'] : [];

        $sql = "
            INSERT INTO museum_sessions (session_key, known_indices)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE
                known_indices = VALUES(known_indices),
                updated_at = CURRENT_TIMESTAMP
        ";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$key, json_encode($knownIdx)]);
    }

    /**
     * Add indices to a session's known list
     *
     * @param string $key Session key
     * @param array $indices Indices to add
     * @return bool Success
     */
    public function addKnownIndices($key, $indices) {
        $session = $this->getSession($key);
        $knownIdx = $session['knownIdx'];

        foreach ($indices as $idx) {
            if (!in_array($idx, $knownIdx)) {
                $knownIdx[] = $idx;
            }
        }

        sort($knownIdx);
        return $this->storeSession($key, ['knownIdx' => $knownIdx]);
    }

    /**
     * Check if an index is known by a session
     *
     * @param string $key Session key
     * @param int $index Index to check
     * @return bool Whether the index is known
     */
    public function isIndexKnown($key, $index) {
        $session = $this->getSession($key);
        return in_array($index, $session['knownIdx']);
    }

    /**
     * Delete a session
     *
     * @param string $key Session key
     * @return bool Success
     */
    public function deleteSession($key) {
        $stmt = $this->db->prepare("DELETE FROM museum_sessions WHERE session_key = ?");
        return $stmt->execute([$key]);
    }

    /**
     * Cleanup old sessions - replaces removeOldClientKeys()
     * Removes sessions older than 2 days
     *
     * @return int Number of deleted sessions
     */
    public function cleanupOldSessions() {
        $stmt = $this->db->prepare("
            DELETE FROM museum_sessions
            WHERE updated_at < DATE_SUB(NOW(), INTERVAL 2 DAY)
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Get session count (for admin stats)
     *
     * @return int
     */
    public function getSessionCount() {
        $stmt = $this->db->query("SELECT COUNT(*) FROM museum_sessions");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get active sessions from last hour (for admin stats)
     *
     * @return int
     */
    public function getActiveSessionCount() {
        $stmt = $this->db->query("
            SELECT COUNT(*)
            FROM museum_sessions
            WHERE updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        return (int)$stmt->fetchColumn();
    }
}
