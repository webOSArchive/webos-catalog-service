<?php
/**
 * build-large-cities.php - regenerate includes/large-cities.tsv, the list of
 * "large cities" the webOS user map snaps a user to when they choose
 * "Nearest large city" (see UserMapRepository::nearestLargeCity()).
 *
 * Source: GeoNames cities15000 (CC BY 4.0, https://www.geonames.org/),
 * filtered to places of 100,000 people or more, minus city districts.
 *
 * Usage:
 *   curl -O https://download.geonames.org/export/dump/cities15000.zip
 *   unzip cities15000.zip
 *   php scripts/build-large-cities.php cities15000.txt
 *
 * Output columns (tab-separated): name, country code, latitude, longitude, population.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

const MIN_POPULATION = 100000;
$source = $argv[1] ?? null;
if ($source === null || !is_readable($source)) {
    fwrite(STDERR, "Usage: php scripts/build-large-cities.php <cities15000.txt>\n");
    exit(1);
}

$in = fopen($source, 'r');
$rows = [];
while (($line = fgets($in)) !== false) {
    // geonames "geoname" table: 1 name, 4 latitude, 5 longitude, 7 feature code,
    // 8 country code, 14 population
    $f = explode("\t", rtrim($line, "\n"));
    if (count($f) < 15 || (int)$f[14] < MIN_POPULATION) continue;
    // Skip city districts and places that no longer exist. (Some districts,
    // like Montreal's boroughs, aren't coded PPLX; nearestLargeCity() copes
    // by preferring the biggest city nearby.)
    if (in_array($f[7], ['PPLX', 'PPLH', 'PPLQ', 'PPLW', 'PPLCH'], true)) continue;
    $rows[] = [$f[1], $f[8], round((float)$f[4], 4), round((float)$f[5], 4), (int)$f[14]];
}
fclose($in);

usort($rows, function ($a, $b) { return [$a[1], $a[0]] <=> [$b[1], $b[0]]; });

$outPath = __DIR__ . '/../includes/large-cities.tsv';
$out = fopen($outPath, 'w');
fwrite($out, "# Cities of " . number_format(MIN_POPULATION) . "+ people, from GeoNames (CC BY 4.0, https://www.geonames.org/).\n");
fwrite($out, "# Regenerate with scripts/build-large-cities.php. Columns: name, country code, latitude, longitude, population.\n");
foreach ($rows as $r) {
    fwrite($out, implode("\t", $r) . "\n");
}
fclose($out);

echo count($rows) . " cities written to includes/large-cities.tsv\n";
