<?php
if (!defined('ADMIN_BOOTSTRAP')) { http_response_code(403); exit('Forbidden'); }
/**
 * Admin UI Header
 *
 * Security: This folder should be protected by nginx basic auth.
 * Add to your nginx server block:
 *
 * location /admin {
 *     auth_basic "webOS Catalog Admin";
 *     auth_basic_user_file /path/to/.htpasswd;
 * }
 *
 * Create password file: htpasswd -c /path/to/.htpasswd username
 */

// Get current page for navigation highlighting
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>webOS Catalog Admin<?php echo isset($pageTitle) ? ' - ' . htmlspecialchars($pageTitle) : ''; ?></title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
    <nav class="admin-nav" id="adminNav">
        <div class="nav-brand">webOS Catalog Admin</div>
        <button type="button" class="nav-toggle" id="navToggle" aria-label="Menu" aria-controls="navMenu" aria-expanded="false">
            <span class="nav-toggle-bar"></span><span class="nav-toggle-bar"></span><span class="nav-toggle-bar"></span>
        </button>
        <div class="nav-menu" id="navMenu">
        <ul class="nav-links">
            <?php if (!admin_is_owner_only()): ?>
            <li><a href="index.php" class="<?php echo $currentPage === 'index' ? 'active' : ''; ?>">Dashboard</a></li>
            <?php endif; ?>
            <?php if (admin_has_capability('apps.edit') || admin_has_capability('apps.own')): ?>
            <li><a href="apps.php" class="<?php echo $currentPage === 'apps' ? 'active' : ''; ?>">Apps</a></li>
            <?php endif; ?>
            <?php if (admin_has_capability('categories.manage')): ?>
            <li><a href="categories.php" class="<?php echo $currentPage === 'categories' ? 'active' : ''; ?>">Categories</a></li>
            <?php endif; ?>
            <?php if (admin_has_capability('authors.manage')): ?>
            <li><a href="authors.php" class="<?php echo $currentPage === 'authors' ? 'active' : ''; ?>">Authors</a></li>
            <?php endif; ?>
            <?php if (admin_has_capability('ipk.manage')): ?>
            <li><a href="ipk-manager.php" class="<?php echo $currentPage === 'ipk-manager' ? 'active' : ''; ?>">IPKs</a></li>
            <?php endif; ?>
            <?php if (admin_has_capability('logs.view')): ?>
            <li><a href="logs.php" class="<?php echo $currentPage === 'logs' ? 'active' : ''; ?>">Logs</a></li>
            <?php endif; ?>
            <?php if (admin_has_capability('accounts.manage')): ?>
            <li><a href="accounts.php" class="<?php echo $currentPage === 'accounts' ? 'active' : ''; ?>">Accounts</a></li>
            <?php endif; ?>
        </ul>
        <div class="nav-actions">
            <a href="../" target="_blank">View Site</a>
            <?php if (function_exists('current_account') && ($__acct = current_account())): ?>
            <span class="nav-user"><?php echo htmlspecialchars($__acct['username']); ?></span>
            <a href="logout.php">Log out</a>
            <?php endif; ?>
        </div>
        </div>
    </nav>
    <main class="admin-content">
