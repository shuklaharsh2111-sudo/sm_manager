<?php
/*
|--------------------------------------------------------------------------
| SME MEETING HELPERS
|--------------------------------------------------------------------------
| Shared by sme/dashboard.php (page load) and sme/add_meeting.php (AJAX), so
| the meeting card is rendered by exactly one function.
|
| Extra tables/columns are created automatically on first use
| (see meeting_migration.sql if the MySQL user cannot CREATE / ALTER).
*/

function smeMeetingEsc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Creates the attendee table and the extra meeting columns when missing.
 * Returns true when everything is available.
 */
function smeMeetingEnsureSchema(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS `sme_meeting_attendees` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `meeting_id` int(11) NOT NULL,
          `user_id` int(11) DEFAULT NULL,
          `name` varchar(150) NOT NULL,
          `designation` varchar(150) NOT NULL DEFAULT '',
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `idx_meeting` (`meeting_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $needed = [
        'points_discussed'       => 'TEXT NULL',
        'next_meeting_date'      => 'DATE NULL',
        'next_meeting_time'      => 'TIME NULL',
        'next_meeting_agenda'    => 'VARCHAR(500) NULL',
        'next_meeting_attendees' => 'VARCHAR(500) NULL',
        // Set when the SME saves attendees + points discussed (only possible after admin approves the MOM).
        'details_saved_at'       => 'DATETIME NULL',
        // Minutes of Meeting (MOM) approval flow: none -> pending -> approved | rejected
        'mom_status'             => "VARCHAR(12) NOT NULL DEFAULT 'none'",
        'mom_sent_at'            => 'DATETIME NULL',
        'mom_reviewed_at'        => 'DATETIME NULL',
        'mom_reviewed_by'        => 'INT NULL',
        'mom_admin_note'         => 'VARCHAR(500) NULL',
    ];

    $readColumns = static function () use ($conn): array {
        $found = [];
        $res = $conn->query('SHOW COLUMNS FROM `sme_meetings`');
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $found[$row['Field']] = true;
            }
            $res->free();
        }
        return $found;
    };

    $existing = $readColumns();
    foreach ($needed as $column => $definition) {
        if (!isset($existing[$column])) {
            $conn->query(
                'ALTER TABLE `sme_meetings` ADD COLUMN `' . $column . '` ' . $definition
            );
        }
    }

    $existing = $readColumns();
    $ready = true;
    foreach (array_keys($needed) as $column) {
        if (!isset($existing[$column])) {
            $ready = false;
            error_log('SME meetings: column ' . $column . ' is missing. Run meeting_migration.sql.');
        }
    }

    $tableCheck = $conn->query("SHOW TABLES LIKE 'sme_meeting_attendees'");
    if (!($tableCheck instanceof mysqli_result) || $tableCheck->num_rows === 0) {
        $ready = false;
        error_log('SME meetings: sme_meeting_attendees is missing. Run meeting_migration.sql.');
    }
    if ($tableCheck instanceof mysqli_result) {
        $tableCheck->free();
    }

    return $ready;
}

/** Public URL (relative to /sme/) of an uploaded profile picture, or ''. */
function smeMeetingAvatarUrl(?string $profImage): string
{
    $file = basename(str_replace('\\', '/', trim((string) $profImage)));
    if ($file === '' || !is_file(__DIR__ . '/../uploads/' . $file)) {
        return '';
    }
    return '../uploads/' . rawurlencode($file);
}

/**
 * Attendees for several meetings in one query: [meeting_id => [attendee, ...]]
 */
function smeMeetingFetchAttendees(mysqli $conn, array $meetingIds): array
{
    $meetingIds = array_values(array_unique(array_filter(array_map('intval', $meetingIds))));
    if (!$meetingIds) {
        return [];
    }
    $sql = 'SELECT a.meeting_id, a.name, a.designation, u.prof_image
            FROM sme_meeting_attendees AS a
            LEFT JOIN users AS u ON u.id = a.user_id
            WHERE a.meeting_id IN (' . implode(',', $meetingIds) . ')
            ORDER BY a.id ASC';
    $result = $conn->query($sql);
    $grouped = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $grouped[(int) $row['meeting_id']][] = [
                'name'        => (string) $row['name'],
                'designation' => (string) $row['designation'],
                'avatar'      => smeMeetingAvatarUrl($row['prof_image'] ?? ''),
            ];
        }
        $result->free();
    }
    return $grouped;
}

function smeMeetingInitials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));
    if (!$parts) {
        return '?';
    }
    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $last = count($parts) > 1 ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) : '';
    return $first . $last;
}

/** Stable pastel-free gradient per person so the same name keeps its colour. */
function smeMeetingAvatarColor(string $name): string
{
    $palette = [
        '#6366f1', '#a855f7', '#ec4899', '#0ea5e9',
        '#14b8a6', '#f59e0b', '#ef4444', '#22c55e',
    ];
    return $palette[abs(crc32(mb_strtolower($name))) % count($palette)];
}


/** Start of the meeting as a Unix timestamp (India time), or null if the date/time is invalid. */
function smeMeetingStartTs(array $meeting): ?int
{
    $time = substr((string) ($meeting['meeting_time'] ?? '00:00:00'), 0, 5) . ':00';
    $start = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        ($meeting['meeting_date'] ?? '') . ' ' . $time,
        new DateTimeZone('Asia/Kolkata')
    );
    return $start !== false ? $start->getTimestamp() : null;
}

/** The meeting may be joined only once its start time has arrived. */
function smeMeetingHasStarted(array $meeting): bool
{
    $ts = smeMeetingStartTs($meeting);
    return $ts !== null && $ts <= time();
}

/**
 * A meeting has no end time. It is over only once the admin approves the MOM request.
 * The attendees and points discussed are added by the SME after that.
 */
function smeMeetingIsOver(array $meeting): bool
{
    return ($meeting['meeting_status'] ?? '') === 'done';
}

/** Saves the attendees entered after the MOM is approved, replacing any earlier list. */
function smeMeetingSaveAttendees(mysqli $conn, int $meetingId, array $attendees): array
{
    $del = $conn->prepare('DELETE FROM sme_meeting_attendees WHERE meeting_id = ?');
    $del->bind_param('i', $meetingId);
    $del->execute();
    $del->close();

    $saved = [];
    if (!$attendees) {
        return $saved;
    }
    $userStmt = $conn->prepare(
        'SELECT id, prof_image FROM users WHERE LOWER(TRIM(fullname)) = LOWER(?) LIMIT 1'
    );
    $attStmt = $conn->prepare(
        'INSERT INTO sme_meeting_attendees (meeting_id, user_id, name, designation) VALUES (?, ?, ?, ?)'
    );
    if (!$userStmt || !$attStmt) {
        throw new RuntimeException('Unable to prepare attendee queries.');
    }
    foreach ($attendees as $person) {
        $matchedId = null;
        $avatar = '';
        $userStmt->bind_param('s', $person['name']);
        if ($userStmt->execute()) {
            $found = $userStmt->get_result()->fetch_assoc();
            if ($found) {
                $matchedId = (int) $found['id'];
                $avatar = smeMeetingAvatarUrl($found['prof_image'] ?? '');
            }
        }
        $attStmt->bind_param('iiss', $meetingId, $matchedId, $person['name'], $person['designation']);
        if (!$attStmt->execute()) {
            throw new RuntimeException('Unable to save attendee: ' . $attStmt->error);
        }
        $saved[] = $person + ['avatar' => $avatar];
    }
    $userStmt->close();
    $attStmt->close();
    return $saved;
}

/** Extra SELECT columns the card needs (empty string if the schema is not ready). */
function smeMeetingMomColumns(): string
{
    return ',
        m.details_saved_at,
        m.mom_status,
        m.mom_sent_at,
        m.mom_admin_note';
}

/**
 * Full Minutes of Meeting, as HTML (used in the admin review panel).
 * $summaryHtml = already-safe HTML of the recording summary, or ''.
 */
function smeMeetingRenderMom(array $meeting, array $attendees, string $summaryHtml = ''): string
{
    $e = 'smeMeetingEsc';
    $html = '<div class="mom-doc">';
    $html .= '<p class="mom-meta"><strong>' . $e($meeting['subject'] ?? '') . '</strong>'
        . (!empty($meeting['company_name']) ? ' &middot; ' . $e($meeting['company_name']) : '')
        . ' &middot; ' . $e(date('d M Y', strtotime((string) $meeting['meeting_date'])))
        . ', ' . $e(date('h:i A', strtotime((string) $meeting['meeting_time']))) . '</p>';

    if ($attendees) {
        $names = array_map(
            static fn($a) => $a['name'] . ($a['designation'] !== '' ? ' (' . $a['designation'] . ')' : ''),
            $attendees
        );
        $html .= '<h4>Attendees</h4><p>' . $e(implode(', ', $names)) . '</p>';
    }

    $points = json_decode((string) ($meeting['points_discussed'] ?? ''), true);
    $html .= '<h4>Points discussed</h4>';
    if (is_array($points) && $points) {
        $html .= '<ol>';
        foreach ($points as $point) {
            $html .= '<li>' . $e($point) . '</li>';
        }
        $html .= '</ol>';
    } else {
        $html .= '<p class="mom-muted">The SME has not added the meeting details yet.</p>';
    }

    if (!empty($meeting['next_meeting_date'])) {
        $when = date('d M Y', strtotime((string) $meeting['next_meeting_date']));
        if (!empty($meeting['next_meeting_time'])) {
            $when .= ', ' . date('h:i A', strtotime((string) $meeting['next_meeting_time']));
        }
        $html .= '<h4>Next meeting</h4><p>' . $e($when)
            . (!empty($meeting['next_meeting_agenda']) ? ' &middot; ' . $e($meeting['next_meeting_agenda']) : '') . '</p>';
    }

    if ($summaryHtml !== '') {
        $html .= '<h4>Recording summary</h4><div class="mom-summary">' . $summaryHtml . '</div>';
    }
    return $html . '</div>';
}

/** One attendee / point row inside the meeting-details form. */
function smeMeetingMomRow(string $type, string $a = '', string $b = ''): string
{
    $e = 'smeMeetingEsc';
    if ($type === 'attendee') {
        return '<div class="sme-mt-row">'
            . '<input type="text" name="attendee_name[]" list="meetingPeople" maxlength="150" placeholder="Name" aria-label="Attendee name" autocomplete="off" value="' . $e($a) . '">'
            . '<input type="text" name="attendee_designation[]" maxlength="150" placeholder="Designation" aria-label="Attendee designation" value="' . $e($b) . '">'
            . '<button type="button" class="sme-mt-remove" aria-label="Remove row">&times;</button></div>';
    }
    return '<div class="sme-mt-row">'
        . '<input type="text" name="point[]" maxlength="500" placeholder="Point discussed" aria-label="Point discussed" value="' . $e($a) . '">'
        . '<button type="button" class="sme-mt-remove" aria-label="Remove row">&times;</button></div>';
}

/**
 * One meeting card (HTML string).
 *
 * $meeting keys: id, subject, comp_name, meeting_date, meeting_time,
 * meeting_link, meeting_status, mom_status, mom_admin_note, details_saved_at,
 * points_discussed, next_meeting_date, next_meeting_time, next_meeting_agenda,
 * next_meeting_attendees (the optional ones may be missing / null).
 *
 * Life of a card:
 *   before start time  -> "Not currently started" (Join locked)
 *   from start time    -> Join now + Send MOM (the SME may send it early or after the time)
 *   MOM sent           -> waiting for admin
 *   admin approves     -> meeting is over; SME adds attendees + points discussed
 *   details saved      -> shown in "Meeting details" (the SME's record and the admin's MOM view)
 */
function smeMeetingRenderCard(array $meeting, array $attendees = []): string
{
    $e = 'smeMeetingEsc';
    $maxAvatars = 3;

    $momStatus = (string) ($meeting['mom_status'] ?? 'none');
    $startTs = smeMeetingStartTs($meeting);
    $started = smeMeetingHasStarted($meeting);
    $isDone = smeMeetingIsOver($meeting);
    $isPending = !$isDone && $momStatus === 'pending';
    $detailsSaved = !empty($meeting['details_saved_at']);

    if ($isDone) {
        $statusClass = 'meeting-done';
        $statusLabel = 'Meeting over';
    } elseif ($isPending) {
        $statusClass = 'meeting-live';
        $statusLabel = 'Awaiting approval';
    } elseif (!$started) {
        $statusClass = 'meeting-upcoming';
        $statusLabel = 'Upcoming';
    } else {
        $statusClass = 'meeting-live';
        $statusLabel = 'In progress';
    }

    $timeValue = substr((string) ($meeting['meeting_time'] ?? ''), 0, 5);
    $dateOut = date('d M Y', strtotime((string) $meeting['meeting_date']));
    $timeOut = date('h:i A', strtotime((string) $meeting['meeting_time']));

    $html = '<article class="sme-meeting-card ' . $e($statusClass) . '"'
        . ' data-meeting-id="' . (int) ($meeting['id'] ?? 0) . '"'
        . ' data-start-ts="' . (int) $startTs . '"'
        . ' data-meeting-datetime="' . $e($meeting['meeting_date'] . 'T' . $timeValue) . '">';

    // Title + company + status pill, then when (no end time: a meeting only has a start)
    $html .= '<div class="sme-meeting-card-top">'
        . '<div class="sme-meeting-card-info">'
        . '<h3>' . $e($meeting['subject']) . '</h3>'
        . '<p class="sme-meeting-company">' . $e($meeting['comp_name'] ?? '') . '</p>'
        . '</div>'
        . '<span class="sme-meeting-pill">' . $e($statusLabel) . '</span>'
        . '</div>';
    $html .= '<div class="sme-meeting-datetime"><span>' . $e($dateOut) . '</span><span>' . $e($timeOut) . '</span></div>';

    // Join (only from the start time) + Send MOM + attendee avatars
    $html .= '<div class="sme-meeting-card-foot">';
    $link = (string) ($meeting['meeting_link'] ?? '');
    $canJoin = !$isDone && !$isPending && $link !== '';
    if ($canJoin && $started) {
        $html .= '<a class="sme-join-btn" href="' . $e($link) . '"'
            . ' target="_blank" rel="noopener noreferrer">Join now</a>';
    } elseif ($canJoin) {
        // Locked until the start time; the page script unlocks it on the dot.
        $html .= '<button type="button" class="sme-join-btn is-locked" disabled'
            . ' data-join-url="' . $e($link) . '">Not currently started</button>';
    } else {
        $html .= '<span></span>';
    }
    if (!$isDone && !$isPending) {
        $html .= '<button type="button" class="sme-mom-btn" data-mom-request'
            . ($started ? '' : ' hidden') . '>'
            . ($momStatus === 'rejected' ? 'Send MOM again' : 'Send MOM') . '</button>';
    }

    $count = count($attendees);
    if ($count > 0) {
        $html .= '<div class="sme-avatar-stack" aria-label="' . $count . ' attendee' . ($count === 1 ? '' : 's') . '">';
        foreach (array_slice($attendees, 0, $maxAvatars) as $person) {
            $label = $person['name'] . ($person['designation'] !== '' ? ' - ' . $person['designation'] : '');
            $html .= '<span class="sme-avatar" title="' . $e($label) . '"'
                . ' style="--avatar-bg:' . $e(smeMeetingAvatarColor($person['name'])) . '">';
            if (!empty($person['avatar'])) {
                $html .= '<img src="' . $e($person['avatar']) . '" alt="' . $e($person['name']) . '" loading="lazy"'
                    . ' onerror="this.remove()">';
            }
            $html .= '<b>' . $e(smeMeetingInitials($person['name'])) . '</b></span>';
        }
        if ($count > $maxAvatars) {
            $rest = $count - $maxAvatars;
            $html .= '<span class="sme-avatar sme-avatar-more" title="' . $rest . ' more">+' . $rest . '</span>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';

    // MOM status / meeting-details form
    if ($isPending) {
        $html .= '<div class="sme-mom-box is-pending"><span class="sme-mom-badge">MOM sent</span>'
            . '<span>Waiting for admin approval</span></div>';
    } elseif ($momStatus === 'rejected' && !$isDone) {
        $html .= '<div class="sme-mom-box"><p class="sme-mom-rejected"><strong>MOM sent back by admin.</strong>'
            . (!empty($meeting['mom_admin_note']) ? ' ' . $e($meeting['mom_admin_note']) : '') . '</p></div>';
    } elseif ($isDone) {
        $html .= '<div class="sme-mom-box is-approved"><span class="sme-mom-badge">Meeting over</span>'
            . '<span>' . ($momStatus === 'approved' ? 'MOM approved by admin' : 'Marked done by admin') . '</span>';
        if (!$detailsSaved) {
            $html .= '<button type="button" class="sme-mom-btn" data-mom-open>Add details &amp; save to Records</button>';
            $html .= '<form class="sme-mom-form" data-mom-form hidden>';
            $html .= '<div class="sme-mt-section"><div class="sme-mt-section-head"><h4>Attendees</h4>'
                . '<button type="button" class="sme-mt-add" data-mom-add="attendee" aria-label="Add attendee">+</button></div>'
                . '<div class="sme-mt-rows" data-mom-rows="attendee">' . smeMeetingMomRow('attendee') . '</div></div>';
            $html .= '<div class="sme-mt-section"><div class="sme-mt-section-head"><h4>Points Discussed</h4>'
                . '<button type="button" class="sme-mt-add" data-mom-add="point" aria-label="Add point">+</button></div>'
                . '<div class="sme-mt-rows sme-mt-numbered" data-mom-rows="point">' . smeMeetingMomRow('point') . '</div></div>';
            $html .= '<div class="sme-mom-actions">'
                . '<button type="button" class="sme-mom-cancel" data-mom-cancel>Cancel</button>'
                . '<button type="submit" class="sme-mom-send">Save to Records</button></div></form>';
        }
        $html .= '</div>';
    }

    // Saved record: attendees, points discussed, next meeting
    $points = json_decode((string) ($meeting['points_discussed'] ?? ''), true);
    $points = is_array($points) ? $points : [];
    $hasNext = !empty($meeting['next_meeting_date']);

    if ($count > 0 || $points || $hasNext) {
        $html .= '<details class="sme-meeting-details"' . ($detailsSaved ? ' open' : '') . '><summary>Meeting details</summary><div class="sme-meeting-details-body">';

        if ($count > 0) {
            $html .= '<h4>Attendees</h4><ul>';
            foreach ($attendees as $person) {
                $html .= '<li>' . $e($person['name'])
                    . ($person['designation'] !== '' ? ' <span>' . $e($person['designation']) . '</span>' : '')
                    . '</li>';
            }
            $html .= '</ul>';
        }
        if ($points) {
            $html .= '<h4>Points discussed</h4><ol>';
            foreach ($points as $point) {
                $html .= '<li>' . $e($point) . '</li>';
            }
            $html .= '</ol>';
        }
        if ($hasNext) {
            $when = date('d M Y', strtotime((string) $meeting['next_meeting_date']));
            if (!empty($meeting['next_meeting_time'])) {
                $when .= ', ' . date('h:i A', strtotime((string) $meeting['next_meeting_time']));
            }
            $html .= '<h4>Next meeting</h4><p>' . $e($when) . '</p>';
            if (!empty($meeting['next_meeting_agenda'])) {
                $html .= '<p><span>Agenda:</span> ' . $e($meeting['next_meeting_agenda']) . '</p>';
            }
            if (!empty($meeting['next_meeting_attendees'])) {
                $html .= '<p><span>Attendees:</span> ' . $e($meeting['next_meeting_attendees']) . '</p>';
            }
        }

        $html .= '</div></details>';
    }

    return $html . '</article>';
}


/** A finished meeting whose attendees + points discussed are saved belongs in Records, not in Upcoming. */
function smeMeetingIsRecorded(array $meeting): bool
{
    return smeMeetingIsOver($meeting) && !empty($meeting['details_saved_at']);
}

/**
 * Saved meeting record (HTML string) for the Records page.
 * $recording = optional Fathom row (needs share_url / recording_url) for this meeting.
 */
function smeMeetingRenderRecordCard(array $meeting, array $attendees = [], array $recording = []): string
{
    $e = 'smeMeetingEsc';
    $ts = strtotime((string) $meeting['meeting_date']);
    $timeOut = date('h:i A', strtotime((string) $meeting['meeting_time']));
    $savedOut = !empty($meeting['details_saved_at'])
        ? date('d M Y, h:i A', strtotime((string) $meeting['details_saved_at']))
        : '';
    $momStatus = (string) ($meeting['mom_status'] ?? 'none');
    $points = json_decode((string) ($meeting['points_discussed'] ?? ''), true);
    $points = is_array($points) ? $points : [];

    $searchText = mb_strtolower(
        ($meeting['subject'] ?? '') . ' ' . ($meeting['comp_name'] ?? '') . ' '
        . implode(' ', array_column($attendees, 'name')) . ' ' . implode(' ', $points)
    );

    $html = '<article class="meeting-record" data-search="' . $e($searchText) . '" data-meeting-id="' . (int) ($meeting['id'] ?? 0) . '">';

    // Header: date block, title + company, badge
    $html .= '<header class="meeting-record-head">'
        . '<div class="meeting-record-date"><b>' . $e(date('d', $ts)) . '</b><span>' . $e(date('M', $ts)) . '</span></div>'
        . '<div class="meeting-record-title"><h3>' . $e($meeting['subject'] ?? '') . '</h3>'
        . '<p class="meeting-record-meta">'
        . (!empty($meeting['comp_name']) ? $e($meeting['comp_name']) . ' &middot; ' : '')
        . $e(date('Y', $ts)) . ' &middot; ' . $e($timeOut) . '</p></div>'
        . '<span class="meeting-record-badge">' . ($momStatus === 'approved' ? 'MOM approved' : 'Completed') . '</span>'
        . '</header>';

    // Body: attendees | points discussed
    $html .= '<div class="meeting-record-body">';
    $html .= '<section class="meeting-record-col"><h4>Attendees <em>' . count($attendees) . '</em></h4>';
    if ($attendees) {
        $html .= '<ul class="meeting-record-people">';
        foreach ($attendees as $person) {
            $html .= '<li><span class="meeting-record-avatar" style="--avatar-bg:'
                . $e(smeMeetingAvatarColor($person['name'])) . '">';
            if (!empty($person['avatar'])) {
                $html .= '<img src="' . $e($person['avatar']) . '" alt="" loading="lazy" onerror="this.remove()">';
            }
            $html .= '<b>' . $e(smeMeetingInitials($person['name'])) . '</b></span>'
                . '<span class="meeting-record-person"><strong>' . $e($person['name']) . '</strong>'
                . ($person['designation'] !== '' ? '<small>' . $e($person['designation']) . '</small>' : '')
                . '</span></li>';
        }
        $html .= '</ul>';
    } else {
        $html .= '<p class="meeting-record-none">No attendees added.</p>';
    }
    $html .= '</section>';

    $html .= '<section class="meeting-record-col"><h4>Points discussed <em>' . count($points) . '</em></h4>';
    if ($points) {
        $html .= '<ol class="meeting-record-points">';
        foreach ($points as $point) {
            $html .= '<li>' . $e($point) . '</li>';
        }
        $html .= '</ol>';
    } else {
        $html .= '<p class="meeting-record-none">No points added.</p>';
    }
    $html .= '</section></div>';

    if (!empty($meeting['next_meeting_date'])) {
        $when = date('d M Y', strtotime((string) $meeting['next_meeting_date']));
        if (!empty($meeting['next_meeting_time'])) {
            $when .= ', ' . date('h:i A', strtotime((string) $meeting['next_meeting_time']));
        }
        $html .= '<div class="meeting-record-next"><span>Next meeting</span><strong>' . $e($when) . '</strong>'
            . (!empty($meeting['next_meeting_agenda']) ? '<em>' . $e($meeting['next_meeting_agenda']) . '</em>' : '')
            . '</div>';
    }

    $html .= '<footer class="meeting-record-foot"><span>'
        . ($savedOut !== '' ? 'Saved ' . $e($savedOut) : '') . '</span>';
    $link = (string) ($recording['share_url'] ?? '') !== '' ? (string) $recording['share_url'] : (string) ($recording['recording_url'] ?? '');
    if (preg_match('#^https?://#i', $link)) {
        $html .= '<a class="rec-btn" href="' . $e($link) . '" target="_blank" rel="noopener noreferrer">Watch recording</a>';
    }
    return $html . '</footer></article>';
}
