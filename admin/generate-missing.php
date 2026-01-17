<?php
/**
 * Generate Missing Apps Files
 *
 * Creates missing.txt and missing.csv from database for community recovery efforts
 */
require_once __DIR__ . '/../includes/Database.php';

$db = Database::getInstance()->getConnection();
$message = '';
$error = '';

// Handle generation request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    try {
        // Fetch all missing apps with their metadata (for filename)
        $stmt = $db->query("
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
                a.adult,
                m.filename
            FROM apps a
            LEFT JOIN categories c ON a.category_id = c.id
            LEFT JOIN app_metadata m ON a.id = m.app_id
            WHERE a.status = 'missing'
            ORDER BY a.title
        ");
        $missingApps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $count = count($missingApps);
        $basePath = __DIR__ . '/..';

        // Check write permissions for the specific files
        $txtPath = $basePath . '/wanted.txt';
        $csvPath = $basePath . '/wanted.csv';

        // If files exist, check if they're writable; if not, check if directory allows creation
        if (file_exists($txtPath) && !is_writable($txtPath)) {
            throw new Exception("wanted.txt is not writable.\nRun: sudo chown www-data:www-data " . realpath($txtPath));
        }
        if (file_exists($csvPath) && !is_writable($csvPath)) {
            throw new Exception("wanted.csv is not writable.\nRun: sudo chown www-data:www-data " . realpath($csvPath));
        }
        if (!file_exists($txtPath) && !is_writable($basePath)) {
            throw new Exception("Cannot create wanted.txt - directory not writable.\nRun: sudo touch $txtPath && sudo chown www-data:www-data $txtPath");
        }
        if (!file_exists($csvPath) && !is_writable($basePath)) {
            throw new Exception("Cannot create wanted.csv - directory not writable.\nRun: sudo touch $csvPath && sudo chown www-data:www-data $csvPath");
        }

        // Generate wanted.txt - IPK filenames only (one per line, for Scanner app compatibility)
        $txtContent = "";
        foreach ($missingApps as $app) {
            if (!empty($app['filename'])) {
                $txtContent .= $app['filename'] . "\n";
            }
        }

        if (file_put_contents($txtPath, $txtContent) === false) {
            throw new Exception("Failed to write wanted.txt. Check file permissions.");
        }

        // Generate wanted.csv - Full data for analysis
        $csvFile = fopen($csvPath, 'w');
        if ($csvFile === false) {
            throw new Exception("Failed to open wanted.csv for writing. Check file permissions.");
        }

        // Header row
        fputcsv($csvFile, [
            'id', 'title', 'author', 'category', 'vendor_id', 'filename', 'summary',
            'pixi', 'pre', 'pre2', 'pre3', 'veer', 'touchpad', 'touchpad_exclusive', 'luneos', 'adult',
            'app_icon', 'app_icon_big'
        ]);

        // Data rows
        foreach ($missingApps as $app) {
            fputcsv($csvFile, [
                $app['id'],
                $app['title'],
                $app['author'],
                $app['category'],
                $app['vendor_id'],
                $app['filename'],
                $app['summary'],
                $app['pixi'] ? 'Yes' : 'No',
                $app['pre'] ? 'Yes' : 'No',
                $app['pre2'] ? 'Yes' : 'No',
                $app['pre3'] ? 'Yes' : 'No',
                $app['veer'] ? 'Yes' : 'No',
                $app['touchpad'] ? 'Yes' : 'No',
                $app['touchpad_exclusive'] ? 'Yes' : 'No',
                $app['luneos'] ? 'Yes' : 'No',
                $app['adult'] ? 'Yes' : 'No',
                $app['app_icon'],
                $app['app_icon_big']
            ]);
        }

        fclose($csvFile);

        // Count how many had filenames
        $withFilenames = count(array_filter($missingApps, fn($a) => !empty($a['filename'])));
        $message = "Successfully generated wanted.txt ($withFilenames IPK filenames) and wanted.csv ($count apps).";

    } catch (Exception $e) {
        $error = "Error generating files: " . $e->getMessage();
    }
}

// Get current stats
$stats = $db->query("SELECT COUNT(*) as count FROM apps WHERE status = 'missing'")->fetch();
$missingCount = $stats['count'];

// Check if files exist
$txtExists = file_exists(__DIR__ . '/../wanted.txt');
$csvExists = file_exists(__DIR__ . '/../wanted.csv');
$txtModified = $txtExists ? date('Y-m-d H:i:s', filemtime(__DIR__ . '/../wanted.txt')) : 'N/A';
$csvModified = $csvExists ? date('Y-m-d H:i:s', filemtime(__DIR__ . '/../wanted.csv')) : 'N/A';

include 'includes/header.php';
?>

<div class="page-header">
    <h1>Generate Missing Apps Files</h1>
    <a href="index.php" class="btn">Back to Dashboard</a>
</div>

<?php if ($message): ?>
<div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-error"><pre style="margin:0;white-space:pre-wrap;"><?php echo htmlspecialchars($error); ?></pre></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Missing Apps Status</h2>
    </div>
    <div class="card-body">
        <table class="data-table" style="max-width: 600px;">
            <tr>
                <th>Missing Apps in Database</th>
                <td><strong><?php echo number_format($missingCount); ?></strong> apps</td>
            </tr>
            <tr>
                <th>wanted.txt</th>
                <td>
                    <?php if ($txtExists): ?>
                        <span style="color: green;">Exists</span> - Last modified: <?php echo $txtModified; ?>
                        <br><a href="../wanted.txt" target="_blank">View file</a>
                    <?php else: ?>
                        <span style="color: red;">Not generated</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>wanted.csv</th>
                <td>
                    <?php if ($csvExists): ?>
                        <span style="color: green;">Exists</span> - Last modified: <?php echo $csvModified; ?>
                        <br><a href="../wanted.csv" target="_blank">Download CSV</a>
                    <?php else: ?>
                        <span style="color: red;">Not generated</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <form method="post" style="margin-top: 20px;">
            <p>Click the button below to regenerate both files from the current database contents.</p>
            <button type="submit" name="generate" class="btn btn-primary">Generate Missing Files</button>
        </form>
    </div>
</div>

<div class="card" style="margin-top: 20px;">
    <div class="card-header">
        <h2>File Descriptions</h2>
    </div>
    <div class="card-body">
        <h3>wanted.txt</h3>
        <p>Human-readable text file listing all wanted/missing apps with basic info (ID, title, author, category).
        Easy to read and share with the community.</p>

        <h3>wanted.csv</h3>
        <p>Full CSV export with all app data including device compatibility flags.
        Useful for analysis, tracking recovery progress, and importing into spreadsheets.</p>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
