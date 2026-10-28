<?php
/*
|--------------------------------------------------------------------------
| SME ANALYTICS
|--------------------------------------------------------------------------
| Included by sme.php (?page=analytics). Shows creative, redo, delay,
| platform, company and meeting statistics for the signed-in SME's assigned
| companies only. Charts are drawn by assets/js/smeanalytics.js (no library).
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$anUserId = (int) ($_SESSION['user_id'] ?? 0);
if ($anUserId <= 0) {
    echo '<p class="an-message">Please log in to view analytics.</p>';
    return;
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/../config/db.php';
}
function anE($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$anStatuses = [
    'approved' => ['Approved',      '#3dd693'],
    'pending'  => ['Pending',       '#ffc052'],
    'revision' => ['Redo Required', '#b177ff'],
    'rejected' => ['Rejected',      '#ff6685'],
];

/* ---------- Filters ---------- */
$anRange = (int) ($_GET['range'] ?? 6);
if (!in_array($anRange, [3, 6, 12, 0], true)) {
    $anRange = 6;
}
$anCompanyFilter = (int) ($_GET['company'] ?? 0);

/* ---------- Assigned companies ---------- */
$anCompanies = [];
$st = $conn->prepare(
    'SELECT c.id, c.comp_name
     FROM sme_company_assignments sca
     INNER JOIN companies c ON c.id = sca.company_id
     WHERE sca.sme_user_id = ?
     ORDER BY c.comp_name ASC'
);
if ($st) {
    $st->bind_param('i', $anUserId);
    if ($st->execute()) {
        $r = $st->get_result();
        while ($row = $r->fetch_assoc()) {
            $anCompanies[(int) $row['id']] = (string) $row['comp_name'];
        }
    }
    $st->close();
}
if ($anCompanyFilter > 0 && !isset($anCompanies[$anCompanyFilter])) {
    $anCompanyFilter = 0; // not one of this SME's companies
}

/* ---------- Creatives ---------- */
$anToday = date('Y-m-d');
$anRangeStart = $anRange > 0 ? date('Y-m-01', strtotime('-' . ($anRange - 1) . ' months')) : null;

$sql = 'SELECT cr.id, cr.company_id, cr.title, cr.platform, cr.creative_date, cr.status, cr.revision_reason
        FROM creatives cr
        WHERE cr.company_id IN (SELECT sca.company_id FROM sme_company_assignments sca WHERE sca.sme_user_id = ?)';
$types = 'i';
$params = [$anUserId];
if ($anCompanyFilter > 0) {
    $sql .= ' AND cr.company_id = ?';
    $types .= 'i';
    $params[] = $anCompanyFilter;
}
if ($anRangeStart !== null) {
    $sql .= ' AND cr.creative_date >= ?';
    $types .= 's';
    $params[] = $anRangeStart;
}
$sql .= ' ORDER BY cr.creative_date ASC, cr.id ASC';

$anCreatives = [];
$st = $conn->prepare($sql);
if ($st) {
    $st->bind_param($types, ...$params);
    if ($st->execute()) {
        $r = $st->get_result();
        while ($row = $r->fetch_assoc()) {
            $s = strtolower((string) $row['status']);
            if (!isset($anStatuses[$s])) {
                continue; // drafts / blank statuses are not part of analytics
            }
            $row['status'] = $s;
            $anCreatives[] = $row;
        }
    }
    $st->close();
}

/* ---------- Aggregation ---------- */
$total = count($anCreatives);
$counts = array_fill_keys(array_keys($anStatuses), 0);
$platforms = [];
$perCompany = [];
foreach ($anCompanies as $cid => $cname) {
    if ($anCompanyFilter === 0 || $anCompanyFilter === $cid) {
        $perCompany[$cid] = ['name' => $cname, 'total' => 0, 'delays' => 0] + array_fill_keys(array_keys($anStatuses), 0);
    }
}
$delayed = [];
$redo = [];
$thisMonth = 0;
$upcoming30 = 0;
$weeks = [0, 0, 0, 0];
$curMonth = date('Y-m');
$todayTs = strtotime($anToday);
$in30 = strtotime('+30 days', $todayTs);
$latestMonth = $curMonth;

foreach ($anCreatives as $cr) {
    $s = $cr['status'];
    $counts[$s]++;
    $p = (string) $cr['platform'];
    $platforms[$p] ??= ['total' => 0, 'approved' => 0];
    $platforms[$p]['total']++;
    if ($s === 'approved') {
        $platforms[$p]['approved']++;
    }
    $cid = (int) $cr['company_id'];
    if (isset($perCompany[$cid])) {
        $perCompany[$cid]['total']++;
        $perCompany[$cid][$s]++;
    }
    $ts = strtotime((string) $cr['creative_date']);
    $isDelayed = $cr['creative_date'] < $anToday && in_array($s, ['pending', 'revision'], true);
    if ($isDelayed) {
        if (isset($perCompany[$cid])) {
            $perCompany[$cid]['delays']++;
        }
        $delayed[] = $cr + ['days_late' => (int) floor(($todayTs - $ts) / 86400)];
    }
    if ($s === 'revision') {
        $redo[] = $cr;
    }
    if (substr((string) $cr['creative_date'], 0, 7) === $curMonth) {
        $thisMonth++;
    }
    if ($ts >= $todayTs && $ts <= $in30) {
        $upcoming30++;
    }
    $dayOffset = (int) floor(($ts - $todayTs) / 86400);
    if ($dayOffset >= 0 && $dayOffset < 28) {
        $weeks[intdiv($dayOffset, 7)]++;
    }
    $m = substr((string) $cr['creative_date'], 0, 7);
    if ($m > $latestMonth) {
        $latestMonth = $m;
    }
}
$delayCount = count($delayed);
$approvalRate = $total > 0 ? round($counts['approved'] / $total * 100, 1) : 0;
$redoRate = $total > 0 ? round($counts['revision'] / $total * 100, 1) : 0;
$rejectRate = $total > 0 ? round($counts['rejected'] / $total * 100, 1) : 0;
$delayRate = $total > 0 ? round($delayCount / $total * 100, 1) : 0;

/* Monthly series: N months ending at the latest month that has a creative (max 3 ahead). */
$monthsN = $anRange > 0 ? $anRange : 12;
$capMonth = date('Y-m', strtotime('+3 months'));
$endMonth = min($latestMonth, $capMonth);
$monthly = [];
for ($i = $monthsN - 1; $i >= 0; $i--) {
    $key = date('Y-m', strtotime($endMonth . '-01 -' . $i . ' months'));
    $monthly[$key] = [
        'label' => date('M y', strtotime($key . '-01')),
        'counts' => array_fill_keys(array_keys($anStatuses), 0),
        'total' => 0,
    ];
}
foreach ($anCreatives as $cr) {
    $m = substr((string) $cr['creative_date'], 0, 7);
    if (isset($monthly[$m])) {
        $monthly[$m]['counts'][$cr['status']]++;
        $monthly[$m]['total']++;
    }
}

/* ---------- Meetings ---------- */
$meetDone = 0;
$meetPending = 0;
$meetUpcoming = 0;
$sql = 'SELECT meeting_status, meeting_date FROM sme_meetings WHERE sme_user_id = ?';
$types = 'i';
$params = [$anUserId];
if ($anCompanyFilter > 0) {
    $sql .= ' AND company_id = ?';
    $types .= 'i';
    $params[] = $anCompanyFilter;
}
$st = $conn->prepare($sql);
if ($st) {
    $st->bind_param($types, ...$params);
    if ($st->execute()) {
        $r = $st->get_result();
        while ($row = $r->fetch_assoc()) {
            if ($row['meeting_status'] === 'done') {
                $meetDone++;
            } else {
                $meetPending++;
                if ($row['meeting_date'] >= $anToday) {
                    $meetUpcoming++;
                }
            }
        }
    }
    $st->close();
}
$meetTotal = $meetDone + $meetPending;

/* Platforms sorted by volume */
uasort($platforms, static fn($a, $b) => $b['total'] <=> $a['total']);
$platformLabel = static fn($p) => strlen($p) <= 1 ? strtoupper($p) : ucfirst($p);

/* ---------- Data for the chart script ---------- */
$anData = [
    'statuses' => array_map(
        static fn($k, $v) => ['key' => $k, 'label' => $v[0], 'color' => $v[1], 'count' => $counts[$k]],
        array_keys($anStatuses),
        array_values($anStatuses)
    ),
    'total' => $total,
    'monthly' => array_values(array_map(
        static fn($m) => ['label' => $m['label'], 'total' => $m['total'], 'counts' => $m['counts']],
        $monthly
    )),
    'platforms' => array_map(
        static fn($k, $v) => ['label' => $platformLabel($k), 'total' => $v['total'], 'approved' => $v['approved']],
        array_keys($platforms),
        array_values($platforms)
    ),
    'weeks' => $weeks,
    'meetings' => ['done' => $meetDone, 'pending' => $meetPending],
];
$anJson = json_encode($anData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

$anRangeLabels = [3 => 'Last 3 months', 6 => 'Last 6 months', 12 => 'Last 12 months', 0 => 'All time'];
$anKpis = [
    ['Total Creatives', $total, '#8b5cf6', 'All creatives in view'],
    ['Approved', $counts['approved'], $anStatuses['approved'][1], $approvalRate . '% approval rate'],
    ['Pending', $counts['pending'], $anStatuses['pending'][1], 'Waiting for admin'],
    ['Redo Required', $counts['revision'], $anStatuses['revision'][1], $redoRate . '% redo rate'],
    ['Rejected', $counts['rejected'], $anStatuses['rejected'][1], $rejectRate . '% rejection rate'],
    ['Delayed', $delayCount, '#fb923c', $delayRate . '% of creatives'],
    ['Next 30 Days', $upcoming30, '#60a5fa', 'Scheduled creatives'],
];
?>
<link rel="stylesheet" href="../assets/css/smeanalytics.css">

<section class="an-page" id="smeAnalytics">
    <div class="an-header">
        <div>
            <div class="an-eyebrow"><span class="an-dot"></span>SME WORKSPACE</div>
            <h2>Creative <span>Analytics</span></h2>
            <p>Track approvals, redo requests, delays and workload across your assigned companies.</p>
        </div>
        <form class="an-filters" method="get" action="sme.php">
            <input type="hidden" name="page" value="analytics">
            <label>Company
                <select name="company" onchange="this.form.submit()">
                    <option value="0">All companies</option>
                    <?php foreach ($anCompanies as $cid => $cname): ?>
                        <option value="<?= (int) $cid ?>" <?= $cid === $anCompanyFilter ? 'selected' : '' ?>><?= anE($cname) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Period
                <select name="range" onchange="this.form.submit()">
                    <?php foreach ($anRangeLabels as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $v === $anRange ? 'selected' : '' ?>><?= anE($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button type="submit">Apply</button></noscript>
        </form>
    </div>

    <?php if (!$anCompanies): ?>
        <p class="an-message">No companies are assigned to you yet, so there is nothing to analyse.</p>
    <?php else: ?>

    <!-- KPI cards -->
    <div class="an-kpis">
        <?php foreach ($anKpis as [$label, $value, $color, $sub]): ?>
            <div class="an-kpi" style="--kc:<?= anE($color) ?>">
                <span class="an-kpi-label"><?= anE($label) ?></span>
                <strong><?= (int) $value ?></strong>
                <small><?= anE($sub) ?></small>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($total === 0): ?>
        <p class="an-message">No creatives found for this company and period.</p>
    <?php endif; ?>

    <div class="an-grid">
        <!-- Status donut -->
        <article class="an-card">
            <h3>Status Breakdown</h3>
            <div class="an-donut-wrap">
                <div class="an-donut" id="anStatusDonut"></div>
                <ul class="an-legend" id="anStatusLegend"></ul>
            </div>
        </article>

        <!-- Rates -->
        <article class="an-card">
            <h3>Performance Rates</h3>
            <div class="an-rates">
                <?php foreach ([
                    ['Approval rate', $approvalRate, $anStatuses['approved'][1]],
                    ['Redo rate', $redoRate, $anStatuses['revision'][1]],
                    ['Rejection rate', $rejectRate, $anStatuses['rejected'][1]],
                    ['Delay rate', $delayRate, '#fb923c'],
                ] as [$rl, $rv, $rc]): ?>
                    <div class="an-rate">
                        <div class="an-rate-top"><span><?= anE($rl) ?></span><strong style="color:<?= anE($rc) ?>"><?= $rv ?>%</strong></div>
                        <div class="an-track"><span style="width:<?= min(100, $rv) ?>%;background:<?= anE($rc) ?>"></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <!-- Monthly chart: one section, one toggle button switches the view -->
        <article class="an-card an-wide" id="anComboCard">
            <div class="an-card-head">
                <h3 id="anComboTitle">Creatives per Month <small>by status</small></h3>
                <button type="button" class="an-toggle" id="anToggle" aria-pressed="false" aria-controls="anMonthlyView anTrendView">Show trend %</button>
            </div>

            <div id="anMonthlyView">
                <div class="an-chart" id="anMonthlyChart"></div>
                <ul class="an-legend an-legend-inline" id="anMonthlyLegend"></ul>
            </div>

            <div id="anTrendView" hidden>
                <div class="an-chart" id="anTrendChart"></div>
                <ul class="an-legend an-legend-inline">
                    <li><span class="an-sw" style="background:<?= anE($anStatuses['approved'][1]) ?>"></span>Approval %</li>
                    <li><span class="an-sw" style="background:<?= anE($anStatuses['revision'][1]) ?>"></span>Redo %</li>
                    <li><span class="an-sw" style="background:<?= anE($anStatuses['rejected'][1]) ?>"></span>Rejection %</li>
                </ul>
            </div>
        </article>

        <!-- Platforms -->
        <article class="an-card">
            <h3>Creatives by Platform</h3>
            <div id="anPlatforms" class="an-bars"></div>
        </article>

        <!-- Upcoming + meetings -->
        <article class="an-card">
            <h3>Workload &amp; Meetings</h3>
            <h4>Scheduled creatives, next 4 weeks</h4>
            <div id="anWeeks" class="an-bars"></div>
            <h4>Meetings</h4>
            <div class="an-meet">
                <div id="anMeetDonut" class="an-donut an-donut-sm"></div>
                <ul class="an-legend">
                    <li><span class="an-sw" style="background:#3dd693"></span>Done <strong><?= $meetDone ?></strong></li>
                    <li><span class="an-sw" style="background:#ffc052"></span>Not done <strong><?= $meetPending ?></strong></li>
                    <li><span class="an-sw" style="background:#60a5fa"></span>Upcoming <strong><?= $meetUpcoming ?></strong></li>
                </ul>
            </div>
        </article>
    </div>

    <script type="application/json" id="anData"><?= $anJson ?></script>
    <script src="../assets/js/smeanalytics.js" defer></script>
    <?php endif; ?>
</section>
