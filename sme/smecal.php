<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
}
$action = $_GET['action'] ?? '';
if (in_array($action, ['fetch_sops', 'fetch_creative'], true)) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    $respond = static function (
        int $status,
        array $data
    ): never {
        http_response_code($status);
        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    };
    $userId = filter_var(
        $_SESSION['user_id'] ?? null,
        FILTER_VALIDATE_INT
    );
    $sessionRole = $_SESSION['role'] ?? '';
    $roles = [];
    if (is_array($sessionRole)) {
        $roles = $sessionRole;
    } elseif (is_string($sessionRole)) {
        $decoded = json_decode($sessionRole, true);
        $roles = is_array($decoded) ? $decoded : [$sessionRole];
    }
    $roles = array_map(
        static fn($role) => strtolower(trim((string) $role)),
        $roles
    );
    if (!$userId || !in_array('sme', $roles, true)) {
        $respond(401, [
            'success' => false,
            'message' => 'Session expired. Please log in again.'
        ]);
    }
    try {
        /*
         * FETCH A SINGLE CREATIVE
         * Table: creatives
         * Columns: id, user_id, company_id, sop_id, image_path, etc.
         */
        if ($action === 'fetch_creative') {
            $sopId = filter_var(
                $_GET['sop_id'] ?? null,
                FILTER_VALIDATE_INT
            );
            if (!$sopId || $sopId <= 0) {
                $respond(400, [
                    'success' => false,
                    'message' => 'Invalid SOP ID.'
                ]);
            }
            $sql = "
                SELECT cr.*
                FROM creatives AS cr
                INNER JOIN company_sops AS cs
                    ON cs.id = cr.sop_id
                INNER JOIN sme_company_assignments AS sca
                    ON sca.company_id = cs.company_id
                WHERE cr.sop_id = ?
                  AND cr.user_id = ?
                  AND sca.sme_user_id = ?
                ORDER BY cr.id DESC
                LIMIT 1
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('iii', $sopId, $userId, $userId);
            $stmt->execute();
            $creative = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$creative) {
                $respond(404, [
                    'success' => false,
                    'message' => 'No creative found for this SOP.'
                ]);
            }
            $respond(200, [
                'success' => true,
                'creative' => $creative
            ]);
        }
        /*
         * FETCH MONTHLY SOPs FOR AN ASSIGNED COMPANY
         */
        $companyId = filter_var(
            $_GET['company_id'] ?? null,
            FILTER_VALIDATE_INT
        );
        $month = (string) ($_GET['month'] ?? '');
        if (
            !$companyId ||
            $companyId <= 0 ||
            !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
        ) {
            $respond(400, [
                'success' => false,
                'message' => 'Invalid company ID or calendar month.'
            ]);
        }
        $monthStart = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $month . '-01'
        );
        if (!$monthStart || $monthStart->format('Y-m') !== $month) {
            $respond(400, [
                'success' => false,
                'message' => 'Invalid calendar month.'
            ]);
        }
        $nextMonth = $monthStart->modify('+1 month');
        $companySql = "
            SELECT c.id, c.comp_name
            FROM companies AS c
            INNER JOIN sme_company_assignments AS sca
                ON sca.company_id = c.id
            WHERE c.id = ?
              AND sca.sme_user_id = ?
            LIMIT 1
        ";
        $companyStmt = $conn->prepare($companySql);
        $companyStmt->bind_param('ii', $companyId, $userId);
        $companyStmt->execute();
        $company = $companyStmt->get_result()->fetch_assoc();
        $companyStmt->close();
        if (!$company) {
            $respond(403, [
                'success' => false,
                'message' => 'This company is not assigned to your account.'
            ]);
        }
        $startDate = $monthStart->format('Y-m-d');
        $endDate = $nextMonth->format('Y-m-d');
        $sopSql = "
            SELECT
                cs.id,
                cs.company_id,
                cs.sop_type,
                cs.sop_title,
                cs.sop_description,
                cs.sop_date,
                (
                    SELECT cr.image_path
                    FROM creatives AS cr
                    WHERE cr.sop_id = cs.id
                      AND cr.user_id = ?
                    ORDER BY cr.id DESC
                    LIMIT 1
                ) AS creative_image
            FROM company_sops AS cs
            WHERE cs.company_id = ?
              AND cs.sop_date >= ?
              AND cs.sop_date < ?
            ORDER BY
                cs.sop_date ASC,
                FIELD(cs.sop_type, 'daily', 'weekly', 'monthly'),
                cs.sop_title ASC,
                cs.id ASC
        ";
        $sopStmt = $conn->prepare($sopSql);
        $sopStmt->bind_param(
            'iiss',
            $userId,
            $companyId,
            $startDate,
            $endDate
        );
        $sopStmt->execute();
        $result = $sopStmt->get_result();
        $sops = [];
        while ($row = $result->fetch_assoc()) {
            $sops[] = [
                'id' => (int) $row['id'],
                'company_id' => (int) $row['company_id'],
                'sop_type' => (string) $row['sop_type'],
                'sop_title' => (string) $row['sop_title'],
                'sop_description' => (string) ($row['sop_description'] ?? ''),
                'sop_date' => (string) ($row['sop_date'] ?? ''),
                'creative_image' => (string) ($row['creative_image'] ?? '')
            ];
        }
        $sopStmt->close();
        $respond(200, [
            'success' => true,
            'company' => [
                'id' => (int) $company['id'],
                'name' => (string) $company['comp_name']
            ],
            'month' => $month,
            'sops' => $sops
        ]);
    } catch (Throwable $exception) {
        error_log('SME calendar API error: ' . $exception->getMessage());
        $respond(500, [
            'success' => false,
            'message' => 'Unable to load the requested data. Please try again.'
        ]);
    }
}
?>
<link rel="stylesheet" type="text/css" href="css/smecal.css">
<!-- SME Company Calendar Modal -->
<div
    class="sme-calendar-modal"
    id="smeCalendarModal"
    aria-hidden="true"
    hidden>
    <div class="sme-calendar-backdrop" data-calendar-close></div>
    <section
        class="sme-calendar-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="smeCalendarCompanyName"
        tabindex="-1">
        <header class="sme-calendar-header">
            <div class="sme-calendar-heading">
                <span class="sme-calendar-eyebrow">
                    SME WORKSPACE
                </span>
                <h2 id="smeCalendarCompanyName">
                    Company Calendar
                </h2>
                <p>
                    View your assigned SOPs by date.
                </p>
            </div>
            <button
                type="button"
                class="sme-calendar-close"
                data-calendar-close
                aria-label="Close calendar">&times;</button>
        </header>
        <div class="sme-calendar-content">
            <div class="sme-calendar-main">
                <div class="sme-calendar-toolbar">
                    <button
                        type="button"
                        id="smeCalendarPrev"
                        class="sme-calendar-nav"
                        aria-label="Previous month">&lsaquo;</button>
                    <h3 id="smeCalendarMonth">
                        Loading calendar...
                    </h3>
                    <button
                        type="button"
                        id="smeCalendarNext"
                        class="sme-calendar-nav"
                        aria-label="Next month">&rsaquo;</button>
                    <button
                        type="button"
                        id="smeCalendarToday"
                        class="sme-calendar-today">Today</button>
                </div>
                <div class="sme-calendar-weekdays" aria-hidden="true">
                    <span>Sun</span>
                    <span>Mon</span>
                    <span>Tue</span>
                    <span>Wed</span>
                    <span>Thu</span>
                    <span>Fri</span>
                    <span>Sat</span>
                </div>
                <div class="sme-calendar-grid" id="smeCalendarGrid" aria-label="Calendar dates"></div>
                <div class="sme-calendar-legend">
                    <span>
                        <i class="sme-legend-dot daily"></i>
                        Daily
                    </span>
                    <span>
                        <i class="sme-legend-dot weekly"></i>
                        Weekly
                    </span>
                    <span>
                        <i class="sme-legend-dot monthly"></i>
                        Monthly
                    </span>
                </div>
            </div>
            <aside class="sme-calendar-details">
                <div class="sme-calendar-details-top">
                    <span class="sme-calendar-eyebrow">
                        SELECTED DATE
                    </span>
                    <h3 id="smeCalendarSelectedDate">
                        Select a date
                    </h3>
                    <p id="smeCalendarSelectedCount">
                        Choose a date to view its SOPs.
                    </p>
                </div>
                <div class="sme-calendar-sop-list" id="smeCalendarSopList" aria-live="polite">
                    <div class="sme-calendar-empty"> Select a calendar date to see its assigned SOPs. </div>
                </div>
            </aside>
        </div>
        <div class="sme-calendar-status" id="smeCalendarStatus" role="status" aria-live="polite" hidden></div>
    </section>
</div>
<!-- Add Creative Modal -->
<div class="creative-modal" id="creativeModal" aria-hidden="true" hidden>
    <div class="creative-modal-backdrop" data-creative-close></div>
    <section
        class="creative-modal-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="creativeModalTitle"
        tabindex="-1">
        <form
            id="creativeForm"
            action="../sme/add_creative.php"
            method="POST"
            enctype="multipart/form-data">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars((string) ($_SESSION['creative_csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <input
                type="hidden"
                name="creative_date"
                id="creativeDate">
            <input type="hidden" name="sop_id" id="creativeSopId">
            <input type="hidden" name="company_id" id="creativeCompanyId">
            <div class="creative-modal-layout">
                <!-- LEFT HALF: IMAGE PREVIEW AND UPLOAD -->
                <div class="creative-image-panel">
                    <div class="creative-image-preview" id="creativeImagePreview">
                        <div class="creative-image-placeholder" id="creativeImagePlaceholder">
                            <span class="creative-image-icon">✦</span>
                            <h3>Your Creative</h3>
                            <p>Select an image to preview it here.</p>
                        </div>
                        <img
                            id="creativePreviewImg"
                            alt="Selected creative preview"
                            hidden>
                    </div>
                    <div class="creative-upload-control">
                        <label for="creativeImage">Creative Image *</label>
                        <input
                            type="file"
                            id="creativeImage"
                            name="image"
                            accept=".jpg,.jpeg,.png,.webp"
                            required>
                        <small>JPG, PNG or WebP · Maximum 5 MB</small>
                    </div>
                </div>
                <!-- RIGHT HALF: ALL FORM FIELDS -->
                <div class="creative-details-panel">
                    <header class="creative-modal-header">
                        <div>
                            <span class="sme-calendar-eyebrow">SOP WORKSPACE</span>
                            <h2 id="creativeModalTitle">Add Creative</h2>
                            <p id="creativeSopLabel">Selected SOP</p>
                        </div>
                        <button
                            type="button"
                            class="creative-modal-close"
                            data-creative-close
                            aria-label="Close">&times;</button>
                    </header>
                    <div class="form-group">
                        <label for="creativePlatform">Social Media Platform</label>
                        <select
                            id="creativePlatform"
                            name="platform"
                            class="form-control"
                            required>
                            <option value="">Select Social Media Platform</option>
                            <option value="instagram">Instagram</option>
                            <option value="facebook">Facebook</option>
                            <option value="linkedin">LinkedIn</option>
                            <option value="x">X</option>
                            <option value="google">Google</option>
                            <option value="youtube">YouTube</option>
                            <option value="pinterest">Pinterest</option>
                            <option value="threads">Threads</option>
                        </select>
                    </div>
                    <div class="creative-form-field">
                        <label for="creativeReferenceLink">Reference Link</label>
                        <input
                            type="url"
                            id="creativeReferenceLink"
                            name="reference_link"
                            placeholder="https://example.com">
                    </div>
                    <div class="creative-form-field">
                        <label for="creativeKeywords">Keywords</label>
                        <input
                            type="text"
                            id="creativeKeywords"
                            name="keywords"
                            maxlength="2000"
                            placeholder="campaign, festival, product">
                    </div>
                    <div class="creative-form-field">
                        <label for="creativeTitle">Creative Title *</label>
                        <input
                            type="text"
                            id="creativeTitle"
                            name="title"
                            maxlength="255"
                            required
                            placeholder="Enter creative title">
                    </div>
                    <div class="creative-form-field">
                        <label for="creativeCaption">Caption *</label>
                        <textarea
                            id="creativeCaption"
                            name="caption"
                            rows="5"
                            maxlength="5000"
                            required
                            placeholder="Write your creative caption..."></textarea>
                    </div>
                    <p id="creativeMessage" role="status" aria-live="polite"></p>
                    <div class="creative-form-actions">
                        <button type="button" data-creative-close>Cancel</button>
                        <button type="submit" id="saveCreativeButton">Save Creative</button>
                    </div>
                </div>
            </div>
        </form>
    </section>
</div>
<!-- View Creative Modal -->
<div class="creative-modal" id="viewCreativeModal" aria-hidden="true" hidden>
    <div class="creative-modal-backdrop" data-view-creative-close></div>
    <section
        class="creative-modal-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="viewCreativeModalTitle"
        tabindex="-1">
        <div class="creative-modal-layout">
            <!-- LEFT HALF: IMAGE PREVIEW -->
            <div class="creative-image-panel">
                <div class="creative-image-preview">
                    <img
                        id="viewCreativePreviewImg"
                        alt="Saved creative image"
                        hidden>
                    <div class="creative-image-placeholder" id="viewCreativeImagePlaceholder">
                        <span>No Image Available</span>
                    </div>
                </div>
            </div>
            <!-- RIGHT HALF: DETAILS -->
            <div class="creative-details-panel">
                <header class="creative-modal-header">
                    <div>
                        <span class="sme-calendar-eyebrow">SOP WORKSPACE</span>
                        <h2 id="viewCreativeModalTitle">View Creative</h2>
                        <p id="viewCreativeSopLabel">Selected SOP</p>
                    </div>
                    <button
                        type="button"
                        class="creative-modal-close"
                        data-view-creative-close
                        aria-label="Close">&times;</button>
                </header>
                <div class="creative-form-field">
                    <label><strong>Platform:</strong></label>
                    <p id="viewCreativePlatform" class="view-field-text">-</p>
                </div>
                <div class="creative-form-field">
                    <label><strong>Title:</strong></label>
                    <p id="viewCreativeTitle" class="view-field-text">-</p>
                </div>
                <div class="creative-form-field">
                    <label><strong>Caption:</strong></label>
                    <p id="viewCreativeCaption" class="view-field-text">-</p>
                </div>
                <div class="creative-form-field">
                    <label><strong>Reference Link:</strong></label>
                    <p><a id="viewCreativeRefLink" href="#" target="_blank" rel="noopener noreferrer">-</a></p>
                </div>
                <div class="creative-form-actions">
                    <button type="button" data-view-creative-close>Close</button>
                </div>
            </div>
        </div>
    </section>
</div>
<script src="js/smecal.js"></script>