<?php

if (
    empty($_SESSION['user_id']) ||
    strtolower(trim((string) ($_SESSION['role'] ?? ''))) !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}
require_once __DIR__ . '/../config/db.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection is unavailable.');
}
$allowedRoles = ['admin', 'reporting_manager', 'sme', 'smi', 'des', 'clients'];
$message = '';
$messageType = '';
$listError = '';
if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}
if (!function_exists('hasSmeRole')) {
    function hasSmeRole(array $roles): bool
    {
        return in_array('sme', $roles, true);
    }
}
if (!function_exists('isValidUsersCsrfToken')) {
    function isValidUsersCsrfToken(): bool
    {
        $submitted = (string) ($_POST['csrf_token'] ?? '');
        $session = (string) ($_SESSION['users_csrf_token'] ?? '');
        return $submitted !== ''
            && $session !== ''
            && hash_equals($session, $submitted);
    }
}
if (!function_exists('removeUploadedUserFiles')) {
    function removeUploadedUserFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }
}
if (!function_exists('uploadUserImage')) {
    function uploadUserImage(
        string $field,
        string $uploadDirectory,
        string &$error
    ) {
        if (!isset($_FILES[$field])) {
            return null;
        }
        $file = $_FILES[$field];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Image upload failed. Please try again.';
            return false;
        }
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
            $error = 'Each image must be 5 MB or smaller.';
            return false;
        }
        if (
            empty($file['tmp_name']) ||
            !is_uploaded_file($file['tmp_name'])
        ) {
            $error = 'Invalid uploaded file.';
            return false;
        }
        if (!class_exists('finfo')) {
            $error = 'Image validation is unavailable on this server.';
            return false;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif'
        ];
        if (
            !isset($extensions[$mime]) ||
            @getimagesize($file['tmp_name']) === false
        ) {
            $error = 'Only JPG, PNG, WEBP and GIF images are allowed.';
            return false;
        }
        if (!is_dir($uploadDirectory)) {
            if (
                !mkdir($uploadDirectory, 0755, true) &&
                !is_dir($uploadDirectory)
            ) {
                $error = 'Unable to create uploads directory.';
                return false;
            }
        }
        $filename = bin2hex(random_bytes(16))
            . '.'
            . $extensions[$mime];
        $destination = $uploadDirectory
            . DIRECTORY_SEPARATOR
            . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            $error = 'Unable to save uploaded image.';
            return false;
        }
        return $filename;
    }
}
if (empty($_SESSION['users_csrf_token'])) {
    $_SESSION['users_csrf_token'] = bin2hex(random_bytes(32));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['assign_companies'])) {
        $error = '';
        if (!isValidUsersCsrfToken()) {
            $error = 'Invalid request token. Refresh the page and try again.';
        }
        $smeUserId = filter_var(
            $_POST['sme_user_id'] ?? null,
            FILTER_VALIDATE_INT
        );
        $submittedIds = $_POST['company_ids'] ?? [];
        $selectedCompanyIds = [];
        if (!is_array($submittedIds)) {
            $error = 'Invalid company selection.';
        } else {
            foreach ($submittedIds as $companyId) {
                if (
                    !is_scalar($companyId) ||
                    filter_var(
                        (string) $companyId,
                        FILTER_VALIDATE_INT
                    ) === false ||
                    (int) $companyId < 1
                ) {
                    $error = 'One or more selected companies are invalid.';
                    break;
                }
                $selectedCompanyIds[] = (int) $companyId;
            }
        }
        $selectedCompanyIds = array_values(
            array_unique($selectedCompanyIds)
        );
        if (
            $error === '' &&
            (!$smeUserId || $smeUserId < 1)
        ) {
            $error = 'Invalid SME selected.';
        }
        if (
            $error === '' &&
            count($selectedCompanyIds) > 5
        ) {
            $error = 'A maximum of 5 companies can be assigned to an SME.';
        }
        if ($error === '') {
            try {
                $conn->begin_transaction();
                $stmt = $conn->prepare(
                    'SELECT id, role
                     FROM users
                     WHERE id = ?
                     FOR UPDATE'
                );
                if (!$stmt) {
                    throw new RuntimeException('Unable to validate SME.');
                }
                $stmt->bind_param('i', $smeUserId);
                $stmt->execute();
                $sme = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $roles = json_decode(
                    (string) ($sme['role'] ?? '[]'),
                    true
                );
                if (
                    !$sme ||
                    !is_array($roles) ||
                    !hasSmeRole($roles)
                ) {
                    throw new RuntimeException(
                        'Companies can only be assigned to SME users.'
                    );
                }
                sort($selectedCompanyIds, SORT_NUMERIC);
                if (!empty($selectedCompanyIds)) {
                    $placeholders = implode(
                        ',',
                        array_fill(0, count($selectedCompanyIds), '?')
                    );
                    $stmt = $conn->prepare(
                        "SELECT id
                         FROM companies
                         WHERE id IN ($placeholders)
                         ORDER BY id
                         FOR UPDATE"
                    );
                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to validate selected companies.'
                        );
                    }
                    $types = str_repeat('i', count($selectedCompanyIds));
                    $stmt->bind_param(
                        $types,
                        ...$selectedCompanyIds
                    );
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $validIds = [];
                    while ($company = $result->fetch_assoc()) {
                        $validIds[] = (int) $company['id'];
                    }
                    $stmt->close();
                    if (count($validIds) !== count($selectedCompanyIds)) {
                        throw new RuntimeException(
                            'One or more selected companies do not exist.'
                        );
                    }
                    $stmt = $conn->prepare(
                        "SELECT company_id
                         FROM sme_company_assignments
                         WHERE company_id IN ($placeholders)
                           AND sme_user_id <> ?
                         FOR UPDATE"
                    );
                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to verify company assignments.'
                        );
                    }
                    $parameters = array_merge(
                        $selectedCompanyIds,
                        [(int) $smeUserId]
                    );
                    $stmt->bind_param(
                        $types . 'i',
                        ...$parameters
                    );
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $conflicts = [];
                    while ($row = $result->fetch_assoc()) {
                        $conflicts[] = (int) $row['company_id'];
                    }
                    $stmt->close();
                    if (!empty($conflicts)) {
                        throw new RuntimeException(
                            'One or more companies are already assigned to another SME.'
                        );
                    }
                }
                $stmt = $conn->prepare(
                    'DELETE FROM sme_company_assignments
                     WHERE sme_user_id = ?'
                );
                if (!$stmt) {
                    throw new RuntimeException(
                        'Unable to update company assignments.'
                    );
                }
                $stmt->bind_param('i', $smeUserId);
                $stmt->execute();
                $stmt->close();
                if (!empty($selectedCompanyIds)) {
                    $assignedBy = (int) $_SESSION['user_id'];
                    $stmt = $conn->prepare(
                        'INSERT INTO sme_company_assignments
                            (sme_user_id, company_id, assigned_by)
                         VALUES (?, ?, ?)'
                    );
                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to prepare company assignments.'
                        );
                    }
                    foreach ($selectedCompanyIds as $companyId) {
                        $stmt->bind_param(
                            'iii',
                            $smeUserId,
                            $companyId,
                            $assignedBy
                        );
                        if (!$stmt->execute()) {
                            throw new RuntimeException(
                                'Unable to save company assignments.'
                            );
                        }
                    }
                    $stmt->close();
                }
                $conn->commit();
                header('Location: admin.php?page=users&assignment_success=1');
                exit;
            } catch (Throwable $exception) {
                try {
                    $conn->rollback();
                } catch (Throwable $ignored) {
                }
                error_log('Company assignment error: ' . $exception->getMessage());
                $error = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Unable to update company assignments.';
            }
        }
        if ($error !== '') {
            $message = $error;
            $messageType = 'error';
        }
    } elseif (isset($_POST['save_sop'])) {
        $error = '';
        if (!isValidUsersCsrfToken()) {
            $error = 'Invalid request token. Refresh the page and try again.';
        }
        $smeUserId = filter_var(
            $_POST['sme_user_id'] ?? null,
            FILTER_VALIDATE_INT
        );
        $companyId = filter_var(
            $_POST['company_id'] ?? null,
            FILTER_VALIDATE_INT
        );
        $sopId = filter_var(
            $_POST['sop_id'] ?? '0',
            FILTER_VALIDATE_INT
        );
        $sopType = strtolower(
            trim((string) ($_POST['sop_type'] ?? ''))
        );
        $sopTitle = trim(
            (string) ($_POST['sop_title'] ?? '')
        );
        $sopDescription = $sopTitle;
        $sopDate = trim(
            (string) ($_POST['sop_date'] ?? '')
        );
        if (
            $error === '' &&
            (
                !$smeUserId ||
                $smeUserId < 1 ||
                !$companyId ||
                $companyId < 1 ||
                $sopId === false ||
                $sopId < 0
            )
        ) {
            $error = 'Invalid SME, company or SOP selected.';
        }
        if (
            $error === '' &&
            !in_array(
                $sopType,
                ['daily', 'weekly', 'monthly'],
                true
            )
        ) {
            $error = 'Please select a valid SOP type.';
        }
        if (
            $error === '' &&
            strlen($sopTitle) > 10000
        ) {
            $error = 'SOP description must not exceed 10,000 characters.';
        }
        $dateObject = DateTime::createFromFormat('!Y-m-d', $sopDate);
        $dateErrors = DateTime::getLastErrors();
        if (
            $error === '' &&
            (
                !$dateObject ||
                $dateObject->format('Y-m-d') !== $sopDate ||
                (
                    $dateErrors !== false &&
                    (
                        $dateErrors['warning_count'] > 0 ||
                        $dateErrors['error_count'] > 0
                    )
                )
            )
        ) {
            $error = 'Please select a valid SOP date.';
        }
        if ($error === '') {
            try {
                $conn->begin_transaction();
                $stmt = $conn->prepare(
                    'SELECT id, role
                     FROM users
                     WHERE id = ?
                     FOR UPDATE'
                );
                if (!$stmt) {
                    throw new RuntimeException('Unable to validate SME.');
                }
                $stmt->bind_param('i', $smeUserId);
                $stmt->execute();
                $sme = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $roles = json_decode(
                    (string) ($sme['role'] ?? '[]'),
                    true
                );
                if (
                    !$sme ||
                    !is_array($roles) ||
                    !hasSmeRole($roles)
                ) {
                    throw new RuntimeException(
                        'SOPs can only be managed for SME users.'
                    );
                }
                $stmt = $conn->prepare(
                    'SELECT company_id
                     FROM sme_company_assignments
                     WHERE sme_user_id = ?
                       AND company_id = ?
                     FOR UPDATE'
                );
                if (!$stmt) {
                    throw new RuntimeException(
                        'Unable to verify the company assignment.'
                    );
                }
                $stmt->bind_param('ii', $smeUserId, $companyId);
                $stmt->execute();
                $assignment = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$assignment) {
                    throw new RuntimeException(
                        'This company is not assigned to the selected SME.'
                    );
                }
                if ($sopId > 0) {
                    $stmt = $conn->prepare(
                        'SELECT cs.id
                         FROM company_sops AS cs
                         INNER JOIN sme_company_assignments AS sca
                            ON sca.company_id = cs.company_id
                         WHERE cs.id = ?
                           AND sca.sme_user_id = ?
                         FOR UPDATE'
                    );
                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to verify the existing SOP.'
                        );
                    }
                    $stmt->bind_param('ii', $sopId, $smeUserId);
                    $stmt->execute();
                    $existingSop = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$existingSop) {
                        throw new RuntimeException(
                            'The selected SOP was not found for this SME.'
                        );
                    }
                    $stmt = $conn->prepare(
                        'UPDATE company_sops
                         SET company_id = ?,
                             sop_type = ?,
                             sop_title = ?,
                             sop_description = ?,
                             sop_date = ?
                         WHERE id = ?'
                    );
                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to prepare SOP update.'
                        );
                    }
                    $stmt->bind_param(
                        'issssi',
                        $companyId,
                        $sopType,
                        $sopTitle,
                        $sopDescription,
                        $sopDate,
                        $sopId
                    );
                    if (!$stmt->execute()) {
                        throw new RuntimeException('Unable to update SOP.');
                    }
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare(
                        'INSERT INTO company_sops
                            (
                                company_id,
                                sop_type,
                                sop_title,
                                sop_description,
                                sop_date
                            )
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    if (!$stmt) {
                        throw new RuntimeException(
                            'Unable to prepare SOP creation.'
                        );
                    }
                    $stmt->bind_param(
                        'issss',
                        $companyId,
                        $sopType,
                        $sopTitle,
                        $sopDescription,
                        $sopDate
                    );
                    if (!$stmt->execute()) {
                        throw new RuntimeException('Unable to add SOP.');
                    }
                    $stmt->close();
                }
                $conn->commit();
                header('Location: admin.php?page=users&sop_success=1');
                exit;
            } catch (Throwable $exception) {
                try {
                    $conn->rollback();
                } catch (Throwable $ignored) {
                }
                error_log('SOP save error: ' . $exception->getMessage());
                $error = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Unable to save SOP. Please try again.';
            }
        }
        if ($error !== '') {
            $message = $error;
            $messageType = 'error';
        }
    } elseif (isset($_POST['add_user'])) {
        $error = '';
        if (!isValidUsersCsrfToken()) {
            $error = 'Invalid request token. Refresh the page and try again.';
        }
        $brandName = trim((string) ($_POST['brand_name'] ?? ''));
        $fullname = trim((string) ($_POST['fullname'] ?? ''));
        $contact = trim((string) ($_POST['contact'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $reportingManager = trim(
            (string) ($_POST['reporting_manager'] ?? '')
        );
        $selectedRoles = $_POST['role'] ?? [];
        if (!is_array($selectedRoles)) {
            $selectedRoles = [];
        }
        $selectedRoles = array_map(
            static function ($role): string {
                return strtolower(trim((string) $role));
            },
            array_filter($selectedRoles, 'is_string')
        );
        $selectedRoles = array_values(
            array_unique($selectedRoles)
        );
        if ($error === '' && ($brandName === '' || $fullname === '' || $contact === '' || $email === '' || $password === '' || $reportingManager === '')) {
            $error = 'Please complete all required fields.';
        } elseif (
            $error === '' &&
            (
                strlen($brandName) > 255 ||
                strlen($fullname) > 255 ||
                strlen($reportingManager) > 255
            )
        ) {
            $error = 'Brand name, full name and reporting manager name must not exceed 255 characters.';
        } elseif (
            $error === '' &&
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $error = 'Please enter a valid email address.';
        } elseif (
            $error === '' &&
            strlen($email) > 255
        ) {
            $error = 'Email address must not exceed 255 characters.';
        } elseif (
            $error === '' &&
            strlen($password) < 8
        ) {
            $error = 'Password must contain at least 8 characters.';
        } elseif (
            $error === '' &&
            !preg_match('/^[0-9+\-\s()]{7,13}$/', $contact)
        ) {
            $error = 'Please enter a valid contact number.';
        } elseif (
            $error === '' &&
            empty($selectedRoles)
        ) {
            $error = 'Please select at least one role.';
        } elseif (
            $error === '' &&
            count(array_diff($selectedRoles, $allowedRoles)) > 0
        ) {
            $error = 'One or more selected roles are invalid.';
        }
        $uploadDirectory = __DIR__ . '/../uploads';
        $brandLogo = null;
        $profileImage = null;
        $uploadedPaths = [];
        if ($error === '') {
            $brandLogo = uploadUserImage(
                'brand_logo',
                $uploadDirectory,
                $error
            );
            if ($brandLogo !== null && $brandLogo !== false) {
                $uploadedPaths[] =
                    $uploadDirectory . DIRECTORY_SEPARATOR . $brandLogo;
            }
        }
        if ($error === '') {
            $profileImage = uploadUserImage(
                'prof_image',
                $uploadDirectory,
                $error
            );
            if ($profileImage !== null && $profileImage !== false) {
                $uploadedPaths[] =
                    $uploadDirectory . DIRECTORY_SEPARATOR . $profileImage;
            }
        }
        if ($error === '') {
            $stmt = $conn->prepare(
                'SELECT id
     FROM users
     WHERE email = ?
     LIMIT 1'
            );
            if (!$stmt) {
                $error = 'Unable to validate the email address.';
            } else {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $existingUser = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($existingUser) {
                    $error = 'This email address is already registered.';
                }
            }
            if ($error === '') {
                $stmt = $conn->prepare(
                    "SELECT id
         FROM users
         WHERE fullname = ?
           AND JSON_VALID(role)
           AND JSON_CONTAINS(
               role,
               '\"reporting_manager\"'
           )
         LIMIT 1"
                );
                if (!$stmt) {
                    $error = 'Unable to validate the reporting manager.';
                } else {
                    $stmt->bind_param('s', $reportingManager);
                    $stmt->execute();
                    $managerResult = $stmt->get_result();
                    $validManager = $managerResult->fetch_assoc();
                    $stmt->close();
                    if (!$validManager) {
                        $error = 'Please select a valid reporting manager.';
                    }
                }
            }
            if ($error === '') {
                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );
                $roleJson = json_encode(
                    $selectedRoles,
                    JSON_UNESCAPED_UNICODE
                );
                if ($roleJson === false) {
                    $error = 'Unable to process the selected roles.';
                } else {
                    $stmt = $conn->prepare(
                        'INSERT INTO users
                        (
                            brand_name,
                            brand_logo,
                            fullname,
                            prof_image,
                            contact,
                            email,
                            password,
                            role,
                            reporting_manager
                        )
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    if (!$stmt) {
                        $error = 'Unable to prepare user creation.';
                    } else {
                        $stmt->bind_param(
                            'sssssssss',
                            $brandName,
                            $brandLogo,
                            $fullname,
                            $profileImage,
                            $contact,
                            $email,
                            $hashedPassword,
                            $roleJson,
                            $reportingManager
                        );
                        if ($stmt->execute()) {
                            $newUserId = $conn->insert_id;
                            $stmt->close();
                            header('Location: admin.php?page=users&success=1');
                            exit;
                        }
                        // Capture the database error before closing the statement.
                        $dbError = $stmt->error;
                        $dbErrorCode = $stmt->errno;
                        error_log(
                            'Add User Database Error [' .
                                $dbErrorCode .
                                ']: ' .
                                $dbError
                        );
                        $stmt->close();

                        $error = 'Unable to create user. Please check the PHP error log for the database error.';
                    }
                }
            }
            if ($error !== '') {
                removeUploadedUserFiles($uploadedPaths);
                $message = $error;
                $messageType = 'error';
            }
        }
    }
}
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $message = 'User created successfully.';
    $messageType = 'success';
}
if (
    isset($_GET['assignment_success']) &&
    $_GET['assignment_success'] === '1'
) {
    $message = 'Company assignments updated successfully.';
    $messageType = 'success';
}
if (isset($_GET['sop_success']) && $_GET['sop_success'] === '1') {
    $message = 'SOP saved successfully.';
    $messageType = 'success';
}
$companies = [];
$companyResult = $conn->query(
    'SELECT id, comp_name, comp_logo
     FROM companies
     ORDER BY comp_name ASC'
);
if ($companyResult) {
    while ($company = $companyResult->fetch_assoc()) {
        $companies[] = $company;
    }
    $companyResult->free();
} else {
    $listError = 'Unable to load companies.';
}
$assignedCompaniesBySme = [];
$assignedCompanyOwners = [];
$assignmentResult = $conn->query(
    'SELECT
        a.sme_user_id,
        a.company_id,
        u.fullname AS sme_name
     FROM sme_company_assignments AS a
     INNER JOIN users AS u
        ON u.id = a.sme_user_id'
);
if ($assignmentResult) {
    while ($assignment = $assignmentResult->fetch_assoc()) {
        $smeId = (int) $assignment['sme_user_id'];
        $companyId = (int) $assignment['company_id'];
        $assignedCompaniesBySme[$smeId][] = $companyId;
        $assignedCompanyOwners[$companyId] = [
            'sme_user_id' => $smeId,
            'sme_name' => $assignment['sme_name']
        ];
    }
    $assignmentResult->free();
} else {
    $listError = 'Unable to load company assignments.';
}
$sopsByCompany = [];
$sopResult = $conn->query(
    'SELECT
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
     ORDER BY cs.sop_date ASC, cs.id ASC'
);
if ($sopResult) {
    while ($sop = $sopResult->fetch_assoc()) {
        $companyId = (int) $sop['company_id'];
        $sopsByCompany[$companyId][] = $sop;
    }
    $sopResult->free();
} else {
    $listError = 'Unable to load SOPs.';
}
$reportingManagers = [];
$managerResult = $conn->query(
    "SELECT id, fullname, email
     FROM users
     WHERE JSON_VALID(role)
       AND JSON_CONTAINS(
           role,
           '\"reporting_manager\"'
       )
     ORDER BY fullname ASC"
);
if ($managerResult) {
    while ($manager = $managerResult->fetch_assoc()) {
        $reportingManagers[] = $manager;
    }
    $managerResult->free();
} else {
    $listError = 'Unable to load reporting managers.';
}
$users = [];
$userResult = $conn->query(
    'SELECT
        id,
        brand_name,
        brand_logo,
        fullname,
        prof_image,
        contact,
        email,
        role,
        reporting_manager
     FROM users
     ORDER BY id ASC'
);
if ($userResult) {
    while ($row = $userResult->fetch_assoc()) {
        $roles = json_decode(
            (string) ($row['role'] ?? '[]'),
            true
        );
        if (!is_array($roles)) {
            $roles = [];
        }
        $roles = array_map(
            static function ($role): string {
                return strtolower(trim((string) $role));
            },
            array_filter($roles, 'is_string')
        );
        // Keep admin accounts visible too; the Add User form permits the ADMIN role.
        $row['_decoded_roles'] = $roles;
        $users[] = $row;
    }
    $userResult->free();
} else {
    $listError = 'Unable to load users.';
}
ob_start();
?>
<link
    rel="stylesheet"
    href="../assets/css/users.css">
<div class="users-section">
    <div class="users-header">
        <div>
            <h2>Users</h2>
            <p>Manage user accounts, roles and company assignments.</p>
        </div>
        <button type="button" id="openAddUserModal">
            Add User
        </button>
    </div>
    <?php if ($message !== ''): ?>
        <div
            class="alert <?= e($messageType) ?>"
            role="status">
            <?= e($message) ?>
        </div>
    <?php endif; ?>
    <?php if ($listError !== ''): ?>
        <p role="alert"><?= e($listError) ?></p>
    <?php else: ?>
        <div class="users-table-container">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Brand</th>
                        <th>Full Name</th>
                        <th>Reporting Manager</th>
                        <th>Contact</th>
                        <th>Email</th>
                        <th>Roles</th>
                        <th>Assigned Companies</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="9">No users found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $row): ?>
                            <?php
                            $userId = (int) $row['id'];
                            $userRoles = $row['_decoded_roles'];
                            $isSme = hasSmeRole($userRoles);
                            $assignedIds = array_values(
                                array_unique(
                                    array_map(
                                        'intval',
                                        $assignedCompaniesBySme[$userId] ?? []
                                    )
                                )
                            );
                            $assignedNames = [];
                            foreach ($companies as $company) {
                                if (
                                    in_array(
                                        (int) $company['id'],
                                        $assignedIds,
                                        true
                                    )
                                ) {
                                    $assignedNames[] = $company['comp_name'];
                                }
                            }
                            $smeSops = [];
                            if ($isSme) {
                                foreach ($assignedIds as $assignedCompanyId) {
                                    foreach (
                                        $sopsByCompany[$assignedCompanyId] ?? []
                                        as $sop
                                    ) {
                                        $smeSops[] = $sop;
                                    }
                                }
                            }
                            $assignedIdsJson = json_encode($assignedIds);
                            $smeSopsJson = json_encode(
                                $smeSops,
                                JSON_HEX_APOS |
                                    JSON_HEX_QUOT |
                                    JSON_HEX_TAG |
                                    JSON_HEX_AMP
                            );
                            ?>
                            <tr>
                                <td data-label="ID"><?= $userId ?></td>
                                <td data-label="Brand">
                                    <?php if (!empty($row['brand_logo'])): ?>
                                        <img
                                            src="../uploads/<?= e(basename($row['brand_logo'])) ?>"
                                            alt="Brand logo"
                                            width="40"
                                            height="40"
                                            loading="lazy">
                                    <?php endif; ?>
                                    <?= e($row['brand_name'] ?? '') ?>
                                </td>
                                <td data-label="Full Name">
                                    <?php if (!empty($row['prof_image'])): ?>
                                        <img
                                            src="../uploads/<?= e(basename($row['prof_image'])) ?>"
                                            alt="Profile image"
                                            width="40"
                                            height="40"
                                            loading="lazy">
                                    <?php endif; ?>
                                    <?= e($row['fullname'] ?? '') ?>
                                </td>
                                <td data-label="Manager"> <?= e($row['reporting_manager'] ?? '') ?> </td>
                                <td data-label="Contact"><?= e($row['contact'] ?? '') ?></td>
                                <td data-label="Email"><?= e($row['email'] ?? '') ?></td>
                                <td data-label="Roles"><?= e(implode(', ', $userRoles)) ?></td>
                                <td data-label="Companies">
                                    <?php if ($isSme && !empty($assignedNames)): ?>
                                        <?= e(implode(', ', $assignedNames)) ?>
                                    <?php elseif ($isSme): ?>
                                        <span>No companies assigned</span>
                                    <?php else: ?>
                                        <span>Not applicable</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Action">
                                    <?php if ($isSme): ?>
                                        <button
                                            type="button"
                                            class="assign-company-button"
                                            data-user-id="<?= $userId ?>"
                                            data-user-name="<?= e($row['fullname'] ?? '') ?>"
                                            data-assigned-company-ids="<?= e($assignedIdsJson ?: '[]') ?>">
                                            Assign Company
                                        </button>
                                        <button
                                            type="button"
                                            class="manage-sops-button"
                                            data-user-id="<?= $userId ?>"
                                            data-user-name="<?= e($row['fullname'] ?? '') ?>"
                                            data-assigned-company-ids="<?= e($assignedIdsJson ?: '[]') ?>"
                                            data-sops="<?= e($smeSopsJson ?: '[]') ?>">
                                            Manage SOPs
                                        </button>
                                    <?php else: ?>
                                        <span>SME only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<!-- =========================================================
     ADD USER MODAL
========================================================= -->
<div
    class="modal"
    id="addUserModal"
    aria-hidden="true"
    role="dialog"
    aria-modal="true"
    aria-labelledby="addUserModalTitle"
    hidden>
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="addUserModalTitle">Add User</h2>
            <button
                type="button"
                id="closeAddUserModal"
                aria-label="Close modal">
                &times;
            </button>
        </div>
        <form
            id="addUserForm"
            action="admin.php?page=users"
            method="POST"
            enctype="multipart/form-data">
            <input type="hidden" name="add_user" value="1">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['users_csrf_token']) ?>">
            <div class="form-group">
                <label for="brand_name">Brand Name *</label>
                <input
                    type="text"
                    id="brand_name"
                    name="brand_name"
                    maxlength="255"
                    required>
            </div>
            <div class="form-group">
                <label for="brand_logo">Brand Logo</label>
                <input
                    type="file"
                    id="brand_logo"
                    name="brand_logo"
                    accept="image/jpeg,image/png,image/webp,image/gif">
            </div>
            <div class="form-group">
                <label for="fullname">Full Name *</label>
                <input
                    type="text"
                    id="fullname"
                    name="fullname"
                    maxlength="255"
                    required>
            </div>
            <div class="form-group">
                <label for="prof_image">Profile Image</label>
                <input
                    type="file"
                    id="prof_image"
                    name="prof_image"
                    accept="image/jpeg,image/png,image/webp,image/gif">
            </div>
            <div class="form-group">
                <label for="contact">Contact *</label>
                <input
                    type="tel"
                    id="contact"
                    name="contact"
                    maxlength="13"
                    required>
            </div>
            <div class="form-group">
                <label for="email">Email *</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="255"
                    required>
            </div>
            <div class="form-group">
                <label for="password">Password *</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    minlength="8"
                    autocomplete="new-password"
                    required>
            </div>
            <div class="form-group">
                <label for="reporting_manager">Reporting Manager *</label>
                <select
                    id="reporting_manager"
                    name="reporting_manager"
                    required>
                    <option value="">Select Reporting Manager</option>
                    <?php foreach ($reportingManagers as $manager): ?>
                        <option value="<?= e($manager['fullname']) ?>">
                            <?= e($manager['fullname']) ?>
                            (<?= e($manager['email']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($reportingManagers)): ?>
                    <small>
                        No reporting managers found. Please create a user with
                        the REPORTING_MANAGER role first.
                    </small>
                <?php endif; ?>
            </div>
            <fieldset class="form-group role-options">
                <legend>Assign Role(s) *</legend>
                <?php foreach ($allowedRoles as $role): ?>
                    <label>
                        <input
                            type="checkbox"
                            name="role[]"
                            value="<?= e($role) ?>">
                        <?= e(strtoupper($role)) ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <p id="usersFormError" role="alert" hidden></p>
            <div class="modal-actions">
                <button type="button" id="cancelAddUser">
                    Cancel
                </button>
                <button type="submit" id="saveUserButton">
                    Save User
                </button>
            </div>
        </form>
    </div>
</div>
<!-- =========================================================
     ASSIGN COMPANY MODAL
========================================================= -->
<div
    class="modal"
    id="assignCompanyModal"
    aria-hidden="true"
    role="dialog"
    aria-modal="true"
    aria-labelledby="assignCompanyModalTitle"
    hidden>
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="assignCompanyModalTitle">Assign Companies</h2>
            <button
                type="button"
                id="closeAssignCompanyModal"
                aria-label="Close modal">
                &times;
            </button>
        </div>
        <form
            id="assignCompanyForm"
            action="admin.php?page=users"
            method="POST">
            <input type="hidden" name="assign_companies" value="1">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['users_csrf_token']) ?>">
            <input
                type="hidden"
                name="sme_user_id"
                id="assignSmeUserId">
            <p class="assign-intro">
                Select up to 5 companies for
                <strong id="assignSmeName"></strong>.
            </p>
            <p class="assign-count">
                Selected:
                <strong id="assignedCompanyCount">0</strong>/5
            </p>
            <div class="company-dropdown" id="companyDropdown">
                <button
                    type="button"
                    class="company-dropdown-toggle"
                    id="companyDropdownToggle"
                    aria-haspopup="listbox"
                    aria-expanded="false"
                    aria-controls="companyDropdownPanel">
                    <span
                        class="company-dropdown-label is-placeholder"
                        id="companyDropdownLabel">
                        Select companies
                    </span>
                    <span class="company-dropdown-caret" aria-hidden="true"></span>
                </button>
                <div
                    class="company-dropdown-panel"
                    id="companyDropdownPanel"
                    hidden>
                    <input
                        type="search"
                        id="companySearch"
                        class="company-dropdown-search"
                        placeholder="Search companies..."
                        autocomplete="off"
                        aria-label="Search companies">
                    <div id="assignCompanyOptions" role="listbox" aria-multiselectable="true">
                        <?php foreach ($companies as $company): ?>
                            <?php
                            $companyId = (int) $company['id'];
                            $owner = $assignedCompanyOwners[$companyId] ?? null;
                            ?>
                            <label class="assign-company-option">
                                <input
                                    type="checkbox"
                                    name="company_ids[]"
                                    value="<?= $companyId ?>"
                                    data-owner-id="<?= $owner
                                                        ? (int) $owner['sme_user_id']
                                                        : '' ?>">
                                <span class="assign-company-name"><?= e($company['comp_name'] ?? '') ?></span>
                                <?php if ($owner): ?>
                                    <small class="assign-company-owner">
                                        Assigned to <?= e($owner['sme_name'] ?? '') ?>
                                    </small>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                        <?php if (empty($companies)): ?>
                            <p class="company-dropdown-empty">No companies found. Add companies first.</p>
                        <?php endif; ?>
                        <p class="company-dropdown-empty" id="companyNoMatch" hidden>No matching companies.</p>
                    </div>
                </div>
            </div>
            <div class="company-chips" id="companyChips"></div>
            <p id="assignCompanyError" role="alert" hidden></p>
            <div class="modal-actions">
                <button type="button" id="cancelAssignCompany">
                    Cancel
                </button>
                <button type="submit" id="saveCompanyAssignments">
                    Save Assignments
                </button>
            </div>
        </form>
    </div>
</div>
<!-- =========================================================
     MANAGE SOPs MODAL
========================================================= -->
<div
    class="modal"
    id="manageSopsModal"
    aria-hidden="true"
    role="dialog"
    aria-modal="true"
    aria-labelledby="manageSopsTitle"
    hidden>
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="manageSopsTitle">Manage SOPs</h2>
            <button
                type="button"
                id="closeManageSopsModal"
                aria-label="Close modal">
                &times;
            </button>
        </div>
        <p>
            SME: <strong id="sopSmeName"></strong>
        </p>
        <form
            id="manageSopForm"
            action="admin.php?page=users"
            method="POST">
            <input type="hidden" name="save_sop" value="1">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['users_csrf_token']) ?>">
            <input
                type="hidden"
                name="sme_user_id"
                id="sopSmeUserId">
            <input
                type="hidden"
                name="sop_id"
                id="sopId"
                value="0">
            <div class="form-group">
                <label for="sopCompany">Company *</label>
                <select name="company_id" id="sopCompany" required>
                    <option value="">Select Company</option>
                    <?php foreach ($companies as $company): ?>
                        <option value="<?= (int) $company['id'] ?>">
                            <?= e($company['comp_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="sopType">SOP Type *</label>
                <select name="sop_type" id="sopType" required>
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                </select>
            </div>
            <div class="form-group">
                <label for="sopTitle">Enter Daily SOP *</label>
                <textarea
                    name="sop_title"
                    id="sopTitle"
                    rows="4"
                    maxlength="10000"
                    placeholder="Enter daily SOP..."
                    required></textarea>
            </div>
            <div class="form-group">
                <label for="sopDate">SOP Date *</label>
                <input
                    type="date"
                    name="sop_date"
                    id="sopDate"
                    required>
            </div>
            <p id="manageSopError" role="alert" hidden></p>
            <div class="modal-actions">
                <button type="button" id="resetSopForm">
                    Clear Form
                </button>
                <button type="submit" id="saveSopButton">
                    Add SOP
                </button>
            </div>
        </form>
        <hr>
        <h3>Existing SOPs</h3>
        <div id="existingSopsList">
            <p>Select an SME to view assigned SOPs.</p>
        </div>
    </div>
</div>
<script src="../assets/js/users.js" defer></script>