<?PHP
/**
 * App Search API Endpoint
 *
 * Search for apps by title/id or author name.
 *
 * Query parameters:
 *   app     - Search by app title/id
 *   author  - Search by author name
 *   adult   - Include adult content (true/false, default false)
 *   onlyLuneOS - Only show LuneOS-tested apps (true/false, default false)
 *
 * Response: {"data": [{app}, {app}, ...]}
 */

require_once __DIR__ . '/../includes/AppRepository.php';
include("ratelimit.php");

// Rate limit: 60 requests per hour for search
checkRateLimit(60, 3600);

header('Content-Type: application/json');

// Parse search parameters
$search_str = $_SERVER["QUERY_STRING"];
$search_type = "app";

if (isset($_REQUEST["app"])) {
	$search_str = $_REQUEST["app"];
}
if (isset($_GET["app"])) {
	$search_str = $_GET["app"];
}

if (isset($_REQUEST["author"])) {
	$search_str = $_REQUEST["author"];
	$search_type = "author";
}
if (isset($_GET["author"])) {
	$search_str = $_GET["author"];
	$search_type = "author";
}

// Sanitize search string
$search_str = urldecode(strtolower($search_str));
$search_str = preg_replace("/[^a-zA-Z0-9 ]+/", "", $search_str);

// Parse filter options
$_adult = false;
if (isset($_GET['adult'])) {
	$_adult = filter_var($_GET['adult'], FILTER_VALIDATE_BOOLEAN);
}

$_onlyLuneOS = false;
if (isset($_GET['onlyLuneOS'])) {
	$_onlyLuneOS = filter_var($_GET['onlyLuneOS'], FILTER_VALIDATE_BOOLEAN);
}

// Perform search using repository
$repo = new AppRepository();

if ($search_type == "app") {
	$results = $repo->searchApps($search_str, $_adult);
} else {
	$results = $repo->searchByAuthor($search_str, $_adult);
}

// Filter for LuneOS if requested
if ($_onlyLuneOS) {
	$results = array_filter($results, function($app) {
		return !empty($app['LuneOS']);
	});
	$results = array_values($results); // Re-index array
}

// Build response
$responseObj = new stdClass();
$responseObj->data = $results;
echo json_encode($responseObj);
?>
