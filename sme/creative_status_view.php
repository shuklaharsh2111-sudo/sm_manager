<?php
/* Shared renderer for the SME's separate creative-status pages. */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
if (!$userId || $userId <= 0) {
    echo '<div class="sme-creative-message is-error" role="alert">Please log in to view your creatives.</div>';
    return;
}

$sessionRole = $_SESSION['role'] ?? '';
if (is_array($sessionRole)) {
    $statusPageRoles = $sessionRole;
} elseif (is_string($sessionRole)) {
    $decodedStatusRoles = json_decode($sessionRole, true);
    $statusPageRoles = is_array($decodedStatusRoles) ? $decodedStatusRoles : [$sessionRole];
} else {
    $statusPageRoles = [];
}
$statusPageRoles = array_map(static fn($role) => strtolower(trim((string)$role)), $statusPageRoles);
if (!in_array('sme', $statusPageRoles, true)) {
    http_response_code(403);
    echo '<div class="sme-creative-message is-error" role="alert">You do not have permission to view these creatives.</div>';
    return;
}

require_once __DIR__ . '/../config/db.php';
$statusDb = $conn ?? $mysqli ?? null;
if (!$statusDb instanceof mysqli) {
    echo '<div class="sme-creative-message is-error" role="alert">Database connection not found. Check config/db.php.</div>';
    return;
}

if (!function_exists('smeCreativeStatusEscape')) {
    function smeCreativeStatusEscape($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$allowedStatusPages = ['pending', 'rejected', 'revision', 'draft'];
if (!isset($creativePageStatus) || !in_array($creativePageStatus, $allowedStatusPages, true)) {
    http_response_code(500);
    echo '<div class="sme-creative-message is-error" role="alert">Creative status page is not configured correctly.</div>';
    return;
}

$creativeItems = [];
$creativePageError = '';
try {
    $statusSql = "
        SELECT cr.id, cr.company_id, cr.platform, cr.creative_date, cr.title,
               cr.image_path, cr.status, cr.revision_reason, cr.created_at,
               c.comp_name AS company_name
        FROM creatives AS cr
        LEFT JOIN companies AS c ON c.id = cr.company_id
        WHERE cr.user_id = ? AND cr.status = ?
        ORDER BY cr.created_at DESC, cr.id DESC
    ";
    $statusStmt = $statusDb->prepare($statusSql);
    if (!$statusStmt) {
        throw new RuntimeException('Unable to prepare creative status query.');
    }
    $statusStmt->bind_param('is', $userId, $creativePageStatus);
    $statusStmt->execute();
    $statusResult = $statusStmt->get_result();
    while ($creativeRow = $statusResult->fetch_assoc()) {
        $creativeItems[] = $creativeRow;
    }
    $statusStmt->close();
} catch (Throwable $exception) {
    error_log('SME creative status page query error (' . $creativePageStatus . '): ' . $exception->getMessage());
    $creativePageError = 'Unable to load these creatives right now. Please try again later.';
}

$statusLabels = [
    'pending' => 'Pending review',
    'rejected' => 'Rejected',
    'revision' => 'Redo required',
    'draft' => 'Draft'
];
?>
<link rel="stylesheet" href="../assets/css/smecreative-status.css">
<section class="sme-creative-reviews is-<?= smeCreativeStatusEscape($creativePageStatus) ?>" aria-labelledby="smeCreativeStatusPageTitle">
    <div class="sme-creative-reviews-heading">
        <div>
            <span class="sme-creative-eyebrow">CREATIVE WORKSPACE</span>
            <h2 id="smeCreativeStatusPageTitle"><?= smeCreativeStatusEscape($creativePageTitle) ?></h2>
            <p><?= smeCreativeStatusEscape($creativePageDescription) ?></p>
        </div>
        <span class="sme-creative-total"><?= count($creativeItems) ?> <?= count($creativeItems) === 1 ? 'creative' : 'creatives' ?></span>
    </div>

    <?php if ($creativePageError !== ''): ?>
        <p class="sme-creative-message is-error" role="alert"><?= smeCreativeStatusEscape($creativePageError) ?></p>
    <?php elseif (empty($creativeItems)): ?>
        <div class="sme-creative-empty">
            <strong><?= smeCreativeStatusEscape($creativeEmptyMessage) ?></strong>
            <p>Creatives with this status will appear here.</p>
        </div>
    <?php else: ?>
        <div class="sme-creative-grid">
            <?php foreach ($creativeItems as $creative): ?>
                <?php
                    $status = strtolower(trim((string)($creative['status'] ?? $creativePageStatus)));
                    $image = trim(str_replace('\\', '/', (string)($creative['image_path'] ?? '')));
                    if ($image !== '' && preg_match('~^https?://~i', $image)) {
                        // External URLs are used as stored.
                    } elseif ($image !== '' && preg_match('~^(?://|data:|javascript:)~i', $image)) {
                        $image = '';
                    } elseif ($image !== '' && (strpos($image, '/') === 0 || strpos($image, '../') === 0)) {
                        // Root-relative and project-relative paths are retained.
                    } elseif ($image !== '') {
                        $image = '../' . ltrim(preg_replace('~^(\./)+~', '', $image), '/');
                    }
                    $dateRaw = trim((string)($creative['creative_date'] ?? ''));
                    $dateLabel = $dateRaw !== '' && strtotime($dateRaw) ? date('d M Y', strtotime($dateRaw)) : 'Date not set';
                    $companyName = trim((string)($creative['company_name'] ?? '')) ?: 'Company not assigned';
                    $feedback = trim((string)($creative['revision_reason'] ?? ''));
                ?>
                <?php
                    $submittedRaw = trim((string)($creative['created_at'] ?? ''));
                    $submittedLabel = $submittedRaw !== '' && strtotime($submittedRaw) ? date('d M Y', strtotime($submittedRaw)) : '';
                    $statusIcons = ['rejected' => '&#10005;', 'revision' => '&#8635;', 'pending' => '&#9719;', 'draft' => '&#9998;'];
                ?>
                <article class="sme-creative-card is-<?= smeCreativeStatusEscape($status) ?>">
                    <div class="sme-creative-thumb">
                        <?php if ($image !== ''): ?>
                            <a class="sme-creative-thumb-link" href="<?= smeCreativeStatusEscape($image) ?>" target="_blank" rel="noopener noreferrer" aria-label="Open full image: <?= smeCreativeStatusEscape($creative['title'] ?? 'Creative') ?>">
                                <img src="<?= smeCreativeStatusEscape($image) ?>" alt="<?= smeCreativeStatusEscape($creative['title'] ?? 'Creative preview') ?>" loading="lazy" onerror="this.parentNode.style.display='none';this.parentNode.nextElementSibling.hidden=false;">
                            </a>
                        <?php endif; ?>
                        <span class="sme-creative-thumb-fallback" <?= $image !== '' ? 'hidden' : '' ?>>No preview</span>
                        <span class="sme-creative-review-status sme-creative-thumb-badge is-<?= smeCreativeStatusEscape($status) ?>">
                            <span aria-hidden="true"><?= $statusIcons[$status] ?? '' ?></span>
                            <?= smeCreativeStatusEscape($statusLabels[$status] ?? ucfirst($status)) ?>
                        </span>
                    </div>
                    <div class="sme-creative-card-content">
                        <div class="sme-creative-card-topline">
                            <span class="sme-creative-platform"><?= smeCreativeStatusEscape(ucfirst((string)($creative['platform'] ?? 'Social'))) ?></span>
                        </div>
                        <h3><?= smeCreativeStatusEscape(trim((string)($creative['title'] ?? '')) ?: 'Untitled creative') ?></h3>
                        <ul class="sme-creative-meta">
                            <li><span>Company</span><strong><?= smeCreativeStatusEscape($companyName) ?></strong></li>
                            <li><span>Creative date</span><strong><?= smeCreativeStatusEscape($dateLabel) ?></strong></li>
                            <?php if ($submittedLabel !== ''): ?>
                                <li><span>Submitted</span><strong><?= smeCreativeStatusEscape($submittedLabel) ?></strong></li>
                            <?php endif; ?>
                        </ul>
                        <?php if ($status === 'rejected'): ?>
                            <div class="sme-creative-feedback is-rejected<?= $feedback === '' ? ' is-empty' : '' ?>">
                                <div class="sme-creative-feedback-head">
                                    <span class="sme-creative-feedback-icon" aria-hidden="true">&#10005;</span>
                                    <strong>Reason for rejection</strong>
                                </div>
                                <p><?= $feedback !== '' ? nl2br(smeCreativeStatusEscape($feedback)) : 'The admin rejected this creative without adding feedback.' ?></p>
                            </div>
                        <?php elseif ($status === 'revision'): ?>
                            <div class="sme-creative-feedback is-revision<?= $feedback === '' ? ' is-empty' : '' ?>">
                                <div class="sme-creative-feedback-head">
                                    <span class="sme-creative-feedback-icon" aria-hidden="true">&#8635;</span>
                                    <strong>What to change</strong>
                                </div>
                                <p><?= $feedback !== '' ? nl2br(smeCreativeStatusEscape($feedback)) : 'The admin requested changes. Please contact the admin for details if needed.' ?></p>
                            </div>
                        <?php elseif ($status === 'pending'): ?>
                            <p class="sme-creative-company">Waiting for admin review.</p>
                        <?php elseif ($status === 'draft'): ?>
                            <p class="sme-creative-company">This creative has not been submitted for review yet.</p>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
