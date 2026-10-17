-- 0013: opt-in webOS user map.
--
-- A user who opts in on My Account (admin/account.php) shows up in
-- WebService/getUserMap.php, a GeoJSON feed for a community map of webOS
-- users. Their location comes from the most recent update check made by any
-- of their signed-in devices: the shared updaters send the device nduid as
-- `clientid` (update_check_logs.client_id), which matches
-- account_tokens.device_id. Only checks made after map_opt_in_at count, so
-- turning it on never publishes where someone was before they agreed.
--
-- map_opt_in_at  NULL = not on the map (the default). Set when the user opts
--                in, cleared when they opt out; changing the precision while
--                opted in keeps it.
-- map_precision  how close the published point is:
--                  city    - the nearest city of 100,000+ people to where
--                            the IP is, not the IP's own town
--                  state   - the center of their state/province
--                  country - the center of their country
--                The point is always a named place's center, never the
--                IP-derived coordinates themselves.

ALTER TABLE accounts
    ADD COLUMN map_opt_in_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN map_precision ENUM('city', 'state', 'country') NOT NULL DEFAULT 'state';

-- IP -> place names from the geo service (MaxMind-shaped JSON), so a map
-- request doesn't re-query it for every user every time. Rows older than 30
-- days are refreshed on use. No account link: just what an IP resolves to.
CREATE TABLE geo_ip_cache (
    ip_address   VARCHAR(45)  NOT NULL PRIMARY KEY,
    country_code CHAR(2)      NULL,
    country      VARCHAR(128) NULL,
    state        VARCHAR(128) NULL,
    city         VARCHAR(128) NULL,
    latitude     DECIMAL(9,6) NULL,   -- server-side only: the origin for the nearest-city lookup
    longitude    DECIMAL(9,6) NULL,
    looked_up_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- State/country -> its center, from OpenStreetMap's Nominatim. (Cities need
-- no cache: the nearest large city comes from includes/large-cities.tsv.)
-- Keyed by precision and the place names, e.g. "state|US|Kansas" (SHA-1'd into
-- place_hash, since utf8mb4 keys that long don't fit an index). A row with
-- NULL coordinates records a lookup that found nothing, so it isn't retried
-- on every request (it is retried once the row is 30 days old).
CREATE TABLE geo_place_cache (
    place_hash   CHAR(40)     NOT NULL PRIMARY KEY,
    place_key    VARCHAR(400) NOT NULL,
    label        VARCHAR(255) NULL,
    latitude     DECIMAL(9,6) NULL,
    longitude    DECIMAL(9,6) NULL,
    looked_up_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verify after applying:
--   SHOW COLUMNS FROM accounts LIKE 'map_%';
--   SHOW TABLES LIKE 'geo_%';
