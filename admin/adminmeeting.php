<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../sme/meeting_helpers.php';
require_once __DIR__ . '/../sme/fathom_helpers.php';
smeMeetingEnsureSchema($conn);
fathomEnsureSchema($conn);
/*
|--------------------------------------------------------------------------
| Admin Authentication
|--------------------------------------------------------------------------
*/
$role = strtolower(trim((string) ($_SESSION['role'] ?? '')));
if (empty($_SESSION['user_id']) || $role !== 'admin') {
    header('Location: ../index.php');
    exit;
}
/*
|--------------------------------------------------------------------------
| Helper: JSON Response
|--------------------------------------------------------------------------
*/
function sendMeetingResponse($success, $message, $extra = [])
{
    while (ob_get_level() > 0) {
        ob_end_clean(); // drop stray output so the browser always gets valid JSON
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit;
}
/*
|--------------------------------------------------------------------------
| CSRF Token
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['meeting_csrf_token'])) {
    $_SESSION['meeting_csrf_token'] = bin2hex(random_bytes(32));
}
/*
|--------------------------------------------------------------------------
| Poll: lets the open page notice a new MOM request without a manual refresh
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['mom_poll'])) {
    $pollRes = $conn->query(
        "SELECT SUM(mom_status = 'pending') AS pending_count, COUNT(*) AS total_count FROM sme_meetings"
    );
    $pollRow = $pollRes ? $pollRes->fetch_assoc() : [];
    sendMeetingResponse(true, '', [
        'pending' => (int) ($pollRow['pending_count'] ?? 0),
        'total'   => (int) ($pollRow['total_count'] ?? 0),
    ]);
}
/*
|--------------------------------------------------------------------------
| Update Meeting Status
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['meeting_csrf_token'], $csrfToken)
    ) {
        sendMeetingResponse(false, 'Invalid security token. Please refresh the page.');
    }
    $meetingId = filter_var(
        $_POST['meeting_id'] ?? null,
        FILTER_VALIDATE_INT
    );
    // MOM review: approving marks the meeting as over; rejecting sends it back to the SME.
    $momAction = $_POST['mom_action'] ?? '';
    if ($momAction !== '') {
        if (!$meetingId || $meetingId < 1 || !in_array($momAction, ['approve', 'reject'], true)) {
            sendMeetingResponse(false, 'Invalid request.');
        }
        $adminId = (int) $_SESSION['user_id'];
        if ($momAction === 'approve') {
            $stmt = $conn->prepare(
                "UPDATE sme_meetings
                 SET mom_status = 'approved', meeting_status = 'done',
                     mom_reviewed_at = NOW(), mom_reviewed_by = ?, mom_admin_note = NULL
                 WHERE id = ? AND mom_status = 'pending'"
            );
            $stmt->bind_param('ii', $adminId, $meetingId);
        } else {
            $note = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['note'] ?? ''))), 0, 500);
            if ($note === '') {
                sendMeetingResponse(false, 'Add a short reason so the SME knows what to fix.');
            }
            $stmt = $conn->prepare(
                "UPDATE sme_meetings
                 SET mom_status = 'rejected', meeting_status = 'not_done',
                     mom_reviewed_at = NOW(), mom_reviewed_by = ?, mom_admin_note = ?
                 WHERE id = ? AND mom_status = 'pending'"
            );
            $stmt->bind_param('isi', $adminId, $note, $meetingId);
        }
        if (!$stmt || !$stmt->execute() || $stmt->affected_rows < 1) {
            sendMeetingResponse(false, 'This MOM is no longer waiting for review. Refresh the page.');
        }
        $stmt->close();
        sendMeetingResponse(true, $momAction === 'approve' ? 'MOM approved. The meeting is now over.' : 'MOM sent back to the SME.', [
            'meeting_id' => $meetingId,
            'mom_status' => $momAction === 'approve' ? 'approved' : 'rejected',
            'meeting_status' => $momAction === 'approve' ? 'done' : 'not_done',
        ]);
    }
    $status = $_POST['meeting_status'] ?? '';
    if (!$meetingId || $meetingId < 1) {
        sendMeetingResponse(false, 'Invalid meeting ID.');
    }
    if (!in_array($status, ['done', 'not_done'], true)) {
        sendMeetingResponse(false, 'Invalid meeting status.');
    }
    $stmt = $conn->prepare(
        "UPDATE sme_meetings
         SET meeting_status = ?
         WHERE id = ?"
    );
    if (!$stmt) {
        sendMeetingResponse(false, 'Unable to prepare the database query.');
    }
    $stmt->bind_param('si', $status, $meetingId);
    if (!$stmt->execute()) {
        $stmt->close();
        sendMeetingResponse(false, 'Unable to update meeting status.');
    }
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    /*
     * affected_rows = 0 can mean either the status was already
     * the requested value or the meeting ID does not exist.
     */
    if ($affectedRows === 0) {
        $checkStmt = $conn->prepare(
            "SELECT id
             FROM sme_meetings
             WHERE id = ?
             LIMIT 1"
        );
        if (!$checkStmt) {
            sendMeetingResponse(false, 'Unable to verify the meeting.');
        }
        $checkStmt->bind_param('i', $meetingId);
        $checkStmt->execute();
        $checkStmt->store_result();
        $exists = $checkStmt->num_rows > 0;
        $checkStmt->close();
        if (!$exists) {
            sendMeetingResponse(false, 'Meeting not found.');
        }
    }
    sendMeetingResponse(true, 'Meeting status saved successfully.', [
        'meeting_id' => $meetingId,
        'meeting_status' => $status,
        'status_label' => $status === 'done' ? 'Done' : 'Not Done'
    ]);
}
/*
|--------------------------------------------------------------------------
| Fetch Meetings
|--------------------------------------------------------------------------
|
| users.fullname   = SME name
| companies.comp_name = Company name
|
*/
$sql = "
    SELECT
        m.id,
        m.sme_user_id,
        m.company_id,
        m.subject,
        m.meeting_date,
        m.meeting_time,
        m.meeting_link,
        m.meeting_status,
        m.created_at,
        m.points_discussed,
        m.details_saved_at,
        m.next_meeting_date,
        m.next_meeting_time,
        m.next_meeting_agenda,
        m.mom_status,
        m.mom_sent_at,
        m.mom_admin_note,
        u.fullname AS sme_name,
        c.comp_name AS company_name
    FROM sme_meetings AS m
    LEFT JOIN users AS u
        ON u.id = m.sme_user_id
    LEFT JOIN companies AS c
        ON c.id = m.company_id
    ORDER BY
        m.meeting_date DESC,
        m.meeting_time DESC,
        m.id DESC
";
$result = $conn->query($sql);
if (!$result) {
    die('Unable to load meetings. Please verify the database table and column names.');
}
$meetings = [];
while ($row = $result->fetch_assoc()) {
    $meetings[] = $row;
}
// Meetings waiting for the admin's approval come first.
usort($meetings, static function ($a, $b) {
    $pa = ($a['mom_status'] ?? '') === 'pending' ? 0 : 1;
    $pb = ($b['mom_status'] ?? '') === 'pending' ? 0 : 1;
    return $pa <=> $pb;
});
$momAttendees = smeMeetingFetchAttendees($conn, array_column($meetings, 'id'));
$momSummaries = [];
if ($meetings) {
    $ids = implode(',', array_map('intval', array_column($meetings, 'id')));
    $recRes = $conn->query(
        "SELECT meeting_id, summary FROM sme_meeting_recordings
         WHERE meeting_id IN ($ids) AND summary IS NOT NULL ORDER BY started_at DESC"
    );
    if ($recRes instanceof mysqli_result) {
        while ($rec = $recRes->fetch_assoc()) {
            $momSummaries[(int) $rec['meeting_id']] ??= fathomMarkdown((string) $rec['summary']);
        }
    }
}
function meetingEscape($value)
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}
function meetingDateFormat($date)
{
    if (!$date) {
        return '-';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date('d-m-Y', $timestamp) : '-';
}
function meetingTimeFormat($time)
{
    if (!$time) {
        return '-';
    }
    $timestamp = strtotime($time);
    return $timestamp ? date('h:i A', $timestamp) : '-';
}
?>
<link rel="stylesheet" type="text/css" href="../assets/css/adminmeeting.css">
<?php
$countDone = count(array_filter($meetings, fn($m) => ($m['meeting_status'] ?? '') === 'done'));
$countPending = count(array_filter($meetings, fn($m) => ($m['mom_status'] ?? '') === 'pending'));
$countNotDone = count($meetings) - $countDone;
$momLabels = [
    'none' => 'MOM not sent',
    'pending' => 'Waiting for approval',
    'approved' => 'MOM approved',
    'rejected' => 'Sent back',
];
?>
<section id="adminMeetingsSection">
    <header class="am-head">
        <h2>SME Meetings</h2>
        <p>Approve an SME's MOM request to mark the meeting as over. The SME then adds the attendees and points discussed, which you can open under View details.</p>
    </header>

    <div class="am-stats">
        <div class="am-stat"><span>Total</span><strong id="totalMeetings"><?= count($meetings) ?></strong></div>
        <div class="am-stat is-attention"><span>Waiting approval</span><strong id="momPendingMeetings"><?= $countPending ?></strong></div>
        <div class="am-stat is-good"><span>Done</span><strong id="doneMeetings"><?= $countDone ?></strong></div>
        <div class="am-stat"><span>Not done</span><strong id="notDoneMeetings"><?= $countNotDone ?></strong></div>
    </div>

    <p id="meetingMessage" role="status" aria-live="polite"></p>

    <?php if (empty($meetings)): ?>
        <div class="am-empty"><h3>No meetings yet</h3><p>Meetings added by SMEs will appear here.</p></div>
    <?php else: ?>
        <div class="am-filters" role="tablist" aria-label="Filter meetings">
            <button type="button" class="am-filter is-active" data-am-filter="all">All</button>
            <button type="button" class="am-filter" data-am-filter="pending">Needs approval</button>
            <button type="button" class="am-filter" data-am-filter="done">Done</button>
            <button type="button" class="am-filter" data-am-filter="not_done">Not done</button>
        </div>

        <div class="am-list" id="meetingsTableBody">
            <?php foreach ($meetings as $meeting): ?>
                <?php
                $meetingId = (int) $meeting['id'];
                $status = ($meeting['meeting_status'] ?? 'not_done') === 'done' ? 'done' : 'not_done';
                $momStatus = (string) ($meeting['mom_status'] ?? 'none');
                $canView = $momStatus === 'approved' || $status === 'done';
                $sentAt = !empty($meeting['mom_sent_at']) ? date('d M, h:i A', strtotime((string) $meeting['mom_sent_at'])) : '';
                ?>
                <article class="am-card<?= $momStatus === 'pending' ? ' needs-approval' : '' ?>"
                    id="meeting-row-<?= $meetingId ?>"
                    data-meeting-id="<?= $meetingId ?>"
                    data-status="<?= meetingEscape($status) ?>"
                    data-mom="<?= meetingEscape($momStatus) ?>">
                    <div class="am-card-main">
                        <div class="am-card-title">
                            <h3><?= meetingEscape($meeting['subject']) ?></h3>
                            <p>
                                <?= meetingEscape($meeting['company_name'] ?? 'Company not found') ?>
                                <span class="am-dot">&bull;</span>
                                <?= meetingEscape($meeting['sme_name'] ?? 'SME not found') ?>
                            </p>
                        </div>
                        <div class="am-card-chips">
                            <span class="am-chip"><?= meetingDateFormat($meeting['meeting_date']) ?></span>
                            <span class="am-chip"><?= meetingTimeFormat($meeting['meeting_time']) ?></span>
                            <strong class="meeting-status am-status am-status-<?= $status ?>" id="meeting-status-<?= $meetingId ?>"><?= $status === 'done' ? 'Done' : 'Not Done' ?></strong>
                            <?php if (!empty($meeting['meeting_link'])): ?>
                                <a class="am-link" href="<?= meetingEscape($meeting['meeting_link']) ?>" target="_blank" rel="noopener noreferrer">Join link</a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="am-mom">
                        <span class="mom-state mom-<?= meetingEscape($momStatus) ?>" id="mom-state-<?= $meetingId ?>"><?= meetingEscape($momLabels[$momStatus] ?? 'MOM not sent') ?></span>

                        <?php if ($momStatus === 'pending'): ?>
                            <div class="mom-review" data-meeting-id="<?= $meetingId ?>">
                                <p class="mom-review-text">
                                    <?= meetingEscape($meeting['sme_name'] ?? 'The SME') ?> asked you to approve this MOM<?= $sentAt !== '' ? ' on ' . meetingEscape($sentAt) : '' ?>.
                                    Approving marks the meeting as over.
                                </p>
                                <div class="mom-review-buttons">
                                    <button type="button" class="mom-approve" data-mom-action="approve">Approve</button>
                                    <button type="button" class="mom-reject" data-mom-action="reject-open">Send back</button>
                                </div>
                                <div class="mom-reject-box" hidden>
                                    <input type="text" class="mom-note" maxlength="500" placeholder="Tell the SME what to fix" aria-label="Reason for sending it back">
                                    <button type="button" class="mom-reject is-solid" data-mom-action="reject">Confirm</button>
                                    <button type="button" class="mom-cancel" data-mom-action="reject-cancel">Cancel</button>
                                </div>
                            </div>
                        <?php elseif ($momStatus === 'rejected' && !empty($meeting['mom_admin_note'])): ?>
                            <p class="mom-note-shown">Reason: <?= meetingEscape($meeting['mom_admin_note']) ?></p>
                        <?php endif; ?>

                        <?php if ($canView): ?>
                            <button type="button" class="mom-toggle" data-meeting-id="<?= $meetingId ?>">View details</button>
                        <?php endif; ?>
                    </div>

                    <?php if ($canView): ?>
                        <div class="mom-row" id="mom-row-<?= $meetingId ?>" hidden>
                            <?= smeMeetingRenderMom($meeting, $momAttendees[$meetingId] ?? [], $momSummaries[$meetingId] ?? '') ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="am-empty" id="amNoMatch" hidden><h3>No meetings in this view</h3></div>
    <?php endif; ?>

    <input type="hidden" id="meetingCsrfToken" value="<?= meetingEscape($_SESSION['meeting_csrf_token']) ?>">
</section>
<script src="../assets/js/adminmeeting.js" defer></script>
