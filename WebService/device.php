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
 * A "webOS Account" IS a catalog `accounts` row; the device gets a per-device
 * token in `account_tokens` (device_id = nduid). Web self-signup stays disabled;
 * device signup is the sanctioned legacy-client path (admin can still disable an
 * account, which revokes device access via the accounts.status check).
 *
 * Transport: served over HTTP because the legacy device JS/TLS stack (libssl
 * 0.9.8) can't negotiate modern TLS. Device tokens are therefore treated as
 * low-trust — per-device, revocable, never the account password.
 */

require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/../includes/AccountRepository.php';

header('Content-Type: application/json');

// Throttle all device-auth traffic per IP (shares WebService/__rateLimit).
checkRateLimit(60, 3600);

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
        $token = $repo->issueDeviceToken($id, $deviceId, $_SERVER['HTTP_USER_AGENT'] ?? null);
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
        $token = $repo->issueDeviceToken($account['id'], $deviceId, $_SERVER['HTTP_USER_AGENT'] ?? null);
        echo json_encode(authenticate_info_ex($account, $token));
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
