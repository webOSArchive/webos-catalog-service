<?php
/**
 * App Reviews API Endpoint
 *
 * Returns archived HP App Catalog reviews for a single app.
 *
 * Query parameters:
 *   id     - App ID (required)
 *   sign   - "positive" (score >= 3) or "negative" (score 1-2), default "positive"
 *   offset - Pagination offset, default 0
 *   count  - Page size, default 10, max 50
 *
 * Response (positive sign):
 *   { totalCount, reviews: [...], ratingsBreakdown: { totalCount, averageRating,
 *     percentPositive, percentNegative, stars: {1,2,3,4,5} } }
 *
 * Response (negative sign):
 *   { totalCount, reviews: [...] }
 */

include('ratelimit.php');
checkRateLimit(600, 3600);

header('Content-Type: application/json');

$id     = @$_GET['id'];
$sign   = @$_GET['sign'] === 'negative' ? 'negative' : 'positive';
$offset = max(0, (int)@$_GET['offset']);
$count  = min(50, max(1, (int)(@$_GET['count'] ?: 10)));

if (!isset($id) || !is_numeric($id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing app ID']);
    exit;
}
$appId = (int)$id;

require_once __DIR__ . '/../includes/Database.php';
$db = Database::getInstance()->getConnection();

// Score ranges: positive = 3-5, negative = 1-2 (score=0 is HP spam/inappropriate marker)
$minScore = ($sign === 'positive') ? 3 : 1;
$maxScore = ($sign === 'positive') ? 5 : 2;

// Total count for this sign
$cntStmt = $db->prepare("
    SELECT COUNT(*) FROM app_reviews
    WHERE app_id = ?
      AND is_inappropriate = 0
      AND score BETWEEN ? AND ?
      AND comments IS NOT NULL AND comments != ''
");
$cntStmt->execute([$appId, $minScore, $maxScore]);
$totalCount = (int)$cntStmt->fetchColumn();

// Reviews page
$revStmt = $db->prepare("
    SELECT id, account_id, creator, comments, score, locale, is_anonymous, created
    FROM app_reviews
    WHERE app_id = ?
      AND is_inappropriate = 0
      AND score BETWEEN ? AND ?
      AND comments IS NOT NULL AND comments != ''
    ORDER BY created DESC
    LIMIT ? OFFSET ?
");
$revStmt->execute([$appId, $minScore, $maxScore, $count, $offset]);

$reviews = [];
while ($row = $revStmt->fetch()) {
    $created = null;
    if ($row['created']) {
        // Stored as UTC datetime; emit as ISO 8601 with Z so JS new Date() parses correctly
        $created = str_replace(' ', 'T', $row['created']) . 'Z';
    }
    $reviews[] = [
        'id'           => (int)$row['id'],
        'accountId'    => $row['account_id'] ? (int)$row['account_id'] : null,
        'creator'      => $row['creator'] ?: '',
        'comments'     => $row['comments'],
        'score'        => (int)$row['score'],
        'locale'       => $row['locale'] ?: 'en_US',
        'isAnonymous'  => (bool)$row['is_anonymous'],
        'created'      => $created,
    ];
}

$result = [
    'totalCount' => $totalCount,
    'reviews'    => $reviews,
];

// ratingsBreakdown is only needed by the positive call (drives the star widget)
if ($sign === 'positive') {
    $aggStmt = $db->prepare("
        SELECT
            COUNT(*)                                          AS total,
            ROUND(AVG(score), 2)                             AS avg_score,
            SUM(CASE WHEN score >= 3 THEN 1 ELSE 0 END)     AS pos_count,
            SUM(CASE WHEN score BETWEEN 1 AND 2 THEN 1 ELSE 0 END) AS neg_count,
            SUM(CASE WHEN score = 5 THEN 1 ELSE 0 END)      AS s5,
            SUM(CASE WHEN score = 4 THEN 1 ELSE 0 END)      AS s4,
            SUM(CASE WHEN score = 3 THEN 1 ELSE 0 END)      AS s3,
            SUM(CASE WHEN score = 2 THEN 1 ELSE 0 END)      AS s2,
            SUM(CASE WHEN score = 1 THEN 1 ELSE 0 END)      AS s1
        FROM app_reviews
        WHERE app_id = ?
          AND is_inappropriate = 0
          AND score BETWEEN 1 AND 5
    ");
    $aggStmt->execute([$appId]);
    $agg = $aggStmt->fetch();

    if ($agg && $agg['total'] > 0) {
        $total    = (int)$agg['total'];
        $posCount = (int)$agg['pos_count'];
        $negCount = (int)$agg['neg_count'];

        $result['ratingsBreakdown'] = [
            'totalCount'      => $total,
            'averageRating'   => (float)$agg['avg_score'],
            'percentPositive' => round($posCount / $total * 100, 1),
            'percentNegative' => round($negCount / $total * 100, 1),
            'stars'           => [
                '5' => (int)$agg['s5'],
                '4' => (int)$agg['s4'],
                '3' => (int)$agg['s3'],
                '2' => (int)$agg['s2'],
                '1' => (int)$agg['s1'],
            ],
        ];
    }
}

ob_start();
ob_start('ob_gzhandler');
echo json_encode($result);
ob_end_flush();
$size = ob_get_length();
header('Content-Encoding: gzip');
header("Content-Length: $size");
header('Connection: close');
ob_end_flush();
ob_flush();
flush();
?>
