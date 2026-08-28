<?php
$config = include('../WebService/config.php');
include("../common.php");

//figure out what protocol to use
if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
    $protocol = "https://";
else
    $protocol = "http://";

//figure out what they're looking for
$req = explode('/', $_SERVER['REQUEST_URI']);
$query = end($req);
$query = str_replace("+", "", $query);
$dest_page = $protocol. $config["service_host"];

//get the results directly without HTTP request to avoid rate limiting
$fullcatalog = load_catalogs();
$search_str = urldecode(strtolower($query));
$search_str = preg_replace("/[^a-zA-Z0-9 ]+/", "", $search_str);

$results = search_apps($fullcatalog, $search_str, false, true);
$app_response = create_app_response($results);

//send them to result if it's the only or an exact-title match, else the search page
$dest_page = $protocol. $config["service_host"];
// Search now also matches author/summary/description, so a title slug can return
// several apps. Still deep-link when the top result's title matches the requested
// slug (searchApps ranks an exact title first, so data[0] is that match).
$topId = null;
if (isset($app_response['data'][0])) {
    $normalize = function ($s) { return preg_replace('/[^a-z0-9]+/', '', strtolower($s)); };
    $wanted = $normalize($search_str);
    $topTitle = $normalize($app_response['data'][0]['title'] ?? '');
    if (count($app_response['data']) == 1 || ($wanted !== '' && $topTitle === $wanted)) {
        $topId = $app_response['data'][0]['id'];
    }
}
if ($topId !== null) {
    $dest_page .= "/showMuseumDetails.php?app=" . $topId;
} else {
    $dest_page .= "/showMuseum.php?search=" . $query;
}
//echo $dest_page;
header("Location: $dest_page");
?>
