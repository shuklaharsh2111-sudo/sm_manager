
<?php
/*
|--------------------------------------------------------------------------
| SME COMPANY HEALTH
|--------------------------------------------------------------------------
| This file is included inside sme/dashboard.php.
| Only the logged-in SME's assigned companies are displayed.
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$bhUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($bhUserId <= 0) {
    echo '<p class="bh-message">Please log in to view this report.</p>';
    return;
}

/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
| dashboard.php already includes config/db.php.
| This fallback supports direct inclusion if the connection is not loaded.
*/

if (!isset($conn) && !isset($con) && !isset($mysqli)) {
    require_once __DIR__ . '/../config/db.php';
}

$bhDb = $conn ?? $con ?? $mysqli ?? null;

if (!($bhDb instanceof mysqli)) {
    echo '<p class="bh-message">Database connection is unavailable. Check config/db.php.</p>';
    return;
}

if (!function_exists('bhEscape')) {
    function bhEscape($value): string
    {
        return htmlspecialchars(
            (string) ($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

$bhRows = [];
$bhError = '';

/*
|--------------------------------------------------------------------------
| FETCH ONLY THIS SME'S ASSIGNED COMPANIES
|--------------------------------------------------------------------------
*/

$bhSql = "
    SELECT
        c.id AS company_id,
        c.comp_name,
        c.comp_logo,

        COALESCE(SUM(cr.status = 'approved'), 0) AS approved_count,
        COALESCE(SUM(cr.status = 'pending'), 0)  AS pending_count,
        COALESCE(SUM(cr.status = 'revision'), 0) AS revision_count,
        COALESCE(SUM(cr.status = 'rejected'), 0) AS rejected_count,

        COALESCE(
            SUM(
                cr.creative_date < CURDATE()
                AND cr.status IN ('pending', 'revision')
            ),
            0
        ) AS delay_count

    FROM sme_company_assignments sca

    INNER JOIN companies c
        ON c.id = sca.company_id

    LEFT JOIN creatives cr
        ON cr.company_id = c.id

    WHERE sca.sme_user_id = ?

    GROUP BY
        c.id,
        c.comp_name,
        c.comp_logo

    ORDER BY c.comp_name ASC
";

$bhStmt = $bhDb->prepare($bhSql);

if ($bhStmt) {
    $bhStmt->bind_param('i', $bhUserId);

    if ($bhStmt->execute()) {
        $bhResult = $bhStmt->get_result();

        while ($bhRow = $bhResult->fetch_assoc()) {
            $approved = (int) $bhRow['approved_count'];
            $pending  = (int) $bhRow['pending_count'];
            $redo     = (int) $bhRow['revision_count'];
            $rejected = (int) $bhRow['rejected_count'];

            // Drafts / blank statuses are not counted (same as Analytics).
            $total = $approved + $pending + $redo + $rejected;

            $bhRow['approved_count'] = $approved;
            $bhRow['pending_count']  = $pending;
            $bhRow['revision_count'] = $redo;
            $bhRow['rejected_count'] = $rejected;
            $bhRow['delay_count']    = (int) $bhRow['delay_count'];
            $bhRow['total_creatives'] = $total;
            $bhRow['approval_rate']  = $total > 0
                ? (int) round($approved / $total * 100)
                : 0;

            // Health: judged on delays and on the approval rate of reviewed
            // creatives (approved / (approved + redo + rejected)).
            $reviewed = $approved + $redo + $rejected;
            $reviewedRate = $reviewed > 0 ? $approved / $reviewed * 100 : null;
            $delayed = $bhRow['delay_count'];
            if ($total === 0) {
                $bhRow['health'] = 'nodata';
                $bhRow['health_label'] = 'No data';
                $bhRow['health_note'] = 'No creatives yet';
            } elseif ($delayed >= 3 || ($reviewedRate !== null && $reviewedRate < 40)) {
                $bhRow['health'] = 'critical';
                $bhRow['health_label'] = 'Critical';
                $bhRow['health_note'] = '3+ delayed creatives or under 40% approved';
            } elseif ($delayed > 0 || ($reviewedRate !== null && $reviewedRate < 70)) {
                $bhRow['health'] = 'risk';
                $bhRow['health_label'] = 'At Risk';
                $bhRow['health_note'] = 'Some delays or under 70% approved';
            } else {
                $bhRow['health'] = 'healthy';
                $bhRow['health_label'] = 'Healthy';
                $bhRow['health_note'] = 'On time and 70%+ approved';
            }

            $bhRows[] = $bhRow;
        }
    } else {
        $bhError = 'Unable to load company health.';
    }

    $bhStmt->close();
} else {
    $bhError = 'Unable to prepare the company health query.';
}

$bhTotalCreatives = array_sum(array_column($bhRows, 'total_creatives'));
$bhTotalApproved  = array_sum(array_column($bhRows, 'approved_count'));
$bhTotalDelayed   = array_sum(array_column($bhRows, 'delay_count'));
?>

<?php
$bhVersion = static function (string $relative): string {
    $file = __DIR__ . '/../assets/' . $relative;
    return '../assets/' . $relative . '?v=' . (is_file($file) ? filemtime($file) : time());
};
?>
<link rel="stylesheet" href="<?php echo bhEscape($bhVersion('css/bh.css')); ?>">
<link rel="stylesheet" href="<?php echo bhEscape($bhVersion('css/bhhealth.css')); ?>">

<section class="bh-report" id="companyComparison">

    <div class="bh-report-header">
        <div class="bh-heading-content">
            <div class="bh-eyebrow">
                <span class="bh-live-dot"></span>
                SME WORKSPACE
            </div>

            <h2>Company <span>Health</span></h2>

            <p>
                Track approvals, redo requests, rejections and delays
                to see how healthy each assigned company is.
            </p>
        </div>

        <div class="bh-header-meta">
            <span class="bh-meta-label">ASSIGNED COMPANIES</span>
            <strong><?php echo count($bhRows); ?></strong>
            <span class="bh-meta-caption">Your portfolio</span>
        </div>
    </div>

    <?php if ($bhError !== ''): ?>

        <div class="bh-message bh-error">
            <?php echo bhEscape($bhError); ?>
        </div>

    <?php else: ?>

        <div class="bh-summary-strip">
            <div class="bh-summary-item">
                <span class="bh-summary-dot bh-dot-blue"></span>
                <span class="bh-summary-label">Companies</span>
                <strong id="bhSumCompanies"><?php echo count($bhRows); ?></strong>
            </div>

            <div class="bh-summary-item">
                <span class="bh-summary-dot bh-dot-orange"></span>
                <span class="bh-summary-label">Total Creatives</span>
                <strong id="bhSumCreatives"><?php echo $bhTotalCreatives; ?></strong>
            </div>

            <div class="bh-summary-item">
                <span class="bh-summary-dot bh-dot-green"></span>
                <span class="bh-summary-label">Approved</span>
                <strong id="bhSumApproved"><?php echo $bhTotalApproved; ?></strong>
            </div>

            <div class="bh-summary-item">
                <span class="bh-summary-dot bh-dot-red"></span>
                <span class="bh-summary-label">Delayed</span>
                <strong id="bhSumDelayed"><?php echo $bhTotalDelayed; ?></strong>
            </div>
        </div>

        <div class="bh-toolbar">
            <div class="bh-search-wrap">
                <span class="bh-search-icon" aria-hidden="true">⌕</span>
                <input
                    type="search"
                    id="bhSearch"
                    placeholder="Search your companies..."
                    aria-label="Search assigned companies"
                >
            </div>

            <button type="button" class="bh-export-btn" id="bhExport">
                Export CSV
            </button>
        </div>

        <div class="bh-table-shell">
            <div class="bh-table-scroll">
                <table class="bh-table" id="bhHealthTable">
                    <thead>
                        <tr>
                            <th>COMPANY</th>
                            <th>TOTAL</th>
                            <th>APPROVED</th>
                            <th>PENDING</th>
                            <th>REDO</th>
                            <th>REJECTED</th>
                            <th>DELAYED</th>
                            <th>APPROVAL</th>
                            <th>HEALTH</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($bhRows)): ?>

                        <tr>
                            <td colspan="9" class="bh-empty">
                                <span class="bh-empty-icon">◇</span>
                                <strong>No assigned companies yet</strong>
                                <p>
                                    Companies assigned to you will appear here.
                                </p>
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($bhRows as $bhRow): ?>
                            <tr class="bh-data-row">
                                <td>
                                    <div class="bh-company-cell">
                                        <div class="bh-company-avatar">
                                            <?php if (!empty($bhRow['comp_logo'])): ?>
                                                <img
                                                    src="../<?php echo bhEscape(ltrim($bhRow['comp_logo'], '/')); ?>"
                                                    alt=""
                                                    loading="lazy"
                                                    onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                                                >
                                            <?php endif; ?>

                                            <span
                                                <?php if (!empty($bhRow['comp_logo'])): ?>
                                                    style="display:none"
                                                <?php endif; ?>
                                            >
                                                <?php echo bhEscape(strtoupper(substr(trim($bhRow['comp_name']), 0, 1))); ?>
                                            </span>
                                        </div>

                                        <div class="bh-company-info">
                                            <strong><?php echo bhEscape($bhRow['comp_name']); ?></strong>
                                            <small>Company #<?php echo (int) $bhRow['company_id']; ?></small>
                                        </div>
                                    </div>
                                </td>

                                <td data-col="total">
                                    <span class="bh-number bh-number-blue"><?php echo $bhRow['total_creatives']; ?></span>
                                </td>
                                <td data-col="approved">
                                    <span class="bh-number"><?php echo $bhRow['approved_count']; ?></span>
                                </td>
                                <td data-col="pending">
                                    <span class="bh-number"><?php echo $bhRow['pending_count']; ?></span>
                                </td>
                                <td data-col="revision">
                                    <span class="bh-number <?php echo $bhRow['revision_count'] > 0 ? 'bh-number-purple' : ''; ?>"><?php echo $bhRow['revision_count']; ?></span>
                                </td>
                                <td data-col="rejected">
                                    <span class="bh-number"><?php echo $bhRow['rejected_count']; ?></span>
                                </td>
                                <td data-col="delayed">
                                    <span class="bh-number <?php echo $bhRow['delay_count'] > 0 ? 'bh-number-red' : ''; ?>"><?php echo $bhRow['delay_count']; ?></span>
                                </td>
                                <td data-col="approval">
                                    <span class="bh-number"><?php echo $bhRow['approval_rate']; ?>%</span>
                                </td>
                                <td data-col="health">
                                    <span class="bh-status bh-status-<?php echo bhEscape($bhRow['health']); ?>" title="<?php echo bhEscape($bhRow['health_note']); ?>">
                                        <span class="bh-status-dot"></span><?php echo bhEscape($bhRow['health_label']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="bh-table-footer">
                <span id="bhResultCount">
                    Showing <?php echo count($bhRows); ?>
                    assigned compan<?php echo count($bhRows) === 1 ? 'y' : 'ies'; ?>
                </span>

                <span class="bh-footer-indicator">
                    <span class="bh-live-dot"></span>
                    Live report
                </span>
            </div>
        </div>

    <?php endif; ?>

</section>

<script src="<?php echo bhEscape($bhVersion('js/bh.js')); ?>" defer></script>
