<?php
$config = include('WebService/config.php');

//Get the app info
$download_path = "";

$content = file_get_contents(__DIR__ . '/0.json');
$outputObj = json_decode($content, true);

$attachment_location = $download_path . $outputObj["filename"];
header("Location: $attachment_location");

?>