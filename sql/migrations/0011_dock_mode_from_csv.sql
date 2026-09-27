-- 0011: Set dock_mode for the few apps on dockModeApps.csv (the curated list of
-- Exhibition-capable apps at the repo root) that migration 0010 could not
-- backfill. Of its 90 apps, 86 already carried dockMode in the imported
-- attributes JSON. The other four:
--   10224   AccuWeather for HP TouchPad  attributes NULL (wiped by the old metadata editor)
--   10842   IAmA Reddit                  attributes NULL (same)
--   10148   SimpleClock                  attributes NULL (same)
--   1005776 Apollo (Pandora Radio Client) HP JSON said false; the community build on the list has dock mode
--
-- Apply:  mysql -u <user> -p <db> < sql/migrations/0011_dock_mode_from_csv.sql

UPDATE app_metadata SET dock_mode = 1 WHERE app_id IN (10224, 10842, 10148, 1005776);
