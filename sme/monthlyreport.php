
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
        'draft' => 0
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
                    COALESCE(SUM(status = 'draft'), 0) AS draft
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
                    COALESCE(SUM(status = 'draft'), 0) AS draft
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
                COALESCE(SUM(cr.status = 'draft'), 0) AS draft
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
                COALESCE(SUM(status = 'draft'), 0) AS draft
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
         * Recent creatives. The latest 10 by creative date.
         */
        $recentCreatives = mrFetchAll(
            $conn,
            "SELECT
                cr.id,
                cr.title,
                cr.platform,
                cr.status,
                cr.creative_date,
                c.comp_name
             FROM creatives cr
             INNER JOIN companies c ON c.id = cr.company_id
             WHERE cr.company_id IN ($placeholders)
               AND cr.creative_date >= ?
               AND cr.creative_date < ?
             ORDER BY cr.creative_date DESC, cr.id DESC
             LIMIT 10",
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

<link rel="stylesheet" href="css/smemonthrep.css">

<section class="mr-page" id="smeMonthlyReports" data-report-url="<?= mrEscape(basename(__FILE__)) ?>" >
    <div class="mr-heading">
        <div>
            <span class="mr-eyebrow">PERFORMANCE ANALYTICS</span>
            <h2>Monthly Reports</h2>
            <p>Review your assigned companies, creative performance,
                SOP schedules and meetings.</p>
        </div>

        <div class="mr-toolbar">
            <label for="mrMonth">Reporting month</label>
            <input
                type="month"
                id="mrMonth"
                value="<?= mrEscape($requestedMonth) ?>"
            >
            <button type="button" id="mrRefresh" class="mr-btn">
                Refresh
            </button>
            <button type="button" id="mrPrint" class="mr-btn mr-btn-light">
                Print Report
            </button>
        </div>
    </div>

    <div id="mrMessage" class="mr-message" role="status" aria-live="polite">
        Loading monthly report...
    </div>

    <div id="mrReportContent" hidden>
        <div class="mr-report-meta">
            <span id="mrPeriodLabel"></span>
            <span id="mrCompanyCount"></span>
        </div>

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
                <span>Revision Required</span>
                <strong id="mrRevision">0</strong>
                <small>Creatives requiring changes</small>
            </article>

            <article class="mr-stat-card mr-stat-rejected">
                <span>Rejected</span>
                <strong id="mrRejected">0</strong>
                <small>Rejected creatives</small>
            </article>

            <article class="mr-stat-card">
                <span>Drafts</span>
                <strong id="mrDraft">0</strong>
                <small>Unfinalized creatives</small>
            </article>
        </div>

        <div class="mr-section">
            <div class="mr-section-heading">
                <div>
                    <h3>Company-wise Performance</h3>
                    <p>Results for companies assigned to your SME account.</p>
                </div>
            </div>

            <div class="mr-table-wrap">
                <table class="mr-table">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Total</th>
                            <th>Approved</th>
                            <th>Pending</th>
                            <th>Revision</th>
                            <th>Rejected</th>
                            <th>Draft</th>
                            <th>Approval Rate</th>
                        </tr>
                    </thead>
                    <tbody id="mrCompanyRows"></tbody>
                </table>
            </div>
        </div>

        <div class="mr-two-columns">
            <div class="mr-section">
                <div class="mr-section-heading">
                    <div>
                        <h3>Platform Performance</h3>
                        <p>Creative distribution by platform.</p>
                    </div>
                </div>

                <div id="mrPlatformRows" class="mr-platform-list"></div>
            </div>

            <div class="mr-section">
                <div class="mr-section-heading">
                    <div>
                        <h3>SOP Schedule</h3>
                        <p>SOPs scheduled during the selected month.</p>
                    </div>
                </div>

                <div class="mr-mini-stats">
                    <div><span>Total SOPs</span><strong id="mrSopTotal">0</strong></div>
                    <div><span>Daily</span><strong id="mrSopDaily">0</strong></div>
                    <div><span>Weekly</span><strong id="mrSopWeekly">0</strong></div>
                    <div><span>Monthly</span><strong id="mrSopMonthly">0</strong></div>
                </div>

                <p class="mr-note">
                    These figures represent scheduled SOP records, not
                    completed tasks. The database currently has no SOP
                    completion status.
                </p>
            </div>
        </div>

        <div class="mr-section">
            <div class="mr-section-heading">
                <div>
                    <h3>Meeting Summary</h3>
                    <p>Meetings scheduled for this SME in the selected month.</p>
                </div>
            </div>

            <div class="mr-meeting-summary">
                <div><span>Total Meetings</span><strong id="mrMeetingTotal">0</strong></div>
                <div><span>Completed</span><strong id="mrMeetingDone">0</strong></div>
                <div><span>Pending</span><strong id="mrMeetingPending">0</strong></div>
            </div>

            <div class="mr-table-wrap">
                <table class="mr-table">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Total Meetings</th>
                            <th>Completed</th>
                            <th>Pending</th>
                        </tr>
                    </thead>
                    <tbody id="mrMeetingRows"></tbody>
                </table>
            </div>
        </div>

        <div class="mr-section">
            <div class="mr-section-heading">
                <div>
                    <h3>Recent Creatives</h3>
                    <p>Latest creatives by creative date, up to 10 records.</p>
                </div>
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
                        </tr>
                    </thead>
                    <tbody id="mrRecentRows"></tbody>
                </table>
            </div>
        </div>

        <p class="mr-footer-note">
            Report figures are calculated from the current database records.
            Approval counts reflect each creative's current status.
        </p>
    </div>
</section>

<script src="js/smemonthrep.js" defer></script>
