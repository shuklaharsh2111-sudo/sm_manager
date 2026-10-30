<?php
/*
|--------------------------------------------------------------------------
| SME SOP TICK / SAVE HELPERS
|--------------------------------------------------------------------------
| A ticked SOP is saved in `sme_sop_completions` and listed on the Saved page.
| The tick refreshes by itself:
|   daily   SOP -> a new tick is needed every day
|   weekly  SOP -> a new tick is needed every week (Monday to Sunday)
|   monthly SOP -> a new tick is needed every month
| Old ticks are kept as history on the Saved page.
*/

function smeSopTimezone(): void
{
    date_default_timezone_set('Asia/Kolkata');
}

/** Creates the table the first time it is needed (see sme_sop_completions_migration.sql). */
function smeSopEnsureTable(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS `sme_sop_completions` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `sme_user_id` int(11) NOT NULL,
          `sop_id` int(11) NOT NULL,
          `company_id` int(11) NOT NULL DEFAULT 0,
          `sop_type` varchar(10) NOT NULL,
          `period_key` varchar(12) NOT NULL,
          `sop_title` varchar(255) NOT NULL DEFAULT '',
          `company_name` varchar(255) NOT NULL DEFAULT '',
          `completed_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_user_sop_period` (`sme_user_id`,`sop_id`,`period_key`),
          KEY `idx_user_done` (`sme_user_id`,`completed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

/** Key of the period a tick belongs to: day, ISO week or month. */
function smeSopPeriodKey(string $type, ?int $timestamp = null): string
{
    smeSopTimezone();
    $timestamp = $timestamp ?? time();
    switch ($type) {
        case 'daily':
            return date('Y-m-d', $timestamp);
        case 'weekly':
            return date('o-\WW', $timestamp);
        case 'monthly':
            return date('Y-m', $timestamp);
    }
    return '';
}

/** Short text such as "Today", "This week" shown beside a ticked SOP. */
function smeSopPeriodLabel(string $type): string
{
    return [
        'daily'   => 'today',
        'weekly'  => 'this week',
        'monthly' => 'this month',
    ][$type] ?? '';
}

/** Readable form of a stored period key for the Saved page. */
function smeSopPeriodText(string $type, string $key): string
{
    smeSopTimezone();
    if ($type === 'daily' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
        return date('d M Y', strtotime($key));
    }
    if ($type === 'weekly' && preg_match('/^(\d{4})-W(\d{2})$/', $key, $m)) {
        $start = new DateTime();
        $start->setISODate((int) $m[1], (int) $m[2], 1);
        $end = clone $start;
        $end->modify('+6 days');
        return $start->format('d M') . ' – ' . $end->format('d M Y');
    }
    if ($type === 'monthly' && preg_match('/^\d{4}-\d{2}$/', $key)) {
        return date('F Y', strtotime($key . '-01'));
    }
    return $key;
}

/**
 * IDs of SOPs this user has already ticked in the CURRENT day / week / month.
 * Returns [sop_id => completed_at].
 */
function smeSopFetchCurrentDone(mysqli $conn, int $userId): array
{
    smeSopTimezone();
    $keys = [
        smeSopPeriodKey('daily'),
        smeSopPeriodKey('weekly'),
        smeSopPeriodKey('monthly'),
    ];
    $done = [];
    $stmt = $conn->prepare(
        'SELECT sop_id, sop_type, period_key, completed_at
         FROM sme_sop_completions
         WHERE sme_user_id = ? AND period_key IN (?, ?, ?)'
    );
    if (!$stmt) {
        return $done;
    }
    $stmt->bind_param('isss', $userId, $keys[0], $keys[1], $keys[2]);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            // The key must match the SOP's own type (a weekly key never ticks a daily SOP).
            if (smeSopPeriodKey((string) $row['sop_type']) === $row['period_key']) {
                $done[(int) $row['sop_id']] = $row['completed_at'];
            }
        }
    }
    $stmt->close();
    return $done;
}

/** Escape helper for the SOP renderer. */
function smeSopEsc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Every SOP the admin has set up for the companies assigned to this SME,
 * grouped as ['daily' => [...], 'weekly' => [...], 'monthly' => [...]].
 * Adding an SOP (Users > Manage SOPs, or Companies) or assigning a company
 * to the SME makes it appear here on the next load / auto-refresh.
 */
function smeSopFetchAssigned(mysqli $conn, int $userId): array
{
    $grouped = ['daily' => [], 'weekly' => [], 'monthly' => []];
    $stmt = $conn->prepare(
        'SELECT cs.id, cs.company_id, c.comp_name, cs.sop_type, cs.sop_title,
                cs.sop_description, cs.sop_date, cs.created_at
         FROM company_sops AS cs
         INNER JOIN companies AS c ON c.id = cs.company_id
         INNER JOIN sme_company_assignments AS sca ON sca.company_id = c.id
         WHERE sca.sme_user_id = ?
         ORDER BY FIELD(cs.sop_type, \'daily\', \'weekly\', \'monthly\'),
                  cs.sop_date ASC, cs.id ASC'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare SOP query.');
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $type = strtolower(trim((string) $row['sop_type']));
        if (isset($grouped[$type])) {
            $grouped[$type][] = $row;
        }
    }
    $stmt->close();
    return $grouped;
}

/** The three tab buttons + three panels (one visible at a time). */
function smeSopRenderTabs(array $sops, array $done, string $active = 'daily'): string
{
    smeSopTimezone();
    $titles = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];
    if (!isset($titles[$active])) {
        $active = 'daily';
    }
    $newAfter = time() - 86400; // assigned in the last 24 h => "New"

    $tabs = '<div class="sme-sop-toggle" role="tablist" aria-label="SOP frequency">';
    $panels = '';
    foreach ($titles as $type => $label) {
        $list = $sops[$type] ?? [];
        $total = count($list);
        $doneCount = 0;
        foreach ($list as $s) {
            if (isset($done[(int) $s['id']])) {
                $doneCount++;
            }
        }
        $isActive = $type === $active;
        $tabs .= '<button type="button" class="sme-sop-toggle-btn' . ($isActive ? ' is-active' : '')
            . '" role="tab" id="sopTab-' . $type . '" data-sop-tab="' . $type
            . '" aria-controls="sopPanel-' . $type . '" aria-selected="' . ($isActive ? 'true' : 'false') . '">'
            . smeSopEsc($label)
            . ' <span class="sme-sop-count" data-sop-count="' . $type . '">'
            . '<span class="sme-sop-done-count">' . $doneCount . '</span>/' . $total . '</span></button>';

        $panels .= '<div class="sme-sop-panel" role="tabpanel" id="sopPanel-' . $type
            . '" aria-labelledby="sopTab-' . $type . '" data-sop-type="' . $type . '"'
            . ($isActive ? '' : ' hidden') . '><div class="sme-sop-list">';
        if (!$list) {
            $panels .= '<p class="sme-sop-empty">No ' . strtolower($label) . ' SOPs assigned yet.</p>';
        }
        foreach ($list as $sop) {
            $id = (int) $sop['id'];
            $isDone = isset($done[$id]);
            $isNew = !empty($sop['created_at']) && strtotime((string) $sop['created_at']) >= $newAfter;
            $period = smeSopPeriodLabel($type);
            $panels .= '<article class="sme-sop-item' . ($isDone ? ' is-done' : '') . '">'
                . '<label class="sme-sop-check"><input type="checkbox" class="sme-sop-checkbox" data-sop-id="' . $id
                . '" aria-label="Mark SOP as done ' . smeSopEsc($period) . '"' . ($isDone ? ' checked' : '') . '>'
                . '<span class="sme-sop-tick" aria-hidden="true"><i class="fa-solid fa-check"></i></span>'
                . '<h4 class="sme-sop-title">' . smeSopEsc($sop['sop_title'] ?: 'Untitled SOP') . '</h4>'
                . ($isNew ? '<span class="sme-sop-new">New</span>' : '')
                . '</label>'
                . '<p class="sme-sop-company"><i class="fa-solid fa-building" aria-hidden="true"></i> '
                . smeSopEsc($sop['comp_name'] ?: 'Unknown Company') . '</p>';
            if (!empty($sop['sop_description']) && trim((string) $sop['sop_description']) !== trim((string) $sop['sop_title'])) {
                $panels .= '<p class="sme-sop-description">' . nl2br(smeSopEsc($sop['sop_description'])) . '</p>';
            }
            if (!empty($sop['sop_date'])) {
                $panels .= '<p class="sme-sop-date"><span>Date:</span> '
                    . smeSopEsc(date('d M Y', strtotime((string) $sop['sop_date']))) . '</p>';
            }
            $panels .= '<p class="sme-sop-saved-note"' . ($isDone ? '' : ' hidden') . '>'
                . '<i class="fa-solid fa-circle-check" aria-hidden="true"></i> Saved ' . smeSopEsc($period)
                . '<span class="sme-sop-saved-time">'
                . ($isDone ? ' · ' . smeSopEsc(date('h:i A', strtotime((string) $done[$id]))) : '')
                . '</span></p></article>';
        }
        $panels .= '</div></div>';
    }
    return $tabs . '</div>' . $panels;
}
