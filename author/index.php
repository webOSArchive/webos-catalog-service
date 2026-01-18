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
<meta name="viewport" content="width=760, initial-scale=0.6">
<?php
//Figure out where to go back to
parse_str($_SERVER["QUERY_STRING"], $query);
unset($query["app"]);
$homePath = $protocol . $config["service_host"]. "";
?>
<title><?php echo htmlspecialchars($author_data['author']); ?> - webOS App Museum II</title>
<link rel="stylesheet" href="<?php echo $protocol . $config["service_host"]; ?>/webmuseum.css">
<?php
//Social media meta
$protocol = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off') || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$currurl = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
?>
<meta name="description" content="webOS App Museum II is the definitive historical archive of legacy Palm/HP webOS mobile apps and games!" />
<link rel="canonical" href="<?php echo $currurl; ?>" />
<meta property="og:locale" content="en_US" />
<meta property="og:type" content="website" />
<meta property="og:title" content="<?php echo htmlspecialchars($author_data['author']); ?>'s Apps on webOS App Museum II" />
<meta property="og:description" content="webOS App Museum II is the definitive historical archive of legacy Palm/HP webOS mobile apps and games!" />
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
<meta name="twitter:title" content="<?php echo htmlspecialchars($author_data['author']); ?>'s Apps on webOS App Museum II" />
<meta name="twitter:description" content="webOS App Museum II is the definitive historical archive of legacy Palm/HP webOS mobile apps and games!" />
<meta name="twitter:image" content="<?php echo $use_icon; ?>" />
</head>
<body>
<?php include("../menu.php") ?>
<div class="show-museum" style="margin-right:1.3em">
	<h2><a href="<?php echo ($homePath); ?>"><img src="<?php echo $protocol . $config["service_host"]; ?>/assets/icon.png" style="height:64px;width:64px;margin-top:-10px;" align="middle"></a> &nbsp;<a href="<?php echo ($homePath); ?>">webOS App Museum II</a></h2>
	<br>
	<table border="0" style="margin-left:1.3em; width:100%; margin-bottom: 40px;">
		<tr>
			<td colspan="2">
				<h1><?php echo htmlspecialchars($author_data['author']); ?></h1>
				<?php if (!empty($author_data['summary'])) { echo "<p>" . htmlspecialchars($author_data['summary']) . "</p>"; } ?>
				<?php
					if (!empty($author_data['sponsorMessage'])) {
						echo "<p>" . htmlspecialchars($author_data['sponsorMessage']);
						if (!empty($author_data['sponsorLink'])) {
							echo "<br><a href='" . htmlspecialchars($author_data['sponsorLink']). "'>" . htmlspecialchars($author_data['sponsorLink']) . "</a>";
						}
						echo "</p>";
					}
				?>
				<?php
					if (!empty($author_data['socialLinks'])) {
						//Social icons by Shawn Rubel
						foreach($author_data['socialLinks'] as $social) {
							echo "<a href='" . htmlspecialchars($social) . "'>" . render_social($social, $protocol . $config["service_host"]) . "</a> ";
						}
					}
				?>
			</td>
			<td rowspan="2" valign="top">
				<?php
				$icon_src = !empty($author_data['iconBig']) ? $author_icon_base . $author_data['iconBig'] : '../author.png';
				?>
				<img src="<?php echo $icon_src; ?>" class="appIcon" onerror="this.onerror=null; this.src='../author.png';" >
			</td>
		</tr>
	</table>
	<div style="margin-left:20px">
	<h3>Apps by <?php echo htmlspecialchars($author_data["author"]); ?>:</h3>
	<?php
		echo("<table cellpadding='5'>");
		if (isset($app_response)) {
			foreach($app_response["data"] as $app) {
				if (strpos($app["appIcon"], "://") === false) {
					$use_img = $img_path.strtolower($app["appIcon"]);
				} else {
					$use_img = $app["appIcon"];
				}
				echo("<tr><td align='center' valign='top'><a href='{$protocol}{$config["service_host"]}/showMuseumDetails.php?{$_SERVER["QUERY_STRING"]}&app={$app["id"]}'><img style='width:64px; height:64px' src='{$use_img}' border='0'></a>");
				echo("<td width='100%' style='padding-left: 14px'><b><a href='{$protocol}{$config["service_host"]}/showMuseumDetails.php?{$_SERVER["QUERY_STRING"]}&app={$app["id"]}'>" . htmlspecialchars($app["title"]) . "</a></b><br/>");
				echo("<small>" . htmlspecialchars(substr($app["summary"] ?? '',0, 180)) . "...</small><br/>&nbsp;");
				echo("</td></tr>");
			}
		}
		echo("</table>");
	?>
	</div>
	<?php
	include '../footer.php';
	?>
	<div style="display:none;margin-top:18px">
</div>
</body>
</html>
