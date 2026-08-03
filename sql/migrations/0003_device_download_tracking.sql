-- 0003: device/account attribution for downloads + one-token-per-device.
--
-- Q1 "how many devices does an account have?"
--     SELECT COUNT(*) FROM account_tokens WHERE account_id = ? AND device_id IS NOT NULL;
-- Q2 "which apps has a device acquired?" (restore-style lookup)
--     SELECT DISTINCT app_identifier FROM download_logs WHERE device_id = ?;

-- download_logs: optional attribution, filled by device clients (nduid) once they
-- start sending it; account_id is resolved server-side from account_tokens at log
-- time (captures who owned the device when the download happened). Old clients
-- keep working — both columns stay NULL for them.
ALTER TABLE download_logs
  ADD COLUMN device_id  VARCHAR(128)    NULL DEFAULT NULL AFTER user_agent,
  ADD COLUMN account_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER device_id,
  ADD INDEX idx_device_id (device_id),
  ADD INDEX idx_account_id (account_id);

-- account_tokens: ONE row per physical device — a re-register/re-sign-in
-- overwrites that device's row (new token/account/expiry) instead of piling up
-- dead rows. The device only ever holds its latest token anyway, so the old rows
-- were unusable. Dedupe first (keep the newest row per device), then enforce.
-- NULL device_id rows (unknown device) are unaffected: MySQL unique indexes
-- allow any number of NULLs.
DELETE t1 FROM account_tokens t1
  JOIN account_tokens t2
    ON t1.device_id = t2.device_id AND t1.id < t2.id
 WHERE t1.device_id IS NOT NULL;

ALTER TABLE account_tokens
  DROP INDEX idx_tokens_device,
  ADD UNIQUE KEY uq_tokens_device (device_id);
