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
