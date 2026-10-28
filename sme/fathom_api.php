<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function fathomJson(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    fathomJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}

// Logged-in SME only.
$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
$sessionRole = $_SESSION['role'] ?? '';
$roles = is_array($sessionRole)
    ? $sessionRole
    : (is_array($decoded = json_decode((string) $sessionRole, true)) ? $decoded : [$sessionRole]);
$roles = array_map(static fn($r) => strtolower(trim((string) $r)), $roles);
if (!$userId || !in_array('sme', $roles, true)) {
    fathomJson(401, ['success' => false, 'message' => 'Please log in as an SME.']);
}

// Same CSRF token the meeting form uses.
$submitted = $_POST['csrf_token'] ?? '';
$expected = $_SESSION['meeting_csrf_token'] ?? '';
if (!is_string($submitted) || !is_string($expected) || $expected === '' || !hash_equals($expected, $submitted)) {
    fathomJson(403, ['success' => false, 'message' => 'Session verification failed. Refresh the page.']);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/fathom_helpers.php';

if (!fathomEnsureSchema($conn)) {
    fathomJson(500, ['success' => false, 'message' => 'Recording tables are not ready. Run fathom_migration.sql.']);
}

$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'sync') {
        $sync = fathomSync($conn);
        fathomJson($sync['ok'] ? 200 : 422, [
            'success' => $sync['ok'],
            'message' => $sync['message'],
            'count'   => $sync['count'],
        ]);
    }

    if ($action === 'transcript') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            fathomJson(422, ['success' => false, 'message' => 'Invalid recording.']);
        }
        $stmt = $conn->prepare('SELECT recording_id FROM sme_meeting_recordings WHERE id = ? AND sme_user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $id, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            fathomJson(404, ['success' => false, 'message' => 'Transcript unavailable.']);
        }
        [$status, $json, $error] = fathomRequest('/recordings/' . rawurlencode($row['recording_id']) . '/transcript');
        if ($status !== 200 || $json === null) {
            fathomJson(502, ['success' => false, 'message' => $error !== '' ? $error : 'Fathom returned HTTP ' . $status . '.']);
        }
        $lines = [];
        foreach (($json['transcript'] ?? $json['segments'] ?? []) as $seg) {
            $speaker = $seg['speaker']['display_name'] ?? $seg['speaker'] ?? '';
            $lines[] = [
                'time'    => (string) ($seg['timestamp'] ?? ''),
                'speaker' => is_string($speaker) ? $speaker : '',
                'text'    => (string) ($seg['text'] ?? ''),
            ];
        }
        fathomJson(200, ['success' => true, 'lines' => $lines]);
    }

    fathomJson(400, ['success' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('Fathom API error: ' . $e->getMessage());
    fathomJson(500, ['success' => false, 'message' => 'Something went wrong. Please try again.']);
}
