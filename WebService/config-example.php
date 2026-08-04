<?php
//copy this file to config.php
//  put global config here, subdirectories are supported, but no trailing slashes
//  host these repositories over HTTPS; devices with the community OTA have TLS 1.3 / modern HTTPS support.
//  Plain HTTP (no redirect to HTTPS) is only needed as a fallback for stock devices without the OTA
//  (you can use the Upgrade-Insecure-Requests header in your server config to serve HTTPS to modern web clients)
//  Note: getConfig.php exposes ONLY an explicit allowlist of public values
//        (service_host, image_host, package_host, package_host_secure, contact_email).
//        Everything else here (db_*, download_secret, azure_connection_string) is secret
//        and is never returned by the API.

// Define function before use, with guard to prevent redeclaration
if (!function_exists('select_lb_resource')) {
    function select_lb_resource($resource_array) {
        return($resource_array[array_rand($resource_array)]);
    }
}

$image_mirrors = array(
        'appcatalog.webosarchive.org/AppImages'
);
$package_mirror_plain = array(
        'appstorage.webosarchive.org/packages'
);
$package_mirror_secure = 'appstorage.webosarchive.org/packages';

return array(
        // Public config (exposed via API)
        'service_host' => 'appcatalog.webosarchive.org',
        'image_host' => select_lb_resource($image_mirrors),
        'package_host' => select_lb_resource($package_mirror_plain),
        'package_host_secure' => $package_mirror_secure,
        // Base for the app-storage + web-auth endpoints (storage.php,
        // device.php), no trailing slash. Config-driven so clients never
        // hard-code it. Public (exposed via getConfig.php).
        'storage_host' => 'appcatalog.webosarchive.org/WebService',
        'contact_email' => 'webosarchive@gmail.com',

        // Filesystem path where app images (icons + screenshots) are stored, in
        // per-app "<appId>" folders, mirroring what image_host serves. Used for
        // admin/developer uploads. No trailing slash. NOT exposed via API.
        'image_path' => '/home/wosa/wosa-web/AppImages',

        // Download URL encoding secret (NOT exposed via API)
        // Used to obfuscate download URLs - change this to a random string
        'download_secret' => 'change_this_to_random_string',

        // Cloud app storage quotas (NOT exposed via API) — enforced on write
        // by WebService/storage.php; omit any key to use its built-in default.
        'storage_max_value_bytes' => 32768,          // 32 KB per value
        'storage_max_keys_per_app' => 200,           // keys per app per account
        'storage_max_bytes_per_app' => 524288,       // 512 KB per app per account
        'storage_max_bytes_per_account' => 2097152,  // 2 MB per account total
        'storage_writes_per_hour' => 300,            // per-account write throttle

        // Database credentials (NOT exposed via API - filtered in getConfig.php)
        'db_host' => 'localhost',
        'db_name' => 'webos_catalog',
        'db_user' => 'catalog_user',
        'db_pass' => 'change_this_password',

        // Azure Blob Storage (NOT exposed via API)
        // Connection string format: DefaultEndpointsProtocol=https;AccountName=xxx;AccountKey=xxx;EndpointSuffix=core.windows.net
        'azure_connection_string' => '',
        'azure_container_name' => 'ipk-packages'
);
?>
