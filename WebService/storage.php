<?php
/**
 * storage.php — cloud app storage: per-account, per-app key/value sync.
 *
 * Apps save small scrambled blobs (preferences, settings, progress) against
 * the signed-in webOS Account and retrieve them from any device. Values are
 * scrambled client-side by the SDK; this endpoint stores/returns them opaquely.
 *
 * Dispatch follows device.php: ?m=<method>, JSON body on POST. Reads are GET,
 * writes are POST (never PUT/DELETE — keeps old-WebKit clients and CORS simple).
 *
 *   GET  ?m=get      &app_id=&key=      one record
 *   GET  ?m=getAll   &app_id=           all records for an app
 *   GET  ?m=list     &app_id=           keys+revisions only (change polling)
 *   GET  ?m=usage    [&app_id=]         quota usage
 *   POST ?m=set      {app_id, key, value[, expected_revision]}
 *   POST ?m=setMany  {app_id, items: [{key, value[, expected_revision]}, ...]}
 *   POST ?m=delete   {app_id, key}
 *
 * Auth on every call: "Authorization: PalmAuth token=<token>" (the header the
 * catalog client already sends — BaseServer.getAuthHeaders), resolved via
 * AccountRepository::verifyDeviceToken(). Fallback: token in the query string
 * or POST body for clients that can't set headers. 401 when invalid.
 *
 * expected_revision (optional on writes): null/omitted = last-write-wins;
 * 0 = create-only; otherwise the write succeeds only if the server revision
 * matches, else 409 with the current record for client-side merging.
 */

require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/../includes/AccountRepository.php';
require_once __DIR__ . '/../includes/StorageRepository.php';

// CORS: bearer-token auth, no cookies, so a wildcard origin is safe — and
// required, since webOS apps call from file:// (Origin: null) and PWAs from
// their own https origins. The Authorization header makes browsers preflight.
header('Access-Control-Allow-Origin: *');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Palm-Device-Id');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

// Sync traffic is chattier than catalog browsing (per-IP; see also the
// per-account write throttle below).
checkRateLimit(600, 3600);

$method = $_GET['m'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}

function storage_fail($status, $error, $message, $extra = []) {
    http_response_code($status);
    echo json_encode(array_merge(['error' => $error, 'message' => $message], $extra));
    exit;
}

function storage_require_verb($verb) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $verb) {
        storage_fail(405, 'method_not_allowed', "This method requires $verb");
    }
}

/** Raw bearer token from the PalmAuth header, query string, or POST body. */
function storage_token($body) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth !== '' && preg_match('/token=([A-Za-z0-9]+)/', $auth, $m)) {
        return $m[1];
    }
    if (!empty($_GET['token'])) {
        return (string)$_GET['token'];
    }
    if (!empty($body['token'])) {
        return (string)$body['token'];
    }
    return null;
}

/** app_id: reverse-DNS (com.example.app), <=128 chars. */
function storage_valid_app_id($appId) {
    return is_string($appId) && strlen($appId) <= 128
        && preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*(\.[A-Za-z0-9][A-Za-z0-9-]*)+$/', $appId);
}

/** data_key: printable name like "settings" or "book:<syncKey>", <=128 chars. */
function storage_valid_key($key) {
    return is_string($key) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@-]{0,127}$/', $key);
}

// -- Authenticate ------------------------------------------------------------

$token = storage_token($body);
if ($token === null) {
    storage_fail(401, 'unauthorized', 'Missing account token');
}
$account = (new AccountRepository())->verifyDeviceToken($token);
if (!$account) {
    storage_fail(401, 'unauthorized', 'Invalid or expired account token');
}
$accountId = (int)$account['id'];
$deviceId  = isset($_SERVER['HTTP_X_PALM_DEVICE_ID'])
    ? substr((string)$_SERVER['HTTP_X_PALM_DEVICE_ID'], 0, 128)
    : null;

// -- Common inputs -----------------------------------------------------------

$config = [];
$configPath = __DIR__ . '/config.php';
if (file_exists($configPath)) {
    $config = include($configPath);
}
$repo = new StorageRepository([
    'max_value_bytes'       => $config['storage_max_value_bytes']       ?? null,
    'max_keys_per_app'      => $config['storage_max_keys_per_app']      ?? null,
    'max_bytes_per_app'     => $config['storage_max_bytes_per_app']     ?? null,
    'max_bytes_per_account' => $config['storage_max_bytes_per_account'] ?? null,
]);

$appId = $_GET['app_id'] ?? ($body['app_id'] ?? '');
if ($appId === '' && $method === 'usage') {
    $appId = null; // account-level usage, no app breakdown
} elseif (!storage_valid_app_id($appId)) {
    storage_fail(400, 'invalid_app_id', 'app_id must be a reverse-DNS id like com.example.app');
}

/** Throttle writes per account so one hot account behind Cloudflare can't
 *  exhaust the shared per-IP budget for others. */
function storage_check_write_limit($accountId, $config) {
    $limiter = new RateLimit();
    $limit = $config['storage_writes_per_hour'] ?? 300;
    if ($limiter->isRateLimited('acct.' . $accountId, $limit, 3600)) {
        storage_fail(429, 'rate_limited', 'Too many writes for this account. Please try again later.');
    }
}

/** Normalize an optional expected_revision input to int or null. */
function storage_expected_revision($item) {
    if (!isset($item['expected_revision']) || $item['expected_revision'] === null) {
        return null;
    }
    return (int)$item['expected_revision'];
}

/** Map a StorageRepository::set() result to an HTTP failure, or return the revision. */
function storage_settle_write($result) {
    if (isset($result['quota'])) {
        storage_fail(413, 'quota_exceeded', 'Storage quota exceeded: ' . $result['quota'],
            ['reason' => $result['quota'], 'usage' => $result['usage']]);
    }
    if (array_key_exists('conflict', $result)) {
        storage_fail(409, 'conflict', 'Revision mismatch — merge with the current record and retry',
            ['current' => $result['conflict']]);
    }
    return $result['revision'];
}

// -- Dispatch ----------------------------------------------------------------

switch ($method) {

    case 'get':
        storage_require_verb('GET');
        $key = $_GET['key'] ?? '';
        if (!storage_valid_key($key)) {
            storage_fail(400, 'invalid_key', 'key must be 1-128 chars of A-Za-z0-9._:@-');
        }
        $record = $repo->get($accountId, $appId, $key);
        if (!$record) {
            storage_fail(404, 'not_found', 'No value stored for this key');
        }
        echo json_encode($record);
        break;

    case 'getAll':
        storage_require_verb('GET');
        echo json_encode([
            'items' => $repo->getAll($accountId, $appId),
            'usage' => $repo->usage($accountId, $appId),
        ]);
        break;

    case 'list':
        storage_require_verb('GET');
        echo json_encode(['items' => $repo->listKeys($accountId, $appId)]);
        break;

    case 'usage':
        storage_require_verb('GET');
        echo json_encode($repo->usage($accountId, $appId));
        break;

    case 'set':
        storage_require_verb('POST');
        storage_check_write_limit($accountId, $config);
        $key   = $body['key']   ?? '';
        $value = $body['value'] ?? null;
        if (!storage_valid_key($key)) {
            storage_fail(400, 'invalid_key', 'key must be 1-128 chars of A-Za-z0-9._:@-');
        }
        if (!is_string($value) || $value === '') {
            storage_fail(400, 'invalid_value', 'value must be a non-empty string (the scrambled blob)');
        }
        $result = $repo->set($accountId, $appId, $key, $value, $deviceId, storage_expected_revision($body));
        $revision = storage_settle_write($result);
        echo json_encode(['revision' => $revision, 'usage' => $repo->usage($accountId, $appId)]);
        break;

    case 'setMany':
        storage_require_verb('POST');
        storage_check_write_limit($accountId, $config);
        $items = $body['items'] ?? null;
        if (!is_array($items) || $items === [] || count($items) > 100) {
            storage_fail(400, 'invalid_items', 'items must be an array of 1-100 {key, value} objects');
        }
        // Per-item results; one bad item doesn't abort the rest (each set() is
        // its own transaction). Quota/conflict failures are reported per key.
        $results = [];
        foreach ($items as $item) {
            $key   = is_array($item) ? ($item['key'] ?? '') : '';
            $value = is_array($item) ? ($item['value'] ?? null) : null;
            if (!storage_valid_key($key) || !is_string($value) || $value === '') {
                $results[] = ['key' => is_string($key) ? $key : '', 'error' => 'invalid_item'];
                continue;
            }
            $result = $repo->set($accountId, $appId, $key, $value, $deviceId, storage_expected_revision($item));
            if (isset($result['quota'])) {
                $results[] = ['key' => $key, 'error' => 'quota_exceeded', 'reason' => $result['quota']];
            } elseif (array_key_exists('conflict', $result)) {
                $results[] = ['key' => $key, 'error' => 'conflict', 'current' => $result['conflict']];
            } else {
                $results[] = ['key' => $key, 'revision' => $result['revision']];
            }
        }
        echo json_encode(['results' => $results, 'usage' => $repo->usage($accountId, $appId)]);
        break;

    case 'delete':
        storage_require_verb('POST');
        storage_check_write_limit($accountId, $config);
        $key = $body['key'] ?? '';
        if (!storage_valid_key($key)) {
            storage_fail(400, 'invalid_key', 'key must be 1-128 chars of A-Za-z0-9._:@-');
        }
        echo json_encode([
            'deleted' => $repo->delete($accountId, $appId, $key),
            'usage'   => $repo->usage($accountId, $appId),
        ]);
        break;

    default:
        storage_fail(404, 'unknown_method', 'Unknown method: ' . $method);
}
