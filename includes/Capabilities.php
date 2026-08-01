<?php
/**
 * Capabilities - role -> capability map for the accounts system (Phase 0).
 *
 * Capabilities are defined in code (not the database) so the permission model
 * stays reviewable in git and simple to reason about. Roles live in the `roles`
 * table and are assigned via `account_roles`; this class turns a set of role
 * names into the capabilities they grant.
 *
 * 'superadmin' holds the wildcard '*', which grants every capability.
 *
 * Nothing enforces these yet - Phase 1 (admin login) will gate pages with
 * AccountRepository::hasCapability($accountId, 'admin.access') and friends.
 */
class Capabilities {

    /** Every capability the app knows about (documentation + validation). */
    const ALL = [
        'admin.access',       // may reach the /admin portal at all
        'accounts.manage',    // create/edit accounts and role assignments
        'apps.edit',          // edit any app / metadata
        'apps.submit',        // submit new apps
        'apps.own',           // manage apps this account owns (owner_account_id)
        'reviews.moderate',   // moderate/flag reviews
        'ipk.manage',         // manage IPK packages
        'categories.manage',  // manage categories
        'authors.manage',     // manage authors/vendors
        'logs.view',          // view logs / reports
    ];

    /** Role name -> capabilities granted. '*' means all capabilities. */
    const ROLE_CAPS = [
        'superadmin' => ['*'],
        'admin'      => [
            'admin.access', 'apps.edit', 'apps.submit', 'reviews.moderate',
            'ipk.manage', 'categories.manage', 'authors.manage', 'logs.view',
        ],
        'curator'    => [
            'admin.access', 'apps.edit', 'categories.manage',
            'authors.manage', 'reviews.moderate', 'logs.view',
        ],
        'developer'  => ['admin.access', 'apps.submit', 'apps.own', 'logs.view', 'ipk.manage'],
    ];

    /**
     * Resolve a set of role names to the flat list of capabilities they grant.
     * If any role holds the wildcard, returns the full capability list.
     *
     * @param string[] $roleNames
     * @return string[]
     */
    public static function forRoles(array $roleNames): array {
        $caps = [];
        foreach ($roleNames as $role) {
            $granted = self::ROLE_CAPS[$role] ?? [];
            if (in_array('*', $granted, true)) {
                return self::ALL;
            }
            foreach ($granted as $c) {
                $caps[$c] = true;
            }
        }
        return array_keys($caps);
    }

    /**
     * Does any of the given roles grant $capability (directly or via '*')?
     *
     * @param string[] $roleNames
     * @param string   $capability
     * @return bool
     */
    public static function roleListGrants(array $roleNames, string $capability): bool {
        foreach ($roleNames as $role) {
            $granted = self::ROLE_CAPS[$role] ?? [];
            if (in_array('*', $granted, true) || in_array($capability, $granted, true)) {
                return true;
            }
        }
        return false;
    }

    /** Known role names (matches the seed rows in 0001_accounts.sql). */
    public static function roleNames(): array {
        return array_keys(self::ROLE_CAPS);
    }
}
