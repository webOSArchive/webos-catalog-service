-- 0005: descriptive device metadata, so the on-device Accounts app can show a
-- readable DEVICES list instead of raw nduids.
--
-- The palmprofile service already posts a full device block on every sign-in
-- (PalmProfileUtil.getDeviceParams -> deviceModel, firmwareVersion, platform,
-- productSku, nduID), so device.php can capture these with no client change.
-- All nullable: rows created before this migration, and web/PWA clients that
-- send no device block, simply have no metadata and render as "webOS device".
--
-- Q "what devices are on this account, in human terms?"
--     SELECT device_name, device_model, device_os FROM account_tokens
--      WHERE account_id = ? AND device_id IS NOT NULL;

ALTER TABLE account_tokens
  ADD COLUMN device_name  VARCHAR(64) NULL DEFAULT NULL AFTER device_id,
  ADD COLUMN device_model VARCHAR(64) NULL DEFAULT NULL AFTER device_name,
  ADD COLUMN device_os    VARCHAR(64) NULL DEFAULT NULL AFTER device_model;
