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

## Future feature — restore previously-acquired apps

HP's device backend exposed the account's **previously-installed apps** so a device could
re-download them after sign-in. The on-device `com.palm.service.backup` still calls these
right after login:

- **`getUserInstalledApps_ext2`** — the list of apps the account had installed (drives
  auto-reinstall). We currently stub it to return **no apps** so restore completes cleanly.
- **`getRestoreDevices`** — the list of the account's prior devices and their backups
  (drives the "copy from another device" UI). Also effectively stubbed empty.

**Someday:** back these with real data. We already know which apps an account owns/downloaded
(`download_logs`, and app ownership). Returning the account's acquired-app list from
`getUserInstalledApps_ext2` would let a freshly-signed-in TouchPad automatically reinstall
everything that account previously got from the Museum — a genuine "restore my apps" feature,
using the exact protocol the device already speaks. The response shape is
`{OutGetUserInstalledAppsV2:{userApps:[{id, version, title, …}]}}`.

## Decoupling firstuse from system/OTA updates

After sign-in, stock firstuse's `PostSignIn` card runs an **OTA software-update check**
(`UpdateService/CheckForUpdate`) and will not finish until it returns. This device has **no
software-update service at all**, so that check never resolves → the sign-in spinner hangs
forever. Decision (per project direction): **firstuse must stay independent of system updates
for the foreseeable future.** We patch the OTA gate out of `PostSignIn.js` (short-circuit
`otaResponse`/`restoreResponse`, set `IsOTAAvailable`/`SoftwareUpdateAvailable` false) so
sign-in completes on its own.

System updates are a **separate project** (replacing the HP OTA server). When that exists, it
should be its own flow/app — not re-coupled into firstuse. Related dead-HP calls firstuse makes
that we've neutralized: `CheckForUpdate`/`GetStatusApp` (OTA), `getRestoreDevices` (backup
device list), `getUserInstalledApps_ext2` (app restore — see Future feature above).

## Revised architecture (learned from wiping a dev device)

**What we learned:** completing stock firstuse in first-use mode runs a device reset that wipes
`/media/cryptofs/apps` — which on webOS holds not just 3rd-party apps but **most 1P apps too**
(Email, Calendar, Maps, Memos, Photos…). Only ROM apps in `/usr/palm/applications` (Phone,
Browser, some Settings) survive. firstuse does this expecting to immediately re-download the full
app set from HP's (dead) catalog/customization servers. So stock firstuse is hostile: every card
depends on a dead HP service, and its completion path resets the device.

**Decision 1 — build our own, don't patch stock firstuse.** Ship a purpose-built
`com.webosarchive.firstrun` app that clones only the good asset — firstuse's **`Signin.js`** UI
(Sign In / Create, already wired to our patched `accountservices`). It does ONLY controlled steps:
show UI → (login patch already) write db8 `com.palm.account:1` + keymanager token → set the
done-flags (`ran-first-use`, `first-use-profile-created`, `first-use-finished`) → close. **No
erase, no OTA check, no HP restore, no `PalmSystem.shutdown()`.** Optional, re-armable, bailable,
and structurally incapable of wiping a device. The on-device palmprofile-service patch + our
Phase-4 endpoints stay as the backend.

**Decision 2 — provisioning is additive-only, and separable.** The `getUserInstalledApps_ext2`
seam (HP's "what should this device install" hook) can be upgraded from an empty stub to a
**curated community baseline** (App Catalog client, patches, TLS bits) so a freshly-Doctored
device auto-provisions the community stack. The invariant that makes this safe: **never erase
first** — the danger was always the reset firstuse couples to the download, not the download
itself. Account-setup and app-provisioning are independent features sharing one transport.

**Testing rule:** validate "signs in WITHOUT wiping apps" and the provisioning baseline only on a
freshly-Doctored device that actually has the full app set — you can't prove "doesn't delete apps"
on a device with no apps. Prototype the additive account-write/flag logic anywhere.

## Status — end of session (VERIFIED WORKING on hardware)

The full flow works end-to-end on a freshly-Doctored + unlocked TouchPad, **without wiping apps**:

- **Unlock reproduced without deviceTool** — memboot `topaz.uImage` (extracted from `devicetoolAIO.jar`)
  → `touch /var/gadget/novacom_enabled` (dev-unlock) + `ran-first-use` (OOBE skip) → reboot. novacom
  survives a normal boot. Community OTA just ships `/var/gadget/novacom_enabled`.
- **Our own app `com.palm.app.webosaccount`** (a neutered clone of firstuse) signs in with a catalog
  account → patched palmprofile service → `WebService/device.php` → writes the device profile
  (db8 palmprofile record, username = account display_name e.g. "codepoet") + saves the token
  (`getAccountToken` returns alias/token/ACTIVE/uniqueId) → **closes cleanly, no reset, no wipe.**
  Verified: 54 apps registered / 34 launchpoints / 17 on disk unchanged; uptime continuous.

**On-device pieces (NOT in git — re-deploy after any Doctor):** service patches
`palm_profile_util.js` (redirect) + `LoginProfileCommandAssistant.js` (skip LCN); app dir
`/usr/palm/applications/com.webosarchive.firstrun/` with id **`com.palm.app.webosaccount`** — its
`FirstUse.js` (safe completion, erase/OTA/powerdown neutered, `dataConnection:true`), `Signin.js`
(skip PostSignIn), `config.js` = `[signin]`, and **the id set in the base AND all
`resources/<locale>/appinfo.json`** (they override the base). Register via `killall LunaSysMgr` or
`applicationManager/rescan`. **Server side is live and in git** (`device.php`, `AccountRepository`).

## Polish plan

1. ✅ **Restore the OEM "thank-you" / complete page** — DONE (in `webos/patches/FirstUse.js.patch`):
   `done()` no longer closes; it shows the `complete` view with the spinner hidden, subtitle
   *"Signed in as \<accountAlias\>. Your webOS Account is ready to use."* (via `updateCompletePage()`,
   called from both `done()` and `getTokenResponse` since `getAccountToken` is async), and a
   **Done** button that calls `closeApp()`. Also fixed `deploy.sh apply()` to be idempotent:
   first touch saves `<file>.stock` on-device and every deploy re-patches from that pristine base
   (patching the live file made `patch` auto-reverse an already-applied diff — this silently
   un-patched the service once).
2. ✅ **Reconcile the device account** — DONE (verified on hardware), by RENAME not delete
   (user call: deletion risks the accounts-service teardown cascade; duplicates were the actual
   bug). `createLocalAccount` in `webos/patches/palm_profile_util.js.patch` now UPSERTS: if a
   `com.palm.palmprofile` account exists it renames it in place via
   `com.palm.service.accounts/modifyAccount {accountId, object:{username}}` (preserves
   capabilityProviders; callable by `com.palm.accountservices` — raw db8 merge is NOT, kind
   permission denied); creates only when none exists; the bypass name "Dr. Skipped Firstuse"
   never overwrites a real account. (Bypass *create* was already duplicate-safe — it has its own
   listAccounts guard and returns accountCreated:false.) Verified: fresh-device simulation (row
   named back to bypass) + real sign-in → log "WOSA: renaming … 'Dr. Skipped Firstuse' ->
   'codepoet'", single account, token ACTIVE. Pre-cleanup backups:
   `/media/internal/.webos-account-backup/palmprofile-accounts-pre-rename-20260803.json`.
3. ✅ **Wire our own TOS card** — DONE (verified on hardware). `webos/patches/Palm.js.patch`:
   `getURL()` skips the dead-LCN `getURLForTerms` retry loop and calls `getTerms()` directly with
   our HTTP `device.php?m=` base (the service's `getTermsAndConditions` POSTs to
   `serverURL + "getTermsAndConditions"`, so the `?m=` base composes as-is); also neutered the
   error-popup path that deleted the saved Wi-Fi profile + restarted the flow (now just retries
   the fetch). `webos/app/config.js` is now `[palm, signin]` — terms card first, then sign-in.
4. ✅ **Launcher entry** — DONE: the localized `resources/<locale>/appinfo.json` files carried
   firstuse's `"visible": false` (they override the base) — deploy.sh's re-id pass now flips
   visibility, sets vendor "webOS Archive", and ships a proper Launcher icon
   (`webos/app/images/icon.png`, account glyph on a webOS-style tile). Launchpoint verified.
4c. ✅ **Installable IPK** (pre-OTA distribution via the Museum) — DONE, verified full lifecycle
   on hardware: `webos/scripts/package.sh` pulls the built app + patched service files off a
   deployed device (repo ships diffs, not HP source) and assembles
   `dist/org.webosarchive.webosaccount_<ver>_all.ipk`. `ipk/postinst` (root, via
   Preware/ipkgservice — the Museum app's install path; stock appinstaller does NOT run
   scripts) replays the deploy: app → rootfs, service patched with `.stock` backups;
   `ipk/prerm` restores stock + removes the app. Gotcha: ipkg 0.99 requires `./`-prefixed
   tar members or it rejects the archive (rc 22). The app is destined for its OWN repo later.
4b. ✅ **De-brand HP strings** (user request — don't put words in HP's mouth): all visible
   HP / hpwebos.com / palm.com / "previously Palm Profile" strings in the terms card
   (`Palm.js.patch`: card title, accept-confirm popup, server-error popup) and sign-in card
   (`Signin.js.patch`: headers, about/forgot/embargo/error popups, view subtitles) replaced with
   generic webOS Account / App Museum / webosarchive.org wording. Note: `rb.$L()` keys off the
   source string, so non-English locales will show these new strings untranslated (English) —
   acceptable. Only remaining "hpwebos" in Signin.js is a commented-out line.
5. **Package the OTA** — patched palmprofile service + the app + `/var/gadget/novacom_enabled` +
   ship `device.php` on the server.
6. **Save artifacts to git** — offered: a `webos/` dir with the device patch-set + app source so the
   spike is version-controlled and re-deployable (currently only on-device + in scratch).

## 8. Confirm before shipping

- **Exact request/response JSON** of `createNovaAccount` / `authenticateAccount` / `getAccountToken`
  — defines the contract both sides must agree on.
- **What deviceinfo actually reads** for the account name — the `com.palm.account:1` record is the
  source of truth (showing "Dr. Skipped Firstuse"); trace deviceinfo's exact read so the display
  updates cleanly.
