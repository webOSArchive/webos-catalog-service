<?php
/**
 * create-account.php - provision a user account from the command line.
 *
 * Accounts are admin-provisioned only (no web self-signup). This is both the
 * initial superadmin seed and the ongoing provisioning tool until the Phase 1
 * admin "Accounts" page exists.
 *
 * Usage:
 *   php scripts/create-account.php <username> [role]
 *
 *   role defaults to "superadmin". Valid roles: superadmin, admin, curator, developer, viewer.
 *
 * The password is read from a hidden prompt (never passed on the command line,
 * so it stays out of shell history and the process list).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../includes/AccountRepository.php';
require_once __DIR__ . '/../includes/Capabilities.php';

function fail($msg) {
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

function prompt($label, $hidden = false) {
    fwrite(STDOUT, $label);
    if ($hidden) {
        // Disable terminal echo for password entry; restore afterwards.
        shell_exec('stty -echo 2>/dev/null');
    }
    $line = fgets(STDIN);
    if ($hidden) {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, "\n");
    }
    return $line === false ? '' : rtrim($line, "\r\n");
}

$username = $argv[1] ?? null;
$role     = $argv[2] ?? 'superadmin';

if (!$username) {
    fail("Usage: php scripts/create-account.php <username> [role]\n" .
         "Roles: " . implode(', ', Capabilities::roleNames()));
}
if (!in_array($role, Capabilities::roleNames(), true)) {
    fail("Unknown role '$role'. Valid roles: " . implode(', ', Capabilities::roleNames()));
}

try {
    $repo = new AccountRepository();
} catch (Throwable $e) {
    fail("Database connection failed: " . $e->getMessage());
}

if ($repo->findByUsername($username)) {
    fail("An account named '$username' already exists.");
}

$email = prompt("Email (optional): ");
$email = trim($email);
if ($email === '') {
    $email = null;
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail("'$email' is not a valid email address.");
}

$password = prompt("Password: ", true);
$confirm  = prompt("Confirm password: ", true);

if ($password === '') {
    fail("Password may not be empty.");
}
if ($password !== $confirm) {
    fail("Passwords did not match.");
}
if (strlen($password) < 8) {
    fail("Password must be at least 8 characters.");
}

try {
    $id = $repo->create([
        'username'     => $username,
        'password'     => $password,
        'email'        => $email,
        'display_name' => $username,
        'status'       => 'active',
    ]);
    if (!$repo->assignRole($id, $role)) {
        fail("Account #$id created, but role '$role' could not be assigned " .
             "(did you run the 0001_accounts.sql migration?).");
    }
} catch (Throwable $e) {
    fail("Could not create account: " . $e->getMessage());
}

echo "Created account #$id ('$username') with role '$role'.\n";
echo "Capabilities: " . implode(', ', $repo->getCapabilities($id)) . "\n";
