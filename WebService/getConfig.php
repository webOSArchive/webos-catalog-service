<?PHP
$config = include('config.php');
header('Content-Type: application/json');

// Only expose an explicit allowlist of public values. Everything else
// (db_* credentials, download_secret, azure_connection_string, etc.) is a
// secret and must never be returned. Using an allowlist instead of a denylist
// ensures any future config key is private by default.
$publicKeys = [
    'service_host',
    'image_host',
    'package_host',
    'package_host_secure',
    'contact_email',
];

$publicConfig = array_intersect_key($config, array_flip($publicKeys));

echo(json_encode($publicConfig));
?>