-- 0008: Backfill download_logs.app_id for rows logged with a package
-- Application ID (e.g. "com.palm.app.foo") instead of the numeric Museum ID.
-- Before this, LogRepository::logDownload() only set app_id for numeric
-- identifiers, so every on-device download landed in one NULL bucket that
-- showed up in admin "Top Downloads" as a blank "ID:" row linking nowhere.
-- The logger now resolves these at write time; this repairs existing rows.
--
-- Apply:  mysql -u <user> -p <db> < sql/migrations/0008_backfill_download_log_app_id.sql

UPDATE download_logs dl
  JOIN app_metadata m ON LOWER(m.public_application_id) = LOWER(dl.app_identifier)
   SET dl.app_id = m.app_id
 WHERE dl.app_id IS NULL
   AND dl.app_identifier IS NOT NULL
   AND dl.app_identifier <> '';
