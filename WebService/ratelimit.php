<?php
/**
 * Simple rate limiting implementation
 * Tracks requests per IP address with configurable limits
 */

class RateLimit {
    private $rate_limit_dir = '__rateLimit';
    private $default_limit = 300; // requests per window
    private $default_window = 3600; // 1 hour in seconds
    
    public function __construct($dir = null) {
        // Optional absolute dir so callers outside WebService/ (e.g. admin login)
        // can reuse the store regardless of the current working directory.
        if ($dir !== null) {
            $this->rate_limit_dir = $dir;
        }
        if (!file_exists($this->rate_limit_dir)) {
            mkdir($this->rate_limit_dir, 0774, true);
        }
    }

    /** Per-key store file ($key is usually an IP, optionally prefixed). */
    private function fileFor($key) {
        $safe = preg_replace('/[^a-zA-Z0-9\.]/', '_', $key);
        return $this->rate_limit_dir . '/' . $safe . '.json';
    }

    /** Count events recorded for $key within $window seconds (does NOT record). */
    public function recentCount($key, $window = null) {
        if ($window === null) $window = $this->default_window;
        $now = time();
        $data = array_filter($this->getRateData($this->fileFor($key)), function ($t) use ($now, $window) {
            return ($now - $t) < $window;
        });
        return count($data);
    }

    /** Record one event for $key (e.g. a failed login attempt). */
    public function record($key) {
        $file = $this->fileFor($key);
        $data = $this->getRateData($file);
        $data[] = time();
        $this->saveRateData($file, $data);
    }

    /** Clear all recorded events for $key (e.g. on a successful login). */
    public function clear($key) {
        $file = $this->fileFor($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }
    
    /**
     * Check if request should be rate limited
     * @param string $ip - Client IP address
     * @param int $limit - Max requests per window (optional)
     * @param int $window - Time window in seconds (optional)
     * @return bool - true if rate limit exceeded
     */
    public function isRateLimited($ip, $limit = null, $window = null) {
        if ($limit === null) $limit = $this->default_limit;
        if ($window === null) $window = $this->default_window;
        
        // Sanitize IP for filename
        $ip_safe = preg_replace('/[^a-zA-Z0-9\.]/', '_', $ip);
        $rate_file = $this->rate_limit_dir . '/' . $ip_safe . '.json';
        
        $now = time();
        $rate_data = $this->getRateData($rate_file);
        
        // Clean old entries outside the window
        $rate_data = array_filter($rate_data, function($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        });
        
        // Check if limit exceeded
        if (count($rate_data) >= $limit) {
            return true;
        }
        
        // Add current request
        $rate_data[] = $now;
        $this->saveRateData($rate_file, $rate_data);
        
        return false;
    }
    
    /**
     * Get client IP address with proxy support
     * @return string
     */
    /**
     * Only CF-Connecting-IP and REMOTE_ADDR are trusted here. Every other
     * "forwarded for" style header (X-Forwarded-For, X-Forwarded,
     * X-Cluster-Client-IP, Forwarded-For, Forwarded, Client-IP) is set
     * verbatim from whatever the client sends - a request can carry any
     * value it likes, so keying rate limits (e.g. the admin login lockout)
     * off them lets an attacker pick a fresh "IP" on every attempt and
     * bypass the limit entirely. CF-Connecting-IP is safe to trust *because*
     * this site sits behind Cloudflare's edge, which sets that header from
     * the real TCP connection and overwrites any client-supplied value of
     * the same name - but only as long as the origin server itself isn't
     * reachable directly (bypassing Cloudflare); that's an infrastructure
     * guarantee (origin firewalled to Cloudflare's IP ranges), not something
     * this code can enforce.
     */
    public function getClientIP() {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Clean up old rate limit files
     */
    public function cleanup() {
        if (!is_dir($this->rate_limit_dir)) {
            return;
        }
        
        $files = glob($this->rate_limit_dir . '/*.json');
        $cutoff = time() - ($this->default_window * 2); // Keep files for 2x window
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }
    
    private function getRateData($file) {
        if (!file_exists($file)) {
            return [];
        }
        
        $content = file_get_contents($file);
        return json_decode($content, true) ?: [];
    }
    
    private function saveRateData($file, $data) {
        file_put_contents($file, json_encode($data));
    }
}

/**
 * Check rate limit for current request
 * @param int $limit - Max requests per hour (optional)
 * @param int $window - Time window in seconds (optional)
 * @return bool - true if rate limited
 */
function checkRateLimit($limit = null, $window = null) {
    static $rate_limiter = null;
    
    if ($rate_limiter === null) {
        $rate_limiter = new RateLimit();
    }
    
    $ip = $rate_limiter->getClientIP();
    
    if ($rate_limiter->isRateLimited($ip, $limit, $window)) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Rate limit exceeded',
            'message' => 'Too many requests. Please try again later.'
        ]);
        exit;
    }
    
    // Occasionally clean up old files (1% chance)
    if (rand(1, 100) === 1) {
        $rate_limiter->cleanup();
    }
    
    return false;
}
?>