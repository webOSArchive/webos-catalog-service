<?php include('tldchange-notice.php'); ?>
<html>
<head>
<link rel="shortcut icon" href="favicon.ico">
<meta name="viewport" content="width=device-width, initial-scale=1">

<?php
$config = include('WebService/config.php');
include('common.php');

function repositionArrayElement(array &$array, $value, int $order): void
{
	$array_count = 0;
	$a = 0;
	foreach ($array as $array_value) {
		if ($array_value == $value)
		{
			$a = $array_count;
		}
		$array_count++;
	}
	$p1 = array_splice($array, $a, 1);
	$p2 = array_splice($array, 0, $order);
	$array = array_merge($p2, $p1, $array);
}

//Figure out what protocol the client wanted
if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
    $PROTOCOL = "https://";
else
    $PROTOCOL = "http://";

//Figure out where images are
$img_path = $PROTOCOL . $config["image_host"] . "/";

//Support for safe search
$_safe = "on";
if (isset($_COOKIE["safesearch"]))
	$_safe = $_COOKIE["safesearch"];
if (isset($_GET['safe'])) {
	$_safe = $_GET['safe'];
	$_safe = preg_replace("/[^a-zA-Z0-9]+/", "", $_safe);
	setcookie("safesearch", $_GET['safe'], time() + 86400, "/");
}
$adult = "";
if ($_safe != "on")
	$adult = "&adult=true";

//Support for sort order
$_sort = "recent";
if (isset($_COOKIE["sortorder"]))
	$_sort = $_COOKIE["sortorder"];
if (isset($_GET['sort'])) {
	$_sort = $_GET['sort'];
	$_sort = preg_replace("/[^a-zA-Z]+/", "", $_sort);
	if (!in_array($_sort, ['alpha', 'recommended', 'recent'])) $_sort = 'recent';
	setcookie("sortorder", $_sort, time() + 86400 * 30, "/");
}

//Get the category list - load directly from database to avoid HTTP request issues
require_once __DIR__ . '/includes/AppRepository.php';
$appRepo = new AppRepository();
$_adult = strpos($adult, 'true') !== false;
$category_counts = $appRepo->getCategoryCounts($_adult, ['active']);
$category_master = array("appCount" => $category_counts);
$category_list = array_keys($category_counts);
sort($category_list);

//Get the app list if there is a category query - using direct catalog loading to avoid rate limiting
if (isset($_GET['category']) && isset($_GET['count']))
{
	$category = $_GET['category'];
	$category = preg_replace("/[^a-zA-Z0-9&' ]+/", "", $_GET['category']);
	$count = preg_replace("/[^0-9]+/", "", $_GET['count']);

	// Load catalog directly instead of HTTP request
	$fullcatalog = load_catalogs();
	$_adult = strpos($adult, 'true') !== false;

	$results = filter_apps_by_category($fullcatalog, $category, $_adult, $count, $_sort);
	$app_response = array('data' => $results);
}
elseif (isset($_GET['search']))
{
	$search = preg_replace("/[^a-zA-Z0-9 ]+/", "", $_GET['search']);

	// Load catalog directly instead of HTTP request
	$fullcatalog = load_catalogs();
	$search_str = strtolower($search);
	$_adult = strpos($adult, 'true') !== false;

	$results = search_apps($fullcatalog, $search_str, $_adult);
	$app_response = array('data' => $results);
}

//Figure out where to go back to
$homePath = "/";
if (isset($app_response))
	$homePath = "showMuseum.php";

//Add social media meta tags
include('meta-social-common.php');
?>
<title>webOS App Museum - Web Catalog</title>
<link rel="stylesheet" href="museum-modern.css">
<link href="<?php echo $PROTOCOL . "://www.webosarchive.org/app-template/"?>web.css" rel="stylesheet" type="text/css" >
<script>
	function changeSearchFilter() {
		if (document.getElementById("txtSearch") && document.getElementById("txtSearch").value == "") {
			document.frmSearch.submit();
		}
	}
</script>
</head>
<body onload="if (document.getElementById('txtSearch')) { document.getElementById('txtSearch').focus(); }">
<?php include("menu.php") ?>

<div class="mm-wrap">
	<div class="mm-head">
		<a class="mm-head-icon" href="<?php echo ($homePath); ?>"><img src="assets/icon.png" alt="webOS App Museum"></a>
		<a class="mm-head-text" href="<?php echo ($homePath); ?>"><span class="mm-title">webOS App Museum</span><span class="mm-sub">A historical archive of Palm / HP webOS apps</span></a>
	</div>

	<div class="mm-layout">
		<div class="mm-cats">
			<h4>Categories</h4>
			<?php
				repositionArrayElement($category_list, "Revisionist History", 1);
				repositionArrayElement($category_list, "Curator's Choice", 1);
				foreach ($category_list as $array_key) {
					$catname = $array_key;
					$catcount = $category_master["appCount"][$array_key];
					if ($catname != "All" && $catname != "Missing Apps" && $catcount > 0)
					{
						$catencode = (urlencode($array_key));
						$isSel = (isset($_GET['category']) && strtolower($catname) == strtolower($_GET['category']));
						echo "<a class='mm-cat" . ($isSel ? " mm-sel" : "") . "' href='showMuseum.php?category={$catencode}&count={$catcount}'>";
						echo "<span class='mm-count'>{$catcount}</span>" . htmlspecialchars($catname);
						echo "</a>";
					}
				}
			?>
		</div>

		<div class="mm-main">
			<div class="mm-main-card">
			<?php
			if (isset($app_response) && count($app_response["data"]) > 0)
			{
				if (isset($_GET['category'])) {
					$category = $_GET['category'];
					$category = preg_replace("/[^a-zA-Z0-9 ]+/", "", $category);
					echo ("<h3>" . htmlspecialchars($category) . "</h3>");
					// Sort toggle
					$sortRecentUrl = "showMuseum.php?category=" . urlencode($_GET['category']) . "&count=" . $_GET['count'] . "&sort=recent";
					$sortAlphaUrl = "showMuseum.php?category=" . urlencode($_GET['category']) . "&count=" . $_GET['count'] . "&sort=alpha";
					$sortRecUrl = "showMuseum.php?category=" . urlencode($_GET['category']) . "&count=" . $_GET['count'] . "&sort=recommended";
					echo "<div class='mm-sort'>";
					echo "Sort: ";
					echo ($_sort == 'recent') ? "<b>Recently Updated</b>" : "<a href='{$sortRecentUrl}'>Recently Updated</a>";
					echo "<span class='mm-sep'>&middot;</span>";
					echo ($_sort == 'alpha') ? "<b>Alphabetical</b>" : "<a href='{$sortAlphaUrl}'>Alphabetical</a>";
					echo "<span class='mm-sep'>&middot;</span>";
					echo ($_sort == 'recommended') ? "<b>Recommended</b>" : "<a href='{$sortRecUrl}'>Recommended</a>";
					echo "</div>";
				}
				if (isset($_GET['search'])) {
					$searchTerm = $_GET['search'];
					$searchTerm = preg_replace("/[^a-zA-Z0-9 ]+/", "", $searchTerm);
					echo ("<h3 style='margin-bottom:14px;'>Search Results: &lsquo;" . htmlspecialchars($searchTerm) . "&rsquo;</h3>");
				}
				// Escape the raw query string for safe use inside HTML href attributes (prevents reflected XSS)
				$qs = htmlspecialchars($_SERVER["QUERY_STRING"], ENT_QUOTES);
				foreach($app_response["data"] as $app) {
					if (strpos($app["appIcon"], "://") === false) {
						$use_img = $img_path.strtolower($app["appIcon"]);
					} else {
						$use_img = $app["appIcon"];
					}
					$detailUrl = "showMuseumDetails.php?{$qs}&app={$app["id"]}";
					echo "<a class='mm-app' href='" . htmlspecialchars($detailUrl, ENT_QUOTES) . "'>";
					echo   "<span class='mm-app-icon'><img src='" . htmlspecialchars($use_img, ENT_QUOTES) . "' alt='' onerror=\"this.src='assets/icon.png';\"></span>";
					echo   "<span class='mm-app-body'>";
					echo     "<span class='mm-app-title'>" . htmlspecialchars($app["title"]) . "</span>";
					echo     "<span class='mm-app-summary'>" . htmlspecialchars(substr($app["summary"], 0, 180)) . "&hellip;</span>";
					echo   "</span>";
					echo "</a>";
				}
				include 'footer.php';
			}
			else
			{
				?>
				<div class="mm-landing">
					<img class="mm-hero" src="assets/webos-apps.png" alt="webOS Apps">
					<p class="mm-lead">Choose a category to view apps, or&hellip;</p>
					<form action="" id="frmSearch" name="frmSearch" method="get">
						<?php
						if (isset($_GET['search'])) {
							$search = preg_replace("/[^a-zA-Z0-9 ]+/", "", $_GET['search']);
						}
						?>
						<div class="mm-searchbar">
							<input type="text" id="txtSearch" name="search" class="mm-search-input" placeholder="Just type&hellip;" value="<?php if (isset($search)) { echo htmlspecialchars($search); } ?>">
						</div>
						<input type="submit" class="mm-search-btn" value="Search">
						<?php
						if (isset($search)) {
							echo "<p class='mm-noresult'>No results</p>";
						}
						?>
						<div class="mm-safe">
							Safe Search:
							<select id="chkSafe" name="safe" onchange="changeSearchFilter()">
								<option value="on" <?php if ($_safe == "on") { echo "selected"; }?>>Enabled</option>
								<option value="off" <?php if ($_safe == "off") { echo "selected"; }?>>Disabled</option>
							</select>
						</div>
					</form>
				</div>
				<?php
				include 'footer.php';
			}
			?>
			</div>
		</div>
	</div>
</div>
</body>
</html>
