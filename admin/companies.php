<?php
// admin/companies.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    empty($_SESSION['user_id']) ||
    strtolower(trim((string) ($_SESSION['role'] ?? ''))) !== 'admin'
) {
    if (
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
    ) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Your session has expired. Please log in again.'
        ]);
        exit;
    }
    header('Location: ../index.php');
    exit;
}
require_once __DIR__ . '/../config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$isAjaxRequest = strtolower(
    $_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''
) === 'xmlhttprequest';
if (empty($_SESSION['companies_csrf_token'])) {
    $_SESSION['companies_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['companies_csrf_token'];
/*
|--------------------------------------------------------------------------
| Assign SME to an Existing Company
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_sme'])) {
    try {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');

        if (
            $submittedToken === '' ||
            !hash_equals($csrfToken, $submittedToken)
        ) {
            throw new RuntimeException(
                'Invalid request. Please refresh and try again.'
            );
        }

        $companyId = (int) ($_POST['company_id'] ?? 0);
        $smeUserId = (int) ($_POST['sme_user_id'] ?? 0);

        if ($companyId < 1 || $smeUserId < 1) {
            throw new RuntimeException(
                'Please select a company and SME employee.'
            );
        }

        $conn->begin_transaction();

        // Lock the company row and confirm it exists.
        $stmt = $conn->prepare(
            'SELECT id
             FROM companies
             WHERE id = ?
             FOR UPDATE'
        );
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $companyExists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$companyExists) {
            throw new RuntimeException('Company not found.');
        }

        // Only allow assignment if the company is currently unassigned.
        $stmt = $conn->prepare(
            'SELECT id
             FROM sme_company_assignments
             WHERE company_id = ?
             LIMIT 1'
        );
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $alreadyAssigned = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($alreadyAssigned) {
            throw new RuntimeException(
                'This company already has an SME assigned.'
            );
        }

        // Lock and validate the selected SME.
        $stmt = $conn->prepare(
            'SELECT id
             FROM users
             WHERE id = ?
               AND JSON_CONTAINS(role, \'"sme"\')
             FOR UPDATE'
        );
        $stmt->bind_param('i', $smeUserId);
        $stmt->execute();
        $smeExists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$smeExists) {
            throw new RuntimeException(
                'Please select a valid SME employee.'
            );
        }

        // Enforce the five-company limit on the server.
        $stmt = $conn->prepare(
            'SELECT COUNT(*) AS total
             FROM sme_company_assignments
             WHERE sme_user_id = ?'
        );
        $stmt->bind_param('i', $smeUserId);
        $stmt->execute();

        $assignedCount = (int) (
            $stmt->get_result()->fetch_assoc()['total'] ?? 0
        );
        $stmt->close();

        if ($assignedCount >= 5) {
            throw new RuntimeException(
                'This SME already has 5 assigned companies.'
            );
        }

        $assignedBy = (int) $_SESSION['user_id'];

        $stmt = $conn->prepare(
            'INSERT INTO sme_company_assignments
                (sme_user_id, company_id, assigned_by)
             VALUES (?, ?, ?)'
        );
        $stmt->bind_param(
            'iii',
            $smeUserId,
            $companyId,
            $assignedBy
        );
        $stmt->execute();
        $stmt->close();

        // Get updated company details.
        $stmt = $conn->prepare(
            'SELECT
                c.id,
                c.comp_name,
                c.comp_logo,
                sca.sme_user_id,
                u.fullname AS sme_name
             FROM companies AS c
             LEFT JOIN sme_company_assignments AS sca
                ON sca.company_id = c.id
             LEFT JOIN users AS u
                ON u.id = sca.sme_user_id
             WHERE c.id = ?'
        );
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $updatedCompany = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $conn->commit();

        companiesJsonResponse(200, [
            'success' => true,
            'message' => 'SME assigned successfully.',
            'company' => $updatedCompany,
            'sme_assigned_count' => $assignedCount + 1
        ]);
    } catch (Throwable $exception) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log(
                'SME assignment rollback error: '
                    . $rollbackError->getMessage()
            );
        }

        error_log(
            'SME assignment error: ' . $exception->getMessage()
        );

        $message = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Unable to assign the SME. Please check the server error log.';

        companiesJsonResponse(400, [
            'success' => false,
            'message' => $message
        ]);
    }
}

$error = '';
$success = '';
/**
 * Escape output for HTML.
 */
function companiesEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
/**
 * Return a JSON response and stop execution.
 */
function companiesJsonResponse(
    int $statusCode,
    array $data
): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
/*
|--------------------------------------------------------------------------
| Add Company
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['add_company'])
) {
    $uploadedLogoPath = null;
    $transactionStarted = false;
    $transactionCommitted = false;
    try {
        // Validate CSRF token.
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');
        if (
            $submittedToken === '' ||
            !hash_equals($csrfToken, $submittedToken)
        ) {
            throw new RuntimeException(
                'Invalid request. Please refresh the page and try again.'
            );
        }
        // Validate company name.
        $companyName = trim((string) ($_POST['comp_name'] ?? ''));
        $smeUserId = (int) ($_POST['sme_user_id'] ?? 0);
        if ($companyName === '') {
            throw new RuntimeException('Please enter the company name.');
        }
        if (mb_strlen($companyName) > 255) {
            throw new RuntimeException(
                'Company name cannot exceed 255 characters.'
            );
        }
        // Logo upload is optional.
        $logoDatabasePath = null;
        if (
            isset($_FILES['comp_logo']) &&
            $_FILES['comp_logo']['error'] !== UPLOAD_ERR_NO_FILE
        ) {
            $file = $_FILES['comp_logo'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException(
                    'Company logo upload failed.'
                );
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                throw new RuntimeException(
                    'Company logo must be 5 MB or smaller.'
                );
            }
            if (!is_uploaded_file($file['tmp_name'])) {
                throw new RuntimeException('Invalid logo upload.');
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/gif'  => 'gif'
            ];
            if (!isset($allowedTypes[$mimeType])) {
                throw new RuntimeException(
                    'Upload a JPG, PNG, WEBP or GIF logo.'
                );
            }
            $uploadDirectory = __DIR__ . '/../uploads/companies/';
            if (
                !is_dir($uploadDirectory) &&
                !mkdir($uploadDirectory, 0755, true) &&
                !is_dir($uploadDirectory)
            ) {
                throw new RuntimeException(
                    'Unable to create the company logo upload folder.'
                );
            }
            $fileName = bin2hex(random_bytes(16))
                . '.'
                . $allowedTypes[$mimeType];
            $destination = $uploadDirectory . $fileName;
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new RuntimeException(
                    'Unable to save the company logo.'
                );
            }
            $uploadedLogoPath = $destination;
            $logoDatabasePath = 'uploads/companies/' . $fileName;
        }
        // Start transaction.
        $conn->begin_transaction();
        $transactionStarted = true;
        // Insert company.
        $stmt = $conn->prepare(
            'INSERT INTO companies (comp_name, comp_logo)
             VALUES (?, ?)'
        );
        $stmt->bind_param('ss', $companyName, $logoDatabasePath);
        $stmt->execute();
        $companyId = (int) $conn->insert_id;
        $stmt->close();
        /*
         * Save Daily, Weekly and Monthly SOPs.
         */
        $sopTitles = $_POST['sop_title'] ?? [];
        $sopDates = $_POST['sop_date'] ?? [];
        $allowedSopTypes = ['daily', 'weekly', 'monthly'];
        $insertSop = $conn->prepare(
            'INSERT INTO company_sops
                (company_id, sop_type, sop_title, sop_description, sop_date)
             VALUES (?, ?, ?, NULL, ?)'
        );
        try {
            foreach ($allowedSopTypes as $type) {
                $titles = $sopTitles[$type] ?? [];
                $dates = $sopDates[$type] ?? [];
                if (!is_array($titles) || !is_array($dates)) {
                    throw new RuntimeException('Invalid SOP form data.');
                }
                if (count($titles) !== count($dates)) {
                    throw new RuntimeException(
                        'SOP title and date count mismatch.'
                    );
                }
                foreach ($titles as $index => $rawTitle) {
                    $title = trim((string) $rawTitle);
                    $date = trim((string) ($dates[$index] ?? ''));
                    // Ignore completely empty optional rows.
                    if ($title === '' && $date === '') {
                        continue;
                    }
                    if ($title === '' || $date === '') {
                        throw new RuntimeException(
                            'Please enter both the SOP text and date for every row.'
                        );
                    }
                    $parsedDate = DateTime::createFromFormat('!Y-m-d', $date);
                    $dateErrors = DateTime::getLastErrors();
                    if (
                        !$parsedDate ||
                        (
                            $dateErrors !== false &&
                            (
                                $dateErrors['warning_count'] > 0 ||
                                $dateErrors['error_count'] > 0
                            )
                        ) ||
                        $parsedDate->format('Y-m-d') !== $date
                    ) {
                        throw new RuntimeException(
                            'Please select a valid SOP date.'
                        );
                    }
                    $insertSop->bind_param(
                        'isss',
                        $companyId,
                        $type,
                        $title,
                        $date
                    );
                    $insertSop->execute();
                }
            }
        } finally {
            $insertSop->close();
        }
        /*
         * Assign SME if selected.
         */
        if ($smeUserId > 0) {
            // Lock the SME user row while checking workload.
            $stmt = $conn->prepare(
                'SELECT id
                 FROM users
                 WHERE id = ?
                   AND JSON_CONTAINS(role, \'"sme"\')
                 FOR UPDATE'
            );
            $stmt->bind_param('i', $smeUserId);
            $stmt->execute();
            $smeExists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$smeExists) {
                throw new RuntimeException(
                    'Please select a valid SME employee.'
                );
            }
            // Check current assignment count.
            $stmt = $conn->prepare(
                'SELECT COUNT(*) AS total
                 FROM sme_company_assignments
                 WHERE sme_user_id = ?'
            );
            $stmt->bind_param('i', $smeUserId);
            $stmt->execute();
            $assignmentCount = (int) (
                $stmt->get_result()->fetch_assoc()['total'] ?? 0
            );
            $stmt->close();
            if ($assignmentCount >= 5) {
                throw new RuntimeException(
                    'This employee already has 5 companies assigned. '
                        . 'Please select another employee.'
                );
            }
            // Insert SME assignment.
            $assignedBy = (int) $_SESSION['user_id'];
            $stmt = $conn->prepare(
                'INSERT INTO sme_company_assignments
                    (sme_user_id, company_id, assigned_by)
                 VALUES (?, ?, ?)'
            );
            $stmt->bind_param(
                'iii',
                $smeUserId,
                $companyId,
                $assignedBy
            );
            $stmt->execute();
            $stmt->close();
        }
        /*
         * Fetch the new company before committing.
         * This avoids returning an AJAX error after a successful commit
         * if the SELECT query fails.
         */
        $stmt = $conn->prepare(
            'SELECT
                c.id,
                c.comp_name,
                c.comp_logo,
                u.fullname AS sme_name
             FROM companies AS c
             LEFT JOIN sme_company_assignments AS sca
                ON sca.company_id = c.id
             LEFT JOIN users AS u
                ON u.id = sca.sme_user_id
             WHERE c.id = ?'
        );
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $newCompany = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$newCompany) {
            throw new RuntimeException(
                'Company was created but could not be loaded.'
            );
        }
        // Commit only after all database work succeeds.
        $conn->commit();
        $transactionStarted = false;
        $transactionCommitted = true;
        if ($isAjaxRequest) {
            companiesJsonResponse(200, [
                'success' => true,
                'message' => 'Company added successfully.',
                'company' => $newCompany
            ]);
        }
        // Normal form submission fallback.
        header('Location: admin.php?page=companies&success=1');
        exit;
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                error_log(
                    'Company transaction rollback error: '
                        . $rollbackError->getMessage()
                );
            }
        }
        // Remove the uploaded file only if the transaction did not commit.
        if (
            !$transactionCommitted &&
            $uploadedLogoPath !== null &&
            is_file($uploadedLogoPath)
        ) {
            @unlink($uploadedLogoPath);
        }
        error_log(
            'Companies page error: ' . $exception->getMessage()
        );
        if ($exception instanceof RuntimeException) {
            $error = $exception->getMessage();
        } else {
            $error = 'Unable to save the company. '
                . 'Please check the database tables and server error log.';
        }
        if ($isAjaxRequest) {
            companiesJsonResponse(400, [
                'success' => false,
                'message' => $error
            ]);
        }
    }
}
/*
|--------------------------------------------------------------------------
| Success messages
|--------------------------------------------------------------------------
*/
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $success = 'Company added successfully.';
}
/*
|--------------------------------------------------------------------------
| Fetch SME employees with fewer than 5 assigned companies
|--------------------------------------------------------------------------
*/
$smeEmployees = [];
try {
    $smeQuery = $conn->query(
        'SELECT
            u.id,
            u.fullname,
            COUNT(sca.id) AS assigned_companies
         FROM users AS u
         LEFT JOIN sme_company_assignments AS sca
            ON sca.sme_user_id = u.id
         WHERE JSON_CONTAINS(u.role, \'"sme"\')
         GROUP BY u.id, u.fullname
         HAVING COUNT(sca.id) < 5
         ORDER BY u.fullname ASC'
    );
    while ($row = $smeQuery->fetch_assoc()) {
        $smeEmployees[] = $row;
    }
    $smeQuery->free();
} catch (Throwable $exception) {
    error_log(
        'SME employee query error: ' . $exception->getMessage()
    );
    if ($error === '') {
        $error = 'Unable to load SME employees. '
            . 'Please check the assignment table and role data.';
    }
}
/*
|--------------------------------------------------------------------------
| Fetch companies and their assigned SME
|--------------------------------------------------------------------------
*/
$companies = [];
try {
    $companyQuery = $conn->query(
        'SELECT
            c.id,
            c.comp_name,
            c.comp_logo,
            sca.sme_user_id,
            u.fullname AS sme_name
         FROM companies AS c
         LEFT JOIN sme_company_assignments AS sca
            ON c.id = sca.company_id
         LEFT JOIN users AS u
            ON sca.sme_user_id = u.id
         ORDER BY c.id DESC'
    );
    while ($row = $companyQuery->fetch_assoc()) {
        $companies[] = $row;
    }
    $companyQuery->free();
} catch (Throwable $exception) {
    error_log(
        'Companies listing error: ' . $exception->getMessage()
    );
    if ($error === '') {
        $error = 'Unable to load the companies list.';
    }
}
?>
<link rel="stylesheet" href="../assets/css/companies.css">
<?php
$totalCompanies = is_array($companies) ? count($companies) : 0;
$assignedCompanies = 0;
if (is_array($companies)) {
    foreach ($companies as $statCompany) {
        if (!empty($statCompany['sme_user_id'])) {
            $assignedCompanies++;
        }
    }
}
$unassignedCompanies = $totalCompanies - $assignedCompanies;
?>
<div class="companies-page">
    <div class="page-header">
        <div>
            <h2>Companies</h2>
            <p>Manage companies, SOPs and SME assignments.</p>
        </div>
        <button
            type="button"
            class="btn btn-primary"
            id="openAddCompanyModal">
            Add Company
        </button>
    </div>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <?= companiesEscape($error) ?>
        </div>
    <?php endif; ?>
    <?php if ($success !== ''): ?>
        <div class="alert alert-success" role="alert">
            <?= companiesEscape($success) ?>
        </div>
    <?php endif; ?>
    <div class="companies-toolbar">
        <div class="companies-stats">
            <span class="stat-chip">
                <strong id="statTotal"><?= $totalCompanies ?></strong> Total
            </span>
            <span class="stat-chip stat-chip--assigned">
                <strong id="statAssigned"><?= $assignedCompanies ?></strong> Assigned
            </span>
            <span class="stat-chip stat-chip--unassigned">
                <strong id="statUnassigned"><?= $unassignedCompanies ?></strong> Unassigned
            </span>
        </div>
        <div class="companies-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input
                type="search"
                id="companySearch"
                placeholder="Search companies or SMEs..."
                autocomplete="off"
                aria-label="Search companies">
        </div>
    </div>
    <div class="table-container">
        <table class="companies-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Logo</th>
                    <th>Company Name</th>
                    <th>Assigned SME</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($companies)): ?>
                    <tr>
                        <td colspan="4" class="no-data">
                            No companies found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($companies as $company): ?>
                        <tr>
                            <td data-label="ID" class="company-id-cell">
                                #<?= (int) $company['id'] ?>
                            </td>
                            <td data-label="Logo">
                                <?php
                                $companyNameText = (string) ($company['comp_name'] ?? '');
                                $companyInitial = function_exists('mb_substr')
                                    ? mb_strtoupper(mb_substr(trim($companyNameText), 0, 1))
                                    : strtoupper(substr(trim($companyNameText), 0, 1));
                                if ($companyInitial === '') {
                                    $companyInitial = '?';
                                }
                                $logoPath = trim(
                                    (string) ($company['comp_logo'] ?? '')
                                );
                                $logoPath = str_replace('\\', '/', $logoPath);
                                $logoPath = ltrim($logoPath, '/');
                                if (
                                    $logoPath !== '' &&
                                    strpos($logoPath, 'uploads/') !== 0
                                ) {
                                    $logoPath = 'uploads/' . $logoPath;
                                }
                                $logoUrl = $logoPath !== ''
                                    ? '../' . $logoPath
                                    : '';
                                ?>
                                <?php if ($logoPath !== ''): ?>
                                    <img
                                        src="<?= companiesEscape($logoUrl) ?>"
                                        alt="<?= companiesEscape($company['comp_name']) ?> logo"
                                        class="company-logo"
                                        onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                                    <span
                                        class="no-logo"
                                        title="Logo unavailable"
                                        style="display:none;"><?= companiesEscape($companyInitial) ?></span>
                                <?php else: ?>
                                    <span
                                        class="no-logo"
                                        title="No logo uploaded"><?= companiesEscape($companyInitial) ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Company" class="company-name-cell">
                                <span class="company-name"><?= companiesEscape($company['comp_name']) ?></span>
                            </td>
                            <td data-label="Assigned SME" class="company-sme-cell">
                                <?php if (!empty($company['sme_user_id'])): ?>

                                    <span class="assigned-sme-name">
                                        <?= companiesEscape(
                                            $company['sme_name'] ?? 'SME'
                                        ) ?>
                                    </span>

                                <?php else: ?>

                                    <span class="unassigned-label">
                                        Not Assigned
                                    </span>

                                    <button
                                        type="button"
                                        class="btn btn-primary assign-sme-button"
                                        data-company-id="<?= (int) $company['id'] ?>"
                                        data-company-name="<?= companiesEscape(
                                                                $company['comp_name']
                                                            ) ?>">
                                        Assign SME
                                    </button>

                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="companies-no-results" id="companyNoResults" hidden>
        No companies match your search.
    </p>
</div>
<!-- Add Company Modal -->
<div class="modal" id="addCompanyModal" aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add Company</h3>
            <button
                type="button"
                class="modal-close"
                id="closeAddCompanyModal"
                aria-label="Close">
                &times;
            </button>
        </div>
        <form
            id="addCompanyForm"
            action="companies.php"
            method="POST"
            enctype="multipart/form-data">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= companiesEscape($csrfToken) ?>">
            <div class="form-group">
                <label for="comp_name">Company Name</label>
                <input
                    type="text"
                    id="comp_name"
                    name="comp_name"
                    maxlength="255"
                    required
                    placeholder="Enter company name">
            </div>
            <div class="form-group">
                <label for="comp_logo">Company Logo</label>
                <input
                    type="file"
                    id="comp_logo"
                    name="comp_logo"
                    accept=".jpg,.jpeg,.png,.webp,.gif">
                <small>Optional. Maximum file size: 5 MB.</small>
            </div>
            <div class="form-group">
                <label for="sme_user_id">Assign SME Employee</label>
                <select id="sme_user_id" name="sme_user_id">
                    <option value="0">
                        Select SME Employee (Optional)
                    </option>
                    <?php foreach ($smeEmployees as $sme): ?>
                        <option value="<?= (int) $sme['id'] ?>">
                            <?= companiesEscape($sme['fullname']) ?>
                            (<?= (int) $sme['assigned_companies'] ?>/5 companies)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($smeEmployees)): ?>
                    <small>
                        No SME employees currently have fewer than
                        5 assigned companies.
                    </small>
                <?php else: ?>
                    <small>
                        Only SMEs with fewer than 5 assigned companies
                        are listed.
                    </small>
                <?php endif; ?>
            </div>
            <div class="sop-section" id="companySopSection">
                <h3>Company SOPs</h3>
                <p>Enter each SOP and select its scheduled date.</p>
                <!-- Daily SOP -->
                <div class="sop-group">
                    <h4>Daily SOP</h4>
                    <div class="sop-rows" id="dailySopRows">
                        <div class="sop-row">
                            <input
                                type="text"
                                name="sop_title[daily][]"
                                placeholder="Enter daily SOP">
                            <input
                                type="date"
                                name="sop_date[daily][]"
                                aria-label="Daily SOP date">
                            <button
                                type="button"
                                class="remove-sop-row"
                                aria-label="Remove SOP">&times;</button>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="add-sop-row"
                        data-type="daily">+ Add Daily SOP</button>
                </div>
                <!-- Weekly SOP -->
                <div class="sop-group">
                    <h4>Weekly SOP</h4>
                    <div class="sop-rows" id="weeklySopRows">
                        <div class="sop-row">
                            <input
                                type="text"
                                name="sop_title[weekly][]"
                                placeholder="Enter weekly SOP">
                            <input
                                type="date"
                                name="sop_date[weekly][]"
                                aria-label="Weekly SOP date">
                            <button
                                type="button"
                                class="remove-sop-row"
                                aria-label="Remove SOP">&times;</button>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="add-sop-row"
                        data-type="weekly">+ Add Weekly SOP</button>
                </div>
                <!-- Monthly SOP -->
                <div class="sop-group">
                    <h4>Monthly SOP</h4>
                    <div class="sop-rows" id="monthlySopRows">
                        <div class="sop-row">
                            <input
                                type="text"
                                name="sop_title[monthly][]"
                                placeholder="Enter monthly SOP">
                            <input
                                type="date"
                                name="sop_date[monthly][]"
                                aria-label="Monthly SOP date">
                            <button
                                type="button"
                                class="remove-sop-row"
                                aria-label="Remove SOP">&times;</button>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="add-sop-row"
                        data-type="monthly">+ Add Monthly SOP</button>
                </div>
            </div>
            <div class="modal-footer">
                <button
                    type="button"
                    class="btn btn-secondary"
                    id="cancelAddCompany">Cancel</button>
                <button
                    type="submit"
                    class="btn btn-primary"
                    name="add_company"
                    value="1">Save Company</button>
            </div>
        </form>
    </div>
</div>
<!-- Assign SME Modal -->
<div
    class="modal"
    id="assignSmeModal"
    aria-hidden="true">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Assign SME</h3>

            <button
                type="button"
                class="modal-close"
                id="closeAssignSmeModal"
                aria-label="Close">&times;</button>
        </div>

        <p id="assignSmeCompanyName"></p>

        <form id="assignSmeForm" action="companies.php" method="POST">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= companiesEscape($csrfToken) ?>">

            <input
                type="hidden"
                name="company_id"
                id="assignSmeCompanyId">

            <div class="form-group">
                <label for="assignSmeEmployee">
                    Select SME Employee
                </label>

                <select
                    id="assignSmeEmployee"
                    name="sme_user_id"
                    required>
                    <option value="">Select SME Employee</option>

                    <?php foreach ($smeEmployees as $sme): ?>
                        <option value="<?= (int) $sme['id'] ?>">
                            <?= companiesEscape($sme['fullname']) ?>
                            (<?= (int) $sme['assigned_companies'] ?>/5 companies)
                        </option>
                    <?php endforeach; ?>
                </select>

                <small>
                    Only SMEs with fewer than 5 assigned companies
                    are listed.
                </small>
            </div>

            <div class="modal-footer">
                <button
                    type="button"
                    class="btn btn-secondary"
                    id="cancelAssignSme">Cancel</button>

                <button
                    type="submit"
                    class="btn btn-primary"
                    id="confirmAssignSme">Assign SME</button>
            </div>
        </form>
    </div>
</div>
<script src="../assets/js/companies.js" defer></script>