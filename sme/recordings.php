<?php
/*
|--------------------------------------------------------------------------
| SME RECORDINGS & SUMMARIES
|--------------------------------------------------------------------------
| Included by sme/sme.php (?page=recordings). Shows the logged-in SME's
| Fathom recordings, AI summaries, action items and (on demand) transcripts.
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/fathom_helpers.php';
require_once __DIR__ . '/meeting_helpers.php';

if (empty($_SESSION['meeting_csrf_token'])) {
    $_SESSION['meeting_csrf_token'] = bin2hex(random_bytes(32));
}

$recUserId = (int) ($_SESSION['user_id'] ?? 0);
$recRows = [];
$recConfigured = fathomApiKey() !== '';
$recError = '';

if ($recUserId > 0 && fathomEnsureSchema($conn)) {
    fathomAutoSync($conn); // refreshes finished meetings at most every 5 minutes
    $stmt = $conn->prepare(
        'SELECT r.*, m.subject AS meeting_subject, c.comp_name
         FROM sme_meeting_recordings AS r
         LEFT JOIN sme_meetings AS m ON m.id = r.meeting_id
         LEFT JOIN companies AS c ON c.id = m.company_id
         WHERE r.sme_user_id = ?
         ORDER BY r.started_at DESC, r.id DESC
         LIMIT 100'
    );
    if ($stmt) {
        $stmt->bind_param('i', $recUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $recRows[] = $row;
        }
        $stmt->close();
    } else {
        $recError = 'Unable to load recordings.';
    }
} else {
    $recError = 'Recording tables are not ready. Run fathom_migration.sql.';
}

/* Meeting records: finished meetings whose attendees + points discussed were saved. */
$meetingRecords = [];
$meetingRecordAttendees = [];
$recordingByMeeting = [];
foreach ($recRows as $recRow) {
    $mid = (int) ($recRow['meeting_id'] ?? 0);
    if ($mid > 0 && !isset($recordingByMeeting[$mid])) {
        $recordingByMeeting[$mid] = $recRow;
    }
}
if ($recUserId > 0) {
    try {
        if (smeMeetingEnsureSchema($conn)) {
            $mrStmt = $conn->prepare(
                "SELECT m.id, m.subject, m.meeting_date, m.meeting_time, m.meeting_status, m.mom_status,
                        m.details_saved_at, m.points_discussed, m.next_meeting_date, m.next_meeting_time,
                        m.next_meeting_agenda, c.comp_name
                 FROM sme_meetings AS m
                 LEFT JOIN companies AS c ON c.id = m.company_id
                 WHERE m.sme_user_id = ? AND m.meeting_status = 'done' AND m.details_saved_at IS NOT NULL
                 ORDER BY m.meeting_date DESC, m.meeting_time DESC, m.id DESC
                 LIMIT 100"
            );
            if ($mrStmt) {
                $mrStmt->bind_param('i', $recUserId);
                $mrStmt->execute();
                $mrRes = $mrStmt->get_result();
                while ($mrRow = $mrRes->fetch_assoc()) {
                    $meetingRecords[] = $mrRow;
                }
                $mrStmt->close();
            }
            if ($meetingRecords) {
                $meetingRecordAttendees = smeMeetingFetchAttendees($conn, array_column($meetingRecords, 'id'));
            }
        }
    } catch (Throwable $mrError) {
        error_log('Meeting records error: ' . $mrError->getMessage());
    }
}
?>
<link rel="stylesheet" href="../assets/css/smerecordings.css">
<section class="rec-page" id="smeRecordings"
    data-endpoint="../sme/fathom_api.php"
    data-csrf="<?= fathomEsc($_SESSION['meeting_csrf_token']) ?>">
    <div class="rec-page-head">
        <div>
            <h2>Records</h2>
            <p>Your finished meetings, recordings and AI summaries in one place.</p>
        </div>
    </div>

    <div class="rec-toolbar">
        <div class="rec-tabs" role="tablist">
            <button type="button" class="rec-tab is-active" role="tab" data-rec-tab="meetings" aria-selected="true">
                Meeting Records <span class="rec-count"><?= count($meetingRecords) ?></span>
            </button>
            <button type="button" class="rec-tab" role="tab" data-rec-tab="recordings" aria-selected="false">
                Recordings &amp; Summaries <span class="rec-count"><?= count($recRows) ?></span>
            </button>
        </div>
        <input type="search" class="rec-search" id="recSearch" placeholder="Search meetings, people, points..." aria-label="Search meeting records">
    </div>

    <div class="rec-panel" data-rec-panel="meetings">
        <?php if (!$meetingRecords): ?>
            <div class="rec-empty">
                <h3>No meeting records yet</h3>
                <p>When a meeting is over, add its attendees and points discussed on the dashboard. It is saved here.</p>
            </div>
        <?php else: ?>
            <div class="rec-list" id="meetingRecordList">
                <?php foreach ($meetingRecords as $mr): ?>
                    <?= smeMeetingRenderRecordCard(
                        $mr,
                        $meetingRecordAttendees[(int) $mr['id']] ?? [],
                        $recordingByMeeting[(int) $mr['id']] ?? []
                    ) ?>
                <?php endforeach; ?>
            </div>
            <div class="rec-empty" id="recNoMatch" hidden><h3>No records match your search</h3></div>
        <?php endif; ?>
    </div>

    <div class="rec-panel" data-rec-panel="recordings" hidden>
        <?php if ($recConfigured): ?>
            <div class="rec-panel-actions"><button type="button" class="rec-btn rec-btn-primary" id="recSyncBtn">Sync now</button></div>
        <?php endif; ?>
        <p class="rec-message" id="recMessage" role="status" aria-live="polite"></p>
        <?php if ($recError !== ''): ?>
            <div class="rec-empty"><h3><?= fathomEsc($recError) ?></h3></div>
        <?php elseif (!$recRows): ?>
            <div class="rec-empty">
                <h3>No recordings yet</h3>
                <p>
                    <?= $recConfigured
                        ? 'After a meeting is held, its recording and summary appear here automatically.'
                        : 'Meeting recording is not set up on the server yet. Ask your admin to add the API key.' ?>
                </p>
            </div>
        <?php else: ?>
            <div class="rec-list" id="recList">
                <?php foreach ($recRows as $row): ?>
                    <?= fathomRenderCard($row) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<script src="../assets/js/smerecordings.js" defer></script>
