<?php
/*
|--------------------------------------------------------------------------
| SME TASK MASTER
|--------------------------------------------------------------------------
| Included inside sme/dashboard.php, directly above the Company Health Report.
| Pitch Deck and Mood Board store uploads / links through taskmaster_api.php.
| Calendar lists every creative in the SME calendar; Reports shows totals by status colour.
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$tmUserId = (int) ($_SESSION['user_id'] ?? 0);
if ($tmUserId <= 0) {
    return;
}
function tmE($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
// key => [title, subtitle, API card name ('' = simple checklist)]
$tmCards = [
    'strategy' => ['Strategy', 'Pitch Deck', 'pitch'],
    'mood'     => ['Mood', 'Board', 'mood'],
    'calendar' => ['Calendar', '', ''],
    'reports'  => ['Reports', '', ''],
];

/*
| Creatives shown on the Calendar and Reports cards.
| Same scope as the SME calendar: creatives of the companies assigned to this SME.
*/
if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/../config/db.php';
}
$tmStatuses = [
    'draft'    => ['Draft',            '#a2aac5'],
    'pending'  => ['Pending',          '#ffc052'],
    'approved' => ['Approved',         '#3dd693'],
    'rejected' => ['Rejected',         '#ff6685'],
    'revision' => ['Redo Required',    '#b177ff'],
];
$tmCreatives = [];
$tmCounts = array_fill_keys(array_keys($tmStatuses), 0);
$tmStmt = $conn->prepare(
    'SELECT cr.id, cr.title, cr.platform, cr.creative_date, cr.status, c.comp_name
     FROM creatives cr
     LEFT JOIN companies c ON c.id = cr.company_id
     WHERE cr.company_id IN (
         SELECT sca.company_id FROM sme_company_assignments sca WHERE sca.sme_user_id = ?
     )
     ORDER BY cr.creative_date DESC, cr.id DESC'
);
if ($tmStmt) {
    $tmStmt->bind_param('i', $tmUserId);
    if ($tmStmt->execute()) {
        $tmRes = $tmStmt->get_result();
        while ($tmRow = $tmRes->fetch_assoc()) {
            $st = strtolower((string) $tmRow['status']);
            if (!isset($tmStatuses[$st])) {
                $st = 'draft'; // unknown / empty status is treated as draft
            }
            $tmRow['status'] = $st;
            $tmCounts[$st]++;
            $tmCreatives[] = $tmRow;
        }
    }
    $tmStmt->close();
}
$tmTotal = count($tmCreatives);
?>
<link rel="stylesheet" href="../assets/css/taskmaster.css">

<section class="tm-section" id="taskMaster"
         data-user="<?= $tmUserId ?>"
         data-api="../sme/taskmaster_api.php">
    <div class="tm-header">
        <div>
            <div class="tm-eyebrow"><span class="tm-dot"></span>SME WORKSPACE</div>
            <h2>Task <span>Master</span></h2>
            <p>Plan strategy, collect inspiration and track recurring work.</p>
        </div>
        <span class="tm-recurring">Recurring</span>
    </div>

    <div class="tm-grid">
        <?php foreach ($tmCards as $key => [$title, $sub, $apiCard]): ?>
            <article class="tm-card" data-card="<?= tmE($key) ?>"<?= $apiCard !== '' ? ' data-api-card="' . tmE($apiCard) . '"' : '' ?>>
                <header class="tm-card-head">
                    <h3><?= tmE($title) ?></h3>
                    <?php if ($sub !== ''): ?><span><?= tmE($sub) ?></span><?php endif; ?>
                </header>
                <?php if ($key === 'calendar'): ?>
                    <ul class="tm-list tm-cal-list" aria-label="All calendar creatives">
                        <?php if (!$tmCreatives): ?>
                            <li class="tm-empty">No creatives in the calendar</li>
                        <?php endif; ?>
                        <?php foreach ($tmCreatives as $cr):
                            $crDate = strtotime((string) $cr['creative_date']);
                            $crPlat = strlen($cr['platform']) <= 1 ? strtoupper($cr['platform']) : ucfirst($cr['platform']);
                            $crInfo = $tmStatuses[$cr['status']];
                        ?>
                            <li class="tm-cal-item">
                                <span class="tm-status-dot" style="background:<?= tmE($crInfo[1]) ?>" title="<?= tmE($crInfo[0]) ?>"></span>
                                <div class="tm-res-body">
                                    <span class="tm-res-title"><?= tmE($cr['title']) ?></span>
                                    <span class="tm-res-meta"><?= tmE($crPlat) ?> · <?= tmE($cr['comp_name'] ?: 'Unassigned company') ?></span>
                                </div>
                                <div class="tm-cal-side">
                                    <span class="tm-cal-date"><?= $crDate ? tmE(date('d M Y', $crDate)) : '-' ?></span>
                                    <span class="tm-pill" style="color:<?= tmE($crInfo[1]) ?>;border-color:<?= tmE($crInfo[1]) ?>"><?= tmE($crInfo[0]) ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="tm-card-link" href="sme.php?page=calendar">Open calendar &rarr;</a>
                <?php elseif ($key === 'reports'): ?>
                    <div class="tm-report">
                        <div class="tm-report-total">
                            <strong><?= $tmTotal ?></strong>
                            <span>Total Creatives</span>
                        </div>
                        <div class="tm-bar" role="img" aria-label="Creatives by status">
                            <?php foreach ($tmStatuses as $sk => [$sl, $sc]): if ($tmCounts[$sk] > 0): ?>
                                <span style="width:<?= round($tmCounts[$sk] / max($tmTotal, 1) * 100, 2) ?>%;background:<?= tmE($sc) ?>" title="<?= tmE($sl) ?>: <?= $tmCounts[$sk] ?>"></span>
                            <?php endif; endforeach; ?>
                        </div>
                        <ul class="tm-legend">
                            <?php foreach ($tmStatuses as $sk => [$sl, $sc]): ?>
                                <li>
                                    <span class="tm-status-dot" style="background:<?= tmE($sc) ?>"></span>
                                    <span class="tm-legend-name"><?= tmE($sl) ?></span>
                                    <strong style="color:<?= tmE($sc) ?>"><?= $tmCounts[$sk] ?></strong>
                                    <small><?= $tmTotal > 0 ? round($tmCounts[$sk] / $tmTotal * 100) : 0 ?>%</small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <ul class="tm-list" aria-label="<?= tmE($title) ?> items"></ul>
                <?php endif; ?>
                <?php if ($apiCard !== ''): ?>
                    <button type="button" class="tm-add-btn" aria-label="Add to <?= tmE($sub ?: $title) ?>">+</button>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>

    <!-- Add item dialog (shared by Pitch Deck and Mood Board) -->
    <div class="tm-modal" id="tmModal" hidden>
        <div class="tm-modal-backdrop" data-tm-close></div>
        <div class="tm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="tmModalTitle">
            <button type="button" class="tm-modal-close" data-tm-close aria-label="Close">&times;</button>
            <h3 id="tmModalTitle">Add to Pitch Deck</h3>

            <div class="tm-tabs" role="tablist">
                <button type="button" class="active" data-tm-tab="upload" role="tab">Upload file</button>
                <button type="button" data-tm-tab="link" role="tab">Add link</button>
            </div>

            <form id="tmUploadForm" class="tm-form" enctype="multipart/form-data">
                <label>Title <small>(optional)</small>
                    <input type="text" name="title" maxlength="150" placeholder="e.g. Q4 pitch deck">
                </label>
                <label><span id="tmFileLabel">File</span>
                    <input type="file" name="file" id="tmFileInput" required>
                </label>
                <p class="tm-hint" id="tmFileHint"></p>
                <p class="tm-msg" role="status" aria-live="polite"></p>
                <div class="tm-actions">
                    <button type="button" data-tm-close>Cancel</button>
                    <button type="submit" class="primary">Upload</button>
                </div>
            </form>

            <form id="tmLinkForm" class="tm-form" enctype="multipart/form-data" hidden>
                <label>Title <small>(optional)</small>
                    <input type="text" name="title" maxlength="150" placeholder="e.g. Competitor reference">
                </label>
                <label>Link
                    <input type="url" name="link_url" maxlength="2000" placeholder="https://..." required>
                </label>
                <label class="tm-mood-only">Image <small>(optional, shown with the link)</small>
                    <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
                </label>
                <p class="tm-msg" role="status" aria-live="polite"></p>
                <div class="tm-actions">
                    <button type="button" data-tm-close>Cancel</button>
                    <button type="submit" class="primary">Save link</button>
                </div>
            </form>
        </div>
    </div>
</section>

<script src="../assets/js/taskmaster.js" defer></script>
