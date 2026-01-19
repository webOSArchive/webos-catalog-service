<?php
/**
 * IPK Manager - Azure Blob Storage
 *
 * Admin page for listing and uploading IPK files to Azure Blob Storage.
 */
$pageTitle = 'IPK Manager';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/../includes/AzureBlobService.php';

$errors = [];
$success = '';

// Check if Azure is configured
$azureConfigured = AzureBlobService::isConfigured();

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['ipk_file']) && $azureConfigured) {
    try {
        // Validate file upload
        if ($_FILES['ipk_file']['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'Upload blocked by PHP extension'
            ];
            $errors[] = $uploadErrors[$_FILES['ipk_file']['error']] ?? 'Unknown upload error';
        }

        // Validate file extension
        if (empty($errors)) {
            $originalName = $_FILES['ipk_file']['name'];
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if ($ext !== 'ipk') {
                $errors[] = 'Invalid file type. Only .ipk files are allowed.';
            }
        }

        // Validate file size (50MB limit)
        if (empty($errors)) {
            $maxSize = 50 * 1024 * 1024; // 50MB
            if ($_FILES['ipk_file']['size'] > $maxSize) {
                $errors[] = 'File too large. Maximum size is 50MB.';
            }
        }

        // Upload to Azure
        if (empty($errors)) {
            $blobName = $originalName;
            $content = file_get_contents($_FILES['ipk_file']['tmp_name']);
            $azure = AzureBlobService::getInstance();

            if ($azure->uploadBlob($blobName, $content, 'application/octet-stream')) {
                $success = 'Successfully uploaded: ' . $blobName;
            } else {
                $errors[] = 'Upload failed. Please try again.';
            }
        }
    } catch (Exception $e) {
        $errors[] = 'Azure error: ' . $e->getMessage();
    }
}

// Get blob list with pagination
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$marker = isset($_GET['marker']) ? $_GET['marker'] : '';
$blobs = [];
$nextMarker = null;

if ($azureConfigured && empty($errors)) {
    try {
        $azure = AzureBlobService::getInstance();
        $result = $azure->listBlobs($search, $marker, 50);
        $blobs = $result['blobs'];
        $nextMarker = $result['nextMarker'];
    } catch (Exception $e) {
        $errors[] = 'Azure error: ' . $e->getMessage();
    }
}

/**
 * Format file size for display
 */
function formatFileSize(int $bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' bytes';
}

include 'includes/header.php';
?>

<div class="page-header">
    <h1>IPK Manager <small style="color:#7f8c8d;font-size:0.6em">(Azure Blob Storage)</small></h1>
    <?php if ($azureConfigured): ?>
    <button type="button" class="btn btn-primary" onclick="toggleUploadForm()">Upload New IPK</button>
    <?php endif; ?>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
    <strong>Error:</strong>
    <?php foreach ($errors as $error): ?>
    <p><?php echo htmlspecialchars($error); ?></p>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<?php if (!$azureConfigured): ?>
<div class="alert alert-warning">
    <strong>Azure Blob Storage is not configured.</strong>
    <p>Add <code>azure_connection_string</code> and <code>azure_container_name</code> to <code>WebService/config.php</code></p>
    <p>Connection string format: <code>DefaultEndpointsProtocol=https;AccountName=xxx;AccountKey=xxx;EndpointSuffix=core.windows.net</code></p>
</div>
<?php else: ?>

<div class="card" id="upload-form" style="display:none;">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="admin-form">
            <fieldset>
                <legend>Upload IPK to Azure</legend>
                <div class="form-group">
                    <label>IPK File</label>
                    <input type="file" name="ipk_file" accept=".ipk" required>
                    <small>Select a .ipk file to upload (max 50MB)</small>
                </div>
            </fieldset>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Upload IPK</button>
                <button type="button" class="btn" onclick="toggleUploadForm()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="get" class="search-form">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Filter by filename prefix...">
            <button type="submit" class="btn">Search</button>
            <?php if ($search): ?>
            <a href="ipk-manager.php" class="btn">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding:0;">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Filename</th>
                    <th>Size</th>
                    <th>Last Modified</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($blobs)): ?>
                <tr>
                    <td colspan="4" style="text-align:center;padding:40px;color:#7f8c8d;">
                        <?php echo $search ? 'No IPK files found matching "' . htmlspecialchars($search) . '".' : 'No IPK files found in this container.'; ?>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($blobs as $blob): ?>
                <tr>
                    <td><?php echo htmlspecialchars($blob['name']); ?></td>
                    <td><?php echo formatFileSize($blob['size']); ?></td>
                    <td><?php echo date('M j, Y H:i', strtotime($blob['lastModified'])); ?></td>
                    <td>
                        <button type="button" class="btn btn-sm" onclick="copyUrl('<?php echo htmlspecialchars($blob['url'], ENT_QUOTES); ?>')">Copy URL</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($nextMarker): ?>
<div class="pagination">
    <a href="?marker=<?php echo urlencode($nextMarker); ?>&search=<?php echo urlencode($search); ?>">Next Page &rsaquo;</a>
</div>
<?php endif; ?>

<?php endif; ?>

<script>
function toggleUploadForm() {
    var form = document.getElementById('upload-form');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function copyUrl(url) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function() {
            alert('URL copied to clipboard');
        }).catch(function() {
            fallbackCopyUrl(url);
        });
    } else {
        fallbackCopyUrl(url);
    }
}

function fallbackCopyUrl(url) {
    var textArea = document.createElement('textarea');
    textArea.value = url;
    textArea.style.position = 'fixed';
    textArea.style.left = '-9999px';
    document.body.appendChild(textArea);
    textArea.select();
    try {
        document.execCommand('copy');
        alert('URL copied to clipboard');
    } catch (err) {
        prompt('Copy this URL:', url);
    }
    document.body.removeChild(textArea);
}
</script>

<?php include 'includes/footer.php'; ?>
