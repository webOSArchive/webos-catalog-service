-- 0012: webOS Chat pass phrases.
--
-- webOS Chat (the OSCAR/AIM server at oscar.webosarchive.org, in the Oscar
-- repository) signs people in with their account username and a chat-only
-- pass phrase: three short words from includes/chat-words.txt, made by
-- AccountRepository::newChatKey() on My Account or by device.php's
-- getChatCredentials (webOS CE and Lunacy set themselves up with it).
--
-- Stored as is, not hashed: AIM's sign-in only needs MD5(phrase), so a hash
-- would sign in just as well and protect nothing, and My Account shows the
-- phrase again. It is never the account password: stock devices sign in over
-- plain TCP. NULL until the account first asks for one.
--
-- A username that fails AccountRepository::usernameError() (a device account
-- still named after its email) can't chat; the OSCAR server only finds
-- accounts by username.

ALTER TABLE accounts
    ADD COLUMN chat_key VARCHAR(64) NULL DEFAULT NULL AFTER password_hash;

-- The OSCAR server's read-only database user, allowed only what a sign-in
-- reads (the server's config.ini: db_user / db_pass). Run by hand, with a
-- real password and the database's own name:
--
--   CREATE USER 'oscar'@'localhost' IDENTIFIED BY '...';
--   GRANT SELECT (username, chat_key, status) ON <database>.accounts TO 'oscar'@'localhost';
--
-- Verify after applying:
--   SHOW COLUMNS FROM accounts LIKE 'chat_key';
--   SELECT COUNT(*) FROM accounts WHERE username REGEXP '^[0-9]';   -- 0: usernames now start with a letter
