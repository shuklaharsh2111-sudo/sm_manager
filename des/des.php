<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
/* =========================================================
   SECURITY: VERIFY DES LOGIN
========================================================= */
if (
    empty($_SESSION['user_id']) ||
    (int) $_SESSION['user_id'] <= 0 ||
    strtolower(trim((string) ($_SESSION['role'] ?? ''))) !== 'des'
) {
    header('Location: ../index.php');
    exit;
}
require_once __DIR__ . '/../config/db.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection is unavailable.');
}
$userId = (int) $_SESSION['user_id'];
/* =========================================================
   FETCH LOGGED-IN DES USER
========================================================= */
$stmt = $conn->prepare(
    "SELECT * FROM users WHERE id = ? LIMIT 1"
);
if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare user query.');
}
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
if (!$user) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $cookie['path'],
            $cookie['domain'],
            $cookie['secure'],
            $cookie['httponly']
        );
    }
    session_destroy();
    header('Location: ../index.php');
    exit;
}
/* =========================================================
   VERIFY CURRENT DATABASE ROLE
========================================================= */
$roles = json_decode((string) ($user['role'] ?? ''), true);
if (!is_array($roles)) {
    $roles = [];
}
$roles = array_map(
    static function ($role) {
        return is_string($role)
            ? strtolower(trim($role))
            : '';
    },
    $roles
);
if (!in_array('des', $roles, true)) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $cookie['path'],
            $cookie['domain'],
            $cookie['secure'],
            $cookie['httponly']
        );
    }
    session_destroy();
    header('Location: ../index.php');
    exit;
}
/* =========================================================
   DES USER DETAILS
========================================================= */
$_SESSION['fullname'] = $user['fullname'] ?? '';
$_SESSION['email'] = $user['email'] ?? '';
$fullname = trim((string) ($user['fullname'] ?? ''));
if ($fullname === '') {
    $fullname = 'User';
}
$email = trim((string) ($user['email'] ?? ''));
$designation = 'Graphic Designer';
$department = 'Designer';
$brandName = trim((string) ($user['brand_name'] ?? ''));
if ($brandName === '') {
    $brandName = 'Unstoppable Solutions';
}
date_default_timezone_set('Asia/Kolkata');
$currentHour = (int) date('G');
if ($currentHour >= 4 && $currentHour < 12) {
    $greeting = 'Good Morning';
} elseif ($currentHour >= 12 && $currentHour < 16) {
    $greeting = 'Good Afternoon';
} else {
    $greeting = 'Good Evening';
}
/* =========================================================
   HTML ESCAPING
========================================================= */
if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}
/* =========================================================
   BRAND LOGO
   Database column: users.brand_logo
========================================================= */
$brandLogo = trim((string) ($user['brand_logo'] ?? ''));
$logoUrl = '';
if ($brandLogo !== '') {
    $normalizedLogo = str_replace('\\', '/', $brandLogo);
    /* Only use a locally uploaded logo. */
    if (
        !preg_match('~^https?://~i', $normalizedLogo) &&
        strpos($normalizedLogo, "\0") === false
    ) {
        $logoFilename = basename($normalizedLogo);
        $logoPath = __DIR__ . '/../uploads/' . $logoFilename;
        if (
            $logoFilename !== '' &&
            is_file($logoPath)
        ) {
            $logoUrl = '../uploads/' . rawurlencode($logoFilename);
        }
    }
}
/* =========================================================
   DES PROFILE IMAGE
   Database column: users.prof_image
========================================================= */
$profileImage = trim((string) ($user['prof_image'] ?? ''));
$profileUrl = '';
if ($profileImage !== '') {
    $normalizedImage = str_replace('\\', '/', $profileImage);
    if (
        preg_match('~^https?://~i', $normalizedImage) &&
        filter_var($normalizedImage, FILTER_VALIDATE_URL)
    ) {
        /* Support externally hosted profile images. */
        $profileUrl = $normalizedImage;
    } elseif (strpos($normalizedImage, "\0") === false) {
        $imageFilename = basename($normalizedImage);
        $imagePath = __DIR__ . '/../uploads/' . $imageFilename;
        if (
            $imageFilename !== '' &&
            is_file($imagePath)
        ) {
            $profileUrl = '../uploads/' . rawurlencode($imageFilename);
        }
    }
}
/* =========================================================
   REPORTING MANAGER
   If unavailable, find an administrator.
========================================================= */
$managerName = '';
$managerId = 0;
$managerAssigned = false;
$managerColumns = [
    'reporting_manager_id',
    'manager_id',
    'reporting_to',
    'reports_to',
    'report_to'
];
$userColumns = [];
$columnsResult = $conn->query("SHOW COLUMNS FROM users");
if ($columnsResult) {
    while ($column = $columnsResult->fetch_assoc()) {
        $userColumns[] = $column['Field'];
    }
    $columnsResult->free();
}
/* Find a manager using an available ID column. */
foreach ($managerColumns as $managerColumn) {
    if (!in_array($managerColumn, $userColumns, true)) {
        continue;
    }
    $candidateId = $user[$managerColumn] ?? null;
    if (
        $candidateId === null ||
        $candidateId === '' ||
        !is_numeric($candidateId) ||
        (int) $candidateId <= 0 ||
        (int) $candidateId === $userId
    ) {
        continue;
    }
    $candidateId = (int) $candidateId;
    $managerStmt = $conn->prepare(
        "SELECT id, fullname, role
         FROM users
         WHERE id = ?
         LIMIT 1"
    );
    if (!$managerStmt) {
        continue;
    }
    $managerStmt->bind_param('i', $candidateId);
    $managerStmt->execute();
    $managerResult = $managerStmt->get_result();
    $manager = $managerResult->fetch_assoc();
    $managerStmt->close();
    if (!$manager) {
        continue;
    }
    $managerRoles = json_decode(
        (string) ($manager['role'] ?? ''),
        true
    );
    if (!is_array($managerRoles)) {
        $managerRoles = [];
    }
    $managerRoles = array_map(
        static function ($role) {
            return is_string($role)
                ? strtolower(trim($role))
                : '';
        },
        $managerRoles
    );
    $isAdmin = in_array('admin', $managerRoles, true)
        || in_array('superadmin', $managerRoles, true);
    $candidateName = trim(
        (string) ($manager['fullname'] ?? '')
    );
    /*
     * Use a valid non-administrator reporting manager.
     * Administrator fallback is handled below.
     */
    if (!$isAdmin && $candidateName !== '') {
        $managerName = $candidateName;
        $managerId = (int) $manager['id'];
        $managerAssigned = true;
        break;
    }
}
/* =========================================================
   ADMINISTRATOR FALLBACK
========================================================= */
if (!$managerAssigned) {
    $adminResult = $conn->query(
        "SELECT id, fullname, role
         FROM users
         WHERE role IS NOT NULL
         ORDER BY id ASC"
    );
    if ($adminResult) {
        while ($admin = $adminResult->fetch_assoc()) {
            $adminRoles = json_decode(
                (string) ($admin['role'] ?? ''),
                true
            );
            if (!is_array($adminRoles)) {
                continue;
            }
            $adminRoles = array_map(
                static function ($role) {
                    return is_string($role)
                        ? strtolower(trim($role))
                        : '';
                },
                $adminRoles
            );
            $isAdmin = in_array('admin', $adminRoles, true)
                || in_array('superadmin', $adminRoles, true);
            $adminName = trim(
                (string) ($admin['fullname'] ?? '')
            );
            if ($isAdmin && $adminName !== '') {
                $managerName = $adminName;
                $managerId = (int) $admin['id'];
                break;
            }
        }
        $adminResult->free();
    }
    if ($managerName === '') {
        $managerName = 'Admin not found';
        $managerId = 0;
    }
}
/* =========================================================
   DES DASHBOARD PAGE ROUTING
========================================================= */
$allowedPages = [
    'dashboard' => 'dashboard.php',
    'calendar'  => 'calendar.php',
];
$currentPage = strtolower(
    trim((string) ($_GET['page'] ?? 'dashboard'))
);
if (!isset($allowedPages[$currentPage])) {
    $currentPage = 'dashboard';
}
$contentFile = __DIR__ . '/' . $allowedPages[$currentPage];
if (!is_file($contentFile)) {
    http_response_code(404);
    $contentFile = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0b1020">
    <title>DES Dashboard | Creative Manager</title>
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="css/des.css">
</head>
<body>
    <div class="dashboard-layout">
        <!-- Mobile sidebar toggle -->
        <button
            type="button"
            class="sidebar-toggle"
            id="sidebarToggle"
            aria-label="Open sidebar"
            aria-controls="sidebar"
            aria-expanded="false">
            <i class="fa-solid fa-bars"></i>
        </button>
        <!-- DES sidebar -->
        <aside class="sidebar" id="sidebar">
            <!-- Brand -->
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
                <button
                    type="button"
                    class="sidebar-close"
                    id="sidebarClose"
                    aria-label="Close sidebar">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <hr>
            <!-- Logged-in DES profile -->
            <section class="sidebar-profile">
                <div class="profile-image-wrap">
                    <?php if ($profileUrl !== ''): ?>
                        <img
                            src="<?= e($profileUrl) ?>"
                            alt="<?= e($fullname) ?> profile photo"
                            class="profile-avatar profile-image"
                            id="profileImage"
                            onerror="this.hidden=true;document.getElementById('profileFallback').hidden=false;">
                    <?php endif; ?>
                    <div
                        class="profile-avatar profile-fallback"
                        id="profileFallback"
                        <?= $profileUrl !== '' ? 'hidden' : '' ?>>
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <span
                        class="profile-online"
                        title="Logged in"></span>
                </div>
                <h3><?= e($fullname) ?></h3>
                <p class="designation">
                    <i class="fa-solid fa-id-badge"></i>
                    <?= e($designation) ?>
                </p>
                <div class="department-details">
                    <span>
                        <i class="fa-solid fa-building"></i>
                        Department
                    </span>
                    <strong><?= e($department) ?></strong>
                </div>
                <!-- Reporting manager / administrator -->
                <div class="reporting-card">
                    <div class="reporting-icon">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                    <div class="reporting-text">
                        <span>
                            <?= $managerAssigned
                                ? 'REPORTING TO'
                                : 'ADMINISTRATOR' ?>
                        </span>
                        <strong><?= e($managerName) ?></strong>
                        <small>
                            <?php if ($managerId > 0): ?>
                                User ID: <?= e($managerId) ?>
                            <?php else: ?>
                                Administrator details unavailable
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </section>
            <hr>
            <!-- DES navigation -->
            <nav
                class="sidebar-navigation"
                aria-label="DES navigation">
                <a
                    href="des.php?page=dashboard"
                    class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>"
                    <?= $currentPage === 'dashboard' ? 'aria-current="page"' : '' ?>>
                    <i class="fa-solid fa-house"></i>
                    <span>Dashboard</span>
                    <span class="nav-active-indicator"></span>
                </a>
                <a
                    href="des.php?page=calendar"
                    class="nav-link <?= $currentPage === 'calendar' ? 'active' : '' ?>"
                    <?= $currentPage === 'calendar' ? 'aria-current="page"' : '' ?>>
                    <i class="fa-regular fa-calendar-days"></i>
                    <span>Calendar</span>
                    <span class="nav-active-indicator"></span>
                </a>
            </nav>
            <!-- Sidebar footer -->
            <div class="sidebar-footer">
                <a href="../logout.php" class="logout-link">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
            </div>
        </aside>
        <!-- Mobile sidebar backdrop -->
        <div
            class="sidebar-backdrop"
            id="sidebarBackdrop"></div>
        <!-- Main content -->
        <main class="main-content">
            <header class="dashboard-header">
                <div>
                    <h1>
                        <?= e($greeting) ?>, <?= e($fullname) ?>!
                    </h1>
                    <p>
                        Welcome to your Creative Manager workspace.
                    </p>
                </div>
                <div class="header-user">
                    <span>
                        <i class="fa-regular fa-clock"></i>
                        <?= e(date('l')) ?>
                    </span>
                    <small><?= e(date('d F Y')) ?></small>
                    <strong id="liveClock">
                        <?= e(date('H:i:s')) ?>
                    </strong>
                </div>
            </header>
            <!-- DES dashboard / calendar content -->
            <section class="dashboard-content">
                <?php if ($contentFile !== ''): ?>
                    <?php require $contentFile; ?>
                <?php else: ?>
                    <h2>Page not found</h2>
                    <p>
                        The requested DES dashboard page does not exist.
                    </p>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <script src="js/des.js" defer></script>
</body>
</html>