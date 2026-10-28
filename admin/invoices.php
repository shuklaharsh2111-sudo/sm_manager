<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
if (empty($_SESSION['user_id']) || strtolower(trim((string) ($_SESSION['role'] ?? ''))) !== 'admin') {
    header('Location: ../index.php');
    exit;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (empty($_SESSION['invoice_csrf'])) {
    $_SESSION['invoice_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['invoice_csrf'];
$adminId = (int) $_SESSION['user_id'];
$error = '';
if (!function_exists('invoiceEscape')) {
    function invoiceEscape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('invoiceWordsBelowHundred')) {
    function invoiceWordsBelowHundred(int $number): string
    {
        $ones = [
            '',
            'One',
            'Two',
            'Three',
            'Four',
            'Five',
            'Six',
            'Seven',
            'Eight',
            'Nine',
            'Ten',
            'Eleven',
            'Twelve',
            'Thirteen',
            'Fourteen',
            'Fifteen',
            'Sixteen',
            'Seventeen',
            'Eighteen',
            'Nineteen'
        ];
        $tens = [
            '',
            '',
            'Twenty',
            'Thirty',
            'Forty',
            'Fifty',
            'Sixty',
            'Seventy',
            'Eighty',
            'Ninety'
        ];
        if ($number < 20) {
            return $ones[$number];
        }
        return $tens[intdiv($number, 10)] .
            (($number % 10) ? ' ' . $ones[$number % 10] : '');
    }
}
if (!function_exists('invoiceNumberWords')) {
    function invoiceNumberWords(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }
        $parts = [];
        $groups = [
            10000000 => 'Crore',
            100000 => 'Lakh',
            1000 => 'Thousand',
            100 => 'Hundred'
        ];
        foreach ($groups as $value => $label) {
            if ($number >= $value) {
                $count = intdiv($number, $value);
                $number %= $value;
                $parts[] = invoiceNumberWords($count) . ' ' . $label;
            }
        }
        if ($number > 0) {
            $parts[] = invoiceWordsBelowHundred($number);
        }
        return implode(' ', $parts);
    }
}
if (!function_exists('invoiceAmountWords')) {
    function invoiceAmountWords($amount): string
    {
        $amount = round((float) $amount, 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);
        if ($paise === 100) {
            $rupees++;
            $paise = 0;
        }
        $result = 'Rupees ' . invoiceNumberWords($rupees);
        if ($paise > 0) {
            $result .= ' and ' . invoiceNumberWords($paise) . ' Paise';
        }
        return $result . ' Only';
    }
}
if (!function_exists('invoiceDate')) {
    function invoiceDate($date): string
    {
        if (empty($date)) {
            return '-';
        }
        $timestamp = strtotime((string) $date);
        return $timestamp ? date('d-m-Y', $timestamp) : '-';
    }
}
if (!function_exists('invoiceCompanyLogo')) {
    function invoiceCompanyLogo($path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $path)) {
            return $path;
        }
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        if (strpos($path, 'uploads/') !== 0) {
            $path = 'uploads/' . basename($path);
        }
        return '../' . $path;
    }
}
$activeTab = strtoupper(trim((string) ($_GET['tab'] ?? $_POST['invoice_type'] ?? 'PFI')));
if (!in_array($activeTab, ['PFI', 'TI'], true)) {
    $activeTab = 'PFI';
}
/* Load invoice selected for editing. */
$editInvoice = null;
$editItems = [];
$editInvoiceId = 0;
if (isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
    $editInvoiceId = (int) $_GET['edit'];
    if ($editInvoiceId > 0) {
        $editStmt = $conn->prepare(
            'SELECT * FROM invoices WHERE id = ? LIMIT 1'
        );
        $editStmt->bind_param('i', $editInvoiceId);
        $editStmt->execute();
        $editInvoice = $editStmt->get_result()->fetch_assoc();
        $editStmt->close();
        if ($editInvoice) {
            $editItemsStmt = $conn->prepare(
                'SELECT item_name, description, quantity, rate, amount
                 FROM invoice_items
                 WHERE invoice_id = ?
                 ORDER BY id ASC'
            );
            $editItemsStmt->bind_param('i', $editInvoiceId);
            $editItemsStmt->execute();
            $editItemsResult = $editItemsStmt->get_result();
            while ($editItem = $editItemsResult->fetch_assoc()) {
                $editItems[] = $editItem;
            }
            $editItemsStmt->close();
            // Keep the correct invoice tab selected.
            $activeTab = $editInvoice['invoice_type'];
        }
    }
}
/* Current admin/company information. */
$companyName = 'Your Company';
$companyLogo = '';
$companyStmt = $conn->prepare(
    'SELECT brand_name, brand_logo FROM users WHERE id = ? LIMIT 1'
);
$companyStmt->bind_param('i', $adminId);
$companyStmt->execute();
$adminCompany = $companyStmt->get_result()->fetch_assoc();
$companyStmt->close();
if ($adminCompany) {
    $companyName = trim((string) ($adminCompany['brand_name'] ?? '')) ?: 'Your Company';
    $companyLogo = invoiceCompanyLogo($adminCompany['brand_logo'] ?? '');
}
/* Save invoice before rendering this included page. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) ($_POST['action'] ?? ''), ['save_invoice', 'update_invoice'], true)) {
    try {
        if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security token expired. Refresh the page and try again.');
        }
        $type = strtoupper(trim((string) ($_POST['invoice_type'] ?? 'PFI')));
        $action = (string) ($_POST['action'] ?? 'save_invoice');
        $editId = (int) ($_POST['edit_invoice_id'] ?? 0);
        if ($action === 'update_invoice' && $editId <= 0) {
            throw new InvalidArgumentException('Invalid invoice selected for editing.');
        }
        if (!in_array($type, ['PFI', 'TI'], true)) {
            throw new InvalidArgumentException('Invalid invoice type.');
        }
        $billTo = trim((string) ($_POST['bill_to'] ?? ''));
        $companyContact = trim((string) ($_POST['company_contact'] ?? ''));
        $billGstin = strtoupper(trim((string) ($_POST['gstin'] ?? '')));
        $shipTo = trim((string) ($_POST['ship_to'] ?? ''));
        $companyPan = strtoupper(trim((string) ($_POST['company_pan'] ?? '')));
        $shipGstin = strtoupper(trim((string) ($_POST['ship_gstin'] ?? '')));
        $issueDate = trim((string) ($_POST['issue_date'] ?? ''));
        $terms = trim((string) ($_POST['terms'] ?? ''));
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($billTo === '' || $shipTo === '' || $issueDate === '') {
            throw new InvalidArgumentException('Bill To, Ship To and Date of Issue are required.');
        }
        if (mb_strlen($companyContact) > 30) {
            throw new InvalidArgumentException('Company Contact must be 30 characters or fewer.');
        }
        if (mb_strlen($billTo) > 255) {
            throw new InvalidArgumentException('Bill To must be 255 characters or fewer.');
        }
        $issueCheck = DateTime::createFromFormat('!Y-m-d', $issueDate);
        if (!$issueCheck || $issueCheck->format('Y-m-d') !== $issueDate) {
            throw new InvalidArgumentException('Please enter a valid issue date.');
        }
        if ($dueDate !== '') {
            $dueCheck = DateTime::createFromFormat('!Y-m-d', $dueDate);
            if (!$dueCheck || $dueCheck->format('Y-m-d') !== $dueDate) {
                throw new InvalidArgumentException('Please enter a valid due date.');
            }
        }
        foreach ([$billGstin, $shipGstin] as $gstinValue) {
            if ($gstinValue !== '' && !preg_match('/^[A-Z0-9]{15}$/', $gstinValue)) {
                throw new InvalidArgumentException('GSTIN must contain exactly 15 letters/numbers.');
            }
            if ($companyPan !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $companyPan)) {
                throw new InvalidArgumentException('Company PAN must be in valid format, e.g. ABCDE1234F.');
            }
        }
        $itemNames = $_POST['item_name'] ?? [];
        $descriptions = $_POST['description'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $rates = $_POST['rate'] ?? [];
        if (
            !is_array($itemNames) ||
            count($itemNames) < 1 ||
            count($itemNames) > 100 ||
            !is_array($descriptions) ||
            !is_array($quantities) ||
            !is_array($rates)
        ) {
            throw new InvalidArgumentException('Add at least one item (maximum 100).');
        }
        $items = [];
        $total = 0.00;
        foreach ($itemNames as $index => $rawName) {
            $itemName = trim((string) $rawName);
            $description = trim((string) ($descriptions[$index] ?? ''));
            $quantityRaw = $quantities[$index] ?? '';
            $rateRaw = $rates[$index] ?? '';
            if ($itemName === '') {
                throw new InvalidArgumentException('Every item must have an item name.');
            }
            if (!is_numeric($quantityRaw) || !is_numeric($rateRaw)) {
                throw new InvalidArgumentException('Quantity and rate must be valid numbers.');
            }
            $quantity = (float) $quantityRaw;
            $rate = (float) $rateRaw;
            if (
                !is_finite($quantity) ||
                !is_finite($rate) ||
                $quantity <= 0 ||
                $rate < 0 ||
                $quantity > 100000000 ||
                $rate > 100000000
            ) {
                throw new InvalidArgumentException('Quantity must be positive and rate cannot be negative.');
            }
            $amount = round($rate, 2);
            $total = round($total + $amount, 2);
            $items[] = [
                'name' => $itemName,
                'description' => $description,
                'quantity' => $quantity,
                'rate' => $rate,
                'amount' => $amount
            ];
        }
        $subtotal = round($total, 2);
        $gstAmount = 0.00;
        if ($type === 'TI') {
            $gstAmount = round($subtotal * 18 / 100, 2);
        }
        $total = round($subtotal + $gstAmount, 2);
        $conn->begin_transaction();
        if ($action === 'update_invoice') {
            // Verify the invoice exists and its type cannot be changed.
            $checkStmt = $conn->prepare(
                'SELECT invoice_type FROM invoices WHERE id = ? LIMIT 1 FOR UPDATE'
            );
            $checkStmt->bind_param('i', $editId);
            $checkStmt->execute();
            $existingInvoice = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
            if (!$existingInvoice) {
                throw new InvalidArgumentException('Invoice not found.');
            }
            if ($existingInvoice['invoice_type'] !== $type) {
                throw new InvalidArgumentException(
                    'Invoice type cannot be changed while editing.'
                );
            }
            // Update existing invoice without changing its number/reference.
            $updateStmt = $conn->prepare(
                "UPDATE invoices SET
            bill_to = ?,
            company_contact = NULLIF(?, ''),
            gstin = NULLIF(?, ''),
            company_pan = NULLIF(?, ''),
            ship_to = ?,
            ship_gstin = NULLIF(?, ''),
            issue_date = ?,
            terms = NULLIF(?, ''),
            due_date = NULLIF(?, ''),
            notes = NULLIF(?, ''),
            subtotal_amount = ?,
            gst_amount = ?,
            total_amount = ?
         WHERE id = ?"
            );
            $updateStmt->bind_param(
                'ssssssssssdddi',
                $billTo,
                $companyContact,
                $billGstin,
                $companyPan,
                $shipTo,
                $shipGstin,
                $issueDate,
                $terms,
                $dueDate,
                $notes,
                $subtotal,
                $gstAmount,
                $total,
                $editId
            );
            $updateStmt->execute();
            $updateStmt->close();
            // Replace old invoice items with the edited items.
            $deleteStmt = $conn->prepare(
                'DELETE FROM invoice_items WHERE invoice_id = ?'
            );
            $deleteStmt->bind_param('i', $editId);
            $deleteStmt->execute();
            $deleteStmt->close();
            $invoiceId = $editId;
        } else {
            // Generate invoice numbers only for a new invoice.
            $counterStmt = $conn->prepare(
                'SELECT next_number, next_reference
         FROM invoice_counters
         WHERE invoice_type = ?
         FOR UPDATE'
            );
            $counterStmt->bind_param('s', $type);
            $counterStmt->execute();
            $counter = $counterStmt->get_result()->fetch_assoc();
            $counterStmt->close();
            if (!$counter) {
                throw new RuntimeException(
                    'Invoice counter is missing. Check invoice_counters table setup.'
                );
            }
            $nextNumber = (int) $counter['next_number'];
            $nextReference = (int) $counter['next_reference'];
            $invoicePrefix = ($type === 'PFI') ? 'PI' : 'TI';
            $invoiceNumber = $invoicePrefix . ' - ' .
                str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
            $invoiceReference = 'INV - ' .
                str_pad((string) $nextReference, 6, '0', STR_PAD_LEFT);
            $invoiceStmt = $conn->prepare(
                "INSERT INTO invoices
     (
         invoice_type,
         invoice_number,
         invoice_reference,
         bill_to,
         company_contact,
         gstin,
         company_pan,
         ship_to,
         ship_gstin,
         issue_date,
         terms,
         due_date,
         notes,
         subtotal_amount,
         gst_amount,
         total_amount
     )
     VALUES (
         ?, ?, ?, ?, NULLIF(?, ''),
         NULLIF(?, ''), NULLIF(?, ''),
         ?, NULLIF(?, ''), ?,
         NULLIF(?, ''), NULLIF(?, ''),
         NULLIF(?, ''), ?, ?, ?
     )"
            );
            $invoiceStmt->bind_param(
                'sssssssssssssddd',
                $type,
                $invoiceNumber,
                $invoiceReference,
                $billTo,
                $companyContact,
                $billGstin,
                $companyPan,
                $shipTo,
                $shipGstin,
                $issueDate,
                $terms,
                $dueDate,
                $notes,
                $subtotal,
                $gstAmount,
                $total
            );
            $invoiceStmt->execute();
            $invoiceId = (int) $conn->insert_id;
            $invoiceStmt->close();
            $updateCounter = $conn->prepare(
                'UPDATE invoice_counters
         SET next_number = next_number + 1,
             next_reference = next_reference + 1
         WHERE invoice_type = ?'
            );
            $updateCounter->bind_param('s', $type);
            $updateCounter->execute();
            $updateCounter->close();
        }
        // Insert the current item rows for both new and edited invoices.
        $itemStmt = $conn->prepare(
            "INSERT INTO invoice_items
     (invoice_id, item_name, description, quantity, rate, amount)
     VALUES (?, ?, NULLIF(?, ''), ?, ?, ?)"
        );
        foreach ($items as $item) {
            $itemStmt->bind_param(
                'issddd',
                $invoiceId,
                $item['name'],
                $item['description'],
                $item['quantity'],
                $item['rate'],
                $item['amount']
            );
            $itemStmt->execute();
        }
        $itemStmt->close();
        $conn->commit();
        $successMessage = ($action === 'update_invoice')
            ? 'Invoice updated successfully.'
            : 'Invoice saved successfully.';
        header(
            'Location: admin.php?page=invoices&tab=' .
                urlencode($type) .
                '&saved=1&message=' .
                urlencode($successMessage)
        );
        exit;
        header('Location: admin.php?page=invoices&tab=' . urlencode($type) . '&saved=1');
        exit;
    } catch (Throwable $exception) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
        if ($exception instanceof InvalidArgumentException || $exception instanceof RuntimeException) {
            $error = $exception->getMessage();
        } else {
            error_log('Invoice save error: ' . $exception->getMessage());
            $error = 'Invoice could not be saved. Check the invoice database tables and columns.';
        }
    }
}
/* Load requested saved invoice and its items. */
$viewInvoice = null;
$viewItems = [];
if (isset($_GET['view']) && ctype_digit((string) $_GET['view'])) {
    $viewId = (int) $_GET['view'];
    $viewStmt = $conn->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
    $viewStmt->bind_param('i', $viewId);
    $viewStmt->execute();
    $viewInvoice = $viewStmt->get_result()->fetch_assoc();
    $viewStmt->close();
    if ($viewInvoice) {
        $itemsStmt = $conn->prepare(
            'SELECT item_name, description, quantity, rate, amount
             FROM invoice_items
             WHERE invoice_id = ?
             ORDER BY id ASC'
        );
        $itemsStmt->bind_param('i', $viewId);
        $itemsStmt->execute();
        $itemsResult = $itemsStmt->get_result();
        while ($itemRow = $itemsResult->fetch_assoc()) {
            $viewItems[] = $itemRow;
        }
        $itemsStmt->close();
    }
}
/* Load opposite-type invoices for copying details. */
$sourceType = ($activeTab === 'PFI') ? 'TI' : 'PFI';
$sourceInvoices = [];
$sourceStmt = $conn->prepare(
    'SELECT id, invoice_type, invoice_number, invoice_reference,
       bill_to, company_contact, gstin, company_pan, ship_to, ship_gstin,
       issue_date, terms, due_date, notes
     FROM invoices
     WHERE invoice_type = ?
     ORDER BY id DESC'
);
$sourceStmt->bind_param('s', $sourceType);
$sourceStmt->execute();
$sourceResult = $sourceStmt->get_result();
while ($sourceRow = $sourceResult->fetch_assoc()) {
    $sourceInvoiceId = (int) $sourceRow['id'];
    $sourceItemStmt = $conn->prepare(
        'SELECT item_name, description, quantity, rate
         FROM invoice_items
         WHERE invoice_id = ?
         ORDER BY id ASC'
    );
    $sourceItemStmt->bind_param('i', $sourceInvoiceId);
    $sourceItemStmt->execute();
    $sourceItemResult = $sourceItemStmt->get_result();
    $sourceRow['items'] = [];
    while ($sourceItem = $sourceItemResult->fetch_assoc()) {
        $sourceRow['items'][] = $sourceItem;
    }
    $sourceItemStmt->close();
    $sourceInvoices[] = $sourceRow;
}
$sourceStmt->close();
/* Load invoice counters and list for the active tab. */
$nextNumbers = [];
$counterResult = $conn->query(
    'SELECT invoice_type, next_number, next_reference FROM invoice_counters'
);
while ($row = $counterResult->fetch_assoc()) {
    $nextNumbers[$row['invoice_type']] = $row;
}
$invoiceList = [];
$listStmt = $conn->prepare(
    'SELECT id, invoice_type, invoice_number, invoice_reference,
            bill_to, issue_date, due_date, total_amount
     FROM invoices
     WHERE invoice_type = ?
     ORDER BY id DESC'
);
$listStmt->bind_param('s', $activeTab);
$listStmt->execute();
$listResult = $listStmt->get_result();
while ($row = $listResult->fetch_assoc()) {
    $invoiceList[] = $row;
}
$listStmt->close();
$next = $nextNumbers[$activeTab] ?? ['next_number' => 1, 'next_reference' => 1];
$nextInvoicePrefix = ($activeTab === 'PFI') ? 'PI' : 'TI';

$nextInvoiceLabel = $nextInvoicePrefix . ' - ' .
    str_pad((string) $next['next_number'], 4, '0', STR_PAD_LEFT);
$nextReferenceLabel = 'INV - ' .
    str_pad((string) $next['next_reference'], 6, '0', STR_PAD_LEFT);
?>
<link rel="stylesheet" href="../assets/css/invoices.css">
<?php if ($viewInvoice): ?>
    <div class="invoice-toolbar">
        <a class="btn btn-light"
            href="admin.php?page=invoices&tab=<?= invoiceEscape($viewInvoice['invoice_type']) ?>">
            Back to Invoices
        </a>
        <button type="button" class="btn btn-primary" id="downloadPdf">Download as PDF</button>
    </div>
    <main class="invoice-paper" id="invoicePaper">
        <header class="pdf-header">
            <div class="pdf-company">
                <img class="company-logo" src="../assets/images/company-logo.png" alt="Company Logo">
                <div class="company-details">
                    <strong>Unstoppable Solutions LLP</strong><br>
                    <span>5th floor, Building-B, 50, Off New Link Rd,</span><br>
                    <span>opposite Citi mall, Veera Desai Industrial Estate,</span><br>
                    <span>Andheri West, Mumbai, Maharashtra - 400053</span><br>
                    <span><b>UDYAM Reg No.</b> : UDYAM-MH-18-0344293(Micro)</span><br>
                    <span><b>GSTIN</b> : 27AAIFU1702D1ZJ</span><br>
                    <span><b>PAN</b> : AAIF1702D</span><br>
                    <span><b>Email</b> : acs@getunstoppable.co.in</span><br>
                </div>
            </div>
            <div class="pdf-title">
                <h1><?= $viewInvoice['invoice_type'] === 'TI' ? 'Tax Invoice' : 'Proforma Invoice' ?></h1>
                <strong> <?= invoiceEscape( preg_replace( '/^PFI(?=\s*-)/i', 'PI', (string) $viewInvoice['invoice_number'] ) ) ?> </strong>
                <!--small>Reference: <//?= invoiceEscape($viewInvoice['invoice_reference']) ?></small-->
            </div>
        </header>
        <div class="invoice-gold-line"></div><br>
        <table class="party-combined-table">
            <tr>
                <td class="party-combined-cell">
                    <div class="party-cell-heading">Bill To</div>
                    <div class="party-cell-content">
                        <strong><?= invoiceEscape($viewInvoice['bill_to']) ?></strong>
                        <!--p> <b>Primary Person & Contact:</b> <//?= invoiceEscape($viewInvoice['company_contact'] ?: '-') ?> </p-->
                        <p><?= nl2br(invoiceEscape($viewInvoice['ship_to'])) ?></p>
                        <p><b>GSTIN:</b> <?= invoiceEscape($viewInvoice['gstin'] ?: '-') ?></p>
                        <p><b>PAN:</b> <?= invoiceEscape($viewInvoice['company_pan'] ?: '-') ?></p>
                    </div>
                </td>
                <td class="party-combined-cell">
                    <div class="party-cell-heading">Ship To</div>
                    <div class="party-cell-content">
                        <strong><?= invoiceEscape($viewInvoice['bill_to']) ?></strong>
                        <!--p> <b>Primary person Contact:</b> <//?= invoiceEscape($viewInvoice['company_contact'] ?: '-') ?> </p-->
                        <p><?= nl2br(invoiceEscape($viewInvoice['ship_to'])) ?></p>
                        <p><b>GSTIN:</b> <?= invoiceEscape($viewInvoice['ship_gstin'] ?: '-') ?></p>
                        <p><b>PAN:</b> <?= invoiceEscape($viewInvoice['company_pan'] ?: '-') ?></p>
                    </div>
                </td>
            </tr>
        </table>
        <table class="pdf-meta-table">
            <thead>
                <tr>
                    <th>Date of Issue</th>
                    <th>Terms</th>
                    <th>Due Date</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= invoiceEscape(invoiceDate($viewInvoice['issue_date'])) ?></td>
                    <!--td><?= invoiceEscape($viewInvoice['terms'] ?: '-') ?></td-->
                    <td>Due On Reciept</td>
                    <td><?= invoiceEscape(invoiceDate($viewInvoice['due_date'])) ?></td>
                </tr>
            </tbody>
        </table>
        <table class="pdf-items-table">
            <thead>
                <tr>
                    <th>Sr.No.</th>
                    <th>Services</th>
                    <!--th class="number-col">Qty</th-->
                    <!--th class="number-col">Rate (₹)</th-->
                    <th class="number-col">Amount (₹)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($viewItems as $index => $item): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td>
                            <strong><?= invoiceEscape($item['item_name']) ?></strong>
                            <?php if (!empty($item['description'])): ?>
                                <div class="item-description"><?= nl2br(invoiceEscape($item['description'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <!--td class="number-col"><//?= number_format((float) $item['quantity'], 2) ?></td-->
                        <!--td class="number-col"><//?= number_format((float) $item['rate'], 2) ?></td-->
                        <td class="number-col"><?= number_format((float) $item['amount'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="pdf-totals">
            <div>
                <span>Subtotal</span>
                <strong>₹<?= number_format((float) ($viewInvoice['subtotal_amount'] ?? $viewInvoice['total_amount']), 2) ?></strong>
            </div>
            <?php if ($viewInvoice['invoice_type'] === 'TI'): ?>
                <div>
                    <span>GST (18%)</span>
                    <strong>₹<?= number_format((float) ($viewInvoice['gst_amount'] ?? 0), 2) ?></strong>
                </div>
            <?php endif; ?>
            <div>
                <span>Total</span>
                <strong>₹<?= number_format((float) $viewInvoice['total_amount'], 2) ?></strong>
            </div>
            <div class="balance-row">
                <span>Balance Due</span>
                <strong>₹<?= number_format((float) $viewInvoice['total_amount'], 2) ?></strong>
            </div>
        </div>
        <section class="amount-words">
            <strong>Total in words:</strong>
            <?= invoiceEscape(invoiceAmountWords($viewInvoice['total_amount'])) ?>
        </section>
        <!-- Bank Details Section -->
        <section class="invoice-bank-details">
            <h4>Bank Details</h4>
            <div class="bank-details-grid">
                <div>
                    <span>Account Holder Name</span>
                    <strong>Unstoppable Solutions LLP</strong>
                </div>
                <div>
                    <span>Bank Name</span>
                    <strong>ICICI Bank</strong>
                </div>
                <div>
                    <span>Account Number</span>
                    <strong>101605002937</strong>
                </div>
                <div>
                    <span>Branch</span>
                    <strong>Borivali S.V. Road Branch, Mumbai</strong>
                </div>
                <div>
                    <span>IFSC Code</span>
                    <strong>ICIC0001016</strong>
                </div>
            </div>
        </section>
        <footer class="pdf-footer">
            <div class="pdf-notes">
                <p><?= nl2br(invoiceEscape($viewInvoice['notes'] ?: 'Thanks for your business.')) ?></p>
            </div>
            <div class="signature-stamp-group">
                <div class="signature-images">
                    <img src="../assets/images/authorized-signature.png" alt="Authorized Signature" onerror="this.style.display='none'">
                    <img src="../assets/images/comp-stamp.png" alt="" onerror="this.style.display='none'">
                </div>
                <!--div class="signature-line"></div-->
                <strong>Authorized Signature</strong>
                <span><b> Mithilesh Kasare</b></span>
                <span><b> Operations Manager</b></span>
            </div>
        </footer>
        <?php if ($viewInvoice['invoice_type'] === 'PFI'): ?>
            <section class="pfi-disclaimer">
                <h4>IMPORTANT NOTICE &amp; DISCLAIMER</h4>
                <p style="text-align: justify; margin: 0;">
                    Unstoppable Solutions LLP is registered as a Micro Enterprise under the Micro, Small and Medium Enterprises Development (MSMED) Act, 2006 (Udyam Registration No.: UDYAM-MH-18-0344293). In accordance with Sections 15 and 16 of the MSMED Act, 2006, delayed payments beyond the applicable statutory timeline shall attract compound interest with monthly rests at three times the bank rate notified by the Reserve Bank of India, wherever applicable. This document is a Proforma Invoice (PI) and not a Tax Invoice, and the applicable tax invoice or other prescribed document shall be issued in accordance with applicable GST laws.
                </p>
            </section>
        <?php elseif ($viewInvoice['invoice_type'] === 'TI'): ?>
            <section class="pfi-disclaimer">
                <h4>DECLARATION &amp; PAYMENT TERMS</h4>
                <p style="text-align: justify; margin: 0;">
                    We declare that this invoice shows the actual price of the goods described and that all particulars are true and correct. TDS rate applicable @ 2% and the payment terms are Immediate. We are registered under MSME Act (Udyam Registration No.: UDYAM-MH-18-0344293), and delayed payments will attract interest as per MSME Act, 2006.
                </p>
            </section>
        <?php endif; ?>
    </main>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        window.invoicePdfName = <?= json_encode(preg_replace('/[^A-Za-z0-9_-]/', '_', $viewInvoice['invoice_number']) . '.pdf') ?>;
    </script>
    <script src="../assets/js/invoices.js"></script>
<?php else: ?>
    <main class="invoice-dashboard">
        <div class="page-heading">
            <div>
                <h1>Invoices</h1>
                <p>Create, view and download your invoices.</p>
            </div>
            <button type="button" class="btn btn-primary" id="toggleInvoiceForm">+ Add Invoice</button>
        </div>
        <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success" role="status">
                <?= invoiceEscape($_GET['message'] ?? 'Invoice saved successfully.') ?>
            </div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert"><?= invoiceEscape($error) ?></div>
        <?php endif; ?>
        <nav class="invoice-tabs">
            <a href="admin.php?page=invoices&tab=PFI"
                class="invoice-tab <?= $activeTab === 'PFI' ? 'active' : '' ?>">
                Proforma Invoice (PFI)
            </a>
            <a href="admin.php?page=invoices&tab=TI"
                class="invoice-tab <?= $activeTab === 'TI' ? 'active' : '' ?>">
                Tax Invoice (TI)
            </a>
        </nav>
        <section class="next-invoice-card">
            <div><span>Next Invoice Number</span><strong><?= invoiceEscape($nextInvoiceLabel) ?></strong></div>
            <div><span>Reference Number</span><strong><?= invoiceEscape($nextReferenceLabel) ?></strong></div>
        </section>
        <!-- All invoice fields and pricing stay together inside this modal. -->
        <div class="invoice-modal" id="invoiceModal" aria-hidden="true">
            <section class="invoice-form-card" role="dialog" aria-modal="true"
                aria-labelledby="invoiceModalTitle">
                <div class="invoice-modal-heading">
                    <div>
                        <h2 id="invoiceModalTitle"> <?= $editInvoice ? 'Edit ' : 'Create ' ?> <?= $activeTab === 'TI' ? 'Tax Invoice' : 'Proforma Invoice' ?> </h2>
                        <p class="form-note">Invoice numbers are generated automatically when you save.</p>
                    </div>
                    <button type="button" class="invoice-modal-close" id="closeInvoiceModal"
                        aria-label="Close invoice form">×</button>
                </div>
                <form method="POST" action="admin.php?page=invoices&tab=<?= invoiceEscape($activeTab) ?><?= $editInvoice ? '&edit=' . (int) $editInvoice['id'] : '' ?>" id="invoiceForm">
                    <input type="hidden" name="action" value="<?= $editInvoice ? 'update_invoice' : 'save_invoice' ?>">
                    <input type="hidden" name="csrf_token" value="<?= invoiceEscape($csrf) ?>">
                    <input type="hidden" name="invoice_type" value="<?= invoiceEscape($activeTab) ?>">
                    <input type="hidden" name="edit_invoice_id" value="<?= $editInvoice ? (int) $editInvoice['id'] : '' ?>">
                    <div class="form-grid invoice-source-grid">
                        <label>
                            Create from Existing Invoice
                            <select name="source_invoice_id" id="sourceInvoiceSelect">
                                <option value="">Create a new invoice from scratch</option>
                                <?php foreach ($sourceInvoices as $sourceInvoice): ?>
                                    <option
                                        value="<?= (int) $sourceInvoice['id'] ?>"
                                        data-invoice="<?= invoiceEscape(json_encode(
                                                            $sourceInvoice,
                                                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                                        )) ?>">
                                        <?= invoiceEscape($sourceInvoice['invoice_number']) ?>
                                        — <?= invoiceEscape($sourceInvoice['bill_to']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="form-grid">
                        <label> Bill To <input type="text" name="bill_to" id="bill_to" maxlength="255" required placeholder="Enter company or client name" value="<?= invoiceEscape($editInvoice['bill_to'] ?? '') ?>"> </label>
                        <label> Primary Person & contact<input type="text" name="company_contact" id="companyContact" maxlength="30" placeholder="Primary Person & Contact" value="<?= invoiceEscape($editInvoice['company_contact'] ?? '') ?>"> </label>
                        <label> Bill To GSTIN <input type="text" name="gstin" id="billGstin" maxlength="15" placeholder="15-character GSTIN" value="<?= invoiceEscape($editInvoice['gstin'] ?? '') ?>"> </label>
                        <label> Company PAN <input type="text" name="company_pan" id="companyPan" maxlength="10" pattern="[A-Za-z]{5}[0-9]{4}[A-Za-z]" title="Enter a valid PAN, e.g. ABCDE1234F" placeholder="Enter company PAN" style="text-transform: uppercase;" value="<?= invoiceEscape($editInvoice['company_pan'] ?? '') ?>"> </label>
                        <label> Ship To <textarea name="ship_to" rows="3" required placeholder="Shipping address"><?= invoiceEscape($editInvoice['ship_to'] ?? '') ?></textarea> </label>
                        <label> Ship To GSTIN <input type="text" name="ship_gstin" maxlength="15" placeholder="Shipping GSTIN (optional)" value="<?= invoiceEscape($editInvoice['ship_gstin'] ?? '') ?>"> </label>
                        <label> Date of Issue <input type="date" name="issue_date" required value="<?= invoiceEscape($editInvoice['issue_date'] ?? date('Y-m-d')) ?>"> </label>
                        <label> Terms <input type="text" name="terms" maxlength="255" placeholder="e.g. Due on receipt" value="<?= invoiceEscape($editInvoice['terms'] ?? '') ?>"> </label>
                        <label> Due Date <input type="date" name="due_date" value="<?= invoiceEscape($editInvoice['due_date'] ?? '') ?>"> </label>
                        <label> Notes <input type="text" name="notes" placeholder="Thanks for your business." value="<?= invoiceEscape($editInvoice['notes'] ?? '') ?>"> </label>
                    </div>
                    <div class="items-heading">
                        <h3>Items and Pricing</h3>
                        <button type="button" class="btn btn-light" id="addInvoiceItem">+ Add Item</button>
                    </div>
                    <div class="items-table-wrap">
                        <table class="input-items-table">
                            <thead>
                                <tr>
                                    <th>Sr. No.</th>
                                    <th>Item</th>
                                    <th>Description</th>
                                    <th>Qty</th>
                                    <th>Rate (₹)</th>
                                    <th>Amount (₹)</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="invoiceItemsBody">
                                <?php $formItems = $editInvoice ? $editItems : [['item_name' => '', 'description' => '', 'quantity' => '1', 'rate' => '0']]; ?>
                                <?php foreach ($formItems as $index => $item): ?>
                                    <tr class="invoice-item-row">
                                        <td class="item-sr"><?= $index + 1 ?></td>
                                        <td>
                                            <input type="text" name="item_name[]" required placeholder="Item name" value="<?= invoiceEscape($item['item_name'] ?? '') ?>">
                                        </td>
                                        <td>
                                            <input type="text" name="description[]" placeholder="Description" value="<?= invoiceEscape($item['description'] ?? '') ?>">
                                        </td>
                                        <td>
                                            <input type="number" name="quantity[]" min="0.01" step="0.01" required value="<?= invoiceEscape($item['quantity'] ?? '1') ?>">
                                        </td>
                                        <td>
                                            <input type="number" name="rate[]" min="0" step="0.01" required value="<?= invoiceEscape($item['rate'] ?? '0') ?>">
                                        </td>
                                        <td>
                                            <input type="text" class="item-amount" value="<?= number_format((float) ($item['rate'] ?? 0), 2, '.', '') ?>" readonly>
                                        </td>
                                        <td>
                                            <button type="button" class="remove-item" aria-label="Remove item">×</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="form-total">
                        <span>Subtotal</span>
                        <strong id="formSubtotal">₹0.00</strong>
                    </div>
                    <div class="form-total" id="formGstRow" hidden>
                        <span>GST (18%)</span>
                        <strong id="formGst">₹0.00</strong>
                    </div>
                    <div class="form-total">
                        <span>Total</span>
                        <strong id="formTotal">₹0.00</strong>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-light" id="cancelInvoiceForm">Cancel</button>
                        <button type="submit" class="btn btn-primary"> <?= $editInvoice ? 'Update Invoice' : 'Save Invoice' ?> </button>
                    </div>
                </form>
            </section>
        </div>
        <section class="invoice-list-card">
            <div class="list-heading">
                <h2><?= $activeTab === 'TI' ? 'Tax Invoices' : 'Proforma Invoices' ?></h2>
                <span><?= count($invoiceList) ?> invoice(s)</span>
            </div>
            <div class="responsive-table">
                <table class="invoice-list-table">
                    <thead>
                        <tr>
                            <th>Invoice No.</th>
                            <th>Reference</th>
                            <th>Bill To</th>
                            <th>Issue Date</th>
                            <th>Due Date</th>
                            <th>Total</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$invoiceList): ?>
                            <tr>
                                <td colspan="7" class="empty-state">No invoices found in this tab.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($invoiceList as $invoice): ?>
                                <tr>
                                    <td><?= invoiceEscape($invoice['invoice_number']) ?></td>
                                    <td><?= invoiceEscape($invoice['invoice_reference']) ?></td>
                                    <td><?= invoiceEscape($invoice['bill_to']) ?></td>
                                    <td><?= invoiceEscape(invoiceDate($invoice['issue_date'])) ?></td>
                                    <td><?= invoiceEscape(invoiceDate($invoice['due_date'])) ?></td>
                                    <td>₹<?= number_format((float) $invoice['total_amount'], 2) ?></td>
                                    <td class="invoice-actions">
                                        <a class="btn btn-small btn-light" href="admin.php?page=invoices&view=<?= (int) $invoice['id'] ?>&tab=<?= invoiceEscape($activeTab) ?>"> View </a>
                                        <a class="btn btn-small btn-primary" href="admin.php?page=invoices&edit=<?= (int) $invoice['id'] ?>&tab=<?= invoiceEscape($activeTab) ?>"> Edit </a>
                                        <a class="btn btn-small btn-primary" href="admin.php?page=invoices&view=<?= (int) $invoice['id'] ?>&download=1&tab=<?= invoiceEscape($activeTab) ?>"> Download PDF </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
    <script src="../assets/js/invoices.js?v=<?= time() ?>"></script>
    <?php if ($editInvoice): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const toggleButton = document.getElementById('toggleInvoiceForm');
                if (toggleButton) {
                    toggleButton.click();
                }
            });
        </script>
    <?php endif; ?>
<?php endif; ?>