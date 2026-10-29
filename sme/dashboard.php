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
    $sopSql = "
        SELECT
            cs.id,
            cs.company_id,
            c.comp_name,
            cs.sop_type,
            cs.sop_title,
            cs.sop_description,
            cs.sop_date
        FROM company_sops AS cs
        INNER JOIN companies AS c
            ON c.id = cs.company_id
        INNER JOIN sme_company_assignments AS sca
            ON sca.company_id = c.id
        WHERE sca.sme_user_id = ?
        ORDER BY
            FIELD(cs.sop_type, 'daily', 'weekly', 'monthly'),
            cs.sop_date ASC,
            cs.id ASC
    ";
    $sopStmt = $conn->prepare($sopSql);
    $sopStmt->bind_param('i', $userId);
    $sopStmt->execute();
    $sopResult = $sopStmt->get_result();
    while ($sop = $sopResult->fetch_assoc()) {
        $type = strtolower(trim((string) $sop['sop_type']));
        if (isset($smeSops[$type])) {
            $smeSops[$type][] = $sop;
        }
    }
    $sopStmt->close();
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
<link rel="stylesheet" type="text/css" href="../assets/css/smedashboard.css">
<link rel="stylesheet" type="text/css" href="../assets/css/smecal.css">
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
                    <p>Tick a SOP when it is done. Daily ticks refresh every day and weekly ticks every week.</p>
                </div>
                <a class="sme-sop-saved-link" href="sme.php?page=saved">
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                    Saved
                </a>
            </div>
            <p class="sme-sop-toast" id="sopToast" role="status" aria-live="polite"></p>
            <div class="sme-sops-layout">
                <?php
                $sopCategories = [
                    'daily' => 'Daily SOPs',
                    'weekly' => 'Weekly SOPs',
                    'monthly' => 'Monthly SOPs'
                ];
                ?>
                <?php foreach ($sopCategories as $type => $categoryTitle): ?>
                    <?php
                    $boxTotal = count($smeSops[$type] ?? []);
                    $boxDone = 0;
                    foreach ($smeSops[$type] ?? [] as $countSop) {
                        if (isset($sopDone[(int) $countSop['id']])) {
                            $boxDone++;
                        }
                    }
                    ?>
                    <div class="sme-sop-box" data-sop-type="<?= escapeSmeDashboard($type) ?>">
                        <div class="sme-sop-box-header">
                            <h3 class="sme-sop-category-title">
                                <?= htmlspecialchars($categoryTitle, ENT_QUOTES, 'UTF-8'); ?>
                            </h3>
                            <span class="sme-sop-count" title="Done / total <?= escapeSmeDashboard(smeSopPeriodLabel($type)) ?>">
                                <span class="sme-sop-done-count"><?= $boxDone ?></span>/<?= $boxTotal ?>
                            </span>
                        </div>
                        <div class="sme-sop-list">
                            <?php if (!empty($sopErrorMessage)): ?>
                                <p class="sme-sop-empty">
                                    <?= htmlspecialchars($sopErrorMessage, ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            <?php elseif (!empty($smeSops[$type])): ?>
                                <?php foreach ($smeSops[$type] as $sop): ?>
                                    <?php $isDone = isset($sopDone[(int) $sop['id']]); ?>
                                    <article class="sme-sop-item<?= $isDone ? ' is-done' : '' ?>">
                                        <label class="sme-sop-check">
                                            <input
                                                type="checkbox"
                                                class="sme-sop-checkbox"
                                                data-sop-id="<?= (int) $sop['id'] ?>"
                                                aria-label="Mark SOP as done <?= escapeSmeDashboard(smeSopPeriodLabel($type)) ?>"
                                                <?= $isDone ? 'checked' : '' ?>>
                                            <span class="sme-sop-tick" aria-hidden="true">
                                                <i class="fa-solid fa-check"></i>
                                            </span>
                                            <h4 class="sme-sop-title">
                                                <?= htmlspecialchars(
                                                    $sop['sop_title'] ?? 'Untitled SOP',
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>
                                            </h4>
                                        </label>
                                        <p class="sme-sop-company">
                                            <?= htmlspecialchars(
                                                $sop['comp_name'] ?? 'Unknown Company',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?>
                                        </p>
                                        <?php if (!empty($sop['sop_description'])): ?>
                                            <p class="sme-sop-description">
                                                <?= nl2br(htmlspecialchars(
                                                    $sop['sop_description'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                )); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if (!empty($sop['sop_date'])): ?>
                                            <p class="sme-sop-date">
                                                <span>Date:</span>
                                                <?= htmlspecialchars(
                                                    date('d M Y', strtotime($sop['sop_date'])),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>
                                            </p>
                                        <?php endif; ?>
                                        <p class="sme-sop-saved-note" <?= $isDone ? '' : 'hidden' ?>>
                                            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                            Saved <?= escapeSmeDashboard(smeSopPeriodLabel($type)) ?><span class="sme-sop-saved-time"><?php
                                                if ($isDone) {
                                                    echo ' · ' . escapeSmeDashboard(date('h:i A', strtotime((string) $sopDone[(int) $sop['id']])));
                                                }
                                            ?></span>
                                        </p>
                                    </article>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="sme-sop-empty">
                                    No <?= strtolower($categoryTitle); ?> assigned yet.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
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

<script src="../assets/js/smedashboard.js" defer></script>
<script src="../assets/js/smecal.js" defer></script>