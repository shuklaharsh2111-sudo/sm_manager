<?php
/*
 * saved.php - SME "Saved" page.
 *
 * Lists every SOP the signed-in SME has ticked on the dashboard (newest first).
 * Rendered inside sme.php (sme.php?page=saved).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Opened directly? Go through the SME wrapper so the sidebar and styles load.
if (
    isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    header('Location: sme.php?page=saved');
    exit;
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/sop_helpers.php';
smeSopTimezone();

function svE($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$svUserId = (int) ($_SESSION['user_id'] ?? 0);
$svType = strtolower(trim((string) ($_GET['type'] ?? 'all')));
if (!in_array($svType, ['all', 'daily', 'weekly', 'monthly'], true)) {
    $svType = 'all';
}

$svRows = [];
$svCounts = ['all' => 0, 'daily' => 0, 'weekly' => 0, 'monthly' => 0];
$svError = '';
try {
    smeSopEnsureTable($conn);
    $stmt = $conn->prepare(
        'SELECT id, sop_type, period_key, sop_title, company_name, completed_at
         FROM sme_sop_completions
         WHERE sme_user_id = ?
         ORDER BY completed_at DESC, id DESC
         LIMIT 500'
    );
    $stmt->bind_param('i', $svUserId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $type = (string) $row['sop_type'];
        $svCounts['all']++;
        if (isset($svCounts[$type])) {
            $svCounts[$type]++;
        }
        if ($svType === 'all' || $svType === $type) {
            $svRows[] = $row;
        }
    }
    $stmt->close();
} catch (Throwable $e) {
    error_log('SME saved page error: ' . $e->getMessage());
    $svError = 'Unable to load your saved SOPs. Please try again later.';
}

// Group by the day each tick was saved.
$svGroups = [];
$todayKey = date('Y-m-d');
$yesterdayKey = date('Y-m-d', strtotime('-1 day'));
foreach ($svRows as $row) {
    $dayKey = date('Y-m-d', strtotime((string) $row['completed_at']));
    $svGroups[$dayKey][] = $row;
}
$svTabs = [
    'all' => 'All',
    'daily' => 'Daily',
    'weekly' => 'Weekly',
    'monthly' => 'Monthly',
];
?>
<section class="sv" id="savedSops">
    <div class="sv-head">
        <h2>Saved</h2>
        <p>Every SOP you tick on the dashboard is saved here. Daily ticks refresh each day and weekly ticks each week, and your history stays in this list.</p>
    </div>

    <div class="sv-tabs" role="tablist" aria-label="Filter saved SOPs">
        <?php foreach ($svTabs as $key => $label): ?>
            <a
                class="sv-tab<?= $svType === $key ? ' is-active' : '' ?>"
                href="sme.php?page=saved&amp;type=<?= svE($key) ?>"
                <?= $svType === $key ? 'aria-current="page"' : '' ?>>
                <?= svE($label) ?>
                <span class="sv-tab-count"><?= (int) $svCounts[$key] ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($svError !== ''): ?>
        <p class="sv-empty" role="alert"><?= svE($svError) ?></p>
    <?php elseif (empty($svGroups)): ?>
        <div class="sv-empty">
            <i class="fa-regular fa-circle-check" aria-hidden="true"></i>
            <h3>Nothing saved yet</h3>
            <p>Tick a SOP on the <a href="sme.php?page=dashboard">dashboard</a> and it will appear here.</p>
        </div>
    <?php else: ?>
        <?php foreach ($svGroups as $dayKey => $items): ?>
            <?php
            if ($dayKey === $todayKey) {
                $dayLabel = 'Today';
            } elseif ($dayKey === $yesterdayKey) {
                $dayLabel = 'Yesterday';
            } else {
                $dayLabel = date('l, d M Y', strtotime($dayKey));
            }
            ?>
            <div class="sv-day">
                <h3 class="sv-day-title">
                    <?= svE($dayLabel) ?>
                    <span><?= count($items) ?></span>
                </h3>
                <div class="sv-list">
                    <?php foreach ($items as $item): ?>
                        <?php $type = (string) $item['sop_type']; ?>
                        <article class="sv-item">
                            <span class="sv-item-tick" aria-hidden="true">
                                <i class="fa-solid fa-check"></i>
                            </span>
                            <div class="sv-item-body">
                                <h4><?= svE($item['sop_title']) ?></h4>
                                <p class="sv-item-company"><?= svE($item['company_name'] !== '' ? $item['company_name'] : 'Unknown Company') ?></p>
                            </div>
                            <div class="sv-item-meta">
                                <span class="sv-badge sv-badge-<?= svE($type) ?>"><?= svE(ucfirst($type)) ?></span>
                                <span class="sv-item-period"><?= svE(smeSopPeriodText($type, (string) $item['period_key'])) ?></span>
                                <span class="sv-item-time"><?= svE(date('h:i A', strtotime((string) $item['completed_at']))) ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
