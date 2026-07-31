<?php
$config = include('../WebService/config.php');
include('../common.php');
require_once __DIR__ . '/../includes/AppRepository.php';

session_start();
if (!isset($_SESSION['encode_salt']))
{
	$_SESSION['encode_salt'] = uniqid();
}

//figure out what protocol to use
if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
    $protocol = "https://";
else
    $protocol = "http://";

//figure out where images are
$img_path = $protocol . $config["image_host"] . "/";

//figure out what they're looking for
$req = explode('/', $_SERVER['REQUEST_URI']);
$query = end($req);
$favicon_search = false;
if ($query == "favicon.ico") {	//this is a special case in support of Enyo front-end
	array_pop($req);
	$query = end($req);
	$favicon_search = true;
}

// Search for apps by this author using database
$appRepo = new AppRepository();
$search_str = urldecode(strtolower($query));
$search_str = preg_replace("/[^a-zA-Z0-9 ]+/", "", $search_str);

$results = $appRepo->searchByAuthor($search_str, false);
$app_response = create_app_response($results);

//find info about author
// from query (default)
$author_data = [
	"author" => mb_convert_case(urldecode($query), MB_CASE_TITLE),
	"favicon" => null,
	"iconBig" => null
];

// from app results list (better)
if (isset($app_response) && isset($app_response["data"][0]) && isset($app_response["data"][0]["author"])) {
	$author_data["author"] = $app_response["data"][0]["author"];
}

// from database (best)
if (isset($app_response) && isset($app_response["data"][0]) && isset($app_response["data"][0]["vendorId"])) {
	$vendorId = $app_response["data"][0]["vendorId"];
	$db_author = $appRepo->getAuthorByVendorId($vendorId);
	if ($db_author) {
		$author_data = $db_author;
	}
}

// Build icon paths for display
$author_icon_base = $protocol . $config["image_host"] . "/authors/";
if (isset($app_response["data"][0]["vendorId"])) {
	$author_icon_base .= $app_response["data"][0]["vendorId"] . "/";
}

// Set icon for social media meta tags
$use_icon = "https://appcatalog.webosarchive.org/assets/webos-apps.png";
if (!empty($author_data['iconBig'])) {
	$use_icon = $author_icon_base . $author_data['iconBig'];
}

// Handle favicon request
if ($favicon_search) {
	if (!empty($author_data['favicon'])) {
		$favicon_url = $author_icon_base . $author_data['favicon'];
		$image = @file_get_contents($favicon_url);
		if ($image) {
			header('content-type: image/x-icon');
			echo $image;
			exit;
		}
	}
	http_response_code(404);
	exit;
}
?>
<html>
<head>
<link rel="shortcut icon" href="<?php echo !empty($author_data['favicon']) ? $author_icon_base . $author_data['favicon'] : '../favicon.ico'; ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
//Figure out where to go back to
parse_str($_SERVER["QUERY_STRING"], $query);
unset($query["app"]);
$homePath = $protocol . $config["service_host"]. "";
?>
<title><?php echo htmlspecialchars($author_data['author']); ?> - webOS App Museum</title>
<link rel="stylesheet" href="<?php echo $protocol . $config["service_host"]; ?>/museum-modern.css">
<?php
//Social media meta
$protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
// Escape host + request URI before reflecting into meta tag URLs (prevents reflected XSS)
$currurl = htmlspecialchars($protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], ENT_QUOTES);
?>
<meta name="description" content="webOS App Museum is the definitive historical archive of legacy Palm/HP webOS mobile apps and games!" />
<link rel="canonical" href="<?php echo $currurl; ?>" />
<meta property="og:locale" content="en_US" />
<meta property="og:type" content="website" />
<meta property="og:title" content="<?php echo htmlspecialchars($author_data['author']); ?>'s Apps on webOS App Museum" />
<meta property="og:description" content="webOS App Museum is the definitive historical archive of legacy Palm/HP webOS mobile apps and games!" />
<meta property="og:url" content="<?php echo $currurl; ?>" />
<meta property="og:site_name" content="webOS App Museum" />
<meta property="article:published_time" content="<?php echo date('m/d/Y H:i:s', time()); ?>" />
<meta property="article:modified_time" content="<?php echo date('m/d/Y H:i:s', time()); ?>" />
<meta property="og:image" content="https://appcatalog.webosarchive.org/assets/webos-apps.png" />
<meta property="og:image:width" content="250" />
<meta property="og:image:height" content="260" />
<meta property="og:image:type" content="image/png" />
<meta name="author" content="webOS Archive" />
<meta name="twitter:card" content="summary" />
<meta name="twitter:title" content="<?php echo htmlspecialchars($author_data['author']); ?>'s Apps on webOS App Museum" />
<meta name="twitter:description" content="webOS App Museum is the definitive historical archive of legacy Palm/HP webOS mobile apps and games!" />
<meta name="twitter:image" content="<?php echo $use_icon; ?>" />
</head>
<body>
<?php include("../menu.php") ?>
<div class="mm-wrap">
	<div class="mm-head">
		<a class="mm-head-icon" href="<?php echo ($homePath); ?>"><img src="<?php echo $protocol . $config["service_host"]; ?>/assets/icon.png" alt="webOS App Museum"></a>
		<a class="mm-head-text" href="<?php echo ($homePath); ?>"><span class="mm-title">webOS App Museum</span><span class="mm-sub">A historical archive of Palm / HP webOS apps</span></a>
	</div>

	<div class="mm-detail">
		<div class="mm-hero">
			<div class="mm-hero-icon">
				<?php $icon_src = !empty($author_data['iconBig']) ? $author_icon_base . $author_data['iconBig'] : '../author.png'; ?>
				<img src="<?php echo htmlspecialchars($icon_src, ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($author_data['author'], ENT_QUOTES); ?>" onerror="this.onerror=null; this.src='../author.png';">
			</div>
			<div class="mm-hero-info">
				<h1><?php echo htmlspecialchars($author_data['author']); ?></h1>
				<?php if (!empty($author_data['summary'])) { echo "<div class='mm-desc' style='margin-bottom:10px;'>" . htmlspecialchars($author_data['summary']) . "</div>"; } ?>
				<?php
					if (!empty($author_data['sponsorMessage'])) {
						echo "<div class='mm-desc' style='margin-bottom:10px;'>" . htmlspecialchars($author_data['sponsorMessage']);
						if (!empty($author_data['sponsorLink'])) {
							echo "<br><a href='" . htmlspecialchars($author_data['sponsorLink'], ENT_QUOTES) . "'>" . htmlspecialchars($author_data['sponsorLink']) . "</a>";
						}
						echo "</div>";
					}
				?>
				<?php
					if (!empty($author_data['socialLinks'])) {
						//Social icons by Shawn Rubel
						echo "<div style='margin-top:8px;'>";
						foreach($author_data['socialLinks'] as $social) {
							echo "<a href='" . htmlspecialchars($social, ENT_QUOTES) . "'>" . render_social($social, $protocol . $config["service_host"]) . "</a> ";
						}
						echo "</div>";
					}
				?>
			</div>
		</div>

		<div class="mm-section-title">Apps by <?php echo htmlspecialchars($author_data["author"]); ?></div>
		<?php
		// Escape the raw query string for safe use inside HTML href attributes (prevents reflected XSS)
		$qs = htmlspecialchars($_SERVER["QUERY_STRING"], ENT_QUOTES);
		$svc = $protocol . $config["service_host"];
		$appCount = (isset($app_response) && !empty($app_response["data"])) ? count($app_response["data"]) : 0;
		if ($appCount > 0) {
			foreach($app_response["data"] as $app) {
				if (strpos($app["appIcon"], "://") === false) {
					$use_img = $img_path.strtolower($app["appIcon"]);
				} else {
					$use_img = $app["appIcon"];
				}
				$detailUrl = $svc . "/showMuseumDetails.php?{$qs}&app={$app["id"]}";
				echo "<a class='mm-app' href='" . htmlspecialchars($detailUrl, ENT_QUOTES) . "'>";
				echo   "<span class='mm-app-icon'><img src='" . htmlspecialchars($use_img, ENT_QUOTES) . "' alt='' onerror=\"this.src='" . htmlspecialchars($svc, ENT_QUOTES) . "/assets/icon.png';\"></span>";
				echo   "<span class='mm-app-body'>";
				echo     "<span class='mm-app-title'>" . htmlspecialchars($app["title"]) . "</span>";
				echo     "<span class='mm-app-summary'>" . htmlspecialchars(substr($app["summary"] ?? '', 0, 180)) . "&hellip;</span>";
				echo   "</span>";
				echo "</a>";
			}
		} else {
			echo "<p class='mm-noresult'>No apps found for this author.</p>";
		}
		?>

		<?php include '../footer.php'; ?>
	</div>
</div>
</body>
</html>
