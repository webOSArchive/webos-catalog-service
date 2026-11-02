-- 0014: app_metadata.last_historical_update, the app's last submission to
-- HP/Palm.
--
-- last_modified_time has become the date of our own fixes and patches, and
-- those edits overwrote the catalog's record of when a developer last
-- submitted the app. This column keeps that record. Nothing in the admin UI
-- can change it: MetadataRepository::upsert() sets it only when an app's
-- metadata row is first created, so an app that is new to the catalog takes
-- its own date. last_modified_time keeps its current values and still drives
-- the "recent" sort.
--
-- Times are UTC, like last_modified_time, and timezones in the source data are
-- only approximate, so treat this as a date.
--
-- Source: the earliest committed value of lastModifiedTime in each
-- AppMetadata/<id>.json (git history from 2021-06-08), compared with all rows.
--
--   - Unchanged apps: copied.
--   - Community apps (id 1005757 and up): the earliest date we have for the app,
--     from the archive, its current date, or when it was added here. Our record
--     is the historical one for these, and an earliest date stays fixed as the
--     app is updated. ScummVM 2 takes the 2012 date of the earlier package
--     listed under its id.
--   - NULL means no historical date is known:
--       * 212 apps whose date is 2014-09-06 12:00:00. That was never a catalog
--         date; it is the default a January 2026 backfill gave apps with no
--         date in their JSON, so that they would sort.
--       * 11 apps overwritten with no archived value to go back to.
--     IPK timestamps are too far from the catalog's dates to fill these in.
--   - 48 apps recovered from the archive (see below).
--
-- Safe to run more than once. updated_at is held still because no app changed.

ALTER TABLE app_metadata
    ADD COLUMN IF NOT EXISTS last_historical_update DATETIME NULL DEFAULT NULL AFTER last_modified_time;

UPDATE app_metadata
SET last_historical_update = last_modified_time, updated_at = updated_at
WHERE last_historical_update IS NULL;

-- No historical date known
UPDATE app_metadata
SET last_historical_update = NULL, updated_at = updated_at
WHERE app_id IN (
    17, 32, 40, 42, 47, 70, 160, 199, 201, 247, 262, 282,
    321, 323, 352, 354, 462, 582, 595, 857, 1002, 1102, 1228, 1322,
    1385, 1406, 1529, 1733, 1739, 1746, 1752, 1879, 1911, 1922, 2077, 2078,
    2116, 2169, 2312, 2320, 2351, 2361, 2365, 2367, 2376, 2414, 2416, 2420,
    2472, 2474, 2529, 2531, 2681, 2812, 2815, 2818, 2850, 2899, 2933, 3003,
    3007, 3045, 3053, 3117, 3310, 3774, 3969, 4282, 4640, 4794, 4798, 4951,
    5067, 5077, 5136, 5177, 5213, 5226, 5241, 5244, 5269, 5271, 5275, 5283,
    5289, 5653, 5834, 6073, 6231, 6339, 6472, 6594, 6759, 6820, 6825, 7035,
    7567, 7668, 7677, 7813, 8300, 8316, 8413, 8447, 8493, 8502, 8709, 8846,
    8871, 8905, 8966, 8967, 8986, 9073, 9089, 9099, 9100, 9110, 9146, 9219,
    9235, 9258, 9264, 9274, 9307, 9333, 9424, 9428, 9437, 9439, 9452, 9500,
    9513, 9604, 9605, 9607, 9648, 9690, 9701, 9745, 9764, 9793, 9826, 9838,
    9876, 9887, 9904, 9905, 9942, 9954, 10054, 10083, 10085, 10090, 10121, 10144,
    10158, 10160, 10166, 10178, 10193, 10194, 10227, 10228, 10271, 10282, 10306, 10324,
    10339, 10393, 10397, 10432, 10464, 10498, 10501, 10503, 10540, 10586, 10612, 10621,
    10626, 10629, 10635, 10712, 10722, 10760, 10793, 10795, 10814, 10827, 10889, 10942,
    10943, 11003, 1000049, 1000075, 1000082, 1000100, 1000107, 1000133, 1000206, 1000350, 1000354, 1000371,
    1000407, 1000428, 1000466, 1000473, 1000501, 1000511, 1000559, 1000581, 1000636, 1000692, 1000704, 1000724,
    1000754, 1000799, 1000920, 1000962, 1005643, 1005761, 1005773);

-- Recovered from the archive
UPDATE app_metadata SET last_historical_update = '2009-11-24 18:24:06', updated_at = updated_at WHERE app_id = 457; -- AccuWeather Premium (seconds only)
UPDATE app_metadata SET last_historical_update = '2011-10-20 09:15:19', updated_at = updated_at WHERE app_id = 1067; -- BibleZ
UPDATE app_metadata SET last_historical_update = '2010-08-02 19:28:52', updated_at = updated_at WHERE app_id = 1101; -- MONOPOLY
UPDATE app_metadata SET last_historical_update = '2012-06-01 01:32:10', updated_at = updated_at WHERE app_id = 1112; -- Glyder 2
UPDATE app_metadata SET last_historical_update = '2010-04-15 01:19:41', updated_at = updated_at WHERE app_id = 1335; -- NFL 2010 (seconds only)
UPDATE app_metadata SET last_historical_update = '2010-01-28 21:53:52', updated_at = updated_at WHERE app_id = 1413; -- Brothers In Arms®: Hour of Heroes (seconds only)
UPDATE app_metadata SET last_historical_update = '2010-01-28 00:24:14', updated_at = updated_at WHERE app_id = 1414; -- Apollo (seconds only)
UPDATE app_metadata SET last_historical_update = '2010-03-30 05:20:08', updated_at = updated_at WHERE app_id = 1445; -- Slacker Radio
UPDATE app_metadata SET last_historical_update = '2012-06-01 04:06:38', updated_at = updated_at WHERE app_id = 1523; -- Slot Machine (seconds only)
UPDATE app_metadata SET last_historical_update = '2010-05-12 17:24:11', updated_at = updated_at WHERE app_id = 1819; -- Clock Sync
UPDATE app_metadata SET last_historical_update = '2012-06-01 15:24:47', updated_at = updated_at WHERE app_id = 2046; -- drPodder
UPDATE app_metadata SET last_historical_update = '2012-06-01 00:31:35', updated_at = updated_at WHERE app_id = 2149; -- Super KO Boxing! 2
UPDATE app_metadata SET last_historical_update = '2012-06-01 01:28:50', updated_at = updated_at WHERE app_id = 2638; -- Mini Golf Wacky Worlds (seconds only)
UPDATE app_metadata SET last_historical_update = '2010-04-27 21:36:02', updated_at = updated_at WHERE app_id = 2744; -- Driver (seconds only)
UPDATE app_metadata SET last_historical_update = '2010-05-18 21:02:48', updated_at = updated_at WHERE app_id = 2980; -- Blades of Fury (seconds only)
UPDATE app_metadata SET last_historical_update = '2012-06-02 03:29:17', updated_at = updated_at WHERE app_id = 3038; -- AuctionMate Pro for eBay
UPDATE app_metadata SET last_historical_update = '2010-06-04 21:29:35', updated_at = updated_at WHERE app_id = 3190; -- Tiger Woods PGA Tour®
UPDATE app_metadata SET last_historical_update = '2011-05-09 18:51:01', updated_at = updated_at WHERE app_id = 3268; -- Towers of Hanoi
UPDATE app_metadata SET last_historical_update = '2012-06-01 04:51:14', updated_at = updated_at WHERE app_id = 4240; -- ExZeus arcade
UPDATE app_metadata SET last_historical_update = '2010-09-14 23:25:57', updated_at = updated_at WHERE app_id = 5235; -- SuperTux (seconds only)
UPDATE app_metadata SET last_historical_update = '2010-10-04 14:52:18', updated_at = updated_at WHERE app_id = 5498; -- Katy Perry Bouncing (seconds only)
UPDATE app_metadata SET last_historical_update = '2012-06-01 11:01:11', updated_at = updated_at WHERE app_id = 6271; -- Daily Devo Pro
UPDATE app_metadata SET last_historical_update = '2012-06-01 16:46:38', updated_at = updated_at WHERE app_id = 7715; -- Missile Defender (seconds only)
UPDATE app_metadata SET last_historical_update = '2011-03-03 21:17:09', updated_at = updated_at WHERE app_id = 7752; -- Missile Defender Lite (seconds only)
UPDATE app_metadata SET last_historical_update = '2012-06-15 17:52:51', updated_at = updated_at WHERE app_id = 8214; -- Snes9x EX (seconds only)
UPDATE app_metadata SET last_historical_update = '2012-02-14 23:14:19', updated_at = updated_at WHERE app_id = 9192; -- iHeartRadio
UPDATE app_metadata SET last_historical_update = '2011-09-01 22:52:24', updated_at = updated_at WHERE app_id = 9216; -- Amazon Kindle Beta (seconds only)
UPDATE app_metadata SET last_historical_update = '2011-07-19 08:13:44', updated_at = updated_at WHERE app_id = 9288; -- Box for TouchPad
UPDATE app_metadata SET last_historical_update = '2011-10-25 20:56:02', updated_at = updated_at WHERE app_id = 9316; -- PressReader (seconds only)
UPDATE app_metadata SET last_historical_update = '2012-10-04 20:32:07', updated_at = updated_at WHERE app_id = 9331; -- World Today
UPDATE app_metadata SET last_historical_update = '2012-06-02 00:41:54', updated_at = updated_at WHERE app_id = 9834; -- Azada HD (seconds only)
UPDATE app_metadata SET last_historical_update = '2012-06-02 01:03:25', updated_at = updated_at WHERE app_id = 9877; -- Internet Radio HD Pro (seconds only)
UPDATE app_metadata SET last_historical_update = '2012-04-16 19:29:00', updated_at = updated_at WHERE app_id = 9880; -- BibleZ HD
UPDATE app_metadata SET last_historical_update = '2011-11-09 06:10:53', updated_at = updated_at WHERE app_id = 10056; -- Dailymotion for Touchpad
UPDATE app_metadata SET last_historical_update = '2011-10-14 08:21:53', updated_at = updated_at WHERE app_id = 10062; -- MLB At Bat 11
UPDATE app_metadata SET last_historical_update = '2013-04-13 01:31:47', updated_at = updated_at WHERE app_id = 10148; -- SimpleClock (seconds only)
UPDATE app_metadata SET last_historical_update = '2013-01-09 21:29:07', updated_at = updated_at WHERE app_id = 10224; -- AccuWeather for HP TouchPad
UPDATE app_metadata SET last_historical_update = '2011-11-16 00:15:18', updated_at = updated_at WHERE app_id = 10348; -- Maps
UPDATE app_metadata SET last_historical_update = '2012-06-02 06:24:02', updated_at = updated_at WHERE app_id = 10842; -- IAmA Reddit
UPDATE app_metadata SET last_historical_update = '2012-06-02 08:48:45', updated_at = updated_at WHERE app_id = 10866; -- Remote Desktop Client (seconds only)
UPDATE app_metadata SET last_historical_update = '2012-10-09 23:40:50', updated_at = updated_at WHERE app_id = 10898; -- ReadOnTouch PRO
UPDATE app_metadata SET last_historical_update = '2012-06-02 05:19:01', updated_at = updated_at WHERE app_id = 10926; -- Green Eggs and Ham - Dr. Seuss
UPDATE app_metadata SET last_historical_update = '2012-08-02 16:37:04', updated_at = updated_at WHERE app_id = 10930; -- The Cat in the Hat - Dr. Seuss
UPDATE app_metadata SET last_historical_update = '2012-06-02 08:21:43', updated_at = updated_at WHERE app_id = 1000475; -- Sound Cloud Player
UPDATE app_metadata SET last_historical_update = '2013-04-25 02:46:48', updated_at = updated_at WHERE app_id = 1000505; -- WebDAV Client HD
UPDATE app_metadata SET last_historical_update = '2012-06-20 17:19:29', updated_at = updated_at WHERE app_id = 1000777; -- Apollo (Pandora Radio Client)
UPDATE app_metadata SET last_historical_update = '2012-10-17 22:41:32', updated_at = updated_at WHERE app_id = 1000996; -- Plex for webOS
UPDATE app_metadata SET last_historical_update = '2011-11-09 02:45:48', updated_at = updated_at WHERE app_id = 1005795; -- PulseAudio Restarter (Patched)

-- Community apps: earliest date we have
UPDATE app_metadata SET last_historical_update = '2020-05-28 17:04:48', updated_at = updated_at WHERE app_id = 1005757; -- FeedSpider 2
UPDATE app_metadata SET last_historical_update = '2020-10-22 17:04:48', updated_at = updated_at WHERE app_id = 1005758; -- AccuWeather Premium (Patched)
UPDATE app_metadata SET last_historical_update = '2012-01-31 20:26:47', updated_at = updated_at WHERE app_id = 1005763; -- ScummVM 2
UPDATE app_metadata SET last_historical_update = '2020-10-22 17:04:48', updated_at = updated_at WHERE app_id = 1005768; -- webOS Archive Proxy
UPDATE app_metadata SET last_historical_update = '2021-04-26 17:04:48', updated_at = updated_at WHERE app_id = 1005771; -- One Night Stand
UPDATE app_metadata SET last_historical_update = '2021-03-31 19:04:48', updated_at = updated_at WHERE app_id = 1005774; -- MeTube
UPDATE app_metadata SET last_historical_update = '2021-04-21 21:04:48', updated_at = updated_at WHERE app_id = 1005777; -- webOS SimpleChat
UPDATE app_metadata SET last_historical_update = '2021-03-31 17:04:48', updated_at = updated_at WHERE app_id = 1005778; -- Podcast Directory
UPDATE app_metadata SET last_historical_update = '2021-04-26 17:04:48', updated_at = updated_at WHERE app_id = 1005779; -- Check Mate
UPDATE app_metadata SET last_historical_update = '2021-03-15 17:04:48', updated_at = updated_at WHERE app_id = 1005782; -- URL Assist
UPDATE app_metadata SET last_historical_update = '2021-03-18 17:04:48', updated_at = updated_at WHERE app_id = 1005783; -- Messaging Account Bypass
UPDATE app_metadata SET last_historical_update = '2020-10-22 17:04:48', updated_at = updated_at WHERE app_id = 1005786; -- NewsZ
UPDATE app_metadata SET last_historical_update = '2021-05-29 17:04:48', updated_at = updated_at WHERE app_id = 1005788; -- Share Space
UPDATE app_metadata SET last_historical_update = '2021-12-22 17:04:48', updated_at = updated_at WHERE app_id = 1005796; -- Map Lite
UPDATE app_metadata SET last_historical_update = '2022-01-13 17:04:48', updated_at = updated_at WHERE app_id = 1005797; -- Webb Telescope Tracker
UPDATE app_metadata SET last_historical_update = '2022-02-07 17:04:48', updated_at = updated_at WHERE app_id = 1005798; -- webOS Stopwatch
UPDATE app_metadata SET last_historical_update = '2022-03-04 17:04:48', updated_at = updated_at WHERE app_id = 1005799; -- Check Mate HD
UPDATE app_metadata SET last_historical_update = '2026-01-18 00:17:31', updated_at = updated_at WHERE app_id = 1005819; -- ACL Manager
UPDATE app_metadata SET last_historical_update = '2026-01-24 18:18:01', updated_at = updated_at WHERE app_id = 1005822; -- Papyrus eReader
UPDATE app_metadata SET last_historical_update = '2026-02-17 14:23:11', updated_at = updated_at WHERE app_id = 1005823; -- EMU7800
UPDATE app_metadata SET last_historical_update = '2026-04-27 18:06:59', updated_at = updated_at WHERE app_id = 1005824; -- Claude Chat
UPDATE app_metadata SET last_historical_update = '2026-04-28 00:38:16', updated_at = updated_at WHERE app_id = 1005825; -- Commander Keen
UPDATE app_metadata SET last_historical_update = '2026-05-09 17:52:19', updated_at = updated_at WHERE app_id = 1005826; -- webCal Sync
UPDATE app_metadata SET last_historical_update = '2026-05-11 14:16:41', updated_at = updated_at WHERE app_id = 1005827; -- PWA Installer
UPDATE app_metadata SET last_historical_update = '2026-06-28 16:27:17', updated_at = updated_at WHERE app_id = 1005831; -- Where's My Water
UPDATE app_metadata SET last_historical_update = '2026-07-03 17:17:04', updated_at = updated_at WHERE app_id = 1005833; -- OTA Ready
UPDATE app_metadata SET last_historical_update = '2026-07-13 13:20:32', updated_at = updated_at WHERE app_id = 1005834; -- Jukie
UPDATE app_metadata SET last_historical_update = '2026-07-23 20:36:56', updated_at = updated_at WHERE app_id = 1005837; -- Trapper Keeper
UPDATE app_metadata SET last_historical_update = '2026-07-26 19:29:44', updated_at = updated_at WHERE app_id = 1005838; -- webOS Second Screen
UPDATE app_metadata SET last_historical_update = '2026-08-03 16:00:45', updated_at = updated_at WHERE app_id = 1005841; -- webOS Community Account Manager
UPDATE app_metadata SET last_historical_update = '2026-08-26 21:40:01', updated_at = updated_at WHERE app_id = 1005844; -- Plants vs Zombies
UPDATE app_metadata SET last_historical_update = '2026-08-26 22:52:30', updated_at = updated_at WHERE app_id = 1005845; -- Amazing Alex
UPDATE app_metadata SET last_historical_update = '2026-08-27 20:02:17', updated_at = updated_at WHERE app_id = 1005846; -- Temple Run 2
UPDATE app_metadata SET last_historical_update = '2026-08-28 14:25:51', updated_at = updated_at WHERE app_id = 1005847; -- Fieldrunners
UPDATE app_metadata SET last_historical_update = '2026-09-09 05:12:26', updated_at = updated_at WHERE app_id = 1005851; -- Cut The Rope HD
UPDATE app_metadata SET last_historical_update = '2026-09-15 22:01:53', updated_at = updated_at WHERE app_id = 1005854; -- Dead Space
UPDATE app_metadata SET last_historical_update = '2026-09-16 14:36:30', updated_at = updated_at WHERE app_id = 1005855; -- Tiny Death Star
UPDATE app_metadata SET last_historical_update = '2026-09-23 12:37:11', updated_at = updated_at WHERE app_id = 1005857; -- Quran Reader
UPDATE app_metadata SET last_historical_update = '2026-10-07 17:16:27', updated_at = updated_at WHERE app_id = 1005859; -- Jellyfin
UPDATE app_metadata SET last_historical_update = '2026-10-11 19:27:38', updated_at = updated_at WHERE app_id = 1005860; -- Topaz Explorer
