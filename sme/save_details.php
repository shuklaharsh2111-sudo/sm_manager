<?php
/*
| Attendees + points discussed. Allowed only after the admin has approved the MOM
| (the meeting is then over). Saved details move the meeting into the SME's Records page and show in the admin MOM view.
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function detailsResponse(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    detailsResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
$sessionRole = $_SESSION['role'] ?? '';
$roles = is_array($sessionRole)
    ? $sessionRole
    : (is_array($decoded = json_decode((string) $sessionRole, true)) ? $decoded : [$sessionRole]);
$roles = array_map(static fn($r) => strtolower(trim((string) $r)), $roles);
if (!$userId || !in_array('sme', $roles, true)) {
    detailsResponse(401, ['success' => false, 'message' => 'Please log in as an SME.']);
}

$submitted = $_POST['csrf_token'] ?? '';
$expected = $_SESSION['meeting_csrf_token'] ?? '';
if (!is_string($submitted) || !is_string($expected) || $expected === '' || !hash_equals($expected, $submitted)) {
    detailsResponse(403, ['success' => false, 'message' => 'Session verification failed. Refresh the page.']);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/meeting_helpers.php';

$meetingId = filter_var($_POST['meeting_id'] ?? null, FILTER_VALIDATE_INT);
if (!$meetingId || $meetingId < 1) {
    detailsResponse(422, ['success' => false, 'message' => 'Invalid meeting.']);
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
        detailsResponse(422, ['success' => false, 'message' => 'Enter a name for every attendee you add.']);
    }
    $attendees[] = ['name' => $name, 'designation' => $role];
}
if (count($attendees) > 20) {
    detailsResponse(422, ['success' => false, 'message' => 'You can add up to 20 attendees.']);
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
    detailsResponse(422, ['success' => false, 'message' => 'Add at least one point discussed.']);
}
if (count($points) > 30) {
    detailsResponse(422, ['success' => false, 'message' => 'You can add up to 30 points.']);
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
        detailsResponse(404, ['success' => false, 'message' => 'Meeting not found.']);
    }
    if ($meeting['meeting_status'] !== 'done') {
        detailsResponse(422, ['success' => false, 'message' => 'You can add the details after the admin approves the MOM.']);
    }
    if (!empty($meeting['details_saved_at'])) {
        detailsResponse(422, ['success' => false, 'message' => 'The details for this meeting are already saved.']);
    }

    $json = json_encode($points, JSON_UNESCAPED_UNICODE);
    $conn->begin_transaction();
    $upd = $conn->prepare(
        "UPDATE sme_meetings SET points_discussed = ?, details_saved_at = NOW()
         WHERE id = ? AND sme_user_id = ? AND meeting_status = 'done'
           AND details_saved_at IS NULL"
    );
    $upd->bind_param('sii', $json, $meetingId, $userId);
    if (!$upd->execute() || $upd->affected_rows < 1) {
        $upd->close();
        $conn->rollback();
        detailsResponse(409, ['success' => false, 'message' => 'Unable to save the details. Refresh and try again.']);
    }
    $upd->close();
    $attendees = smeMeetingSaveAttendees($conn, $meetingId, $attendees);
    $conn->commit();

    $meeting['points_discussed'] = $json;
    $meeting['details_saved_at'] = date('Y-m-d H:i:s');

    detailsResponse(200, [
        'success'   => true,
        'message'   => 'Meeting details saved to Records. The admin can see them too.',
        'recorded'  => true,
        'card_html' => smeMeetingRenderCard($meeting, $attendees),
    ]);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        @$conn->rollback();
    }
    error_log('Save meeting details error: ' . $e->getMessage());
    detailsResponse(500, ['success' => false, 'message' => 'Unable to save the details. Please try again.']);
}
