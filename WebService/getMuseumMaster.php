<?PHP
/**
 * Museum Master Catalog API Endpoint
 *
 * Main endpoint for browsing the app catalog with filtering, pagination,
 * and session-based optimization (returning null for known apps).
 *
 * Query parameters:
 *   key          - Session key (required for pagination optimization)
 *   device       - Device filter: 'All', 'Veer', 'Pre', 'Pre2', 'TouchPad', 'Pixi'
 *   category     - Category filter: 'All' or specific category name
 *   query        - Text search across title, author, summary
 *   page         - Page index (0-based)
 *   index        - Return single app by index (overwrites page/count)
 *   vendorId     - Filter by vendor ID
 *   count        - Items per page (default 20)
 *   excluded_appIds - Comma-separated app IDs to exclude
 *   blacklist    - Comma-separated vendor IDs to blacklist
 *   ignore_blacklist - Boolean
 *   hide_missing - Hide apps without IPK
 *   show_only_missing - Show only missing apps
 *   adult        - Include adult content
 *   onlyLuneOS   - Only LuneOS-tested apps
 *   museumVersion - Client version for backward compatibility
 *   useAppId     - Use appIds list mode
 *   appIds       - Comma-separated app IDs or "random"
 *   sort         - Sort order: 'recent' (default), 'alpha', or 'recommended'
 *
 * Response format (must be maintained for backward compatibility):
 * {
 *   "return_indices": [...],
 *   "indices": [...],
 *   "data": [{app}, null, ...],  // null for known apps
 *   "first_position": {"A": 0, "B": 5, "#": 100, ...},
 *   "request": {...},
 *   "extraData": {"listCount": n, ...},
 *   "appCount": {"All": n, "Games": n, ...}
 * }
 */

require_once __DIR__ . '/../includes/AppRepository.php';
require_once __DIR__ . '/../includes/MetadataRepository.php';
require_once __DIR__ . '/../includes/SessionRepository.php';
include('ratelimit.php');

// Rate limit: 120 requests per hour for main catalog
checkRateLimit(120, 3600);

header('Content-Type: application/json');

// Output buffer functions for gzip compression
function gMM_startOutputBuffer() {
	ob_start();
	ob_start('ob_gzhandler');
}

function gMM_endOutputBuffer() {
	ob_end_flush();
	$size = ob_get_length();
	header("Content-Encoding: gzip");
	header("Content-Length:{$size}");
	header("Connection: close");
	ob_end_flush();
	ob_flush();
	flush();
}

// Initialize repositories
$appRepo = new AppRepository();
$sessionRepo = new SessionRepository();
$metaRepo = new MetadataRepository();

// Parse session key - required for pagination optimization
$_key = @$_REQUEST['key'];
if (!isset($_key) || (isset($_REQUEST['page']) && $_REQUEST['page'] < 0)) {
	gMM_startOutputBuffer();
	echo(json_encode(array(
		"indices" => array(),
		"data"    => array()
	)));
	gMM_endOutputBuffer();
	$sessionRepo->cleanupOldSessions();
	die();
}

mb_internal_encoding("UTF-8");
$_sessionData = $sessionRepo->getSession($_key);

// Parse all query parameters
$_device      = isset($_REQUEST['device']) ? $_REQUEST['device'] : 'All';
$_category    = isset($_REQUEST['category']) ? $_REQUEST['category'] : 'All';
$_query       = isset($_REQUEST['query']) ? $_REQUEST['query'] : '';
$_page        = isset($_REQUEST['page']) ? (int)$_REQUEST['page'] : 0;
$_index       = isset($_REQUEST['index']) ? $_REQUEST['index'] : null;
$_vendorId    = isset($_REQUEST['vendorId']) ? $_REQUEST['vendorId'] : null;
$_count       = isset($_REQUEST['count']) ? (int)$_REQUEST['count'] : 20;
$_exclAppIds  = isset($_REQUEST['excluded_appIds']) ? $_REQUEST['excluded_appIds'] : '';
$_useAppId    = isset($_REQUEST['useAppId']) ? $_REQUEST['useAppId'] : false;
$_appIdList   = isset($_REQUEST['appIds']) ? $_REQUEST['appIds'] : '';
$_blacklisted = isset($_REQUEST['blacklist']) ? $_REQUEST['blacklist'] : '';
$_ignoreBL    = isset($_REQUEST['ignore_blacklist']) ? $_REQUEST['ignore_blacklist'] : false;
$_hideMissing = isset($_REQUEST['hide_missing']) ? $_REQUEST['hide_missing'] : false;
$_showOnlyMis = isset($_REQUEST['show_only_missing']) ? $_REQUEST['show_only_missing'] : false;
$_adult       = isset($_REQUEST['adult']) ? filter_var($_REQUEST['adult'], FILTER_VALIDATE_BOOLEAN) : false;
$_onlyLuneOS  = isset($_REQUEST['onlyLuneOS']) ? $_REQUEST['onlyLuneOS'] : false;
$_museumVersion = isset($_REQUEST['museumVersion']) ? $_REQUEST['museumVersion'] : "0.0.0";
$_sort        = isset($_REQUEST['sort']) && in_array($_REQUEST['sort'], ['alpha', 'recommended', 'recent']) ? $_REQUEST['sort'] : 'recent';

// Convert string booleans
if (gettype($_useAppId) === "string") { $_useAppId = strtolower($_useAppId) === "true"; }
if (gettype($_ignoreBL) === "string") { $_ignoreBL = strtolower($_ignoreBL) === "true"; }
if (gettype($_showOnlyMis) === "string") { $_showOnlyMis = strtolower($_showOnlyMis) === "true"; }
if (gettype($_hideMissing) === "string") { $_hideMissing = strtolower($_hideMissing) === "true"; }
if (gettype($_onlyLuneOS) === "string") { $_onlyLuneOS = strtolower($_onlyLuneOS) === "true"; }

// Parse comma-separated lists
$_exclAppIds = explode(",", $_exclAppIds);
$_appIdList = explode(",", $_appIdList);
$_blacklisted = explode(",", $_blacklisted);

$_device = !is_null($_vendorId) && !empty($_vendorId) ? "All" : $_device;
$_category = !is_null($_vendorId) && !empty($_vendorId) ? "All" : $_category;

if ($_showOnlyMis) {
	$_hideMissing = false;
}

$extraData = array();

// All apps are now in 'active' status (post_shutdown flag indicates community apps)
$statuses = ['active'];

// Load master data from database
$masterdata = $appRepo->loadCatalog($statuses, $_sort);

// Build filtered indices
$output = array();
$indices = array();
$return_indices = array();
$firstPos = array();

$appCount = array(
	"All" => 0,
	"Missing Apps" => 0
);

// Filter and build indices
foreach ($masterdata as $key => $app) {
	// Hidden missing filter (no longer used but kept for compatibility)
	if ($_hideMissing && $app['status'] === 'missing') {
		$appCount['Missing Apps']++;
		continue;
	}
	if ($_showOnlyMis && $app['status'] !== 'missing') {
		continue;
	}

	// Blacklist filter
	if (!$_ignoreBL && $_blacklisted[0] !== "" && in_array($app['vendorId'], $_blacklisted)) {
		continue;
	}

	// Adult filter
	if (!$_adult && $app['Adult']) {
		continue;
	}

	// LuneOS filter
	if ($_onlyLuneOS && !$app['LuneOS']) {
		continue;
	}

	// Device filter
	$validDevice = ($_device === 'All' || (isset($app[$_device]) && $app[$_device] === true));

	// Category filter (including virtual categories)
	if ($_category === 'Revisionist History') {
		$category = !empty($app['inRevisionistHistory']);
	} elseif ($_category === "Curator's Choice") {
		$category = !empty($app['inCuratorsChoice']);
	} else {
		$category = ($_category === 'All' || $app['category'] === $_category);
	}

	// Query filter
	$titleFound = empty($_query) || stripos($app['title'], $_query) !== false;
	$authorFound = empty($_query) || stripos($app['author'], $_query) !== false;
	$summaryFound = empty($_query) || stripos($app['summary'] ?? '', $_query) !== false;

	// Vendor filter
	$vendorId = !is_null($_vendorId) && !empty($_vendorId);

	if ($vendorId) {
		if ($app['vendorId'] === $_vendorId) {
			array_push($indices, $key);
		}
	} else {
		if ($validDevice && $category && ($titleFound || $authorFound || $summaryFound)) {
			array_push($indices, $key);
		}
	}

	// Count apps for appCount (excluding category filter for accurate counts)
	if ($validDevice && ($titleFound || $authorFound || $summaryFound)) {
		$appCount["All"]++;
		if (isset($appCount[$app['category']])) {
			$appCount[$app['category']]++;
		} else {
			$appCount[$app['category']] = 1;
		}
		if ($app['status'] === 'missing') {
			$appCount['Missing Apps']++;
		}
		// Virtual category counts (not added to All total - they overlap real categories)
		if (!empty($app['inRevisionistHistory'])) {
			if (!isset($appCount['Revisionist History'])) {
				$appCount['Revisionist History'] = 0;
			}
			$appCount['Revisionist History']++;
		}
		if (!empty($app['inCuratorsChoice'])) {
			if (!isset($appCount["Curator's Choice"])) {
				$appCount["Curator's Choice"] = 0;
			}
			$appCount["Curator's Choice"]++;
		}
	}
}

// Handle random app request
$_random = false;
if (count($_appIdList) === 1 && $_appIdList[0] === "random") {
	$_useAppId = false;
	$_random = true;
	if (count($indices) > 0) {
		$_index = array_rand($indices);
		$extraData['randomOffset'] = $_index;
	}
}

// Handle vendor request (return all at once)
if (!is_null($_vendorId) && !empty($_vendorId)) {
	$_page = 0;
	$_count = count($indices);
}

// Calculate pagination
$top = (int)$_page * (int)$_count;
if (!is_null($_index)) {
	$top = (int)$_index;
	if (!$_random) {
		$_count = 1;
	}
}

// Build output based on mode
switch ($_useAppId) {
	case true:
		// Use specific app IDs
		$count = 0;
		$lastChar = "";
		$biggerThanZ = false;

		foreach ($masterdata as $key => $app) {
			if (in_array($app['id'], $_appIdList)) {
				$app['archived'] = true;
				$app['_archived'] = false;
				if ($app['status'] === 'missing') {
					$app['archived'] = false;
					$app['_archived'] = true;
				}

				array_push($output, $app);
				array_push($indices, $key);
				array_push($return_indices, $count);

				// Build first position map
				$firstLetter = mb_strtoupper(mb_substr($app['title'], 0, 1));
				if ($firstLetter !== $lastChar) {
					if ($firstLetter < "A") {
						$firstPos["#"] = 0;
					} else if ($firstLetter > "Z" && !isset($firstPos["%"])) {
						$firstPos["%"] = $key;
					} else if ($firstLetter <= "Z" || ($firstLetter > "Z" && !$biggerThanZ)) {
						$lastChar = $firstLetter;
						$firstPos[$firstLetter] = $key;
						$biggerThanZ = $firstLetter > "Z";
					}
				}
				$count++;
			}
		}
		$extraData['listCount'] = count($_appIdList);
		break;

	default:
		// Standard pagination mode
		$lastChar = "";
		$biggerThanZ = false;

		$bottom = (int)$top + (int)$_count;
		$temp = array();

		for ($i = $top; $i < $bottom; $i++) {
			if (!isset($indices[$i])) { break; }

			$appKey = $indices[$i];
			$app = $masterdata[$appKey];

			if ($_random || !in_array($appKey, $_sessionData['knownIdx'])) {
				// Return full data if client doesn't know this app
				$app['archived'] = true;
				$app['_archived'] = false;
				if ($app['status'] === 'missing') {
					$app['archived'] = false;
					$app['_archived'] = true;
				}

				// For random app, include detail data
				if ($_random && $i === (int)$_index) {
					$detail = $metaRepo->getMetadata($app['id']);
					if ($detail) {
						$app['detail'] = $detail;
					}
				}

				array_push($output, $app);
			} else {
				// Return null for apps client already knows
				array_push($output, null);
			}

			array_push($return_indices, $i);
			array_push($temp, $appKey);
		}

		$extraData['listCount'] = count($indices);

		// Build first position map for all indices
		for ($i = 0; $i < count($indices); $i++) {
			$app = $masterdata[$indices[$i]];
			$firstLetter = mb_strtoupper(mb_substr($app['title'], 0, 1));
			if ($firstLetter !== $lastChar) {
				if ($firstLetter < "A") {
					$firstPos["#"] = 0;
				} else if ($firstLetter <= "Z" || ($firstLetter > "Z" && !$biggerThanZ)) {
					$lastChar = $firstLetter;
					$firstPos[$firstLetter] = $i;
					$biggerThanZ = $firstLetter > "Z";
				}
			}
		}

		$indices = $temp;
		break;
}

// Build final response
$data = array(
	"return_indices" => $return_indices,
	"indices"        => $indices,
	"data"           => $output,
	"first_position" => $firstPos,
	"request"        => $_REQUEST,
	"extraData"      => $extraData,
	"appCount"       => $appCount
);

// Output response
gMM_startOutputBuffer();
echo(json_encode($data));
gMM_endOutputBuffer();

// Update session with newly returned indices
foreach ($data['indices'] as $k => $idx) {
	if (!in_array($idx, $_sessionData['knownIdx'])) {
		array_push($_sessionData['knownIdx'], $idx);
	}
}
sort($_sessionData['knownIdx']);

$sessionRepo->storeSession($_key, $_sessionData);
$sessionRepo->cleanupOldSessions();
?>
