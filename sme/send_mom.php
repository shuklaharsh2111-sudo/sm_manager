<?php
/*
| MOM request. The SME clicks "Send MOM" once the meeting has started (or earlier than planned
| if it ends early). Nothing else is sent: the meeting is over only when the admin approves.
| Attendees and points discussed are added afterwards (sme/save_details.php).
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

    $upd = $conn->prepare(
        "UPDATE sme_meetings
         SET mom_status = 'pending', mom_sent_at = NOW(),
             mom_reviewed_at = NULL, mom_reviewed_by = NULL, mom_admin_note = NULL
         WHERE id = ? AND sme_user_id = ? AND mom_status IN ('none','rejected') AND meeting_status <> 'done'"
    );
    $upd->bind_param('ii', $meetingId, $userId);
    if (!$upd->execute() || $upd->affected_rows < 1) {
        $upd->close();
        momResponse(409, ['success' => false, 'message' => 'Unable to send the MOM. Refresh and try again.']);
    }
    $upd->close();

    $meeting['mom_status'] = 'pending';
    $attendees = smeMeetingFetchAttendees($conn, [$meetingId])[$meetingId] ?? [];

    momResponse(200, [
        'success'   => true,
        'message'   => 'MOM request sent to admin. The meeting is over once the admin approves it.',
        'card_html' => smeMeetingRenderCard($meeting, $attendees),
    ]);
} catch (Throwable $e) {
    error_log('Send MOM error: ' . $e->getMessage());
    momResponse(500, ['success' => false, 'message' => 'Unable to send the MOM. Please try again.']);
}
