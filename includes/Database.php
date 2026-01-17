<?php
/**
 * Database connection singleton using PDO
 *
 * Usage:
 *   $db = Database::getInstance()->getConnection();
 *   $stmt = $db->prepare("SELECT * FROM apps WHERE id = ?");
 *   $stmt->execute([123]);
 */
class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $configPath = __DIR__ . '/../WebService/config.php';
        if (!file_exists($configPath)) {
            throw new Exception("Config file not found: $configPath");
        }

        $config = include($configPath);

        if (!isset($config['db_host']) || !isset($config['db_name'])) {
            throw new Exception("Database configuration missing. Add db_host, db_name, db_user, db_pass to config.php");
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['db_host'],
            $config['db_name']
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        try {
            $this->pdo = new PDO(
                $dsn,
                $config['db_user'] ?? '',
                $config['db_pass'] ?? '',
                $options
            );
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get PDO connection
     */
    public function getConnection() {
        return $this->pdo;
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public function commit() {
        return $this->pdo->commit();
    }

    /**
     * Rollback a transaction
     */
    public function rollBack() {
        return $this->pdo->rollBack();
    }

    /**
     * Check if in transaction
     */
    public function inTransaction() {
        return $this->pdo->inTransaction();
    }
}
