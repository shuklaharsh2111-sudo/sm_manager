<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// The SME wrapper (sme.php) has already sent the no-cache headers
// before rendering the dashboard. Only set them here when this endpoint is
// requested directly (for calendar AJAX requests), not when included by sme.php.
$isDirectCalendarRequest = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);
if ($isDirectCalendarRequest) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
require_once __DIR__ . '/../config/db.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection is not available. Check config/db.php.');
}
mysqli_set_charset($conn, 'utf8mb4');
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$rawRole = $_SESSION['role'] ?? '';
$decodedRole = json_decode((string)$rawRole, true);
$roles = is_array($decodedRole)
    ? array_map('strtolower', $decodedRole)
    : [strtolower(trim((string)$rawRole))];
$roles = array_values(array_filter($roles));
$isAdmin = (bool)array_intersect($roles, ['admin', 'superadmin']);
if ($currentUserId <= 0) {
    if (isset($_GET['calendar_ajax']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
        exit;
    }
    header('Location: ../index.php');
    exit;
}
function calendarJson(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function calendarCanAccess(
    mysqli $conn,
    int $creativeId,
    int $userId,
    array $roles
): ?array {
    $stmt = $conn->prepare(
        "SELECT cr.*, c.comp_name
         FROM creatives cr
         LEFT JOIN companies c ON c.id = cr.company_id
         WHERE cr.id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $creativeId);
    $stmt->execute();
    $creative = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$creative) {
        return null;
    }
    if (array_intersect($roles, ['admin', 'superadmin'])) {
        return $creative;
    }
    if (in_array('sme', $roles, true)) {
        if (empty($creative['company_id'])) {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT id
             FROM sme_company_assignments
             WHERE sme_user_id = ? AND company_id = ?
             LIMIT 1"
        );
        $companyId = (int)$creative['company_id'];
        $stmt->bind_param('ii', $userId, $companyId);
        $stmt->execute();
        $allowed = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $allowed ? $creative : null;
    }
    return (int)$creative['user_id'] === $userId
        ? $creative
        : null;
}
/*
|--------------------------------------------------------------------------
| AJAX: Update an approved creative and upload its image
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'upload_approved_creative'
) {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['calendar_csrf_token'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        calendarJson(['success' => false, 'message' => 'Security token expired. Refresh the page.'], 403);
    }
    $creativeId = filter_var($_POST['creative_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$creativeId || $creativeId <= 0) {
        calendarJson(['success' => false, 'message' => 'Invalid creative ID.'], 422);
    }
    $creative = calendarCanAccess($conn, $creativeId, $currentUserId, $roles);
    if (!$creative) {
        calendarJson(['success' => false, 'message' => 'Creative not found or access denied.'], 404);
    }
    if ($creative['status'] !== 'approved') {
        calendarJson(['success' => false, 'message' => 'Only approved creatives can be uploaded.'], 403);
    }
    $platforms = [
        'instagram',
        'facebook',
        'linkedin',
        'google',
        'youtube',
        'x',
        'pinterest',
        'threads'
    ];
    $platform = strtolower(trim((string)($_POST['platform'] ?? '')));
    $title = trim((string)($_POST['title'] ?? ''));
    $creativeDate = trim((string)($_POST['creative_date'] ?? ''));
    $caption = trim((string)($_POST['caption'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $referenceLink = trim((string)($_POST['reference_link'] ?? ''));
    $keywords = trim((string)($_POST['keywords'] ?? ''));
    if (!in_array($platform, $platforms, true)) {
        calendarJson(['success' => false, 'message' => 'Select a valid social media platform.'], 422);
    }
    if ($title === '' || mb_strlen($title) > 255) {
        calendarJson(['success' => false, 'message' => 'Enter a title of up to 255 characters.'], 422);
    }
    $dateObject = DateTime::createFromFormat('!Y-m-d', $creativeDate);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $creativeDate) {
        calendarJson(['success' => false, 'message' => 'Select a valid creative date.'], 422);
    }
    if (mb_strlen($referenceLink) > 2048) {
        calendarJson(['success' => false, 'message' => 'Reference link is too long.'], 422);
    }
    if (
        $referenceLink !== ''
        && !filter_var($referenceLink, FILTER_VALIDATE_URL)
    ) {
        calendarJson(['success' => false, 'message' => 'Enter a valid reference URL.'], 422);
    }
    if (
        mb_strlen($caption) > 60000 || mb_strlen($description) > 60000
        || mb_strlen($keywords) > 60000
    ) {
        calendarJson(['success' => false, 'message' => 'One of the text fields is too long.'], 422);
    }
    if (
        !isset($_FILES['creative_image'])
        || $_FILES['creative_image']['error'] !== UPLOAD_ERR_OK
    ) {
        calendarJson(['success' => false, 'message' => 'Please select an image to upload.'], 422);
    }
    $image = $_FILES['creative_image'];
    if ($image['size'] <= 0 || $image['size'] > 5 * 1024 * 1024) {
        calendarJson(['success' => false, 'message' => 'Image size must be under 5 MB.'], 422);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($image['tmp_name']);
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif'
    ];
    if (!isset($allowedMimes[$mime]) || @getimagesize($image['tmp_name']) === false) {
        calendarJson(['success' => false, 'message' => 'Only valid JPG, PNG, WEBP or GIF images are allowed.'], 422);
    }
    $uploadDirectory = dirname(__DIR__) . '/uploads/creatives';
    if (
        !is_dir($uploadDirectory)
        && !mkdir($uploadDirectory, 0755, true)
        && !is_dir($uploadDirectory)
    ) {
        calendarJson(['success' => false, 'message' => 'Could not create the upload directory.'], 500);
    }
    if (!is_writable($uploadDirectory)) {
        calendarJson(['success' => false, 'message' => 'The uploads/creatives directory is not writable.'], 500);
    }
    try {
        $fileName = bin2hex(random_bytes(16)) . '.' . $allowedMimes[$mime];
    } catch (Throwable $e) {
        calendarJson(['success' => false, 'message' => 'Could not generate an image filename.'], 500);
    }
    $destination = $uploadDirectory . '/' . $fileName;
    $newImagePath = 'uploads/creatives/' . $fileName;
    if (!move_uploaded_file($image['tmp_name'], $destination)) {
        calendarJson(['success' => false, 'message' => 'Image upload failed. Check folder permissions.'], 500);
    }
    /*
     * Keep the original approval status.
     * Only replace the old image after the database update succeeds.
     */
    $stmt = $conn->prepare(
        "UPDATE creatives
         SET platform = ?,
             creative_date = ?,
             title = ?,
             caption = ?,
             description = ?,
             reference_link = ?,
             keywords = ?,
             image_path = ?
         WHERE id = ? AND status = 'approved'"
    );
    $stmt->bind_param(
        'ssssssssi',
        $platform,
        $creativeDate,
        $title,
        $caption,
        $description,
        $referenceLink,
        $keywords,
        $newImagePath,
        $creativeId
    );
    try {
        $updated = $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        $updated = false;
    }
    if (!$updated) {
        if (is_file($destination)) {
            unlink($destination);
        }
        calendarJson(['success' => false, 'message' => 'Could not update the creative. Please try again.'], 500);
    }
    /*
     * Delete the previous image only if it is inside the expected folder.
     */
    $oldImage = (string)($creative['image_path'] ?? '');
    if ($oldImage !== '' && strpos($oldImage, 'uploads/creatives/') === 0) {
        $oldAbsolutePath = dirname(__DIR__) . '/' . $oldImage;
        $expectedDirectory = realpath($uploadDirectory);
        $oldRealPath = realpath($oldAbsolutePath);
        if (
            $expectedDirectory !== false
            && $oldRealPath !== false
            && dirname($oldRealPath) === $expectedDirectory
            && $oldRealPath !== realpath($destination)
            && is_file($oldRealPath)
        ) {
            @unlink($oldRealPath);
        }
    }
    calendarJson([
        'success' => true,
        'message' => 'Creative updated and image uploaded successfully.',
        'creative_id' => (int)$creativeId,
        'image_path' => $newImagePath
    ]);
}
/*
|--------------------------------------------------------------------------
| AJAX: Fetch creatives for a platform and calendar month
|--------------------------------------------------------------------------
*/
if (isset($_GET['calendar_ajax'])) {
    $platform = strtolower(trim((string)($_GET['platform'] ?? 'instagram')));
    $month = trim((string)($_GET['month'] ?? date('Y-m')));
    $platforms = [
        'instagram',
        'facebook',
        'linkedin',
        'google',
        'youtube',
        'x',
        'pinterest',
        'threads'
    ];
    if (
        !in_array($platform, $platforms, true)
        || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
    ) {
        calendarJson(['success' => false, 'message' => 'Invalid calendar filter.'], 422);
    }
    $monthStart = $month . '-01';
    $nextMonth = date('Y-m-d', strtotime($monthStart . ' +1 month'));
    $sql = "
        SELECT
            cr.id,
            cr.user_id,
            cr.company_id,
            cr.sop_id,
            cr.platform,
            cr.creative_date,
            cr.title,
            cr.caption,
            cr.description,
            cr.image_path,
            cr.reference_link,
            cr.keywords,
            cr.status,
            cr.revision_reason,
            cr.created_at,
            c.comp_name
        FROM creatives cr
        LEFT JOIN companies c ON c.id = cr.company_id
        WHERE cr.platform = ?
          AND cr.creative_date >= ?
          AND cr.creative_date < ?
    ";
    if (!$isAdmin) {
        if (in_array('sme', $roles, true)) {
            $sql .= "
                AND cr.company_id IN (
                    SELECT sca.company_id
                    FROM sme_company_assignments sca
                    WHERE sca.sme_user_id = ?
                )
            ";
        } else {
            $sql .= " AND cr.user_id = ? ";
        }
    }
    $sql .= " ORDER BY cr.creative_date ASC, cr.id DESC";
    $stmt = $conn->prepare($sql);
    if (!$isAdmin) {
        $stmt->bind_param(
            'sssi',
            $platform,
            $monthStart,
            $nextMonth,
            $currentUserId
        );
    } else {
        $stmt->bind_param('sss', $platform, $monthStart, $nextMonth);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $creatives = [];
    while ($row = $result->fetch_assoc()) {
        $creatives[] = [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'company_id' => $row['company_id'] !== null ? (int)$row['company_id'] : null,
            'sop_id' => $row['sop_id'] !== null ? (int)$row['sop_id'] : null,
            'platform' => $row['platform'],
            'creative_date' => $row['creative_date'],
            'title' => $row['title'],
            'caption' => $row['caption'] ?? '',
            'description' => $row['description'] ?? '',
            'image_path' => $row['image_path'] ?? '',
            'reference_link' => $row['reference_link'] ?? '',
            'keywords' => $row['keywords'] ?? '',
            'status' => $row['status'],
            'revision_reason' => $row['revision_reason'] ?? '',
            'created_at' => $row['created_at'],
            'company_name' => $row['comp_name'] ?? 'Unassigned company'
        ];
    }
    $stmt->close();
    calendarJson([
        'success' => true,
        'platform' => $platform,
        'month' => $month,
        'creatives' => $creatives
    ]);
}
if (empty($_SESSION['calendar_csrf_token'])) {
    $_SESSION['calendar_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['calendar_csrf_token'];
?>
<?php if ($isDirectCalendarRequest): ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#090b18">
    <title>Creative Calendar | Creative Manager</title>
    <link rel="stylesheet" href="../assets/css/calendar.css">
</head>

<body class="calendar-standalone">
<?php else: ?>
<?php /* Included inside sme.php: output a fragment only (no second <html>/<body>). */ ?>
<link rel="stylesheet" href="../assets/css/calendar.css">
<?php endif; ?>
    <div class="calendar-page" id="calendarPage">
        <div class="calendar-app">
            <header class="calendar-header">
                <div class="header-copy">
                    <div class="eyebrow"><span class="eyebrow-dot"></span> CREATIVE WORKSPACE</div>
                    <h1>Social <span>Creative Calendar</span></h1>
                    <p>Plan, review and upload your approved social media creatives.</p>
                </div>
                <div class="header-date">
                    <span class="header-date-label">TODAY</span>
                    <strong id="todayLabel">Loading...</strong>
                </div>
            </header>
            <section class="stats-grid" aria-label="Creative statistics">
                <article class="stat-card">
                    <span class="stat-icon purple-icon"><i class="icon-calendar"></i></span>
                    <div><span class="stat-label">Total Creatives</span><strong id="totalCount">0</strong></div>
                </article>
                <article class="stat-card">
                    <span class="stat-icon blue-icon"><i class="icon-clock"></i></span>
                    <div><span class="stat-label">Pending Review</span><strong id="pendingCount">0</strong></div>
                </article>
                <article class="stat-card">
                    <span class="stat-icon green-icon"><i class="icon-check"></i></span>
                    <div><span class="stat-label">Approved</span><strong id="approvedCount">0</strong></div>
                </article>
                <article class="stat-card">
                    <span class="stat-icon pink-icon"><i class="icon-upload"></i></span>
                    <div><span class="stat-label">Ready to Upload</span><strong id="readyCount">0</strong></div>
                </article>
            </section>
            <section class="platform-panel">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">YOUR CHANNELS</span>
                        <h2>Social handles</h2>
                    </div>
                    <span class="platform-hint">Select a platform to view its calendar</span>
                </div>
                <div class="platform-tabs" id="platformTabs" role="tablist" aria-label="Social media platforms">
                <button class="platform-tab active" type="button" data-platform="instagram" role="tab" aria-selected="true"><span class="platform-symbol instagram-symbol">◎</span>Instagram</button>
                <button class="platform-tab" type="button" data-platform="facebook" role="tab" aria-selected="false"><span class="platform-symbol facebook-symbol">f</span>Facebook</button>
                <button class="platform-tab" type="button" data-platform="linkedin" role="tab" aria-selected="false"><span class="platform-symbol linkedin-symbol">in</span>LinkedIn</button>
                <button class="platform-tab" type="button" data-platform="google" role="tab" aria-selected="false"><span class="platform-symbol google-symbol">G</span>Google</button>
                <button class="platform-tab" type="button" data-platform="youtube" role="tab" aria-selected="false"><span class="platform-symbol youtube-symbol">▶</span>YouTube</button>
                <button class="platform-tab" type="button" data-platform="x" role="tab" aria-selected="false"><span class="platform-symbol x-symbol">𝕏</span>X</button>
                <button class="platform-tab" type="button" data-platform="pinterest" role="tab" aria-selected="false"><span class="platform-symbol pinterest-symbol">P</span>Pinterest</button>
                <button class="platform-tab" type="button" data-platform="threads" role="tab" aria-selected="false"><span class="platform-symbol threads-symbol">@</span>Threads</button>
            </div>
            </section>
            <section class="calendar-panel">
                <div class="calendar-toolbar">
                    <div>
                        <span class="section-kicker">CONTENT SCHEDULE</span>
                        <h2 id="calendarHeading">Instagram Calendar</h2>
                        <p id="calendarSubheading">Your scheduled creatives, at a glance.</p>
                    </div>
                    <div class="calendar-controls">
                        <button type="button" class="icon-button" id="prevMonth" aria-label="Previous month">&lsaquo;</button>
                        <button type="button" class="month-button" id="monthLabel" aria-live="polite">Loading...</button>
                        <button type="button" class="icon-button" id="nextMonth" aria-label="Next month">&rsaquo;</button>
                        <button type="button" class="today-button" id="goToday">Today</button>
                    </div>
                </div>
                <div class="calendar-legend">
                    <span><i class="legend-dot draft-dot"></i>Draft</span>
                    <span><i class="legend-dot pending-dot"></i>Pending</span>
                    <span><i class="legend-dot approved-dot"></i>Approved</span>
                    <span><i class="legend-dot rejected-dot"></i>Rejected</span>
                    <span><i class="legend-dot revision-dot"></i>Revision</span>
                </div>
                <div class="calendar-grid-wrap">
                    <div class="calendar-weekdays">
                        <div>MON</div>
                        <div>TUE</div>
                        <div>WED</div>
                        <div>THU</div>
                        <div>FRI</div>
                        <div>SAT</div>
                        <div>SUN</div>
                    </div>
                    <div class="calendar-grid" id="calendarGrid" aria-live="polite"></div>
                </div>
                <div class="calendar-loading" id="calendarLoading" hidden>
                    <span class="loading-spinner"></span> Loading your creatives...
                </div>
                <div class="calendar-error" id="calendarError" role="alert" hidden></div>
            </section>
        </div>

        <!-- Selected date creatives modal (moved to <body> by calendar.js) -->
        <div class="calendar-modal" id="dayCreativesModal" aria-hidden="true">
            <div class="calendar-modal-backdrop" data-close-day-modal></div>
            <section class="calendar-modal-content day-modal-content"
                role="dialog"
                aria-modal="true"
                aria-labelledby="selectedDayTitle">
                <div class="calendar-modal-header">
                    <div>
                        <span class="section-kicker">SCHEDULED CREATIVES</span>
                        <h2 id="selectedDayTitle">Select a date</h2>
                        <p id="selectedDaySubtitle">Creatives for the selected date will appear here.</p>
                    </div>
                    <button type="button"
                        class="modal-close-btn"
                        id="closeDayCreativesModal"
                        aria-label="Close creatives modal">&times;</button>
                </div>
                <div class="day-modal-count" id="selectedDayCount">0 creatives</div>
                <div id="dayCreatives" class="day-creatives">
                    <div class="empty-day">
                        <strong>No creatives on this date</strong>
                        <p>Select a date from the calendar.</p>
                    </div>
                </div>
            </section>
        </div>

        <!-- Upload creative modal (moved to <body> by calendar.js) -->
        <div class="calendar-modal" id="creativeModal" aria-hidden="true">
            <div class="calendar-modal-backdrop" data-close-upload-modal></div>
            <section class="calendar-modal-content upload-modal-content"
                role="dialog"
                aria-modal="true"
                aria-labelledby="modalTitle">
                <div class="calendar-modal-header">
                    <div>
                        <span class="section-kicker">CREATIVE MANAGEMENT</span>
                        <h2 id="modalTitle">Upload Creative</h2>
                        <p>Update the details and choose the final image. The approval status stays unchanged.</p>
                    </div>
                    <button type="button"
                        class="modal-close-btn"
                        id="closeModal"
                        aria-label="Close upload form">&times;</button>
                </div>

                <form id="creativeUploadForm" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="action" value="upload_approved_creative">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="creative_id" id="creativeId" value="">

                    <div class="form-grid">
                        <label class="form-field full-field" for="creativeTitle">
                            <span>Title <b>*</b></span>
                            <input type="text" id="creativeTitle" name="title" maxlength="255" required placeholder="Creative title">
                        </label>

                        <label class="form-field" for="creativePlatform">
                            <span>Platform <b>*</b></span>
                            <select id="creativePlatform" name="platform" required>
                                <option value="instagram">Instagram</option>
                                <option value="facebook">Facebook</option>
                                <option value="linkedin">LinkedIn</option>
                                <option value="google">Google</option>
                                <option value="youtube">YouTube</option>
                                <option value="x">X</option>
                                <option value="pinterest">Pinterest</option>
                                <option value="threads">Threads</option>
                            </select>
                        </label>

                        <label class="form-field" for="creativeDate">
                            <span>Creative date <b>*</b></span>
                            <input type="date" id="creativeDate" name="creative_date" required>
                        </label>

                        <label class="form-field full-field" for="creativeCaption">
                            <span>Caption</span>
                            <textarea id="creativeCaption" name="caption" rows="3" placeholder="Write the caption"></textarea>
                        </label>

                        <label class="form-field full-field" for="creativeDescription">
                            <span>Description</span>
                            <textarea id="creativeDescription" name="description" rows="3" placeholder="Internal description"></textarea>
                        </label>

                        <label class="form-field" for="creativeReference">
                            <span>Reference link</span>
                            <input type="url" id="creativeReference" name="reference_link" maxlength="2048" placeholder="https://example.com">
                        </label>

                        <label class="form-field" for="creativeKeywords">
                            <span>Keywords</span>
                            <input type="text" id="creativeKeywords" name="keywords" placeholder="campaign, festival, product">
                        </label>

                        <div class="form-field full-field">
                            <span>Current image</span>
                            <div class="current-image-wrap">
                                <img id="currentCreativeImage" alt="Current creative image" hidden>
                                <span id="noCurrentImage">No image uploaded yet.</span>
                            </div>
                        </div>

                        <div class="form-field full-field">
                            <span>New image <b>*</b></span>
                            <div class="file-drop-zone" id="fileDropZone">
                                <input type="file" id="creativeImage" name="creative_image" accept="image/jpeg,image/png,image/webp,image/gif" aria-label="Choose a new creative image">
                                <span class="file-icon">&uarr;</span>
                                <strong>Choose a new creative image</strong>
                                <small>or drag and drop &middot; JPG, PNG, WEBP or GIF &middot; max 5 MB</small>
                                <small class="selected-file-name" id="selectedFileName">No file selected</small>
                            </div>
                            <img id="newImagePreview" class="new-image-preview" alt="New image preview" hidden>
                        </div>
                    </div>

                    <div class="modal-notice" id="modalNotice" role="alert" hidden></div>

                    <div class="modal-actions">
                        <button type="button" class="cancel-button" id="cancelModal">Cancel</button>
                        <button type="submit" class="submit-button" id="submitCreative"><span>&uarr;</span> Upload creative</button>
                    </div>
                </form>
            </section>
        </div>

        <div class="toast-container" id="toastContainer" aria-live="polite"></div>
    </div>
    <script src="../assets/js/calendar.js" defer></script>
<?php if ($isDirectCalendarRequest): ?>
</body>

</html>
<?php endif; ?>
