-- webOS App Catalog Database Schema
-- Run: mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS webos_catalog
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE webos_catalog;

-- Categories lookup table (21 categories)
CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed categories
INSERT INTO categories (name, display_order) VALUES
    ('Revisionist History', 1),
    ('Curator''s Choice', 2),
    ('Books', 10),
    ('Business', 11),
    ('Education', 12),
    ('Entertainment', 13),
    ('Finance', 14),
    ('Food', 15),
    ('Games', 16),
    ('Health & Fitness', 17),
    ('Lifestyle', 18),
    ('Music', 19),
    ('Navigation', 20),
    ('News', 21),
    ('Photography', 22),
    ('Productivity', 23),
    ('Reference', 24),
    ('Social Networking', 25),
    ('Sports', 26),
    ('Travel', 27),
    ('Utilities', 28),
    ('Weather', 29)
ON DUPLICATE KEY UPDATE display_order = VALUES(display_order);

-- Apps main table (replaces masterAppData, archivedAppData, missingAppData, newerAppData)
CREATE TABLE IF NOT EXISTS apps (
    id INT UNSIGNED PRIMARY KEY,  -- Original app ID from JSON (not auto-increment)
    title VARCHAR(255) NOT NULL,
    author VARCHAR(255) NOT NULL,
    summary TEXT,
    app_icon VARCHAR(500),
    app_icon_big VARCHAR(500),
    category_id INT UNSIGNED,
    vendor_id VARCHAR(50),  -- Can be string or integer in original data

    -- Device compatibility flags
    pixi BOOLEAN DEFAULT FALSE,
    pre BOOLEAN DEFAULT FALSE,
    pre2 BOOLEAN DEFAULT FALSE,
    pre3 BOOLEAN DEFAULT FALSE,
    veer BOOLEAN DEFAULT FALSE,
    touchpad BOOLEAN DEFAULT FALSE,
    touchpad_exclusive BOOLEAN DEFAULT FALSE,
    luneos BOOLEAN DEFAULT FALSE,

    -- Content flags
    adult BOOLEAN DEFAULT FALSE,

    -- Recommendation order (higher = more recommended, 0 = not featured)
    recommendation_order INT UNSIGNED DEFAULT 0,

    -- Virtual categories (apps can be in these AND a real category)
    in_revisionist_history BOOLEAN DEFAULT FALSE,
    in_curators_choice BOOLEAN DEFAULT FALSE,

    -- Post-shutdown flag (community-created apps after platform EOL)
    post_shutdown BOOLEAN DEFAULT FALSE,

    -- Status tracking (replaces separate JSON files)
    -- active = archivedAppData.json (main usable catalog)
    -- archived = masterAppData.json only (historical, no IPK available in museum)
    -- missing = missingAppData.json (known to exist, IPK not found)
    -- newer = newerAppData.json (post-freeze submissions)
    status ENUM('active', 'archived', 'missing', 'newer') NOT NULL DEFAULT 'active',

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_apps_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_title (title),
    INDEX idx_author (author),
    INDEX idx_category (category_id),
    INDEX idx_vendor (vendor_id),
    INDEX idx_status (status),
    INDEX idx_adult (adult),
    INDEX idx_luneos (luneos),
    INDEX idx_recommendation (recommendation_order DESC),
    INDEX idx_revisionist (in_revisionist_history),
    INDEX idx_curators (in_curators_choice),
    FULLTEXT INDEX ft_search (title, author, summary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- App metadata (detailed info from webos-catalog-metadata/*.json)
CREATE TABLE IF NOT EXISTS app_metadata (
    app_id INT UNSIGNED PRIMARY KEY,
    public_application_id VARCHAR(255),
    description TEXT,
    version VARCHAR(50),
    version_note TEXT,
    home_url VARCHAR(500),
    support_url VARCHAR(500),
    cust_support_email VARCHAR(255),
    cust_support_phone VARCHAR(50),
    copyright VARCHAR(500),
    license_url VARCHAR(500),
    locale VARCHAR(20) DEFAULT 'en_US',
    app_size BIGINT UNSIGNED,
    install_size BIGINT UNSIGNED,
    is_encrypted BOOLEAN DEFAULT FALSE,
    adult_rating BOOLEAN DEFAULT FALSE,
    is_location_based BOOLEAN DEFAULT FALSE,
    last_modified_time DATETIME,
    media_link VARCHAR(500),
    media_icon VARCHAR(500),
    price DECIMAL(10,2) DEFAULT 0.00,
    currency VARCHAR(10) DEFAULT 'USD',
    free BOOLEAN DEFAULT TRUE,
    is_advertized BOOLEAN DEFAULT FALSE,
    filename VARCHAR(500),
    original_filename VARCHAR(500),
    star_rating TINYINT UNSIGNED,

    -- Nested attributes stored as JSON
    attributes JSON,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_metadata_app FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE,
    INDEX idx_public_app_id (public_application_id),
    INDEX idx_version (version),
    INDEX idx_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- App screenshots/images (normalized from metadata images object)
CREATE TABLE IF NOT EXISTS app_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_id INT UNSIGNED NOT NULL,
    image_order TINYINT UNSIGNED NOT NULL,
    screenshot_path VARCHAR(500),
    thumbnail_path VARCHAR(500),
    orientation CHAR(1),  -- 'P' portrait, 'L' landscape
    device CHAR(1),       -- 'T' TouchPad/Tablet, 'P' Phone

    CONSTRAINT fk_images_app FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE,
    INDEX idx_app_images (app_id, image_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Authors/Vendors table (from AuthorMetadata/)
CREATE TABLE IF NOT EXISTS authors (
    vendor_id VARCHAR(50) PRIMARY KEY,
    author_name VARCHAR(255) NOT NULL,
    summary TEXT,
    favicon VARCHAR(255),
    icon VARCHAR(255),
    icon_big VARCHAR(255),
    sponsor_message TEXT,
    sponsor_link VARCHAR(500),
    social_links JSON,  -- Array of social link URLs

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_author_name (author_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Museum sessions (replaces __museumSessions/*.json files)
CREATE TABLE IF NOT EXISTS museum_sessions (
    session_key VARCHAR(255) PRIMARY KEY,
    known_indices JSON,  -- Array of known app indices for pagination optimization
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_session_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Download tracking (replaces logs/downloadcount.log)
CREATE TABLE IF NOT EXISTS download_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_id INT UNSIGNED,
    app_identifier VARCHAR(255),  -- Store original app identifier for reference
    source VARCHAR(255) DEFAULT 'app',
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_app_downloads (app_id),
    INDEX idx_app_identifier (app_identifier),
    INDEX idx_download_date (created_at),
    INDEX idx_download_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update check logs (replaces logs/updatecheck.log)
CREATE TABLE IF NOT EXISTS update_check_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_id INT UNSIGNED,
    app_name VARCHAR(255),
    device_data VARCHAR(500),
    client_info VARCHAR(500),
    client_id VARCHAR(255),
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_update_app (app_id),
    INDEX idx_update_app_name (app_name),
    INDEX idx_update_date (created_at),
    INDEX idx_update_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin audit log (tracks changes made via admin UI)
CREATE TABLE IF NOT EXISTS admin_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_user VARCHAR(255),
    action ENUM('create', 'update', 'delete') NOT NULL,
    table_name VARCHAR(50) NOT NULL,
    record_id VARCHAR(50),
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_audit_date (created_at),
    INDEX idx_audit_table (table_name),
    INDEX idx_audit_record (table_name, record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- App relationships (bidirectional related apps)
CREATE TABLE IF NOT EXISTS app_relationships (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_id INT UNSIGNED NOT NULL,
    related_app_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_relationship (app_id, related_app_id),
    CONSTRAINT fk_app_rel_app FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE,
    CONSTRAINT fk_app_rel_related FOREIGN KEY (related_app_id) REFERENCES apps(id) ON DELETE CASCADE,
    INDEX idx_related_app (related_app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create a view for easy catalog querying (mimics original JSON structure)
CREATE OR REPLACE VIEW v_catalog_apps AS
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
    a.status,
    CASE WHEN a.status = 'active' THEN TRUE ELSE FALSE END AS archived,
    CASE WHEN a.status = 'missing' THEN TRUE ELSE FALSE END AS `_archived`
FROM apps a
LEFT JOIN categories c ON a.category_id = c.id;

-- Stored procedure to clean up old sessions (older than 2 days)
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS cleanup_old_sessions()
BEGIN
    DELETE FROM museum_sessions
    WHERE updated_at < DATE_SUB(NOW(), INTERVAL 2 DAY);
END //
DELIMITER ;

-- Event to auto-cleanup sessions daily (requires EVENT scheduler enabled)
-- SET GLOBAL event_scheduler = ON;
CREATE EVENT IF NOT EXISTS evt_cleanup_sessions
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO CALL cleanup_old_sessions();
