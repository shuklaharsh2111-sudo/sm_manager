
<?php
/*
|--------------------------------------------------------------------------
| SME Monthly Reports
| File: sme/monthly_reports.php
| Requires: ../config/db.php ($conn = mysqli connection)
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['user_id'])) {
    if (isset($_GET['report_api'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Please log in again.'
        ]);
        exit;
    }

    exit('Please log in first.');
}

require_once __DIR__ . '/../config/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    if (isset($_GET['report_api'])) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Database connection $conn was not found.'
        ]);
        exit;
    }

    exit('Database connection not found. Check config/db.php.');
}

$conn->set_charset('utf8mb4');

$reportUserId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Verify actual SME role from database
|--------------------------------------------------------------------------
*/

$roleStmt = $conn->prepare(
    "SELECT role FROM users WHERE id = ? LIMIT 1"
);
$roleStmt->bind_param('i', $reportUserId);
$roleStmt->execute();
$roleRow = $roleStmt->get_result()->fetch_assoc();
$roleStmt->close();

$roles = [];

if ($roleRow) {
    $decodedRoles = json_decode($roleRow['role'] ?? '', true);

    if (is_array($decodedRoles)) {
        $roles = $decodedRoles;
    } else {
        $roles = [(string) ($roleRow['role'] ?? '')];
    }
}

$roles = array_map(
    static fn($role) => strtolower(trim((string) $role)),
    $roles
);

if (!in_array('sme', $roles, true)) {
    if (isset($_GET['report_api'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'SME access required.'
        ]);
        exit;
    }

    http_response_code(403);
    exit('Access denied. SME account required.');
}

/*
|--------------------------------------------------------------------------
| Reporting period
|--------------------------------------------------------------------------
*/

$requestedMonth = $_GET['month'] ?? date('Y-m');

if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requestedMonth)) {
    $requestedMonth = date('Y-m');
}

$startDate = $requestedMonth . '-01';
$endDate = date(
    'Y-m-d',
    strtotime($startDate . ' +1 month')
);
$previousStart = date(
    'Y-m-d',
    strtotime($startDate . ' -1 month')
);

$monthLabel = date('F Y', strtotime($startDate));

/*
|--------------------------------------------------------------------------
| API response helper
|--------------------------------------------------------------------------
*/

function mrFetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Could not prepare report query.');
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function mrFetchOne(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $rows = mrFetchAll($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

/*
|--------------------------------------------------------------------------
| Build report
|--------------------------------------------------------------------------
*/

function mrBuildReport(
    mysqli $conn,
    int $userId,
    string $startDate,
    string $endDate,
    string $previousStart,
    string $monthLabel
): array {

    /*
     * Only companies assigned to the logged-in SME.
     */
    $companies = mrFetchAll(
        $conn,
        "SELECT c.id, c.comp_name, c.comp_logo
         FROM sme_company_assignments a
         INNER JOIN companies c ON c.id = a.company_id
         WHERE a.sme_user_id = ?
         ORDER BY c.comp_name ASC",
        'i',
        [$userId]
    );

    $companyIds = array_map(
        static fn($company) => (int) $company['id'],
        $companies
    );

    $emptyStats = [
        'total' => 0,
        'approved' => 0,
        'pending' => 0,
        'revision' => 0,
        'rejected' => 0,
        'draft' => 0,
        'delays' => 0,
        'overdue' => 0
    ];

    $stats = $emptyStats;
    $previousStats = $emptyStats;
    $companyReports = [];
    $platformReports = [];
    $recentCreatives = [];

    if ($companyIds) {
        $placeholders = implode(
            ',',
            array_fill(0, count($companyIds), '?')
        );

        $companyTypes = str_repeat('i', count($companyIds));

        /*
         * Current month creative statistics.
         */
        $stats = array_merge(
            $emptyStats,
            mrFetchOne(
                $conn,
                "SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM(status = 'approved'), 0) AS approved,
                    COALESCE(SUM(status = 'pending'), 0) AS pending,
                    COALESCE(SUM(status = 'revision'), 0) AS revision,
                    COALESCE(SUM(status = 'rejected'), 0) AS rejected,
                    COALESCE(SUM(status = 'draft'), 0) AS draft,
                        COALESCE(SUM(status <> 'draft' AND DATE(created_at) > creative_date), 0) AS delays,
                        COALESCE(SUM(creative_date < CURDATE() AND status IN ('pending', 'revision')), 0) AS overdue
                 FROM creatives
                 WHERE company_id IN ($placeholders)
                   AND creative_date >= ?
                   AND creative_date < ?",
                $companyTypes . 'ss',
                array_merge($companyIds, [$startDate, $endDate])
            )
        );

        /*
         * Previous month: total creatives for comparison.
         */
        $previousStats = array_merge(
            $emptyStats,
            mrFetchOne(
                $conn,
                "SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM(status = 'approved'), 0) AS approved,
                    COALESCE(SUM(status = 'pending'), 0) AS pending,
                    COALESCE(SUM(status = 'revision'), 0) AS revision,
                    COALESCE(SUM(status = 'rejected'), 0) AS rejected,
                    COALESCE(SUM(status = 'draft'), 0) AS draft,
                        COALESCE(SUM(status <> 'draft' AND DATE(created_at) > creative_date), 0) AS delays,
                        COALESCE(SUM(creative_date < CURDATE() AND status IN ('pending', 'revision')), 0) AS overdue
                 FROM creatives
                 WHERE company_id IN ($placeholders)
                   AND creative_date >= ?
                   AND creative_date < ?",
                $companyTypes . 'ss',
                array_merge($companyIds, [$previousStart, $startDate])
            )
        );

        /*
         * Company-wise creative performance.
         */
        $companyReports = mrFetchAll(
            $conn,
            "SELECT
                c.id AS company_id,
                c.comp_name,
                COUNT(cr.id) AS total,
                COALESCE(SUM(cr.status = 'approved'), 0) AS approved,
                COALESCE(SUM(cr.status = 'pending'), 0) AS pending,
                COALESCE(SUM(cr.status = 'revision'), 0) AS revision,
                COALESCE(SUM(cr.status = 'rejected'), 0) AS rejected,
                COALESCE(SUM(cr.status = 'draft'), 0) AS draft,
                COALESCE(SUM(cr.status <> 'draft' AND DATE(cr.created_at) > cr.creative_date), 0) AS delays,
                COALESCE(SUM(cr.creative_date < CURDATE() AND cr.status IN ('pending', 'revision')), 0) AS overdue
             FROM companies c
             INNER JOIN sme_company_assignments a
                ON a.company_id = c.id
             LEFT JOIN creatives cr
                ON cr.company_id = c.id
               AND cr.creative_date >= ?
               AND cr.creative_date < ?
             WHERE a.sme_user_id = ?
             GROUP BY c.id, c.comp_name
             ORDER BY total DESC, c.comp_name ASC",
            'ssi',
            [$startDate, $endDate, $userId]
        );

        /*
         * Platform-wise performance.
         */
        $platformReports = mrFetchAll(
            $conn,
            "SELECT
                platform,
                COUNT(*) AS total,
                COALESCE(SUM(status = 'approved'), 0) AS approved,
                COALESCE(SUM(status = 'pending'), 0) AS pending,
                COALESCE(SUM(status = 'revision'), 0) AS revision,
                COALESCE(SUM(status = 'rejected'), 0) AS rejected,
                COALESCE(SUM(status = 'draft'), 0) AS draft,
                    COALESCE(SUM(status <> 'draft' AND DATE(created_at) > creative_date), 0) AS delays,
                    COALESCE(SUM(creative_date < CURDATE() AND status IN ('pending', 'revision')), 0) AS overdue
             FROM creatives
             WHERE company_id IN ($placeholders)
               AND creative_date >= ?
               AND creative_date < ?
             GROUP BY platform
             ORDER BY total DESC, platform ASC",
            $companyTypes . 'ss',
            array_merge($companyIds, [$startDate, $endDate])
        );

        /*
         * Every creative of the month (capped at 300 rows), with timing flags.
         */
        $recentCreatives = mrFetchAll(
            $conn,
            "SELECT
                cr.id,
                cr.title,
                cr.platform,
                cr.status,
                cr.creative_date,
                cr.created_at,
                cr.revision_reason,
                c.comp_name,
                (cr.status <> 'draft' AND DATE(cr.created_at) > cr.creative_date) AS is_delayed,
                (cr.creative_date < CURDATE() AND cr.status IN ('pending', 'revision')) AS is_overdue
             FROM creatives cr
             INNER JOIN companies c ON c.id = cr.company_id
             WHERE cr.company_id IN ($placeholders)
               AND cr.creative_date >= ?
               AND cr.creative_date < ?
             ORDER BY cr.creative_date ASC, cr.id ASC
             LIMIT 300",
            $companyTypes . 'ss',
            array_merge($companyIds, [$startDate, $endDate])
        );
    }

    /*
     * SOPs scheduled during the selected month.
     * The schema does not contain SOP completion status.
     */
    $sopStats = [
        'total' => 0,
        'daily' => 0,
        'weekly' => 0,
        'monthly' => 0
    ];

    if ($companyIds) {
        $placeholders = implode(
            ',',
            array_fill(0, count($companyIds), '?')
        );

        $sopRows = mrFetchOne(
            $conn,
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(sop_type = 'daily'), 0) AS daily,
                COALESCE(SUM(sop_type = 'weekly'), 0) AS weekly,
                COALESCE(SUM(sop_type = 'monthly'), 0) AS monthly
             FROM company_sops
             WHERE company_id IN ($placeholders)
               AND sop_date >= ?
               AND sop_date < ?",
            str_repeat('i', count($companyIds)) . 'ss',
            array_merge($companyIds, [$startDate, $endDate])
        );

        $sopStats = array_merge($sopStats, $sopRows);
    }

    /*
     * Meetings belonging to this SME and assigned companies.
     */
    $meetingStats = [
        'total' => 0,
        'done' => 0,
        'not_done' => 0
    ];

    $meetingReports = [];

    if ($companyIds) {
        $placeholders = implode(
            ',',
            array_fill(0, count($companyIds), '?')
        );

        $meetingStats = array_merge(
            $meetingStats,
            mrFetchOne(
                $conn,
                "SELECT
                    COUNT(*) AS total,
                    COALESCE(SUM(meeting_status = 'done'), 0) AS done,
                    COALESCE(SUM(meeting_status = 'not_done'), 0) AS not_done
                 FROM sme_meetings
                 WHERE sme_user_id = ?
                   AND company_id IN ($placeholders)
                   AND meeting_date >= ?
                   AND meeting_date < ?",
                'i' . str_repeat('i', count($companyIds)) . 'ss',
                array_merge(
                    [$userId],
                    $companyIds,
                    [$startDate, $endDate]
                )
            )
        );

        $meetingReports = mrFetchAll(
            $conn,
            "SELECT
                c.comp_name,
                COUNT(m.id) AS total,
                COALESCE(SUM(m.meeting_status = 'done'), 0) AS done,
                COALESCE(SUM(m.meeting_status = 'not_done'), 0) AS not_done
             FROM companies c
             INNER JOIN sme_company_assignments a
                ON a.company_id = c.id
             LEFT JOIN sme_meetings m
                ON m.company_id = c.id
               AND m.sme_user_id = ?
               AND m.meeting_date >= ?
               AND m.meeting_date < ?
             WHERE a.sme_user_id = ?
             GROUP BY c.id, c.comp_name
             ORDER BY c.comp_name ASC",
            'issi',
            [$userId, $startDate, $endDate, $userId]
        );
    }

    /*
     * SOP ticks saved during the month (table is created on first use by
     * the dashboard, so it may not exist yet).
     */
    $sopDone = null;

    try {
        $tick = mrFetchOne(
            $conn,
            "SELECT COUNT(*) AS n
             FROM sme_sop_completions
             WHERE sme_user_id = ?
               AND completed_at >= ?
               AND completed_at < ?",
            'iss',
            [$userId, $startDate, $endDate]
        );
        $sopDone = (int) ($tick['n'] ?? 0);
    } catch (Throwable $e) {
        $sopDone = null;
    }

    /*
     * Individual meetings of the month.
     */
    $meetingList = [];

    if ($companyIds) {
        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));

        $meetingList = mrFetchAll(
            $conn,
            "SELECT
                m.meeting_date,
                m.meeting_time,
                m.subject,
                m.meeting_status,
                m.mom_status,
                c.comp_name
             FROM sme_meetings m
             INNER JOIN companies c ON c.id = m.company_id
             WHERE m.sme_user_id = ?
               AND m.company_id IN ($placeholders)
               AND m.meeting_date >= ?
               AND m.meeting_date < ?
             ORDER BY m.meeting_date ASC, m.meeting_time ASC
             LIMIT 200",
            'i' . str_repeat('i', count($companyIds)) . 'ss',
            array_merge([$userId], $companyIds, [$startDate, $endDate])
        );
    }

    /*
     * Health rules (same as SME Company Health):
     *   Healthy  : below the At Risk limits
     *   At Risk  : 2+ delays, 4+ revisions, 2+ rejections or 2+ overdue
     *   Critical : 3+ delays, 5+ revisions, 3+ rejections or 3+ overdue
     */
    $healthOf = static function (array $row): array {
        $nonDraft = (int) ($row['total'] ?? 0) - (int) ($row['draft'] ?? 0);
        $d = (int) ($row['delays'] ?? 0);
        $v = (int) ($row['revision'] ?? 0);
        $r = (int) ($row['rejected'] ?? 0);
        $o = (int) ($row['overdue'] ?? 0);

        if ($nonDraft <= 0) {
            return ['nodata', 'No data'];
        }
        if ($d >= 3 || $v >= 5 || $r >= 3 || $o >= 3) {
            return ['critical', 'Critical'];
        }
        if ($d >= 2 || $v >= 4 || $r >= 2 || $o >= 2) {
            return ['risk', 'At Risk'];
        }
        return ['healthy', 'Healthy'];
    };

    foreach ($companyReports as &$companyRow) {
        [$companyRow['health'], $companyRow['health_label']] = $healthOf($companyRow);
    }
    unset($companyRow);

    [$overallHealth, $overallHealthLabel] = $healthOf($stats);

    /*
     * SME details for the report header.
     */
    $smeRow = mrFetchOne(
        $conn,
        "SELECT fullname, email, reporting_manager FROM users WHERE id = ? LIMIT 1",
        'i',
        [$userId]
    );

    $total = (int) $stats['total'];
    $approved = (int) $stats['approved'];
    $previousTotal = (int) $previousStats['total'];

    $approvalRate = $total > 0
        ? round(($approved / $total) * 100, 1)
        : 0;

    $previousApprovalRate = $previousTotal > 0
        ? round(
            ((int) $previousStats['approved'] / $previousTotal) * 100,
            1
        )
        : 0;

    $creativeChange = $previousTotal > 0
        ? round((($total - $previousTotal) / $previousTotal) * 100, 1)
        : null;

    return [
        'month' => $monthLabel,
        'period_text' => date('d M Y', strtotime($startDate))
            . ' – ' . date('d M Y', strtotime($endDate . ' -1 day')),
        'previous_month' => date('F Y', strtotime($previousStart)),
        'generated_at' => date('d M Y, h:i A'),
        'sme_name' => (string) ($smeRow['fullname'] ?? ''),
        'sme_email' => (string) ($smeRow['email'] ?? ''),
        // Same values the SME sidebar shows (sme.php): there is no position column in `users`.
        'sme_position' => 'Social Media Executive (SME)',
        'sme_department' => 'Social Media',
        'reporting_manager' => trim((string) ($smeRow['reporting_manager'] ?? '')),
        'overall_health' => $overallHealth,
        'overall_health_label' => $overallHealthLabel,
        'sop_done' => $sopDone,
        'meeting_list' => $meetingList,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'stats' => $stats,
        'previous_stats' => $previousStats,
        'creative_change' => $creativeChange,
        'approval_rate' => $approvalRate,
        'previous_approval_rate' => $previousApprovalRate,
        'companies' => $companies,
        'company_reports' => $companyReports,
        'platform_reports' => $platformReports,
        'recent_creatives' => $recentCreatives,
        'sop_stats' => $sopStats,
        'meeting_stats' => $meetingStats,
        'meeting_reports' => $meetingReports
    ];
}

/*
|--------------------------------------------------------------------------
| JSON endpoint used by monthly-reports.js
|--------------------------------------------------------------------------
*/

if (isset($_GET['report_api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    try {
        $report = mrBuildReport(
            $conn,
            $reportUserId,
            $startDate,
            $endDate,
            $previousStart,
            $monthLabel
        );

        echo json_encode([
            'success' => true,
            'report' => $report
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        error_log('SME Monthly Report Error: ' . $e->getMessage());
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Unable to generate the report. Check the PHP error log.'
        ]);
    }

    exit;
}

/*
|--------------------------------------------------------------------------
| Initial page shell. Data loads through the JSON endpoint.
|--------------------------------------------------------------------------
*/

function mrEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>

<link rel="stylesheet" href="css/smemonthrep.css?v=<?= (int) @filemtime(__DIR__ . '/css/smemonthrep.css') ?>">

<section class="mr-page" id="smeMonthlyReports" data-report-url="<?= mrEscape(basename(__FILE__)) ?>" data-css-url="css/smemonthrep.css?v=<?= (int) @filemtime(__DIR__ . '/css/smemonthrep.css') ?>">
    <div class="mr-heading">
        <div>
            <span class="mr-eyebrow">PERFORMANCE ANALYTICS</span>
            <h2>Monthly Reports</h2>
            <p>Review your assigned companies, creative performance,
                SOP schedules and meetings.</p>
        </div>

        <div class="mr-toolbar">
            <label for="mrMonth">Reporting month</label>
            <input type="month" id="mrMonth" value="<?= mrEscape($requestedMonth) ?>">
            <button type="button" id="mrRefresh" class="mr-btn">Refresh</button>
            <button type="button" id="mrPrint" class="mr-btn mr-btn-light">Print / Save PDF</button>
        </div>
    </div>

    <div id="mrMessage" class="mr-message" role="status" aria-live="polite">
        Loading monthly report...
    </div>

    <div id="mrReportContent" hidden>
    <article class="mr-doc" id="mrDoc">

        <!-- Document header -->
        <header class="mr-doc-head">
            <div class="mr-doc-top">
                <div class="mr-doc-brand">
                    <img src="../assets/images/company-logo.png"
                         alt="Unstoppable - Beyond the Blueprint"
                         class="mr-doc-logo" width="418" height="97"
                         onerror="this.style.display='none'">
                </div>
                <span id="mrOverallHealth" class="mr-health"></span>
            </div>

            <span class="mr-doc-kicker">SME PERFORMANCE REPORT</span>
            <h1 class="mr-doc-title">Monthly Report</h1>
            <p class="mr-doc-subtitle" id="mrPeriodLabel"></p>

            <dl class="mr-meta-grid">
                <div><dt>Prepared for</dt><dd id="mrSmeName">-</dd></div>
                <div><dt>Position</dt><dd id="mrSmePosition">-</dd></div>
                <div><dt>Department</dt><dd id="mrSmeDepartment">-</dd></div>
                <div><dt>Email</dt><dd id="mrSmeEmail">-</dd></div>
                <div><dt>Reporting manager</dt><dd id="mrManagerName">-</dd></div>
                <div><dt>Assigned companies</dt><dd id="mrCompanyCount">-</dd></div>
                <div><dt>Reporting period</dt><dd id="mrPeriodRange">-</dd></div>
                <div><dt>Compared with</dt><dd id="mrPrevLabel">-</dd></div>
                <div><dt>Generated on</dt><dd id="mrGeneratedAt">-</dd></div>
            </dl>
        </header>

        <!-- 01 Executive summary -->
        <section class="mr-section">
            <div class="mr-section-heading">
                <h3>Executive Summary</h3>
                <p>The month at a glance.</p>
            </div>

            <p class="mr-lead" id="mrNarrative"></p>

            <div class="mr-stats-grid">
                <article class="mr-stat-card">
                    <span>Total Creatives</span>
                    <strong id="mrTotal">0</strong>
                    <small id="mrTotalChange">Previous month comparison</small>
                </article>
                <article class="mr-stat-card mr-stat-approved">
                    <span>Approved</span>
                    <strong id="mrApproved">0</strong>
                    <small id="mrApprovalRate">0% approval rate</small>
                </article>
                <article class="mr-stat-card mr-stat-pending">
                    <span>Pending Review</span>
                    <strong id="mrPending">0</strong>
                    <small>Awaiting a final decision</small>
                </article>
                <article class="mr-stat-card mr-stat-revision">
                    <span>Revision</span>
                    <strong id="mrRevision">0</strong>
                    <small>Creatives requiring changes</small>
                </article>
                <article class="mr-stat-card mr-stat-rejected">
                    <span>Rejected</span>
                    <strong id="mrRejected">0</strong>
                    <small>Rejected creatives</small>
                </article>
                <article class="mr-stat-card mr-stat-delays">
                    <span>Delays</span>
                    <strong id="mrDelays">0</strong>
                    <small>Submitted after the creative date</small>
                </article>
                <article class="mr-stat-card mr-stat-overdue">
                    <span>Overdue</span>
                    <strong id="mrOverdue">0</strong>
                    <small>Pending / revision past the date</small>
                </article>
                <article class="mr-stat-card mr-stat-draft">
                    <span>Drafts</span>
                    <strong id="mrDraft">0</strong>
                    <small>Unfinalized creatives</small>
                </article>
            </div>

            <h4 class="mr-subheading">Key observations</h4>
            <ul class="mr-observations" id="mrObservations"></ul>
        </section>

        <!-- 02 Month over month -->
        <section class="mr-section">
            <div class="mr-section-heading">
                <h3>Month-over-Month Comparison</h3>
                <p>This month compared with the previous month.</p>
            </div>

            <div class="mr-table-wrap">
                <table class="mr-table mr-table-compact">
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th class="mr-num" id="mrCmpThis">This month</th>
                            <th class="mr-num" id="mrCmpPrev">Previous month</th>
                            <th class="mr-num">Change</th>
                        </tr>
                    </thead>
                    <tbody id="mrCompareRows"></tbody>
                </table>
            </div>
        </section>

        <!-- 03 Companies -->
        <section class="mr-section">
            <div class="mr-section-heading">
                <h3>Company-wise Performance</h3>
                <p>Results and health for the companies assigned to you.</p>
            </div>

            <div class="mr-table-wrap">
                <table class="mr-table mr-table-wide">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th class="mr-num">Total</th>
                            <th class="mr-num">Approved</th>
                            <th class="mr-num">Pending</th>
                            <th class="mr-num">Revision</th>
                            <th class="mr-num">Rejected</th>
                            <th class="mr-num">Delays</th>
                            <th class="mr-num">Overdue</th>
                            <th class="mr-num">Draft</th>
                            <th>Approval</th>
                            <th class="mr-num">Health</th>
                        </tr>
                    </thead>
                    <tbody id="mrCompanyRows"></tbody>
                    <tfoot id="mrCompanyFoot"></tfoot>
                </table>
            </div>

            <p class="mr-note">
                <strong>Health rules.</strong>
                <span class="mr-health mr-health-healthy">Healthy</span> below the At Risk limits &middot;
                <span class="mr-health mr-health-risk">At Risk</span> 2+ delays, 4+ revisions, 2+ rejections or 2+ overdue &middot;
                <span class="mr-health mr-health-critical">Critical</span> 3+ delays, 5+ revisions, 3+ rejections or 3+ overdue.
            </p>
        </section>

        <!-- 04 Platforms -->
        <section class="mr-section">
            <div class="mr-section-heading">
                <h3>Platform Performance</h3>
                <p>Creative distribution by platform and status.</p>
            </div>

            <div id="mrPlatformRows" class="mr-platform-list"></div>
            <ul class="mr-legend" id="mrPlatformLegend"></ul>
        </section>

        <!-- 05 SOP -->
        <section class="mr-section">
            <div class="mr-section-heading">
                <h3>SOP Schedule</h3>
                <p>SOPs scheduled during the selected month.</p>
            </div>

            <div class="mr-mini-stats">
                <div><span>Total SOPs</span><strong id="mrSopTotal">0</strong></div>
                <div><span>Daily</span><strong id="mrSopDaily">0</strong></div>
                <div><span>Weekly</span><strong id="mrSopWeekly">0</strong></div>
                <div><span>Monthly</span><strong id="mrSopMonthly">0</strong></div>
                <div><span>Ticks saved</span><strong id="mrSopDone">-</strong></div>
            </div>

            <p class="mr-note">
                Scheduled SOP records are counted by their SOP date. Ticks saved
                are the completions marked on the dashboard during this month.
            </p>
        </section>

        <!-- 06 Meetings -->
        <section class="mr-section">
            <div class="mr-section-heading">
                <h3>Meetings</h3>
                <p>Meetings scheduled for this SME in the selected month.</p>
            </div>

            <div class="mr-meeting-summary">
                <div><span>Total Meetings</span><strong id="mrMeetingTotal">0</strong></div>
                <div><span>Completed</span><strong id="mrMeetingDone">0</strong></div>
                <div><span>Pending</span><strong id="mrMeetingPending">0</strong></div>
                <div><span>Completion Rate</span><strong id="mrMeetingRate">0%</strong></div>
            </div>

            <div class="mr-table-wrap">
                <table class="mr-table mr-table-compact">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th class="mr-num">Total Meetings</th>
                            <th class="mr-num">Completed</th>
                            <th class="mr-num">Pending</th>
                        </tr>
                    </thead>
                    <tbody id="mrMeetingRows"></tbody>
                </table>
            </div>

            <h4 class="mr-subheading">Meeting details</h4>
            <div class="mr-table-wrap">
                <table class="mr-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Company</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>MOM</th>
                        </tr>
                    </thead>
                    <tbody id="mrMeetingList"></tbody>
                </table>
            </div>
        </section>

        <!-- 07 Creative details -->
        <section class="mr-section mr-section-flow">
            <div class="mr-section-heading">
                <h3>Creative Details</h3>
                <p id="mrCreativeSub">Every creative of the selected month.</p>
            </div>

            <div class="mr-table-wrap">
                <table class="mr-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Title</th>
                            <th>Company</th>
                            <th>Platform</th>
                            <th>Status</th>
                            <th>Timing</th>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody id="mrRecentRows"></tbody>
                </table>
            </div>
        </section>

        <!-- 08 Notes + sign-off -->
        <section class="mr-section mr-section-end">
            <div class="mr-section-heading">
                <h3>Notes &amp; Definitions</h3>
            </div>

            <ul class="mr-defs">
                <li><strong>Delays</strong> &ndash; creatives submitted after their creative date.</li>
                <li><strong>Overdue</strong> &ndash; creatives still pending or in revision after their creative date.</li>
                <li><strong>Approval rate</strong> &ndash; approved creatives as a share of all creatives in the period.</li>
                <li>Figures reflect each creative's current status and are calculated from live database records at the time of generation.</li>
            </ul>

            <div class="mr-signoff">
                <div><span></span>Prepared by (SME)<small id="mrSignSme"></small></div>
                <div><span></span>Reviewed by (Reporting Manager)<small id="mrSignManager"></small></div>
                <div><span></span>Date<small id="mrSignDate"></small></div>
            </div>
        </section>
    </article>
    </div>
</section>

<script src="js/smemonthrep.js?v=<?= (int) @filemtime(__DIR__ . '/js/smemonthrep.js') ?>" defer></script>
