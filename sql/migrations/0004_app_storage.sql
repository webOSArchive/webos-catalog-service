-- 0004: cloud app storage — per-account, per-app key/value sync store.
--
-- Apps save small scrambled JSON blobs (preferences, settings, progress)
-- keyed by (account, app_id, data_key); signing in on another device
-- retrieves them. Values arrive already scrambled by the client SDK and are
-- stored verbatim — the server never parses or decodes them.
--
-- Quotas (value size, keys/app, bytes/app, bytes/account) are enforced in
-- WebService/storage.php from config.php values; value_bytes is denormalized
-- so quota sums don't LENGTH() every blob.
--
-- revision is the concurrency primitive: bumped server-side on every write,
-- returned on every read, optionally checked on write (expected_revision) so
-- careful clients can detect conflicts. Client clocks are never trusted.
--
-- No FK to accounts, matching 0003 (plain indexes only). Before applying,
-- confirm accounts.id is BIGINT UNSIGNED via SHOW CREATE TABLE accounts —
-- 0001_accounts.sql is not in this repo to check against.
-- Account deletion must also DELETE FROM account_app_storage WHERE account_id = ?.

CREATE TABLE account_app_storage (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id  BIGINT UNSIGNED NOT NULL,
  app_id      VARCHAR(128)    NOT NULL,           -- reverse-DNS, e.g. com.palm.codepoet.papyrus
  data_key    VARCHAR(128)    NOT NULL,           -- e.g. "settings", "book:<syncKey>"
  value       MEDIUMTEXT      NOT NULL,           -- opaque scrambled blob ("v1:<base64>")
  value_bytes INT UNSIGNED    NOT NULL,
  revision    BIGINT UNSIGNED NOT NULL DEFAULT 1,
  device_id   VARCHAR(128)    NULL DEFAULT NULL,  -- last writer's nduid, for debugging
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Leftmost prefixes of this key also serve the per-app and per-account
  -- quota/list queries, so no additional indexes are needed.
  UNIQUE KEY uq_storage (account_id, app_id, data_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
