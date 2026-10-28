<?php
/*
| MOM request. The SME clicks "Send MOM" once the meeting has started, fills in the attendees and
| points discussed, and sends them together. The admin sees them and approves (meeting over, saved
| to Records) or sends the MOM back.
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function momResponse(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    momResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
$sessionRole = $_SESSION['role'] ?? '';
$roles = is_array($sessionRole)
    ? $sessionRole
    : (is_array($decoded = json_decode((string) $sessionRole, true)) ? $decoded : [$sessionRole]);
$roles = array_map(static fn($r) => strtolower(trim((string) $r)), $roles);
if (!$userId || !in_array('sme', $roles, true)) {
    momResponse(401, ['success' => false, 'message' => 'Please log in as an SME.']);
}

$submitted = $_POST['csrf_token'] ?? '';
$expected = $_SESSION['meeting_csrf_token'] ?? '';
if (!is_string($submitted) || !is_string($expected) || $expected === '' || !hash_equals($expected, $submitted)) {
    momResponse(403, ['success' => false, 'message' => 'Session verification failed. Refresh the page.']);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/meeting_helpers.php';

$meetingId = filter_var($_POST['meeting_id'] ?? null, FILTER_VALIDATE_INT);
if (!$meetingId || $meetingId < 1) {
    momResponse(422, ['success' => false, 'message' => 'Invalid meeting.']);
}

$clean = static function ($value, int $max): string {
    $value = preg_replace('/\s+/u', ' ', trim((string) $value));
    return mb_substr((string) $value, 0, $max);
};
$postList = static function (string $key): array {
    $value = $_POST[$key] ?? [];
    return is_array($value) ? array_values($value) : [];
};

// Attendees: Name + Designation rows.
$attendees = [];
$attendeeRoles = $postList('attendee_designation');
foreach ($postList('attendee_name') as $i => $rawName) {
    $name = $clean($rawName, 150);
    $role = $clean($attendeeRoles[$i] ?? '', 150);
    if ($name === '' && $role === '') {
        continue;
    }
    if ($name === '') {
        momResponse(422, ['success' => false, 'message' => 'Enter a name for every attendee you add.']);
    }
    $attendees[] = ['name' => $name, 'designation' => $role];
}
if (count($attendees) > 20) {
    momResponse(422, ['success' => false, 'message' => 'You can add up to 20 attendees.']);
}

// Points discussed.
$points = [];
foreach ($postList('point') as $rawPoint) {
    $point = $clean($rawPoint, 500);
    if ($point !== '') {
        $points[] = $point;
    }
}
if (!$points) {
    momResponse(422, ['success' => false, 'message' => 'Add at least one point discussed.']);
}
if (count($points) > 30) {
    momResponse(422, ['success' => false, 'message' => 'You can add up to 30 points.']);
}

try {
    if (!smeMeetingEnsureSchema($conn)) {
        throw new RuntimeException('Meeting tables are not ready.');
    }
    $stmt = $conn->prepare(
        'SELECT m.id, m.subject, m.meeting_date, m.meeting_time, m.meeting_link, m.meeting_status,
                m.mom_status, m.details_saved_at, m.points_discussed, m.next_meeting_date, m.next_meeting_time,
                m.next_meeting_agenda, m.next_meeting_attendees, m.mom_admin_note, c.comp_name
         FROM sme_meetings AS m
         LEFT JOIN companies AS c ON c.id = m.company_id
         WHERE m.id = ? AND m.sme_user_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $meetingId, $userId);
    $stmt->execute();
    $meeting = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$meeting) {
        momResponse(404, ['success' => false, 'message' => 'Meeting not found.']);
    }
    if (!smeMeetingHasStarted($meeting)) {
        momResponse(422, ['success' => false, 'message' => 'The meeting has not started yet.']);
    }
    if ($meeting['meeting_status'] === 'done' || $meeting['mom_status'] === 'approved') {
        momResponse(422, ['success' => false, 'message' => 'This meeting is already approved.']);
    }
    if ($meeting['mom_status'] === 'pending') {
        momResponse(422, ['success' => false, 'message' => 'The MOM request is already waiting for admin approval.']);
    }

    $json = json_encode($points, JSON_UNESCAPED_UNICODE);
    $conn->begin_transaction();
    $upd = $conn->prepare(
        "UPDATE sme_meetings
         SET mom_status = 'pending', mom_sent_at = NOW(),
             mom_reviewed_at = NULL, mom_reviewed_by = NULL, mom_admin_note = NULL,
             points_discussed = ?, details_saved_at = NOW()
         WHERE id = ? AND sme_user_id = ? AND mom_status IN ('none','rejected') AND meeting_status <> 'done'"
    );
    $upd->bind_param('sii', $json, $meetingId, $userId);
    if (!$upd->execute() || $upd->affected_rows < 1) {
        $upd->close();
        $conn->rollback();
        momResponse(409, ['success' => false, 'message' => 'Unable to send the MOM. Refresh and try again.']);
    }
    $upd->close();
    $attendees = smeMeetingSaveAttendees($conn, $meetingId, $attendees);
    $conn->commit();

    $meeting['points_discussed'] = $json;
    $meeting['details_saved_at'] = date('Y-m-d H:i:s');
    $meeting['mom_status'] = 'pending';

    momResponse(200, [
        'success'   => true,
        'message'   => 'MOM sent to admin with the attendees and points discussed. The meeting is over once the admin approves it.',
        'card_html' => smeMeetingRenderCard($meeting, $attendees),
    ]);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        @$conn->rollback();
    }
    error_log('Send MOM error: ' . $e->getMessage());
    momResponse(500, ['success' => false, 'message' => 'Unable to send the MOM. Please try again.']);
}
