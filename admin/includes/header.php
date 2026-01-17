<?php
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
    <nav class="admin-nav">
        <div class="nav-brand">webOS Catalog Admin</div>
        <ul class="nav-links">
            <li><a href="index.php" class="<?php echo $currentPage === 'index' ? 'active' : ''; ?>">Dashboard</a></li>
            <li><a href="apps.php" class="<?php echo $currentPage === 'apps' ? 'active' : ''; ?>">Apps</a></li>
            <li><a href="categories.php" class="<?php echo $currentPage === 'categories' ? 'active' : ''; ?>">Categories</a></li>
            <li><a href="authors.php" class="<?php echo $currentPage === 'authors' ? 'active' : ''; ?>">Authors</a></li>
            <li><a href="logs.php" class="<?php echo $currentPage === 'logs' ? 'active' : ''; ?>">Logs</a></li>
        </ul>
        <div class="nav-actions">
            <a href="../" target="_blank">View Site</a>
        </div>
    </nav>
    <main class="admin-content">
