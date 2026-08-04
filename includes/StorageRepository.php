<?php
/**
 * Storage Repository - per-account, per-app key/value store for app data sync
 * (table: account_app_storage, migration 0004).
 *
 * Values are opaque blobs scrambled client-side by the SDK; this layer never
 * parses or decodes them. Every write bumps `revision` server-side — that is
 * the only concurrency primitive (last-write-wins, with optional
 * expected_revision checks for clients that want conflict detection).
 *
 * Quota limits come from config.php via the constructor; set() enforces them
 * inside the write transaction so concurrent writes can't blow far past them.
 */
require_once __DIR__ . '/Database.php';

class StorageRepository {
    private $db;
    private $limits;

    // Fallbacks when config.php has no storage_* keys.
    private static $defaultLimits = [
        'max_value_bytes'       => 32768,    // 32 KB per value
        'max_keys_per_app'      => 200,
        'max_bytes_per_app'     => 524288,   // 512 KB per app per account
        'max_bytes_per_account' => 2097152,  // 2 MB per account
    ];

    /**
     * @param array $limits Override quota limits (keys as in $defaultLimits)
     * @param PDO|null $pdo Injectable connection for tests; defaults to the shared one
     */
    public function __construct($limits = [], $pdo = null) {
        $this->db = $pdo ?: Database::getInstance()->getConnection();
        $this->limits = array_merge(self::$defaultLimits, array_filter($limits, 'is_int'));
    }

    /** One record, or null. */
    public function get($accountId, $appId, $key) {
        $stmt = $this->db->prepare(
            "SELECT data_key, value, revision, updated_at
               FROM account_app_storage
              WHERE account_id = ? AND app_id = ? AND data_key = ?"
        );
        $stmt->execute([(int)$accountId, $appId, $key]);
        $row = $stmt->fetch();
        return $row ? $this->publicRecord($row) : null;
    }

    /** All records for one app. */
    public function getAll($accountId, $appId) {
        $stmt = $this->db->prepare(
            "SELECT data_key, value, revision, updated_at
               FROM account_app_storage
              WHERE account_id = ? AND app_id = ?
              ORDER BY data_key"
        );
        $stmt->execute([(int)$accountId, $appId]);
        return array_map([$this, 'publicRecord'], $stmt->fetchAll());
    }

    /** Keys + revisions only — cheap "what changed" poll without the blobs. */
    public function listKeys($accountId, $appId) {
        $stmt = $this->db->prepare(
            "SELECT data_key, revision, value_bytes, updated_at
               FROM account_app_storage
              WHERE account_id = ? AND app_id = ?
              ORDER BY data_key"
        );
        $stmt->execute([(int)$accountId, $appId]);
        return array_map(function ($row) {
            return [
                'key'        => $row['data_key'],
                'revision'   => (int)$row['revision'],
                'bytes'      => (int)$row['value_bytes'],
                'updated_at' => $row['updated_at'],
            ];
        }, $stmt->fetchAll());
    }

    /**
     * Upsert one value. Returns one of:
     *   ['revision' => N]                          success
     *   ['conflict' => <record|null>]              expected_revision mismatch
     *   ['quota' => <reason>, 'usage' => [...]]    over a limit
     *
     * $expectedRevision null = unconditional (last-write-wins); 0 = create-only.
     */
    public function set($accountId, $appId, $key, $value, $deviceId = null, $expectedRevision = null) {
        $accountId = (int)$accountId;
        $bytes = strlen($value);
        if ($bytes > $this->limits['max_value_bytes']) {
            return ['quota' => 'value_too_large', 'usage' => $this->usage($accountId, $appId)];
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT id, revision, value_bytes FROM account_app_storage
                  WHERE account_id = ? AND app_id = ? AND data_key = ?"
            );
            $stmt->execute([$accountId, $appId, $key]);
            $row = $stmt->fetch();

            if ($expectedRevision !== null) {
                $current = $row ? (int)$row['revision'] : 0;
                if ($current !== (int)$expectedRevision) {
                    $this->db->rollBack();
                    return ['conflict' => $this->get($accountId, $appId, $key)];
                }
            }

            $reason = $this->quotaViolation($accountId, $appId, $row, $bytes);
            if ($reason !== null) {
                $this->db->rollBack();
                return ['quota' => $reason, 'usage' => $this->usage($accountId, $appId)];
            }

            if ($row) {
                $upd = $this->db->prepare(
                    "UPDATE account_app_storage
                        SET value = ?, value_bytes = ?, revision = revision + 1,
                            device_id = ?, updated_at = CURRENT_TIMESTAMP
                      WHERE id = ?"
                );
                $upd->execute([$value, $bytes, $deviceId, (int)$row['id']]);
                $revision = (int)$row['revision'] + 1;
            } else {
                $ins = $this->db->prepare(
                    "INSERT INTO account_app_storage
                            (account_id, app_id, data_key, value, value_bytes, revision, device_id)
                     VALUES (?, ?, ?, ?, ?, 1, ?)"
                );
                $ins->execute([$accountId, $appId, $key, $value, $bytes, $deviceId]);
                $revision = 1;
            }

            $this->db->commit();
            return ['revision' => $revision];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @return bool true if a record existed and was deleted */
    public function delete($accountId, $appId, $key) {
        $stmt = $this->db->prepare(
            "DELETE FROM account_app_storage
              WHERE account_id = ? AND app_id = ? AND data_key = ?"
        );
        $stmt->execute([(int)$accountId, $appId, $key]);
        return $stmt->rowCount() > 0;
    }

    /** Remove everything an account has stored (account-deletion cascade). */
    public function deleteAccount($accountId) {
        $stmt = $this->db->prepare("DELETE FROM account_app_storage WHERE account_id = ?");
        $stmt->execute([(int)$accountId]);
        return $stmt->rowCount();
    }

    /**
     * Quota usage for an account, plus per-app detail when $appId is given.
     * Shape: {account: {bytes, keys, max_bytes}, app?: {bytes, keys, max_bytes, max_keys}}
     */
    public function usage($accountId, $appId = null) {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS c, COALESCE(SUM(value_bytes), 0) AS b
               FROM account_app_storage WHERE account_id = ?"
        );
        $stmt->execute([(int)$accountId]);
        $acct = $stmt->fetch();

        $usage = ['account' => [
            'bytes'     => (int)$acct['b'],
            'keys'      => (int)$acct['c'],
            'max_bytes' => $this->limits['max_bytes_per_account'],
        ]];

        if ($appId !== null) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS c, COALESCE(SUM(value_bytes), 0) AS b
                   FROM account_app_storage WHERE account_id = ? AND app_id = ?"
            );
            $stmt->execute([(int)$accountId, $appId]);
            $app = $stmt->fetch();
            $usage['app'] = [
                'app_id'    => $appId,
                'bytes'     => (int)$app['b'],
                'keys'      => (int)$app['c'],
                'max_bytes' => $this->limits['max_bytes_per_app'],
                'max_keys'  => $this->limits['max_keys_per_app'],
            ];
        }
        return $usage;
    }

    public function maxValueBytes() {
        return $this->limits['max_value_bytes'];
    }

    /** Reason string if writing $newBytes (replacing $existingRow) would breach a limit. */
    private function quotaViolation($accountId, $appId, $existingRow, $newBytes) {
        $oldBytes = $existingRow ? (int)$existingRow['value_bytes'] : 0;
        $delta = $newBytes - $oldBytes;

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS c, COALESCE(SUM(value_bytes), 0) AS b
               FROM account_app_storage WHERE account_id = ? AND app_id = ?"
        );
        $stmt->execute([$accountId, $appId]);
        $app = $stmt->fetch();

        if (!$existingRow && (int)$app['c'] + 1 > $this->limits['max_keys_per_app']) {
            return 'too_many_keys';
        }
        if ((int)$app['b'] + $delta > $this->limits['max_bytes_per_app']) {
            return 'app_bytes_exceeded';
        }

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(value_bytes), 0) AS b
               FROM account_app_storage WHERE account_id = ?"
        );
        $stmt->execute([$accountId]);
        if ((int)$stmt->fetchColumn() + $delta > $this->limits['max_bytes_per_account']) {
            return 'account_bytes_exceeded';
        }
        return null;
    }

    private function publicRecord($row) {
        return [
            'key'        => $row['data_key'],
            'value'      => $row['value'],
            'revision'   => (int)$row['revision'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
