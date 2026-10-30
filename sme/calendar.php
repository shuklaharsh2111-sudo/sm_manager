<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$isDirectCalendarRequest = isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);
if ($isDirectCalendarRequest) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
require_once __DIR__ . '/../config/db.php';
if (!isset($conn) || !($conn instanceof mysqli)) { http_response_code(500); exit('Database connection error.'); }
mysqli_set_charset($conn, 'utf8mb4');
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$rawRole = $_SESSION['role'] ?? '';
$decodedRole = json_decode((string)$rawRole, true);
$roles = is_array($decodedRole) ? array_map('strtolower', $decodedRole) : [strtolower(trim((string)$rawRole))];
$roles = array_values(array_filter($roles));
$isAdmin = (bool)array_intersect($roles, ['admin', 'superadmin']);

if ($currentUserId <= 0) {
    if (isset($_GET['calendar_ajax']) || $_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Session expired.']);
        exit;
    }
    header('Location: ../index.php');
    exit;
}

function calendarJson(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX: Fetch Assigned Companies for the User
if (isset($_GET['fetch_assigned_companies'])) {
    if ($isAdmin) {
        $sqlComp = "SELECT id, comp_name, comp_logo FROM companies ORDER BY comp_name ASC";
        $stmtComp = $conn->prepare($sqlComp);
    } else {
        $sqlComp = "SELECT c.id, c.comp_name, c.comp_logo 
                    FROM sme_company_assignments sca 
                    JOIN companies c ON c.id = sca.company_id 
                    WHERE sca.sme_user_id = ? 
                    ORDER BY c.comp_name ASC";
        $stmtComp = $conn->prepare($sqlComp);
        $stmtComp->bind_param('i', $currentUserId);
    }
    $stmtComp->execute();
    $resComp = $stmtComp->get_result();
    $assignedCompanies = [];
    while ($r = $resComp->fetch_assoc()) {
        $assignedCompanies[] = [
            'id' => (int)$r['id'],
            'comp_name' => $r['comp_name'],
            'comp_logo' => $r['comp_logo'] ?? ''
        ];
    }
    $stmtComp->close();
    calendarJson(['success' => true, 'companies' => $assignedCompanies]);
}

// AJAX: Add Creative
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_creative') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['calendar_csrf_token'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        calendarJson(['success' => false, 'message' => 'Security token expired.'], 403);
    }
    $platforms = ['instagram', 'facebook', 'linkedin', 'google', 'youtube', 'x', 'pinterest', 'threads'];
    $platform = strtolower(trim((string)($_POST['platform'] ?? 'instagram')));
    if (!in_array($platform, $platforms, true)) {
        calendarJson(['success' => false, 'message' => 'Invalid platform selected.'], 422);
    }
    
    $companyId = (int)($_POST['company_id'] ?? 0);
    if ($companyId <= 0) {
        calendarJson(['success' => false, 'message' => 'Please select a company.'], 422);
    }

    $creativeDate = trim((string)($_POST['creative_date'] ?? ''));
    $title = trim((string)($_POST['title'] ?? 'Untitled'));
    $caption = trim((string)($_POST['caption'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $referenceLink = trim((string)($_POST['reference_link'] ?? ''));
    $keywords = trim((string)($_POST['keywords'] ?? ''));
    
    $uploadDir = dirname(__DIR__) . '/uploads/creatives';
    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
    $imagePath = null;
    if (isset($_FILES['creative_image']) && $_FILES['creative_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['creative_image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $fileName = bin2hex(random_bytes(16)) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fileName)) {
                $imagePath = 'uploads/creatives/' . $fileName;
            }
        }
    }
    $stmt = $conn->prepare("INSERT INTO creatives (user_id, company_id, platform, creative_date, title, caption, description, reference_link, keywords, image_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')");
    $stmt->bind_param('iissssssss', $currentUserId, $companyId, $platform, $creativeDate, $title, $caption, $description, $referenceLink, $keywords, $imagePath);
    $success = $stmt->execute();
    $stmt->close();
    if ($success) {
        calendarJson(['success' => true, 'message' => 'Creative added successfully!']);
    } else {
        calendarJson(['success' => false, 'message' => 'Failed to add creative.'], 500);
    }
}

// AJAX: Fetch Creatives
if (isset($_GET['calendar_ajax'])) {
    $platform = strtolower(trim((string)($_GET['platform'] ?? 'instagram')));
    $companyId = (int)($_GET['company_id'] ?? 0);
    $month = trim((string)($_GET['month'] ?? date('Y-m')));
    $monthStart = $month . '-01';
    $nextMonth = date('Y-m-d', strtotime($monthStart . ' +1 month'));
    
    $sql = "SELECT cr.*, c.comp_name FROM creatives cr LEFT JOIN companies c ON c.id = cr.company_id WHERE cr.platform = ? AND cr.company_id = ? AND cr.creative_date >= ? AND cr.creative_date < ?";
    if (!$isAdmin) { $sql .= " AND cr.user_id = ?"; }
    $sql .= " ORDER BY cr.creative_date ASC, cr.id DESC";
    $stmt = $conn->prepare($sql);
    
    if (!$isAdmin) {
        $stmt->bind_param('sissi', $platform, $companyId, $monthStart, $nextMonth, $currentUserId);
    } else {
        $stmt->bind_param('siss', $platform, $companyId, $monthStart, $nextMonth);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $creatives = [];
    while ($row = $result->fetch_assoc()) {
        $creatives[] = [
            'id' => (int)$row['id'],
            'company_id' => (int)$row['company_id'],
            'platform' => $row['platform'],
            'creative_date' => $row['creative_date'],
            'title' => $row['title'],
            'caption' => $row['caption'] ?? '',
            'description' => $row['description'] ?? '',
            'image_path' => $row['image_path'] ?? '',
            'reference_link' => $row['reference_link'] ?? '',
            'keywords' => $row['keywords'] ?? '',
            'status' => $row['status'],
            'company_name' => $row['comp_name'] ?? 'Unassigned'
        ];
    }
    $stmt->close();
    calendarJson(['success' => true, 'platform' => $platform, 'company_id' => $companyId, 'month' => $month, 'creatives' => $creatives]);
}

if (empty($_SESSION['calendar_csrf_token'])) { $_SESSION['calendar_csrf_token'] = bin2hex(random_bytes(32)); }
$csrfToken = $_SESSION['calendar_csrf_token'];
?>
<?php if ($isDirectCalendarRequest): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creative Calendar</title>
    <link rel="stylesheet" href="../assets/css/calendar.css">
</head>
<body class="calendar-standalone">
<?php else: ?>
<link rel="stylesheet" href="css/calendar.css">
<?php endif; ?>
    <div class="calendar-page" id="calendarPage">
        <div class="calendar-app">
            <header class="calendar-header">
                <div class="header-copy">
                    <div class="eyebrow"><span class="eyebrow-dot"></span> WORKSPACE</div>
                    <h1>Social <span>Creative Calendar</span></h1>
                    <p>Click any date to add a creative directly for the selected company.</p>
                </div>
                <div class="header-date">
                    <span class="header-date-label">TODAY</span>
                    <strong id="todayLabel">Loading...</strong>
                </div>
            </header>

            <!-- Top Assigned Companies Row -->
            <section class="company-section">
                <div class="company-bar-label">ASSIGNED COMPANIES</div>
                <div class="company-bar" id="companyBar">
                    <p style="color:var(--muted);font-size:11px;">Loading companies...</p>
                </div>
            </section>

            <section class="platform-panel">
                <div class="platform-tabs" id="platformTabs" role="tablist">
                    <button class="platform-tab active" type="button" data-platform="instagram">Instagram</button>
                    <button class="platform-tab" type="button" data-platform="facebook">Facebook</button>
                    <button class="platform-tab" type="button" data-platform="linkedin">LinkedIn</button>
                    <button class="platform-tab" type="button" data-platform="google">Google</button>
                    <button class="platform-tab" type="button" data-platform="youtube">YouTube</button>
                    <button class="platform-tab" type="button" data-platform="x">X</button>
                    <button class="platform-tab" type="button" data-platform="pinterest">Pinterest</button>
                    <button class="platform-tab" type="button" data-platform="threads">Threads</button>
                </div>
            </section>

            <section class="calendar-panel">
                <div class="calendar-toolbar">
                    <h2 id="calendarHeading">Calendar</h2>
                    <div class="calendar-controls">
                        <button type="button" class="icon-button" id="prevMonth">&lsaquo;</button>
                        <button type="button" class="month-button" id="monthLabel">Loading...</button>
                        <button type="button" class="icon-button" id="nextMonth">&rsaquo;</button>
                        <button type="button" class="today-button" id="goToday">Today</button>
                    </div>
                </div>
                <div class="calendar-grid-wrap">
                    <div class="calendar-weekdays"><div>MON</div><div>TUE</div><div>WED</div><div>THU</div><div>FRI</div><div>SAT</div><div>SUN</div></div>
                    <div class="calendar-grid" id="calendarGrid"></div>
                </div>
            </section>
        </div>

        <!-- Add Creative Modal (Direct Form with Split Layout) -->
        <div class="calendar-modal" id="addCreativeModal" aria-hidden="true">
            <div class="calendar-modal-backdrop" data-close-add-modal></div>
            <section class="calendar-modal-content upload-modal-content" role="dialog">
                <div class="calendar-modal-header">
                    <div>
                        <h2 id="addModalTitle">Add Creative</h2>
                        <p id="addModalSubtitle">Upload creative image and fill details.</p>
                    </div>
                    <button type="button" class="modal-close-btn" id="closeAddModal">&times;</button>
                </div>
                <form id="addCreativeForm" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="action" value="add_creative">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="creative_date" id="addCreativeDate" value="">
                    <input type="hidden" name="company_id" id="addCompanyId" value="">
                    
                    <div class="creative-form-layout">
                        <!-- Left Side: Image Upload & Preview -->
                        <div class="creative-upload-side">
                            <span class="field-label-title">Creative Image</span>
                            <div class="image-dropzone" id="imageDropzone">
                                <input type="file" name="creative_image" id="creativeImageInput" accept="image/*">
                                <div class="dropzone-placeholder" id="dropzonePlaceholder">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                    <span>Click or drag image here</span>
                                </div>
                                <div class="image-preview-container" id="imagePreviewContainer" style="display: none;">
                                    <img id="imagePreview" src="" alt="Preview">
                                    <button type="button" id="removeImageBtn" title="Remove Image">&times;</button>
                                </div>
                            </div>
                        </div>

                        <!-- Right Side: Other Inputs -->
                        <div class="creative-inputs-side">
                            <div class="form-grid-inner">
                                <label class="form-field">
                                    <span>Platform</span>
                                    <select name="platform" id="addPlatformSelect">
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
                                <label class="form-field">
                                    <span>Title</span>
                                    <input type="text" name="title" placeholder="Optional title">
                                </label>
                                <label class="form-field full-field">
                                    <span>Caption</span>
                                    <textarea name="caption" rows="2" placeholder="Optional caption"></textarea>
                                </label>
                                <label class="form-field full-field">
                                    <span>Description</span>
                                    <textarea name="description" rows="2" placeholder="Optional description"></textarea>
                                </label>
                                <label class="form-field">
                                    <span>Reference Link</span>
                                    <input type="url" name="reference_link" placeholder="https://...">
                                </label>
                                <label class="form-field">
                                    <span>Keywords</span>
                                    <input type="text" name="keywords" placeholder="keyword1, keyword2">
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="cancel-button" id="cancelAddModal">Cancel</button>
                        <button type="submit" class="submit-button">Save Creative</button>
                    </div>
                </form>
            </section>
        </div>

        <div class="toast-container" id="toastContainer"></div>
    </div>
    <script src="js/calendar.js" defer></script>
<?php if ($isDirectCalendarRequest): ?>
</body>
</html>
<?php endif; ?>