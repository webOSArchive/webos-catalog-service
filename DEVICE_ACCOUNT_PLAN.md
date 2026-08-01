# Reviving webOS Account Setup as a shared "webOS Account"

Goal: revive the TouchPad's account-creation flow so that a **webOS Account** is the
**same account** as an [appcatalog.webosarchive.org](https://appcatalog.webosarchive.org)
catalog account — one shared community account. It must be **optional** (not required like
the old HP Account), **re-armable on demand**, **always bailable**, and the created account
must become the device account shown in `com.palm.app.deviceinfo`.

Context: HP's activation servers died >10 years ago; `devicetoolAIO.jar` currently bypasses
activation by injecting a fake profile. We are building a community OTA, so we can patch
anything on-device. TLS upgrades ship in the same OTA, so the device can speak modern HTTPS.

---

## 1. What's actually on the device (grounded in probes)

**The account is a db8 record.** `com.palm.account:1` currently holds the deviceTool's
bypass profile:

```json
{ "_kind":"com.palm.account:1", "templateId":"com.palm.palmprofile",
  "username":"Dr. Skipped Firstuse",
  "capabilityProviders":[ CONTACTS, CALENDAR, TASKS, MEMOS, MESSAGING, PHONE, LOCAL.FILESTORAGE ] }
```

That single record (templateId `com.palm.palmprofile`) **is** "the device account" deviceinfo
displays. The deviceTool trick: write this row + set the firstuse flags so the device believes
it's activated. A separate `com.palm.telephony` "local" account also exists — leave it alone.

**firstuse is a web app, not a binary.** `com.palm.app.firstuse` is `type: web` with readable
JS: `source/signin/{Signin,PostSignIn,Namedevice}.js`, `source/tnc/Palm.js`, `FirstUse.js`,
`Service.js`. It calls the account system over the bus at `palm://com.palm.accountservices/`
(FirstUse.js:217/234). It is `visible:false` and launched explicitly.

**The account server client is also patchable JS.** `com.palm.service.palmprofile` (registered
on the bus as `com.palm.accountservices`) is an ES5 Foundations service — `serviceassistant.js`,
`handlers/`, `models/`, `sources.json`. Its `services.json` methods are the entire account
protocol:

| Method | Role |
|---|---|
| `createNovaAccount` | **create** a new webOS account |
| `authenticateAccount` / `isUserValid` | **sign in** to an existing one |
| `getAccountToken` | mint the device token |
| `isEmailAvailable`, `isDeviceInUse` | pre-flight checks |
| `getServerUrl` / `getAggregatedAccountInfo` | base URL source, account read-back |
| `changePassword`, `requestPasswordResetEmail` | lifecycle |

Today these POST to HP's dead REST backend (the service still references `lcn.palmws.com`).
**This service is the single integration seam.**

**firstuse gating is four 0-byte flag files** in `/var/luna/preferences/`: `ran-first-use`,
`first-use-profile-created`, `personal-data-encrypted`, `used-first-card`. Existence = "done."
Remove the first two → firstuse re-arms.

---

## 2. Core strategy

Don't fight the platform — **speak its protocol.** The palmprofile service already models
"create account / authenticate / get token / read account info." Point that seam at the
catalog-service instead of HP, and every consumer above it (firstuse, deviceinfo, Accounts app,
keymanager) keeps working unmodified.

```
  ┌─ DEVICE ──────────────────────────────┐        ┌─ catalog-service ─────────┐
  │ firstuse (opt-in card)                 │        │  Phase 4 device API       │
  │   → palm://com.palm.accountservices/   │        │   POST /device/register   │
  │        createNovaAccount / authenticate│ HTTPS  │   POST /device/authenticate│
  │   ↳ com.palm.service.palmprofile  ──────┼───────▶│   POST /device/token      │
  │       (REDIRECTED base URL / handlers) │        │   accounts + account_tokens│
  │   → writes com.palm.account:1 record   │        └───────────────────────────┘
  │   → deviceinfo shows the real username │
  └────────────────────────────────────────┘
```

The **shared identity** is the existing `accounts` table. A "webOS Account" is a catalog account
with a device token issued into the `account_tokens` table (device_id = NDUID). One account works
on the website, in the admin UI, and on-device.

---

## 3. Catalog-service side (Phase 4 — "legacy client auth")

Normal PHP in this repo; no device risk. New rate-limited endpoints under `WebService/` backed by
`AccountRepository` + the `account_tokens` table:

- **`device/authenticate`** — username/password → `verifyLogin()` → issue a device token bound to
  the posted NDUID → return `{ token, displayName, email, accountId }`.
- **`device/register`** — create a new account from the device. Web self-signup stays disabled;
  **device signup is the sanctioned path**. Same validation as admin account creation + low per-IP
  rate limit + shared app-key so only our firstuse build can hit it.
- **`device/token/refresh` + `device/deauthenticate`** — token lifecycle / "sign out this device."
- Map the palmprofile vocabulary onto these: `isEmailAvailable`→check endpoint,
  `getAccountToken`→`device/token`, `getAggregatedAccountInfo`→profile read.

**Transport:** the OTA ships modern TLS, so target **HTTPS** to the origin. Keep the base URL
config-driven (one string) so it can fall back to HTTP or the `oauth.wosa.link` broker for any
not-yet-upgraded device. Tokens are bearer secrets; NDUID is treated as non-secret.

---

## 4. Device side

**(a) The redirect.** Change where `com.palm.service.palmprofile` sends requests — override the
base-URL/`getServerUrl` model and swap the request/response mappers in `handlers/`+`models/` so
`createNovaAccount`→`device/register`, `authenticateAccount`→`device/authenticate`,
`getAccountToken`→`device/token`, with JSON shapes matching our API. ES5 JS in a community OTA —
fully patchable. Restart Luna (`org.webosinternals.ipkgservice/restartLuna`) to reload.

**(b) Writing the device account.** On success, do what the deviceTool does but for real: upsert
the `com.palm.account:1` record (templateId `com.palm.palmprofile`, `username` = webOS display
name), store the device token in **keymanager** (`palm://com.palm.keymanager/store`), let the
configurator's `first-use-profile-created` hook register palmprofile kinds. deviceinfo then shows
the real account name.

**(c) Re-arming firstuse on demand.** A small "Set up a webOS Account" launcher that: backs up the
current bypass account, removes `ran-first-use` + `first-use-profile-created`, and launches
`com.palm.app.firstuse` via `applicationManager/open` (no reboot; jump straight to
`source/signin/Signin.js`, skipping language/TNC).

**(d) Bail-out = restore, not brick.** Activation is currently satisfied by the bypass row, so
**never delete it until a real account replaces it.** Setup keeps the bypass record staged;
"Cancel" restores it verbatim + re-writes the flags. The device is always in a valid activated
state — opt-in is purely additive.

---

## 5. How each requirement is met

- **Not required** — bypass account stays the default; nothing forces firstuse.
- **Re-arm on demand + walk through it** — launcher clears two flags, opens firstuse's signin cards.
- **Always able to bail** — cancel restores the staged bypass record; can't get stuck unactivated.
- **Becomes the device account** — we write the real `com.palm.account:1` palmprofile record.
- **Same account as the catalog** — it *is* a catalog `accounts` row; token in `account_tokens`.

---

## 6. Landmines (respect these)

1. **NDUID / mountcrypt is sacred.** `/var/db` is dm-crypt unlocked by an NDUID-derived key. Never
   spoof NDUID or touch the key blobs — a bad NDUID silently fails the mount and every db8 write is
   lost. We only *read* NDUID as a non-secret id.
2. **keymanager init is gated on `com.palm.palmprofile:1`.** If registered but empty, every
   `fetchKey` returns "library not initialized." Write a valid palmprofile record, not a half one.
3. **LS2 roles load once at hub start.** New service names / bus permissions need role files on disk
   + a Luna restart, not a live reload.
4. **Stage on `/media/internal/`** — survives reboots, uninstalls, and a Doctor reflash. Back up the
   bypass account there so a botched setup is always recoverable.
5. **Everything reversible via one OTA** — ship the patched service + launcher with clean uninstall.

---

## 7. Sequence

1. **Verify the seam** (read-only): dump `getServerUrl` + the `createNovaAccount`/
   `authenticateAccount`/`getAccountToken` request+response mappers to pin exact JSON shapes.
2. **Build Phase 4 on catalog-service** (safe PHP, testable without a device) to match that contract.
3. **Patch palmprofile service** to redirect + remap; test each method with `luna-send` before
   touching firstuse.
4. **Wire firstuse** (skip to signin, success writes the account) + re-arm launcher + staged bail-out.
5. **Package the OTA** with backup-to-`/media/internal/` and clean uninstall.

---

## 8. Confirm before shipping

- **Exact request/response JSON** of `createNovaAccount` / `authenticateAccount` / `getAccountToken`
  — defines the contract both sides must agree on.
- **What deviceinfo actually reads** for the account name — the `com.palm.account:1` record is the
  source of truth (showing "Dr. Skipped Firstuse"); trace deviceinfo's exact read so the display
  updates cleanly.
