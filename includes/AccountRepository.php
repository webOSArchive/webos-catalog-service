<?php
/**
 * AccountRepository - data access for the user accounts system (Phase 0).
 *
 * Backed by the tables created in sql/migrations/0001_accounts.sql:
 *   accounts, roles, account_roles (account_tokens is used from Phase 4 on).
 *
 * Phase 0 provides account CRUD-lite, password verification and role/capability
 * helpers. Nothing calls these yet; Phase 1 (admin login) will be the first
 * consumer. Passwords are hashed with password_hash(); find*() never returns
 * the password hash - only the internal verifyLogin() reads it.
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Capabilities.php';

class AccountRepository {
    private $db;

    /** Columns safe to return to callers (excludes password_hash). */
    const PUBLIC_COLS = 'id, username, email, display_name, status, author_vendor_id, legacy_account_id, created_at, updated_at, last_login_at';

    /**
     * A valid throwaway bcrypt hash. verifyLogin() always runs one
     * password_verify() - against this when the account is missing or has no
     * password - so response time doesn't reveal whether a username exists.
     */
    const DUMMY_HASH = '$2y$12$ztFAj8B7XTfOoq0.FkVVZejCjlIBBohgn7zUkGmRxFpDAS386VvqG';

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // -- Lookups --------------------------------------------------------------

    public function findById($id) {
        $stmt = $this->db->prepare("SELECT " . self::PUBLIC_COLS . " FROM accounts WHERE id = ?");
        $stmt->execute([(int)$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByUsername($username) {
        $stmt = $this->db->prepare("SELECT " . self::PUBLIC_COLS . " FROM accounts WHERE username = ?");
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByEmail($email) {
        $stmt = $this->db->prepare("SELECT " . self::PUBLIC_COLS . " FROM accounts WHERE email = ?");
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * List accounts with their role names (for the future admin Accounts page).
     * @return array
     */
    public function listAccounts() {
        $stmt = $this->db->query("SELECT " . self::PUBLIC_COLS . " FROM accounts ORDER BY username");
        $accounts = $stmt->fetchAll();
        foreach ($accounts as &$a) {
            $a['roles'] = $this->getRoleNames($a['id']);
        }
        return $accounts;
    }

    // -- Create / update ------------------------------------------------------

    /**
     * Create an account. Accounts are admin-provisioned only (no self-signup).
     *
     * @param array $data username (required), plus optional password (plaintext,
     *                    hashed here), email, display_name, status,
     *                    author_vendor_id, legacy_account_id.
     * @return int New account id.
     */
    public function create(array $data) {
        if (empty($data['username'])) {
            throw new InvalidArgumentException('username is required');
        }
        $hash = !empty($data['password'])
            ? password_hash($data['password'], PASSWORD_DEFAULT)
            : null;

        $stmt = $this->db->prepare("
            INSERT INTO accounts
                (username, email, password_hash, display_name, status, author_vendor_id, legacy_account_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['username'],
            $data['email'] ?? null,
            $hash,
            $data['display_name'] ?? null,
            $data['status'] ?? 'active',
            $data['author_vendor_id'] ?? null,
            $data['legacy_account_id'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function setPassword($accountId, $plainPassword) {
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("UPDATE accounts SET password_hash = ? WHERE id = ?");
        return $stmt->execute([$hash, (int)$accountId]);
    }

    public function setStatus($accountId, $status) {
        $stmt = $this->db->prepare("UPDATE accounts SET status = ? WHERE id = ?");
        return $stmt->execute([$status, (int)$accountId]);
    }

    /**
     * Permanently delete an account. account_roles / account_tokens cascade;
     * apps.owner_account_id / app_reviews.author_account_id are set NULL (FKs).
     * Callers should require the account be disabled first (see accounts.php).
     */
    public function deleteAccount($accountId) {
        $stmt = $this->db->prepare("DELETE FROM accounts WHERE id = ?");
        return $stmt->execute([(int)$accountId]);
    }

    public function updateLastLogin($accountId) {
        $stmt = $this->db->prepare("UPDATE accounts SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?");
        return $stmt->execute([(int)$accountId]);
    }

    // -- Authentication (consumed by Phase 1 admin login) ---------------------

    /**
     * Verify a username/password. Returns the public account row on success
     * (only for active accounts with a password set), or null.
     */
    public function verifyLogin($username, $password) {
        $stmt = $this->db->prepare("SELECT id, password_hash, status FROM accounts WHERE username = ?");
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        // Always run exactly one password_verify (constant-time-ish) so a missing
        // or password-less account is indistinguishable from a wrong password.
        $hash = (!empty($row['password_hash'])) ? $row['password_hash'] : self::DUMMY_HASH;
        $passwordOk = password_verify($password, $hash);
        if (!$row || $row['status'] !== 'active' || empty($row['password_hash']) || !$passwordOk) {
            return null;
        }
        return $this->findById($row['id']);
    }

    // -- Device / legacy-client auth (Phase 4) --------------------------------

    /**
     * Verify a device-client login. Device clients send an "accountAlias" that is
     * usually the email, so we match username OR email (preferring an exact
     * username match). Runs exactly one password_verify() like verifyLogin() so a
     * missing/password-less account is indistinguishable from a wrong password.
     * Returns the public account row on success, or null.
     */
    public function verifyDeviceLogin($alias, $password) {
        $stmt = $this->db->prepare(
            "SELECT id, password_hash, status FROM accounts
             WHERE username = ? OR email = ?
             ORDER BY (username = ?) DESC LIMIT 1"
        );
        $stmt->execute([$alias, $alias, $alias]);
        $row = $stmt->fetch();
        $hash = (!empty($row['password_hash'])) ? $row['password_hash'] : self::DUMMY_HASH;
        $passwordOk = password_verify($password, $hash);
        if (!$row || $row['status'] !== 'active' || empty($row['password_hash']) || !$passwordOk) {
            return null;
        }
        return $this->findById($row['id']);
    }

    /**
     * Issue a device auth token for an account. Returns the RAW token once (to
     * hand to the client); only its sha256 hash is stored. $deviceId is the
     * client's nduid/device id. $ttlDays null => no expiry.
     */
    public function issueDeviceToken($accountId, $deviceId = null, $userAgent = null, $ttlDays = 365) {
        $raw     = bin2hex(random_bytes(32));
        $hash    = hash('sha256', $raw);
        $expires = ($ttlDays === null) ? null : date('Y-m-d H:i:s', time() + ((int)$ttlDays * 86400));
        // One row per physical device (uq_tokens_device): a re-register or
        // re-sign-in overwrites that device's row — the device only holds its
        // latest token, so the replaced row was unusable anyway. created_at is
        // preserved as "first seen". Tokens without a device id just insert
        // (unique index ignores NULLs).
        $stmt = $this->db->prepare(
            "INSERT INTO account_tokens (account_id, token_hash, device_id, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                account_id   = VALUES(account_id),
                token_hash   = VALUES(token_hash),
                user_agent   = VALUES(user_agent),
                expires_at   = VALUES(expires_at),
                last_seen_at = NOW()"
        );
        $stmt->execute([
            (int)$accountId,
            $hash,
            $deviceId  !== null ? substr((string)$deviceId, 0, 128)  : null,
            $userAgent !== null ? substr((string)$userAgent, 0, 255) : null,
            $expires,
        ]);
        return $raw;
    }

    /** Devices registered to an account (Q: "how many devices does an account have?"). */
    public function devicesForAccount($accountId) {
        $stmt = $this->db->prepare(
            "SELECT device_id, user_agent, created_at, last_seen_at, expires_at
               FROM account_tokens
              WHERE account_id = ? AND device_id IS NOT NULL
              ORDER BY created_at"
        );
        $stmt->execute([(int)$accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Resolve a device id (nduid) to the account currently signed in on it, or null. */
    public function accountIdForDevice($deviceId) {
        if ($deviceId === null || $deviceId === '') {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT account_id FROM account_tokens
              WHERE device_id = ? AND (expires_at IS NULL OR expires_at > NOW())"
        );
        $stmt->execute([substr((string)$deviceId, 0, 128)]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /**
     * Resolve a raw device token to its account (active, unexpired) and bump
     * last_seen_at. Returns the public account row, or null.
     */
    public function verifyDeviceToken($rawToken) {
        if (!is_string($rawToken) || $rawToken === '') {
            return null;
        }
        $hash = hash('sha256', $rawToken);
        $stmt = $this->db->prepare(
            "SELECT t.id AS token_id, t.account_id, t.expires_at, a.status
             FROM account_tokens t JOIN accounts a ON a.id = t.account_id
             WHERE t.token_hash = ? LIMIT 1"
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'active') {
            return null;
        }
        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            return null;
        }
        $upd = $this->db->prepare("UPDATE account_tokens SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?");
        $upd->execute([(int)$row['token_id']]);
        return $this->findById($row['account_id']);
    }

    /** Revoke a single device token by its raw value. */
    public function revokeDeviceToken($rawToken) {
        $stmt = $this->db->prepare("DELETE FROM account_tokens WHERE token_hash = ?");
        return $stmt->execute([hash('sha256', (string)$rawToken)]);
    }

    /**
     * Trade a valid raw token for a fresh one on the same account + device,
     * invalidating the old token. Returns the new raw token, or null when the
     * presented token is invalid/expired or the account isn't active.
     */
    public function refreshDeviceToken($rawToken, $userAgent = null, $ttlDays = 365) {
        if (!is_string($rawToken) || $rawToken === '') {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT t.id, t.account_id, t.device_id, t.expires_at, a.status
             FROM account_tokens t JOIN accounts a ON a.id = t.account_id
             WHERE t.token_hash = ? LIMIT 1"
        );
        $stmt->execute([hash('sha256', $rawToken)]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'active') {
            return null;
        }
        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            return null;
        }
        if ($row['device_id'] === null) {
            // No device id means no uq_tokens_device row to overwrite — delete
            // the old row explicitly so refreshes don't pile up dead tokens.
            $del = $this->db->prepare("DELETE FROM account_tokens WHERE id = ?");
            $del->execute([(int)$row['id']]);
        }
        return $this->issueDeviceToken((int)$row['account_id'], $row['device_id'], $userAgent, $ttlDays);
    }

    // -- Roles & capabilities -------------------------------------------------

    /** @return string[] role names assigned to the account */
    public function getRoleNames($accountId) {
        $stmt = $this->db->prepare("
            SELECT r.name
            FROM account_roles ar
            JOIN roles r ON r.id = ar.role_id
            WHERE ar.account_id = ?
            ORDER BY r.name
        ");
        $stmt->execute([(int)$accountId]);
        return array_map(function ($r) { return $r['name']; }, $stmt->fetchAll());
    }

    public function assignRole($accountId, $roleName) {
        $roleId = $this->roleIdByName($roleName);
        if ($roleId === null) {
            return false;
        }
        $stmt = $this->db->prepare("INSERT IGNORE INTO account_roles (account_id, role_id) VALUES (?, ?)");
        return $stmt->execute([(int)$accountId, $roleId]);
    }

    public function removeRole($accountId, $roleName) {
        $roleId = $this->roleIdByName($roleName);
        if ($roleId === null) {
            return false;
        }
        $stmt = $this->db->prepare("DELETE FROM account_roles WHERE account_id = ? AND role_id = ?");
        return $stmt->execute([(int)$accountId, $roleId]);
    }

    /** @return string[] capabilities granted to the account via its roles */
    public function getCapabilities($accountId) {
        return Capabilities::forRoles($this->getRoleNames($accountId));
    }

    /** Does the account have $capability (directly or via '*')? */
    public function hasCapability($accountId, $capability) {
        return Capabilities::roleListGrants($this->getRoleNames($accountId), $capability);
    }

    private function roleIdByName($roleName) {
        $stmt = $this->db->prepare("SELECT id FROM roles WHERE name = ?");
        $stmt->execute([$roleName]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }
}
