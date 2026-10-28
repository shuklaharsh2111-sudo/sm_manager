<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
function jsonResponse(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}
function postValue(array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (isset($_POST[$key]) && !is_array($_POST[$key])) {
            return trim((string) $_POST[$key]);
        }
    }
    return $default;
}
try {
    // Only POST requests are accepted.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(405, [
            'success' => false,
            'message' => 'Only POST requests are allowed.'
        ]);
    }
    // Check login session.
    if (empty($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        jsonResponse(401, [
            'success' => false,
            'message' => 'Session expired. Please log in again.'
        ]);
    }
    // Support either a role string or a JSON role array.
    $sessionRole = $_SESSION['role'];
    if (is_array($sessionRole)) {
        $roles = $sessionRole;
    } else {
        $decodedRoles = json_decode((string) $sessionRole, true);
        $roles = is_array($decodedRoles)
            ? $decodedRoles
            : [(string) $sessionRole];
    }
    $roles = array_map(
        static fn($role) => strtolower(trim((string) $role)),
        $roles
    );
    if (!in_array('sme', $roles, true)) {
        jsonResponse(403, [
            'success' => false,
            'message' => 'Only SME users can add creatives.'
        ]);
    }
    // Database connection.
    require_once __DIR__ . '/../config/db.php';
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException(
            'Database connection $conn was not found in config/db.php.'
        );
    }
    $conn->set_charset('utf8mb4');
    $userId = (int) $_SESSION['user_id'];
    // Read form fields.
    $platform = strtolower(postValue(['platform']));
    $creativeDate = postValue([
        'creative_date',
        'selected_date',
        'date'
    ]);
    $title = postValue([
        'title',
        'creative_title'
    ]);
    $caption = postValue([
        'caption',
        'creative_caption'
    ]);
    $description = postValue([
        'description',
        'creative_description'
    ]);
    $referenceLink = postValue(['reference_link']);
    $keywords = postValue(['keywords']);
    $companyIdRaw = postValue(['company_id']);
    $sopIdRaw = postValue(['sop_id']);
    $companyId = $companyIdRaw === ''
        ? null
        : filter_var($companyIdRaw, FILTER_VALIDATE_INT);
    $sopId = $sopIdRaw === ''
        ? null
        : filter_var($sopIdRaw, FILTER_VALIDATE_INT);
    if (
        ($companyIdRaw !== '' &&
            ($companyId === false || $companyId <= 0)) ||
        ($sopIdRaw !== '' &&
            ($sopId === false || $sopId <= 0))
    ) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Invalid company or SOP ID.'
        ]);
    }
    if (
        $referenceLink !== '' &&
        (
            strlen($referenceLink) > 2048 ||
            !filter_var($referenceLink, FILTER_VALIDATE_URL) ||
            !in_array(
                strtolower((string) parse_url($referenceLink, PHP_URL_SCHEME)),
                ['http', 'https'],
                true
            )
        )
    ) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Please enter a valid reference link.'
        ]);
    }
    if (mb_strlen($keywords) > 2000) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Keywords cannot exceed 2000 characters.'
        ]);
    }
    if (mb_strlen($caption) > 5000) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Caption cannot exceed 5000 characters.'
        ]);
    }
    // Validate platform against the database ENUM.
    $allowedPlatforms = [
        'instagram',
        'facebook',
        'linkedin',
        'google',
        'youtube',
        'x',
        'pinterest',
        'threads'
    ];
    if (!in_array($platform, $allowedPlatforms, true)) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Please select a valid social media platform.'
        ]);
    }
    // Validate date.
    $dateObject = DateTime::createFromFormat('!Y-m-d', $creativeDate);
    $dateErrors = DateTime::getLastErrors();
    if (
        !$dateObject ||
        $dateObject->format('Y-m-d') !== $creativeDate ||
        (
            $dateErrors !== false &&
            (
                $dateErrors['warning_count'] > 0 ||
                $dateErrors['error_count'] > 0
            )
        )
    ) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Please select a valid creative date.'
        ]);
    }
    if ($title === '') {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Please enter the creative title.'
        ]);
    }
    if (mb_strlen($title) > 255) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Creative title cannot exceed 255 characters.'
        ]);
    }
    // Verify the logged-in user exists and has the SME role.
    $userStmt = $conn->prepare(
        'SELECT id, role FROM users WHERE id = ? LIMIT 1'
    );
    if (!$userStmt) {
        throw new RuntimeException('Could not prepare user validation.');
    }
    $userStmt->bind_param('i', $userId);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    $user = $userResult->fetch_assoc();
    $userStmt->close();
    if (!$user) {
        jsonResponse(401, [
            'success' => false,
            'message' => 'Your user account could not be verified.'
        ]);
    }
    $dbRoles = json_decode((string) $user['role'], true);
    if (
        !is_array($dbRoles) ||
        !in_array('sme', array_map(
            static fn($role) => strtolower(trim((string) $role)),
            $dbRoles
        ), true)
    ) {
        jsonResponse(403, [
            'success' => false,
            'message' => 'Your account does not have SME access.'
        ]);
    }
    // Locate the uploaded image.
    $fileKey = null;
    foreach (['creative_image', 'creative', 'image'] as $key) {
        if (isset($_FILES[$key])) {
            $fileKey = $key;
            break;
        }
    }
    if ($fileKey === null) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Please select a creative image.'
        ]);
    }
    $file = $_FILES[$fileKey];
    if (!isset($file['error']) || is_array($file['error'])) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Invalid image upload.'
        ]);
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadMessages = [
            UPLOAD_ERR_INI_SIZE =>
            'The image exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE =>
            'The image exceeds the allowed form upload size.',
            UPLOAD_ERR_PARTIAL =>
            'The image was only partially uploaded.',
            UPLOAD_ERR_NO_FILE =>
            'Please select a creative image.',
            UPLOAD_ERR_NO_TMP_DIR =>
            'The server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE =>
            'The server could not write the uploaded image.',
            UPLOAD_ERR_EXTENSION =>
            'The server blocked the image upload.'
        ];
        jsonResponse(422, [
            'success' => false,
            'message' => $uploadMessages[$file['error']]
                ?? 'Image upload failed.'
        ]);
    }
    if (
        empty($file['tmp_name']) ||
        !is_uploaded_file($file['tmp_name'])
    ) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Invalid uploaded image.'
        ]);
    }
    if ($file['size'] <= 0 || $file['size'] > 5 * 1024 * 1024) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Image must be 5 MB or smaller.'
        ]);
    }
    // Validate actual image content and MIME type.
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'The uploaded file is not a valid image.'
        ]);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $allowedImageTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];
    if (!isset($allowedImageTypes[$mimeType])) {
        jsonResponse(422, [
            'success' => false,
            'message' => 'Only JPG, PNG and WebP images are allowed.'
        ]);
    }
    // Create the upload directory if it does not exist.
    $uploadDirectory = __DIR__ . '/../uploads/creatives';
    if (
        !is_dir($uploadDirectory) &&
        !mkdir($uploadDirectory, 0755, true) &&
        !is_dir($uploadDirectory)
    ) {
        throw new RuntimeException(
            'Could not create uploads/creatives directory.'
        );
    }
    // Generate a unique filename.
    $fileName = bin2hex(random_bytes(16))
        . '.'
        . $allowedImageTypes[$mimeType];
    $absolutePath = $uploadDirectory
        . DIRECTORY_SEPARATOR
        . $fileName;
    $relativePath = 'uploads/creatives/' . $fileName;
    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
        throw new RuntimeException(
            'Could not move the uploaded image into the upload directory.'
        );
    }
    // Save the creative in the existing creatives table.
    // The database uses lowercase ENUM status values.
    $sql = "
    INSERT INTO creatives (
        user_id,
        company_id,
        sop_id,
        platform,
        creative_date,
        title,
        caption,
        description,
        image_path,
        reference_link,
        keywords,
        status
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        @unlink($absolutePath);
        throw new RuntimeException(
            'Could not prepare the creative database query.'
        );
    }
    $stmt->bind_param(
        'iiissssssss',
        $userId,
        $companyId,
        $sopId,
        $platform,
        $creativeDate,
        $title,
        $caption,
        $description,
        $relativePath,
        $referenceLink,
        $keywords
    );
    if (!$stmt->execute()) {
        $stmt->close();
        @unlink($absolutePath);
        throw new RuntimeException(
            'Could not insert the creative into the database.'
        );
    }
    $creativeId = (int) $stmt->insert_id;
    $stmt->close();
    jsonResponse(201, [
        'success' => true,
        'message' => 'Creative saved successfully.',
        'creative' => [
            'id' => $creativeId,
            'user_id' => $userId,
            'platform' => $platform,
            'creative_date' => $creativeDate,
            'title' => $title,
            'caption' => $caption,
            'description' => $description,
            'image_path' => $relativePath,
            'status' => 'pending'
        ]
    ]);
} catch (Throwable $e) {
    error_log('Add Creative Error: ' . $e->getMessage());
    jsonResponse(500, [
        'success' => false,
        'message' =>
        'Unable to save creative. Check the PHP error log.'
    ]);
}
