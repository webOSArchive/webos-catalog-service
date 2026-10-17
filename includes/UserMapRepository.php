<?php
/**
 * User Map Repository - the opt-in webOS user map (migration 0013).
 *
 * An account that opts in on My Account is placed on the map at the
 * precision it chose (nearest large city, state, or country), using the most
 * recent update check one of its signed-in devices made since opting in. The
 * shared updaters send the device nduid as `clientid`, so
 * update_check_logs.client_id matches account_tokens.device_id.
 *
 * Location is a two-step lookup, both steps cached in the database:
 *   1. IP -> place names (country / state / city, plus the IP's rough
 *      coordinates) from the MaxMind-shaped geo service that
 *      WebService/reports/getgeodata.php also uses (geo_ip_cache).
 *   2. place -> published point. A state or country is its center from
 *      OpenStreetMap's Nominatim (cached in geo_place_cache); a city is the
 *      nearest city of 100,000+ people in includes/large-cities.tsv
 *      (GeoNames). The IP's own coordinates are never published.
 * If a precision can't be resolved (no city in the IP record, no large city
 * nearby, an unknown state), it falls back to the next coarser one.
 *
 * External lookups are capped per call ($lookupBudget) so a map request with
 * many new users can't stall on Nominatim's 1-request-per-second policy;
 * users that don't fit are counted as pending and resolve on a later call.
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AccountRepository.php';

class UserMapRepository {
    const PRECISIONS = ['city', 'state', 'country'];
    const LOOKBACK_MONTHS = 12;
    const CACHE_DAYS = 30;
    const METRO_KM = 50;
    const CITY_RADIUS_KM = 300;

    const DEFAULT_GEOIP_URL = 'http://museum.weboslives.eu/dqidqsrwpnhotjldxljdhkxubidheffi/fhlyggephfhwaljgtxwqxmyhuvdexcjr.php?ip=';
    const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    private $db;
    private $geoipUrl;
    private $userAgent;
    private $lastNominatimCall = 0.0;

    public function __construct(array $config = []) {
        $this->db = Database::getInstance()->getConnection();
        $this->geoipUrl = $config['geoip_service_url'] ?? self::DEFAULT_GEOIP_URL;
        // Nominatim's usage policy asks for an identifying User-Agent.
        $contact = $config['contact_email'] ?? 'webosarchive@gmail.com';
        $this->userAgent = 'webOS App Museum II user map (' . $contact . ')';
    }

    // -- Settings (My Account) ------------------------------------------------

    /** @return array{opted_in: bool, opted_in_at: ?string, precision: string} */
    public function settings($accountId) {
        $stmt = $this->db->prepare("SELECT map_opt_in_at, map_precision FROM accounts WHERE id = ?");
        $stmt->execute([(int)$accountId]);
        $row = $stmt->fetch() ?: ['map_opt_in_at' => null, 'map_precision' => 'state'];
        return [
            'opted_in'    => $row['map_opt_in_at'] !== null,
            'opted_in_at' => $row['map_opt_in_at'],
            'precision'   => $row['map_precision'],
        ];
    }

    /**
     * Opting in stamps map_opt_in_at (kept if already opted in, so changing
     * the precision doesn't hide the current location); opting out clears it.
     */
    public function saveSettings($accountId, $optIn, $precision) {
        if (!in_array($precision, self::PRECISIONS, true)) {
            throw new InvalidArgumentException('Unknown precision: ' . $precision);
        }
        $stmt = $this->db->prepare(
            "UPDATE accounts
                SET map_precision = ?,
                    map_opt_in_at = IF(?, COALESCE(map_opt_in_at, NOW()), NULL)
              WHERE id = ?"
        );
        return $stmt->execute([$precision, $optIn ? 1 : 0, (int)$accountId]);
    }

    // -- Map ------------------------------------------------------------------

    /**
     * Where an opted-in account appears on the map, or null if it doesn't
     * (opted out, no qualifying update check yet, or not resolvable yet).
     *
     * @return array|null {lat, lon, place, precision, last_seen}
     */
    public function locate($accountId, &$lookupBudget = 3) {
        $stmt = $this->db->prepare(
            "SELECT id, username, status, map_opt_in_at, map_precision FROM accounts WHERE id = ?"
        );
        $stmt->execute([(int)$accountId]);
        $account = $stmt->fetch();
        if (!$account || $account['map_opt_in_at'] === null) {
            return null;
        }
        return $this->locateAccount($account, $lookupBudget);
    }

    /**
     * Every opted-in account with a location, as a GeoJSON FeatureCollection
     * (coordinates are [longitude, latitude], as GeoJSON requires).
     */
    public function featureCollection($lookupBudget = 8) {
        $stmt = $this->db->query(
            "SELECT id, username, status, map_opt_in_at, map_precision
               FROM accounts
              WHERE map_opt_in_at IS NOT NULL AND status = 'active'
              ORDER BY username"
        );

        $features = [];
        $pending = 0;
        foreach ($stmt->fetchAll() as $account) {
            $location = $this->locateAccount($account, $lookupBudget, $wasPending);
            if ($location === null) {
                if ($wasPending) $pending++;
                continue;
            }
            $features[] = [
                'type'       => 'Feature',
                'geometry'   => [
                    'type'        => 'Point',
                    'coordinates' => [$location['lon'], $location['lat']],
                ],
                'properties' => [
                    'username'  => $account['username'],
                    'place'     => $location['place'],
                    'precision' => $location['precision'],
                    'last_seen' => $location['last_seen'],
                ],
            ];
        }

        return [
            'type'         => 'FeatureCollection',
            'generated_at' => gmdate('c'),
            // Opted-in users whose location wasn't resolved within this
            // request's lookup budget; they appear on a later request.
            'pending'      => $pending,
            'features'     => $features,
        ];
    }

    private function locateAccount(array $account, &$lookupBudget, &$wasPending = false) {
        $wasPending = false;
        // The username is published; one that fails the rules (a device
        // account still named after its email) would publish an email address.
        if ($account['status'] !== 'active' || AccountRepository::usernameError($account['username']) !== null) {
            return null;
        }

        $check = $this->latestCheck($account['id'], $account['map_opt_in_at']);
        if ($check === null) {
            return null;
        }

        $ip = $this->placeForIp($check['ip_address'], $lookupBudget);
        if ($ip === false) {
            $wasPending = true;
            return null;
        }
        if ($ip === null || empty($ip['country_code'])) {
            return null;
        }

        // Fall back to coarser precisions when the chosen one can't be resolved.
        $chain = array_slice(self::PRECISIONS, array_search($account['map_precision'], self::PRECISIONS, true));
        foreach ($chain as $precision) {
            $point = $this->pointFor($precision, $ip, $lookupBudget);
            if ($point === false) {
                $wasPending = true;
                return null;
            }
            if ($point !== null) {
                return [
                    'lat'       => round((float)$point['latitude'], 4),
                    'lon'       => round((float)$point['longitude'], 4),
                    'place'     => $point['label'],
                    'precision' => $precision,
                    'last_seen' => substr($check['created_at'], 0, 10),
                ];
            }
        }
        return null;
    }

    /**
     * The newest update check from any of the account's devices since it
     * opted in (and within LOOKBACK_MONTHS). Two queries instead of a join:
     * update_check_logs and account_tokens may not share a collation.
     */
    private function latestCheck($accountId, $optedInAt) {
        $stmt = $this->db->prepare(
            "SELECT device_id FROM account_tokens WHERE account_id = ? AND device_id IS NOT NULL"
        );
        $stmt->execute([(int)$accountId]);
        $devices = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($devices)) {
            return null;
        }

        $in = implode(',', array_fill(0, count($devices), '?'));
        $stmt = $this->db->prepare(
            "SELECT ip_address, created_at
               FROM update_check_logs
              WHERE client_id IN ($in)
                AND created_at >= ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL " . self::LOOKBACK_MONTHS . " MONTH)
                AND ip_address IS NOT NULL AND ip_address <> ''
              ORDER BY created_at DESC
              LIMIT 1"
        );
        $stmt->execute(array_merge($devices, [$optedInAt]));
        return $stmt->fetch() ?: null;
    }

    // -- Step 1: IP -> place names --------------------------------------------

    /** @return array|null|false place row, null if unknown, false if out of lookup budget */
    private function placeForIp($ip, &$lookupBudget) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT country_code, country, state, city, latitude, longitude
               FROM geo_ip_cache
              WHERE ip_address = ? AND looked_up_at >= DATE_SUB(NOW(), INTERVAL " . self::CACHE_DAYS . " DAY)"
        );
        $stmt->execute([$ip]);
        if ($row = $stmt->fetch()) {
            return $row;
        }

        if ($lookupBudget <= 0) {
            return false;
        }
        $lookupBudget--;

        $geo = json_decode((string)$this->httpGet($this->geoipUrl . urlencode($ip)), true);
        if (!is_array($geo)) {
            return null;   // service down: don't cache, try again next time
        }
        $row = [
            'country_code' => $geo['country']['iso_code'] ?? null,
            'country'      => $geo['country']['names']['en'] ?? null,
            'state'        => $geo['subdivisions'][0]['names']['en'] ?? null,
            'city'         => $geo['city']['names']['en'] ?? null,
            'latitude'     => $geo['location']['latitude'] ?? null,
            'longitude'    => $geo['location']['longitude'] ?? null,
        ];
        $this->db->prepare(
            "REPLACE INTO geo_ip_cache (ip_address, country_code, country, state, city, latitude, longitude)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$ip, $row['country_code'], $row['country'], $row['state'], $row['city'], $row['latitude'], $row['longitude']]);
        return $row;
    }

    // -- Step 2: place -> published point -------------------------------------

    /** @return array|null|false {label, latitude, longitude}, null if unresolvable, false if out of budget */
    private function pointFor($precision, array $ip, &$lookupBudget) {
        if ($precision === 'city') {
            // Local data, nothing to cache. No city in the IP record means its
            // coordinates are only a country/region center, too rough to use.
            if (empty($ip['city']) || $ip['latitude'] === null) return null;
            return $this->nearestLargeCity((float)$ip['latitude'], (float)$ip['longitude'], $ip['country_code'], $ip['country']);
        }
        if ($precision === 'state') {
            if (empty($ip['state'])) return null;
            $key = implode('|', ['state', $ip['country_code'], $ip['state']]);
        } else {
            $key = implode('|', ['country', $ip['country_code']]);
        }

        $hash = sha1($key);
        $stmt = $this->db->prepare(
            "SELECT label, latitude, longitude
               FROM geo_place_cache
              WHERE place_hash = ? AND looked_up_at >= DATE_SUB(NOW(), INTERVAL " . self::CACHE_DAYS . " DAY)"
        );
        $stmt->execute([$hash]);
        if ($row = $stmt->fetch()) {
            return $row['latitude'] === null ? null : $row;
        }

        if ($lookupBudget <= 0) {
            return false;
        }
        $lookupBudget--;

        if ($precision === 'state') {
            $point = $this->nominatim(['state' => $ip['state'], 'country' => $ip['country_code'], 'featureType' => 'state'],
                $ip['state'] . ', ' . $ip['country']);
        } else {
            $point = $this->nominatim(['country' => $ip['country_code'], 'featureType' => 'country'], $ip['country']);
        }
        if ($point === false) {
            return null;   // request failed: don't cache, try again next time
        }

        $this->db->prepare(
            "REPLACE INTO geo_place_cache (place_hash, place_key, label, latitude, longitude) VALUES (?, ?, ?, ?, ?)"
        )->execute([$hash, $key, $point['label'] ?? null, $point['latitude'] ?? null, $point['longitude'] ?? null]);
        return $point;
    }

    /** @return array|null|false point, null if nothing found, false if the request failed */
    private function nominatim(array $query, $label) {
        // Usage policy: at most one request per second.
        $wait = 1.0 - (microtime(true) - $this->lastNominatimCall);
        if ($wait > 0) usleep((int)($wait * 1000000));
        $this->lastNominatimCall = microtime(true);

        $body = $this->httpGet(self::NOMINATIM_URL . '?' . http_build_query($query + ['format' => 'jsonv2', 'limit' => 1]));
        $results = json_decode((string)$body, true);
        if (!is_array($results)) {
            return false;
        }
        if (empty($results[0]['lat'])) {
            return null;
        }
        return ['label' => $label, 'latitude' => (float)$results[0]['lat'], 'longitude' => (float)$results[0]['lon']];
    }

    /**
     * The large city (100,000+ people, includes/large-cities.tsv from
     * GeoNames) a user near the IP's coordinates is shown at, so someone in
     * a small town appears at the big city near them, not at their town:
     * the most populous one within METRO_KM (the metro they're in, rather
     * than a suburb or borough of it), else the nearest within
     * CITY_RADIUS_KM. Only cities in the IP's own country count. Null if
     * there's none (the caller falls back to the state).
     */
    private function nearestLargeCity($lat, $lon, $countryCode, $countryName) {
        $metro = null;
        $nearest = null;
        $nearestDistance = self::CITY_RADIUS_KM;
        foreach (self::largeCities() as $city) {
            if ($city[1] !== $countryCode) continue;
            $distance = self::distanceKm($lat, $lon, $city[2], $city[3]);
            if ($distance < self::METRO_KM && ($metro === null || $city[4] > $metro[4])) {
                $metro = $city;
            }
            if ($distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearest = $city;
            }
        }
        $best = $metro ?? $nearest;
        if ($best === null) {
            return null;
        }
        return ['label' => $best[0] . ', ' . ($countryName ?: $countryCode), 'latitude' => $best[2], 'longitude' => $best[3]];
    }

    /** @return array<array{0: string, 1: string, 2: float, 3: float, 4: int}> name, country code, lat, lon, population */
    private static function largeCities() {
        static $cities = null;
        if ($cities === null) {
            $cities = [];
            foreach (file(__DIR__ . '/large-cities.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if ($line[0] === '#') continue;
                $f = explode("\t", $line);
                $cities[] = [$f[0], $f[1], (float)$f[2], (float)$f[3], (int)$f[4]];
            }
        }
        return $cities;
    }

    private static function distanceKm($lat1, $lon1, $lat2, $lon2) {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Returns the body, or null on failure. */
    private function httpGet($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ($body !== false && $status >= 200 && $status < 300) ? $body : null;
    }
}
