<?php
/**
 * ImageStorage - filesystem storage for app images (icons + screenshots).
 *
 * Images live under a base dir (config['image_path'], e.g.
 * /home/wosa/wosa-web/AppImages) in per-app "<appId>" folders, mirroring what
 * image_host serves. Paths stored in the database are relative to that base,
 * e.g. "123/icon.png". Not stored in the database.
 *
 * Security: uploads are validated as real images (getimagesize), the extension
 * is derived from the detected type (never the user's filename), the base name
 * is caller-supplied and sanitized, and deletes are confined to the base dir.
 */
class ImageStorage {
    /** detected mime => stored extension */
    const ALLOWED = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/gif'  => 'gif',
    ];

    private $base;

    public function __construct($basePath) {
        $this->base = rtrim((string)$basePath, '/');
    }

    public static function fromConfig(array $config) {
        return new self($config['image_path'] ?? '');
    }

    public function isConfigured() {
        return $this->base !== '' && is_dir($this->base);
    }

    public function basePath() {
        return $this->base;
    }

    public function appDir($appId) {
        return $this->base . '/' . (int)$appId;
    }

    /** Create the per-app folder if needed. Returns true if it exists after. */
    public function ensureAppDir($appId) {
        $dir = $this->appDir($appId);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return is_dir($dir);
    }

    /** Validate a file is a supported image; return its extension or null. */
    public function imageExtension($tmpPath) {
        $info = @getimagesize($tmpPath);
        if (!$info || empty($info['mime']) || !isset(self::ALLOWED[$info['mime']])) {
            return null;
        }
        return self::ALLOWED[$info['mime']];
    }

    /**
     * Save an uploaded image into the app's folder as "<baseName>.<ext>", where
     * ext comes from the detected image type. Returns the DB-relative path
     * ("<appId>/<baseName>.<ext>") or null on failure/invalid image.
     */
    public function saveUpload($appId, $tmpPath, $baseName) {
        $ext = $this->imageExtension($tmpPath);
        if ($ext === null) {
            return null;
        }
        $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$baseName); // no dots/slashes; ext added below
        if ($baseName === '' || !$this->ensureAppDir($appId)) {
            return null;
        }
        $filename = $baseName . '.' . $ext;
        $dest = $this->appDir($appId) . '/' . $filename;

        // move_uploaded_file for real uploads; copy fallback for tests/CLI.
        if (!@move_uploaded_file($tmpPath, $dest) && !@copy($tmpPath, $dest)) {
            return null;
        }
        @chmod($dest, 0644);
        return (int)$appId . '/' . $filename;
    }

    /** Delete a stored image by DB-relative path (confined to the base dir). */
    public function delete($relPath) {
        $full = $this->safeFullPath($relPath);
        if ($full !== null && is_file($full)) {
            @unlink($full);
            return true;
        }
        return false;
    }

    /** Does a stored image exist? */
    public function exists($relPath) {
        $full = $this->safeFullPath($relPath);
        return $full !== null && is_file($full);
    }

    /** Next "screenshot-N" base name given the app's existing screenshot paths. */
    public function nextScreenshotName($existingRelPaths) {
        $max = 0;
        foreach ((array)$existingRelPaths as $p) {
            if (preg_match('#screenshot-(\d+)\.#i', basename((string)$p), $m)) {
                $max = max($max, (int)$m[1]);
            }
        }
        return 'screenshot-' . ($max + 1);
    }

    /** Absolute path for a relative path, only if it stays within the base dir. */
    private function safeFullPath($relPath) {
        $relPath = ltrim((string)$relPath, '/');
        if ($relPath === '' || strpos($relPath, '..') !== false) {
            return null;
        }
        $full = $this->base . '/' . $relPath;
        $realBase = realpath($this->base);
        $realDir  = realpath(dirname($full));
        if ($realBase === false || $realDir === false) {
            return null;
        }
        if ($realDir !== $realBase && strpos($realDir, $realBase . '/') !== 0) {
            return null;
        }
        return $full;
    }
}
