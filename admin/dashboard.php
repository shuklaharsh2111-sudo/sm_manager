
<?php
/**
 * File: admin/dashboard.php
 * Content-only admin dashboard.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/../config/db.php';
}

if (!function_exists('cmDashboardEscape')) {
    function cmDashboardEscape($value): string
    {
        return htmlspecialchars(
            (string) ($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('cmDashboardCount')) {
    function cmDashboardCount(mysqli $conn, string $sql): int
    {
        try {
            $result = $conn->query($sql);
            if (!$result) {
                return 0;
            }

            $row = $result->fetch_assoc();
            return (int) ($row['total'] ?? 0);
        } catch (Throwable $e) {
            error_log('Admin dashboard count error: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('cmDashboardRows')) {
    function cmDashboardRows(mysqli $conn, string $sql): array
    {
        try {
            $result = $conn->query($sql);

            if (!$result) {
                return [];
            }

            return $result->fetch_all(MYSQLI_ASSOC);
        } catch (Throwable $e) {
            error_log('Admin dashboard query error: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('cmDashboardMoney')) {
    function cmDashboardMoney($amount): string
    {
        return '₹' . number_format((float) $amount, 2);
    }
}

/* -----------------------------
   Main summary cards
----------------------------- */

$totalUsers = cmDashboardCount(
    $conn,
    'SELECT COUNT(*) AS total FROM users'
);

$totalCompanies = cmDashboardCount(
    $conn,
    'SELECT COUNT(*) AS total FROM companies'
);

$totalCreatives = cmDashboardCount(
    $conn,
    'SELECT COUNT(*) AS total FROM creatives'
);

$totalInvoices = cmDashboardCount(
    $conn,
    'SELECT COUNT(*) AS total FROM invoices'
);

$totalInvoiceValue = 0.0;

$invoiceValueRows = cmDashboardRows(
    $conn,
    'SELECT COALESCE(SUM(total_amount), 0) AS total_value FROM invoices'
);

if (!empty($invoiceValueRows)) {
    $totalInvoiceValue = (float) $invoiceValueRows[0]['total_value'];
}

/* -----------------------------
   Count users by JSON role
----------------------------- */

$roleCounts = [
    'admin'   => 0,
    'sme'     => 0,
    'smi'     => 0,
    'des'     => 0,
    'clients' => 0,
    'other'   => 0,
];

$userRoleRows = cmDashboardRows(
    $conn,
    'SELECT role FROM users'
);

foreach ($userRoleRows as $userRoleRow) {
    $decodedRole = json_decode(
        (string) ($userRoleRow['role'] ?? ''),
        true
    );

    // Support both JSON array roles and a plain role string.
    if (is_array($decodedRole)) {
        $userRoles = $decodedRole;
    } elseif (is_string($decodedRole)) {
        $userRoles = [$decodedRole];
    } else {
        $userRoles = [];
    }

    $recognized = false;

    foreach ($userRoles as $userRole) {
        $userRole = strtolower(trim((string) $userRole));

        if (isset($roleCounts[$userRole])) {
            $roleCounts[$userRole]++;
            $recognized = true;
        }
    }

    if (!$recognized) {
        $roleCounts['other']++;
    }
}

/* -----------------------------
   Creative status summary
----------------------------- */

$creativeStatuses = [
    'draft'    => 0,
    'pending'  => 0,
    'approved' => 0,
    'rejected' => 0,
    'revision' => 0,
];

$creativeStatusRows = cmDashboardRows(
    $conn,
    'SELECT status, COUNT(*) AS total
     FROM creatives
     GROUP BY status'
);

foreach ($creativeStatusRows as $statusRow) {
    $status = strtolower((string) ($statusRow['status'] ?? ''));

    if (isset($creativeStatuses[$status])) {
        $creativeStatuses[$status] = (int) $statusRow['total'];
    }
}

$approvalPercentage = $totalCreatives > 0
    ? round(
        ($creativeStatuses['approved'] / $totalCreatives) * 100
    )
    : 0;

/* -----------------------------
   Platform breakdown
----------------------------- */

$platformRows = cmDashboardRows(
    $conn,
    'SELECT platform, COUNT(*) AS total
     FROM creatives
     GROUP BY platform
     ORDER BY total DESC'
);

$platformMaximum = 1;

foreach ($platformRows as $platformRow) {
    $platformMaximum = max(
        $platformMaximum,
        (int) $platformRow['total']
    );
}

/* -----------------------------
   Company and assignment summary
----------------------------- */

$totalAssignments = cmDashboardCount(
    $conn,
    'SELECT COUNT(*) AS total FROM sme_company_assignments'
);

$totalSops = cmDashboardCount(
    $conn,
    'SELECT COUNT(*) AS total FROM company_sops'
);

/* -----------------------------
   Upcoming meetings
----------------------------- */

$upcomingMeetings = cmDashboardRows(
    $conn,
    "SELECT
        m.id,
        m.subject,
        m.meeting_date,
        m.meeting_time,
        m.meeting_link,
        m.meeting_status,
        c.comp_name,
        u.fullname AS sme_name
     FROM sme_meetings m
     LEFT JOIN companies c ON c.id = m.company_id
     LEFT JOIN users u ON u.id = m.sme_user_id
     WHERE m.meeting_date >= CURDATE()
       AND m.meeting_status = 'not_done'
     ORDER BY m.meeting_date ASC, m.meeting_time ASC
     LIMIT 5"
);

/* -----------------------------
   Recent creatives
----------------------------- */

$recentCreatives = cmDashboardRows(
    $conn,
    'SELECT
        cr.id,
        cr.title,
        cr.platform,
        cr.creative_date,
        cr.status,
        u.fullname AS creator_name,
        c.comp_name
     FROM creatives cr
     LEFT JOIN users u ON u.id = cr.user_id
     LEFT JOIN companies c ON c.id = cr.company_id
     ORDER BY cr.created_at DESC, cr.id DESC
     LIMIT 6'
);

$todayLabel = date('l, d F Y');

$statusLabels = [
    'draft'    => 'Draft',
    'pending'  => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'revision' => 'Revision',
];

$platformLabels = [
    'instagram' => 'Instagram',
    'facebook'  => 'Facebook',
    'linkedin'  => 'LinkedIn',
    'google'    => 'Google',
    'youtube'   => 'YouTube',
    'x'         => 'X',
    'pinterest' => 'Pinterest',
    'threads'   => 'Threads',
];
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.3.1/css/all.css" integrity="sha512-x9WwyMYBnlXMNQ6kQ/Lyzu1NqIhLQKL5Oq6xByfXuRj7s9CskyCbLv/1IjqzJmXwFXWr0ov6jBV7Qbc0hh9nHg==" crossorigin="anonymous" referrerpolicy="no-referrer">

<link rel="stylesheet" href="../assets/css/admindashboard.css">

<div class="cm-dashboard" id="cmDashboard">

    <header class="cm-dashboard-header">
        <div>
            <span class="cm-dashboard-eyebrow">ADMINISTRATION</span>
            <h1>Dashboard Overview</h1>
            <p>Monitor your team, creative workflow and business activity.</p>
        </div>

        <div class="cm-dashboard-date">
            <span class="cm-date-dot"></span>
            <span id="cmCurrentDate">
                <?= cmDashboardEscape($todayLabel) ?>
            </span>
        </div>
    </header>

    <!-- Main statistics -->
    <section class="cm-stat-grid" aria-label="Business statistics">

        <article class="cm-stat-card">
            <div class="cm-stat-top">
                <span class="cm-stat-icon cm-icon-users">
                    <i class="fa-solid fa-users"></i>
                </span>
                <span class="cm-stat-label">Accounts</span>
            </div>
            <div class="cm-stat-number">
                <?= number_format($totalUsers) ?>
            </div>
            <div class="cm-stat-bottom">
                <span><?= number_format($roleCounts['sme']) ?> SMEs</span>
                <span><?= number_format($roleCounts['des']) ?> Designers</span>
            </div>
            <a class="cm-card-link" href="admin.php?page=users">
                Manage users <i class="fa-solid fa-arrow-right"></i>
            </a>
        </article>

        <article class="cm-stat-card">
            <div class="cm-stat-top">
                <span class="cm-stat-icon cm-icon-companies">
                    <i class="fa-solid fa-building"></i>
                </span>
                <span class="cm-stat-label">Companies</span>
            </div>
            <div class="cm-stat-number">
                <?= number_format($totalCompanies) ?>
            </div>
            <div class="cm-stat-bottom">
                <span><?= number_format($totalAssignments) ?> assignments</span>
            </div>
            <a class="cm-card-link" href="admin.php?page=companies">
                Manage companies <i class="fa-solid fa-arrow-right"></i>
            </a>
        </article>

        <article class="cm-stat-card">
            <div class="cm-stat-top">
                <span class="cm-stat-icon cm-icon-creatives">
                    <i class="fa-solid fa-images"></i>
                </span>
                <span class="cm-stat-label">Total Creatives</span>
            </div>
            <div class="cm-stat-number">
                <?= number_format($totalCreatives) ?>
            </div>
            <div class="cm-stat-bottom">
                <span><?= number_format($creativeStatuses['pending']) ?> awaiting review</span>
            </div>
            <a class="cm-card-link" href="admin.php?page=creatives">
                View creatives <i class="fa-solid fa-arrow-right"></i>
            </a>
        </article>

        <article class="cm-stat-card">
            <div class="cm-stat-top">
                <span class="cm-stat-icon cm-icon-invoices">
                    <i class="fa-solid fa-file-invoice"></i>
                </span>
                <span class="cm-stat-label">Invoices</span>
            </div>
            <div class="cm-stat-number">
                <?= number_format($totalInvoices) ?>
            </div>
            <div class="cm-stat-bottom">
                <span>Total invoiced value</span>
                <strong><?= cmDashboardEscape(cmDashboardMoney($totalInvoiceValue)) ?></strong>
            </div>
            <a class="cm-card-link" href="admin.php?page=invoices">
                View invoices <i class="fa-solid fa-arrow-right"></i>
            </a>
        </article>

    </section>

    <!-- Creative analytics and team roles -->
    <section class="cm-dashboard-grid">

        <article class="cm-panel cm-status-panel">
            <div class="cm-panel-heading">
                <div>
                    <span class="cm-section-kicker">WORKFLOW</span>
                    <h2>Creative Status</h2>
                    <p>Current status of all submitted creatives.</p>
                </div>
                <span class="cm-panel-icon">
                    <i class="fa-solid fa-chart-pie"></i>
                </span>
            </div>

            <div class="cm-status-total">
                <div>
                    <span class="cm-muted-label">Approval rate</span>
                    <strong><?= $approvalPercentage ?>%</strong>
                </div>
                <span class="cm-status-total-count">
                    <?= number_format($totalCreatives) ?> total
                </span>
            </div>

            <div class="cm-approval-track" role="progressbar"
                 aria-label="Creative approval rate"
                 aria-valuemin="0" aria-valuemax="100"
                 aria-valuenow="<?= $approvalPercentage ?>">
                <span style="width: <?= $approvalPercentage ?>%"></span>
            </div>

            <div class="cm-status-list">
                <?php foreach ($creativeStatuses as $status => $count): ?>
                    <div class="cm-status-row">
                        <div class="cm-status-name">
                            <span class="cm-status-dot cm-dot-<?= cmDashboardEscape($status) ?>"></span>
                            <span><?= cmDashboardEscape($statusLabels[$status]) ?></span>
                        </div>
                        <strong><?= number_format($count) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="cm-panel cm-team-panel">
            <div class="cm-panel-heading">
                <div>
                    <span class="cm-section-kicker">PEOPLE</span>
                    <h2>Team Distribution</h2>
                    <p>Accounts grouped by their assigned role.</p>
                </div>
                <span class="cm-panel-icon">
                    <i class="fa-solid fa-people-group"></i>
                </span>
            </div>

            <?php
            $teamRoles = [
                'admin'   => ['label' => 'Administrators', 'icon' => 'fa-user-shield'],
                'sme'     => ['label' => 'SMEs', 'icon' => 'fa-user-tie'],
                'smi'     => ['label' => 'Social Media Managers', 'icon' => 'fa-hashtag'],
                'des'     => ['label' => 'Designers', 'icon' => 'fa-pen-nib'],
                'clients' => ['label' => 'Clients', 'icon' => 'fa-handshake'],
                'other'   => ['label' => 'Other / Unassigned', 'icon' => 'fa-user'],
            ];
            ?>

            <div class="cm-team-list">
                <?php foreach ($teamRoles as $roleKey => $roleInfo): ?>
                    <?php
                    $roleCount = $roleCounts[$roleKey];
                    $rolePercent = $totalUsers > 0
                        ? round(($roleCount / $totalUsers) * 100)
                        : 0;
                    ?>
                    <div class="cm-team-row">
                        <div class="cm-team-row-top">
                            <div class="cm-team-role">
                                <span class="cm-team-role-icon">
                                    <i class="fa-solid <?= cmDashboardEscape($roleInfo['icon']) ?>"></i>
                                </span>
                                <span><?= cmDashboardEscape($roleInfo['label']) ?></span>
                            </div>
                            <strong><?= number_format($roleCount) ?></strong>
                        </div>
                        <div class="cm-team-track">
                            <span style="width: <?= $rolePercent ?>%"></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

    </section>

    <!-- Platform breakdown -->
    <section class="cm-panel cm-platform-panel">
        <div class="cm-panel-heading">
            <div>
                <span class="cm-section-kicker">SOCIAL CHANNELS</span>
                <h2>Platform Activity</h2>
                <p>Creative count by social media platform.</p>
            </div>
        </div>

        <?php if (empty($platformRows)): ?>
            <div class="cm-empty-state">
                <i class="fa-solid fa-chart-column"></i>
                <p>No platform activity to display yet.</p>
            </div>
        <?php else: ?>
            <div class="cm-platform-grid">
                <?php foreach ($platformRows as $platformRow): ?>
                    <?php
                    $platformKey = strtolower((string) $platformRow['platform']);
                    $platformCount = (int) $platformRow['total'];
                    $platformPercent = round(
                        ($platformCount / $platformMaximum) * 100
                    );
                    ?>
                    <div class="cm-platform-item">
                        <div class="cm-platform-info">
                            <span><?= cmDashboardEscape(
                                $platformLabels[$platformKey] ?? ucfirst($platformKey)
                            ) ?></span>
                            <strong><?= number_format($platformCount) ?></strong>
                        </div>
                        <div class="cm-platform-track">
                            <span style="width: <?= $platformPercent ?>%"></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Recent creatives -->
    <section class="cm-panel cm-recent-panel">
        <div class="cm-panel-heading cm-recent-heading">
            <div>
                <span class="cm-section-kicker">LATEST ACTIVITY</span>
                <h2>Recent Creatives</h2>
                <p>The latest creative entries recorded in the database.</p>
            </div>
            <a class="cm-outline-link" href="admin.php?page=creatives">
                View all <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <?php if (empty($recentCreatives)): ?>
            <div class="cm-empty-state">
                <i class="fa-regular fa-images"></i>
                <p>No creatives have been added yet.</p>
            </div>
        <?php else: ?>
            <div class="cm-table-wrap">
                <table class="cm-data-table">
                    <thead>
                        <tr>
                            <th>Creative</th>
                            <th>Company</th>
                            <th>Created By</th>
                            <th>Platform</th>
                            <th>Creative Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentCreatives as $creative): ?>
                            <?php
                            $status = strtolower((string) ($creative['status'] ?? 'draft'));
                            $statusLabel = $statusLabels[$status] ?? ucfirst($status);
                            $platformKey = strtolower((string) ($creative['platform'] ?? ''));
                            ?>
                            <tr data-creative-status="<?= cmDashboardEscape($status) ?>">
                                <td>
                                    <span class="cm-creative-title">
                                        <?= cmDashboardEscape($creative['title']) ?>
                                    </span>
                                    <small>#<?= (int) $creative['id'] ?></small>
                                </td>
                                <td><?= cmDashboardEscape($creative['comp_name'] ?: '—') ?></td>
                                <td><?= cmDashboardEscape($creative['creator_name'] ?: '—') ?></td>
                                <td>
                                    <span class="cm-platform-tag">
                                        <?= cmDashboardEscape(
                                            $platformLabels[$platformKey] ?? ucfirst($platformKey)
                                        ) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= !empty($creative['creative_date'])
                                        ? cmDashboardEscape(
                                            date('d M Y', strtotime($creative['creative_date']))
                                        )
                                        : '—' ?>
                                </td>
                                <td>
                                    <span class="cm-status-badge cm-badge-<?= cmDashboardEscape($status) ?>">
                                        <?= cmDashboardEscape($statusLabel) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- Upcoming meetings and SOPs -->
    <section class="cm-dashboard-grid cm-bottom-grid">

        <article class="cm-panel cm-meetings-panel">
            <div class="cm-panel-heading">
                <div>
                    <span class="cm-section-kicker">SCHEDULE</span>
                    <h2>Upcoming Meetings</h2>
                    <p>Next scheduled meetings that are not marked done.</p>
                </div>
                <span class="cm-panel-icon">
                    <i class="fa-solid fa-calendar-check"></i>
                </span>
            </div>

            <?php if (empty($upcomingMeetings)): ?>
                <div class="cm-empty-state">
                    <i class="fa-regular fa-calendar"></i>
                    <p>No upcoming meetings found.</p>
                </div>
            <?php else: ?>
                <div class="cm-meeting-list">
                    <?php foreach ($upcomingMeetings as $meeting): ?>
                        <article class="cm-meeting-item">
                            <div class="cm-meeting-date">
                                <strong><?= cmDashboardEscape(
                                    date('d', strtotime($meeting['meeting_date']))
                                ) ?></strong>
                                <span><?= cmDashboardEscape(
                                    date('M', strtotime($meeting['meeting_date']))
                                ) ?></span>
                            </div>
                            <div class="cm-meeting-details">
                                <h3><?= cmDashboardEscape($meeting['subject']) ?></h3>
                                <p><?= cmDashboardEscape($meeting['comp_name'] ?: 'Company not found') ?></p>
                                <small>
                                    <?= cmDashboardEscape(
                                        date('h:i A', strtotime($meeting['meeting_time']))
                                    ) ?>
                                    <?php if (!empty($meeting['sme_name'])): ?>
                                        &middot; <?= cmDashboardEscape($meeting['sme_name']) ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <?php if (!empty($meeting['meeting_link'])): ?>
                                <a class="cm-meeting-link"
                                   href="<?= cmDashboardEscape($meeting['meeting_link']) ?>"
                                   target="_blank" rel="noopener noreferrer"
                                   aria-label="Join meeting">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                </a>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>

        <article class="cm-panel cm-sop-panel">
            <div class="cm-panel-heading">
                <div>
                    <span class="cm-section-kicker">OPERATIONS</span>
                    <h2>Company SOPs</h2>
                    <p>Standard operating procedures recorded for companies.</p>
                </div>
                <span class="cm-panel-icon">
                    <i class="fa-solid fa-list-check"></i>
                </span>
            </div>

            <div class="cm-sop-total">
                <span class="cm-sop-icon">
                    <i class="fa-solid fa-clipboard-check"></i>
                </span>
                <div>
                    <strong><?= number_format($totalSops) ?></strong>
                    <span>Total SOP entries</span>
                </div>
            </div>

            <div class="cm-sop-note">
                SOP entries include daily, weekly and monthly procedures.
            </div>

            <a class="cm-outline-link cm-sop-link" href="admin.php?page=companies">
                Manage companies and SOPs
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </article>

    </section>

    <footer class="cm-dashboard-footer">
        <span>Creative Manager</span>
        <span>Dashboard data is loaded from your database.</span>
    </footer>

</div>

<script src="../assets/js/admindashboard.js"></script>