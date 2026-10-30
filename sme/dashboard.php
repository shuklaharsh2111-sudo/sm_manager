<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// The SME wrapper (sme.php) sends no-cache headers before rendering the page.
// Set these headers here only if dashboard.php is opened directly, otherwise
// they trigger 'headers already sent' warnings when this file is included.
$isDirectDashboardRequest = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);
if ($isDirectDashboardRequest) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/meeting_helpers.php';
require_once __DIR__ . '/sop_helpers.php';
if (empty($_SESSION['meeting_csrf_token'])) {
    $_SESSION['meeting_csrf_token'] = bin2hex(
        random_bytes(32)
    );
}
// Validate SME session and role.
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
    header('Location: ../index.php');
    exit;
}
function escapeSmeDashboard($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}
function getSmeDashboardLogo(?string $logo): string
{
    $logo = trim((string) $logo);
    if ($logo === '') {
        return '';
    }
    // External logo URL.
    if (preg_match('~^https?://~i', $logo)) {
        return $logo;
    }
    // Root-relative URL.
    if (str_starts_with($logo, '/')) {
        return $logo;
    }
    // Existing relative paths.
    if (str_starts_with($logo, '../')) {
        return $logo;
    }
    // Logo stored inside the project's uploads folder.
    if (str_starts_with($logo, 'uploads/')) {
        return '../' . $logo;
    }
    return '../uploads/' . ltrim($logo, '/');
}
$companies = [];
$errorMessage = '';
$sopErrorMessage = '';
$smeSops = [
    'daily' => [],
    'weekly' => [],
    'monthly' => []
];
try {
    $smeSops = smeSopFetchAssigned($conn, (int) $userId);
} catch (Throwable $exception) {
    error_log(
        'SME SOP query error: ' . $exception->getMessage()
    );
    $sopErrorMessage =
        'Unable to load your assigned SOPs. Please try again later.';
}
// SOPs ticked in the current day / week / month (refreshes automatically).
$sopDone = [];
try {
    smeSopEnsureTable($conn);
    $sopDone = smeSopFetchCurrentDone($conn, $userId);
} catch (Throwable $exception) {
    error_log('SME SOP tick load error: ' . $exception->getMessage());
}
$displayName = trim(
    (string) (
        $_SESSION['fullname']
        ?? $_SESSION['name']
        ?? 'SME'
    )
);
try {
    $sql = "
        SELECT
            c.id,
            c.comp_name,
            c.comp_logo
        FROM sme_company_assignments AS sca
        INNER JOIN companies AS c
            ON c.id = sca.company_id
        WHERE sca.sme_user_id = ?
        ORDER BY c.comp_name ASC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException(
            'Unable to prepare company query.'
        );
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($company = $result->fetch_assoc()) {
        $companies[] = $company;
    }
    $stmt->close();
} catch (Throwable $exception) {
    error_log(
        'SME dashboard error: ' . $exception->getMessage()
    );
    $errorMessage =
        'Unable to load assigned companies. Please try again later.';
}
$upcomingMeetings = [];
$recordedMeetingCount = 0;
$meetingAttendees = [];
$meetingPeople = [];
$meetingErrorMessage = '';
try {
    // Adds the attendee table + notes columns the first time this runs.
    $meetingExtras = smeMeetingEnsureSchema($conn);
    $extraColumns = $meetingExtras
        ? ',
        m.points_discussed,
        m.next_meeting_date,
        m.next_meeting_time,
        m.next_meeting_agenda,
        m.next_meeting_attendees' . smeMeetingMomColumns()
        : '';
    $meetingSql = "
    SELECT
        m.id,
        m.subject,
        m.meeting_date,
        m.meeting_time,
        m.meeting_link,
        m.meeting_status,
        c.comp_name" . $extraColumns . "
    FROM sme_meetings AS m
    INNER JOIN companies AS c
        ON c.id = m.company_id
    INNER JOIN sme_company_assignments AS sca
        ON sca.company_id = c.id
       AND sca.sme_user_id = m.sme_user_id
    WHERE m.sme_user_id = ?
    ORDER BY
        m.meeting_date ASC,
        m.meeting_time ASC
";
    $meetingStmt = $conn->prepare($meetingSql);
    if (!$meetingStmt) {
        throw new RuntimeException('Unable to prepare meetings query.');
    }
    $meetingStmt->bind_param('i', $userId);
    $meetingStmt->execute();
    $meetingResult = $meetingStmt->get_result();
    while ($meeting = $meetingResult->fetch_assoc()) {
        // Over + details saved = it now lives on the Records page.
        if ($meetingExtras && smeMeetingIsRecorded($meeting)) {
            $recordedMeetingCount++;
            continue;
        }
        $upcomingMeetings[] = $meeting;
    }
    $meetingStmt->close();

    if ($meetingExtras && $upcomingMeetings) {
        $meetingAttendees = smeMeetingFetchAttendees(
            $conn,
            array_column($upcomingMeetings, 'id')
        );
    }

    // Names suggested while typing an attendee (picks up their profile photo).
    $peopleResult = $conn->query(
        "SELECT DISTINCT fullname FROM users
         WHERE fullname IS NOT NULL AND TRIM(fullname) <> ''
         ORDER BY fullname ASC LIMIT 500"
    );
    if ($peopleResult instanceof mysqli_result) {
        while ($person = $peopleResult->fetch_assoc()) {
            $meetingPeople[] = $person['fullname'];
        }
        $peopleResult->free();
    }
} catch (Throwable $exception) {
    error_log(
        'SME meetings query error: ' . $exception->getMessage()
    );
    $meetingErrorMessage =
        'Unable to load upcoming meetings.';
}


?>
<?php
// ?v=<file time> makes the browser fetch the new file after every edit (no stale cache).
$smeAssetVersion = static function (string $relative): string {
    $file = __DIR__ . '/../assets/' . $relative;
    return '../assets/' . $relative . '?v=' . (is_file($file) ? filemtime($file) : time());
};
?>
<link rel="stylesheet" type="text/css" href="<?= escapeSmeDashboard($smeAssetVersion('css/smedashboard.css')) ?>">
<link rel="stylesheet" type="text/css" href="<?= escapeSmeDashboard($smeAssetVersion('css/smecal.css')) ?>">
<link rel="stylesheet" type="text/css" href="<?= escapeSmeDashboard($smeAssetVersion('css/smesop.css')) ?>">
<link rel="stylesheet" type="text/css" href="<?= escapeSmeDashboard($smeAssetVersion('css/smemeetings.css')) ?>">
<section class="sme-dashboard" id="smeDashboard">
    <section
        class="sme-companies-section"
        aria-labelledby="workingWithTitle">
        <div class="sme-section-heading">
            <div>
                <h2 id="workingWithTitle">
                    You're Working With
                </h2>
                <p>Your assigned companies</p>
            </div>
            <span class="sme-company-count">
                <?= count($companies) ?>
                <?= count($companies) === 1
                    ? 'Company'
                    : 'Companies' ?>
            </span>
        </div>
        <?php if ($errorMessage !== ''): ?>
            <p class="sme-message sme-error" role="alert">
                <?= escapeSmeDashboard($errorMessage) ?>
            </p>
        <?php elseif (empty($companies)): ?>
            <div class="sme-empty-state">
                <h3>No Companies Assigned</h3>
                <p>
                    Companies assigned to your account will
                    appear here. Please contact your
                    administrator if you need an assignment.
                </p>
            </div>
        <?php else: ?>
            <div
                class="sme-companies-grid"
                id="smeCompaniesGrid">
                <?php foreach ($companies as $company): ?>
                    <?php
                    $companyName = trim(
                        (string) ($company['comp_name'] ?? '')
                    );
                    if ($companyName === '') {
                        $companyName = 'Unnamed Company';
                    }
                    $logoPath = getSmeDashboardLogo(
                        $company['comp_logo'] ?? ''
                    );
                    $initial = function_exists('mb_substr')
                        ? mb_substr(
                            $companyName,
                            0,
                            1,
                            'UTF-8'
                        )
                        : substr($companyName, 0, 1);
                    ?>
                        <article class="sme-company-card" data-company-id="<?= (int) $company['id'] ?>" data-company-name="<?= escapeSmeDashboard($companyName) ?>" style="cursor:pointer;">
                        <div class="sme-company-logo-circle">
                            <?php if ($logoPath !== ''): ?>
                                <img
                                    class="sme-company-logo"
                                    src="<?= escapeSmeDashboard($logoPath) ?>"
                                    alt="<?= escapeSmeDashboard($companyName) ?> logo"
                                    loading="lazy">
                                <span
                                    class="sme-company-logo-fallback"
                                    hidden
                                    aria-hidden="true"><?= escapeSmeDashboard($initial) ?></span>
                            <?php else: ?>
                                <span
                                    class="sme-company-logo-fallback"
                                    aria-hidden="true"><?= escapeSmeDashboard($initial) ?></span>
                            <?php endif; ?>
                        </div>
                        <h3 class="sme-company-name">
                            <?= escapeSmeDashboard($companyName) ?>
                        </h3>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <div class="sme-sops-meetings-layout">
        <!-- Assigned SOPs Section -->
        <section
            class="sme-sops-section"
            id="smeSopsSection"
            data-endpoint="../sme/sop_api.php"
            data-csrf="<?= escapeSmeDashboard($_SESSION['meeting_csrf_token']) ?>">
            <div class="sme-sops-heading sme-sops-heading-row">
                <div>
                    <h2>Your SOPs</h2>
                    <p>SOPs assigned to you by the admin. Tick one when it is done. Daily ticks refresh every day, weekly every week, monthly every month.</p>
                </div>
                <a class="sme-sop-saved-link" href="sme.php?page=saved">
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                    Saved
                </a>
            </div>
            <p class="sme-sop-toast" id="sopToast" role="status" aria-live="polite"></p>
            <div class="sme-sops-layout" id="smeSopsTabs">
                <?php if ($sopErrorMessage !== ''): ?>
                    <p class="sme-sop-empty"><?= escapeSmeDashboard($sopErrorMessage) ?></p>
                <?php else: ?>
                    <?= smeSopRenderTabs($smeSops, $sopDone, 'daily') ?>
                <?php endif; ?>
            </div>
        </section>
        <!-- Upcoming Meetings Section -->
        <section class="sme-meetings-section" id="smeMeetingsSection" data-endpoint="../sme/add_meeting.php" data-mom-endpoint="../sme/send_mom.php" data-details-endpoint="../sme/save_details.php" data-poll-endpoint="../sme/meeting_poll.php" data-server-now="<?= time() ?>">
            <div class="sme-meetings-heading">
                <div>
                    <h2>Upcoming Meetings</h2>
                    <p>Manage your scheduled company meetings. Finished meetings are saved in
                        <a class="sme-records-link" href="sme.php?page=recordings" id="meetingRecordsLink">Records</a>.</p>
                </div>
                <button
                    type="button"
                    class="sme-add-meeting-btn"
                    id="openMeetingForm">
                    <span aria-hidden="true">+</span>
                    Add Meeting
                </button>
            </div>
            <p
                class="sme-meeting-message"
                id="meetingMessage"
                role="status"
                aria-live="polite"></p>
            <!-- Add Meeting Form -->
            <form
                id="addMeetingForm"
                class="sme-meeting-form"
                hidden>
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapeSmeDashboard(
                                $_SESSION['meeting_csrf_token']
                            ) ?>">
                <div class="sme-meeting-form-grid">
                    <div class="sme-meeting-field">
                        <label for="meetingSubject">Subject</label>
                        <input
                            type="text"
                            id="meetingSubject"
                            name="subject"
                            maxlength="255"
                            placeholder="Enter meeting subject"
                            required>
                    </div>
                    <div class="sme-meeting-field">
                        <label for="meetingCompany">Company</label>
                        <select
                            id="meetingCompany"
                            name="company_id"
                            required>
                            <option value="">Select Company</option>
                            <?php foreach ($companies as $company): ?>
                                <option
                                    value="<?= (int) $company['id'] ?>">
                                    <?= escapeSmeDashboard(
                                        $company['comp_name']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sme-meeting-field">
                        <label for="meetingDate">Date</label>
                        <input
                            type="date"
                            id="meetingDate"
                            name="meeting_date"
                            min="<?= date('Y-m-d') ?>"
                            required>
                    </div>
                    <div class="sme-meeting-field">
                        <label for="meetingTime">Time</label>
                        <input
                            type="time"
                            id="meetingTime"
                            name="meeting_time"
                            required>
                    </div>
                    <div class="sme-meeting-field">
                        <label for="meetingLink">Meeting Link <small>(optional)</small></label>
                        <input
                            type="url"
                            id="meetingLink"
                            name="meeting_link"
                            maxlength="2048"
                            placeholder="Leave blank to create a link automatically">
                    </div>
                    <div class="sme-mt-rows sme-mt-numbered" id="stepRows"></div>
                </div>

                <!-- Next meeting -->
                <div class="sme-mt-section">
                    <div class="sme-mt-section-head">
                        <h4>Next Meeting</h4>
                    </div>
                    <div class="sme-mt-next-grid">
                        <input type="date" name="next_meeting_date" min="<?= date('Y-m-d') ?>" aria-label="Next meeting date">
                        <input type="time" name="next_meeting_time" aria-label="Next meeting time">
                        <input type="text" name="next_meeting_agenda" maxlength="500" placeholder="Agenda" aria-label="Next meeting agenda">
                        <input type="text" name="next_meeting_attendees" maxlength="500" placeholder="Attendees" aria-label="Next meeting attendees">
                    </div>
                </div>

                <div class="sme-meeting-form-actions">
                    <button
                        type="button"
                        class="sme-cancel-meeting-btn"
                        id="cancelMeetingForm">
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="sme-save-meeting-btn"
                        id="saveMeetingBtn">
                        Create Link
                    </button>
                </div>

                <datalist id="meetingPeople">
                    <?php foreach ($meetingPeople as $personName): ?>
                        <option value="<?= escapeSmeDashboard($personName) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </form>
            <!-- Upcoming Meetings List -->
            <div class="sme-meetings-list" id="smeMeetingsList">
                <?php if ($meetingErrorMessage !== ''): ?>
                    <p class="sme-meeting-empty">
                        <?= escapeSmeDashboard($meetingErrorMessage) ?>
                    </p>
                <?php elseif (empty($upcomingMeetings)): ?>
                    <div class="sme-meeting-empty" id="noMeetingsMessage">
                        <h3>No Upcoming Meetings</h3>
                        <p>Your scheduled meetings will appear here.<?php if ($recordedMeetingCount > 0): ?>
                            Finished meetings are in <a class="sme-records-link" href="sme.php?page=recordings">Records</a>.<?php endif; ?></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcomingMeetings as $meeting): ?>
                        <?= smeMeetingRenderCard(
                            $meeting,
                            $meetingAttendees[(int) $meeting['id']] ?? []
                        ) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</section>
<?php require_once __DIR__ . '/smecal.php'; ?>
<?php require_once __DIR__ . '/taskmaster.php'; ?>
<?php require_once __DIR__ . '/bh.php'; ?>

<script src="<?= escapeSmeDashboard($smeAssetVersion('js/smedashboard.js')) ?>" defer></script>
<script src="<?= escapeSmeDashboard($smeAssetVersion('js/smecal.js')) ?>" defer></script>
<script src="<?= escapeSmeDashboard($smeAssetVersion('js/smesop.js')) ?>" defer></script>