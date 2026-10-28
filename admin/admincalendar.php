<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Please login first.');
}
// Support JSON, array and plain-string role formats.
$sessionRole = $_SESSION['role'] ?? '';
$decodedRole = is_string($sessionRole)
    ? json_decode($sessionRole, true)
    : $sessionRole;
if (is_array($decodedRole)) {
    $roles = $decodedRole;
} else {
    $roles = [$sessionRole];
}
$roles = array_map(
    static fn($role) => strtolower(trim((string)$role)),
    $roles
);
if (!in_array('admin', $roles, true)) {
    http_response_code(403);
    exit('Access denied.');
}
// Your existing db.php should provide $conn or $mysqli.
$db = $conn ?? $mysqli ?? null;
if (!$db instanceof mysqli) {
    http_response_code(500);
    exit('Database connection not found. Check db.php.');
}
/*
|--------------------------------------------------------------------------
| AJAX: Fetch creatives for a particular month
|--------------------------------------------------------------------------
*/
if (isset($_GET['action']) && $_GET['action'] === 'events') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid month.']);
        exit;
    }
    $startDate = $month . '-01';
    $endDate = date('Y-m-d', strtotime($startDate . ' +1 month'));
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
            cr.created_at,
            cr.revision_reason,
            u.fullname AS creator_name,
            u.email AS creator_email,
            u.contact AS creator_contact,
            u.brand_name AS creator_brand,
            u.prof_image AS creator_image,
            co.comp_name AS company_name,
            co.comp_logo AS company_logo
        FROM creatives cr
        LEFT JOIN users u
            ON u.id = cr.user_id
        LEFT JOIN companies co
            ON co.id = cr.company_id
        WHERE cr.creative_date >= ?
          AND cr.creative_date < ?
        ORDER BY cr.creative_date ASC, cr.created_at DESC, cr.id DESC
    ";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to prepare calendar query.']);
        exit;
    }
    $stmt->bind_param('ss', $startDate, $endDate);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['error' => 'Unable to fetch creatives.']);
        exit;
    }
    $result = $stmt->get_result();
    $events = [];
    while ($row = $result->fetch_assoc()) {
        $events[] = $row;
    }
    $stmt->close();
    echo json_encode(
        [
            'month' => $month,
            'total' => count($events),
            'events' => $events
        ],
        JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.3.1/css/all.css" integrity="sha512-x9WwyMYBnlXMNQ6kQ/Lyzu1NqIhLQKL5Oq6xByfXuRj7s9CskyCbLv/1IjqzJmXwFXWr0ov6jBV7Qbc0hh9nHg==" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="../assets/css/admincalendar.css">
<section class="ac-shell" id="adminCalendar">
    <div class="ac-topbar">
        <div class="ac-heading">
            <span class="ac-eyebrow">
                WORKSPACE / CREATIVE OVERVIEW
            </span>
            <h2>Creative <span>Calendar</span></h2>
            <p>
                Track every creative, creator and publishing date
                from one place.
            </p>
        </div>
        <button type="button" class="ac-today-btn" id="acToday">
            <span>↗</span> Today
        </button>
    </div>
    <div class="ac-stats">
        <article class="ac-stat-card ac-stat-blue">
            <div class="ac-stat-icon">▦</div>
            <div>
                <span>Total Creatives</span>
                <strong id="acTotal">0</strong>
                <small>Selected month</small>
            </div>
        </article>
        <article class="ac-stat-card ac-stat-purple">
            <div class="ac-stat-icon">✦</div>
            <div>
                <span>Active Dates</span>
                <strong id="acActiveDates">0</strong>
                <small>Dates with creatives</small>
            </div>
        </article>
        <article class="ac-stat-card ac-stat-cyan">
            <div class="ac-stat-icon">✓</div>
            <div>
                <span>Approved</span>
                <strong id="acApproved">0</strong>
                <small>Approved creatives</small>
            </div>
        </article>
        <article class="ac-stat-card ac-stat-pink">
            <div class="ac-stat-icon">◷</div>
            <div>
                <span>Pending</span>
                <strong id="acPending">0</strong>
                <small>Awaiting review</small>
            </div>
        </article>
    </div>
    <div class="ac-workspace">
        <div class="ac-calendar-card">
            <div class="ac-calendar-toolbar">
                <div>
                    <h3 id="acMonthLabel">Loading month...</h3>
                    <p>Select a highlighted date to explore creatives.</p>
                </div>
                <div class="ac-month-controls">
                    <button type="button" id="acPrev"
                        aria-label="Previous month">‹</button>
                    <button type="button" id="acNext"
                        aria-label="Next month">›</button>
                </div>
            </div>
            <div class="ac-weekdays">
                <span>MON</span>
                <span>TUE</span>
                <span>WED</span>
                <span>THU</span>
                <span>FRI</span>
                <span>SAT</span>
                <span>SUN</span>
            </div>
            <div class="ac-grid" id="acGrid"></div>
            <div class="ac-legend">
                <span><i class="ac-dot ac-dot-purple"></i> Has creatives</span>
                <span><i class="ac-dot ac-dot-blue"></i> Today</span>
                <span><i class="ac-dot ac-dot-cyan"></i> Selected date</span>
            </div>
        </div>
        <aside class="ac-details-card" id="acDetails">
            <div class="ac-details-header">
                <div>
                    <span class="ac-eyebrow">DAILY OVERVIEW</span>
                    <h3 id="acSelectedDate">Select a date</h3>
                </div>
                <span class="ac-date-count" id="acDateCount">0</span>
            </div>
            <div class="ac-details-body" id="acCreativeList">
                <div class="ac-empty">
                    <div class="ac-empty-icon">✦</div>
                    <h4>Explore your calendar</h4>
                    <p>Choose a date to see all its creative details.</p>
                </div>
            </div>
        </aside>
    </div>
    <div class="ac-toast" id="acToast" role="status"></div>
</section>
<script src="../assets/js/admincalendar.js" defer></script>