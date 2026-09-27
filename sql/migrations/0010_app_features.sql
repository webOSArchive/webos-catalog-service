-- 0010: App feature flags for the App Catalog "Find More…" searches.
--
-- Several webOS apps open App Catalog on a filtered search with no text:
-- Exhibition preferences (dockMode), Just Type preferences (universalSearch)
-- and Accounts "Find More…" (connector/<CAPABILITY>, e.g. connector/CONTACTS).
-- Until now the only place this data lived was the free-form
-- app_metadata.attributes JSON blob imported from HP's catalog, which
-- getMuseumMaster.php could not filter on and which admin/metadata-edit.php
-- overwrote with NULL on every save (MetadataRepository::upsert() wrote
-- attributes = NULL when the form did not carry it; it now keeps the stored
-- value instead).
--
-- This adds three curator-editable columns and backfills them from the JSON:
--   dock_mode        <- attributes.provides.dockMode
--   universal_search <- attributes.provides.universalSearch
--   connectors       <- the "connector/<CAP>" entries of attributes.provides.services,
--                       stored as a comma-separated upper-case list ("CALENDAR,CONTACTS").
--                       (HP's data put them under `services`; `connectors` was always
--                       ["null"] or [] and is ignored here.)
--
-- getMuseumDetails.php now composes attributes.provides.{dockMode,universalSearch,
-- connectors} from these columns, so the JSON blob no longer needs to be right.
--
-- Verify after applying:
--   SELECT COUNT(*) FROM app_metadata WHERE dock_mode = 1;          -- ~135 on the 2026-09-27 backup
--   SELECT COUNT(*) FROM app_metadata WHERE universal_search = 1;   -- ~264
--   SELECT app_id, connectors FROM app_metadata WHERE connectors <> '';  -- 15 rows, e.g. 141 -> CALENDAR,CONTACTS
--
-- Preview the backfill before applying:
--   SELECT COUNT(*) FROM app_metadata WHERE JSON_EXTRACT(attributes, '$.provides.dockMode') = 'true';
--   SELECT app_id, JSON_EXTRACT(attributes, '$.provides.services') FROM app_metadata
--    WHERE JSON_EXTRACT(attributes, '$.provides.services') LIKE '%connector%';
--
-- Apply:  mysql -u <user> -p <db> < sql/migrations/0010_app_features.sql

ALTER TABLE app_metadata
  ADD COLUMN dock_mode        TINYINT(1)   NOT NULL DEFAULT 0  AFTER is_location_based,
  ADD COLUMN universal_search TINYINT(1)   NOT NULL DEFAULT 0  AFTER dock_mode,
  ADD COLUMN connectors       VARCHAR(255) NOT NULL DEFAULT '' AFTER universal_search;

UPDATE app_metadata
   SET dock_mode        = COALESCE(JSON_EXTRACT(attributes, '$.provides.dockMode') = 'true', 0),
       universal_search = COALESCE(JSON_EXTRACT(attributes, '$.provides.universalSearch') = 'true', 0)
 WHERE attributes IS NOT NULL
   AND JSON_VALID(attributes)
   AND JSON_TYPE(JSON_EXTRACT(attributes, '$.provides')) = 'OBJECT';

-- services is a JSON array such as ["connector\/CALENDAR","connector\/CONTACTS","com.x.service"].
-- Step 1 keeps the connector/ entries (unquoted) and drops every other entry;
-- step 2 strips everything that is not part of an upper-case capability name
-- or a separator; step 3 collapses the commas left by dropped entries.
UPDATE app_metadata
   SET connectors = TRIM(BOTH ',' FROM REGEXP_REPLACE(
                      REGEXP_REPLACE(
                        REGEXP_REPLACE(
                          REPLACE(JSON_EXTRACT(attributes, '$.provides.services'), '\\/', '/'),
                          '"(connector/[^"]*)"|"[^"]*"', '\\1'),
                        '[^A-Z0-9.,_-]', ''),
                      ',+', ','))
 WHERE attributes IS NOT NULL
   AND JSON_VALID(attributes)
   AND REPLACE(JSON_EXTRACT(attributes, '$.provides.services'), '\\/', '/') LIKE '%"connector/%';
