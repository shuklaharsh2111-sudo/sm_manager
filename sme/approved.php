<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isAjax = isset($_GET['approved_api']) || isset($_POST['approved_api']);
if (empty($_SESSION['user_id'])) {
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Please log in again.'
        ]);
        exit;
    }
    echo '<p>Please log in to view approved creatives.</p>';
    return;
}
require_once __DIR__ . '/../config/db.php';
$db = $conn ?? null;
if (!$db instanceof mysqli) {
    if ($isAjax) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed.'
        ]);
        exit;
    }
    echo '<p>Database connection failed.</p>';
    return;
}
$db->set_charset('utf8mb4');
/* Create the assignment table automatically. */
$db->query("
    CREATE TABLE IF NOT EXISTS creative_des_assignments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        creative_id INT NOT NULL UNIQUE,
        smi_user_id INT NOT NULL,
        assigned_by INT NOT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
/* Return JSON for assignment requests. */
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid request.'
        ]);
        exit;
    }
    $creativeId = (int)($_POST['creative_id'] ?? 0);
    $smiId = (int)($_POST['smi_user_id'] ?? 0);
    if ($creativeId < 1 || $smiId < 1) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Please select an SMI employee.'
        ]);
        exit;
    }
    /* Make sure the creative is approved. */
    $stmt = $db->prepare("
        SELECT id
        FROM creatives
        WHERE id = ?
          AND LOWER(TRIM(status)) = 'approved'
        LIMIT 1
    ");
    $stmt->bind_param('i', $creativeId);
    $stmt->execute();
    $creative = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$creative) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Approved creative not found.'
        ]);
        exit;
    }
    /* Make sure the selected user has the SMI role. */
    $stmt = $db->prepare("
        SELECT id, role
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $smiId);
    $stmt->execute();
    $smi = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $roles = json_decode($smi['role'] ?? '', true);
    if (!is_array($roles)) {
        $roles = [$smi['role'] ?? ''];
    }
    $roles = array_map(
        fn($role) => strtolower(trim((string)$role)),
        $roles
    );
    if (!$smi || !in_array('des', $roles, true)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Please select a valid DES employee.'
        ]);
        exit;
    }
    /* Save the assignment, or update the existing assignment. */
    $assignedBy = (int)$_SESSION['user_id'];
    $stmt = $db->prepare("
        INSERT INTO creative_des_assignments
            (creative_id, smi_user_id, assigned_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            smi_user_id = VALUES(smi_user_id),
            assigned_by = VALUES(assigned_by),
            assigned_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param('iii', $creativeId, $smiId, $assignedBy);
    if (!$stmt->execute()) {
        $stmt->close();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Assignment could not be saved.'
        ]);
        exit;
    }
    $stmt->close();
    echo json_encode([
        'success' => true,
        'message' => 'Employee assigned successfully.'
    ]);
    exit;
}
/* Fetch approved creatives and their current assignments. */
$creatives = [];
$sql = "
    SELECT
        c.id,
        c.user_id,
        c.company_id,
        c.platform,
        c.creative_date,
        c.title,
        c.caption,
        c.description,
        c.image_path,
        c.reference_link,
        u.fullname AS employee_name,
        co.comp_name AS company_name,
        a.smi_user_id,
        su.fullname AS assigned_smi_name
    FROM creatives c
    LEFT JOIN users u ON u.id = c.user_id
    LEFT JOIN companies co ON co.id = c.company_id
    LEFT JOIN creative_des_assignments a ON a.creative_id = c.id
    LEFT JOIN users su ON su.id = a.smi_user_id
    WHERE LOWER(TRIM(COALESCE(c.status, ''))) = 'approved'
    ORDER BY c.creative_date DESC, c.id DESC
";
$result = $db->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $creatives[] = $row;
    }
}
/* Fetch only SMI employees for the dropdown. */
/* Fetch only DES employees for the dropdown. */
$desUsers = [];
$result = $db->query("
    SELECT id, fullname, email, role
    FROM users
    ORDER BY fullname ASC
");
if ($result) {
    while ($user = $result->fetch_assoc()) {
        $roles = json_decode($user['role'] ?? '', true);
        if (!is_array($roles)) {
            $roles = [$user['role'] ?? ''];
        }
        $roles = array_map(
            fn($role) => strtolower(trim((string)$role)),
            $roles
        );
        if (in_array('des', $roles, true)) {
            $desUsers[] = $user;
        }
    }
}
?>
<link rel="stylesheet" href="/usp/sme/css/approved.css">
<div class="approved-page" id="approvedPage"
    data-endpoint="/usp/sme/approved.php">
    <div class="ap-hero">
        <div class="ap-hero-copy">
            <span class="ap-eyebrow">CREATIVE WORKSPACE</span>
            <h1>Approved <span>Creatives</span></h1>
            <p>Select an SMI employee and assign the approved creative.</p>
        </div>
        <div class="ap-total">
            <div>
                <strong><?= count($creatives) ?></strong>
                <small>Approved creatives</small>
            </div>
        </div>
    </div>
    <div class="ap-toolbar">
        <label class="ap-search">
            <span aria-hidden="true">⌕</span>
            <input id="apSearch" type="search"
                placeholder="Search creative, employee or company..."
                autocomplete="off">
        </label>
        <select id="apPlatform" aria-label="Filter platform">
            <option value="">All platforms</option>
            <?php foreach (['instagram', 'facebook', 'linkedin', 'google', 'youtube', 'x', 'pinterest', 'threads'] as $platform): ?>
                <option value="<?= htmlspecialchars($platform, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars(ucfirst($platform), ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select id="apAssignment" aria-label="Filter assignment">
            <option value="">All assignments</option>
            <option value="assigned">Assigned</option>
            <option value="unassigned">Not assigned</option>
        </select>
        <span class="ap-results" id="apResults">
            <?= count($creatives) ?> items
        </span>
    </div>
    <div class="ap-grid" id="apGrid">
        <?php foreach ($creatives as $c):
            $img = trim((string)($c['image_path'] ?? ''));
            if ($img !== '') {
                $img = str_replace('\\', '/', $img);
                if (!preg_match('~^(https?:)?//~i', $img)) {
                    $img = preg_replace('~^(\./)+~', '', $img);
                    if (strpos($img, '/') === false) {
                        $img = 'uploads/creatives/' . $img;
                    }
                    if (strpos($img, '/') !== 0) {
                        $img = '/usp/' . ltrim($img, '/');
                    }
                }
            }
            $platform = strtolower((string)($c['platform'] ?? ''));
            $assigned = !empty($c['smi_user_id']);
            $searchText = strtolower(implode(' ', [
                $c['title'] ?? '',
                $c['employee_name'] ?? '',
                $c['company_name'] ?? '',
                $platform
            ]));
        ?>
            <article class="ap-card"
                data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>"
                data-platform="<?= htmlspecialchars($platform, ENT_QUOTES, 'UTF-8') ?>"
                data-assigned="<?= $assigned ? 'assigned' : 'unassigned' ?>">
                <div class="ap-preview">
                    <?php if ($img !== ''): ?>
                        <img src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>"
                            alt="<?= htmlspecialchars((string)($c['title'] ?? 'Creative'), ENT_QUOTES, 'UTF-8') ?>"
                            loading="lazy">
                    <?php else: ?>
                        <div class="ap-no-image">
                            <span>▧</span>
                            <small>No creative preview</small>
                        </div>
                    <?php endif; ?>
                    <span class="ap-approved-badge">✓ Approved</span>
                    <span class="ap-platform">
                        <?= htmlspecialchars(ucfirst($platform), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <div class="ap-card-body">
                    <div class="ap-card-heading">
                        <div>
                            <span class="ap-card-kicker">CREATIVE #<?= (int)$c['id'] ?></span>
                            <h2><?= htmlspecialchars((string)($c['title'] ?: 'Untitled Creative'), ENT_QUOTES, 'UTF-8') ?></h2>
                        </div>
                        <span class="ap-checkmark">✓</span>
                    </div>
                    <div class="ap-meta">
                        <span><?= htmlspecialchars((string)($c['employee_name'] ?: 'Unknown employee'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span><?= htmlspecialchars((string)($c['company_name'] ?: 'No company'), ENT_QUOTES, 'UTF-8') ?></span>
                        <span><?= htmlspecialchars((string)($c['creative_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <?php if (!empty($c['caption']) || !empty($c['description'])): ?>
                        <p class="ap-caption">
                            <?= htmlspecialchars((string)($c['caption'] ?: $c['description']), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($c['reference_link'])): ?>
                        <a class="ap-reference"
                            href="<?= htmlspecialchars((string)$c['reference_link'], ENT_QUOTES, 'UTF-8') ?>"
                            target="_blank" rel="noopener noreferrer">
                            View reference ↗
                        </a>
                    <?php endif; ?>
                    <div class="ap-assign-area">
                        <label for="ap-smi-<?= (int)$c['id'] ?>">Assign to SMI</label>
                        <div class="ap-assign-row">
                            <select class="ap-smi-select"
                                id="ap-smi-<?= (int)$c['id'] ?>"
                                data-creative-id="<?= (int)$c['id'] ?>">
                                <option value="">Choose DES employee</option>
                                <?php foreach ($desUsers as $des): ?>
                                    <option value="<?= (int)$des['id'] ?>"
                                        <?= (int)$c['smi_user_id'] === (int)$des['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(
                                            (string)($des['fullname'] ?: $des['email'] ?: 'DES #' . $des['id']),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="ap-assign-btn"
                                type="button"
                                data-creative-id="<?= (int)$c['id'] ?>">
                                Assign
                            </button>
                        </div>
                        <p class="ap-assigned-label">
                            <?php if ($assigned): ?>
                                Currently assigned to:
                                <?= htmlspecialchars((string)($c['assigned_smi_name'] ?? 'SMI employee'), ENT_QUOTES, 'UTF-8') ?>
                            <?php else: ?>
                                Not assigned yet
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <div class="ap-empty" id="apEmpty" <?= count($creatives) ? 'hidden' : '' ?>>
        <h3>No approved creatives found</h3>
        <p>Approved creatives will appear here once they are available.</p>
    </div>
    <div class="ap-toast" id="apToast" role="status" aria-live="polite"></div>
</div>
<script src="/usp/sme/js/approved.js" defer></script>