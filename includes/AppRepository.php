<?php
/**
 * App Repository - Database access for app catalog data
 *
 * Replaces JSON file loading with database queries while maintaining
 * backward compatibility with existing response formats.
 */
require_once __DIR__ . '/Database.php';

class AppRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Load apps from database - replaces load_catalogs()
     *
     * @param array $statuses Which statuses to include ['active', 'newer', etc]
     * @param string $sort Sort order: 'alpha' (default) or 'recommended'
     * @return array Apps in format matching original JSON structure
     */
    public function loadCatalog($statuses = ['active', 'newer'], $sort = 'alpha') {
        $placeholders = str_repeat('?,', count($statuses) - 1) . '?';

        // Determine sort order
        $orderBy = ($sort === 'recommended')
            ? 'a.recommendation_order DESC, a.title'
            : 'a.title';

        $sql = "
            SELECT
                a.id,
                a.title,
                a.author,
                a.summary,
                a.app_icon AS appIcon,
                a.app_icon_big AS appIconBig,
                c.name AS category,
                a.vendor_id AS vendorId,
                a.pixi AS Pixi,
                a.pre AS `Pre`,
                a.pre2 AS Pre2,
                a.pre3 AS Pre3,
                a.veer AS Veer,
                a.touchpad AS TouchPad,
                a.touchpad_exclusive,
                a.luneos AS LuneOS,
                a.adult AS Adult,
                a.recommendation_order AS recommendationOrder,
                a.in_revisionist_history AS inRevisionistHistory,
                a.in_curators_choice AS inCuratorsChoice,
                a.status
            FROM apps a
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE a.status IN ($placeholders)
            ORDER BY $orderBy
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($statuses);

        // Convert boolean fields from int to bool for JSON compatibility
        $results = [];
        while ($row = $stmt->fetch()) {
            $row['Pixi'] = (bool)$row['Pixi'];
            $row['Pre'] = (bool)$row['Pre'];
            $row['Pre2'] = (bool)$row['Pre2'];
            $row['Pre3'] = (bool)$row['Pre3'];
            $row['Veer'] = (bool)$row['Veer'];
            $row['TouchPad'] = (bool)$row['TouchPad'];
            $row['touchpad_exclusive'] = (bool)$row['touchpad_exclusive'];
            $row['LuneOS'] = (bool)$row['LuneOS'];
            $row['Adult'] = (bool)$row['Adult'];
            $row['inRevisionistHistory'] = (bool)$row['inRevisionistHistory'];
            $row['inCuratorsChoice'] = (bool)$row['inCuratorsChoice'];
            $row['id'] = (int)$row['id'];
            $results[] = $row;
        }

        return $results;
    }

    /**
     * Search apps by title/id - replaces search_apps()
     *
     * @param string $searchStr Search term
     * @param bool $adult Whether to include adult content
     * @param array $statuses Which statuses to search
     * @return array Matching apps
     */
    public function searchApps($searchStr, $adult = false, $statuses = ['active', 'newer']) {
        $searchStr = $this->sanitizeSearch($searchStr);
        if (empty($searchStr)) {
            return [];
        }

        $statusPlaceholders = str_repeat('?,', count($statuses) - 1) . '?';

        // Try exact ID match first
        $sql = "
            SELECT
                a.id,
                a.title,
                a.author,
                a.summary,
                a.app_icon AS appIcon,
                a.app_icon_big AS appIconBig,
                c.name AS category,
                a.vendor_id AS vendorId,
                a.pixi AS Pixi,
                a.pre AS `Pre`,
                a.pre2 AS Pre2,
                a.pre3 AS Pre3,
                a.veer AS Veer,
                a.touchpad AS TouchPad,
                a.touchpad_exclusive,
                a.luneos AS LuneOS,
                a.adult AS Adult
            FROM apps a
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE a.status IN ($statusPlaceholders)
        ";

        $params = $statuses;

        // Build search conditions
        $sql .= " AND (
            LOWER(a.title) = LOWER(?)
            OR a.id = ?
            OR LOWER(a.title) LIKE LOWER(?)
            OR LOWER(REPLACE(a.title, ' ', '')) LIKE LOWER(?)
        )";

        $params[] = $searchStr;
        $params[] = is_numeric($searchStr) ? (int)$searchStr : 0;
        $params[] = "%{$searchStr}%";
        $params[] = "%{$searchStr}%";

        if (!$adult) {
            $sql .= " AND a.adult = FALSE";
        }

        // Order by relevance: exact match first, then ID match, then partial
        $sql .= " ORDER BY
            CASE
                WHEN LOWER(a.title) = LOWER(?) THEN 1
                WHEN a.id = ? THEN 2
                ELSE 3
            END,
            a.title";

        $params[] = $searchStr;
        $params[] = is_numeric($searchStr) ? (int)$searchStr : 0;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $this->formatResults($stmt->fetchAll());
    }

    /**
     * Search by author - replaces search_apps_by_author()
     *
     * @param string $authorStr Author name to search
     * @param bool $adult Whether to include adult content
     * @param array $statuses Which statuses to search
     * @return array Matching apps
     */
    public function searchByAuthor($authorStr, $adult = false, $statuses = ['active', 'newer']) {
        $authorStr = $this->sanitizeSearch($authorStr);
        if (empty($authorStr)) {
            return [];
        }

        $statusPlaceholders = str_repeat('?,', count($statuses) - 1) . '?';

        $sql = "
            SELECT
                a.id,
                a.title,
                a.author,
                a.summary,
                a.app_icon AS appIcon,
                a.app_icon_big AS appIconBig,
                c.name AS category,
                a.vendor_id AS vendorId,
                a.pixi AS Pixi,
                a.pre AS `Pre`,
                a.pre2 AS Pre2,
                a.pre3 AS Pre3,
                a.veer AS Veer,
                a.touchpad AS TouchPad,
                a.touchpad_exclusive,
                a.luneos AS LuneOS,
                a.adult AS Adult
            FROM apps a
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE a.status IN ($statusPlaceholders)
              AND (
                  LOWER(a.author) = LOWER(?)
                  OR LOWER(a.author) LIKE LOWER(?)
                  OR LOWER(REPLACE(a.author, ' ', '')) = LOWER(?)
                  OR LOWER(REPLACE(a.author, ' ', '')) LIKE LOWER(?)
              )
        ";

        $params = array_merge($statuses, [
            $authorStr,
            "%{$authorStr}%",
            $authorStr,
            "%{$authorStr}%"
        ]);

        if (!$adult) {
            $sql .= " AND a.adult = FALSE";
        }

        $sql .= " ORDER BY a.title";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $this->formatResults($stmt->fetchAll());
    }

    /**
     * Filter by category - replaces filter_apps_by_category()
     *
     * @param string $category Category name ('All' for no filter)
     * @param bool $adult Whether to include adult content
     * @param int $limit Max results (0 for unlimited)
     * @param array $statuses Which statuses to include
     * @param string $sort Sort order: 'alpha' (default) or 'recommended'
     * @return array Filtered apps
     */
    public function filterByCategory($category, $adult = false, $limit = 0, $statuses = ['active', 'newer'], $sort = 'alpha') {
        $statusPlaceholders = str_repeat('?,', count($statuses) - 1) . '?';

        $sql = "
            SELECT
                a.id,
                a.title,
                a.author,
                a.summary,
                a.app_icon AS appIcon,
                a.app_icon_big AS appIconBig,
                c.name AS category,
                a.vendor_id AS vendorId,
                a.pixi AS Pixi,
                a.pre AS `Pre`,
                a.pre2 AS Pre2,
                a.pre3 AS Pre3,
                a.veer AS Veer,
                a.touchpad AS TouchPad,
                a.touchpad_exclusive,
                a.luneos AS LuneOS,
                a.adult AS Adult,
                a.recommendation_order AS recommendationOrder
            FROM apps a
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE a.status IN ($statusPlaceholders)
        ";

        $params = $statuses;

        // Handle virtual categories vs real categories
        if ($category === "Revisionist History") {
            $sql .= " AND a.in_revisionist_history = TRUE";
        } elseif ($category === "Curator's Choice") {
            $sql .= " AND a.in_curators_choice = TRUE";
        } elseif ($category !== 'All') {
            $sql .= " AND c.name = ?";
            $params[] = $category;
        }

        if (!$adult) {
            $sql .= " AND a.adult = FALSE";
        }

        // Determine sort order
        $orderBy = ($sort === 'recommended')
            ? 'a.recommendation_order DESC, a.title'
            : 'a.title';
        $sql .= " ORDER BY $orderBy";

        if ($limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $this->formatResults($stmt->fetchAll());
    }

    /**
     * Get apps by vendor ID
     *
     * @param string $vendorId Vendor identifier
     * @param bool $adult Whether to include adult content
     * @param array $statuses Which statuses to include
     * @return array Apps by this vendor
     */
    public function getByVendorId($vendorId, $adult = false, $statuses = ['active', 'newer']) {
        $statusPlaceholders = str_repeat('?,', count($statuses) - 1) . '?';

        $sql = "
            SELECT
                a.id,
                a.title,
                a.author,
                a.summary,
                a.app_icon AS appIcon,
                a.app_icon_big AS appIconBig,
                c.name AS category,
                a.vendor_id AS vendorId,
                a.pixi AS Pixi,
                a.pre AS `Pre`,
                a.pre2 AS Pre2,
                a.pre3 AS Pre3,
                a.veer AS Veer,
                a.touchpad AS TouchPad,
                a.touchpad_exclusive,
                a.luneos AS LuneOS,
                a.adult AS Adult
            FROM apps a
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE a.status IN ($statusPlaceholders)
              AND a.vendor_id = ?
        ";

        $params = array_merge($statuses, [$vendorId]);

        if (!$adult) {
            $sql .= " AND a.adult = FALSE";
        }

        $sql .= " ORDER BY a.title";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $this->formatResults($stmt->fetchAll());
    }

    /**
     * Get single app by ID
     *
     * @param int $id App ID
     * @return array|null App data or null if not found
     */
    public function getById($id) {
        $sql = "
            SELECT
                a.*,
                c.name AS category
            FROM apps a
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE a.id = ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * Get apps by list of IDs
     *
     * @param array $ids Array of app IDs
     * @param bool $adult Whether to include adult content
     * @return array Apps matching the IDs
     */
    public function getByIds($ids, $adult = false) {
        if (empty($ids)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($ids) - 1) . '?';

        $sql = "
            SELECT
                a.id,
                a.title,
                a.author,
                a.summary,
                a.app_icon AS appIcon,
                a.app_icon_big AS appIconBig,
                c.name AS category,
                a.vendor_id AS vendorId,
                a.pixi AS Pixi,
                a.pre AS `Pre`,
                a.pre2 AS Pre2,
                a.pre3 AS Pre3,
                a.veer AS Veer,
                a.touchpad AS TouchPad,
                a.touchpad_exclusive,
                a.luneos AS LuneOS,
                a.adult AS Adult
            FROM apps a
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE a.id IN ($placeholders)
        ";

        $params = $ids;

        if (!$adult) {
            $sql .= " AND a.adult = FALSE";
        }

        $sql .= " ORDER BY a.title";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $this->formatResults($stmt->fetchAll());
    }

    /**
     * Get random app
     *
     * @param bool $adult Whether to include adult content
     * @param array $statuses Which statuses to include
     * @return array|null Random app or null
     */
    public function getRandom($adult = false, $statuses = ['active', 'newer']) {
        $statusPlaceholders = str_repeat('?,', count($statuses) - 1) . '?';

        $sql = "
            SELECT
                a.id,
                a.title,
                a.author,
                a.summary,
                a.app_icon AS appIcon,
                a.app_icon_big AS appIconBig,
                c.name AS category,
                a.vendor_id AS vendorId,
                a.pixi AS Pixi,
                a.pre AS `Pre`,
                a.pre2 AS Pre2,
                a.pre3 AS Pre3,
                a.veer AS Veer,
                a.touchpad AS TouchPad,
                a.touchpad_exclusive,
                a.luneos AS LuneOS,
                a.adult AS Adult
            FROM apps a
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE a.status IN ($statusPlaceholders)
        ";

        $params = $statuses;

        if (!$adult) {
            $sql .= " AND a.adult = FALSE";
        }

        $sql .= " ORDER BY RAND() LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        return $result ? $this->formatResults([$result])[0] : null;
    }

    /**
     * Get category counts for getMuseumMaster appCount response
     *
     * @param bool $adult Whether to include adult content
     * @param array $statuses Which statuses to count
     * @return array Category name => count
     */
    public function getCategoryCounts($adult = false, $statuses = ['active', 'newer']) {
        $statusPlaceholders = str_repeat('?,', count($statuses) - 1) . '?';

        $sql = "
            SELECT c.name, COUNT(*) as count
            FROM apps a
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE a.status IN ($statusPlaceholders)
        ";

        $params = $statuses;

        if (!$adult) {
            $sql .= " AND a.adult = FALSE";
        }

        $sql .= " GROUP BY c.name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $counts = ['All' => 0, 'Missing Apps' => 0];
        while ($row = $stmt->fetch()) {
            if ($row['name']) {
                $counts[$row['name']] = (int)$row['count'];
                $counts['All'] += (int)$row['count'];
            }
        }

        // Get missing count
        $missingStmt = $this->db->query("SELECT COUNT(*) FROM apps WHERE status = 'missing'");
        $counts['Missing Apps'] = (int)$missingStmt->fetchColumn();

        // Get virtual category counts (not added to All since they overlap with real categories)
        $adultClause = $adult ? "" : " AND adult = FALSE";

        $rhStmt = $this->db->prepare("SELECT COUNT(*) FROM apps WHERE in_revisionist_history = TRUE AND status IN ($statusPlaceholders)" . $adultClause);
        $rhStmt->execute($statuses);
        $counts['Revisionist History'] = (int)$rhStmt->fetchColumn();

        $ccStmt = $this->db->prepare("SELECT COUNT(*) FROM apps WHERE in_curators_choice = TRUE AND status IN ($statusPlaceholders)" . $adultClause);
        $ccStmt->execute($statuses);
        $counts["Curator's Choice"] = (int)$ccStmt->fetchColumn();

        return $counts;
    }

    /**
     * Get total count of apps matching criteria
     *
     * @param array $filters Filter options
     * @return int Count of matching apps
     */
    public function getCount($filters = []) {
        $statuses = $filters['statuses'] ?? ['active', 'newer'];
        $statusPlaceholders = str_repeat('?,', count($statuses) - 1) . '?';

        $sql = "SELECT COUNT(*) FROM apps a WHERE a.status IN ($statusPlaceholders)";
        $params = $statuses;

        if (!empty($filters['adult']) !== true) {
            $sql .= " AND a.adult = FALSE";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Get all categories
     *
     * @return array Categories with id, name, display_order
     */
    public function getCategories() {
        $stmt = $this->db->query("SELECT id, name, display_order FROM categories ORDER BY display_order, name");
        return $stmt->fetchAll();
    }

    // ============ Admin CRUD Methods ============

    /**
     * Create a new app
     *
     * @param array $data App data
     * @return int New app ID
     */
    public function create($data) {
        $sql = "
            INSERT INTO apps (
                id, title, author, summary, app_icon, app_icon_big,
                category_id, vendor_id, pixi, pre, pre2, pre3, veer,
                touchpad, touchpad_exclusive, luneos, adult,
                in_revisionist_history, in_curators_choice, post_shutdown, recommendation_order, status
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                (SELECT id FROM categories WHERE name = ?),
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $data['id'],
            $data['title'],
            $data['author'],
            $data['summary'] ?? '',
            $data['app_icon'] ?? '',
            $data['app_icon_big'] ?? '',
            $data['category'] ?? 'Utilities',
            $data['vendor_id'] ?? null,
            (int)($data['pixi'] ?? false),
            (int)($data['pre'] ?? false),
            (int)($data['pre2'] ?? false),
            (int)($data['pre3'] ?? false),
            (int)($data['veer'] ?? false),
            (int)($data['touchpad'] ?? false),
            (int)($data['touchpad_exclusive'] ?? false),
            (int)($data['luneos'] ?? false),
            (int)($data['adult'] ?? false),
            (int)($data['in_revisionist_history'] ?? false),
            (int)($data['in_curators_choice'] ?? false),
            (int)($data['post_shutdown'] ?? false),
            (int)($data['recommendation_order'] ?? 0),
            $data['status'] ?? 'active'
        ]);

        return $data['id'];
    }

    /**
     * Update an existing app
     *
     * @param int $id App ID
     * @param array $data Updated data
     * @return bool Success
     */
    public function update($id, $data) {
        $sql = "
            UPDATE apps SET
                title = ?,
                author = ?,
                summary = ?,
                app_icon = ?,
                app_icon_big = ?,
                category_id = (SELECT id FROM categories WHERE name = ?),
                vendor_id = ?,
                pixi = ?,
                pre = ?,
                pre2 = ?,
                pre3 = ?,
                veer = ?,
                touchpad = ?,
                touchpad_exclusive = ?,
                luneos = ?,
                adult = ?,
                in_revisionist_history = ?,
                in_curators_choice = ?,
                post_shutdown = ?,
                recommendation_order = ?,
                status = ?
            WHERE id = ?
        ";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $data['title'],
            $data['author'],
            $data['summary'] ?? '',
            $data['app_icon'] ?? '',
            $data['app_icon_big'] ?? '',
            $data['category'] ?? 'Utilities',
            $data['vendor_id'] ?? null,
            (int)($data['pixi'] ?? false),
            (int)($data['pre'] ?? false),
            (int)($data['pre2'] ?? false),
            (int)($data['pre3'] ?? false),
            (int)($data['veer'] ?? false),
            (int)($data['touchpad'] ?? false),
            (int)($data['touchpad_exclusive'] ?? false),
            (int)($data['luneos'] ?? false),
            (int)($data['adult'] ?? false),
            (int)($data['in_revisionist_history'] ?? false),
            (int)($data['in_curators_choice'] ?? false),
            (int)($data['post_shutdown'] ?? false),
            (int)($data['recommendation_order'] ?? 0),
            $data['status'] ?? 'active',
            $id
        ]);
    }

    /**
     * Delete an app
     *
     * @param int $id App ID
     * @return bool Success
     */
    public function delete($id) {
        $stmt = $this->db->prepare("DELETE FROM apps WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Search for admin interface (with pagination)
     *
     * @param array $filters Search filters
     * @return array Matching apps
     */
    public function adminSearch($filters = []) {
        $sql = "
            SELECT
                a.id,
                a.title,
                a.author,
                a.app_icon AS appIcon,
                c.name AS category,
                a.status,
                a.adult,
                a.updated_at
            FROM apps a
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($filters['search'])) {
            $sql .= " AND (a.title LIKE ? OR a.author LIKE ? OR a.id = ?)";
            $search = "%{$filters['search']}%";
            $params[] = $search;
            $params[] = $search;
            $params[] = is_numeric($filters['search']) ? (int)$filters['search'] : 0;
        }

        if (!empty($filters['status'])) {
            $sql .= " AND a.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['category'])) {
            $sql .= " AND c.name = ?";
            $params[] = $filters['category'];
        }

        $sql .= " ORDER BY a.title";

        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = (int)($filters['perPage'] ?? 50);
        $offset = ($page - 1) * $perPage;

        $sql .= " LIMIT $perPage OFFSET $offset";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Count for admin search (for pagination)
     *
     * @param array $filters Search filters
     * @return int Total count
     */
    public function adminSearchCount($filters = []) {
        $sql = "
            SELECT COUNT(*)
            FROM apps a
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE 1=1
        ";

        $params = [];

        if (!empty($filters['search'])) {
            $sql .= " AND (a.title LIKE ? OR a.author LIKE ? OR a.id = ?)";
            $search = "%{$filters['search']}%";
            $params[] = $search;
            $params[] = $search;
            $params[] = is_numeric($filters['search']) ? (int)$filters['search'] : 0;
        }

        if (!empty($filters['status'])) {
            $sql .= " AND a.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['category'])) {
            $sql .= " AND c.name = ?";
            $params[] = $filters['category'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    // ============ Helper Methods ============

    /**
     * Sanitize search string
     */
    private function sanitizeSearch($str) {
        $str = urldecode(strtolower($str));
        $str = preg_replace("/[^a-zA-Z0-9 ]+/", "", $str);
        return trim($str);
    }

    /**
     * Format database results to match original JSON structure
     */
    private function formatResults($rows) {
        $results = [];
        foreach ($rows as $row) {
            $row['Pixi'] = (bool)$row['Pixi'];
            $row['Pre'] = (bool)$row['Pre'];
            $row['Pre2'] = (bool)$row['Pre2'];
            $row['Pre3'] = (bool)$row['Pre3'];
            $row['Veer'] = (bool)$row['Veer'];
            $row['TouchPad'] = (bool)$row['TouchPad'];
            $row['touchpad_exclusive'] = (bool)$row['touchpad_exclusive'];
            $row['LuneOS'] = (bool)$row['LuneOS'];
            $row['Adult'] = (bool)$row['Adult'];
            $row['id'] = (int)$row['id'];
            $results[] = $row;
        }
        return $results;
    }
}
