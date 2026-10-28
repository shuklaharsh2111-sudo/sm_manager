
<?php


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function sendJson(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    sendJson(405, [
        'success' => false,
        'message' => 'Only POST requests are allowed.'
    ]);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    sendJson(401, [
        'success' => false,
        'message' => 'Your session expired. Please log in again.'
    ]);
}

$sessionToken = (string) ($_SESSION['des_calendar_csrf'] ?? '');
$postedToken = (string) ($_POST['csrf_token'] ?? '');

if (
    $sessionToken === '' ||
    $postedToken === '' ||
    !hash_equals($sessionToken, $postedToken)
) {
    sendJson(403, [
        'success' => false,
        'message' => 'Security token expired. Refresh the page and try again.'
    ]);
}

require_once __DIR__ . '/../config/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    sendJson(500, [
        'success' => false,
        'message' => 'Database connection is unavailable.'
    ]);
}

$date = trim((string) ($_POST['creative_date'] ?? ''));
$title = trim((string) ($_POST['title'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$caption = trim((string) ($_POST['caption'] ?? ''));
$platform = strtolower(trim((string) ($_POST['platform'] ?? 'instagram')));

$allowedPlatforms = [
    'instagram', 'facebook', 'linkedin', 'google',
    'youtube', 'x', 'pinterest', 'threads'
];

$dateObject = DateTime::createFromFormat('!Y-m-d', $date);
$dateErrors = DateTime::getLastErrors();

if (
    !$dateObject ||
    ($dateErrors !== false &&
        ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
    $dateObject->format('Y-m-d') !== $date
) {
    sendJson(422, [
        'success' => false,
        'message' => 'Please select a valid calendar date.'
    ]);
}

if ($title === '' || mb_strlen($title) > 255) {
    sendJson(422, [
        'success' => false,
        'message' => 'Title is required and must be within 255 characters.'
    ]);
}

if (!in_array($platform, $allowedPlatforms, true)) {
    sendJson(422, [
        'success' => false,
        'message' => 'Please select a valid platform.'
    ]);
}

if (
    strlen($description) > 100000 ||
    strlen($caption) > 100000
) {
    sendJson(422, [
        'success' => false,
        'message' => 'Description or caption is too long.'
    ]);
}

/*
 * Optional company selection:
 * A company is only accepted if it is assigned to this user
 * through sme_company_assignments.
 */
$companyId = null;

if (isset($_POST['company_id']) && $_POST['company_id'] !== '') {
    $requestedCompany = filter_var(
        $_POST['company_id'],
        FILTER_VALIDATE_INT
    );

    if (!$requestedCompany || $requestedCompany <= 0) {
        sendJson(422, [
            'success' => false,
            'message' => 'Invalid company selection.'
        ]);
    }

    $companyCheck = $conn->prepare(
        'SELECT company_id
         FROM sme_company_assignments
         WHERE sme_user_id = ? AND company_id = ?
         LIMIT 1'
    );

    if (!$companyCheck) {
        sendJson(500, [
            'success' => false,
            'message' => 'Unable to validate the company.'
        ]);
    }

    $companyCheck->bind_param('ii', $userId, $requestedCompany);
    $companyCheck->execute();
    $companyResult = $companyCheck->get_result();
    $companyAllowed = $companyResult->fetch_assoc();
    $companyCheck->close();

    if (!$companyAllowed) {
        sendJson(403, [
            'success' => false,
            'message' => 'You are not assigned to that company.'
        ]);
    }

    $companyId = (int) $requestedCompany;
}

/* Require a valid image upload. */
if (
    !isset($_FILES['creative_image']) ||
    !is_array($_FILES['creative_image']) ||
    $_FILES['creative_image']['error'] !== UPLOAD_ERR_OK
) {
    sendJson(422, [
        'success' => false,
        'message' => 'Please choose an image to upload.'
    ]);
}

$file = $_FILES['creative_image'];

if (
    !isset($file['size']) ||
    (int) $file['size'] <= 0 ||
    (int) $file['size'] > 5 * 1024 * 1024
) {
    sendJson(422, [
        'success' => false,
        'message' => 'Image size must be between 1 byte and 5 MB.'
    ]);
}

if (!is_uploaded_file($file['tmp_name'])) {
    sendJson(422, [
        'success' => false,
        'message' => 'Invalid image upload.'
    ]);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

$allowedImages = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif'
];

if (
    !isset($allowedImages[$mime]) ||
    @getimagesize($file['tmp_name']) === false
) {
    sendJson(422, [
        'success' => false,
        'message' => 'Only valid JPG, PNG, WEBP or GIF images are allowed.'
    ]);
}

/*
 * The endpoint is located in the des directory.
 * Files are stored in the project root uploads/creatives directory.
 */
$uploadDirectory = dirname(__DIR__) . '/uploads/creatives';

if (
    !is_dir($uploadDirectory) &&
    !mkdir($uploadDirectory, 0755, true) &&
    !is_dir($uploadDirectory)
) {
    sendJson(500, [
        'success' => false,
        'message' => 'Unable to create the image upload directory.'
    ]);
}

if (!is_writable($uploadDirectory)) {
    sendJson(500, [
        'success' => false,
        'message' => 'The image upload directory is not writable.'
    ]);
}

$filename = bin2hex(random_bytes(16)) . '.' . $allowedImages[$mime];
$absolutePath = $uploadDirectory . '/' . $filename;
$relativePath = 'uploads/creatives/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
    sendJson(500, [
        'success' => false,
        'message' => 'Could not save the uploaded image.'
    ]);
}

$conn->begin_transaction();

try {
    /*
     * New creatives are initially saved as drafts.
     * user_id is the logged-in user.
     */
    $insert = $conn->prepare(
        'INSERT INTO creatives
            (user_id, company_id, sop_id, platform, creative_date,
             title, caption, description, image_path, status)
         VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, "draft")'
    );

    if (!$insert) {
        throw new RuntimeException('Could not prepare creative insert.');
    }

    $insert->bind_param(
        'iissssss',
        $userId,
        $companyId,
        $platform,
        $date,
        $title,
        $caption,
        $description,
        $relativePath
    );

    if (!$insert->execute()) {
        throw new RuntimeException('Could not save the creative.');
    }

    $creativeId = (int) $conn->insert_id;
    $insert->close();

    /*
     * The calendar query reads creative_des_assignments.
     * Assign this creative to the current user so it appears there.
     *
     * If this endpoint is used by a DES who creates creatives for
     * a different SMI, add an SMI selector and validate that user
     * before inserting this assignment.
     */
    $assignment = $conn->prepare(
        'INSERT INTO creative_des_assignments
            (creative_id, smi_user_id, assigned_by)
         VALUES (?, ?, ?)'
    );

    if (!$assignment) {
        throw new RuntimeException('Could not prepare assignment insert.');
    }

    $assignment->bind_param('iii', $creativeId, $userId, $userId);

    if (!$assignment->execute()) {
        throw new RuntimeException(
            'Could not create the calendar assignment. ' .
            $assignment->error
        );
    }

    $assignment->close();
    $conn->commit();

    sendJson(201, [
        'success' => true,
        'message' => 'Creative added successfully!',
        'creative' => [
            'id' => $creativeId,
            'date' => $date,
            'title' => $title,
            'caption' => $caption,
            'description' => $description,
            'image' => $relativePath,
            'reference' => '',
            'platform' => $platform,
            'status' => 'draft',
            'company' => 'Unassigned Company',
            'company_logo' => '',
            'creator' => (string) ($_SESSION['fullname'] ?? 'You'),
            'assigned_at' => date('Y-m-d H:i:s')
        ]
    ]);
} catch (Throwable $exception) {
    $conn->rollback();

    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }

    error_log('Add creative error: ' . $exception->getMessage());

    sendJson(500, [
        'success' => false,
        'message' => 'Creative could not be saved. Check the PHP error log.'
    ]);
}
