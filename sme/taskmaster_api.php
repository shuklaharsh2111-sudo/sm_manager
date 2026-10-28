<?php
/*
|--------------------------------------------------------------------------
| SME TASK MASTER API
|--------------------------------------------------------------------------
| Handles file / image / link items for the Pitch Deck and Mood Board cards.
| Actions: list, upload, add_link, delete (POST) and download, view (GET).
| Every item belongs to the logged-in SME; nobody else can read or delete it.
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function tmOut(int $status, array $data): void
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// ---- Authentication -------------------------------------------------------
if (empty($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    tmOut(401, ['success' => false, 'message' => 'Session expired. Please log in again.']);
}
$sessionRole = $_SESSION['role'];
if (is_array($sessionRole)) {
    $roles = $sessionRole;
} else {
    $decoded = json_decode((string) $sessionRole, true);
    $roles = is_array($decoded) ? $decoded : [(string) $sessionRole];
}
$roles = array_map(static fn($r) => strtolower(trim((string) $r)), $roles);
if (!in_array('sme', $roles, true)) {
    tmOut(403, ['success' => false, 'message' => 'Only SME users can use Task Master.']);
}
$userId = (int) $_SESSION['user_id'];

require_once __DIR__ . '/../config/db.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    tmOut(500, ['success' => false, 'message' => 'Database connection not available.']);
}

// ---- Table (created automatically; see sme_taskmaster_migration.sql) --------
$conn->query(
    "CREATE TABLE IF NOT EXISTS `sme_taskmaster_items` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `card` varchar(10) NOT NULL,
      `title` varchar(150) NOT NULL DEFAULT '',
      `link_url` varchar(2000) DEFAULT NULL,
      `stored_name` varchar(80) DEFAULT NULL,
      `original_name` varchar(255) DEFAULT NULL,
      `file_size` int(11) NOT NULL DEFAULT 0,
      `is_image` tinyint(1) NOT NULL DEFAULT 0,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_user_card` (`user_id`,`card`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

// ---- Rules ------------------------------------------------------------------
const TM_MAX_BYTES = 20 * 1024 * 1024;
$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$docExts   = ['pdf', 'ppt', 'pptx', 'key', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'zip'];
$rules = [
    'pitch' => array_merge($docExts, $imageExts),
    'mood'  => array_merge($imageExts, ['pdf', 'ppt', 'pptx', 'zip']),
];
$mimeByExt = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
];

$uploadDir = __DIR__ . '/../uploads/taskmaster';
function tmEnsureDir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        tmOut(500, ['success' => false, 'message' => 'Unable to create the upload folder.']);
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        // Files are served only through this API (ownership checked).
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
}

/** Validate and store an uploaded file. Returns [stored, original, size, isImage]. */
function tmStore(string $field, array $allowedExts, string $dir, bool $mustBeImage = false): array
{
    global $imageExts;
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        tmOut(422, ['success' => false, 'message' => 'Please choose a file.']);
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $msg = in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'File is larger than the server upload limit (check upload_max_filesize / post_max_size in php.ini).'
            : 'The file could not be uploaded. Please try again.';
        tmOut(422, ['success' => false, 'message' => $msg]);
    }
    if ($f['size'] > TM_MAX_BYTES) {
        tmOut(422, ['success' => false, 'message' => 'File is too large (max 20 MB).']);
    }
    $orig = trim(basename(str_replace('\\', '/', (string) $f['name'])));
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = $mustBeImage ? $imageExts : $allowedExts;
    if ($ext === '' || !in_array($ext, $allowed, true)) {
        tmOut(422, ['success' => false, 'message' => 'File type not allowed. Allowed: ' . implode(', ', $allowed) . '.']);
    }
    $isImage = in_array($ext, $imageExts, true);
    if ($isImage && @getimagesize($f['tmp_name']) === false) {
        tmOut(422, ['success' => false, 'message' => 'The selected file is not a valid image.']);
    }
    tmEnsureDir($dir);
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
        tmOut(500, ['success' => false, 'message' => 'Unable to save the uploaded file.']);
    }
    return [$stored, mb_substr($orig, 0, 255), (int) $f['size'], $isImage];
}

function tmItem(array $r): array
{
    $hasFile = !empty($r['stored_name']);
    $base = 'taskmaster_api.php?id=' . (int) $r['id'] . '&action=';
    return [
        'id'            => (int) $r['id'],
        'card'          => $r['card'],
        'title'         => $r['title'],
        'link_url'      => $r['link_url'],
        'original_name' => $r['original_name'],
        'file_size'     => (int) $r['file_size'],
        'is_image'      => (bool) $r['is_image'],
        'has_file'      => $hasFile,
        'view_url'      => ($hasFile && $r['is_image']) ? $base . 'view' : null,
        'download_url'  => $hasFile ? $base . 'download' : null,
    ];
}

function tmFetch(mysqli $conn, int $id, int $userId): ?array
{
    $st = $conn->prepare('SELECT * FROM sme_taskmaster_items WHERE id = ? AND user_id = ? LIMIT 1');
    $st->bind_param('ii', $id, $userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

$action = (string) ($_REQUEST['action'] ?? 'list');
$card   = (string) ($_REQUEST['card'] ?? '');

// ---- Downloads / inline images (GET) ----------------------------------------
if ($action === 'download' || $action === 'view') {
    $row = tmFetch($conn, (int) ($_GET['id'] ?? 0), $userId);
    $path = $row && $row['stored_name'] ? $uploadDir . '/' . basename($row['stored_name']) : '';
    if ($path === '' || !is_file($path)) {
        http_response_code(404);
        exit('File not found.');
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($action === 'view') {
        if (!$row['is_image']) {
            http_response_code(404);
            exit('Not an image.');
        }
        header('Content-Type: ' . ($mimeByExt[$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: inline');
        header('Cache-Control: private, max-age=3600');
    } else {
        $name = $row['original_name'] ?: ('download.' . $ext);
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=\"" . addslashes(preg_replace('/[^\w.\- ]+/u', '_', $name)) . "\"; filename*=UTF-8''" . rawurlencode($name));
    }
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ---- JSON actions -----------------------------------------------------------
try {
    if ($action === 'list') {
        if (!isset($rules[$card])) {
            tmOut(400, ['success' => false, 'message' => 'Invalid card.']);
        }
        $st = $conn->prepare('SELECT * FROM sme_taskmaster_items WHERE user_id = ? AND card = ? ORDER BY id DESC');
        $st->bind_param('is', $userId, $card);
        $st->execute();
        $items = [];
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $items[] = tmItem($r);
        }
        $st->close();
        tmOut(200, ['success' => true, 'items' => $items]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        tmOut(405, ['success' => false, 'message' => 'Only POST requests are allowed.']);
    }

    if ($action === 'upload' || $action === 'add_link') {
        if (!isset($rules[$card])) {
            tmOut(400, ['success' => false, 'message' => 'Invalid card.']);
        }
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 150);
        $url = null; $stored = null; $orig = null; $size = 0; $isImage = 0;

        if ($action === 'upload') {
            [$stored, $orig, $size, $isImg] = tmStore('file', $rules[$card], $uploadDir);
            $isImage = $isImg ? 1 : 0;
            if ($title === '') {
                $title = pathinfo($orig, PATHINFO_FILENAME);
            }
        } else {
            $url = trim((string) ($_POST['link_url'] ?? ''));
            $parts = parse_url($url);
            if (
                $url === '' || strlen($url) > 2000 || !$parts ||
                !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) ||
                empty($parts['host'])
            ) {
                tmOut(422, ['success' => false, 'message' => 'Enter a valid link starting with http:// or https://']);
            }
            if ($title === '') {
                $title = $parts['host'];
            }
            // Optional image shown with the link (Mood Board).
            if ($card === 'mood' && !empty($_FILES['image']) && ($_FILES['image']['error'] ?? 0) !== UPLOAD_ERR_NO_FILE) {
                [$stored, $orig, $size, ] = tmStore('image', [], $uploadDir, true);
                $isImage = 1;
            }
        }

        $st = $conn->prepare(
            'INSERT INTO sme_taskmaster_items
             (user_id, card, title, link_url, stored_name, original_name, file_size, is_image)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->bind_param('isssssii', $userId, $card, $title, $url, $stored, $orig, $size, $isImage);
        if (!$st->execute()) {
            if ($stored) { @unlink($uploadDir . '/' . $stored); }
            tmOut(500, ['success' => false, 'message' => 'Unable to save the item.']);
        }
        $newId = $st->insert_id;
        $st->close();
        tmOut(200, ['success' => true, 'item' => tmItem(tmFetch($conn, $newId, $userId))]);
    }

    if ($action === 'delete') {
        $row = tmFetch($conn, (int) ($_POST['id'] ?? 0), $userId);
        if (!$row) {
            tmOut(404, ['success' => false, 'message' => 'Item not found.']);
        }
        $st = $conn->prepare('DELETE FROM sme_taskmaster_items WHERE id = ? AND user_id = ?');
        $st->bind_param('ii', $row['id'], $userId);
        $st->execute();
        $st->close();
        if ($row['stored_name']) {
            @unlink($uploadDir . '/' . basename($row['stored_name']));
        }
        tmOut(200, ['success' => true]);
    }

    tmOut(400, ['success' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('taskmaster_api: ' . $e->getMessage());
    tmOut(500, ['success' => false, 'message' => 'Something went wrong. Please try again.']);
}
