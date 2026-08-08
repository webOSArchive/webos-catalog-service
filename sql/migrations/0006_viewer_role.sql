-- 0006: 'viewer' role — read-only accounts.
--
-- Grants only admin.access (via includes/Capabilities.php), so accounts with
-- this role can sign into /admin but every other capability check fails,
-- landing them on the read-only Stats page (admin/stats.php) instead of the
-- editable Dashboard. Apply, then assign the role to an account from
-- Accounts (admin/accounts.php).
--
-- Apply:  mysql -u <user> -p <db> < sql/migrations/0006_viewer_role.sql

INSERT IGNORE INTO roles (name) VALUES ('viewer');
