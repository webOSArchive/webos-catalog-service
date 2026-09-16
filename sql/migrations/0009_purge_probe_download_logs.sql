-- 0009: Remove download_logs rows that don't belong to any catalog app.
--
-- Run AFTER 0008 (which re-attaches device downloads to their apps by package
-- ID). Whatever is still app_id IS NULL afterwards never named a catalog app:
-- in practice vulnerability scanners hitting countAppDownload.php?appid=
-- .env.local / credentials / database.yml~ looking for secrets. They were
-- outranking real apps in admin "Top Downloads". countAppDownload.php now
-- refuses to log anything that doesn't resolve to a catalog app, so these
-- can't come back.
--
-- Preview what will go (if a real package ID shows up here, that app is
-- missing its public_application_id in app_metadata — fix that, re-run 0008,
-- then purge):
--   SELECT app_identifier, COUNT(*) AS n FROM download_logs
--    WHERE app_id IS NULL GROUP BY app_identifier ORDER BY n DESC;
--
-- Apply:  sudo mysql <db> < sql/migrations/0009_purge_probe_download_logs.sql

DELETE FROM download_logs WHERE app_id IS NULL;
