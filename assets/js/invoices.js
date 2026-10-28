document.addEventListener("DOMContentLoaded", () => {
    const modal = document.getElementById("invoiceModal");
    const formCard = document.getElementById("invoiceFormCard");
    const form = document.getElementById("invoiceForm");
    const openButton = document.getElementById("toggleInvoiceForm");
    const closeButton = document.getElementById("closeInvoiceModal");
    const cancelButton = document.getElementById("cancelInvoiceForm");
    const addItemButton = document.getElementById("addInvoiceItem");
    const itemsBody = document.getElementById("invoiceItemsBody");
    const subtotalElement = document.getElementById("formSubtotal");
    const gstRow = document.getElementById("formGstRow");
    const gstElement = document.getElementById("formGst");
    const totalElement = document.getElementById("formTotal");
    const invoiceTypeInput = form?.querySelector('[name="invoice_type"]');
    const sourceInvoiceSelect = document.getElementById("sourceInvoiceSelect");
    const billToSelect = document.getElementById("billToSelect");
    const billGstin = document.getElementById("billGstin");
    const downloadButton = document.getElementById("downloadPdf");
    const invoicePaper = document.getElementById("invoicePaper");
    const formatMoney = (amount) =>
        Number(amount || 0).toLocaleString("en-IN", {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    function openForm() {
        if (modal) {
            modal.classList.add("is-open");
            modal.setAttribute("aria-hidden", "false");
            document.body.classList.add("invoice-modal-open");
        }
        if (formCard) {
            formCard.hidden = false;
        }
        const firstInput = (modal || formCard)?.querySelector(
            'input[name="bill_to"]'
        );
        if (firstInput) {
            firstInput.focus();
        }
        (modal || formCard)?.scrollIntoView({
            behavior: "smooth",
            block: "start"
        });
    }
    function closeForm() {
        if (modal) {
            modal.classList.remove("is-open");
            modal.setAttribute("aria-hidden", "true");
            document.body.classList.remove("invoice-modal-open");
        }
        if (formCard) {
            formCard.hidden = true;
        }
    }
    openButton?.addEventListener("click", openForm);
    closeButton?.addEventListener("click", closeForm);
    cancelButton?.addEventListener("click", closeForm);
    modal?.addEventListener("click", (event) => {
        if (event.target === modal) {
            closeForm();
        }
    });
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && modal?.classList.contains("is-open")) {
            closeForm();
        }
    });
    billToSelect?.addEventListener("change", () => {
        const selectedOption = billToSelect.selectedOptions[0];
        if (billGstin && selectedOption) {
            billGstin.value = selectedOption.dataset.gstin || "";
        }
    });
    function updateItems() {
        if (!itemsBody) return;
        let subtotal = 0;
        itemsBody.querySelectorAll(".invoice-item-row").forEach((row, index) => {
            const sr = row.querySelector(".item-sr");
            const rateInput = row.querySelector('[name="rate[]"]');
            const amountInput = row.querySelector(".item-amount");
            const rate = Number(rateInput?.value || 0);
            // Amount will depend only on Rate, not Quantity.
            const amount =
                Number.isFinite(rate) && rate >= 0
                    ? Math.round((rate + Number.EPSILON) * 100) / 100
                    : 0;
            if (sr) {
                sr.textContent = String(index + 1);
            }
            if (amountInput) {
                amountInput.value = formatMoney(amount);
            }
            subtotal += amount;
        });
        subtotal = Math.round((subtotal + Number.EPSILON) * 100) / 100;
        const invoiceType = (
            form?.querySelector('[name="invoice_type"]')?.value || ""
        ).trim().toUpperCase();
        const isTaxInvoice = invoiceType === "TI";
        // Calculate GST at 18% for Tax Invoice only.
        const gst = isTaxInvoice
            ? Math.round((subtotal * 18 / 100 + Number.EPSILON) * 100) / 100
            : 0;
        const finalTotal = Math.round(
            (subtotal + gst + Number.EPSILON) * 100
        ) / 100;
        if (subtotalElement) {
            subtotalElement.textContent = "₹" + formatMoney(subtotal);
        }
        if (gstElement) {
            gstElement.textContent = "₹" + formatMoney(gst);
        }
        if (gstRow) {
            gstRow.hidden = !isTaxInvoice;
        }
        if (totalElement) {
            totalElement.textContent = "₹" + formatMoney(finalTotal);
        }
    }
    function addItemRow() {
        if (!itemsBody) return;
        if (itemsBody.querySelectorAll(".invoice-item-row").length >= 100) {
            alert("Maximum 100 items can be added.");
            return;
        }
        const row = document.createElement("tr");
        row.className = "invoice-item-row";
        row.innerHTML = `
            <td class="item-sr"></td>
            <td>
                <input type="text" name="item_name[]" required
                       placeholder="Item name">
            </td>
            <td>
                <input type="text" name="description[]"
                       placeholder="Description">
            </td>
            <td>
                <input type="number" name="quantity[]" min="0.01"
                       step="0.01" value="1" required>
            </td>
            <td>
                <input type="number" name="rate[]" min="0"
                       step="0.01" value="0" required>
            </td>
            <td>
                <input type="text" class="item-amount"
                       value="0.00" readonly>
            </td>
            <td>
                <button type="button" class="remove-item"
                        aria-label="Remove item">×</button>
            </td>
        `;
        itemsBody.appendChild(row);
        updateItems();
        row.querySelector('[name="item_name[]"]')?.focus();
    }
    addItemButton?.addEventListener("click", addItemRow);
    itemsBody?.addEventListener("input", (event) => {
        if (event.target.matches('[name="quantity[]"], [name="rate[]"]')) {
            updateItems();
        }
    });
    itemsBody?.addEventListener("click", (event) => {
        const removeButton = event.target.closest(".remove-item");
        if (!removeButton) return;
        const row = removeButton.closest(".invoice-item-row");
        if (!row) return;
        const rows = itemsBody.querySelectorAll(".invoice-item-row");
        if (rows.length === 1) {
            row.querySelector('[name="item_name[]"]').value = "";
            row.querySelector('[name="description[]"]').value = "";
            row.querySelector('[name="quantity[]"]').value = "1";
            row.querySelector('[name="rate[]"]').value = "0";
        } else {
            row.remove();
        }
        updateItems();
    });
    const originalFormValues = {};
    [
        "bill_to",
        "company_contact",
        "gstin",
        "company_pan",
        "ship_to",
        "ship_gstin",
        "terms",
        "notes",
        "issue_date",
        "due_date"
    ].forEach((name) => {
        const field = form?.querySelector(`[name="${name}"]`);
        if (field) {
            originalFormValues[name] = field.value;
        }
    });
    function fillInvoiceFromSource(invoice) {
        if (!form || !itemsBody) return;
        const fields = [
            "bill_to",
            "company_contact",
            "gstin",
            "company_pan",
            "ship_to",
            "ship_gstin",
            "terms",
            "notes"
        ];
        fields.forEach((name) => {
            const field = form.querySelector(`[name="${name}"]`);
            if (field) {
                field.value = invoice[name] || "";
            }
        });
        const issueDate = form.querySelector('[name="issue_date"]');
        const dueDate = form.querySelector('[name="due_date"]');
        if (issueDate) {
            issueDate.value = originalFormValues.issue_date || "";
        }
        if (dueDate) {
            dueDate.value = "";
        }
        if (billToSelect) {
            const matchingOption = Array.from(billToSelect.options).find(
                (option) => option.value === invoice.bill_to
                    || option.textContent.trim() === invoice.bill_to
            );
            if (matchingOption) {
                billToSelect.value = matchingOption.value;
                if (billGstin) {
                    billGstin.value =
                        matchingOption.dataset.gstin || invoice.gstin || "";
                }
            }
        }
        itemsBody.innerHTML = "";
        const sourceItems = Array.isArray(invoice.items)
            ? invoice.items
            : [];
        if (sourceItems.length === 0) {
            addItemRow();
            updateItems();
            return;
        }
        sourceItems.forEach((item) => {
            addItemRow();
            const row = itemsBody.lastElementChild;
            row.querySelector('[name="item_name[]"]').value =
                item.item_name || "";
            row.querySelector('[name="description[]"]').value =
                item.description || "";
            row.querySelector('[name="quantity[]"]').value =
                item.quantity ?? 1;
            row.querySelector('[name="rate[]"]').value =
                item.rate ?? 0;
        });
        updateItems();
    }
    function resetInvoiceForm() {
        if (!form || !itemsBody) return;
        Object.entries(originalFormValues).forEach(([name, value]) => {
            const field = form.querySelector(`[name="${name}"]`);
            if (field) {
                field.value = value;
            }
        });
        if (sourceInvoiceSelect) {
            sourceInvoiceSelect.value = "";
        }
        itemsBody.innerHTML = "";
        addItemRow();
        updateItems();
        if (billToSelect && billGstin) {
            const option = billToSelect.selectedOptions[0];
            billGstin.value = option?.dataset.gstin || "";
        }
    }
    sourceInvoiceSelect?.addEventListener("change", () => {
        const selectedOption =
            sourceInvoiceSelect.options[sourceInvoiceSelect.selectedIndex];
        const invoiceData = selectedOption?.dataset.invoice;
        if (!invoiceData) {
            resetInvoiceForm();
            return;
        }
        try {
            const invoice = JSON.parse(invoiceData);
            fillInvoiceFromSource(invoice);
        } catch (error) {
            console.error("Unable to load invoice details:", error);
            alert("Invoice details could not be loaded. Please try again.");
        }
    });
    form?.addEventListener("submit", (event) => {
        if (!form.reportValidity()) {
            event.preventDefault();
            return;
        }
        const rows = itemsBody?.querySelectorAll(".invoice-item-row") || [];
        if (
            rows.length === 0 ||
            !Array.from(rows).some((row) =>
                row.querySelector('[name="item_name[]"]')?.value.trim()
            )
        ) {
            event.preventDefault();
            alert("Please add at least one item with an item name.");
        }
    });
    async function downloadInvoicePdf() {
        if (!invoicePaper) return;
        if (typeof html2pdf === "undefined") {
            alert("PDF library could not load. Check your internet connection and retry.");
            return;
        }
        const originalText = downloadButton?.textContent;
        if (downloadButton) {
            downloadButton.disabled = true;
            downloadButton.textContent = "Preparing PDF...";
        }
        try {
            const images = Array.from(invoicePaper.querySelectorAll("img"));
            await Promise.all(
                images.map((img) => {
                    if (img.complete) return Promise.resolve();
                    return new Promise((resolve) => {
                        img.onload = resolve;
                        img.onerror = resolve;
                    });
                })
            );
            const options = {
                margin: [5, 5, 5, 5], // Top, left, bottom, right margins ko optimize kiya
                filename: window.invoicePdfName || "invoice.pdf",
                image: {
                    type: "jpeg",
                    quality: 0.98
                },
                html2canvas: {
                    scale: 2,
                    useCORS: true,
                    backgroundColor: "#ffffff",
                    scrollX: 0,
                    scrollY: 0,
                    logging: false
                },
                jsPDF: {
                    unit: "mm",
                    format: "a4",
                    orientation: "portrait",
                    compress: true
                },
                pagebreak: {
                    mode: ["css", "legacy"],
                    avoid: [
                        ".pdf-header",
                        ".party-combined-table",
                        ".pdf-meta-table",
                        ".pdf-totals",
                        ".amount-words",
                        ".invoice-bank-details",
                        ".pdf-footer",
                        ".pfi-disclaimer"
                    ]
                }
            };
            await html2pdf()
                .set(options)
                .from(invoicePaper)
                .save();
        } catch (error) {
            console.error("Invoice PDF error:", error);
            alert("PDF could not be generated. Please try again.");
        } finally {
            if (downloadButton) {
                downloadButton.disabled = false;
                downloadButton.textContent =
                    originalText || "Download as PDF";
            }
        }
    }
    downloadButton?.addEventListener("click", downloadInvoicePdf);
    const query = new URLSearchParams(window.location.search);
    if (invoicePaper && query.get("download") === "1") {
        window.setTimeout(downloadInvoicePdf, 500);
    }
    updateItems();
});
