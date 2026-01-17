<?php
/**
 * Metadata Repository - Database access for detailed app metadata
 *
 * Replaces individual JSON metadata file access with database queries.
 * Returns data in the exact format expected by the API.
 */
require_once __DIR__ . '/Database.php';

class MetadataRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get full metadata for an app - replaces reading {id}.json file
     *
     * @param int $appId App ID
     * @return array|null Metadata in original JSON format, or null if not found
     */
    public function getMetadata($appId) {
        $sql = "
            SELECT
                m.*,
                a.title,
                a.author,
                a.touchpad_exclusive
            FROM app_metadata m
            JOIN apps a ON m.app_id = a.id
            WHERE m.app_id = ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$appId]);
        $metadata = $stmt->fetch();

        if (!$metadata) {
            return null;
        }

        // Get images
        $images = $this->getImages($appId);

        // Format response to match original JSON structure exactly
        return [
            'publicApplicationId' => $metadata['public_application_id'],
            'description' => $metadata['description'],
            'version' => $metadata['version'],
            'versionNote' => $metadata['version_note'],
            'homeURL' => $metadata['home_url'],
            'supportURL' => $metadata['support_url'],
            'custsupportemail' => $metadata['cust_support_email'],
            'custsupportphonenum' => $metadata['cust_support_phone'] ?? '',
            'copyright' => $metadata['copyright'],
            'licenseURL' => $metadata['license_url'],
            'locale' => $metadata['locale'] ?? 'en_US',
            'appSize' => $metadata['app_size'] ? (int)$metadata['app_size'] : null,
            'installSize' => $metadata['install_size'] ? (int)$metadata['install_size'] : null,
            'isEncrypted' => (bool)$metadata['is_encrypted'],
            'adultRating' => (bool)$metadata['adult_rating'],
            'islocationbased' => (bool)$metadata['is_location_based'],
            'lastModifiedTime' => $metadata['last_modified_time'],
            'mediaLink' => $metadata['media_link'],
            'mediaIcon' => $metadata['media_icon'],
            'attributes' => $metadata['attributes'] ? json_decode($metadata['attributes'], true) : [],
            'price' => (float)$metadata['price'],
            'currency' => $metadata['currency'] ?? 'USD',
            'isAdvertized' => (bool)$metadata['is_advertized'],
            'filename' => $metadata['filename'],
            'free' => (bool)$metadata['free'],
            'touchpad_exclusive' => (bool)$metadata['touchpad_exclusive'],
            'images' => $images,
            'originalFileName' => $metadata['original_filename'],
            'starRating' => $metadata['star_rating'] ? (int)$metadata['star_rating'] : null
        ];
    }

    /**
     * Get images for an app
     *
     * @param int $appId App ID
     * @return array Images in original format (keyed by order number)
     */
    public function getImages($appId) {
        $stmt = $this->db->prepare("
            SELECT image_order, screenshot_path, thumbnail_path, orientation, device
            FROM app_images
            WHERE app_id = ?
            ORDER BY image_order
        ");
        $stmt->execute([$appId]);

        $images = [];
        while ($img = $stmt->fetch()) {
            // Original format uses string keys like "1", "2", etc.
            $key = (string)$img['image_order'];
            $images[$key] = [
                'screenshot' => $img['screenshot_path'],
                'thumbnail' => $img['thumbnail_path'],
                'orientation' => $img['orientation'],
                'device' => $img['device']
            ];
        }

        return $images;
    }

    /**
     * Get version info for update checking (getLatestVersionInfo.php)
     *
     * @param int $appId App ID
     * @return array|null Version info or null if not found
     */
    public function getVersionInfo($appId) {
        $sql = "
            SELECT
                version,
                version_note AS versionNote,
                last_modified_time AS lastModifiedTime,
                filename
            FROM app_metadata
            WHERE app_id = ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$appId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Check if metadata exists for an app
     *
     * @param int $appId App ID
     * @return bool
     */
    public function exists($appId) {
        $stmt = $this->db->prepare("SELECT 1 FROM app_metadata WHERE app_id = ?");
        $stmt->execute([$appId]);
        return $stmt->fetch() !== false;
    }

    // ============ Admin CRUD Methods ============

    /**
     * Create or update metadata for an app
     *
     * @param int $appId App ID
     * @param array $data Metadata fields
     * @return bool Success
     */
    public function upsert($appId, $data) {
        $sql = "
            INSERT INTO app_metadata (
                app_id, public_application_id, description, version, version_note,
                home_url, support_url, cust_support_email, cust_support_phone,
                copyright, license_url, locale, app_size, install_size,
                is_encrypted, adult_rating, is_location_based, last_modified_time,
                media_link, media_icon, price, currency, free, is_advertized,
                filename, original_filename, star_rating, attributes
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?
            )
            ON DUPLICATE KEY UPDATE
                public_application_id = VALUES(public_application_id),
                description = VALUES(description),
                version = VALUES(version),
                version_note = VALUES(version_note),
                home_url = VALUES(home_url),
                support_url = VALUES(support_url),
                cust_support_email = VALUES(cust_support_email),
                cust_support_phone = VALUES(cust_support_phone),
                copyright = VALUES(copyright),
                license_url = VALUES(license_url),
                locale = VALUES(locale),
                app_size = VALUES(app_size),
                install_size = VALUES(install_size),
                is_encrypted = VALUES(is_encrypted),
                adult_rating = VALUES(adult_rating),
                is_location_based = VALUES(is_location_based),
                last_modified_time = VALUES(last_modified_time),
                media_link = VALUES(media_link),
                media_icon = VALUES(media_icon),
                price = VALUES(price),
                currency = VALUES(currency),
                free = VALUES(free),
                is_advertized = VALUES(is_advertized),
                filename = VALUES(filename),
                original_filename = VALUES(original_filename),
                star_rating = VALUES(star_rating),
                attributes = VALUES(attributes)
        ";

        $lastModified = null;
        if (!empty($data['lastModifiedTime'])) {
            $lastModified = date('Y-m-d H:i:s', strtotime($data['lastModifiedTime']));
        }

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $appId,
            $data['publicApplicationId'] ?? null,
            $data['description'] ?? null,
            $data['version'] ?? null,
            $data['versionNote'] ?? null,
            $data['homeURL'] ?? null,
            $data['supportURL'] ?? null,
            $data['custsupportemail'] ?? null,
            $data['custsupportphonenum'] ?? null,
            $data['copyright'] ?? null,
            $data['licenseURL'] ?? null,
            $data['locale'] ?? 'en_US',
            $data['appSize'] ?? null,
            $data['installSize'] ?? null,
            (int)($data['isEncrypted'] ?? false),
            (int)($data['adultRating'] ?? false),
            (int)($data['islocationbased'] ?? false),
            $lastModified,
            $data['mediaLink'] ?? null,
            $data['mediaIcon'] ?? null,
            $data['price'] ?? 0,
            $data['currency'] ?? 'USD',
            (int)($data['free'] ?? true),
            (int)($data['isAdvertized'] ?? false),
            $data['filename'] ?? null,
            $data['originalFileName'] ?? null,
            $data['starRating'] ?? null,
            isset($data['attributes']) ? json_encode($data['attributes']) : null
        ]);
    }

    /**
     * Update images for an app (replaces existing)
     *
     * @param int $appId App ID
     * @param array $images Images array in original format
     * @return bool Success
     */
    public function updateImages($appId, $images) {
        // Delete existing images
        $stmt = $this->db->prepare("DELETE FROM app_images WHERE app_id = ?");
        $stmt->execute([$appId]);

        if (empty($images)) {
            return true;
        }

        // Insert new images
        $insertStmt = $this->db->prepare("
            INSERT INTO app_images (app_id, image_order, screenshot_path, thumbnail_path, orientation, device)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($images as $order => $image) {
            $insertStmt->execute([
                $appId,
                (int)$order,
                $image['screenshot'] ?? null,
                $image['thumbnail'] ?? null,
                $image['orientation'] ?? null,
                $image['device'] ?? null
            ]);
        }

        return true;
    }

    /**
     * Delete metadata for an app
     *
     * @param int $appId App ID
     * @return bool Success
     */
    public function delete($appId) {
        // Images are deleted via CASCADE
        $stmt = $this->db->prepare("DELETE FROM app_metadata WHERE app_id = ?");
        return $stmt->execute([$appId]);
    }

    /**
     * Get metadata for admin editing (raw database format)
     *
     * @param int $appId App ID
     * @return array|null
     */
    public function getForAdmin($appId) {
        $stmt = $this->db->prepare("SELECT * FROM app_metadata WHERE app_id = ?");
        $stmt->execute([$appId]);
        $result = $stmt->fetch();

        if ($result && $result['attributes']) {
            $result['attributes'] = json_decode($result['attributes'], true);
        }

        return $result ?: null;
    }
}
