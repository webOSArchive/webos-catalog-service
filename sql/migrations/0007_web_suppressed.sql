-- 0007: 'web_suppressed' flag on app_metadata — hides an app from the public
-- web UI (category listings, search, author pages, the /app/<title> slug
-- redirect, and numeric Museum ID lookup) while leaving the JSON API
-- (webOS/LuneOS clients, WebService/*) completely unaffected — those always
-- see the app. On the web it's still reachable via an exact
-- showMuseumDetails.php?appid=<publicApplicationId> match, for sharing a
-- direct link without listing it publicly. Web-side category/search counts
-- are intentionally unaffected too.
--
-- Apply:  mysql -u <user> -p <db> < sql/migrations/0007_web_suppressed.sql

ALTER TABLE app_metadata
  ADD COLUMN web_suppressed TINYINT(1) NOT NULL DEFAULT 0 AFTER adult_rating;
