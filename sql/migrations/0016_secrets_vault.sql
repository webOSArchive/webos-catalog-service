-- 0016: Secrets vault — signing keys, client IDs and the like, shared with
-- trusted community leaders so the project can carry on without its founder.
--
-- Everything sensitive is encrypted with a key derived from a shared vault
-- password (Argon2id via sodium_crypto_pwhash, then XSalsa20-Poly1305 via
-- sodium_crypto_secretbox; see includes/SecretsRepository.php). The server
-- stores neither the password nor the key, so a database dump or backup of
-- these tables is useless without the password. Names are encrypted too.
--
-- Access: admin/secrets.php, for accounts holding `secrets.view` (the new
-- `trustee` role, or superadmin). Adding, changing, deleting and changing the
-- vault password need `secrets.manage` (superadmin only).
--
-- These tables are not in scripts/export-archive.php's $EXPORT list, so they
-- never reach the public archive bundle. Keep it that way.
--
-- Apply:  mysql -u <user> -p <db> < sql/migrations/0016_secrets_vault.sql
-- Then sign in as a superadmin and open Secrets to set the vault password.

-- One row (id = 1): the KDF parameters and a check value that proves a
-- password is right. Absent until a superadmin sets the first password.
CREATE TABLE secrets_vault (
    id          TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    kdf_salt    VARBINARY(16)    NOT NULL,
    kdf_ops     INT UNSIGNED     NOT NULL,
    kdf_mem     BIGINT UNSIGNED  NOT NULL,
    verifier    VARBINARY(128)   NOT NULL,  -- nonce || secretbox(known text)
    created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rekeyed_at  DATETIME         NULL DEFAULT NULL
) ENGINE=InnoDB;

-- One row per secret. `meta` holds the encrypted JSON {name, notes, filename};
-- `content` the encrypted file. Both are nonce || ciphertext.
CREATE TABLE secrets (
    id           INT UNSIGNED     NOT NULL AUTO_INCREMENT PRIMARY KEY,
    meta         BLOB             NOT NULL,
    content      MEDIUMBLOB       NOT NULL,
    content_size INT UNSIGNED     NOT NULL,  -- plaintext bytes, for display
    created_by   BIGINT UNSIGNED  NULL,
    updated_by   BIGINT UNSIGNED  NULL,
    created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Who unlocked, viewed, downloaded or changed what. Username is copied in so
-- the record survives the account being deleted. Only the entry's id is
-- kept, not its name, since names are encrypted; the page shows the current
-- name once the vault is unlocked.
CREATE TABLE secrets_access_log (
    id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id   BIGINT UNSIGNED  NULL,
    username     VARCHAR(64)      NOT NULL,
    action       VARCHAR(32)      NOT NULL,  -- unlock, unlock_failed, view, download, create, update, delete, init, rekey
    secret_id    INT UNSIGNED     NULL,
    ip_address   VARCHAR(45)      NULL,
    created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- Role for community leaders who should be able to open the vault. Grants
-- admin.access + secrets.view (includes/Capabilities.php). Assign it on
-- Accounts alongside whatever other role they hold.
INSERT IGNORE INTO roles (name) VALUES ('trustee');

-- Verify after applying:
--   SHOW TABLES LIKE 'secrets%';
--   SELECT name FROM roles WHERE name = 'trustee';
