<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
$isAdmin = false;
$sessionRole = $_SESSION['role'] ?? '';
if (is_array($sessionRole)) {
    $isAdmin = in_array('admin', $sessionRole, true);
} elseif (is_string($sessionRole)) {
    $decodedRole = json_decode($sessionRole, true);
    $isAdmin = strtolower(trim($sessionRole)) === 'admin'
        || (is_array($decodedRole) && in_array('admin', $decodedRole, true));
}
if (empty($_SESSION['user_id']) || !$isAdmin) {
    http_response_code(403);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required.'
        ]);
        exit;
    }
    echo '<div class="ac-alert ac-alert-error">Admin access required.</div>';
    return;
}
/*
 * POST actions return JSON. This lets the page work when included
 * inside the existing admin dashboard.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? ($_POST['csrf_token'] ?? '');
    if (
        empty($_SESSION['admin_creatives_csrf'])
        || !is_string($token)
        || !hash_equals($_SESSION['admin_creatives_csrf'], $token)
    ) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Security token expired. Refresh the page and try again.'
        ]);
        exit;
    }
    $creativeId = filter_input(INPUT_POST, 'creative_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    if (!$creativeId || !in_array($action, ['approve', 'reject', 'revision'], true)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid creative or action.'
        ]);
        exit;
    }
    if ($action === 'approve' || $action === 'reject') {
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $conn->prepare(
            "UPDATE creatives
             SET status = ?, revision_reason = NULL
             WHERE id = ?"
        );
        if (!$stmt) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Unable to prepare the database operation.'
            ]);
            exit;
        }
        $stmt->bind_param('si', $newStatus, $creativeId);
    } else {
        $reason = trim($_POST['revision_reason'] ?? '');
        if ($reason === '') {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'Please enter a reason for revision.'
            ]);
            exit;
        }
        if (mb_strlen($reason) > 5000) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'Revision reason cannot exceed 5000 characters.'
            ]);
            exit;
        }
        $stmt = $conn->prepare(
            "UPDATE creatives
             SET status = 'revision', revision_reason = ?
             WHERE id = ?"
        );
        if (!$stmt) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Unable to prepare the database operation.'
            ]);
            exit;
        }
        $stmt->bind_param('si', $reason, $creativeId);
    }
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to update the creative status.'
        ]);
        $stmt->close();
        exit;
    }
    $updated = $stmt->affected_rows;
    $stmt->close();
    // affected_rows can be 0 when the same value is already saved.
    $check = $conn->prepare('SELECT id FROM creatives WHERE id = ? LIMIT 1');
    $check->bind_param('i', $creativeId);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();
    if (!$exists) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Creative not found.'
        ]);
        exit;
    }
    echo json_encode([
        'success' => true,
        'message' => $action === 'approve'
            ? 'Creative approved successfully.'
            : ($action === 'reject'
                ? 'Creative rejected successfully.'
                : 'Creative sent for revision successfully.'),
        'status' => $action === 'approve'
            ? 'approved'
            : ($action === 'reject' ? 'rejected' : 'revision'),
        'creative_id' => $creativeId
    ]);
    exit;
}
if (empty($_SESSION['admin_creatives_csrf'])) {
    $_SESSION['admin_creatives_csrf'] = bin2hex(random_bytes(32));
}
function ac_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
function ac_date($value): string
{
    if (!$value) {
        return '—';
    }
    $time = strtotime($value);
    return $time ? date('d M Y', $time) : '—';
}
$sql = "
    SELECT
        cr.id,
        cr.user_id,
        cr.company_id,
        cr.platform,
        cr.creative_date,
        cr.title,
        cr.caption,
        cr.description,
        cr.image_path,
        cr.reference_link,
        cr.keywords,
        cr.status,
        cr.revision_reason,
        cr.created_at,
        u.fullname AS creator_name,
        u.email AS creator_email,
        c.comp_name AS company_name,
        c.comp_logo AS company_logo
    FROM creatives cr
    LEFT JOIN users u ON u.id = cr.user_id
    LEFT JOIN companies c ON c.id = cr.company_id
    ORDER BY
        CASE cr.status
            WHEN 'pending' THEN 1
            WHEN 'revision' THEN 2
            WHEN 'rejected' THEN 3
            WHEN 'draft' THEN 4
            WHEN 'approved' THEN 5
            ELSE 6
        END,
        cr.created_at DESC,
        cr.id DESC
";
$result = $conn->query($sql);
if (!$result) {
    echo '<div class="ac-alert ac-alert-error">Unable to load creatives. Check your database connection and table structure.</div>';
    return;
}
$creatives = [];
while ($row = $result->fetch_assoc()) {
    $creatives[] = $row;
}
$counts = [
    'all' => count($creatives),
    'pending' => 0,
    'approved' => 0,
    'revision' => 0,
    'draft' => 0,
    'rejected' => 0
];
foreach ($creatives as $creative) {
    $status = strtolower(trim($creative['status'] ?? ''));
    if (isset($counts[$status])) {
        $counts[$status]++;
    }
}
$platformIcons = [
    'instagram' => 'IG',
    'facebook' => 'FB',
    'linkedin' => 'IN',
    'google' => 'G',
    'youtube' => 'YT',
    'x' => 'X',
    'pinterest' => 'P',
    'threads' => 'TH'
];
$csrf = $_SESSION['admin_creatives_csrf'];
?>
<link rel="stylesheet" href="../assets/css/admincreatives.css">
<div class="admin-creatives" id="adminCreatives"
    data-endpoint="admincreatives.php"
    data-csrf="<?= ac_h($csrf) ?>">
    <div class="ac-glow ac-glow-one"></div>
    <div class="ac-glow ac-glow-two"></div>
    <header class="ac-header">
        <div class="ac-heading">
            <div class="ac-eyebrow">
                <span class="ac-live-dot"></span>
                CREATIVE MANAGEMENT
            </div>
            <h1>Creative <span>Studio.</span></h1>
            <p>Review, approve and manage every creative in one place.</p>
        </div>
        <div class="ac-total-card">
            <span>Total Creatives</span>
            <strong><?= (int)$counts['all'] ?></strong>
            <small>Across all companies</small>
        </div>
    </header>
    <section class="ac-stats">
        <?php
        $statItems = [
            ['all', 'All Creatives', 'ac-purple'],
            ['pending', 'Pending Review', 'ac-blue'],
            ['approved', 'Approved', 'ac-green'],
            ['revision', 'Needs Revision', 'ac-orange'],
            ['rejected', 'Rejected', 'ac-red']
        ];
        ?>
        <?php foreach ($statItems as $stat): ?>
            <button type="button"
                class="ac-stat <?= ac_h($stat[2]) ?>"
                data-filter="<?= ac_h($stat[0]) ?>">
                <span class="ac-stat-label"><?= ac_h($stat[1]) ?></span>
                <strong><?= (int)$counts[$stat[0]] ?></strong>
                <span class="ac-stat-line"></span>
            </button>
        <?php endforeach; ?>
    </section>
    <section class="ac-toolbar">
        <div class="ac-search">
            <span aria-hidden="true">⌕</span>
            <input type="search" id="acSearch"
                placeholder="Search title, company, creator..."
                aria-label="Search creatives">
        </div>
        <select id="acStatusFilter" aria-label="Filter by status">
            <option value="all">All statuses</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="revision">Revision</option>
            <option value="draft">Draft</option>
            <option value="rejected">Rejected</option>
        </select>
        <select id="acPlatformFilter" aria-label="Filter by platform">
            <option value="all">All platforms</option>
            <?php foreach ($platformIcons as $platform => $icon): ?>
                <option value="<?= ac_h($platform) ?>">
                    <?= ac_h(ucfirst($platform)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span class="ac-results-count" id="acResultsCount">
            <?= count($creatives) ?> results
        </span>
    </section>
    <div class="ac-notice" id="acNotice" role="status" aria-live="polite"></div>
    <section class="ac-grid" id="acGrid">
        <?php foreach ($creatives as $index => $creative): ?>
            <?php
            $id = (int)$creative['id'];
            $status = strtolower(trim($creative['status'] ?? 'draft'));
            if (!in_array($status, ['draft', 'pending', 'approved', 'revision', 'rejected'], true)) {
                $status = 'draft';
            }
            $title = trim($creative['title'] ?? '') ?: 'Untitled Creative';
            $company = trim($creative['company_name'] ?? '') ?: 'Company not assigned';
            $creator = trim($creative['creator_name'] ?? '') ?: 'Unknown user';
            $platform = strtolower($creative['platform'] ?? '');
            $platformLabel = ucfirst($platform ?: 'Other');
            /*
             * image_path is stored relative to the project root,
             * e.g. uploads/creatives/example.jpg.
             */
            $rawImage = trim($creative['image_path'] ?? '');
            $imageUrl = '';
            if ($rawImage !== '' && !preg_match('~^(?:https?:)?//|^data:|^javascript:~i', $rawImage)) {
                $imageUrl = '../' . ltrim($rawImage, '/');
            }
            $reference = trim($creative['reference_link'] ?? '');
            $safeReference = '';
            if ($reference !== '') {
                $parts = parse_url($reference);
                if (
                    is_array($parts)
                    && isset($parts['scheme'], $parts['host'])
                    && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
                ) {
                    $safeReference = $reference;
                }
            }
            $searchText = strtolower(
                $title . ' ' . $company . ' ' . $creator . ' ' .
                    $platformLabel . ' ' . $status
            );
            $payload = [
                'id' => $id,
                'title' => $title,
                'company' => $company,
                'creator' => $creator,
                'email' => $creative['creator_email'] ?? '',
                'platform' => $platformLabel,
                'date' => ac_date($creative['creative_date'] ?? ''),
                'created' => ac_date($creative['created_at'] ?? ''),
                'status' => $status,
                'caption' => $creative['caption'] ?? '',
                'description' => $creative['description'] ?? '',
                'keywords' => $creative['keywords'] ?? '',
                'image' => $imageUrl,
                'reference' => $safeReference,
                'reason' => $creative['revision_reason'] ?? ''
            ];
            ?>
            <article class="ac-card"
                style="--card-index: <?= (int)$index ?>"
                data-id="<?= $id ?>"
                data-status="<?= ac_h($status) ?>"
                data-platform="<?= ac_h($platform) ?>"
                data-search="<?= ac_h($searchText) ?>"
                data-creative="<?= ac_h(json_encode($payload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>">
                <div class="ac-card-media">
                    <?php if ($imageUrl !== ''): ?>
                        <img src="<?= ac_h($imageUrl) ?>"
                            alt="<?= ac_h($title) ?>"
                            loading="lazy"
                            onerror="this.style.display='none';this.nextElementSibling.hidden=false;">
                    <?php endif; ?>
                    <div class="ac-image-fallback" <?= $imageUrl !== '' ? 'hidden' : '' ?>>
                        <span><?= ac_h($platformIcons[$platform] ?? 'CR') ?></span>
                        <small>Creative Preview</small>
                    </div>
                    <span class="ac-platform"><?= ac_h($platformLabel) ?></span>
                    <span class="ac-status ac-status-<?= ac_h($status) ?>"
                        data-status-badge>
                        <?= ac_h(ucfirst($status)) ?>
                    </span>
                    <button type="button" class="ac-view-image"
                        data-action="view" aria-label="View creative">
                        View Creative <span>↗</span>
                    </button>
                </div>
                <div class="ac-card-body">
                    <div class="ac-card-topline">
                        <span class="ac-id">CREATIVE #<?= $id ?></span>
                        <span class="ac-date"><?= ac_h(ac_date($creative['creative_date'])) ?></span>
                    </div>
                    <h2><?= ac_h($title) ?></h2>
                    <div class="ac-meta">
                        <div class="ac-meta-icon">C</div>
                        <div>
                            <small>Company</small>
                            <strong><?= ac_h($company) ?></strong>
                        </div>
                    </div>
                    <div class="ac-meta">
                        <div class="ac-meta-icon ac-meta-creator">U</div>
                        <div>
                            <small>Added by</small>
                            <strong><?= ac_h($creator) ?></strong>
                            <?php if (!empty($creative['creator_email'])): ?>
                                <span class="ac-email"><?= ac_h($creative['creator_email']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($status === 'revision' && !empty($creative['revision_reason'])): ?>
                        <div class="ac-revision-note">
                            <strong>Revision reason</strong>
                            <p><?= nl2br(ac_h($creative['revision_reason'])) ?></p>
                        </div>
                    <?php endif; ?>
                    <div class="ac-card-actions">
                        <button type="button" class="ac-btn ac-btn-view"
                            data-action="view">View Details</button>
                        <?php if ($status !== 'approved'): ?>
                            <button type="button" class="ac-btn ac-btn-approve"
                                data-action="approve">✓ Approve</button>
                        <?php else: ?>
                            <span class="ac-approved-label">✓ Approved</span>
                        <?php endif; ?>
                        <button type="button" class="ac-btn ac-btn-revision"
                            data-action="revision">↻ Revision</button>
                        <button type="button" class="ac-btn ac-btn-reject"
                            data-action="reject">✕ Reject</button>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
    <div class="ac-empty" id="acEmpty" <?= count($creatives) ? 'hidden' : '' ?>>
        <div class="ac-empty-icon">✦</div>
        <h3>No creatives found</h3>
        <p>Try changing the search text or filters.</p>
    </div>
    <!-- Creative details modal -->
    <div class="ac-modal" id="acViewModal" aria-hidden="true">
        <div class="ac-modal-backdrop" data-close-modal></div>
        <section class="ac-modal-panel ac-view-panel"
            role="dialog" aria-modal="true"
            aria-labelledby="acViewTitle">
            <button type="button" class="ac-modal-close"
                data-close-modal aria-label="Close">×</button>
            <div class="ac-view-layout">
                <div class="ac-view-preview" id="acViewPreview"></div>
                <div class="ac-view-content">
                    <span class="ac-eyebrow">CREATIVE DETAILS</span>
                    <h2 id="acViewTitle">Creative</h2>
                    <div id="acViewStatus"></div>
                    <div class="ac-detail-list" id="acViewDetails"></div>
                    <div id="acViewText"></div>
                    <div id="acViewReference"></div>
                    <div class="ac-modal-actions">
                        <button type="button" class="ac-btn ac-btn-approve"
                            data-modal-action="approve">✓ Approve</button>
                        <button type="button" class="ac-btn ac-btn-revision"
                            data-modal-action="revision">↻ Request Revision</button>
                        <button type="button" class="ac-btn ac-btn-reject"
                            data-modal-action="reject">✕ Reject</button>
                    </div>
                </div>
            </div>
        </section>
    </div>
    <!-- Revision reason modal -->
    <div class="ac-modal" id="acRevisionModal" aria-hidden="true">
        <div class="ac-modal-backdrop" data-close-modal></div>
        <section class="ac-modal-panel ac-revision-panel"
            role="dialog" aria-modal="true"
            aria-labelledby="acRevisionTitle">
            <button type="button" class="ac-modal-close"
                data-close-modal aria-label="Close">×</button>
            <div class="ac-revision-symbol">↻</div>
            <span class="ac-eyebrow">FEEDBACK & IMPROVEMENTS</span>
            <h2 id="acRevisionTitle">Request a revision</h2>
            <p class="ac-modal-subtitle">
                Explain what needs to change. Your feedback will be saved with this creative.
            </p>
            <label for="acRevisionReason">Reason for revision <span>*</span></label>
            <textarea id="acRevisionReason" maxlength="5000"
                placeholder="e.g. Update the headline, correct the brand colours, and improve the CTA..."
                rows="5" required></textarea>
            <div class="ac-textarea-footer">
                <span>Be specific and constructive.</span>
                <span id="acReasonCount">0 / 5000</span>
            </div>
            <div class="ac-modal-actions">
                <button type="button" class="ac-btn ac-btn-cancel"
                    data-close-modal>Cancel</button>
                <button type="button" class="ac-btn ac-btn-revision"
                    id="acSubmitRevision">Send for Revision ↗</button>
            </div>
        </section>
    </div>
</div>
<script src="../assets/js/admincreatives.js" defer></script>