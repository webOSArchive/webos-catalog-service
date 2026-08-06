<?php
/**
 * device.php — account backend for restored webOS device clients (Phase 4).
 *
 * The patched on-device account service (com.palm.service.palmprofile) posts here
 * instead of HP's dead servers. Its handlers build request URLs as
 *   getServerUrl() + <methodName>
 * and we set getServerUrl() to "…/WebService/device.php?m=", so the method arrives
 * as ?m=<methodName> with a JSON body. We emulate the two HP calls the firstuse
 * flow needs and answer in the exact shape the service consumes: an
 * "AuthenticateInfoEx" object it reads (saveAccountToken) to mint the local db8
 * profile + keymanager token.
 *
 *   ?m=createDeviceAccount      register a new webOS Account (= a catalog account)
 *   ?m=authenticateFromDevice   sign in to an existing account
 *
 * The stock Accounts settings app (com.palm.app.accounts) drives a second,
 * token-authenticated surface — the profile editor behind the account row. Its
 * assistants are stock and already post through our transport, so these are
 * server-only implementations of HP's original wire shapes:
 *
 *   ?m=getAccountInfoAggregate  the whole profile in one call (name, email,
 *                               username, device list) — the app's entry call
 *   ?m=isUserValid              re-auth gate ("enter your account password")
 *   ?m=updateAccountInfo        change the display name
 *   ?m=changeEmailAddress       change the account email
 *   ?m=changePassword           set a new password
 *   ?m=assignDeviceName         the device names itself after sign-in
 *   ?m=updateUsername           OURS, not HP's: pick a public username
 *
 * Username: every device account starts with username = its email (see
 * createDeviceAccount). The Accounts app now exposes it as an editable field so
 * members have a shareable handle that is not their email address, and the
 * device publishes it to other apps via getAccountToken.
 *
 * Web/PWA clients (Phase 2 of the app-storage plan) use the plain-JSON
 * methods instead of the HP shapes — same accounts, same per-device tokens,
 * with a browser-generated synthetic device id (recommend "pwa-<uuid>" kept
 * in localStorage) so each browser shows up in devicesForAccount() and is
 * individually revocable:
 *
 *   ?m=authenticateWeb   {login, password, device_id} -> {token, expires_at, account}
 *   ?m=refreshToken      {token} -> {token, expires_at}   (old token is invalidated)
 *   ?m=deauthenticate    {token} -> {deauthenticated}     (sign-out; idempotent)
 *
 * A "webOS Account" IS a catalog `accounts` row; the device gets a per-device
 * token in `account_tokens` (device_id = nduid). Web self-signup stays disabled;
 * device signup is the sanctioned legacy-client path (admin can still disable an
 * account, which revokes device access via the accounts.status check).
 *
 * Transport: HTTPS — devices running the community OTA have modern TLS (the
 * on-device service posts via /usr/bin/curl). Tokens are still treated as
 * low-trust — per-device, revocable, never the account password.
 */

require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/../includes/AccountRepository.php';

// CORS for browser/PWA clients: bearer-token auth with no cookies, so a
// wildcard origin is safe. Content-Type: application/json makes browsers
// preflight, hence the OPTIONS handler. Legacy device clients (curl) ignore
// these headers entirely.
header('Access-Control-Allow-Origin: *');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    // Same set as storage.php: the SDK sends X-Palm-Device-Id on every call
    // (it mints the device id before first sign-in) and Authorization once a
    // token exists (refreshToken/deauthenticate).
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Palm-Device-Id');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

// Throttle all device-auth traffic per IP (shares WebService/__rateLimit).
// Sign-in alone is a handful of calls, but the Accounts app's profile editor is
// chatty (an aggregate fetch plus a re-auth every time it is opened, then one
// call per edit), and a household behind one NAT shares this budget.
checkRateLimit(240, 3600);

$method = $_GET['m'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}

$repo = new AccountRepository();

/**
 * The success payload the palmprofile service expects. It reads accountAlias,
 * token, accountState and the *Time fields (saveAccountToken -> getToken); the
 * device's displayed profile name comes from the firstuse first/last name, not
 * from here. moreData.entry must exist (getToken iterates it).
 */
function authenticate_info_ex($account, $token) {
    $nowMs   = time() * 1000;
    $yearMs  = 365 * 86400 * 1000;
    return ['AuthenticateInfoEx' => [
        'accountAlias'          => $account['email'] ?: $account['username'],
        // Our own field: the patched getToken() copies it into the db8 profile so
        // getAccountToken can hand the username to any app on the device.
        'accountUsername'       => $account['username'],
        'displayName'           => ($account['display_name'] ?? '') ?: ($account['email'] ?: $account['username']),
        'token'                 => $token,
        'accountState'          => 'ACTIVE',
        'authenticationTime'    => $nowMs,
        'expirationTime'        => $nowMs + $yearMs,
        'accountExpirationTime' => $nowMs + $yearMs,
        'uniqueId'              => (string)$account['id'],
        'moreData'              => ['entry' => []],
    ]];
}

/** Handled error: the service surfaces a generic create/sign-in failure. */
function device_fail($code) {
    echo json_encode(['JSONException' => $code]);
    exit;
}

/** Pull the device/nduid identifier out of an HP-style device param block. */
function device_id_from($device) {
    if (!is_array($device)) {
        return null;
    }
    $id = $device['nduID'] ?? $device['deviceID'] ?? null;
    return ($id !== null && $id !== '') ? $id : null;
}

/**
 * Descriptive device fields out of the same HP device block, for the account's
 * DEVICES list. The device sends no friendly name at sign-in — it assigns one
 * afterwards via assignDeviceName — so only model/OS are available here.
 */
function device_meta_from($device) {
    if (!is_array($device)) {
        return [];
    }
    return [
        'model' => $device['deviceModel'] ?? null,
        'os'    => $device['firmwareVersion'] ?? null,
    ];
}

/**
 * Resolve the caller's account from an HP "authToken"/token field, or end the
 * request. Every profile-editing method is authenticated this way: the device
 * holds a per-device token from sign-in and never re-sends the password except
 * through isUserValid.
 */
function device_account_or_fail($repo, $token) {
    $account = is_string($token) && $token !== '' ? $repo->verifyDeviceToken($token) : null;
    if (!$account) {
        // ACCOUNT_NOT_DEFINED_ERROR is one of the few codes the Accounts app has
        // real copy for ("We were unable to locate your account information").
        device_fail('ACCOUNT_NOT_DEFINED_ERROR');
    }
    return $account;
}

/**
 * Split a stored display_name back into the first/last pair the Accounts app
 * edits. Lossy for multi-word given names ("Mary Jo Smith" -> "Mary"/"Jo
 * Smith"), but it round-trips: the app rejoins them with a space on save.
 */
function device_split_name($display) {
    $display = trim((string)$display);
    if ($display === '') {
        return ['', ''];
    }
    $sp = strpos($display, ' ');
    return $sp === false
        ? [$display, '']
        : [substr($display, 0, $sp), trim(substr($display, $sp + 1))];
}

/**
 * Username rules. Deliberately narrower than accounts.username's VARCHAR(64):
 * this is a public handle, so keep it short, typeable and free of the '@' that
 * would make it ambiguous with an email at sign-in (verifyDeviceLogin matches
 * username OR email). Uniqueness is case-insensitive via the column collation.
 *
 * @return string|null error code, or null when acceptable.
 */
function device_username_error($username) {
    static $reserved = ['admin', 'administrator', 'root', 'system', 'support', 'help',
                        'moderator', 'webos', 'webosarchive', 'museum', 'palm', 'hp', 'null'];
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,31}$/', $username)) {
        return 'USERNAME_INVALID';
    }
    if (in_array(strtolower($username), $reserved, true)) {
        return 'USERNAME_RESERVED';
    }
    return null;
}

/** The account's devices, in the shape the Accounts app's DEVICES list renders. */
function device_list_for($repo, $accountId) {
    $out = [];
    foreach ($repo->devicesForAccount($accountId) as $d) {
        $model = (string)($d['device_model'] ?? '');
        $out[] = [
            'nduId'            => $d['device_id'],
            'deviceName'       => ($d['device_name'] ?? '') ?: ($model ?: 'webOS device'),
            'deviceModel'      => $model,
            'deviceType'       => $model ?: 'webOS',
            'webOSDisplayName' => (string)($d['device_os'] ?? ''),
        ];
    }
    return $out;
}

switch ($method) {

    case 'createDeviceAccount':
        $in       = $body['InCreateDeviceAccount'] ?? [];
        $acct     = $in['account'] ?? [];
        $email    = trim((string)($acct['email'] ?? ''));
        $password = (string)($in['password'] ?? '');
        $first    = trim((string)($acct['firstName'] ?? ''));
        $last     = trim((string)($acct['lastName'] ?? ''));
        $deviceId = device_id_from($in['device'] ?? null);

        if ($email === '' || $password === '' || $first === '') {
            device_fail('INVALID_REQUEST');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            device_fail('INVALID_EMAIL');
        }
        // Same minimum as scripts/create-account.php and the device UI ("at
        // least 8 characters"). No maximum — password_hash handles any length.
        if (strlen($password) < 8) {
            device_fail('WEAK_PASSWORD');
        }
        if ($repo->findByEmail($email)) {
            device_fail('EMAIL_TAKEN');
        }
        // Device accounts use the email as the login handle; accounts.username is
        // VARCHAR(64), so derive a safe handle when the email is longer.
        $username = strlen($email) <= 64 ? $email : ('dev_' . substr(hash('sha256', $email), 0, 40));
        if ($repo->findByUsername($username)) {
            device_fail('EMAIL_TAKEN');
        }
        $display = trim($first . ' ' . $last);
        try {
            $id = $repo->create([
                'username'     => $username,
                'email'        => $email,
                'password'     => $password,
                'display_name' => $display !== '' ? $display : $email,
                'status'       => 'active',
            ]);
        } catch (Throwable $e) {
            device_fail('ACCOUNT_CREATION_ERROR');
        }
        $token = $repo->issueDeviceToken($id, $deviceId, $_SERVER['HTTP_USER_AGENT'] ?? null, 365,
                                         device_meta_from($in['device'] ?? null));
        echo json_encode(authenticate_info_ex($repo->findById($id), $token));
        break;

    case 'authenticateFromDevice':
        $in       = $body['InAuthenticateFromDevice'] ?? [];
        $alias    = trim((string)($in['accountAlias'] ?? ''));
        $password = (string)($in['password'] ?? '');
        $deviceId = device_id_from($in['device'] ?? null);

        if ($alias === '' || $password === '') {
            device_fail('INVALID_REQUEST');
        }
        $account = $repo->verifyDeviceLogin($alias, $password);
        if (!$account) {
            // No AuthenticateInfoEx => the service reports a sign-in error.
            echo json_encode(['authFailed' => true]);
            exit;
        }
        $token = $repo->issueDeviceToken($account['id'], $deviceId, $_SERVER['HTTP_USER_AGENT'] ?? null, 365,
                                         device_meta_from($in['device'] ?? null));
        echo json_encode(authenticate_info_ex($account, $token));
        break;

    case 'getAccountInfoAggregate':
        // Entry call for the Accounts app's profile editor. HP answered this with
        // one fat payload so the app didn't need four round trips, and the stock
        // GetAccountInfoAggregateAssistant hands OutAccountInfoAggregate straight
        // through as the app's `accountAggregate`. (HP's typo "Aggretate" in the
        // REQUEST key is real — it is what the assistant sends.)
        $in      = $body['InAccountInfoAggretate'] ?? [];
        $account = device_account_or_fail($repo, $in['token'] ?? '');
        list($first, $last) = device_split_name($account['display_name']);
        // The app echoes country/language back on save; we don't store either, so
        // derive them from the locale the app sent and let them round-trip.
        $locale = (string)($in['locale'] ?? 'en_US');
        $parts  = preg_split('/[_-]/', $locale);
        echo json_encode(['OutAccountInfoAggregate' => [
            'accountInfo' => [
                'firstName'    => $first,
                'lastName'     => $last,
                'email'        => $account['email'] ?: '',
                'username'     => $account['username'],
                // Not 'A'/'C': those two make the app offer "Resend Verification
                // Email", and we have no email verification flow.
                'accountState' => 'ACTIVE',
                'language'     => strtolower($parts[0] ?? 'en'),
                'country'      => strtoupper($parts[1] ?? 'US'),
            ],
            'accountDevices' => device_list_for($repo, $account['id']),
            // We store no security questions — the profile editor shows the
            // username in that row instead. These stay as harmless empties so a
            // device running the patched service but the stock app still renders
            // (ProfileSettings dereferences .question without a guard).
            'acctChallengeQuestions'         => ['id' => 0, 'question' => ''],
            'challengeQuestions'             => [],
            'securityQuestionSelectedAnswer' => '',
        ]]);
        break;

    case 'isUserValid':
        // Re-auth gate: the profile editor asks for the account password before
        // showing anything. Verifies only — issuing a token here would overwrite
        // this device's uq_tokens_device row and invalidate the very token the
        // app is about to edit with.
        $in       = $body['InAuth'] ?? [];
        $alias    = trim((string)($in['accountAlias'] ?? ''));
        $password = (string)($in['password'] ?? '');
        if ($alias === '' || $password === '') {
            device_fail('INVALID_REQUEST');
        }
        $account = $repo->verifyDeviceLogin($alias, $password);
        $out     = ['status' => (bool)$account];
        if ($account) {
            // The assistant writes this back to the local db8 profile, which is
            // how a changed email propagates to a device that missed the change.
            $out['accountAlias'] = $account['email'] ?: $account['username'];
        }
        echo json_encode(['OutUserValid' => $out]);
        break;

    case 'updateAccountInfo':
        // The NAME row. Only the display name is written: the request also
        // carries email/language/country, but email has its own method (and its
        // own uniqueness rules) and we store no locale.
        $in      = $body['InUpdateAccountInfo'] ?? [];
        $acct    = $in['account'] ?? [];
        $account = device_account_or_fail($repo, $in['authToken'] ?? '');
        $first   = trim((string)($acct['firstName'] ?? ''));
        $last    = trim((string)($acct['lastName'] ?? ''));
        if ($first === '') {
            device_fail('INVALID_REQUEST');
        }
        // The app collected the password at the re-auth gate and replays it here.
        // Verify it against this account rather than trusting the token alone.
        if (!$repo->verifyAccountPassword($account['id'], (string)($in['password'] ?? ''))) {
            device_fail('PAMS1100');   // "The password you entered is incorrect."
        }
        $repo->updateProfile($account['id'], ['display_name' => trim($first . ' ' . $last)]);
        echo json_encode(['OutUpdateAccountInfo' => ['returnValue' => true]]);
        break;

    case 'changeEmailAddress':
        // The EMAIL row. Note this does NOT touch accounts.username even when the
        // two happen to match (they do for every account created on-device):
        // silently changing the login handle out from under the member would be
        // worse than leaving a stale-looking one, and the username is separately
        // editable now.
        $in       = $body['InChangeEmailAddress'] ?? [];
        $account  = device_account_or_fail($repo, $in['authToken'] ?? '');
        $newEmail = trim((string)($in['newEmailAddress'] ?? ''));
        if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            device_fail('PAMS1005');   // "Please enter a valid email address."
        }
        if (strcasecmp($newEmail, (string)$account['email']) === 0) {
            device_fail('PAMS1123');   // "Cannot change to the same email address."
        }
        if ($repo->emailTaken($newEmail, $account['id'])) {
            device_fail('PAMS1008');   // "Email address is already in use."
        }
        $repo->updateProfile($account['id'], ['email' => $newEmail]);
        echo json_encode(['OutChangeEmailAddress' => ['returnValue' => true]]);
        break;

    case 'changePassword':
        // The PASSWORD row. The device's existing tokens are deliberately left
        // alive: revoking them here would sign the member out of the very device
        // they are standing in front of, mid-edit.
        $in       = $body['InChangePassword'] ?? [];
        $account  = device_account_or_fail($repo, $in['authToken'] ?? '');
        $newPass  = (string)($in['newPassword'] ?? '');
        if ($newPass === '') {
            device_fail('INVALID_REQUEST');
        }
        // Same floor as account creation, so a password set here could also have
        // been used to sign up.
        if (strlen($newPass) < 8) {
            device_fail('WEAK_PASSWORD');
        }
        $repo->setPassword($account['id'], $newPass);
        echo json_encode(['OutChangePassword' => ['returnValue' => true]]);
        break;

    case 'assignDeviceName':
        // The device naming itself, shortly after sign-in. Previously unimplemented
        // (the call failed silently), which is why the DEVICES list had nothing
        // human-readable to show. The assistant reads back .InAssignDeviceName
        // .assignedName — HP used the "In" prefix on the response too.
        $in      = $body['InAssignDeviceName'] ?? [];
        $account = device_account_or_fail($repo, $in['authToken'] ?? '');
        $name    = trim((string)($in['name'] ?? ''));
        $nduId   = trim((string)($in['nduId'] ?? ''));
        if ($name === '' || $nduId === '') {
            device_fail('INVALID_REQUEST');
        }
        $repo->setDeviceName($account['id'], $nduId, $name);
        echo json_encode(['InAssignDeviceName' => ['assignedName' => substr($name, 0, 64)]]);
        break;

    case 'updateUsername':
        // Ours, not HP's — reached from the USERNAME row the Accounts app shows
        // where HP put the security question. On success the service also writes
        // the new username into the local db8 profile so other apps pick it up
        // from getAccountToken without another sign-in.
        $in       = $body['InUpdateUsername'] ?? [];
        $account  = device_account_or_fail($repo, $in['authToken'] ?? '');
        $username = trim((string)($in['username'] ?? ''));
        if ($username === (string)$account['username']) {
            // No-op rather than an error: the dialog opens pre-filled, so saving
            // an untouched field is the most likely "edit" of all. Compared
            // exactly, not case-insensitively, so "jon" -> "Jon" is still a real
            // change (usernameTaken excludes this account, so it won't collide).
            echo json_encode(['OutUpdateUsername' => ['username' => $account['username']]]);
            break;
        }
        if ($err = device_username_error($username)) {
            device_fail($err);
        }
        if ($repo->usernameTaken($username, $account['id'])) {
            device_fail('USERNAME_TAKEN');
        }
        $repo->updateProfile($account['id'], ['username' => $username]);
        echo json_encode(['OutUpdateUsername' => ['username' => $username]]);
        break;

    case 'getUserInstalledApps_ext2':
        // App-restore lookup run by com.palm.service.backup right after sign-in.
        // callServer reads resultObj.OutGetUserInstalledAppsV2; with no "userApps"
        // key the backup activity completes cleanly ("nothing to restore") instead
        // of erroring and retrying forever. We don't track per-account installed
        // apps yet, so report none.
        echo json_encode(['OutGetUserInstalledAppsV2' => new stdClass()]);
        break;

    case 'getAllChallengeQuestions':
        // Security-question list. Currently UNUSED: the create-account card hides
        // its security-question fields (we never stored the answer; recovery is
        // handled by the Archive, not on-device). Kept for a future recovery flow —
        // the service's GetAllQuestionsCommandAssistant returns OutChallengeQuestions
        // verbatim and the card reads .challengeQuestions[{id, question}].
        echo json_encode(['OutChallengeQuestions' => ['challengeQuestions' => [
            ['id' => 1, 'question' => 'What was the name of your first pet?'],
            ['id' => 2, 'question' => 'What city were you born in?'],
            ['id' => 3, 'question' => 'What was your childhood nickname?'],
            ['id' => 4, 'question' => 'What was your first webOS device?'],
            ['id' => 5, 'question' => 'What is your favorite mobile app of all time?'],
        ]]]);
        break;

    case 'isEmailAvailable':
        // Create-account email precheck (our patched IsEmailAvailableCommandAssistant
        // posts {email}; the stock one asked the dead LCN location server). Mirrors
        // createDeviceAccount's uniqueness rules so the precheck never passes an
        // address that account creation would then reject.
        $email = trim((string)($body['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['isEmailAvailable' => false]);
            break;
        }
        $taken = (bool)$repo->findByEmail($email);
        if (!$taken) {
            $username = strlen($email) <= 64 ? $email : ('dev_' . substr(hash('sha256', $email), 0, 40));
            $taken = (bool)$repo->findByUsername($username);
        }
        echo json_encode(['isEmailAvailable' => !$taken]);
        break;

    case 'getTermsAndConditions':
        // Our own community Terms of Service, shown by the firstuse Terms card.
        // The service wraps this as {GetTermsAndConditions: <this>}; Palm.js reads
        // .PALM (rendered as HTML) and .GOOGLE.
        echo json_encode([
            'PALM'   => device_terms_html(),
            'GOOGLE' => '',
        ]);
        break;

    case 'authenticateWeb':
        // Browser/PWA sign-in. Same verification and token issuance as
        // authenticateFromDevice, minus the HP protocol shapes. The client's
        // synthetic device_id makes the browser just another revocable
        // "device" under the one-token-per-device model.
        $login    = trim((string)($body['login'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $deviceId = trim((string)($body['device_id'] ?? ''));
        if ($login === '' || $password === '' || $deviceId === '' || strlen($deviceId) > 128) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_request',
                              'message' => 'login, password and device_id (max 128 chars) are required']);
            break;
        }
        $account = $repo->verifyDeviceLogin($login, $password);
        if (!$account) {
            http_response_code(401);
            echo json_encode(['error' => 'auth_failed',
                              'message' => 'Wrong login or password, or account disabled']);
            break;
        }
        $token = $repo->issueDeviceToken($account['id'], $deviceId, $_SERVER['HTTP_USER_AGENT'] ?? null);
        echo json_encode([
            'token'      => $token,
            'expires_at' => date('c', time() + 365 * 86400),
            'account'    => [
                'alias'        => $account['email'] ?: $account['username'],
                'display_name' => ($account['display_name'] ?? '') ?: ($account['email'] ?: $account['username']),
            ],
        ]);
        break;

    case 'refreshToken':
        // Trade a valid token for a fresh one (same account + device); the old
        // token stops working. Lets long-lived clients renew before the
        // 365-day expiry without re-asking for the password.
        $token = (string)($body['token'] ?? '');
        $new   = $token !== '' ? $repo->refreshDeviceToken($token, $_SERVER['HTTP_USER_AGENT'] ?? null) : null;
        if (!$new) {
            http_response_code(401);
            echo json_encode(['error' => 'invalid_token',
                              'message' => 'Token is invalid, expired, or the account is disabled']);
            break;
        }
        echo json_encode(['token' => $new, 'expires_at' => date('c', time() + 365 * 86400)]);
        break;

    case 'deauthenticate':
        // Sign-out: revoke this token. Idempotent — an already-dead token
        // still reports success so client sign-out never gets stuck.
        $token = (string)($body['token'] ?? '');
        if ($token !== '') {
            $repo->revokeDeviceToken($token);
        }
        echo json_encode(['deauthenticated' => true]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['JSONException' => 'UNKNOWN_METHOD']);
        break;
}

/** Community Terms of Service HTML shown on the device's firstuse Terms card. */
function device_terms_html() {
    return
        '<h2>webOS Archive Account &mdash; Terms of Service</h2>'
      . '<p>This device connects to the community-run webOS App Museum II at '
      . 'appcatalog.webosarchive.org. HP&rsquo;s original webOS services shut down in 2015; '
      . 'this is an independent preservation project and is not affiliated with, or endorsed '
      . 'by, HP or Palm.</p>'
      . '<h3>1. Your account</h3>'
      . '<p>A webOS Archive account is optional and is the same account used on the App Museum '
      . 'website. It lets you sign in on this device and, in the future, post ratings and '
      . 'reviews. You are responsible for keeping your password secure.</p>'
      . '<h3>2. Acceptable use</h3>'
      . '<p>Use the service lawfully and respectfully. Do not attempt to disrupt it, abuse other '
      . 'members, or upload unlawful content.</p>'
      . '<h3>3. Privacy</h3>'
      . '<p>We store only what is needed to run the service: your account details and a per-device '
      . 'sign-in token. We do not sell your data. Your device sends a device identifier so a lost '
      . 'or retired device&rsquo;s access can be revoked.</p>'
      . '<h3>4. No warranty</h3>'
      . '<p>This is a free, volunteer-run archive provided &ldquo;as is,&rdquo; without warranty of '
      . 'any kind. Service may change or end at any time.</p>'
      . '<h3>5. Contact</h3>'
      . '<p>Questions? Visit appcatalog.webosarchive.org. By tapping Accept you agree to these terms.</p>';
}
