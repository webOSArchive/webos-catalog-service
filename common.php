<?php
/**
 * Common functions for webOS App Catalog
 *
 * These functions provide backward-compatible wrappers around the new
 * database repositories. Existing code can continue to call these functions
 * without modification.
 */

require_once __DIR__ . '/includes/AppRepository.php';

/**
 * Render social media icon for a link
 */
function render_social($link, $basePath) {
	$imgsrc = $basePath. "/social/web.png";
	if (strpos($link, "discord.com") !== false || strpos($link, "webosarchive.org/discord") !== false)
		$imgsrc = $basePath. "/social/discord.png";
	if (strpos($link, "facebook.com") !== false)
		$imgsrc = $basePath. "/social/facebook.png";
	if (strpos($link, "github.com") !== false)
		$imgsrc = $basePath. "/social/github.png";
	if (strpos($link, "instagram.com") !== false)
		$imgsrc = $basePath. "/social/instagram.png";
	if (strpos($link, "linkedin.com") !== false)
		$imgsrc = $basePath. "/social/linkedin.png";
	if (strpos($link, "reddit.com") !== false)
		$imgsrc = $basePath. "/social/reddit.png";
	if (strpos($link, "snapchat.com") !== false)
		$imgsrc = $basePath. "/social/snapchat.png";
	if (strpos($link, "twitter.com") !== false)
		$imgsrc = $basePath. "/social/twitter.png";
	if (strpos($link, "youtube.com") !== false)
		$imgsrc = $basePath. "/social/youtube.png";
	return "<img src='" . $imgsrc . "' class='authorSocial'>";
}

/**
 * Load app catalog from database
 *
 * @param array $catalogs - Array of catalog file names (used to determine statuses)
 * @return array - Array of apps
 */
function load_catalogs($catalogs = []) {
	// Map old file names to database statuses (for backward compatibility)
	$statuses = [];
	foreach ($catalogs as $catalog) {
		$catalog = strtolower(basename($catalog));
		if (strpos($catalog, 'archived') !== false) $statuses[] = 'active';
		if (strpos($catalog, 'master') !== false) $statuses[] = 'archived';
		if (strpos($catalog, 'missing') !== false) $statuses[] = 'missing';
	}

	// Default to active if no specific statuses determined
	if (empty($statuses)) {
		$statuses = ['active'];
	}

	$repo = new AppRepository();
	return $repo->loadCatalog(array_unique($statuses));
}

/**
 * Search apps by title/id with fuzzy matching
 *
 * @param array $catalog - Ignored (kept for backward compatibility)
 * @param string $search_str - Search term
 * @param bool $adult - Whether to include adult content
 * @return array - Matching apps
 */
function search_apps($catalog, $search_str, $adult = false) {
	$repo = new AppRepository();
	return $repo->searchApps($search_str, $adult);
}

/**
 * Search apps by author with fuzzy matching
 *
 * @param array $catalog - Ignored (kept for backward compatibility)
 * @param string $search_str - Author search term
 * @param bool $adult - Whether to include adult content
 * @return array - Matching apps
 */
function search_apps_by_author($catalog, $search_str, $adult = false) {
	$repo = new AppRepository();
	return $repo->searchByAuthor($search_str, $adult);
}

/**
 * Filter apps by category with adult content filtering
 *
 * @param array $catalog - Ignored (kept for backward compatibility)
 * @param string $category - Category to filter by ('All' for no filter)
 * @param bool $adult - Whether to include adult content
 * @param int $limit - Maximum number of results (0 for no limit)
 * @param string $sort - Sort order: 'alpha' (default) or 'recommended'
 * @return array - Filtered apps
 */
function filter_apps_by_category($catalog, $category, $adult = false, $limit = 0, $sort = 'alpha') {
	$repo = new AppRepository();
	return $repo->filterByCategory($category, $adult, $limit, ['active'], $sort);
}

/**
 * Create a standard response object for app search results
 *
 * @param array $results - Array of app results
 * @return array - Standardized response format
 */
function create_app_response($results) {
	$responseObj = new stdClass();
	$responseObj->data = $results;
	return json_decode(json_encode($responseObj), true);
}
?>