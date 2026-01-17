<?PHP
$config = include('config.php');
header('Content-Type: application/json');

// Filter out database credentials - these should never be exposed
$publicConfig = array_filter($config, function($key) {
    return strpos($key, 'db_') !== 0;
}, ARRAY_FILTER_USE_KEY);

echo(json_encode($publicConfig));
?>