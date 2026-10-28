<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function endResponse(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    endResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
$sessionRole = $_SESSION['role'] ?? '';
$roles = is_array($sessionRole)
    ? $sessionRole
    : (is_array($decoded = json_decode((string) $sessionRole, true)) ? $decoded : [$sessionRole]);
$roles = array_map(static fn($r) => strtolower(trim((string) $r)), $roles);
if (!$userId || !in_array('sme', $roles, true)) {
    endResponse(401, ['success' => false, 'message' => 'Please log in as an SME.']);
}

$submitted = $_POST['csrf_token'] ?? '';
$expected = $_SESSION['meeting_csrf_token'] ?? '';
if (!is_string($submitted) || !is_string($expected) || $expected === '' || !hash_equals($expected, $submitted)) {
    endResponse(403, ['success' => false, 'message' => 'Session verification failed. Refresh the page.']);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/meeting_helpers.php';

$meetingId = filter_var($_POST['meeting_id'] ?? null, FILTER_VALIDATE_INT);
if (!$meetingId || $meetingId < 1) {
    endResponse(422, ['success' => false, 'message' => 'Invalid meeting.']);
}

try {
    if (!smeMeetingEnsureSchema($conn)) {
        throw new RuntimeException('Meeting tables are not ready.');
    }
    $stmt = $conn->prepare(
        'SELECT m.id, m.subject, m.meeting_date, m.meeting_time, m.meeting_link, m.meeting_status,
                m.mom_status, m.meeting_ended_at, m.points_discussed, m.next_meeting_date,
                m.next_meeting_time, m.next_meeting_agenda, m.next_meeting_attendees,
                m.mom_admin_note, c.comp_name
         FROM sme_meetings AS m
         LEFT JOIN companies AS c ON c.id = m.company_id
         WHERE m.id = ? AND m.sme_user_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $meetingId, $userId);
    $stmt->execute();
    $meeting = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$meeting) {
        endResponse(404, ['success' => false, 'message' => 'Meeting not found.']);
    }
    if (!smeMeetingHasStarted($meeting)) {
        endResponse(422, ['success' => false, 'message' => 'The meeting has not started yet.']);
    }
    if ($meeting['meeting_status'] === 'done' || $meeting['mom_status'] === 'pending' || smeMeetingIsOver($meeting)) {
        endResponse(422, ['success' => false, 'message' => 'This meeting is already over.']);
    }

    $upd = $conn->prepare(
        "UPDATE sme_meetings SET meeting_ended_at = NOW()
         WHERE id = ? AND sme_user_id = ? AND meeting_ended_at IS NULL AND meeting_status <> 'done'"
    );
    $upd->bind_param('ii', $meetingId, $userId);
    if (!$upd->execute() || $upd->affected_rows < 1) {
        $upd->close();
        endResponse(409, ['success' => false, 'message' => 'Unable to end the meeting. Refresh and try again.']);
    }
    $upd->close();

    $meeting['meeting_ended_at'] = date('Y-m-d H:i:s');
    $attendees = smeMeetingFetchAttendees($conn, [$meetingId])[$meetingId] ?? [];

    endResponse(200, [
        'success'   => true,
        'message'   => 'Meeting ended. Add the attendees and points discussed, then send the MOM.',
        'card_html' => smeMeetingRenderCard($meeting, $attendees),
    ]);
} catch (Throwable $e) {
    error_log('End meeting error: ' . $e->getMessage());
    endResponse(500, ['success' => false, 'message' => 'Unable to end the meeting. Please try again.']);
}
