<?PHP
/**
 * App Details API Endpoint
 *
 * Returns detailed metadata for a single app.
 *
 * Query parameters:
 *   id     - App ID (required)
 *   appIds - If set to "random", returns data without compression for internal use
 *
 * Response: Full app metadata JSON
 */

require_once __DIR__ . '/../includes/MetadataRepository.php';
require_once __DIR__ . '/../includes/AppRepository.php';
include('ratelimit.php');

// Rate limit: 200 requests per hour for app details
checkRateLimit(200, 3600);

header('Content-Type: application/json');

$id = @$_GET['id'];

function gMD_startOutputBuffer() {
	ob_start();					// Buffer all upcoming output...
	ob_start('ob_gzhandler');	// ...and make sure it will be compressed with either gzip or deflate if accepted by the client
}

function gMD_endOutputBuffer() {
	ob_end_flush();						// Flush the gzipped buffer

	$size = ob_get_length();			// get the size of our output
	header("Content-Encoding: gzip");	// ensure compression
	header("Content-Length:{$size}");	// set the content length of the response
	header("Connection: close");		// close the connection

	ob_end_flush();						// Flush all output
	ob_flush();
	flush();
}

// Validate app ID
if (!isset($id) || !is_numeric($id)) {
	http_response_code(400);
	echo json_encode(['error' => 'Invalid or missing app ID']);
	exit;
}

// Get metadata from database
$repo = new MetadataRepository();
$metadata = $repo->getMetadata((int)$id);

if (!$metadata) {
	http_response_code(404);
	echo json_encode(['error' => 'App not found']);
	exit;
}

// Add related apps
$appRepo = new AppRepository();
$metadata['relatedApps'] = $appRepo->getRelatedApps((int)$id, 10);

// Return data - with or without compression based on appIds parameter
if (!isset($_REQUEST['appIds']) || $_REQUEST['appIds'] !== "random") {
	gMD_startOutputBuffer();
	echo json_encode($metadata);
	gMD_endOutputBuffer();
} else {
	// Return without compression for internal use (e.g., random app in getMuseumMaster)
	echo json_encode($metadata);
}
?>
