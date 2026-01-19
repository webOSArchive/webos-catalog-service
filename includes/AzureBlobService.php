<?php
/**
 * Azure Blob Storage Service
 *
 * Provides direct REST API access to Azure Blob Storage for IPK management.
 * Uses SharedKey authentication via connection string.
 *
 * Usage:
 *   $azure = AzureBlobService::getInstance();
 *   $blobs = $azure->listBlobs();
 *   $azure->uploadBlob('filename.ipk', $fileData, 'application/octet-stream');
 */
class AzureBlobService {
    private static $instance = null;

    private $accountName;
    private $accountKey;
    private $containerName;
    private $endpointSuffix = 'core.windows.net';

    private function __construct() {
        $configPath = __DIR__ . '/../WebService/config.php';
        if (!file_exists($configPath)) {
            throw new Exception("Config file not found: $configPath");
        }

        $config = include($configPath);

        if (empty($config['azure_connection_string'])) {
            throw new Exception("Azure connection string not configured. Add azure_connection_string to config.php");
        }
        if (empty($config['azure_container_name'])) {
            throw new Exception("Azure container name not configured. Add azure_container_name to config.php");
        }

        $this->parseConnectionString($config['azure_connection_string']);
        $this->containerName = $config['azure_container_name'];
    }

    /**
     * Get singleton instance
     * @throws Exception if configuration is missing
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if Azure is configured
     * @return bool True if connection string is set
     */
    public static function isConfigured(): bool {
        $configPath = __DIR__ . '/../WebService/config.php';
        if (!file_exists($configPath)) {
            return false;
        }
        $config = include($configPath);
        return !empty($config['azure_connection_string']) && !empty($config['azure_container_name']);
    }

    /**
     * Parse Azure connection string into components
     * @param string $connectionString Full connection string
     * @throws Exception if required components missing
     */
    private function parseConnectionString(string $connectionString): void {
        $parts = [];
        foreach (explode(';', $connectionString) as $part) {
            $part = trim($part);
            if (empty($part)) continue;

            $pos = strpos($part, '=');
            if ($pos === false) continue;

            $key = substr($part, 0, $pos);
            $value = substr($part, $pos + 1);
            $parts[$key] = $value;
        }

        if (empty($parts['AccountName'])) {
            throw new Exception("AccountName missing from Azure connection string");
        }
        if (empty($parts['AccountKey'])) {
            throw new Exception("AccountKey missing from Azure connection string");
        }

        $this->accountName = $parts['AccountName'];
        $this->accountKey = $parts['AccountKey'];
        $this->endpointSuffix = $parts['EndpointSuffix'] ?? 'core.windows.net';
    }

    /**
     * List blobs in the container
     * @param string $prefix Optional prefix filter (e.g., "apps/")
     * @param string $marker Continuation token for pagination
     * @param int $maxResults Maximum results per request (max 5000)
     * @return array ['blobs' => [...], 'nextMarker' => string|null]
     * @throws Exception on API error
     */
    public function listBlobs(string $prefix = '', string $marker = '', int $maxResults = 100): array {
        $queryParams = [
            'restype' => 'container',
            'comp' => 'list',
            'maxresults' => min($maxResults, 5000)
        ];

        if ($prefix !== '') {
            $queryParams['prefix'] = $prefix;
        }
        if ($marker !== '') {
            $queryParams['marker'] = $marker;
        }

        $response = $this->sendRequest('GET', '?' . http_build_query($queryParams));

        // Parse XML response
        $xml = @simplexml_load_string($response['body']);
        if ($xml === false) {
            throw new Exception("Failed to parse Azure response as XML");
        }

        $blobs = [];
        if (isset($xml->Blobs->Blob)) {
            foreach ($xml->Blobs->Blob as $blob) {
                $blobs[] = [
                    'name' => (string)$blob->Name,
                    'size' => (int)$blob->Properties->{'Content-Length'},
                    'lastModified' => (string)$blob->Properties->{'Last-Modified'},
                    'contentType' => (string)$blob->Properties->{'Content-Type'},
                    'url' => $this->getBlobUrl((string)$blob->Name)
                ];
            }
        }

        $nextMarker = isset($xml->NextMarker) && (string)$xml->NextMarker !== ''
            ? (string)$xml->NextMarker
            : null;

        return [
            'blobs' => $blobs,
            'nextMarker' => $nextMarker
        ];
    }

    /**
     * Upload a blob to the container
     * @param string $blobName Target blob name (path within container)
     * @param string $content Raw file content
     * @param string $contentType MIME type (default: application/octet-stream)
     * @return bool Success
     * @throws Exception on API error
     */
    public function uploadBlob(string $blobName, string $content, string $contentType = 'application/octet-stream'): bool {
        $headers = [
            'Content-Type' => $contentType,
            'x-ms-blob-type' => 'BlockBlob'
        ];

        $response = $this->sendRequest('PUT', '/' . ltrim($blobName, '/'), $headers, $content);

        return $response['status'] === 201;
    }

    /**
     * Get the public URL for a blob
     * @param string $blobName Blob name
     * @return string Full URL
     */
    public function getBlobUrl(string $blobName): string {
        return sprintf(
            'https://%s.blob.%s/%s/%s',
            $this->accountName,
            $this->endpointSuffix,
            $this->containerName,
            ltrim($blobName, '/')
        );
    }

    /**
     * Execute HTTP request to Azure Blob Storage
     * @param string $method HTTP method (GET, PUT, etc.)
     * @param string $path URL path (after container)
     * @param array $headers Additional headers
     * @param string|null $body Request body
     * @return array ['status' => int, 'headers' => array, 'body' => string]
     * @throws Exception on cURL error or API error
     */
    private function sendRequest(string $method, string $path, array $headers = [], ?string $body = null): array {
        $url = sprintf(
            'https://%s.blob.%s/%s%s',
            $this->accountName,
            $this->endpointSuffix,
            $this->containerName,
            $path
        );

        // Required headers for Azure
        $date = gmdate('D, d M Y H:i:s T');
        $version = '2020-10-02';

        $headers['x-ms-date'] = $date;
        $headers['x-ms-version'] = $version;

        if ($body !== null) {
            $headers['Content-Length'] = strlen($body);
        }

        // Generate authorization header
        $canonicalizedResource = '/' . $this->accountName . '/' . $this->containerName . $path;
        // Remove query string from canonicalized resource but keep query params formatted
        $resourcePath = parse_url($canonicalizedResource, PHP_URL_PATH);
        $queryString = parse_url($canonicalizedResource, PHP_URL_QUERY);

        $canonicalizedResource = $resourcePath;
        if ($queryString) {
            parse_str($queryString, $queryParams);
            ksort($queryParams);
            foreach ($queryParams as $key => $value) {
                $canonicalizedResource .= "\n" . strtolower($key) . ':' . $value;
            }
        }

        $authHeader = $this->generateAuthorizationHeader($method, $canonicalizedResource, $headers, $body);
        $headers['Authorization'] = $authHeader;

        // Build cURL request
        $ch = curl_init();

        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "$key: $value";
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 300, // 5 minutes for large uploads
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("cURL error: $error");
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        if ($httpCode >= 400) {
            $errorMessage = $this->parseAzureError($responseBody);
            throw new Exception("Azure API error ($httpCode): $errorMessage");
        }

        return [
            'status' => $httpCode,
            'headers' => $responseHeaders,
            'body' => $responseBody
        ];
    }

    /**
     * Generate SharedKey authorization header
     * @param string $method HTTP method
     * @param string $canonicalizedResource Resource path with query params
     * @param array $headers Request headers
     * @param string|null $body Request body
     * @return string Authorization header value
     */
    private function generateAuthorizationHeader(string $method, string $canonicalizedResource, array $headers, ?string $body): string {
        // Content-Length should be empty string for 0 or not present, not "0"
        $contentLength = '';
        if (isset($headers['Content-Length']) && $headers['Content-Length'] > 0) {
            $contentLength = (string)$headers['Content-Length'];
        }

        // Build string to sign per Azure specification
        $stringToSign = $method . "\n" .                           // HTTP verb
            ($headers['Content-Encoding'] ?? '') . "\n" .          // Content-Encoding
            ($headers['Content-Language'] ?? '') . "\n" .          // Content-Language
            $contentLength . "\n" .                                // Content-Length
            ($headers['Content-MD5'] ?? '') . "\n" .               // Content-MD5
            ($headers['Content-Type'] ?? '') . "\n" .              // Content-Type
            ($headers['Date'] ?? '') . "\n" .                      // Date
            ($headers['If-Modified-Since'] ?? '') . "\n" .         // If-Modified-Since
            ($headers['If-Match'] ?? '') . "\n" .                  // If-Match
            ($headers['If-None-Match'] ?? '') . "\n" .             // If-None-Match
            ($headers['If-Unmodified-Since'] ?? '') . "\n" .       // If-Unmodified-Since
            ($headers['Range'] ?? '') . "\n" .                     // Range
            $this->getCanonicalizedHeaders($headers) .             // Canonicalized headers
            $canonicalizedResource;                                 // Canonicalized resource

        $signature = base64_encode(
            hash_hmac('sha256', $stringToSign, base64_decode($this->accountKey), true)
        );

        return 'SharedKey ' . $this->accountName . ':' . $signature;
    }

    /**
     * Build canonicalized headers string for signature
     * @param array $headers All headers
     * @return string Canonicalized x-ms-* headers
     */
    private function getCanonicalizedHeaders(array $headers): string {
        $msHeaders = [];
        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (strpos($lowerKey, 'x-ms-') === 0) {
                $msHeaders[$lowerKey] = $value;
            }
        }
        ksort($msHeaders);

        $result = '';
        foreach ($msHeaders as $key => $value) {
            $result .= $key . ':' . $value . "\n";
        }

        return $result;
    }

    /**
     * Parse Azure error response XML
     * @param string $responseBody Response body
     * @return string Error message
     */
    private function parseAzureError(string $responseBody): string {
        $xml = @simplexml_load_string($responseBody);
        if ($xml !== false && isset($xml->Message)) {
            return (string)$xml->Message;
        }
        // Return truncated body if not XML
        return strlen($responseBody) > 200 ? substr($responseBody, 0, 200) . '...' : $responseBody;
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
}
