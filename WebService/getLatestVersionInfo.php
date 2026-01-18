<?PHP
$config = include('config.php');
header('Content-Type: application/json');
include('../common.php');
require_once __DIR__ . '/../includes/LogRepository.php';

//Load archives
$fullcatalog = load_catalogs();

// Initialize log repository
$logRepo = null;
try {
    $logRepo = new LogRepository();
} catch (Exception $e) {
    error_log("Non-fatal error: " . $_SERVER['SCRIPT_NAME'] . " was unable to connect to database: " . $e->getMessage(), 0);
}

$found_id = "null";
//Determine what the request was
$devicedata = str_replace(",", "", $_SERVER['HTTP_USER_AGENT']);
if (isset($_COOKIE["clientid"])) {
	$clientinfo = $_COOKIE['clientid'];
} else {
	$clientinfo = uniqid();
	setcookie ("clientid", $clientinfo, 2147483647);
}
if (isset($_GET["clientid"])) {
	$clientinfo = $_GET["clientid"];
}
if (isset($_GET["app"]))
{
	$search_str = $_GET["app"];
	if (isset($_GET["device"])) {
		$devicedata = $_GET["device"];
	}
	if (isset($_GET["client"])) {
		$clientinfo = $_GET["client"];
	}
}
else
{
	$search_str = $_SERVER["QUERY_STRING"];
}
$search_str = urldecode(strtolower($search_str));

if ($search_str == "0" ||	//Treat the museum itself differently
 $search_str == "app museum" ||
 $search_str == "app museum 2" ||
 $search_str == "app museum ii" ||
 $search_str == "appmuseum" ||
 $search_str == "appmuseum2" ||
 $search_str == "appmuseumii" ||
 $search_str == "appmuseum.museumapp" )
{
	if ($logRepo) { logUpdateCheck($logRepo, "app museum 2", $devicedata, $clientinfo); }
	$found_id = "0";
	$meta_path = __DIR__ . '/../0.json';
}
else	//Any other app
{
	if ($logRepo) { logUpdateCheck($logRepo, $search_str, $devicedata, $clientinfo); }
	//strip out version number if present
	$name_parts = explode("/", $search_str);
	$search_str = $name_parts[0];

	foreach ($fullcatalog as $this_app => $app_a) {
		if (strtolower($app_a["title"]) == $search_str || $app_a["id"] == $search_str) {
			//echo ("Found app: " . $app_a["title"] . "-" . $app_a["id"] . ".json<br>");
			$found_id = $app_a["id"];
		}
	}

	if ($found_id == "null") {
		echo "{\"error\": \"No matching app found for " . $search_str . "\"}";
		die;
	}
	$meta_path = "http://" . $config["service_host"] . "/WebService/getMuseumDetails.php?id=" . $found_id;
}

if (isset($meta_path)) {
	$meta_file = fopen($meta_path, "rb");
	$content = stream_get_contents($meta_file);
	fclose($meta_file);

	$json_m = json_decode($content, true);
	if (strpos($json_m["filename"], "://") === false) {
		$use_uri = "http://" . $config["package_host"] . '/' . $json_m["filename"];
	} else {
		$use_uri = $json_m["filename"];
	}
	$outputObj = array (
		"version" => $json_m["version"],
		"versionNote" => get_last_version_note($json_m["versionNote"]),
		"lastModifiedTime" => $json_m["lastModifiedTime"],
		"downloadURI" => $use_uri,
	);
}
echo (json_encode($outputObj));

function get_last_version_note($versionNote){
	$lastVersionNote = explode("\r\n", $versionNote);
	return end($lastVersionNote);
}

function logUpdateCheck($logRepo, $appname, $devicedata, $clientinfo) {
	try {
		$ipAddress = getVisitorIP();
		$logRepo->logUpdateCheck($appname, $devicedata, $clientinfo, $clientinfo, $ipAddress);
	} catch (Exception $e) {
		error_log("Non-fatal error logging update check: " . $e->getMessage(), 0);
	}
}

function getVisitorIP() {
	// Get real visitor IP behind CloudFlare network
	if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
		return $_SERVER["HTTP_CF_CONNECTING_IP"];
	}

	$client = @$_SERVER['HTTP_CLIENT_IP'];
	$forward = @$_SERVER['HTTP_X_FORWARDED_FOR'];
	$remote = $_SERVER['REMOTE_ADDR'];

	if (filter_var($client, FILTER_VALIDATE_IP)) {
		return $client;
	} elseif (filter_var($forward, FILTER_VALIDATE_IP)) {
		return $forward;
	}
	return $remote;
}
?>
