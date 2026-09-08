<?php
/**
 * What's New RSS feed
 *
 * RSS 2.0 feed of recent catalog activity, newest first: the same "recently
 * updated" ordering the web catalog uses (app_metadata.last_modified_time),
 * so newly added apps and updated apps both appear as their date is set.
 * Backed by AppRepository::getRecentChanges().
 *
 * Query parameters (all optional):
 *   count  - Items to return, 1-100 (default 50)
 *   adult  - '1' to include adult-flagged apps (excluded by default; feed
 *            readers don't carry the site's safe-search cookie)
 *
 * Mirrors the web UI's visibility rules: active apps only, no future-dated
 * (scheduled) apps, no web_suppressed apps.
 */

require_once __DIR__ . '/includes/AppRepository.php';
$config = include(__DIR__ . '/WebService/config.php');

// Figure out what protocol the client wanted (same rule as showMuseum.php)
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
	$PROTOCOL = "https://";
else
	$PROTOCOL = "http://";

$host = $config['service_host'] ?? ($_SERVER['HTTP_HOST'] ?? 'appcatalog.webosarchive.org');
$baseUrl = $PROTOCOL . $host;
$imgPath = $PROTOCOL . $config["image_host"] . "/";

// Parameters
$count = isset($_GET['count']) ? (int)$_GET['count'] : 50;
$count = max(1, min(100, $count));
$adult = isset($_GET['adult']) && $_GET['adult'] === '1';

$repo = new AppRepository();
$items = $repo->getRecentChanges($count, $adult, true);

// Self URL (for atom:link) reflecting the effective parameters
$selfQuery = [];
if ($count !== 50) $selfQuery['count'] = $count;
if ($adult) $selfQuery['adult'] = '1';
$selfUrl = $baseUrl . '/feed.php' . ($selfQuery ? '?' . http_build_query($selfQuery) : '');

$channelTitle = "webOS App Museum - What's New";

$lastBuild = time();
if (!empty($items) && !empty($items[0]['lastModifiedTime'])) {
	$lastBuild = strtotime($items[0]['lastModifiedTime']) ?: $lastBuild;
}

header('Content-Type: application/rss+xml; charset=utf-8');
// Readers poll often; let Cloudflare/browsers reuse a copy for a while
header('Cache-Control: public, max-age=1800');

echo feed_render_rss($items, [
	'title' => $channelTitle,
	'link' => $baseUrl . '/showMuseum.php',
	'self' => $selfUrl,
	'description' => 'New apps and updates in the webOS App Museum, the historical archive of Palm/HP webOS mobile apps and games.',
	'image' => $baseUrl . '/assets/webos-apps.png',
	'lastBuild' => $lastBuild,
	'baseUrl' => $baseUrl,
	'imgPath' => $imgPath,
]);

/**
 * XML-escape text for element content/attributes, dropping characters that
 * are illegal in XML 1.0 (stray control bytes from old catalog imports).
 */
function feed_xml($s) {
	$s = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string)$s);
	if ($s === null) $s = ''; // preg_replace fails on invalid UTF-8
	return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Wrap HTML in a CDATA section safely (a literal "]]>" would end it early). */
function feed_cdata($html) {
	$html = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string)$html);
	if ($html === null) $html = '';
	return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $html) . ']]>';
}

/** HTML-escape for the CDATA description body. */
function feed_h($s) {
	return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Absolute icon URL, mirroring showMuseum.php's handling of appIcon. */
function feed_icon_url($appIcon, $imgPath) {
	if (empty($appIcon)) return null;
	if (strpos($appIcon, "://") === false) {
		return $imgPath . strtolower($appIcon);
	}
	return $appIcon;
}

/**
 * Build the RSS document.
 *
 * @param array $items Rows from AppRepository::getRecentChanges()
 * @param array $ch Channel settings: title, link, self, description, image, lastBuild, baseUrl, imgPath
 * @return string XML
 */
function feed_render_rss($items, $ch) {
	$out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$out .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
	$out .= "<channel>\n";
	$out .= "\t<title>" . feed_xml($ch['title']) . "</title>\n";
	$out .= "\t<link>" . feed_xml($ch['link']) . "</link>\n";
	$out .= "\t<atom:link href=\"" . feed_xml($ch['self']) . "\" rel=\"self\" type=\"application/rss+xml\" />\n";
	$out .= "\t<description>" . feed_xml($ch['description']) . "</description>\n";
	$out .= "\t<language>en-us</language>\n";
	$out .= "\t<lastBuildDate>" . date(DATE_RSS, $ch['lastBuild']) . "</lastBuildDate>\n";
	$out .= "\t<ttl>60</ttl>\n";
	$out .= "\t<image>\n";
	$out .= "\t\t<url>" . feed_xml($ch['image']) . "</url>\n";
	$out .= "\t\t<title>" . feed_xml($ch['title']) . "</title>\n";
	$out .= "\t\t<link>" . feed_xml($ch['link']) . "</link>\n";
	$out .= "\t</image>\n";

	foreach ($items as $app) {
		$when = strtotime($app['lastModifiedTime']) ?: time();
		$link = $ch['baseUrl'] . '/showMuseumDetails.php?app=' . (int)$app['id'];
		$version = trim((string)($app['version'] ?? ''));

		$title = $app['title'] . ($version !== '' ? ' v' . $version : '');

		// A stable id per event: a later update to an app yields a fresh item
		// in readers, but re-fetching the same feed doesn't duplicate anything.
		$guid = 'webos-app-museum:app:' . (int)$app['id'] . ':' . date('YmdHis', $when);

		// Description body (HTML)
		$html = '';
		$icon = feed_icon_url($app['appIcon'], $ch['imgPath']);
		if ($icon) {
			$html .= '<p><img src="' . feed_h($icon) . '" alt="" width="64" height="64" /></p>';
		}
		$html .= '<p><strong>' . feed_h($app['title']) . '</strong>' . ($version !== '' ? ' v' . feed_h($version) : '') . '</p>';
		$meta = [];
		if (!empty($app['author'])) $meta[] = 'By ' . feed_h($app['author']);
		if (!empty($app['category'])) $meta[] = 'Category: ' . feed_h($app['category']);
		if (!empty($app['postShutdown'])) $meta[] = 'Community release';
		if ($meta) {
			$html .= '<p>' . implode(' &middot; ', $meta) . '</p>';
		}
		if (!empty($app['summary'])) {
			$html .= '<p>' . feed_h($app['summary']) . '</p>';
		}
		$note = trim((string)($app['versionNote'] ?? ''));
		if ($note !== '') {
			$html .= '<p><em>What\'s new:</em><br />' . nl2br(feed_h($note)) . '</p>';
		}
		$html .= '<p><a href="' . feed_h($link) . '">View in the App Museum</a></p>';

		$out .= "\t<item>\n";
		$out .= "\t\t<title>" . feed_xml($title) . "</title>\n";
		$out .= "\t\t<link>" . feed_xml($link) . "</link>\n";
		$out .= "\t\t<guid isPermaLink=\"false\">" . feed_xml($guid) . "</guid>\n";
		$out .= "\t\t<pubDate>" . date(DATE_RSS, $when) . "</pubDate>\n";
		if (!empty($app['category'])) {
			$out .= "\t\t<category>" . feed_xml($app['category']) . "</category>\n";
		}
		$out .= "\t\t<description>" . feed_cdata($html) . "</description>\n";
		$out .= "\t</item>\n";
	}

	$out .= "</channel>\n</rss>\n";
	return $out;
}
