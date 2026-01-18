<?php
//copy this file to config.php
//  put global config here, subdirectories are supported, but no trailing slashes
//  you must host these repositories over HTTPS AND HTTP without redirecting to HTTPS
//  (you can use the Upgrade-Insecure-Requests header in your server config to serve HTTPS to modern web clients)
//  Note: Public config values (service_host, etc.) are exposed via getConfig.php API
//        Database credentials (db_*) are NOT exposed - they are filtered out in getConfig.php

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
        'contact_email' => 'webosarchive@gmail.com',

        // Database credentials (NOT exposed via API - filtered in getConfig.php)
        'db_host' => 'localhost',
        'db_name' => 'webos_catalog',
        'db_user' => 'catalog_user',
        'db_pass' => 'change_this_password'
);
?>
