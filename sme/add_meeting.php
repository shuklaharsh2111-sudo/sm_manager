<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
function meetingResponse(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}
// Only allow POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    meetingResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}
// Validate the logged-in SME.
$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT
);
$sessionRole = $_SESSION['role'] ?? '';
$roles = [];
if (is_array($sessionRole)) {
    $roles = $sessionRole;
} elseif (is_string($sessionRole)) {
    $decodedRoles = json_decode($sessionRole, true);
    $roles = is_array($decodedRoles)
        ? $decodedRoles
        : [$sessionRole];
}
$roles = array_map(
    static fn($role) => strtolower(trim((string) $role)),
    $roles
);
if (!$userId || !in_array('sme', $roles, true)) {
    meetingResponse(401, [
        'success' => false,
        'message' => 'Please log in as an SME.'
    ]);
}
// Validate CSRF token.
$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['meeting_csrf_token'] ?? '';
if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    meetingResponse(403, [
        'success' => false,
        'message' => 'Session verification failed. Refresh the page.'
    ]);
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/meeting_helpers.php';
$subject = trim((string) ($_POST['subject'] ?? ''));
$companyId = filter_var(
    $_POST['company_id'] ?? null,
    FILTER_VALIDATE_INT
);
$meetingDate = trim((string) ($_POST['meeting_date'] ?? ''));
$meetingTime = trim((string) ($_POST['meeting_time'] ?? ''));
$meetingLink = trim(
    (string) ($_POST['meeting_link'] ?? '')
);

// Validate subject.
if ($subject === '' || strlen($subject) > 255) {
    meetingResponse(422, [
        'success' => false,
        'message' => 'Enter a valid meeting subject.'
    ]);
}
if (!$companyId || $companyId < 1) {
    meetingResponse(422, [
        'success' => false,
        'message' => 'Please select a company.'
    ]);
}
// Validate date strictly in YYYY-MM-DD format.
$dateObject = DateTimeImmutable::createFromFormat(
    '!Y-m-d',
    $meetingDate
);
if (
    !$dateObject ||
    $dateObject->format('Y-m-d') !== $meetingDate ||
    $meetingDate < date('Y-m-d')
) {
    meetingResponse(422, [
        'success' => false,
        'message' => 'Select today or a future date.'
    ]);
}
// Validate time in HH:MM format.
if (
    !preg_match(
        '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
        $meetingTime
    )
) {
    meetingResponse(422, [
        'success' => false,
        'message' => 'Please select a valid meeting time.'
    ]);
}
// A meeting can only be scheduled for a time that has not passed yet.
$startCheck = DateTimeImmutable::createFromFormat(
    '!Y-m-d H:i',
    $meetingDate . ' ' . $meetingTime,
    new DateTimeZone('Asia/Kolkata')
);
if ($startCheck && $startCheck->getTimestamp() < time() - 60) {
    meetingResponse(422, [
        'success' => false,
        'message' => 'That time has already passed. Select a later time.'
    ]);
}
/* ---------- Extra meeting details ---------- */
$postList = static function (string $key): array {
    $value = $_POST[$key] ?? [];
    return is_array($value) ? array_values($value) : [];
};
$clean = static function ($value, int $max): string {
    $value = preg_replace('/\s+/u', ' ', trim((string) $value));
    return mb_substr((string) $value, 0, $max);
};
$isValidDate = static function (string $value): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $d !== false && $d->format('Y-m-d') === $value;
};

// Next meeting (all optional).
$nextDate = trim((string) ($_POST['next_meeting_date'] ?? ''));
$nextTime = trim((string) ($_POST['next_meeting_time'] ?? ''));
$nextAgenda = $clean($_POST['next_meeting_agenda'] ?? '', 500);
$nextAttendees = $clean($_POST['next_meeting_attendees'] ?? '', 500);
if ($nextDate === '' && ($nextTime !== '' || $nextAgenda !== '' || $nextAttendees !== '')) {
    meetingResponse(422, [
        'success' => false,
        'message' => 'Select a date for the next meeting.'
    ]);
}
if ($nextDate !== '') {
    if (!$isValidDate($nextDate) || $nextDate < date('Y-m-d') || $nextDate < $meetingDate) {
        meetingResponse(422, [
            'success' => false,
            'message' => 'The next meeting must be on or after this meeting.'
        ]);
    }
    if ($nextTime !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $nextTime)) {
        meetingResponse(422, [
            'success' => false,
            'message' => 'Enter a valid time for the next meeting.'
        ]);
    }
}

$linkGenerated = false;
if ($meetingLink === '') {
    // No link pasted: create a private Jitsi Meet room for this meeting.
    $slug = preg_replace('/[^A-Za-z0-9]+/', '', ucwords($subject));
    $slug = substr((string) $slug, 0, 24);
    if ($slug === '') {
        $slug = 'Meeting';
    }
    $meetingLink = 'https://meet.jit.si/' . $slug . '-' . bin2hex(random_bytes(6));
    $linkGenerated = true;
} else {
    if (
        strlen($meetingLink) > 2048 ||
        !filter_var($meetingLink, FILTER_VALIDATE_URL)
    ) {
        meetingResponse(422, [
            'success' => false,
            'message' => 'Please enter a valid meeting link.'
        ]);
    }
    $linkParts = parse_url($meetingLink);
    if (
        !is_array($linkParts) ||
        strtolower($linkParts['scheme'] ?? '') !== 'https' ||
        empty($linkParts['host']) ||
        isset($linkParts['user']) ||
        isset($linkParts['pass'])
    ) {
        meetingResponse(422, [
            'success' => false,
            'message' => 'Enter a valid HTTPS meeting link.'
        ]);
    }
}
try {
    // Verify the company is assigned to this SME.
    $checkSql = "
        SELECT c.id, c.comp_name
        FROM companies AS c
        INNER JOIN sme_company_assignments AS sca
            ON sca.company_id = c.id
        WHERE c.id = ?
          AND sca.sme_user_id = ?
        LIMIT 1
    ";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param('ii', $companyId, $userId);
    $checkStmt->execute();
    $companyResult = $checkStmt->get_result();
    $company = $companyResult->fetch_assoc();
    $checkStmt->close();
    if (!$company) {
        meetingResponse(403, [
            'success' => false,
            'message' => 'This company is not assigned to you.'
        ]);
    }
    // Make sure the attendee table / meeting columns exist.
    if (!smeMeetingEnsureSchema($conn)) {
        throw new RuntimeException('Meeting tables are not ready.');
    }

    $nextDateDb = $nextDate !== '' ? $nextDate : null;
    $nextTimeDb = ($nextDate !== '' && $nextTime !== '') ? $nextTime : null;
    $nextAgendaDb = $nextAgenda !== '' ? $nextAgenda : null;
    $nextAttendeesDb = $nextAttendees !== '' ? $nextAttendees : null;

    $conn->begin_transaction();

    $insertSql = "
        INSERT INTO sme_meetings (
            sme_user_id,
            company_id,
            subject,
            meeting_date,
            meeting_time,
            meeting_link,
            next_meeting_date,
            next_meeting_time,
            next_meeting_agenda,
            next_meeting_attendees
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $insertStmt = $conn->prepare($insertSql);
    if (!$insertStmt) {
        throw new RuntimeException(
            'Unable to prepare meeting insert query.'
        );
    }
    $insertStmt->bind_param(
        'iissssssss',
        $userId,
        $companyId,
        $subject,
        $meetingDate,
        $meetingTime,
        $meetingLink,
        $nextDateDb,
        $nextTimeDb,
        $nextAgendaDb,
        $nextAttendeesDb
    );
    if (!$insertStmt->execute()) {
        throw new RuntimeException('Unable to save meeting: ' . $insertStmt->error);
    }
    $meetingId = (int) $conn->insert_id;
    $insertStmt->close();

    $conn->commit();

    $meetingRow = [
        'id' => $meetingId,
        'subject' => $subject,
        'comp_name' => $company['comp_name'],
        'meeting_date' => $meetingDate,
        'meeting_time' => $meetingTime,
        'meeting_link' => $meetingLink,
        'meeting_status' => 'not_done',
        'next_meeting_date' => $nextDateDb,
        'next_meeting_time' => $nextTimeDb,
        'next_meeting_agenda' => $nextAgendaDb,
        'next_meeting_attendees' => $nextAttendeesDb,
    ];

    meetingResponse(201, [
        'success' => true,
        'message' => $linkGenerated
            ? 'Meeting created and its link is ready.'
            : 'Meeting added successfully.',
        'meeting' => [
            'id' => $meetingId,
            'subject' => $subject,
            'company' => $company['comp_name'],
            'meeting_date' => $meetingDate,
            'meeting_time' => $meetingTime,
            'meeting_link' => $meetingLink
        ],
        'link_generated' => $linkGenerated,
        'card_html' => smeMeetingRenderCard($meetingRow, []),
    ]);
} catch (Throwable $exception) {
    if (isset($conn) && $conn instanceof mysqli) {
        @$conn->rollback();
    }
    error_log(
        'Add SME meeting error: ' . $exception->getMessage()
    );
    meetingResponse(500, [
        'success' => false,
        'message' => 'Unable to save the meeting. Please try again.'
    ]);
}
