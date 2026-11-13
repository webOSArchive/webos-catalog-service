<?php
/**
 * Secrets vault - signing keys, client IDs and other credentials shared with
 * trusted community leaders, so the project can carry on without its founder.
 * Storage + crypto: includes/SecretsRepository.php (migration 0016).
 *
 * Who: 'secrets.view' (trustee role, superadmin) can unlock, view and
 * download; 'secrets.manage' (superadmin) can also add, change and delete
 * entries and set or change the vault password. Opening the vault always
 * needs the shared vault password on top of the admin login.
 *
 * While unlocked, the vault key is kept split in two: the session holds it
 * sealed with a random wrapping key, and that wrapping key lives only in a
 * cookie (WOSVAULT, HttpOnly, SameSite=Strict, /admin/). Session files on the
 * server's disk therefore never contain a usable key, and the cookie alone is
 * worthless. The unlock lapses after IDLE_TIMEOUT seconds without activity,
 * on Lock, on logout, and as soon as another admin changes the password.
 *
 * Every unlock (and failed attempt), view, download and change is logged to
 * secrets_access_log and shown at the bottom of this page.
 */
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/../includes/SecretsRepository.php';
require_once __DIR__ . '/../WebService/ratelimit.php';
admin_require_capability('secrets.view');
$pageTitle = 'Secrets';

const VAULT_COOKIE  = 'WOSVAULT';
const IDLE_TIMEOUT  = 600;  // seconds of inactivity before the vault locks again
const UNLOCK_FAILS  = 5;    // failed unlocks allowed per account...
const UNLOCK_WINDOW = 900;  // ...within 15 minutes

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

$me        = current_account();
$canManage = admin_has_capability('secrets.manage');
$repo      = new SecretsRepository();
$limiter   = new RateLimit(__DIR__ . '/../WebService/__rateLimit');
$clientIp  = $limiter->getClientIP();
$failKey   = 'vault_' . (int)$me['id'];

$errors  = [];
$success = '';
$notice  = '';

// --- Unlocked-key handling ----------------------------------------------------

function vault_cookie_secure() {
    return (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function vault_set_cookie($value, $expires) {
    setcookie(VAULT_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/admin/',
        'httponly' => true,
        'secure'   => vault_cookie_secure(),
        'samesite' => 'Strict',
    ]);
}

function vault_remember($key) {
    $wrap = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $_SESSION['vault'] = [
        'wrapped' => SecretsRepository::seal($key, $wrap),
        'seen'    => time(),
    ];
    vault_set_cookie(sodium_bin2base64($wrap, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), 0);
    $_COOKIE[VAULT_COOKIE] = sodium_bin2base64($wrap, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
}

function vault_forget() {
    unset($_SESSION['vault']);
    if (isset($_COOKIE[VAULT_COOKIE])) {
        vault_set_cookie('', time() - 3600);
        unset($_COOKIE[VAULT_COOKIE]);
    }
}

/** The unlocked vault key for this session, or null. Sets $why when it lapsed. */
function vault_key(SecretsRepository $repo, &$why = null) {
    $s = $_SESSION['vault'] ?? null;
    $c = $_COOKIE[VAULT_COOKIE] ?? '';
    if (!$s || !is_string($c) || $c === '') {
        if ($s) { vault_forget(); }
        return null;
    }
    if (time() - (int)$s['seen'] > IDLE_TIMEOUT) {
        vault_forget();
        $why = 'The vault locked itself after ' . (IDLE_TIMEOUT / 60) . ' minutes without activity.';
        return null;
    }
    try {
        $wrap = sodium_base642bin($c, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    } catch (SodiumException $e) {
        $wrap = '';
    }
    $key = strlen($wrap) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? SecretsRepository::open($s['wrapped'], $wrap) : false;
    if ($key === false) {
        vault_forget();
        return null;
    }
    if (!$repo->keyIsCurrent($key)) {
        vault_forget();
        $why = 'The vault password was changed. Unlock with the new password.';
        return null;
    }
    $_SESSION['vault']['seen'] = time();
    return $key;
}

/** Validate an upload or pasted text. Returns [filename, content] or a string error. */
function vault_read_content($required) {
    $f = $_FILES['file'] ?? null;
    if ($f && $f['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            return 'That file is too large (1 MB at most).';
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return 'The upload failed. Please try again.';
        }
        if ($f['size'] > SecretsRepository::MAX_CONTENT_BYTES) {
            return 'That file is too large (1 MB at most).';
        }
        $content = file_get_contents($f['tmp_name']);
        @unlink($f['tmp_name']);
        $name = vault_clean_filename($f['name']);
        return [$name !== '' ? $name : 'secret.bin', $content];
    }
    $text = (string)($_POST['text'] ?? '');
    if ($text !== '') {
        if (strlen($text) > SecretsRepository::MAX_CONTENT_BYTES) {
            return 'That text is too long (1 MB at most).';
        }
        $text = str_replace("\r\n", "\n", $text);
        $name = vault_clean_filename($_POST['text_filename'] ?? '');
        return [$name !== '' ? $name : 'secret.txt', $text];
    }
    return $required ? 'Choose a file or paste the contents.' : null;
}

function vault_clean_filename($name) {
    $name = basename(str_replace('\\', '/', (string)$name));
    $name = preg_replace('/[\x00-\x1F\x7F"]/u', '', $name);
    return mb_substr(trim($name), 0, 200);
}

function vault_is_text($content) {
    return $content !== '' && mb_check_encoding($content, 'UTF-8') && strpos($content, "\0") === false;
}

function vault_size($bytes) {
    return $bytes < 1024 ? $bytes . ' B' : number_format($bytes / 1024, 1) . ' KB';
}

$lapsed = null;
$key = vault_key($repo, $lapsed);
if ($lapsed) {
    $notice = $lapsed;
}
$initialized = $repo->isInitialized();

// --- Actions -------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_validate()) {
        $errors[] = 'Your session expired. Please try again.';

    } elseif ($action === 'lock') {
        vault_forget();
        header('Location: secrets.php');
        exit;

    } elseif ($action === 'init' && $canManage && !$initialized) {
        $pw = (string)($_POST['password'] ?? '');
        if ($err = SecretsRepository::passwordError($pw)) {
            $errors[] = $err;
        } elseif (!hash_equals($pw, (string)($_POST['confirm'] ?? ''))) {
            $errors[] = 'The two passwords do not match.';
        } elseif (($newKey = $repo->initialize($pw)) === null) {
            $errors[] = 'Another admin set the vault password just now. Unlock with theirs.';
        } else {
            $repo->log($me['id'], $me['username'], 'init', null, $clientIp);
            vault_remember($newKey);
            header('Location: secrets.php?done=init');
            exit;
        }

    } elseif ($action === 'unlock' && $initialized) {
        if ($limiter->recentCount($failKey, UNLOCK_WINDOW) >= UNLOCK_FAILS) {
            $errors[] = 'Too many wrong passwords. Wait 15 minutes and try again.';
        } else {
            $newKey = $repo->unlock((string)($_POST['password'] ?? ''));
            if ($newKey === null) {
                $limiter->record($failKey);
                $repo->log($me['id'], $me['username'], 'unlock_failed', null, $clientIp);
                $errors[] = 'That is not the vault password.';
            } else {
                $limiter->clear($failKey);
                $repo->log($me['id'], $me['username'], 'unlock', null, $clientIp);
                session_regenerate_id(true);
                vault_remember($newKey);
                header('Location: secrets.php');
                exit;
            }
        }

    } elseif (!$key) {
        $errors[] = $lapsed ?: 'Unlock the vault first.';

    } elseif (!$canManage) {
        http_response_code(403);
        die('Forbidden: your account does not have the required permission (secrets.manage).');

    } elseif ($action === 'create' || $action === 'update') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim((string)($_POST['name'] ?? ''));
        $notes = trim(str_replace("\r\n", "\n", (string)($_POST['notes'] ?? '')));
        $existing = $action === 'update' ? $repo->getEntry($id, $key) : null;
        $file = vault_read_content($action === 'create');

        if ($name === '') {
            $errors[] = 'Give the entry a name.';
        } elseif (mb_strlen($name) > 200) {
            $errors[] = 'Keep the name under 200 characters.';
        }
        if (mb_strlen($notes) > 20000) {
            $errors[] = 'Keep the notes under 20,000 characters.';
        }
        if (is_string($file)) {
            $errors[] = $file;
        }
        if ($action === 'update' && !$existing) {
            $errors[] = 'That entry no longer exists.';
        }
        if (empty($errors)) {
            try {
                if ($action === 'create') {
                    $id = $repo->create($key, $name, $notes, $file[0], $file[1], $me['id']);
                } else {
                    $repo->update($key, $id, $name, $notes,
                        $file ? $file[0] : $existing['filename'], $file ? $file[1] : null, $me['id']);
                }
                $repo->log($me['id'], $me['username'], $action, $id, $clientIp);
                header('Location: secrets.php?done=' . $action);
                exit;
            } catch (RuntimeException $e) {
                vault_forget();
                $key = null;
                $errors[] = $e->getMessage();
            }
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($repo->delete($id)) {
            $repo->log($me['id'], $me['username'], 'delete', $id, $clientIp);
        }
        header('Location: secrets.php?done=delete');
        exit;

    } elseif ($action === 'rekey') {
        $pw = (string)($_POST['new_password'] ?? '');
        if ($limiter->recentCount($failKey, UNLOCK_WINDOW) >= UNLOCK_FAILS) {
            $errors[] = 'Too many wrong passwords. Wait 15 minutes and try again.';
        } elseif ($repo->unlock((string)($_POST['current_password'] ?? '')) === null) {
            $limiter->record($failKey);
            $repo->log($me['id'], $me['username'], 'unlock_failed', null, $clientIp);
            $errors[] = 'The current vault password is not right.';
        } elseif ($err = SecretsRepository::passwordError($pw)) {
            $errors[] = $err;
        } elseif (!hash_equals($pw, (string)($_POST['confirm'] ?? ''))) {
            $errors[] = 'The two new passwords do not match.';
        } else {
            try {
                $newKey = $repo->rekey($key, $pw);
                $repo->log($me['id'], $me['username'], 'rekey', null, $clientIp);
                vault_remember($newKey);
                header('Location: secrets.php?done=rekey');
                exit;
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

// --- Downloads (before any HTML) -------------------------------------------------

if ($key && isset($_GET['download'])) {
    $entry = $repo->getEntry((int)$_GET['download'], $key);
    if (!$entry || $entry['content'] === false) {
        http_response_code(404);
        die('That entry does not exist or could not be decrypted.');
    }
    $repo->log($me['id'], $me['username'], 'download', $entry['id'], $clientIp);
    $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $entry['filename']);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($entry['filename']));
    header('Content-Length: ' . strlen($entry['content']));
    echo $entry['content'];
    exit;
}

$doneMessages = [
    'init'   => 'The vault is set up and unlocked. Share the vault password with the trustees in person or over a private channel.',
    'create' => 'Entry added.',
    'update' => 'Entry saved.',
    'delete' => 'Entry deleted.',
    'rekey'  => 'Vault password changed and every entry re-encrypted. Anyone else with the vault open has been locked out until they use the new password.',
];
if (isset($_GET['done'], $doneMessages[$_GET['done']])) {
    $success = $doneMessages[$_GET['done']];
}

// --- Page data -----------------------------------------------------------------

$entries = $key ? $repo->listEntries($key) : [];
$names = [];
foreach ($entries as $e) {
    $names[$e['id']] = $e['name'];
}

$viewEntry = null;
if ($key && isset($_GET['view'])) {
    $viewEntry = $repo->getEntry((int)$_GET['view'], $key);
    if ($viewEntry) {
        $repo->log($me['id'], $me['username'], 'view', $viewEntry['id'], $clientIp);
    }
}
$editEntry = null;
if ($key && $canManage && isset($_GET['edit'])) {
    $editEntry = $repo->getEntry((int)$_GET['edit'], $key);
} elseif ($key && $canManage && $errors && ($_POST['action'] ?? '') === 'update') {
    $editEntry = $repo->getEntry((int)($_POST['id'] ?? 0), $key); // keep the edit form after a failed save
}

$accountNames = [];
if ($entries) {
    $acctRepo = new AccountRepository();
    foreach ($entries as $e) {
        foreach ([$e['created_by'], $e['updated_by']] as $aid) {
            if ($aid && !isset($accountNames[$aid])) {
                $a = $acctRepo->findById($aid);
                $accountNames[$aid] = $a ? $a['username'] : "#$aid";
            }
        }
    }
}

$log = $repo->recentLog(100);
$actionLabels = [
    'init' => 'set up the vault', 'unlock' => 'unlocked', 'unlock_failed' => 'wrong password',
    'view' => 'viewed', 'download' => 'downloaded', 'create' => 'added', 'update' => 'changed',
    'delete' => 'deleted', 'rekey' => 'changed the vault password',
];

include 'includes/header.php';
?>

<style>
    .secret-notes {
        max-width: 360px;
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
        line-clamp: 2;
        overflow: hidden;
        overflow-wrap: anywhere;
    }
</style>

<div class="page-header">
    <h1>Secrets</h1>
    <?php if ($key): ?>
    <form method="post" style="margin:0;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="lock">
        <button type="submit" class="btn">Lock</button>
    </form>
    <?php endif; ?>
</div>

<p style="color:#777;font-size:13px;max-width:760px;">
    Signing keys, client IDs and other credentials the community needs to keep things running if the
    maintainer can't. Everything here is encrypted with the shared vault password, which is never stored
    on the server. Every unlock, view and download is logged below.
</p>

<?php if ($success): ?>
<div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($notice && !$errors): ?>
<div class="alert alert-warning"><?php echo htmlspecialchars($notice); ?></div>
<?php endif; ?>
<?php if ($errors): ?>
<div class="alert alert-error">
    <?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$initialized): ?>
<div class="card" style="max-width:520px;">
    <div class="card-header"><h2>Set Up the Vault</h2></div>
    <div class="card-body">
        <?php if ($canManage): ?>
        <p style="color:#777;font-size:13px;margin-top:0;">
            Choose the shared vault password. It can't be recovered: if every trustee forgets it, the
            contents are gone. Write it down somewhere safe, then share it with the trustees in person or
            over a private channel, never in a public one.
        </p>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="init">
            <div class="form-group">
                <label>Vault Password</label>
                <input type="password" name="password" autocomplete="new-password" required minlength="<?php echo SecretsRepository::MIN_PASSWORD_LENGTH; ?>">
                <small>At least <?php echo SecretsRepository::MIN_PASSWORD_LENGTH; ?> characters. A few random words works well.</small>
            </div>
            <div class="form-group">
                <label>Confirm Vault Password</label>
                <input type="password" name="confirm" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-primary">Set Up Vault</button>
        </form>
        <?php else: ?>
        <p>The vault hasn't been set up yet. A superadmin needs to choose the vault password first.</p>
        <?php endif; ?>
    </div>
</div>

<?php elseif (!$key): ?>
<div class="card" style="max-width:420px;">
    <div class="card-header"><h2>Unlock the Vault</h2></div>
    <div class="card-body">
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="unlock">
            <div class="form-group">
                <label>Vault Password</label>
                <input type="password" name="password" autocomplete="off" required autofocus>
                <small>This is the shared vault password, not your sign-in password.</small>
            </div>
            <button type="submit" class="btn btn-primary">Unlock</button>
        </form>
    </div>
</div>

<?php else: ?>

<?php if ($viewEntry): ?>
<div class="card">
    <div class="card-header"><h2><?php echo htmlspecialchars($viewEntry['name']); ?></h2></div>
    <div class="card-body">
        <?php if ($viewEntry['notes'] !== ''): ?>
        <div style="white-space:pre-wrap;overflow-wrap:anywhere;margin-bottom:12px;"><?php echo htmlspecialchars($viewEntry['notes']); ?></div>
        <?php endif; ?>
        <p style="color:#777;font-size:13px;"><?php echo htmlspecialchars($viewEntry['filename']); ?> &middot; <?php echo vault_size($viewEntry['content_size']); ?></p>
        <?php if ($viewEntry['content'] === false): ?>
        <div class="alert alert-error">This file could not be decrypted.</div>
        <?php elseif (vault_is_text($viewEntry['content'])): ?>
        <pre style="background:#f6f6f6;border:1px solid #ddd;padding:12px;overflow:auto;max-height:480px;white-space:pre-wrap;word-break:break-all;"><?php echo htmlspecialchars($viewEntry['content']); ?></pre>
        <?php else: ?>
        <p>This is a binary file. Download it to use it.</p>
        <?php endif; ?>
        <a href="secrets.php?download=<?php echo $viewEntry['id']; ?>" class="btn btn-primary">Download</a>
        <a href="secrets.php" class="btn">Close</a>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>Entries (<?php echo count($entries); ?>)</h2></div>
    <div class="card-body">
        <?php if (!$entries): ?>
        <p style="color:#777;">No entries yet.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr><th>Name</th><th>Notes</th><th>File</th><th>Last changed</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $e): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($e['name']); ?></strong></td>
                    <td style="color:#555;font-size:13px;">
                        <?php
                        // One flattened line, clamped to two rows by CSS; the full
                        // notes are under View.
                        $flat = trim(preg_replace('/\s+/u', ' ', $e['notes']));
                        $short = mb_substr($flat, 0, 300);
                        ?>
                        <div class="secret-notes" title="<?php echo htmlspecialchars(mb_substr($flat, 0, 1000), ENT_QUOTES); ?>"><?php echo htmlspecialchars($short) . (mb_strlen($flat) > 300 ? '&hellip;' : ''); ?></div>
                    </td>
                    <td style="font-size:13px;"><?php echo htmlspecialchars($e['filename']); ?><br><span style="color:#777;"><?php echo vault_size($e['content_size']); ?></span></td>
                    <td style="font-size:13px;"><?php echo htmlspecialchars($e['updated_at']); ?><br><span style="color:#777;">by <?php echo htmlspecialchars($accountNames[$e['updated_by']] ?? 'unknown'); ?></span></td>
                    <td style="white-space:nowrap;">
                        <a href="secrets.php?view=<?php echo $e['id']; ?>" class="btn btn-sm">View</a>
                        <a href="secrets.php?download=<?php echo $e['id']; ?>" class="btn btn-sm btn-primary">Download</a>
                        <?php if ($canManage): ?>
                        <a href="secrets.php?edit=<?php echo $e['id']; ?>#entry-form" class="btn btn-sm">Edit</a>
                        <form method="post" style="display:inline;">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $e['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger" data-confirm="Delete &quot;<?php echo htmlspecialchars($e['name'], ENT_QUOTES); ?>&quot; from the vault? This can't be undone.">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManage): ?>
<div class="card-grid">
<div class="card" id="entry-form">
    <div class="card-header"><h2><?php echo $editEntry ? 'Edit Entry' : 'Add Entry'; ?></h2></div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="<?php echo $editEntry ? 'update' : 'create'; ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo SecretsRepository::MAX_CONTENT_BYTES; ?>">
            <?php if ($editEntry): ?>
            <input type="hidden" name="id" value="<?php echo $editEntry['id']; ?>">
            <?php endif; ?>
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="name" required maxlength="200" value="<?php echo htmlspecialchars($editEntry['name'] ?? ($_POST['name'] ?? '')); ?>" placeholder="e.g. App Museum IPK signing key">
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="5" placeholder="What it's for, how to use it, where it's registered, when it expires"><?php echo htmlspecialchars($editEntry['notes'] ?? ($_POST['notes'] ?? '')); ?></textarea>
            </div>
            <div class="form-group">
                <label><?php echo $editEntry ? 'Replace File' : 'File'; ?></label>
                <input type="file" name="file">
                <small>
                    <?php if ($editEntry): ?>Currently <?php echo htmlspecialchars($editEntry['filename']); ?> (<?php echo vault_size($editEntry['content_size']); ?>). Leave empty to keep it. <?php endif; ?>
                    Up to 1 MB, any type.
                </small>
            </div>
            <div class="form-group">
                <label>&hellip;or paste the contents</label>
                <textarea name="text" rows="6" style="font-family:monospace;" autocomplete="off" spellcheck="false"></textarea>
                <input type="text" name="text_filename" placeholder="File name for pasted text (default secret.txt)" style="margin-top:6px;">
            </div>
            <button type="submit" class="btn btn-primary"><?php echo $editEntry ? 'Save' : 'Add Entry'; ?></button>
            <?php if ($editEntry): ?><a href="secrets.php" class="btn">Cancel</a><?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Change Vault Password</h2></div>
    <div class="card-body">
        <p style="color:#777;font-size:13px;margin-top:0;">
            Do this when someone leaves the trustees. Every entry is re-encrypted with the new password,
            and anyone with the vault open is locked out until they use it.
        </p>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="rekey">
            <div class="form-group">
                <label>Current Vault Password</label>
                <input type="password" name="current_password" autocomplete="off" required>
            </div>
            <div class="form-group">
                <label>New Vault Password</label>
                <input type="password" name="new_password" autocomplete="new-password" required minlength="<?php echo SecretsRepository::MIN_PASSWORD_LENGTH; ?>">
                <small>At least <?php echo SecretsRepository::MIN_PASSWORD_LENGTH; ?> characters.</small>
            </div>
            <div class="form-group">
                <label>Confirm New Vault Password</label>
                <input type="password" name="confirm" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-danger">Change Vault Password</button>
        </form>
    </div>
</div>
</div>
<?php endif; ?>

<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>Access Log</h2></div>
    <div class="card-body">
        <?php if (!$log): ?>
        <p style="color:#777;">Nothing yet.</p>
        <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>When</th><th>Who</th><th>What</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($log as $l): ?>
                <tr<?php echo $l['action'] === 'unlock_failed' ? ' style="color:#b00;"' : ''; ?>>
                    <td style="white-space:nowrap;font-size:13px;"><?php echo htmlspecialchars($l['created_at']); ?></td>
                    <td><?php echo htmlspecialchars($l['username']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($actionLabels[$l['action']] ?? $l['action']); ?>
                        <?php if ($l['secret_id']): ?>
                            <?php if (isset($names[$l['secret_id']])): ?>
                            &ldquo;<?php echo htmlspecialchars($names[$l['secret_id']]); ?>&rdquo;
                            <?php else: ?>
                            entry #<?php echo (int)$l['secret_id']; ?><?php echo $key ? ' (since deleted)' : ''; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:13px;color:#777;"><?php echo htmlspecialchars((string)$l['ip_address']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
