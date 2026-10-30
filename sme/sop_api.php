<?php
/*
|--------------------------------------------------------------------------
| SME SOP TICK API
|--------------------------------------------------------------------------
| POST sop_id, checked (1 = tick and save, 0 = untick), csrf_token
| Only SOPs of companies assigned to the signed-in SME can be ticked.
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: application/json; charset=UTF-8');

function sopOut(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$isListRequest = $_SERVER['REQUEST_METHOD'] === 'GET'
    && (string) ($_GET['action'] ?? '') === 'list';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$isListRequest) {
    sopOut(405, ['success' => false, 'message' => 'Invalid request.']);
}
$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
$sessionRole = $_SESSION['role'] ?? '';
if (is_array($sessionRole)) {
    $roles = $sessionRole;
} else {
    $decoded = json_decode((string) $sessionRole, true);
    $roles = is_array($decoded) ? $decoded : [(string) $sessionRole];
}
$roles = array_map(static fn($r) => strtolower(trim((string) $r)), $roles);
if (!$userId || !in_array('sme', $roles, true)) {
    sopOut(401, ['success' => false, 'message' => 'Session expired. Please log in again.']);
}

// GET ?action=list : fresh SOP boxes (used by the dashboard auto-refresh, so SOPs
// the admin assigns show up without reloading the page).
if ($isListRequest) {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/sop_helpers.php';
    smeSopTimezone();
    try {
        smeSopEnsureTable($conn);
        $active = (string) ($_GET['active'] ?? 'daily');
        $sops = smeSopFetchAssigned($conn, (int) $userId);
        $done = smeSopFetchCurrentDone($conn, (int) $userId);
        sopOut(200, [
            'success' => true,
            'html' => smeSopRenderTabs($sops, $done, $active),
            'total' => count($sops['daily']) + count($sops['weekly']) + count($sops['monthly']),
        ]);
    } catch (Throwable $e) {
        error_log('SME SOP list error: ' . $e->getMessage());
        sopOut(500, ['success' => false, 'message' => 'Unable to load SOPs.']);
    }
}

$token = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['meeting_csrf_token'] ?? '');
if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
    sopOut(403, ['success' => false, 'message' => 'Security check failed. Please refresh the page.']);
}

$sopId = filter_var($_POST['sop_id'] ?? null, FILTER_VALIDATE_INT);
$checked = (string) ($_POST['checked'] ?? '1') === '1';
if (!$sopId || $sopId <= 0) {
    sopOut(422, ['success' => false, 'message' => 'Invalid SOP.']);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/sop_helpers.php';
smeSopTimezone();

try {
    smeSopEnsureTable($conn);

    // The SOP must belong to a company assigned to this SME.
    $stmt = $conn->prepare(
        'SELECT cs.id, cs.company_id, cs.sop_type, cs.sop_title, c.comp_name
         FROM company_sops AS cs
         INNER JOIN companies AS c ON c.id = cs.company_id
         INNER JOIN sme_company_assignments AS sca ON sca.company_id = c.id
         WHERE cs.id = ? AND sca.sme_user_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('ii', $sopId, $userId);
    $stmt->execute();
    $sop = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$sop) {
        sopOut(404, ['success' => false, 'message' => 'This SOP is not assigned to you.']);
    }

    $type = (string) $sop['sop_type'];
    $periodKey = smeSopPeriodKey($type);

    if ($checked) {
        $title = (string) $sop['sop_title'];
        $company = (string) $sop['comp_name'];
        $companyId = (int) $sop['company_id'];
        $ins = $conn->prepare(
            'INSERT INTO sme_sop_completions
                (sme_user_id, sop_id, company_id, sop_type, period_key, sop_title, company_name)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sop_id = sop_id'
        );
        $ins->bind_param('iiissss', $userId, $sopId, $companyId, $type, $periodKey, $title, $company);
        $ins->execute();
        $ins->close();
    } else {
        // Untick = undo for the current day / week / month only. Older history stays.
        $del = $conn->prepare(
            'DELETE FROM sme_sop_completions
             WHERE sme_user_id = ? AND sop_id = ? AND period_key = ?'
        );
        $del->bind_param('iis', $userId, $sopId, $periodKey);
        $del->execute();
        $del->close();
    }

    sopOut(200, [
        'success' => true,
        'checked' => $checked,
        'message' => $checked ? 'Saved to your Saved list.' : 'Tick removed.',
        'saved_time' => $checked ? date('h:i A') : '',
    ]);
} catch (Throwable $e) {
    error_log('SME SOP tick error: ' . $e->getMessage());
    sopOut(500, ['success' => false, 'message' => 'Unable to save. Please try again.']);
}
