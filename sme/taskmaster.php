<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
/* ==========================================
   TASK BOARD - HELPER FUNCTIONS
========================================== */
$tbEscape = static function ($value): string {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
};
$tbRespond = static function (
    int $status,
    array $data
): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
};
/* ==========================================
   TASK BOARD - SESSION / USER VALIDATION
========================================== */
$taskBoardUserId = (int) ($_SESSION['user_id'] ?? 0);
if ($taskBoardUserId <= 0) {
    $isTaskBoardAjax = isset($_GET['taskboard_action']);
    if ($isTaskBoardAjax) {
        $tbRespond(401, [
            'success' => false,
            'message' => 'Session expired. Please log in again.'
        ]);
    }
    // Page access without a valid login.
    http_response_code(401);
    exit('Please log in to access the Task Board.');
}
/* ==========================================
   TASK BOARD - CSRF TOKEN
========================================== */
if (empty($_SESSION['taskboard_csrf'])) {
    $_SESSION['taskboard_csrf'] = bin2hex(random_bytes(32));
}
$taskBoardCsrf = $_SESSION['taskboard_csrf'];
/* ==========================================
   TASK BOARD - CURRENT USER NAME
========================================== */
$taskBoardUserName = trim(
    (string) (
        $_SESSION['fullname']
        ?? $_SESSION['user']['fullname']
        ?? $_SESSION['name']
        ?? ''
    )
);
if ($taskBoardUserName === '') {
    $nameStmt = $conn->prepare(
        "SELECT fullname
         FROM users
         WHERE id = ?
         LIMIT 1"
    );
    if ($nameStmt) {
        $nameStmt->bind_param('i', $taskBoardUserId);
        $nameStmt->execute();
        $nameStmt->bind_result($dbName);
        if ($nameStmt->fetch()) {
            $taskBoardUserName = trim((string) $dbName);
        }
        $nameStmt->close();
    }
}
if ($taskBoardUserName === '') {
    $taskBoardUserName = 'User #' . $taskBoardUserId;
}
/* ==========================================
   TASK BOARD - AJAX CREATE TASK HANDLER
========================================== */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['taskboard_action'] ?? '') === 'create'
) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        $tbRespond(405, [
            'success' => false,
            'message' => 'Invalid request method.'
        ]);
    }
    /* Verify CSRF token */
    $sessionToken = $_SESSION['taskboard_csrf'] ?? '';
    $postedToken = $_POST['csrf_token'] ?? '';
    if (
        !is_string($postedToken)
        || !is_string($sessionToken)
        || $sessionToken === ''
        || !hash_equals($sessionToken, $postedToken)
    ) {
        $tbRespond(403, [
            'success' => false,
            'message' => 'Security token expired. Refresh the page and try again.'
        ]);
    }
    /* Read and validate request data */
    $companyId = filter_var(
        $_POST['company_id'] ?? null,
        FILTER_VALIDATE_INT
    );
    $titleInput = $_POST['task_title'] ?? '';
    $typeInput = $_POST['task_type'] ?? '';
    $objectiveInput = $_POST['objective'] ?? '';
    if (
        !is_string($titleInput)
        || !is_string($typeInput)
        || !is_string($objectiveInput)
    ) {
        $tbRespond(422, [
            'success' => false,
            'message' => 'Invalid task details.'
        ]);
    }
    $title = trim($titleInput);
    $type = trim($typeInput);
    $objective = trim($objectiveInput);
    $isPriority = (
        isset($_POST['is_priority'])
        && $_POST['is_priority'] === '1'
    ) ? 1 : 0;
    $allowedTypes = [
        'Strategy/Pitch Deck',
        'Moodboard',
        'Shoots',
        'CP',
        'Content Writing',
        'Influencer'
    ];
    $allowedObjectives = [
        'Brand Awareness',
        'Lead Generation',
        'Positioning',
        'Others'
    ];
    if ($companyId === false || $companyId === null || $companyId < 1) {
        $tbRespond(422, [
            'success' => false,
            'message' => 'Please select a valid company.'
        ]);
    }
    if (
        $title === ''
        || mb_strlen($title, 'UTF-8') > 255
    ) {
        $tbRespond(422, [
            'success' => false,
            'message' => 'Task title is required and must not exceed 255 characters.'
        ]);
    }
    if (!in_array($type, $allowedTypes, true)) {
        $tbRespond(422, [
            'success' => false,
            'message' => 'Please select a valid task type.'
        ]);
    }
    if (!in_array($objective, $allowedObjectives, true)) {
        $tbRespond(422, [
            'success' => false,
            'message' => 'Please select a valid objective.'
        ]);
    }
    /* ==========================================
       VERIFY COMPANY ASSIGNMENT
       IMPORTANT: Check before inserting a task.
    ========================================== */
    $assignmentStmt = $conn->prepare(
        "SELECT 1
         FROM sme_company_assignments
         WHERE sme_user_id = ?
           AND company_id = ?
         LIMIT 1"
    );
    if (!$assignmentStmt) {
        error_log('Task Board assignment prepare failed: ' . $conn->error);
        $tbRespond(500, [
            'success' => false,
            'message' => 'Unable to verify company assignment.'
        ]);
    }
    $assignmentStmt->bind_param(
        'ii',
        $taskBoardUserId,
        $companyId
    );
    if (!$assignmentStmt->execute()) {
        error_log(
            'Task Board assignment query failed: '
                . $assignmentStmt->error
        );
        $assignmentStmt->close();
        $tbRespond(500, [
            'success' => false,
            'message' => 'Unable to verify company assignment.'
        ]);
    }
    $assignmentStmt->store_result();
    $isAssigned = $assignmentStmt->num_rows > 0;
    $assignmentStmt->close();
    if (!$isAssigned) {
        $tbRespond(403, [
            'success' => false,
            'message' => 'You are not assigned to this company.'
        ]);
    }
    /* ==========================================
       VERIFY COMPANY DETAILS
    ========================================== */
    $companyStmt = $conn->prepare(
        "SELECT id
         FROM companies
         WHERE id = ?
         LIMIT 1"
    );
    if (!$companyStmt) {
        error_log('Task Board company prepare failed: ' . $conn->error);
        $tbRespond(500, [
            'success' => false,
            'message' => 'Unable to verify company.'
        ]);
    }
    $companyStmt->bind_param('i', $companyId);
    if (!$companyStmt->execute()) {
        error_log(
            'Task Board company query failed: '
                . $companyStmt->error
        );
        $companyStmt->close();
        $tbRespond(500, [
            'success' => false,
            'message' => 'Unable to verify company.'
        ]);
    }
    $companyStmt->store_result();
    $companyExists = $companyStmt->num_rows > 0;
    $companyStmt->close();
    if (!$companyExists) {
        $tbRespond(422, [
            'success' => false,
            'message' => 'Selected company was not found.'
        ]);
    }
    /* ==========================================
       VERIFY LOGGED-IN DATABASE USER
    ========================================== */
    $userStmt = $conn->prepare(
        "SELECT fullname
         FROM users
         WHERE id = ?
         LIMIT 1"
    );
    if (!$userStmt) {
        error_log('Task Board user prepare failed: ' . $conn->error);
        $tbRespond(500, [
            'success' => false,
            'message' => 'Unable to verify logged-in user.'
        ]);
    }
    $userStmt->bind_param('i', $taskBoardUserId);
    if (!$userStmt->execute()) {
        error_log(
            'Task Board user query failed: '
                . $userStmt->error
        );
        $userStmt->close();
        $tbRespond(500, [
            'success' => false,
            'message' => 'Unable to verify logged-in user.'
        ]);
    }
    $userStmt->bind_result($creatorName);
    $userFound = $userStmt->fetch();
    $userStmt->close();
    if (!$userFound) {
        $tbRespond(401, [
            'success' => false,
            'message' => 'Logged-in user could not be verified.'
        ]);
    }
    $creatorName = trim((string) $creatorName);
    if ($creatorName === '') {
        $creatorName = 'User #' . $taskBoardUserId;
    }
    /* ==========================================
       INSERT TASK
    ========================================== */
    $insertStmt = $conn->prepare(
        "INSERT INTO task_board_tasks
        (
            company_id,
            task_title,
            task_type,
            objective,
            is_priority,
            created_by,
            created_by_name
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$insertStmt) {
        error_log('Task Board insert prepare failed: ' . $conn->error);
        $tbRespond(500, [
            'success' => false,
            'message' => 'Unable to prepare task.'
        ]);
    }
    $insertStmt->bind_param(
        'isssiis',
        $companyId,
        $title,
        $type,
        $objective,
        $isPriority,
        $taskBoardUserId,
        $creatorName
    );
    if (!$insertStmt->execute()) {
        error_log(
            'Task Board insert failed: '
                . $insertStmt->error
        );
        $insertStmt->close();
        $tbRespond(500, [
            'success' => false,
            'message' => 'Task could not be saved.'
        ]);
    }
    $taskId = (int) $conn->insert_id;
    $insertStmt->close();
    $tbRespond(201, [
        'success' => true,
        'message' => 'Task created successfully!',
        'task' => [
            'id' => $taskId,
            'company_id' => (int) $companyId,
            'task_title' => $title,
            'task_type' => $type,
            'objective' => $objective,
            'is_priority' => $isPriority,
            'created_by' => $taskBoardUserId,
            'created_by_name' => $creatorName
        ]
    ]);
}
/* ==========================================
   TASK BOARD - PAGE DATA
   LOAD ONLY ASSIGNED COMPANIES
========================================== */
$taskBoardCompanies = [];
$taskBoardError = '';
$companiesStmt = $conn->prepare(
    "SELECT DISTINCT
        c.id,
        c.comp_name,
        c.comp_logo
     FROM companies AS c
     INNER JOIN sme_company_assignments AS sca
        ON sca.company_id = c.id
     WHERE sca.sme_user_id = ?
     ORDER BY c.comp_name ASC"
);
if (!$companiesStmt) {
    error_log(
        'Task Board companies prepare failed: '
            . $conn->error
    );
    $taskBoardError = 'Unable to load assigned companies.';
} else {
    $companiesStmt->bind_param(
        'i',
        $taskBoardUserId
    );
    if (!$companiesStmt->execute()) {
        error_log(
            'Task Board companies query failed: '
                . $companiesStmt->error
        );
        $taskBoardError = 'Unable to load assigned companies.';
    } else {
        $companiesResult = $companiesStmt->get_result();
        while ($company = $companiesResult->fetch_assoc()) {
            $taskBoardCompanies[] = $company;
        }
        $companiesResult->free();
    }
    $companiesStmt->close();
}
/* ==========================================
   TASK BOARD - ESCAPE HELPER
========================================== */
$tbEscape = static function ($value): string {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
};
/* ==========================================
   TASK BOARD - LOAD CREATED TASKS
========================================== */
$taskBoardTasks = [];
$tasksStmt = $conn->prepare(
    "SELECT
        t.id,
        t.company_id,
        t.task_title,
        t.task_type,
        t.objective,
        t.is_priority,
        t.created_by,
        t.created_by_name,
        t.created_at,
        c.comp_name,
        c.comp_logo
    FROM task_board_tasks AS t
    INNER JOIN companies AS c
        ON c.id = t.company_id
    INNER JOIN sme_company_assignments AS sca
        ON sca.company_id = t.company_id
       AND sca.sme_user_id = ?
    ORDER BY
        t.is_priority DESC,
        t.created_at DESC,
        t.id DESC"
);
if ($tasksStmt) {
    $tasksStmt->bind_param('i', $taskBoardUserId);
    if ($tasksStmt->execute()) {
        $tasksResult = $tasksStmt->get_result();
        while ($task = $tasksResult->fetch_assoc()) {
            $taskBoardTasks[] = $task;
        }
        $tasksResult->free();
    } else {
        error_log(
            'Task Board fetch failed: ' . $tasksStmt->error
        );
        $taskBoardError = 'Unable to load tasks.';
    }
    $tasksStmt->close();
} else {
    error_log(
        'Task Board fetch prepare failed: ' . $conn->error
    );
    $taskBoardError = 'Unable to load tasks.';
}
?>
<link rel="stylesheet" type="text/css" href="css/taskmaster.css">
<section
    class="task-board-module"
    id="task-board-module"
    data-api-url="taskmaster.php"
    data-csrf-token="<?= $tbEscape($taskBoardCsrf) ?>">
    <!-- HEADER -->
    <div class="task-board-header">
        <div class="task-board-heading">
            <span class="task-board-eyebrow">WORKSPACE MANAGEMENT</span>
            <h1>Task Board</h1>
            <p>Organize your tasks and keep your workflow moving.</p>
        </div>
        <div class="task-board-header-icon">
            <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
        </div>
    </div>
    <!-- THREE EXISTING CARDS -->
    <div class="task-board-grid">
        <article class="task-category-card new-task-card">
            <div class="task-card-top">
                <div class="task-card-icon">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <span class="task-card-number">01</span>
            </div>
            <div class="task-card-content">
                <h2>New Task</h2>
                <p>Manage newly created tasks and upcoming work.</p>
                <div class="task-list" id="task-list">
                    <?php if (empty($taskBoardTasks)): ?>
                        <div class="task-empty-state" id="task-empty-state">
                            <i class="fa-solid fa-clipboard-list"></i>
                            <p>No tasks created yet.</p>
                            <span>Create a new task to get started.</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($taskBoardTasks as $task): ?>
                            <article
                                class="task-list-item"
                                data-task-id="<?= (int) $task['id'] ?>">
                                <div class="task-list-item-top">
                                    <div class="task-list-item-details">
                                        <h3>
                                            <?= $tbEscape($task['task_title']) ?>
                                        </h3>
                                        <span class="task-company-name">
                                            <?= $tbEscape($task['comp_name']) ?>
                                        </span>
                                    </div>
                                    <?php if ((int) $task['is_priority'] === 1): ?>
                                        <span class="task-priority-badge">
                                            Priority
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="task-list-meta">
                                    <span>
                                        <?= $tbEscape($task['task_type']) ?>
                                    </span>
                                    <span>
                                        <?= $tbEscape($task['objective']) ?>
                                    </span>
                                </div>
                                <div class="task-list-footer">
                                    <span>
                                        Created by
                                        <?= $tbEscape($task['created_by_name']) ?>
                                    </span>
                                    <span>
                                        <?= $tbEscape(
                                            date(
                                                'd M Y',
                                                strtotime($task['created_at'])
                                            )
                                        ) ?>
                                    </span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="task-card-footer">
                <span class="task-card-status">
                    <span class="status-dot"></span>
                    Ready to start
                </span>
                <button
                    type="button"
                    class="task-add-button"
                    data-open-task-modal
                    aria-label="Add new task"
                    title="Add New Task">
                    <i class="fa-solid fa-plus"></i>
                </button>
            </div>
        </article>
        <article class="task-category-card wip-task-card">
            <div class="task-card-top">
                <div class="task-card-icon">
                    <i class="fa-solid fa-spinner"></i>
                </div>
                <span class="task-card-number">02</span>
            </div>
            <div class="task-card-content">
                <h2>Work in Progress <span>(WIP)</span></h2>
                <p>Keep track of tasks currently being worked on.</p>
            </div>
            <div class="task-card-footer">
                <span class="task-card-status">
                    <span class="status-dot"></span>
                    In progress
                </span>
                <button
                    type="button"
                    class="task-add-button"
                    aria-label="WIP tasks"
                    title="WIP integration coming next"
                    disabled>
                    <i class="fa-solid fa-plus"></i>
                </button>
            </div>
        </article>
        <article class="task-category-card recurring-task-card">
            <div class="task-card-top">
                <div class="task-card-icon">
                    <i class="fa-solid fa-repeat"></i>
                </div>
                <span class="task-card-number">03</span>
            </div>
            <div class="task-card-content">
                <h2>Recurring Task</h2>
                <p>Organize repetitive tasks and regular activities.</p>
            </div>
            <div class="task-card-footer">
                <span class="task-card-status">
                    <span class="status-dot"></span>
                    Scheduled work
                </span>
                <button
                    type="button"
                    class="task-add-button"
                    aria-label="Recurring tasks"
                    title="Recurring task integration coming next"
                    disabled>
                    <i class="fa-solid fa-plus"></i>
                </button>
            </div>
        </article>
    </div>
    <!-- CREATE TASK MODAL -->
    <div class="task-modal-overlay" id="task-modal-overlay" hidden>
        <section
            class="task-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="task-modal-title">
            <div class="task-modal-header">
                <div>
                    <span class="task-board-eyebrow">TASK MANAGEMENT</span>
                    <h2 id="task-modal-title">Create New Task</h2>
                    <p>Add the details of your new task.</p>
                </div>
                <button
                    type="button"
                    class="task-modal-close"
                    data-close-task-modal
                    aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <?php if ($taskBoardError !== ''): ?>
                <div class="task-form-message is-error">
                    <?= $tbEscape($taskBoardError) ?>
                </div>
            <?php endif; ?>
            <form id="task-create-form">
                <input type="hidden" name="taskboard_action" value="create">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= $tbEscape($taskBoardCsrf) ?>">
                <div class="task-form-grid">
                    <div class="task-form-field task-company-field">
                        <label for="task-company">Company Name *</label>
                        <select id="task-company" name="company_id" required>
                            <option value="">Select company</option>
                            <?php foreach ($taskBoardCompanies as $company): ?>
                                <option
                                    value="<?= (int) $company['id'] ?>"
                                    data-logo="<?= $tbEscape($company['comp_logo'] ?? '') ?>">
                                    <?= $tbEscape($company['comp_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div
                            class="task-company-preview"
                            id="task-company-preview"
                            hidden>
                            <img id="task-company-logo" src="" alt="Company logo">
                            <span id="task-company-name"></span>
                        </div>
                    </div>
                    <div class="task-form-field">
                        <label for="task-title">Task Title *</label>
                        <input
                            type="text"
                            id="task-title"
                            name="task_title"
                            maxlength="255"
                            placeholder="Enter task title"
                            required>
                    </div>
                    <div class="task-form-field">
                        <label for="task-type">Task Type *</label>
                        <select id="task-type" name="task_type" required>
                            <option value="">Select task type</option>
                            <option value="Strategy/Pitch Deck">Strategy/Pitch Deck</option>
                            <option value="Moodboard">Moodboard</option>
                            <option value="Shoots">Shoots</option>
                            <option value="CP">CP</option>
                            <option value="Content Writing">Content Writing</option>
                            <option value="Influencer">Influencer</option>
                        </select>
                    </div>
                    <div class="task-form-field">
                        <label for="task-objective">Objective *</label>
                        <select id="task-objective" name="objective" required>
                            <option value="">Select objective</option>
                            <option value="Brand Awareness">Brand Awareness</option>
                            <option value="Lead Generation">Lead Generation</option>
                            <option value="Positioning">Positioning</option>
                            <option value="Others">Others</option>
                        </select>
                    </div>
                    <div class="task-form-field task-priority-field">
                        <label>Priority</label>
                        <label class="task-priority-toggle">
                            <input
                                type="checkbox"
                                name="is_priority"
                                value="1">
                            <span class="task-priority-switch"></span>
                            <span class="task-priority-label">
                                <strong>Mark as priority</strong>
                                <small>Give this task higher attention.</small>
                            </span>
                        </label>
                    </div>
                    <div class="task-form-field task-created-by-field">
                        <label>Created By</label>
                        <div class="task-created-by">
                            <i class="fa-solid fa-user-shield"></i>
                            <span><?= $tbEscape($taskBoardUserName) ?></span>
                        </div>
                        <small>Automatically recorded from your login.</small>
                    </div>
                </div>
                <div
                    class="task-form-message"
                    id="task-form-message"
                    role="status"
                    aria-live="polite"
                    hidden></div>
                <div class="task-modal-actions">
                    <button
                        type="button"
                        class="task-cancel-button"
                        data-close-task-modal>
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="task-submit-button"
                        id="task-submit-button">
                        <i class="fa-solid fa-check"></i>
                        <span>Create Task</span>
                    </button>
                </div>
            </form>
        </section>
    </div>
    <div
        class="task-board-toast"
        id="task-board-toast"
        role="status"
        aria-live="polite"
        hidden></div>
</section>
<script src="js/taskmaster.js"></script>