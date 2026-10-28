<?php
/*
| Lets the SME dashboard notice that the admin approved / sent back a MOM request,
| without a manual refresh. Returns freshly rendered cards for the asked meetings.
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function pollResponse(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    pollResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
$sessionRole = $_SESSION['role'] ?? '';
$roles = is_array($sessionRole)
    ? $sessionRole
    : (is_array($decoded = json_decode((string) $sessionRole, true)) ? $decoded : [$sessionRole]);
$roles = array_map(static fn($r) => strtolower(trim((string) $r)), $roles);
if (!$userId || !in_array('sme', $roles, true)) {
    pollResponse(401, ['success' => false, 'message' => 'Please log in as an SME.']);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/meeting_helpers.php';

$ids = [];
foreach (explode(',', (string) ($_GET['ids'] ?? '')) as $raw) {
    $id = filter_var(trim($raw), FILTER_VALIDATE_INT);
    if ($id && $id > 0) {
        $ids[$id] = $id;
    }
}
$ids = array_slice(array_values($ids), 0, 50);
if (!$ids) {
    pollResponse(200, ['success' => true, 'cards' => []]);
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
    $attendeesByMeeting = smeMeetingFetchAttendees($conn, $ids);
    $cards = [];
    foreach ($ids as $meetingId) {
        $stmt->bind_param('ii', $meetingId, $userId);
        $stmt->execute();
        $meeting = $stmt->get_result()->fetch_assoc();
        if ($meeting) {
            $cards[] = [
                'id'        => $meetingId,
                'card_html' => smeMeetingRenderCard($meeting, $attendeesByMeeting[$meetingId] ?? []),
            ];
        }
    }
    $stmt->close();
    pollResponse(200, ['success' => true, 'cards' => $cards]);
} catch (Throwable $e) {
    error_log('Meeting poll error: ' . $e->getMessage());
    pollResponse(500, ['success' => false, 'message' => 'Unable to check the meetings.']);
}
