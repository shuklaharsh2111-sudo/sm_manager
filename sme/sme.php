<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if (empty($_SESSION['user_id']) || strtolower(trim((string)($_SESSION['role'] ?? ''))) !== 'sme') {
    header('Location: ../index.php');
    exit;
}
require_once __DIR__ . '/../config/db.php';
$userId = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT fullname, email, brand_name, brand_logo, prof_image, role, reporting_manager FROM users WHERE id = ? LIMIT 1");
if (!$stmt) { http_response_code(500); exit('Unable to load user information.'); }
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { session_unset(); session_destroy(); header('Location: ../index.php'); exit; }
$roles = json_decode($user['role'] ?? '', true);
if (!is_array($roles) || !in_array('sme', array_map(static fn($r) => is_string($r) ? strtolower(trim($r)) : '', $roles), true)) {
    $_SESSION = []; session_destroy(); header('Location: ../index.php'); exit;
}
$_SESSION['fullname'] = $user['fullname'] ?? '';
$_SESSION['email'] = $user['email'] ?? '';
$fullname = $user['fullname'] ?: 'User';
$designation = 'Social Media Executive';
$department = 'Social Media';
$brandName = $user['brand_name'] ?: 'Unstoppable Solutions';
$brandLogo = trim((string)($user['brand_logo'] ?? ''));
$reportingManagerName = '';
$reportingManagerEmail = '';
$managerAssigned = false;
$reportingManagerValue = trim((string)($user['reporting_manager'] ?? ''));
if ($reportingManagerValue !== '') {
    $managerStmt = $conn->prepare("SELECT fullname, email FROM users WHERE LOWER(TRIM(fullname)) = LOWER(TRIM(?)) AND JSON_VALID(role) AND JSON_CONTAINS(LOWER(role), '\"admin\"') = 0 ORDER BY id ASC LIMIT 1");
    if ($managerStmt) {
        $managerStmt->bind_param('s', $reportingManagerValue);
        $managerStmt->execute();
        $managerRow = $managerStmt->get_result()->fetch_assoc();
        if ($managerRow) {
            $reportingManagerName = trim((string)($managerRow['fullname'] ?? ''));
            $reportingManagerEmail = trim((string)($managerRow['email'] ?? ''));
            $managerAssigned = ($reportingManagerName !== '');
        }
        $managerStmt->close();
    }
}
if (!$managerAssigned) {
    $adminStmt = $conn->prepare("SELECT fullname, email FROM users WHERE JSON_VALID(role) AND JSON_CONTAINS(LOWER(role), '\"admin\"') = 1 ORDER BY id ASC LIMIT 1");
    if ($adminStmt) {
        $adminStmt->execute();
        $adminRow = $adminStmt->get_result()->fetch_assoc();
        if ($adminRow) {
            $reportingManagerName = trim((string)($adminRow['fullname'] ?? '')) ?: 'Admin';
            $reportingManagerEmail = trim((string)($adminRow['email'] ?? ''));
            $managerAssigned = true;
        }
        $adminStmt->close();
    }
}
if ($reportingManagerName === '') { $reportingManagerName = 'Admin'; }
if ($reportingManagerEmail === '') { $reportingManagerEmail = 'Email not available'; }
date_default_timezone_set('Asia/Kolkata');
$currentHour = (int)date('G');
$greeting = ($currentHour >= 4 && $currentHour < 12) ? 'Good Morning' : (($currentHour >= 12 && $currentHour < 16) ? 'Good Afternoon' : 'Good Evening');
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$logoUrl = '';
if ($brandLogo !== '') {
    $logoFilename = basename(str_replace('\\', '/', $brandLogo));
    if (is_file(__DIR__ . '/../uploads/' . $logoFilename)) { $logoUrl = '../uploads/' . rawurlencode($logoFilename); }
}
$profileImageUrl = '';
$profileImage = trim((string)($user['prof_image'] ?? ''));
if ($profileImage !== '') {
    $profileImageFile = basename(str_replace('\\', '/', $profileImage));
    if (is_file(__DIR__ . '/../uploads/' . $profileImageFile)) { $profileImageUrl = '../uploads/' . rawurlencode($profileImageFile); }
}
$avatarInitial = function_exists('mb_substr') && function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($fullname, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($fullname, 0, 1));
$clockFormat = '24h';
$clockStmt = $conn->prepare('SELECT clock_format FROM user_settings WHERE user_id = ? LIMIT 1');
if ($clockStmt) {
    $clockStmt->bind_param('i', $userId);
    if ($clockStmt->execute()) {
        $clockRow = $clockStmt->get_result()->fetch_assoc();
        if ($clockRow && ($clockRow['clock_format'] ?? '') === '12h') { $clockFormat = '12h'; }
    }
    $clockStmt->close();
}
$allowedPages = [
    'dashboard' => 'dashboard.php',
    'calendar'  => 'calendar.php',
    'pending'   => 'pending.php',
    'recordings' => 'recordings.php',
    'saved' => 'saved.php',
    'monthlyreport' => 'monthlyreport.php',
    'analytics' => 'analytics.php',
    'settings'  => 'settings.php'
];
$currentPage = strtolower(trim((string)($_GET['page'] ?? 'dashboard')));
if (!isset($allowedPages[$currentPage])) { $currentPage = 'dashboard'; }
$contentFile = __DIR__ . '/' . $allowedPages[$currentPage];
if (!is_file($contentFile)) { http_response_code(404); $contentFile = ''; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SME Dashboard | Creative Manager</title>
    <link rel="stylesheet" href="../assets/css/sme.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.3.1/css/all.css" integrity="sha512-x9WwyMYBnlXMNQ6kQ/Lyzu1NqIhLQKL5Oq6xByfXuRj7s9CskyCbLv/1IjqzJmXwFXWr0ov6jBV7Qbc0hh9nHg==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <?php if ($currentPage === 'calendar'): ?><link rel="stylesheet" href="../assets/css/calendar.css"><?php endif; ?>
    <?php if ($currentPage === 'settings'): ?><link rel="stylesheet" href="../assets/css/smesettings.css"><?php endif; ?>
    <?php if ($currentPage === 'saved'): ?><link rel="stylesheet" href="../assets/css/smesaved.css"><?php endif; ?>
</head>
<body data-clock-format="<?= e($clockFormat) ?>">
    <div class="dashboard-layout">
        <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar" aria-controls="sidebar" aria-expanded="false">☰</button>
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?= e($logoUrl) ?>" alt="Brand Logo" class="brand-logo">
                <?php else: ?>
                    <div class="brand-placeholder">US</div>
                <?php endif; ?>
                <div class="brand-details">
                    <h2><?= e($brandName) ?></h2>
                    <p>Creative Manager</p>
                </div>
            </div>
            <hr>
            <section class="sidebar-profile">
                <div class="profile-avatar" id="sidebarAvatar">
                    <?php if ($profileImageUrl !== ''): ?><img src="<?= e($profileImageUrl) ?>" alt=""><?php else: ?><?= e($avatarInitial) ?><?php endif; ?>
                </div>
                <h3 id="sidebarName"><?= e($fullname) ?></h3>
                <p class="designation">SME (<?= e($designation) ?>)</p>
                <div class="reporting-manager-card">
                    <div class="reporting-manager-heading">
                        <span class="reporting-manager-indicator"></span>
                        <span>REPORTING MANAGER</span>
                    </div>
                    <div class="reporting-manager-info">
                        <div class="reporting-manager-avatar"><i class="fa-solid fa-user-tie"></i></div>
                        <div class="reporting-manager-text">
                            <strong><?= e($reportingManagerName) ?></strong>
                            <a href="<?= $reportingManagerEmail !== 'Email not available' ? 'mailto:' . e($reportingManagerEmail) : '#' ?>"><?= e($reportingManagerEmail) ?></a>
                        </div>
                    </div>
                </div>
                <div class="department-details">
                    <span>Department</span>
                    <strong><?= e($department) ?></strong>
                </div>
            </section>
            <hr>
            <nav class="sidebar-navigation" aria-label="Main navigation">
                <a href="sme.php?page=dashboard" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>" <?= $currentPage === 'dashboard' ? 'aria-current="page"' : '' ?>>Dashboard</a>
                <a href="sme.php?page=calendar" class="nav-link <?= $currentPage === 'calendar' ? 'active' : '' ?>" <?= $currentPage === 'calendar' ? 'aria-current="page"' : '' ?>>Calendar</a>
                <a href="sme.php?page=pending" class="nav-link <?= $currentPage === 'pending' ? 'active' : '' ?>" <?= $currentPage === 'pending' ? 'aria-current="page"' : '' ?>>Pending Creatives</a>
                <a href="sme.php?page=recordings" class="nav-link <?= $currentPage === 'recordings' ? 'active' : '' ?>" <?= $currentPage === 'recordings' ? 'aria-current="page"' : '' ?>>Records</a>
                <a href="sme.php?page=saved" class="nav-link <?= $currentPage === 'saved' ? 'active' : '' ?>" <?= $currentPage === 'saved' ? 'aria-current="page"' : '' ?>>Saved</a>
                <a href="sme.php?page=analytics" class="nav-link <?= $currentPage === 'analytics' ? 'active' : '' ?>" <?= $currentPage === 'analytics' ? 'aria-current="page"' : '' ?>>Analytics</a>
                <a href="sme.php?page=settings" class="nav-link <?= $currentPage === 'settings' ? 'active' : '' ?>" <?= $currentPage === 'settings' ? 'aria-current="page"' : '' ?>>Settings</a>
                <a href="sme.php?page=monthlyreport" class="nav-link <?= $currentPage === 'monthlyreport' ? 'active' : '' ?>" <?= $currentPage === 'monthlyreport' ? 'aria-current="page"' : '' ?>>Monthly Report</a>
            </nav>
            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-link">Logout</a>
            </div>
        </aside>
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
        <main class="main-content">
            <header class="dashboard-header">
                <div>
                    <h1><?= e($greeting) ?>, <span id="headerName"><?= e($fullname) ?></span>!</h1>
                    <p>Welcome to your Creative Manager workspace.</p>
                </div>
                <div class="header-user">
                    <span><?= date('l') ?></span>
                    <small><?= date('d F Y') ?></small>
                    <strong id="liveClock"><?= date($clockFormat === '12h' ? 'h:i:s A' : 'H:i:s') ?></strong>
                </div>
            </header>
            <section class="dashboard-content">
                <?php if ($contentFile !== ''): ?>
                    <?php require $contentFile; ?>
                <?php else: ?>
                    <h2>Page not found</h2>
                    <p>The requested page file does not exist.</p>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <script src="../assets/js/sme.js" defer></script>
    <?php if ($currentPage === 'settings'): ?><script src="../assets/js/smesettings.js" defer></script><?php endif; ?>
</body>
</html>