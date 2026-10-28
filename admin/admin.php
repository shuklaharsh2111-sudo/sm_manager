<?php
// Buffer dashboard output so included pages can still send redirect headers.
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Prevent cached page access after logout.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
// Allow only authenticated Admin users.
if (
    empty($_SESSION['user_id']) ||
    strtolower(trim((string) ($_SESSION['role'] ?? ''))) !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}
require_once __DIR__ . '/../config/db.php';
// Fetch latest user details.
$userId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare(
    "SELECT fullname, email, brand_name, brand_logo
     FROM users
     WHERE id = ?
     LIMIT 1"
);
if (!$stmt) {
    http_response_code(500);
    exit('Unable to load user information.');
}
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
// Invalidate session if user no longer exists.
if (!$user) {
    $_SESSION = [];
    session_destroy();
    header('Location: ../index.php');
    exit;
}
// Update session details.
$_SESSION['fullname'] = $user['fullname'] ?? '';
$_SESSION['email'] = $user['email'] ?? '';
$fullname = trim((string) ($user['fullname'] ?? '')) ?: 'User';
$email = (string) ($user['email'] ?? '');
$brandName = trim((string) ($user['brand_name'] ?? ''))
    ?: 'Unstoppable Solutions';
$brandLogo = trim((string) ($user['brand_logo'] ?? ''));
// Set Indian Standard Time.
date_default_timezone_set('Asia/Kolkata');
// Greeting according to current time.
$currentHour = (int) date('G');
if ($currentHour >= 4 && $currentHour < 12) {
    $greeting = 'Good Morning';
} elseif ($currentHour >= 12 && $currentHour < 16) {
    $greeting = 'Good Afternoon';
} else {
    $greeting = 'Good Evening';
}
// Escape output safely.
if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}
// Load brand logo from uploads directory.
$logoUrl = '';
if ($brandLogo !== '') {
    $logoFilename = basename(
        str_replace('\\', '/', $brandLogo)
    );
    $logoPath = __DIR__ . '/../uploads/' . $logoFilename;
    if (is_file($logoPath)) {
        $logoUrl = '../uploads/' . rawurlencode($logoFilename);
    }
}
// Allowed admin content pages.
$allowedPages = [
    'dashboard' => 'dashboard.php',
    'users'     => 'users.php',
    'companies' => 'companies.php',
    'invoices' => 'invoices.php',
    'meetings' => 'adminmeeting.php',
    'creatives' => 'admincreatives.php',
    'admincalendar' => 'admincalendar.php'
];
// Identify current page.
$currentPage = strtolower(
    trim((string) ($_GET['page'] ?? 'dashboard'))
);
// Invalid pages default to dashboard.
if (!isset($allowedPages[$currentPage])) {
    $currentPage = 'dashboard';
}
$contentFile = __DIR__ . '/' . $allowedPages[$currentPage];
// Verify content file exists.
if (!is_file($contentFile)) {
    http_response_code(404);
    $contentFile = '';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Creative Manager</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>

<body>
    <div class="dashboard-layout">
        <!-- Mobile Sidebar Toggle -->
        <button
            type="button"
            class="sidebar-toggle"
            id="sidebarToggle"
            aria-label="Toggle sidebar"
            aria-controls="sidebar"
            aria-expanded="false">
            ☰
        </button>
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <!-- Brand Information -->
            <div class="sidebar-brand">
                <?php if ($logoUrl !== ''): ?>
                    <img
                        src="<?= e($logoUrl) ?>"
                        alt="Brand Logo"
                        class="brand-logo">
                <?php else: ?>
                    <div class="brand-placeholder">US</div>
                <?php endif; ?>
                <div class="brand-details">
                    <h2><?= e($brandName) ?></h2>
                    <p>Creative Manager</p>
                </div>
            </div>
            <hr>
            <!-- Admin Profile -->
            <section class="sidebar-profile">
                <div class="profile-avatar">
                    <?= e(strtoupper(substr($fullname, 0, 1))) ?>
                </div>
                <h3><?= e($fullname) ?></h3>
                <p class="designation">Administrator</p>
                <div class="department-details">
                    <span>Email</span>
                    <strong><?= e($email) ?></strong>
                </div>
            </section>
            <hr>
            <!-- Admin Navigation -->
            <nav class="sidebar-navigation" aria-label="Admin navigation">
                <a
                    href="admin.php?page=dashboard"
                    class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>"
                    <?= $currentPage === 'dashboard' ? 'aria-current="page"' : '' ?>>
                    Dashboard
                </a>
                <a
                    href="admin.php?page=users"
                    class="nav-link <?= $currentPage === 'users' ? 'active' : '' ?>"
                    <?= $currentPage === 'users' ? 'aria-current="page"' : '' ?>>
                    Users
                </a>
                <a
                    href="admin.php?page=companies"
                    class="nav-link <?= $currentPage === 'companies' ? 'active' : '' ?>"
                    <?= $currentPage === 'companies' ? 'aria-current="page"' : '' ?>>
                    Companies
                </a>
                <a
                    href="admin.php?page=invoices"
                    class="nav-link <?= $currentPage === 'invoices' ? 'active' : '' ?>"
                    <?= $currentPage === 'invoices' ? 'aria-current="page"' : '' ?>>
                    Invoices
                </a>
              <a
                    href="admin.php?page=meetings"
                    class="nav-link <?= $currentPage === 'meetings' ? 'active' : '' ?>"
                    <?= $currentPage === 'meetings' ? 'aria-current="page"' : '' ?>>
                    Meetings
                </a>
                <a
                    href="admin.php?page=creatives"
                    class="nav-link <?= $currentPage === 'creatives' ? 'active' : '' ?>"
                    <?= $currentPage === 'creatives' ? 'aria-current="page"' : '' ?>>
                    Creatives
                </a>
                <a
                    href="admin.php?page=admincalendar"
                    class="nav-link <?= $currentPage === 'admincalendar' ? 'active' : '' ?>"
                    <?= $currentPage === 'admincalendar' ? 'aria-current="page"' : '' ?>>
                    Calendar
                </a>
            </nav>
            <!-- Logout -->
            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-link">
                    Logout
                </a>
            </div>
        </aside>
        <!-- Mobile Backdrop -->
        <div
            class="sidebar-backdrop"
            id="sidebarBackdrop"></div>
        <!-- Main Content -->
        <main class="main-content">
            <header class="dashboard-header">
                <div>
                    <h1>
                        <?= e($greeting) ?>, <?= e($fullname) ?>!
                    </h1>
                    <p>Welcome to your Admin workspace.</p>
                </div>
                <div class="header-user">
                    <span><?= e(date('l')) ?></span>
                    <small><?= e(date('d F Y')) ?></small>
                    <strong id="liveClock">
                        <?= e(date('H:i:s')) ?>
                    </strong>
                </div>
            </header>
            <!-- Dynamic Content -->
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
    <script src="../assets/js/admin.js" defer></script>
</body>

</html>