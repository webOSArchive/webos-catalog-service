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
| 3 | App submission + moderation (#3) | ⏭ planned |
| 4 | Legacy-client auth + reviews/ratings writes (#5) | ⏭ planned |

## Building blocks already in place (Phases 0–2)

- **Tables:** `accounts`, `roles`, `account_roles`, `account_tokens` (created but
  unused until Phase 4), plus columns `apps.owner_account_id` and
  `app_reviews.author_account_id`. Rescued reviews keep their historical numeric
  `app_reviews.account_id`; `accounts.legacy_account_id` exists to map to it later.
- **`includes/Capabilities.php`** — role → capability map. Roles: `superadmin`
  (wildcard), `admin`, `curator`, `developer`. Capabilities include `admin.access`,
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
  `AppRepository` create/update persist `owner_account_id`, `adminSearch` joins the
  owner username.

## Phase 3 — App submission + moderation (#3)

Goal: accounts with `apps.submit` can submit apps; admins moderate them.

- **Statuses:** submissions land as a new `apps.status = 'pending'` (add
  `'rejected'` too), with `owner_account_id` = the submitter.
- **Submission form:** a page for `apps.submit` accounts to create an app record
  (title, author, category, icons, optional IPK). Decide surface: a limited-nav
  area of `/admin`, or a dedicated authenticated page.
- **Moderation queue:** admin page listing `pending` apps → approve (→ `active`)
  or reject (→ `rejected`), gated by an approval capability.
- **Developer view:** filter apps to `owner_account_id` for `developer` accounts
  (a "My Apps" view) — a small lead-in worth doing first.
- **IPK tie-in:** consider hooking submissions into `ipk-manager` / Azure blob.

Open questions: where developers submit from; whether submissions include an IPK
upload or just metadata + link.

## Phase 4 — Legacy-client accounts + writes (#5)

Goal: restored webOS clients authenticate and post ratings/reviews.

- **Device login endpoint** (`WebService/`): validate username/password → issue a
  device token stored in `account_tokens` (`token_hash`, `device_id`/nduid,
  `expires_at`); return the raw token to the client once.
- **Authenticated write endpoints:** POST review/rating validated by the token →
  write to `app_reviews` with `author_account_id` = account, then recompute
  `app_metadata.star_rating` / `apps.review_count`. `is_inappropriate` already
  exists for moderation (`reviews.moderate`).
- **Legacy HTTP reality:** device tokens travel cleartext (legacy TLS). Treat
  device accounts as low-trust — per-device revocable tokens, never the admin
  password; rate-limit the login endpoint (reuse `WebService/ratelimit.php`).
- **Optional reconciliation:** map rescued Palm `app_reviews.account_id` →
  `accounts.legacy_account_id` so a returning user reclaims their old reviews.

Open questions: the exact protocol the restored enyo/mojo clients speak; whether to
re-enable on-device signup (currently admin-provisioned only).

## Smaller follow-ons

- "My Apps" filter by `owner_account_id` for developer accounts (lead-in to Phase 3).
- Deeper authenticated pen test with throwaway `curator`/`developer` accounts.

## Decided / out of scope

- No web self-signup, ever (foreseeable future).
- Admin-provisioned accounts only; on-device signup deferred (revisit in Phase 4).
