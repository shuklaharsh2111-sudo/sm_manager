<?php
/*
 * DES Creative Calendar
 * Parent dashboard must initialize the session and authorize access.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/../config/db.php';
}
$calendarUserId = (int) ($_SESSION['user_id'] ?? 0);
$calendarCreatives = [];
$calendarError = '';
/* CSRF protection for the Add Creative form. */
if (empty($_SESSION['des_calendar_csrf'])) {
    $_SESSION['des_calendar_csrf'] = bin2hex(random_bytes(32));
}
$calendarCsrf = $_SESSION['des_calendar_csrf'];
/* Load creatives assigned to the current calendar user. */
if ($calendarUserId > 0) {
    $sql = "
        SELECT
            c.id,
            c.creative_date,
            c.title,
            c.caption,
            c.description,
            c.image_path,
            c.reference_link,
            c.platform,
            c.status,
            c.company_id,
            co.comp_name,
            co.comp_logo,
            creator.fullname AS creator_name,
            assignment.assigned_at
        FROM creative_des_assignments AS assignment
        INNER JOIN creatives AS c
            ON c.id = assignment.creative_id
        LEFT JOIN companies AS co
            ON co.id = c.company_id
        LEFT JOIN users AS creator
            ON creator.id = c.user_id
        WHERE assignment.smi_user_id = ?
        ORDER BY c.creative_date ASC, c.id DESC
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $calendarUserId);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $calendarCreatives[] = [
                    'id'          => (int) $row['id'],
                    'date'        => $row['creative_date'],
                    'title'       => $row['title'] ?? '',
                    'caption'     => $row['caption'] ?? '',
                    'description' => $row['description'] ?? '',
                    'image'       => $row['image_path'] ?? '',
                    'reference'   => $row['reference_link'] ?? '',
                    'platform'    => $row['platform'] ?? '',
                    'status'      => $row['status'] ?? 'draft',
                    'company'     => $row['comp_name'] ?? 'Unassigned Company',
                    'company_logo' => $row['comp_logo'] ?? '',
                    'creator'     => $row['creator_name'] ?? 'Unknown',
                    'assigned_at' => $row['assigned_at'] ?? ''
                ];
            }
        } else {
            $calendarError = 'Unable to load assigned creatives.';
            error_log('Calendar execute error: ' . $stmt->error);
        }
        $stmt->close();
    } else {
        $calendarError = 'Unable to prepare calendar query.';
        error_log('Calendar prepare error: ' . $conn->error);
    }
} else {
    $calendarError = 'User session is missing. Please log in again.';
}
$calendarJson = json_encode(
    $calendarCreatives,
    JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_AMP |
        JSON_HEX_QUOT |
        JSON_INVALID_UTF8_SUBSTITUTE
);
if ($calendarJson === false) {
    $calendarJson = '[]';
}
?>
<link rel="stylesheet" href="css/calendar.css">
<section
    class="des-calendar"
    id="desCalendar"
    data-calendar-user="<?= $calendarUserId ?>"
    data-save-url="save_creative.php"
    data-csrf="<?= htmlspecialchars($calendarCsrf, ENT_QUOTES, 'UTF-8') ?>">
    <div class="cal-orb cal-orb-one"></div>
    <div class="cal-orb cal-orb-two"></div>
    <div class="cal-header">
        <div class="cal-heading">
            <span class="cal-eyebrow">
                <span class="cal-live-dot"></span>
                WORKSPACE / CREATIVE SCHEDULE
            </span>
            <h1>Creative <span>Calendar</span></h1>
            <p>Your assigned creatives, organized day by day.</p>
        </div>
        <div class="cal-header-badge">
            <span class="cal-badge-icon">✦</span>
            <div>
                <strong>DES Workspace</strong>
                <small>Assignment overview</small>
            </div>
        </div>
    </div>
    <?php if ($calendarError !== ''): ?>
        <div class="cal-error" role="alert">
            <?= htmlspecialchars($calendarError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
    <div class="cal-stats">
        <article class="cal-stat-card">
            <span class="cal-stat-icon violet">▦</span>
            <div><span>Total assigned</span><strong id="calTotal">0</strong></div>
        </article>
        <article class="cal-stat-card">
            <span class="cal-stat-icon blue">◷</span>
            <div><span>This month</span><strong id="calMonthTotal">0</strong></div>
        </article>
        <article class="cal-stat-card">
            <span class="cal-stat-icon green">✓</span>
            <div><span>Approved</span><strong id="calApproved">0</strong></div>
        </article>
        <article class="cal-stat-card">
            <span class="cal-stat-icon pink">◈</span>
            <div><span>Pending / Revision</span><strong id="calPending">0</strong></div>
        </article>
    </div>
    <div class="cal-main-grid">
        <div class="cal-calendar-panel">
            <div class="cal-toolbar">
                <div class="cal-month-title">
                    <h2 id="calMonthLabel">Loading calendar...</h2>
                    <p>Click any date to add a creative</p>
                </div>
                <div class="cal-nav">
                    <button type="button" id="calPrev" aria-label="Previous month">&#8249;</button>
                    <button type="button" id="calToday" class="cal-today-btn">Today</button>
                    <button type="button" id="calNext" aria-label="Next month">&#8250;</button>
                </div>
            </div>
            <div class="cal-weekdays" aria-hidden="true">
                <span>Sun</span><span>Mon</span><span>Tue</span>
                <span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
            </div>
            <div class="cal-days" id="calDays" aria-label="Creative calendar dates"></div>
            <div class="cal-legend">
                <span><i class="legend-dot approved-dot"></i> Approved</span>
                <span><i class="legend-dot pending-dot"></i> Pending</span>
                <span><i class="legend-dot revision-dot"></i> Revision</span>
                <span><i class="legend-dot other-dot"></i> Other</span>
            </div>
        </div>
        <aside class="cal-agenda-panel">
            <div class="cal-agenda-heading">
                <div>
                    <span class="cal-agenda-eyebrow">DAILY OVERVIEW</span>
                    <h3 id="calSelectedLabel">Select a date</h3>
                </div>
                <span class="cal-date-count" id="calSelectedCount">0</span>
            </div>
            <div class="cal-agenda-list" id="calAgenda">
                <div class="cal-empty">
                    <div class="cal-empty-icon">▦</div>
                    <h4>Select a calendar date</h4>
                    <p>Assigned creatives will appear here.</p>
                </div>
            </div>
        </aside>
    </div>
    <script type="application/json" id="calCreativeData">
        <?= $calendarJson ?>
    </script>
</section>
<!-- One modal is reused for Add Creative and View Details. -->
<div class="cal-modal" id="calModal" aria-hidden="true">
    <div class="cal-modal-backdrop" data-close-calendar-modal></div>
    <div
        class="cal-modal-card"
        id="calModalContent"
        role="dialog"
        aria-modal="true"
        aria-labelledby="calModalTitle"
        tabindex="-1">
        <button
            type="button"
            class="cal-modal-close"
            data-close-calendar-modal
            aria-label="Close modal">&times;</button>
        <div id="calModalBody"></div>
    </div>
</div>
<script src="js/calendar.js" defer></script>