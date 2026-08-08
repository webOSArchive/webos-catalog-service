# Accounts System — Roadmap & Status

Guiding principle: **accounts exist only for (a) admin/staff or (b) legacy-client
support.** There is no web self-signup, and web visitors never need an account to
browse. Accounts are admin-provisioned.

## Status

| Phase | What | State |
|-------|------|-------|
| 0 | Schema / foundation | ✅ done |
| 1 | Admin login + permissions (inside basic auth) | ✅ done (pen-tested + hardened) |
| 2 | Link apps to an owner account (#4) | ✅ done |
| 3 | App submission + moderation (#3) | 🔶 partial — trusted submission + claims live; moderation queue planned |
| 4 | Legacy-client auth + reviews/ratings writes (#5) | ⏭ planned |

## Building blocks already in place (Phases 0–2)

- **Tables:** `accounts`, `roles`, `account_roles`, `account_tokens` (created but
  unused until Phase 4), plus columns `apps.owner_account_id` and
  `app_reviews.author_account_id`. Rescued reviews keep their historical numeric
  `app_reviews.account_id`; `accounts.legacy_account_id` exists to map to it later.
- **`includes/Capabilities.php`** — role → capability map. Roles: `superadmin`
  (wildcard), `admin`, `curator`, `developer`, `viewer` (read-only; `admin.access`
  only, redirected to `admin/stats.php`). Capabilities include `admin.access`,
  `accounts.manage`, `apps.edit`, `apps.submit`, `apps.own`, `reviews.moderate`,
  `ipk.manage`, `categories.manage`, `authors.manage`, `logs.view`.
- **`includes/AccountRepository.php`** — account CRUD, constant-time `verifyLogin`,
  role/capability helpers, `deleteAccount` (cascades roles/tokens, NULLs owned
  apps / authored reviews).
- **`scripts/create-account.php`** — CLI provisioning (hidden password prompt).
- **Admin auth:** `admin/includes/security.php` is the bootstrap (isolated session,
  gate on `admin.access`, CSRF token + Referer, `admin_require_capability()`).
  `admin/login.php` (failed-login rate limit + unified error), `logout.php`,
  `accounts.php` (superadmin-only management: create / roles / enable-disable /
  reset password / delete-when-disabled), nav shows Accounts + current user.
- **App ownership:** `app-edit.php` Owner Account selector; `apps.php` Owner column;
  `AppRepository` create/update persist `owner_account_id`, `adminSearch` filters/joins
  by owner.
- **Per-page permission gating:** `admin_require_capability()` / `admin_require_any()`
  / `admin_is_owner_only()` gate each admin page and filter the nav. `developer`
  accounts (`admin.access`, `apps.own`, `apps.submit`, `logs.view`, `ipk.manage`)
  reach the Dashboard, Logs and IPK manager, and an Apps view scoped to **only their
  own** apps (owner filter on the list; ownership check on edit; curation/status fields
  forced on save; owner selector hidden). IPK **uploads** are further scoped: the file
  name must start with the `public_application_id` of one of their owned apps. They do
  not see Categories, Authors or Accounts.
- **App images:** icons + screenshots are uploaded via `admin/app-images.php` to the
  filesystem (`config['image_path']`, per-app `<appId>/` folders auto-created on app
  create) using `includes/ImageStorage.php` — never stored in the DB. Managers
  (`apps.edit`, incl. curators) manage any app's images; owners (developers) only their
  own. Uploads are validated as real images and given safe generated names.

## Phase 3 — App submission + moderation (#3)

Goal: accounts with `apps.submit` can submit apps; admins moderate them.

**Done (trusted-submission interim — all current users are trusted, no approval
steps yet):**

- **Direct submission:** `apps.submit` accounts use the regular `app-edit.php`
  "Add New App" flow. New apps by owner-only accounts are forced to
  `owner_account_id` = the submitter and start uncurated
  (`recommendation_order` 0, no featured flags); curation fieldsets are hidden
  for them. The developer picks status/content flags.
- **App claims:** `admin/app-claim.php` ("Claim Existing App" on the Apps page,
  `apps.own`) lets a developer claim an **unowned** app by ID with a required
  explanation — e.g. to restore an app that was originally theirs. Claims are
  auto-granted atomically (`AppRepository::claimApp`, race-safe on
  `owner_account_id IS NULL`) and recorded in the `app_claims` table
  (`sql/migrations/0002_app_claims.sql`; statuses `granted`/`pending`/`rejected`
  ready for a future approval flow). Apps owned by someone else cannot be claimed.

**Remaining (when trust no longer scales):**

- **Moderation queue for submissions:** submissions land as
  `apps.status = 'pending'` (add `'rejected'` too); admin page to approve
  (→ `active`) or reject, gated by an approval capability.
- **Claim approval flow:** claims land as `pending` in `app_claims`; admin
  review UI to grant/reject (schema already supports it).
- **IPK tie-in:** consider hooking submissions into `ipk-manager` / Azure blob.

## Phase 4 — Legacy-client accounts + writes (#5)

Goal: restored webOS clients authenticate and post ratings/reviews.

- **Device login endpoint** (`WebService/`): validate username/password → issue a
  device token stored in `account_tokens` (`token_hash`, `device_id`/nduid,
  `expires_at`); return the raw token to the client once.
- **Authenticated write endpoints:** POST review/rating validated by the token →
  write to `app_reviews` with `author_account_id` = account, then recompute
  `app_metadata.star_rating` / `apps.review_count`. `is_inappropriate` already
  exists for moderation (`reviews.moderate`).
- **Transport:** devices running the community OTA have TLS 1.3 / full modern
  HTTPS, so device tokens travel encrypted. Still treat device accounts as
  low-trust — per-device revocable tokens, never the admin password;
  rate-limit the login endpoint (reuse `WebService/ratelimit.php`).
- **Optional reconciliation:** map rescued Palm `app_reviews.account_id` →
  `accounts.legacy_account_id` so a returning user reclaims their old reviews.

Open questions: the exact protocol the restored enyo/mojo clients speak; whether to
re-enable on-device signup (currently admin-provisioned only).

## Smaller follow-ons

- Deeper authenticated pen test with throwaway `curator`/`developer` accounts.

## Decided / out of scope

- No web self-signup, ever (foreseeable future).
- Admin-provisioned accounts only; on-device signup deferred (revisit in Phase 4).
