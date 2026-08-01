-- ============================================================================
-- 0001_accounts.sql  —  User accounts foundation (Phase 0)
-- ----------------------------------------------------------------------------
-- Adds the identity model for admin + legacy-client users. NOTHING in the
-- application reads or writes these tables/columns yet — this migration only
-- creates the schema so later phases (admin login, app ownership, submissions,
-- device auth + reviews) can slot in.
--
-- Target: MariaDB 10.2+ (uses `IF NOT EXISTS` on ALTER, so re-running is safe).
--   MySQL 8 note: MySQL does NOT support `IF NOT EXISTS` on ADD COLUMN/KEY/
--   FOREIGN KEY. On MySQL, remove the four `IF NOT EXISTS` tokens in the two
--   ALTER TABLE blocks at the bottom and run this file exactly once.
--
-- Apply:  mysql -u <user> -p <db> < sql/migrations/0001_accounts.sql
-- ============================================================================

SET NAMES utf8mb4;

-- --- Accounts: one identity for admin AND legacy-client users -----------------
CREATE TABLE IF NOT EXISTS accounts (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username          VARCHAR(64)  NOT NULL,                 -- admin login handle
  email             VARCHAR(255) DEFAULT NULL,
  password_hash     VARCHAR(255) DEFAULT NULL,             -- password_hash(); NULL for device-only accounts
  display_name      VARCHAR(128) DEFAULT NULL,             -- shown as review "creator" later
  status            ENUM('active','disabled','pending') NOT NULL DEFAULT 'active',
  author_vendor_id  VARCHAR(64)  DEFAULT NULL,             -- soft link to authors.vendor_id (an account's vendor identity)
  legacy_account_id BIGINT UNSIGNED DEFAULT NULL,          -- maps to rescued Palm app_reviews.account_id
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login_at     TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_accounts_username (username),
  UNIQUE KEY uq_accounts_email (email),
  KEY idx_accounts_legacy (legacy_account_id),
  KEY idx_accounts_vendor (author_vendor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Roles (capabilities themselves live in includes/Capabilities.php) --------
CREATE TABLE IF NOT EXISTS roles (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(32) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (name, description) VALUES
  ('superadmin', 'Full access, including account management'),
  ('admin',      'Full catalog management (no account management)'),
  ('curator',    'Edit apps, categories, authors; moderate reviews'),
  ('developer',  'Submit and manage their own apps (no admin portal)');

-- --- Account <-> role (many-to-many; an account can be admin AND developer) ---
CREATE TABLE IF NOT EXISTS account_roles (
  account_id BIGINT UNSIGNED NOT NULL,
  role_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (account_id, role_id),
  KEY idx_ar_role (role_id),
  CONSTRAINT fk_ar_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE,
  CONSTRAINT fk_ar_role    FOREIGN KEY (role_id)    REFERENCES roles (id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Device tokens for legacy-client auth (created now, unused until Phase 4) --
CREATE TABLE IF NOT EXISTS account_tokens (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id   BIGINT UNSIGNED NOT NULL,
  token_hash   CHAR(64) NOT NULL,             -- sha256 hex of the issued token (never store the raw token)
  device_id    VARCHAR(128) DEFAULT NULL,     -- nduid / device identifier
  user_agent   VARCHAR(255) DEFAULT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TIMESTAMP NULL DEFAULT NULL,
  expires_at   TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tokens_hash (token_hash),
  KEY idx_tokens_account (account_id),
  KEY idx_tokens_device (device_id),
  CONSTRAINT fk_tokens_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Link an app to the account that owns/submitted it (#4) --------------------
-- Distinct from apps.vendor_id (historical author) and apps.author (string).
ALTER TABLE apps
  ADD COLUMN IF NOT EXISTS owner_account_id BIGINT UNSIGNED DEFAULT NULL AFTER vendor_id,
  ADD KEY IF NOT EXISTS idx_apps_owner (owner_account_id);
ALTER TABLE apps
  ADD CONSTRAINT fk_apps_owner FOREIGN KEY IF NOT EXISTS (owner_account_id)
  REFERENCES accounts (id) ON DELETE SET NULL;

-- --- Attribute NEW client reviews to an account (#5); rescued reviews keep -----
-- their historical numeric account_id untouched in the existing column.
ALTER TABLE app_reviews
  ADD COLUMN IF NOT EXISTS author_account_id BIGINT UNSIGNED DEFAULT NULL,
  ADD KEY IF NOT EXISTS idx_reviews_author_account (author_account_id);
ALTER TABLE app_reviews
  ADD CONSTRAINT fk_reviews_author FOREIGN KEY IF NOT EXISTS (author_account_id)
  REFERENCES accounts (id) ON DELETE SET NULL;
