<?php
/**
 * getUserMap.php — opted-in webOS users as GeoJSON, for a community user map.
 *
 * Users opt in (and choose nearest large city / state / country) on My Account
 * (admin/account.php); see includes/UserMapRepository.php for how a location
 * is worked out. Returns a GeoJSON FeatureCollection that Leaflet/OpenLayers
 * load directly (e.g. L.geoJSON(data)) over OSM tiles:
 *
 *   { "type": "FeatureCollection", "generated_at": "...", "pending": 0,
 *     "features": [ { "type": "Feature",
 *       "geometry": { "type": "Point", "coordinates": [lon, lat] },
 *       "properties": { "username": "...", "place": "Kansas, United States",
 *                       "precision": "state", "last_seen": "2026-10-01" } } ] }
 *
 * Auth: the `user_map_api_key` from config.php, as an "X-Api-Key" header or
 * ?key=. Without that config key the endpoint is off (503).
 */

require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/../includes/UserMapRepository.php';
$config = include(__DIR__ . '/config.php');

checkRateLimit(120, 3600);

function userMapError($status, $message) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

$expected = (string)($config['user_map_api_key'] ?? '');
if ($expected === '') {
    userMapError(503, 'The user map is not enabled.');
}
$given = (string)($_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '');
if (!hash_equals($expected, $given)) {
    userMapError(401, 'Invalid API key.');
}

try {
    $repo = new UserMapRepository($config);
    $map = $repo->featureCollection();
} catch (Exception $e) {
    error_log('getUserMap.php: ' . $e->getMessage());
    userMapError(500, 'Unable to build the user map.');
}

header('Content-Type: application/geo+json');
header('Cache-Control: private, max-age=300');
echo json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
