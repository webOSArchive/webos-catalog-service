<?php include('tldchange-notice.php'); ?>
<html>
<head>
<link rel="shortcut icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>
function showHelp() {
	alert("Most webOS Devices should use the App Museum II native app to browse and install from the catalog. Older devices that can't run the Museum can Option+Tap (Orange or White Key) or Long Press (if enabled) on the Preware link on this page and copy it to your clipboard. Then you can use the 'Install Package' menu option in Preware to paste in and install the app using that link.");
}

/* Lightbox for screenshots - ES5 compatible with fallback */
var lightboxImages = [];
var lightboxIndex = 0;

function openLightbox(src, index) {
	try {
		var overlay = document.getElementById('lightbox-overlay');
		var img = document.getElementById('lightbox-img');
		if (!overlay || !img) {
			return true; /* fallback to normal link */
		}
		img.src = src;
		lightboxIndex = index || 0;
		updateNavVisibility();
		overlay.style.display = 'block';
		return false; /* prevent default link behavior */
	} catch (e) {
		return true; /* fallback on any error */
	}
}

function closeLightbox() {
	try {
		var overlay = document.getElementById('lightbox-overlay');
		if (overlay) {
			overlay.style.display = 'none';
		}
	} catch (e) {
		/* ignore errors on close */
	}
}

function prevImage(e) {
	if (e && e.stopPropagation) {
		e.stopPropagation();
	} else if (window.event) {
		window.event.cancelBubble = true;
	}
	try {
		if (lightboxImages.length > 1 && lightboxIndex > 0) {
			lightboxIndex--;
			var img = document.getElementById('lightbox-img');
			if (img) {
				img.src = lightboxImages[lightboxIndex];
				updateNavVisibility();
			}
		}
	} catch (e) {
		/* ignore errors */
	}
}

function nextImage(e) {
	if (e && e.stopPropagation) {
		e.stopPropagation();
	} else if (window.event) {
		window.event.cancelBubble = true;
	}
	try {
		if (lightboxImages.length > 1 && lightboxIndex < lightboxImages.length - 1) {
			lightboxIndex++;
			var img = document.getElementById('lightbox-img');
			if (img) {
				img.src = lightboxImages[lightboxIndex];
				updateNavVisibility();
			}
		}
	} catch (e) {
		/* ignore errors */
	}
}

function updateNavVisibility() {
	try {
		var prevBtn = document.getElementById('lightbox-prev');
		var nextBtn = document.getElementById('lightbox-next');
		if (prevBtn) {
			prevBtn.style.visibility = (lightboxIndex > 0) ? 'visible' : 'hidden';
		}
		if (nextBtn) {
			nextBtn.style.visibility = (lightboxIndex < lightboxImages.length - 1) ? 'visible' : 'hidden';
		}
	} catch (e) {
		/* ignore errors */
	}
}

/* Handle keyboard navigation: Escape to close, arrows to navigate */
function handleLightboxKey(e) {
	e = e || window.event;
	var key = e.keyCode || e.which;
	var overlay = document.getElementById('lightbox-overlay');
	if (!overlay || overlay.style.display !== 'block') {
		return;
	}
	if (key === 27) { /* Escape */
		closeLightbox();
	} else if (key === 37) { /* Left arrow */
		prevImage();
	} else if (key === 39) { /* Right arrow */
		nextImage();
	}
}
if (document.addEventListener) {
	document.addEventListener('keydown', handleLightboxKey);
} else if (document.attachEvent) {
	document.attachEvent('onkeydown', handleLightboxKey);
}
</script>
<style>
#lightbox-overlay {
	display: none;
	position: fixed;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	background-color: #000000;
	background-color: rgba(0, 0, 0, 0.85);
	text-align: center;
	z-index: 9999;
	cursor: pointer;
}
#lightbox-overlay img {
	max-width: 90%;
	max-height: 90%;
	margin-top: 2%;
	border: 2px solid #ffffff;
}
#lightbox-close {
	position: absolute;
	top: 10px;
	right: 20px;
	color: #ffffff;
	font-size: 30px;
	font-weight: bold;
	cursor: pointer;
}
#lightbox-close:hover {
	color: #cccccc;
}
#lightbox-prev, #lightbox-next {
	position: absolute;
	top: 50%;
	margin-top: -25px;
	color: #ffffff;
	font-size: 50px;
	font-weight: bold;
	cursor: pointer;
	padding: 5px 15px 15px 15px;
	background-color: transparent;
	background-color: rgba(0, 0, 0, 0.3);
	-webkit-user-select: none;
	-moz-user-select: none;
	-ms-user-select: none;
	user-select: none;
}
#lightbox-prev {
	left: 10px;
}
#lightbox-next {
	right: 10px;
}
#lightbox-prev:hover, #lightbox-next:hover {
	background-color: rgba(0, 0, 0, 0.6);
	color: #cccccc;
}
/* The JS-populated download link sits inside a button-styled box */
.mm-dl a, .mm-dl a:hover {
	color: #ffffff;
	text-decoration: none;
}
</style>

<?php
$config = include('WebService/config.php');
include('common.php');
require_once __DIR__ . '/includes/AppRepository.php';
require_once __DIR__ . '/includes/MetadataRepository.php';
// Use config-based secret for URL encoding (allows direct links to be shareable)
$encode_secret = $config['download_secret'] ?? 'webos_archive_default_secret';
// Find app - use direct ID lookup for numeric IDs, search for text
$found_app = null;
$found_id = null;
$appRepo = new AppRepository();

if (isset($_GET["app"])) {
	$search_str = $_GET["app"];
	$search_str = urldecode($search_str);

	// If numeric, do direct ID lookup (much faster)
	if (is_numeric($search_str)) {
		$found_app = $appRepo->getById((int)$search_str);
		if ($found_app) {
			// Normalize field names to match expected format
			$found_app['appIconBig'] = $found_app['app_icon_big'] ?? '';
			$found_app['Pre'] = $found_app['pre'] ?? false;
			$found_app['Pixi'] = $found_app['pixi'] ?? false;
			$found_app['Pre2'] = $found_app['pre2'] ?? false;
			$found_app['Pre3'] = $found_app['pre3'] ?? false;
			$found_app['Veer'] = $found_app['veer'] ?? false;
			$found_app['TouchPad'] = $found_app['touchpad'] ?? false;
			$found_app['LuneOS'] = $found_app['luneos'] ?? false;
			$found_id = $found_app['id'];
		}
	} else {
		// Text search - sanitize and search
		$search_str = strtolower($search_str);
		$search_str = preg_replace("/[^a-zA-Z0-9 ]+/", "", $search_str);
		$results = $appRepo->searchApps($search_str, true); // Include adult content
		if (count($results) > 0) {
			$found_app = $results[0];
			$found_id = $found_app["id"];
		}
	}
}

if (!$found_app) {
	echo("ERROR: No matching app found");
	die;
}

//Figure out what protocol the client wanted
if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
    $PROTOCOL = "https://";
else
    $PROTOCOL = "http://";

// Get app detail data from database first, fallback to metadata host
$metaRepo = new MetadataRepository();
$app_detail = $metaRepo->getMetadata((int)$found_id);

// Note: External metadata host fallback removed - all metadata should be in database
// If an app has no metadata, the page will show with empty fields rather than timing out

//Improve some strings for web output
$img_path = $PROTOCOL . $config["image_host"] . "/";
if (isset($app_detail["description"])) {
	$app_detail["description"] = str_replace("\n", "<br>", $app_detail["description"]);
	$app_detail["description"] = str_replace("\r\n", "<br>", $app_detail["description"]);
} else {
	$app_detail["description"] = "";
}
if (isset($app_detail["versionNote"])) {
	$app_detail["versionNote"] = str_replace("\n", "<br>", $app_detail["versionNote"]);
	$app_detail["versionNote"] = str_replace("\r\n", "<br>", $app_detail["versionNote"]);
} else {
	$app_detail["versionNote"] = "";
}

//Let's make some URLs!
$author_url = "author/" . str_replace(" " , "%20", $found_app["author"]);
$share_url = $PROTOCOL . $config["service_host"] . "/app/" . str_replace(" " , "", $found_app["title"]);
//Support absolute download paths (files hosted elsewhere)
//Always use HTTP for package downloads (storage host doesn't have SSL)
if (isset($app_detail["filename"]) && strpos($app_detail["filename"], "://") === false) {
	$plainURI = "http://" . $config["package_host"] . "/" . $app_detail["filename"];
} else {
	$plainURI = $app_detail["filename"];
	$plainURI = str_replace("https://", "http://", $plainURI);
}
//alternateFileName
if (isset($app_detail["alternateFileName"]) && strpos($app_detail["alternateFileName"], "://") === false) {
	$altPlainURI = "http://" . $config["package_host"] . "/" . $app_detail["alternateFileName"];
}
//Encode URL to reduce brute force downloads
//	The complete archive will be posted elsewhere to save my bandwidth
$downloadURI = base64_encode($plainURI);
$splitPos = rand(1, strlen($downloadURI) - 2);
$downloadURI = substr($downloadURI, 0, $splitPos) . $encode_secret . substr($downloadURI, $splitPos);
if (isset($altPlainURI)) {
	$altDownloadURI = base64_encode($altPlainURI);
	$splitPos = rand(1, strlen($altDownloadURI) - 2);
	$altDownloadURI = substr($altDownloadURI, 0, $splitPos) . $encode_secret . substr($altDownloadURI, $splitPos);
}

//Figure out where to go back to
parse_str($_SERVER["QUERY_STRING"], $query);
unset($query["app"]);
$homePath = "showMuseum.php?" . http_build_query($query);

//Figure out image paths
if (strpos($found_app["appIconBig"], "://") === false) {
	$use_icon = $img_path.strtolower($found_app["appIconBig"]);
} else {
	$use_icon = $found_app["appIconBig"];
}

//Shorten description for social media
$meta_desc = str_replace($app_detail["description"], "/r", "<br>");
$meta_desc = str_replace($app_detail["description"], "/n", "<br>");
$meta_desc = explode("<br>", $app_detail["description"]);
$meta_desc = trim($meta_desc[0]);

//Add social media meta tags
include('meta-social-app.php');

//Helper: is a device supported? (keeps original truthiness semantics)
function mm_dev_on($app, $key) {
	return isset($app[$key]) && !empty($app[$key]);
}
?>
<title><?php echo $found_app["title"] ?> - webOS App Museum II</title>
<link rel="stylesheet" href="museum-modern.css">
<script src="downloadHelper.php"></script>
</head>
<body onload="populateLink()">
<!-- Lightbox overlay - click anywhere to close -->
<div id="lightbox-overlay" onclick="closeLightbox()">
	<span id="lightbox-close" title="Close">&times;</span>
	<span id="lightbox-prev" onclick="prevImage(event)" title="Previous">&lsaquo;</span>
	<span id="lightbox-next" onclick="nextImage(event)" title="Next">&rsaquo;</span>
	<img id="lightbox-img" src="" alt="Screenshot">
</div>
<?php include("menu.php") ?>
<div class="mm-wrap">
	<div class="mm-head">
		<a class="mm-head-icon" href="<?php echo ($homePath); ?>"><img src="assets/icon.png" alt="webOS App Museum II"></a>
		<a class="mm-head-text" href="<?php echo ($homePath); ?>"><span class="mm-title">webOS App Museum II</span><span class="mm-sub">A historical archive of Palm / HP webOS apps</span></a>
	</div>

	<div class="mm-detail">
		<div class="mm-hero">
			<div class="mm-hero-icon">
				<img src="<?php echo htmlspecialchars($use_icon, ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($found_app["title"], ENT_QUOTES); ?> icon" onerror="this.src='assets/icon.png';">
			</div>
			<div class="mm-hero-info">
				<h1><?php echo htmlspecialchars($found_app["title"]); ?></h1>
				<div class="mm-byline">
					by <a href="<?php echo htmlspecialchars($author_url, ENT_QUOTES); ?>"><?php echo htmlspecialchars($found_app["author"]); ?></a>
					<?php if (!empty($app_detail["version"])) { echo " &middot; v" . htmlspecialchars($app_detail["version"]); } ?>
				</div>
				<?php
				$browserAsString = $_SERVER['HTTP_USER_AGENT'];
				if (strstr(strtolower($browserAsString), "webos") || strstr(strtolower($browserAsString), "hpwos")) {
					$plainURI = str_replace("https://", "http://", $plainURI);
				?>
					<a class="mm-dl" href="<?php echo $plainURI ?>">Preware Link</a>
					<a class="mm-dl-note" href="javascript:showHelp()">(?)</a>
				<?php
				} else {
				?>
					<span class="mm-dl" id="tdDownloadLink" title="Download Link Decoded by Javascript" data-encoded-uri="<?php echo $downloadURI ?>" data-app-id="<?php echo $found_app["id"] ?>"><i>Requires Javascript</i></span>
					<?php if (isset($altDownloadURI)) { ?>
						<div style="margin-top:10px;">
							<span class="mm-section-title" style="margin:0 8px 0 0;display:inline;">Alternate Version</span>
							<span class="mm-dl" id="tdAltDownloadLink" title="Download Link Decoded by Javascript" data-encoded-uri="<?php echo $altDownloadURI ?>" data-app-id="<?php echo $found_app["id"] ?>"><i>Requires Javascript</i></span>
						</div>
					<?php } ?>
				<?php } ?>
			</div>
		</div>

		<?php if (trim($app_detail["description"]) !== "") { ?>
			<div class="mm-section-title">Description</div>
			<div class="mm-desc"><?php echo $app_detail["description"]; ?></div>
		<?php } ?>

		<?php if (trim($app_detail["versionNote"]) !== "") { ?>
			<div class="mm-section-title">Version Note</div>
			<div class="mm-desc"><?php echo $app_detail["versionNote"]; ?></div>
		<?php } ?>

		<div class="mm-rows">
			<div class="mm-row"><span class="mm-label">Museum ID</span><span class="mm-value"><?php echo htmlspecialchars($found_app["id"]) ?></span></div>
			<div class="mm-row"><span class="mm-label">Application ID</span><span class="mm-value"><?php echo htmlspecialchars($app_detail["publicApplicationId"] ?? "") ?></span></div>
			<div class="mm-row"><span class="mm-label">Share Link</span><span class="mm-value"><a href="<?php echo htmlspecialchars($share_url, ENT_QUOTES) ?>"><?php echo htmlspecialchars($share_url) ?></a></span></div>
			<div class="mm-row"><span class="mm-label">Author</span><span class="mm-value"><a href="<?php echo htmlspecialchars($author_url, ENT_QUOTES) ?>"><?php echo htmlspecialchars($found_app["author"]) ?></a></span></div>
			<div class="mm-row"><span class="mm-label">Version</span><span class="mm-value"><?php echo htmlspecialchars($app_detail["version"] ?? "") ?></span></div>
			<div class="mm-row"><span class="mm-label">Home Page</span><span class="mm-value"><a href="<?php echo htmlspecialchars($app_detail["homeURL"] ?? "", ENT_QUOTES) ?>" target="_blank"><?php echo htmlspecialchars($app_detail["homeURL"] ?? "") ?></a></span></div>
			<div class="mm-row"><span class="mm-label">Support URL</span><span class="mm-value"><a href="<?php echo htmlspecialchars($app_detail["supportURL"] ?? "", ENT_QUOTES) ?>" target="_blank"><?php echo htmlspecialchars($app_detail["supportURL"] ?? "") ?></a></span></div>
			<div class="mm-row"><span class="mm-label">File Size</span><span class="mm-value"><?php echo round(($app_detail["appSize"] ?? 0)/1024,2) ?> KB</span></div>
			<div class="mm-row"><span class="mm-label">License</span><span class="mm-value"><?php echo htmlspecialchars($app_detail["licenseURL"] ?? "") ?></span></div>
			<div class="mm-row"><span class="mm-label">Copyright</span><span class="mm-value"><?php echo htmlspecialchars($app_detail["copyright"] ?? "") ?></span></div>
		</div>

		<div class="mm-section-title">Device Support</div>
		<ul class="mm-devices">
			<?php
			$device_labels = array(
				"Pre" => "Pre", "Pixi" => "Pixi", "Pre2" => "Pre2",
				"Veer" => "Veer", "Pre3" => "Pre3", "TouchPad" => "TouchPad", "LuneOS" => "LuneOS"
			);
			foreach ($device_labels as $key => $label) {
				$on = mm_dev_on($found_app, $key);
				$icon = $on ? "assets/true.png" : "assets/false.png";
				echo "<li class='" . ($on ? "mm-dev-yes" : "") . "'><img src='" . $icon . "' alt=''>" . htmlspecialchars($label) . "</li>";
			}
			?>
		</ul>

		<?php if (!empty($app_detail["images"])) { ?>
		<div class="mm-section-title">Screenshots</div>
		<div class="mm-shots">
		<?php
		$screenshot_urls = array();
		$screenshot_index = 0;
		foreach ($app_detail["images"] as $value) {
			if (strpos($value["screenshot"], "://") === false) {
				$use_screenshot = $img_path.strtolower($value["screenshot"]);
			} else {
				$use_screenshot = $value["screenshot"];
			}
			if (strpos($value["thumbnail"], "://") === false) {
				$use_thumb = $img_path.strtolower($value["thumbnail"]);
			} else {
				$use_thumb = $value["thumbnail"];
			}
			$screenshot_urls[] = $use_screenshot;
			echo("<a href='" . htmlspecialchars($use_screenshot, ENT_QUOTES) . "' target='_blank' onclick=\"return openLightbox('" . htmlspecialchars($use_screenshot, ENT_QUOTES) . "', " . $screenshot_index . ")\"><img src='" . htmlspecialchars($use_thumb, ENT_QUOTES) . "' alt='Screenshot'></a>");
			$screenshot_index++;
		}
		?>
		</div>
		<script>
		lightboxImages = <?php echo json_encode($screenshot_urls); ?>;
		</script>
		<?php } ?>

		<?php
		// Get and display related apps
		$appRepo = new AppRepository();
		$relatedApps = $appRepo->getRelatedApps($found_id, 6);
		if (!empty($relatedApps)):
		?>
		<div class="mm-section-title">Related Apps</div>
		<div class="mm-related">
		<?php
		foreach ($relatedApps as $related) {
			if (strpos($related["appIcon"], "://") === false) {
				$related_icon = $img_path.strtolower($related["appIcon"]);
			} else {
				$related_icon = $related["appIcon"];
			}
			$related_url = "showMuseumDetails.php?" . $_SERVER["QUERY_STRING"] . "&app=" . $related["id"];
			echo "<a href='" . htmlspecialchars($related_url) . "'>";
			echo "<img src='" . htmlspecialchars($related_icon) . "' onerror=\"this.src='assets/icon.png';\"><br>";
			echo "<small>" . htmlspecialchars($related["title"]) . "</small>";
			echo "</a>";
		}
		?>
		</div>
		<?php endif; ?>

		<?php include 'footer.php'; ?>
	</div>
</div>
</body>
</html>
