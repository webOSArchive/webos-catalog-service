-- 0015: fill app_metadata.last_historical_update from last_modified_time where
-- it is NULL and last_modified_time is in 2018 or earlier.
--
-- 0014 left these rows NULL, including the 212 apps dated 2014-09-06 12:00:00,
-- so they showed as Unknown despite having a date.
--
-- Rows with no last_modified_time stay NULL, as do rows dated 2019 or later.
-- Safe to run more than once. updated_at is held still because no app changed.
--
-- Apply:  mysql -u <user> -p <db> < sql/migrations/0015_fill_historical_update.sql

UPDATE app_metadata
SET last_historical_update = last_modified_time, updated_at = updated_at
WHERE last_historical_update IS NULL
  AND last_modified_time < '2019-01-01';
