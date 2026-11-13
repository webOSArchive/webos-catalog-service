<?php
/**
 * Secrets Repository - the shared secrets vault (migration 0016): signing
 * keys, client IDs and the like, kept for trusted community leaders so the
 * project can carry on without its founder. Page: admin/secrets.php.
 *
 * Crypto: the vault password goes through Argon2id (sodium_crypto_pwhash)
 * with the salt and limits stored in secrets_vault, giving a 32-byte key.
 * Every encrypted value is nonce || sodium_crypto_secretbox(plaintext).
 * secrets_vault.verifier is VERIFIER_TEXT sealed with the key: if it opens,
 * the password was right. Neither the password nor the key is ever stored,
 * so the tables alone (a dump, a backup) reveal only how many entries there
 * are and how big they are. Somebody controlling the live server could still
 * catch the password at the next unlock; this protects data at rest.
 *
 * Writes check the caller's key against the verifier inside the transaction,
 * with the vault row locked, so an entry can't be sealed with a stale key
 * while another admin changes the password.
 */
require_once __DIR__ . '/Database.php';

class SecretsRepository {
    const VERIFIER_TEXT = 'webos-secrets-vault-v1';
    const MAX_CONTENT_BYTES = 1048576; // 1 MB per file
    const MIN_PASSWORD_LENGTH = 12;

    private $db;

    public function __construct($pdo = null) {
        $this->db = $pdo ?: Database::getInstance()->getConnection();
    }

    // --- Crypto helpers -----------------------------------------------------

    private static function deriveKey($password, $salt, $ops, $mem) {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $password, $salt, (int)$ops, (int)$mem,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }

    public static function seal($plaintext, $key) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($plaintext, $nonce, $key);
    }

    /** Plaintext, or false if the blob is damaged or the key is wrong. */
    public static function open($blob, $key) {
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (!is_string($blob) || strlen($blob) < $n + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return false;
        }
        return sodium_crypto_secretbox_open(substr($blob, $n), substr($blob, 0, $n), $key);
    }

    /** Builds a fresh vault row (new salt) for $password: [row fields, key]. */
    private static function newVault($password) {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $ops  = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;
        $mem  = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;
        $key  = self::deriveKey($password, $salt, $ops, $mem);
        return [[
            'kdf_salt' => $salt,
            'kdf_ops'  => $ops,
            'kdf_mem'  => $mem,
            'verifier' => self::seal(self::VERIFIER_TEXT, $key),
        ], $key];
    }

    /** Error message for an unacceptable new vault password, or null. */
    public static function passwordError($password) {
        if (!is_string($password) || mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'The vault password must be at least ' . self::MIN_PASSWORD_LENGTH .
                ' characters. A few random words works well.';
        }
        return null;
    }

    // --- Vault --------------------------------------------------------------

    private function getVault($forUpdate = false) {
        $stmt = $this->db->query("SELECT * FROM secrets_vault WHERE id = 1" . ($forUpdate ? " FOR UPDATE" : ""));
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function isInitialized() {
        return $this->getVault() !== null;
    }

    /** Sets the first vault password. Returns the key, or null if a vault already exists. */
    public function initialize($password) {
        [$v, $key] = self::newVault($password);
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO secrets_vault (id, kdf_salt, kdf_ops, kdf_mem, verifier) VALUES (1, ?, ?, ?, ?)"
            );
            $stmt->execute([$v['kdf_salt'], $v['kdf_ops'], $v['kdf_mem'], $v['verifier']]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                return null; // someone else set it first
            }
            throw $e;
        }
        return $key;
    }

    /** The vault key if $password is right, else null. */
    public function unlock($password) {
        $v = $this->getVault();
        if (!$v) {
            return null;
        }
        $key = self::deriveKey((string)$password, $v['kdf_salt'], $v['kdf_ops'], $v['kdf_mem']);
        return $this->verifierOpens($v, $key) ? $key : null;
    }

    private function verifierOpens($vault, $key) {
        $plain = self::open($vault['verifier'], $key);
        return $plain !== false && hash_equals(self::VERIFIER_TEXT, $plain);
    }

    /** Is $key still the vault's key (the password hasn't changed since)? */
    public function keyIsCurrent($key) {
        $v = $this->getVault();
        return $v && is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            && $this->verifierOpens($v, $key);
    }

    /** Inside a transaction: lock the vault row and confirm $key, or throw. */
    private function lockAndCheckKey($key) {
        $v = $this->getVault(true);
        if (!$v || !$this->verifierOpens($v, $key)) {
            throw new RuntimeException('The vault password has changed. Unlock again.');
        }
        return $v;
    }

    /**
     * Change the vault password: re-encrypts every entry under a key from
     * $newPassword (with a new salt). Returns the new key.
     */
    public function rekey($oldKey, $newPassword) {
        [$v, $newKey] = self::newVault($newPassword);
        $this->db->beginTransaction();
        try {
            $this->lockAndCheckKey($oldKey);
            $rows = $this->db->query("SELECT id, meta, content FROM secrets FOR UPDATE")->fetchAll();
            $upd = $this->db->prepare("UPDATE secrets SET meta = ?, content = ?, updated_at = updated_at WHERE id = ?");
            foreach ($rows as $r) {
                $meta = self::open($r['meta'], $oldKey);
                $content = self::open($r['content'], $oldKey);
                if ($meta === false || $content === false) {
                    throw new RuntimeException("Entry #{$r['id']} could not be decrypted; nothing was changed.");
                }
                $upd->execute([self::seal($meta, $newKey), self::seal($content, $newKey), $r['id']]);
            }
            $stmt = $this->db->prepare(
                "UPDATE secrets_vault SET kdf_salt = ?, kdf_ops = ?, kdf_mem = ?, verifier = ?, rekeyed_at = NOW() WHERE id = 1"
            );
            $stmt->execute([$v['kdf_salt'], $v['kdf_ops'], $v['kdf_mem'], $v['verifier']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $newKey;
    }

    // --- Entries ------------------------------------------------------------

    /** All entries with decrypted name/notes/filename (no file contents), sorted by name. */
    public function listEntries($key) {
        $rows = $this->db->query(
            "SELECT id, meta, content_size, created_by, updated_by, created_at, updated_at FROM secrets"
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->decodeRow($r, $key);
        }
        usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        return $out;
    }

    /** One entry including 'content', or null. */
    public function getEntry($id, $key) {
        $stmt = $this->db->prepare("SELECT * FROM secrets WHERE id = ?");
        $stmt->execute([(int)$id]);
        $r = $stmt->fetch();
        if (!$r) {
            return null;
        }
        $e = $this->decodeRow($r, $key);
        $e['content'] = self::open($r['content'], $key);
        return $e;
    }

    private function decodeRow($r, $key) {
        $meta = self::open($r['meta'], $key);
        $m = $meta !== false ? json_decode($meta, true) : null;
        return [
            'id'           => (int)$r['id'],
            'name'         => $m['name'] ?? "(entry #{$r['id']} could not be decrypted)",
            'notes'        => $m['notes'] ?? '',
            'filename'     => $m['filename'] ?? 'secret.txt',
            'decrypted'    => is_array($m),
            'content_size' => (int)$r['content_size'],
            'created_by'   => $r['created_by'],
            'updated_by'   => $r['updated_by'],
            'created_at'   => $r['created_at'],
            'updated_at'   => $r['updated_at'],
        ];
    }

    private static function encodeMeta($name, $notes, $filename) {
        return json_encode(['name' => $name, 'notes' => $notes, 'filename' => $filename], JSON_UNESCAPED_UNICODE);
    }

    public function create($key, $name, $notes, $filename, $content, $accountId) {
        $this->db->beginTransaction();
        try {
            $this->lockAndCheckKey($key);
            $stmt = $this->db->prepare(
                "INSERT INTO secrets (meta, content, content_size, created_by, updated_by) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                self::seal(self::encodeMeta($name, $notes, $filename), $key),
                self::seal($content, $key),
                strlen($content), $accountId, $accountId,
            ]);
            $id = (int)$this->db->lastInsertId();
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Update name/notes; replace the file too when $content is not null. */
    public function update($key, $id, $name, $notes, $filename, $content, $accountId) {
        $this->db->beginTransaction();
        try {
            $this->lockAndCheckKey($key);
            $meta = self::seal(self::encodeMeta($name, $notes, $filename), $key);
            if ($content === null) {
                $stmt = $this->db->prepare("UPDATE secrets SET meta = ?, updated_by = ? WHERE id = ?");
                $stmt->execute([$meta, $accountId, (int)$id]);
            } else {
                $stmt = $this->db->prepare(
                    "UPDATE secrets SET meta = ?, content = ?, content_size = ?, updated_by = ? WHERE id = ?"
                );
                $stmt->execute([$meta, self::seal($content, $key), strlen($content), $accountId, (int)$id]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function delete($id) {
        $stmt = $this->db->prepare("DELETE FROM secrets WHERE id = ?");
        $stmt->execute([(int)$id]);
        return $stmt->rowCount() > 0;
    }

    // --- Access log ---------------------------------------------------------

    public function log($accountId, $username, $action, $secretId = null, $ip = null) {
        $stmt = $this->db->prepare(
            "INSERT INTO secrets_access_log (account_id, username, action, secret_id, ip_address) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$accountId, (string)$username, $action, $secretId, $ip]);
    }

    public function recentLog($limit = 100) {
        $limit = max(1, min(1000, (int)$limit));
        return $this->db->query(
            "SELECT * FROM secrets_access_log ORDER BY created_at DESC, id DESC LIMIT $limit"
        )->fetchAll();
    }
}
