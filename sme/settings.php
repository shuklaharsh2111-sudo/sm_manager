<?php
/*
 * settings.php - SME account settings (profile, password).
 *
 * Rendered inside sme.php (sme.php?page=settings). The page's own AJAX calls
 * post straight back to this file (settings_api=1) and receive JSON.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ------------------------------------------------------------------ */
/* Helpers                                                              */
/* ------------------------------------------------------------------ */

function st_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function st_fail(string $message, int $status = 400, array $errors = []): void
{
    $payload = ['success' => false, 'message' => $message];
    if ($errors) {
        $payload['errors'] = $errors;
    }
    st_json($payload, $status);
}

function st_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function st_initial(string $name): string
{
    $name = trim($name) !== '' ? trim($name) : 'User';
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return strtoupper(substr($name, 0, 1));
}

/* Returns a ../uploads/ URL when the file exists, otherwise an empty string. */
function st_image_url($filename): string
{
    $filename = trim((string) $filename);
    if ($filename === '') {
        return '';
    }
    $safe = basename(str_replace('\\', '/', $filename));
    if ($safe === '' || !is_file(__DIR__ . '/../uploads/' . $safe)) {
        return '';
    }
    return '../uploads/' . rawurlencode($safe);
}

function st_ensure_settings_table(mysqli $db): void
{
    $db->query(
        "CREATE TABLE IF NOT EXISTS user_settings (
            user_id INT NOT NULL PRIMARY KEY,
            notify_approved TINYINT(1) NOT NULL DEFAULT 1,
            notify_rejected TINYINT(1) NOT NULL DEFAULT 1,
            notify_revision TINYINT(1) NOT NULL DEFAULT 1,
            notify_meeting TINYINT(1) NOT NULL DEFAULT 1,
            clock_format VARCHAR(3) NOT NULL DEFAULT '24h',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function st_default_prefs(): array
{
    return [
        'notify_approved' => 1,
        'notify_rejected' => 1,
        'notify_revision' => 1,
        'notify_meeting'  => 1,
        'clock_format'    => '24h',
    ];
}

function st_load_prefs(mysqli $db, int $userId): array
{
    $prefs = st_default_prefs();
    $stmt = $db->prepare(
        'SELECT notify_approved, notify_rejected, notify_revision, notify_meeting, clock_format
         FROM user_settings WHERE user_id = ? LIMIT 1'
    );
    if (!$stmt) {
        return $prefs;
    }
    $stmt->bind_param('i', $userId);
    if ($stmt->execute()) {
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            foreach (['notify_approved', 'notify_rejected', 'notify_revision', 'notify_meeting'] as $key) {
                $prefs[$key] = (int) $row[$key] === 1 ? 1 : 0;
            }
            $prefs['clock_format'] = ($row['clock_format'] ?? '') === '12h' ? '12h' : '24h';
        }
    }
    $stmt->close();
    return $prefs;
}

function st_get_user(mysqli $db, int $userId): ?array
{
    $stmt = $db->prepare(
        'SELECT fullname, email, contact, prof_image, brand_name, reporting_manager
         FROM users WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/* True when another user already has this email or contact number. */
function st_value_taken(mysqli $db, string $column, string $value, int $userId): bool
{
    if (!in_array($column, ['email', 'contact'], true)) {
        st_fail('Invalid request.', 400);
    }
    $stmt = $db->prepare("SELECT id FROM users WHERE id <> ? AND $column = ? LIMIT 1");
    if (!$stmt) {
        st_fail('We could not check your details right now. Try again.', 500);
    }
    $stmt->bind_param('is', $userId, $value);
    if (!$stmt->execute()) {
        $stmt->close();
        st_fail('We could not check your details right now. Try again.', 500);
    }
    $taken = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $taken;
}

/*
 * Validates an uploaded photo without moving it.
 * Returns null (no file chosen), false (invalid, $error is set) or
 * ['tmp' => path, 'ext' => extension].
 */
function st_inspect_image(string $field, string &$error)
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }
    $file = $_FILES[$field];
    $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        $error = 'The photo must be 5 MB or smaller.';
        return false;
    }
    if ($code !== UPLOAD_ERR_OK) {
        $error = 'The photo could not be uploaded. Try again.';
        return false;
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        $error = 'The photo must be 5 MB or smaller.';
        return false;
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $error = 'The uploaded file is not valid.';
        return false;
    }
    if (!class_exists('finfo')) {
        $error = 'Photo upload is not available on this server.';
        return false;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($extensions[$mime]) || @getimagesize($tmp) === false) {
        $error = 'Use a JPG, PNG, WEBP or GIF image.';
        return false;
    }
    return ['tmp' => $tmp, 'ext' => $extensions[$mime]];
}

/* Moves a validated upload into /uploads. Returns the new filename or false. */
function st_store_image(array $image)
{
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $image['ext'];
    if (!move_uploaded_file($image['tmp'], $dir . DIRECTORY_SEPARATOR . $filename)) {
        return false;
    }
    return $filename;
}

/*
 * Deletes an old photo from disk, but only when it was created by an upload
 * (32 hex characters) and nothing else in the database still points at it.
 * Sample files such as img3.jpg are shared between records and never deleted.
 */
function st_delete_image_if_unused(mysqli $db, $filename): void
{
    $filename = basename(str_replace('\\', '/', (string) $filename));
    if (!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp|gif)$/', $filename)) {
        return;
    }
    $stmt = $db->prepare(
        'SELECT
            (SELECT COUNT(*) FROM users WHERE prof_image = ? OR brand_logo = ?)
          + (SELECT COUNT(*) FROM companies WHERE comp_logo = ?) AS uses_count'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('sss', $filename, $filename, $filename);
    if (!$stmt->execute()) {
        $stmt->close();
        return;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && (int) $row['uses_count'] === 0) {
        $path = __DIR__ . '/../uploads/' . $filename;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/* ------------------------------------------------------------------ */
/* Actions (AJAX)                                                       */
/* ------------------------------------------------------------------ */

function st_save_profile(mysqli $db, int $userId): void
{
    $current = st_get_user($db, $userId);
    if (!$current) {
        st_fail('We could not load your account. Sign in again.', 404);
    }

    $fullname = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['fullname'] ?? '')));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $contact = (string) preg_replace('/[\s\-().]+/', '', (string) ($_POST['contact'] ?? ''));
    $errors = [];

    if ($fullname === '') {
        $errors['fullname'] = 'Enter your full name.';
    } elseif (!preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .\'’-]{1,99}$/u', $fullname)) {
        $errors['fullname'] = 'Use 2 to 100 letters. Spaces, dots, hyphens and apostrophes are allowed.';
    }

    if ($email === '') {
        $errors['email'] = 'Enter your email address.';
    } elseif (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address, like name@example.com.';
    }

    if ($contact === '') {
        $errors['contact'] = 'Enter your contact number.';
    } elseif (!preg_match('/^\+?[0-9]{10,12}$/', $contact)) {
        $errors['contact'] = 'Enter 10 to 12 digits. You can start with +.';
    }

    if (!isset($errors['email']) && st_value_taken($db, 'email', $email, $userId)) {
        $errors['email'] = 'Another account already uses this email address.';
    }
    if (!isset($errors['contact']) && st_value_taken($db, 'contact', $contact, $userId)) {
        $errors['contact'] = 'Another account already uses this contact number.';
    }

    $imageError = '';
    $image = st_inspect_image('prof_image', $imageError);
    if ($image === false) {
        $errors['prof_image'] = $imageError;
    }

    if ($errors) {
        st_fail('Check the highlighted fields and try again.', 422, $errors);
    }

    $newImage = null;
    if (is_array($image)) {
        $stored = st_store_image($image);
        if ($stored === false) {
            st_fail('We could not save your photo. Try again.', 500);
        }
        $newImage = $stored;
    }

    $oldImage = trim((string) ($current['prof_image'] ?? ''));
    $imageName = $newImage !== null ? $newImage : ($oldImage !== '' ? $oldImage : null);

    $saved = false;
    $stmt = $db->prepare(
        'UPDATE users SET fullname = ?, email = ?, contact = ?, prof_image = ? WHERE id = ? LIMIT 1'
    );
    if ($stmt) {
        $stmt->bind_param('ssssi', $fullname, $email, $contact, $imageName, $userId);
        $saved = $stmt->execute();
        $stmt->close();
    }
    if (!$saved) {
        if ($newImage !== null) {
            @unlink(__DIR__ . '/../uploads/' . $newImage);
        }
        st_fail('We could not save your changes. Try again.', 500);
    }

    $_SESSION['fullname'] = $fullname;
    $_SESSION['email'] = $email;
    $_SESSION['contact'] = $contact;

    if ($newImage !== null && $oldImage !== '') {
        st_delete_image_if_unused($db, $oldImage);
    }

    st_json([
        'success' => true,
        'message' => 'Changes saved.',
        'data'    => [
            'fullname'  => $fullname,
            'email'     => $email,
            'contact'   => $contact,
            'initial'   => st_initial($fullname),
            'image_url' => st_image_url($imageName),
        ],
    ]);
}

function st_remove_photo(mysqli $db, int $userId): void
{
    $current = st_get_user($db, $userId);
    if (!$current) {
        st_fail('We could not load your account. Sign in again.', 404);
    }
    $oldImage = trim((string) ($current['prof_image'] ?? ''));

    $saved = false;
    $stmt = $db->prepare('UPDATE users SET prof_image = NULL WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $saved = $stmt->execute();
        $stmt->close();
    }
    if (!$saved) {
        st_fail('We could not remove your photo. Try again.', 500);
    }
    if ($oldImage !== '') {
        st_delete_image_if_unused($db, $oldImage);
    }

    st_json([
        'success' => true,
        'message' => 'Photo removed.',
        'data'    => [
            'fullname'  => (string) ($current['fullname'] ?? ''),
            'initial'   => st_initial((string) ($current['fullname'] ?? '')),
            'image_url' => '',
        ],
    ]);
}

function st_change_password(mysqli $db, int $userId): void
{
    $now = time();
    $lockUntil = (int) ($_SESSION['settings_pw_lock_until'] ?? 0);
    if ($lockUntil > $now) {
        $minutes = (int) ceil(($lockUntil - $now) / 60);
        st_fail(
            'Too many incorrect attempts. Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.',
            429
        );
    }

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $errors = [];

    if ($currentPassword === '') {
        $errors['current_password'] = 'Enter your current password.';
    }
    if ($newPassword === '') {
        $errors['new_password'] = 'Enter a new password.';
    } elseif (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
        $errors['new_password'] = 'Use 8 to 72 characters.';
    } elseif (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        $errors['new_password'] = 'Include at least one letter and one number.';
    }
    if ($confirmPassword === '') {
        $errors['confirm_password'] = 'Re-enter the new password.';
    } elseif ($newPassword !== $confirmPassword) {
        $errors['confirm_password'] = 'The passwords do not match.';
    }
    if ($errors) {
        st_fail('Check the highlighted fields and try again.', 422, $errors);
    }

    $stmt = $db->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        st_fail('We could not update your password. Try again.', 500);
    }
    $stmt->bind_param('i', $userId);
    $row = null;
    if ($stmt->execute()) {
        $row = $stmt->get_result()->fetch_assoc();
    }
    $stmt->close();
    if (!$row) {
        st_fail('We could not load your account. Sign in again.', 404);
    }
    $hash = (string) ($row['password'] ?? '');

    if ($hash === '' || !password_verify($currentPassword, $hash)) {
        $fails = (int) ($_SESSION['settings_pw_fails'] ?? 0) + 1;
        if ($fails >= 5) {
            $_SESSION['settings_pw_lock_until'] = $now + 300;
            $_SESSION['settings_pw_fails'] = 0;
            st_fail('Too many incorrect attempts. Try again in 5 minutes.', 429);
        }
        $_SESSION['settings_pw_fails'] = $fails;
        st_fail(
            'Check the highlighted fields and try again.',
            422,
            ['current_password' => 'That is not your current password.']
        );
    }

    if (password_verify($newPassword, $hash)) {
        st_fail(
            'Check the highlighted fields and try again.',
            422,
            ['new_password' => 'Choose a password that is different from your current one.']
        );
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $saved = false;
    if (is_string($newHash) && $newHash !== '') {
        $update = $db->prepare('UPDATE users SET password = ? WHERE id = ? LIMIT 1');
        if ($update) {
            $update->bind_param('si', $newHash, $userId);
            $saved = $update->execute();
            $update->close();
        }
    }
    if (!$saved) {
        st_fail('We could not update your password. Try again.', 500);
    }

    unset($_SESSION['settings_pw_fails'], $_SESSION['settings_pw_lock_until']);
    session_regenerate_id(true);

    st_json(['success' => true, 'message' => 'Password updated.']);
}

/* ------------------------------------------------------------------ */
/* Request handling                                                     */
/* ------------------------------------------------------------------ */

$stIsDirect = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);
$stIsAjax = $stIsDirect
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (isset($_POST['settings_api'])
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

// Opening this file in the browser directly sends the person to the settings page.
if ($stIsDirect && !$stIsAjax) {
    header('Location: sme.php?page=settings');
    exit;
}

$stUserId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
$stRole = strtolower(trim((string) ($_SESSION['role'] ?? '')));
if (!$stUserId || $stUserId <= 0 || $stRole !== 'sme') {
    if ($stIsAjax) {
        st_fail('Your session has expired. Sign in again.', 401);
    }
    echo '<p>Sign in again to open your settings.</p>';
    return;
}

require_once __DIR__ . '/../config/db.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    if ($stIsAjax) {
        st_fail('The database connection was not found. Check config/db.php.', 500);
    }
    echo '<p>The database connection was not found. Check config/db.php.</p>';
    return;
}

if (empty($_SESSION['settings_csrf_token'])) {
    $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
}

if ($stIsAjax) {
    // A body larger than post_max_size arrives empty.
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        st_fail('That upload is too large. Choose a photo under 5 MB.', 413);
    }
    $stSentToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['settings_csrf_token'], $stSentToken)) {
        st_fail('Your security token has expired. Refresh the page and try again.', 403);
    }

    switch ((string) ($_POST['action'] ?? '')) {
        case 'save_profile':
            st_save_profile($conn, $stUserId);
            break;
        case 'remove_photo':
            st_remove_photo($conn, $stUserId);
            break;
        case 'change_password':
            st_change_password($conn, $stUserId);
            break;
        default:
            st_fail('Unknown request.', 400);
    }
    exit;
}

/* ------------------------------------------------------------------ */
/* Page data                                                            */
/* ------------------------------------------------------------------ */

$stUser = st_get_user($conn, $stUserId);
if (!$stUser) {
    echo '<p>We could not load your account. Sign in again.</p>';
    return;
}
$stFullname = (string) ($stUser['fullname'] ?? '');
$stImageUrl = st_image_url($stUser['prof_image'] ?? '');
$stManager = trim((string) ($stUser['reporting_manager'] ?? ''));
$stBrand = trim((string) ($stUser['brand_name'] ?? ''));

$st = [
    'csrf'       => st_e($_SESSION['settings_csrf_token']),
    'fullname'   => st_e($stFullname),
    'email'      => st_e($stUser['email'] ?? ''),
    'contact'    => st_e($stUser['contact'] ?? ''),
    'initial'    => st_e(st_initial($stFullname)),
    'image_url'  => st_e($stImageUrl),
    'has_image'  => $stImageUrl !== '' ? '1' : '0',
    'photo_btn'  => $stImageUrl !== '' ? 'Change photo' : 'Upload photo',
    'manager'    => st_e($stManager !== '' ? $stManager : 'Not assigned yet'),
    'brand'      => st_e($stBrand !== '' ? $stBrand : 'Unstoppable Solutions'),
];
$stHas = ['image' => $stImageUrl !== ''];
?>
<?php /* view */ ?>
<section class="stg" id="smeSettings" data-endpoint="settings.php" data-csrf="<?= $st['csrf'] ?>">
    <div class="stg-head">
        <h2>Settings</h2>
        <p>Update your profile and change your password.</p>
    </div>

    <div class="stg-layout">
        <div class="stg-tabs" role="tablist" aria-label="Settings sections">
            <button type="button" class="stg-tab is-active" role="tab" id="stgTabProfile" data-panel="profile" aria-controls="stgPanelProfile" aria-selected="true">
                <span class="stg-tab-title">Profile</span>
                <span class="stg-tab-sub">Photo, name and contact</span>
            </button>
            <button type="button" class="stg-tab" role="tab" id="stgTabPassword" data-panel="password" aria-controls="stgPanelPassword" aria-selected="false" tabindex="-1">
                <span class="stg-tab-title">Password</span>
                <span class="stg-tab-sub">Change how you sign in</span>
            </button>
        </div>

        <div class="stg-panels">
            <!-- Profile -->
            <div class="stg-panel" id="stgPanelProfile" role="tabpanel" aria-labelledby="stgTabProfile" data-panel="profile" tabindex="0">
                <form class="stg-card" id="stgProfileForm" method="post" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="action" value="save_profile">
                    <h3 class="stg-card-title">Your profile</h3>
                    <p class="stg-card-note">This is how your team sees you in Creative Manager.</p>

                    <div class="stg-photo">
                        <div class="stg-avatar" id="stgAvatar" data-image="<?= $st['has_image'] ?>">
                            <?php if ($stHas['image']): ?>
                                <img src="<?= $st['image_url'] ?>" alt="Your profile photo">
                            <?php else: ?>
                                <span class="stg-avatar-initial"><?= $st['initial'] ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="stg-photo-body">
                            <p class="stg-photo-title">Profile photo</p>
                            <p class="stg-hint">JPG, PNG, WEBP or GIF, up to 5 MB.</p>
                            <div class="stg-photo-actions">
                                <input class="stg-file" type="file" id="stgPhotoInput" name="prof_image" accept="image/jpeg,image/png,image/webp,image/gif" aria-describedby="stgPhotoError stgPhotoNote">
                                <label class="stg-btn stg-btn-secondary" for="stgPhotoInput" id="stgPhotoLabel"><?= $st['photo_btn'] ?></label>
                                <button type="button" class="stg-btn stg-btn-ghost" id="stgRemovePhoto"<?php if (!$stHas['image']): ?> hidden<?php endif; ?>>Remove photo</button>
                            </div>
                            <p class="stg-note" id="stgPhotoNote" hidden>New photo selected. Select Save changes to apply it.</p>
                            <p class="stg-error" id="stgPhotoError" data-error-for="prof_image"></p>
                        </div>
                    </div>

                    <div class="stg-grid">
                        <div class="stg-field stg-field-wide">
                            <label class="stg-label" for="stgFullname">Full name</label>
                            <input class="stg-input" type="text" id="stgFullname" name="fullname" value="<?= $st['fullname'] ?>" maxlength="100" autocomplete="name" required aria-describedby="stgFullnameError">
                            <p class="stg-error" id="stgFullnameError" data-error-for="fullname"></p>
                        </div>
                        <div class="stg-field">
                            <label class="stg-label" for="stgEmail">Email address</label>
                            <input class="stg-input" type="email" id="stgEmail" name="email" value="<?= $st['email'] ?>" maxlength="255" autocomplete="email" required aria-describedby="stgEmailError">
                            <p class="stg-error" id="stgEmailError" data-error-for="email"></p>
                        </div>
                        <div class="stg-field">
                            <label class="stg-label" for="stgContact">Contact number</label>
                            <input class="stg-input" type="tel" id="stgContact" name="contact" value="<?= $st['contact'] ?>" maxlength="16" inputmode="tel" autocomplete="tel" required aria-describedby="stgContactHint stgContactError">
                            <p class="stg-hint" id="stgContactHint">You can also sign in with this number.</p>
                            <p class="stg-error" id="stgContactError" data-error-for="contact"></p>
                        </div>
                    </div>

                    <div class="stg-actions">
                        <button type="submit" class="stg-btn stg-btn-primary" data-label="Save changes">Save changes</button>
                    </div>
                </form>

                <section class="stg-card stg-card-quiet">
                    <h3 class="stg-card-title">Work details</h3>
                    <p class="stg-card-note">Your admin manages these. Ask them if something needs to change.</p>
                    <dl class="stg-details">
                        <div><dt>Role</dt><dd>SME (Social Media Executive)</dd></div>
                        <div><dt>Department</dt><dd>Social Media</dd></div>
                        <div><dt>Reporting manager</dt><dd><?= $st['manager'] ?></dd></div>
                        <div><dt>Brand</dt><dd><?= $st['brand'] ?></dd></div>
                    </dl>
                </section>
            </div>

            <!-- Password -->
            <div class="stg-panel" id="stgPanelPassword" role="tabpanel" aria-labelledby="stgTabPassword" data-panel="password" tabindex="0" hidden>
                <form class="stg-card" id="stgPasswordForm" method="post" novalidate>
                    <input type="hidden" name="action" value="change_password">
                    <h3 class="stg-card-title">Change password</h3>
                    <p class="stg-card-note">Choose a password you do not use on any other site.</p>

                    <div class="stg-stack">
                        <div class="stg-field">
                            <label class="stg-label" for="stgCurrentPassword">Current password</label>
                            <div class="stg-password">
                                <input class="stg-input" type="password" id="stgCurrentPassword" name="current_password" autocomplete="current-password" aria-describedby="stgCurrentPasswordError">
                                <button type="button" class="stg-reveal" data-reveal="stgCurrentPassword" aria-pressed="false" aria-label="Show current password">Show</button>
                            </div>
                            <p class="stg-error" id="stgCurrentPasswordError" data-error-for="current_password"></p>
                        </div>

                        <div class="stg-field">
                            <label class="stg-label" for="stgNewPassword">New password</label>
                            <div class="stg-password">
                                <input class="stg-input" type="password" id="stgNewPassword" name="new_password" autocomplete="new-password" aria-describedby="stgStrengthLabel stgNewPasswordError">
                                <button type="button" class="stg-reveal" data-reveal="stgNewPassword" aria-pressed="false" aria-label="Show new password">Show</button>
                            </div>
                            <div class="stg-strength" id="stgStrength" data-level="0">
                                <div class="stg-strength-bar"><span></span></div>
                                <span class="stg-strength-label" id="stgStrengthLabel">Enter a password to see its strength.</span>
                            </div>
                            <ul class="stg-reqs" id="stgReqs">
                                <li data-req="length">At least 8 characters</li>
                                <li data-req="letter">One letter</li>
                                <li data-req="number">One number</li>
                            </ul>
                            <p class="stg-error" id="stgNewPasswordError" data-error-for="new_password"></p>
                        </div>

                        <div class="stg-field">
                            <label class="stg-label" for="stgConfirmPassword">Confirm new password</label>
                            <div class="stg-password">
                                <input class="stg-input" type="password" id="stgConfirmPassword" name="confirm_password" autocomplete="new-password" aria-describedby="stgConfirmPasswordError">
                                <button type="button" class="stg-reveal" data-reveal="stgConfirmPassword" aria-pressed="false" aria-label="Show confirmation password">Show</button>
                            </div>
                            <p class="stg-error" id="stgConfirmPasswordError" data-error-for="confirm_password"></p>
                        </div>
                    </div>

                    <div class="stg-actions">
                        <button type="submit" class="stg-btn stg-btn-primary" data-label="Update password">Update password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="stg-toast" id="stgToast" role="status" aria-live="polite"></div>
</section>
