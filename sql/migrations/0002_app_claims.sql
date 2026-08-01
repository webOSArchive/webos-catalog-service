-- ============================================================================
-- 0002_app_claims.sql  —  App ownership claims (Phase 3-lite)
-- ----------------------------------------------------------------------------
-- Records developer requests to claim an existing (unowned) catalog app,
-- including the claimant's explanation. For now claims on unowned apps are
-- auto-granted (status 'granted'); the 'pending'/'rejected' statuses exist so
-- a future approval flow can slot in without another migration.
--
-- Target: MariaDB 10.2+.
--
-- Apply:  mysql -u <user> -p <db> < sql/migrations/0002_app_claims.sql
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS app_claims (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  app_id      INT UNSIGNED NOT NULL,
  account_id  BIGINT UNSIGNED NOT NULL,
  explanation TEXT NOT NULL,
  status      ENUM('granted','pending','rejected') NOT NULL DEFAULT 'granted',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_claims_app (app_id),
  KEY idx_claims_account (account_id),
  CONSTRAINT fk_claims_app     FOREIGN KEY (app_id)     REFERENCES apps (id)     ON DELETE CASCADE,
  CONSTRAINT fk_claims_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
