<?php
/**
 * Export App Data as JSON
 *
 * Exports apps in the original JSON format used by the legacy files.
 * Can export by status or all apps.
 */
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/AppRepository.php';

$db = Database::getInstance()->getConnection();
$appRepo = new AppRepository();

$message = '';
$error = '';

// Status options matching original files
$exportOptions = [
    'active' => 'Active Apps (archivedAppData.json format)',
    'newer' => 'Newer Apps (newerAppData.json format)',
    'missing' => 'Missing Apps (missingAppData.json format)',
    'archived' => 'Archived Only (masterAppData.json exclusives)',
    'all' => 'All Apps (complete catalog)'
];

// Handle export request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export'])) {
    $status = $_POST['status'] ?? 'active';
    $download = isset($_POST['download']);

    try {
        // Build query based on status
        if ($status === 'all') {
            $statuses = ['active', 'newer', 'missing', 'archived'];
        } else {
            $statuses = [$status];
        }

        $placeholders = str_repeat('?,', count($statuses) - 1) . '?';

        $stmt = $db->prepare("
            SELECT
                a.id,
                a.title,
                a.author,
                a.summary,
                a.app_icon,
                a.app_icon_big,
                c.name as category,
                a.vendor_id,
                a.pixi,
                a.pre,
                a.pre2,
                a.pre3,
                a.veer,
                a.touchpad,
                a.touchpad_exclusive,
                a.luneos,
                a.adult
            FROM apps a
            LEFT JOIN categories c ON a.category_id = c.id
            WHERE a.status IN ($placeholders)
            ORDER BY a.title
        ");
        $stmt->execute($statuses);
        $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format to match original JSON structure
        $output = [];
        foreach ($apps as $app) {
            $output[] = [
                'id' => (int)$app['id'],
                'title' => $app['title'],
                'author' => $app['author'],
                'summary' => $app['summary'],
                'appIcon' => $app['app_icon'],
                'appIconBig' => $app['app_icon_big'],
                'category' => $app['category'],
                'vendorId' => $app['vendor_id'],
                'Pixi' => (bool)$app['pixi'],
                'Pre' => (bool)$app['pre'],
                'Pre2' => (bool)$app['pre2'],
                'Pre3' => (bool)$app['pre3'],
                'Veer' => (bool)$app['veer'],
                'TouchPad' => (bool)$app['touchpad'],
                'touchpad_exclusive' => (bool)$app['touchpad_exclusive'],
                'LuneOS' => (bool)$app['luneos'],
                'Adult' => (bool)$app['adult']
            ];
        }

        $json = json_encode($output, JSON_UNESCAPED_UNICODE);
        $count = count($output);

        if ($download) {
            // Send as file download
            $filename = $status . 'AppData.json';
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($json));
            echo $json;
            exit;
        } else {
            $message = "Export ready: $count apps. Click 'Download' to save the file.";
        }

    } catch (Exception $e) {
        $error = "Error exporting: " . $e->getMessage();
    }
}

// Get counts for display
$counts = [];
$stmt = $db->query("SELECT status, COUNT(*) as count FROM apps GROUP BY status");
while ($row = $stmt->fetch()) {
    $counts[$row['status']] = (int)$row['count'];
}
$counts['all'] = array_sum($counts);

include 'includes/header.php';
?>

<div class="page-header">
    <h1>Export App Data (JSON)</h1>
    <a href="index.php" class="btn">Back to Dashboard</a>
</div>

<?php if ($message): ?>
<div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Export Options</h2>
    </div>
    <div class="card-body">
        <p>Export app data in the original JSON format for backup or migration purposes.</p>

        <form method="post">
            <div class="form-group">
                <label>Select Export Type</label>
                <select name="status" class="form-control" style="max-width:400px;">
                    <?php foreach ($exportOptions as $value => $label): ?>
                    <option value="<?php echo $value; ?>">
                        <?php echo $label; ?> (<?php echo number_format($counts[$value] ?? 0); ?> apps)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-actions" style="margin-top:20px;">
                <button type="submit" name="export" value="1" class="btn">Preview Count</button>
                <button type="submit" name="export" value="1" name="download" class="btn btn-primary"
                        onclick="document.querySelector('input[name=download]').value='1'">Download JSON</button>
                <input type="hidden" name="download" value="">
            </div>
        </form>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <div class="card-header">
        <h2>Export Format Reference</h2>
    </div>
    <div class="card-body">
        <table class="data-table">
            <tr><th>Original File</th><th>Status</th><th>Description</th></tr>
            <tr><td>archivedAppData.json</td><td>active</td><td>Main catalog - apps with available IPKs</td></tr>
            <tr><td>newerAppData.json</td><td>newer</td><td>Post-freeze submissions</td></tr>
            <tr><td>missingAppData.json</td><td>missing</td><td>Apps needing IPK recovery</td></tr>
            <tr><td>masterAppData.json</td><td>archived</td><td>Historical reference only</td></tr>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
