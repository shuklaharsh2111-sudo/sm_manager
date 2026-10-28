document.addEventListener("DOMContentLoaded", function () {
    "use strict";
    const root = document.getElementById("desCalendar");
    if (!root) return;
    const $ = (id) => document.getElementById(id);
    const daysContainer = $("calDays");
    const monthLabel = $("calMonthLabel");
    const selectedLabel = $("calSelectedLabel");
    const selectedCount = $("calSelectedCount");
    const agenda = $("calAgenda");
    const modal = $("calModal");
    const modalCard = $("calModalContent");
    const modalBody = $("calModalBody");
    const csrfToken = root.dataset.csrf || "";
    const saveUrl = root.dataset.saveUrl || "save_creative.php";
    if (
        !daysContainer || !monthLabel || !selectedLabel ||
        !selectedCount || !agenda || !modal || !modalCard || !modalBody
    ) {
        console.error("Calendar: one or more required elements are missing.");
        return;
    }
    let creatives = [];
    try {
        const dataElement = $("calCreativeData");
        creatives = JSON.parse(dataElement?.textContent || "[]");
        if (!Array.isArray(creatives)) creatives = [];
    } catch (error) {
        console.error("Could not parse creative data:", error);
    }
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    let visibleMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    let selectedDate = formatDate(today);
    let lastFocusedElement = null;
    const monthFormatter = new Intl.DateTimeFormat("en-IN", {
        month: "long",
        year: "numeric"
    });
    const selectedFormatter = new Intl.DateTimeFormat("en-IN", {
        day: "numeric",
        month: "short",
        year: "numeric"
    });
    const fullDateFormatter = new Intl.DateTimeFormat("en-IN", {
        weekday: "long",
        day: "numeric",
        month: "long",
        year: "numeric"
    });
    function formatDate(date) {
        return [
            date.getFullYear(),
            String(date.getMonth() + 1).padStart(2, "0"),
            String(date.getDate()).padStart(2, "0")
        ].join("-");
    }
    function parseDate(value) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value || ""))) {
            return null;
        }
        const [year, month, day] = value.split("-").map(Number);
        const date = new Date(year, month - 1, day);
        if (
            date.getFullYear() !== year ||
            date.getMonth() !== month - 1 ||
            date.getDate() !== day
        ) {
            return null;
        }
        return date;
    }
    function escapeHTML(value) {
        return String(value ?? "").replace(/[&<>"']/g, (char) => ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#039;"
        })[char]);
    }
    function safeUrl(value) {
        const input = String(value || "").trim();
        try {
            const url = new URL(input, window.location.href);
            if (!["http:", "https:"].includes(url.protocol)) return "";
            return url.href;
        } catch {
            return "";
        }
    }
    function resolveImagePath(value) {
        const input = String(value || "").trim();
        if (!input || /[\u0000-\u001f\u007f]/.test(input)) return "";
        if (/^https?:\/\//i.test(input)) return safeUrl(input);
        if (input.startsWith("//")) return "";
        if (input.startsWith("/")) return input;
        const cleanPath = input.replace(/\\/g, "/");
        if (cleanPath.split("/").some(
            (part) => part === "." || part === ".."
        )) {
            return "";
        }
        // Supports paths stored as uploads/creatives/image.jpg.
        return "../" + cleanPath;
    }
    function formatMonthKey(date) {
        return date.getFullYear() + "-" +
            String(date.getMonth() + 1).padStart(2, "0");
    }
    function getCreativesForDate(date) {
        return creatives.filter((item) => item.date === date);
    }
    function statusClass(status) {
        switch (String(status || "").toLowerCase()) {
            case "approved": return "status-approved";
            case "pending": return "status-pending";
            case "revision": return "status-revision";
            case "rejected": return "status-rejected";
            default: return "status-draft";
        }
    }
    function formatStatus(status) {
        const value = String(status || "draft");
        return value.charAt(0).toUpperCase() + value.slice(1);
    }
    function renderStats() {
        const monthKey = formatMonthKey(visibleMonth);
        const monthItems = creatives.filter(
            (item) => String(item.date || "").startsWith(monthKey)
        );
        $("calTotal").textContent = creatives.length;
        $("calMonthTotal").textContent = monthItems.length;
        $("calApproved").textContent = creatives.filter(
            (item) => String(item.status).toLowerCase() === "approved"
        ).length;
        $("calPending").textContent = creatives.filter((item) => {
            const status = String(item.status).toLowerCase();
            return status === "pending" || status === "revision";
        }).length;
    }
    function renderCalendar() {
        monthLabel.textContent = monthFormatter.format(visibleMonth);
        const year = visibleMonth.getFullYear();
        const month = visibleMonth.getMonth();
        const start = new Date(year, month, 1);
        const gridStart = new Date(year, month, 1 - start.getDay());
        daysContainer.innerHTML = "";
        for (let index = 0; index < 42; index++) {
            const date = new Date(gridStart);
            date.setDate(gridStart.getDate() + index);
            const key = formatDate(date);
            const items = getCreativesForDate(key);
            const cell = document.createElement("button");
            cell.type = "button";
            cell.className = "cal-day";
            cell.dataset.date = key;
            if (date.getMonth() !== month) cell.classList.add("is-outside");
            if (key === formatDate(today)) cell.classList.add("is-today");
            if (key === selectedDate) cell.classList.add("is-selected");
            cell.setAttribute(
                "aria-label",
                fullDateFormatter.format(date) +
                ", " + items.length + " creatives. Click to add a creative."
            );
            const chips = items.slice(0, 3).map((item) => `
                <span class="cal-day-chip ${statusClass(item.status)}"
                    title="${escapeHTML(item.title || "Untitled Creative")}">
                    ${escapeHTML(item.title || "Untitled Creative")}
                </span>
            `).join("");
            const more = items.length > 3
                ? `<span class="cal-day-more">+${items.length - 3} more</span>`
                : "";
            cell.innerHTML = `
                <span class="cal-day-number">${date.getDate()}</span>
                ${items.length
                    ? `<span class="cal-day-count">${items.length}</span>`
                    : ""}
                <span class="cal-day-items">${chips}${more}</span>
                <span class="cal-day-add-hint">+ Add</span>
            `;
            daysContainer.appendChild(cell);
        }
        renderStats();
    }
    function renderAgenda() {
        const date = parseDate(selectedDate);
        selectedLabel.textContent = date
            ? selectedFormatter.format(date)
            : "Selected date";
        const items = getCreativesForDate(selectedDate);
        selectedCount.textContent = items.length;
        agenda.innerHTML = "";
        if (!items.length) {
            agenda.innerHTML = `
                <div class="cal-empty">
                    <div class="cal-empty-icon">✦</div>
                    <h4>No assigned creatives</h4>
                    <p>Click a calendar date to create a new creative.</p>
                    <button type="button" class="cal-view-btn"
                        data-add-for-date="${escapeHTML(selectedDate)}">
                        + Add Creative
                    </button>
                </div>
            `;
            return;
        }
        items.forEach((item, index) => {
            const card = document.createElement("article");
            card.className = "cal-agenda-card";
            card.style.animationDelay = `${Math.min(index * 45, 250)}ms`;
            const image = resolveImagePath(item.image);
            card.innerHTML = `
                ${image
                    ? `<img class="cal-agenda-thumb"
                        src="${escapeHTML(image)}"
                        alt="${escapeHTML(item.title)}"
                        loading="lazy">`
                    : `<div class="cal-agenda-thumb is-missing">✦</div>`}
                <h4>${escapeHTML(item.title || "Untitled Creative")}</h4>
                <div class="cal-agenda-company">
                    ${escapeHTML(item.company || "Unassigned Company")}
                </div>
                <div class="cal-agenda-meta">
                    <span class="cal-platform">
                        ${escapeHTML(item.platform || "Other")}
                    </span>
                    <span class="cal-status ${statusClass(item.status)}">
                        ${escapeHTML(formatStatus(item.status))}
                    </span>
                </div>
                <button type="button" class="cal-view-btn"
                    data-view-creative="${Number(item.id)}">
                    View Creative Details
                </button>
            `;
            agenda.appendChild(card);
        });
        const addButton = document.createElement("button");
        addButton.type = "button";
        addButton.className = "cal-view-btn cal-add-another";
        addButton.dataset.addForDate = selectedDate;
        addButton.textContent = "+ Add Another Creative";
        agenda.appendChild(addButton);
    }
    function renderAll() {
        renderCalendar();
        renderAgenda();
    }
    function openModal() {
        lastFocusedElement = document.activeElement;
        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
        requestAnimationFrame(() => {
            modalCard.focus();
        });
    }
    function closeModal() {
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
        if (lastFocusedElement && typeof lastFocusedElement.focus === "function") {
            lastFocusedElement.focus();
        }
    }
    function showMessage(message, type) {
        const box = $("calFormMessage");
        if (!box) return;
        box.className = "cal-form-message " +
            (type === "success" ? "is-success" : "is-error");
        box.textContent = message;
        box.hidden = false;
    }
    function openAddCreativeModal(dateValue) {
        const date = parseDate(dateValue);
        if (!date) return;
        selectedDate = dateValue;
        visibleMonth = new Date(date.getFullYear(), date.getMonth(), 1);
        renderAll();
        modalBody.innerHTML = `
            <div class="cal-form-heading">
                <span class="cal-form-kicker">CREATIVE STUDIO / NEW ENTRY</span>
                <h2 id="calModalTitle">Add <span>Creative</span></h2>
                <p>Bring your next creative idea to life.</p>
            </div>
            <form id="calAddCreativeForm" class="cal-add-form"
                enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token"
                    value="${escapeHTML(csrfToken)}">
                <input type="hidden" name="creative_date"
                    value="${escapeHTML(dateValue)}">
                <div class="cal-selected-date">
                    <span class="cal-date-icon">◷</span>
                    <div>
                        <small>CREATIVE SCHEDULE DATE</small>
                        <strong>${escapeHTML(fullDateFormatter.format(date))}</strong>
                    </div>
                </div>
                <div class="cal-upload-zone" id="calUploadZone">
                    <input type="file" id="calCreativeImage"
                        name="creative_image"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        required>
                    <div class="cal-upload-placeholder" id="calUploadPlaceholder">
                        <span class="cal-upload-icon">↑</span>
                        <strong>Upload your creative</strong>
                        <span>JPG, PNG, WEBP or GIF · Maximum 5 MB</span>
                        <span class="cal-upload-browse">Choose Image</span>
                    </div>
                    <img id="calImagePreview" class="cal-image-preview"
                        alt="Selected creative preview" hidden>
                    <button type="button" id="calRemoveImage"
                        class="cal-remove-image" hidden>
                        Remove image
                    </button>
                </div>
                <label class="cal-field-label" for="calCreativeTitle">
                    Creative Title <span>*</span>
                </label>
                <input class="cal-form-input" type="text"
                    id="calCreativeTitle" name="title"
                    maxlength="255" placeholder="Enter a creative title"
                    required>
                <label class="cal-field-label" for="calCreativePlatform">
                    Social Platform <span>*</span>
                </label>
                <select class="cal-form-input" id="calCreativePlatform"
                    name="platform" required>
                    <option value="instagram">Instagram</option>
                    <option value="facebook">Facebook</option>
                    <option value="linkedin">LinkedIn</option>
                    <option value="google">Google</option>
                    <option value="youtube">YouTube</option>
                    <option value="x">X</option>
                    <option value="pinterest">Pinterest</option>
                    <option value="threads">Threads</option>
                </select>
                <label class="cal-field-label" for="calCreativeDescription">
                    Description
                </label>
                <textarea class="cal-form-input" id="calCreativeDescription"
                    name="description" rows="3"
                    placeholder="Describe the creative concept..."></textarea>
                <label class="cal-field-label" for="calCreativeCaption">
                    Caption
                </label>
                <textarea class="cal-form-input" id="calCreativeCaption"
                    name="caption" rows="3"
                    placeholder="Write your social media caption..."></textarea>
                <div id="calFormMessage" class="cal-form-message" hidden
                    role="status" aria-live="polite"></div>
                <div class="cal-form-actions">
                    <button type="button" class="cal-cancel-btn"
                        data-close-calendar-modal>Cancel</button>
                    <button type="submit" class="cal-submit-btn"
                        id="calSaveCreative">
                        <span class="cal-submit-label">Save Creative</span>
                        <span class="cal-submit-spinner" hidden></span>
                    </button>
                </div>
            </form>
        `;
        openModal();
        wireAddForm();
        $("calCreativeTitle")?.focus();
    }
    function wireAddForm() {
        const form = $("calAddCreativeForm");
        const fileInput = $("calCreativeImage");
        const preview = $("calImagePreview");
        const placeholder = $("calUploadPlaceholder");
        const removeButton = $("calRemoveImage");
        const uploadZone = $("calUploadZone");
        if (!form || !fileInput || !preview) return;
        function resetImage() {
            fileInput.value = "";
            preview.removeAttribute("src");
            preview.hidden = true;
            placeholder.hidden = false;
            removeButton.hidden = true;
            uploadZone.classList.remove("has-image");
        }
        fileInput.addEventListener("change", function () {
            const file = fileInput.files?.[0];
            if (!file) {
                resetImage();
                return;
            }
            const allowed = [
                "image/jpeg", "image/png", "image/webp", "image/gif"
            ];
            if (!allowed.includes(file.type) || file.size > 5 * 1024 * 1024) {
                resetImage();
                showMessage(
                    "Choose a valid JPG, PNG, WEBP or GIF image up to 5 MB.",
                    "error"
                );
                return;
            }
            const reader = new FileReader();
            reader.onload = function (event) {
                preview.src = event.target.result;
                preview.hidden = false;
                placeholder.hidden = true;
                removeButton.hidden = false;
                uploadZone.classList.add("has-image");
            };
            reader.readAsDataURL(file);
        });
        removeButton.addEventListener("click", resetImage);
        form.addEventListener("submit", async function (event) {
            event.preventDefault();
            const titleInput = $("calCreativeTitle");
            const image = fileInput.files?.[0];
            if (!image) {
                showMessage("Please select a creative image.", "error");
                return;
            }
            if (!titleInput.value.trim()) {
                showMessage("Please enter the creative title.", "error");
                titleInput.focus();
                return;
            }
            const saveButton = $("calSaveCreative");
            const saveLabel = saveButton.querySelector(".cal-submit-label");
            const spinner = saveButton.querySelector(".cal-submit-spinner");
            saveButton.disabled = true;
            saveLabel.textContent = "Saving Creative...";
            spinner.hidden = false;
            form.querySelectorAll("input, textarea, select").forEach(
                (element) => element.disabled = true
            );
            try {
                const response = await fetch(saveUrl, {
                    method: "POST",
                    body: new FormData(form),
                    credentials: "same-origin",
                    headers: {
                        "Accept": "application/json",
                        "X-Requested-With": "XMLHttpRequest"
                    }
                });
                const result = await response.json().catch(() => null);
                if (!response.ok || !result || !result.success) {
                    throw new Error(
                        result?.message || "Unable to save creative."
                    );
                }
                const saved = result.creative;
                const existingIndex = creatives.findIndex(
                    (item) => Number(item.id) === Number(saved.id)
                );
                if (existingIndex >= 0) {
                    creatives[existingIndex] = {
                        ...creatives[existingIndex],
                        ...saved
                    };
                } else {
                    creatives.push(saved);
                }
                selectedDate = saved.date;
                const savedDate = parseDate(saved.date);
                visibleMonth = new Date(
                    savedDate.getFullYear(),
                    savedDate.getMonth(),
                    1
                );
                renderAll();
                showMessage(result.message || "Creative saved!", "success");
                /*
                 * Brief success feedback, then close the modal.
                 * The calendar is already updated without reloading.
                 */
                saveLabel.textContent = "Saved Successfully!";
                spinner.hidden = true;
                window.setTimeout(() => {
                    closeModal();
                }, 650);
            } catch (error) {
                showMessage(
                    error.message || "Something went wrong. Please try again.",
                    "error"
                );
                saveButton.disabled = false;
                saveLabel.textContent = "Save Creative";
                spinner.hidden = true;
                form.querySelectorAll("input, textarea, select").forEach(
                    (element) => element.disabled = false
                );
            }
        });
    }
    function openCreative(creativeId) {
        const item = creatives.find(
            (creative) => Number(creative.id) === Number(creativeId)
        );
        if (!item) return;
        const image = resolveImagePath(item.image);
        const reference = safeUrl(item.reference);
        const fields = [
            ["Company", item.company],
            ["Creative Date", item.date],
            ["Platform", item.platform],
            ["Created By", item.creator],
            ["Assignment Date", item.assigned_at],
            ["Caption", item.caption],
            ["Description", item.description]
        ];
        const fieldsMarkup = fields
            .filter((field) => String(field[1] ?? "").trim() !== "")
            .map((field) => `
                <div class="cal-modal-field">
                    <strong>${escapeHTML(field[0])}</strong>
                    <p>${escapeHTML(field[1])}</p>
                </div>
            `).join("");
        modalBody.innerHTML = `
            ${image ? `
                <img class="cal-modal-image"
                    src="${escapeHTML(image)}"
                    alt="${escapeHTML(item.title || "Creative")}">
            ` : ""}
            <div class="cal-form-heading">
                <span class="cal-form-kicker">CREATIVE DETAILS</span>
                <h2 id="calModalTitle">
                    ${escapeHTML(item.title || "Untitled Creative")}
                </h2>
            </div>
            <div class="cal-modal-tags">
                <span class="cal-status ${statusClass(item.status)}">
                    ${escapeHTML(formatStatus(item.status))}
                </span>
                <span class="cal-status status-draft">
                    ${escapeHTML(item.platform || "Other")}
                </span>
            </div>
            ${fieldsMarkup}
            ${reference ? `
                <a class="cal-modal-link"
                    href="${escapeHTML(reference)}"
                    target="_blank" rel="noopener noreferrer">
                    Open Reference Link ↗
                </a>
            ` : ""}
            <button type="button" class="cal-submit-btn cal-detail-add"
                data-add-for-date="${escapeHTML(item.date)}">
                + Add Creative on This Date
            </button>
        `;
        openModal();
    }
    /* Expose this function in case another component needs to open the form. */
    window.openAddCreativeModal = openAddCreativeModal;
    $("calPrev")?.addEventListener("click", function () {
        visibleMonth = new Date(
            visibleMonth.getFullYear(),
            visibleMonth.getMonth() - 1,
            1
        );
        renderCalendar();
    });
    $("calNext")?.addEventListener("click", function () {
        visibleMonth = new Date(
            visibleMonth.getFullYear(),
            visibleMonth.getMonth() + 1,
            1
        );
        renderCalendar();
    });
    $("calToday")?.addEventListener("click", function () {
        visibleMonth = new Date(today.getFullYear(), today.getMonth(), 1);
        selectedDate = formatDate(today);
        renderAll();
    });
    daysContainer.addEventListener("click", function (event) {
        const cell = event.target.closest(".cal-day");
        if (!cell) return;
        openAddCreativeModal(cell.dataset.date);
    });
    agenda.addEventListener("click", function (event) {
        const addButton = event.target.closest("[data-add-for-date]");
        if (addButton) {
            openAddCreativeModal(addButton.dataset.addForDate);
            return;
        }
        const viewButton = event.target.closest("[data-view-creative]");
        if (viewButton) {
            openCreative(viewButton.dataset.viewCreative);
        }
    });
    modal.addEventListener("click", function (event) {
        if (event.target.closest("[data-close-calendar-modal]")) {
            closeModal();
        }
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && modal.classList.contains("is-open")) {
            closeModal();
        }
    });
    agenda.addEventListener("error", function (event) {
        const image = event.target;
        if (
            image instanceof HTMLImageElement &&
            image.classList.contains("cal-agenda-thumb")
        ) {
            const placeholder = document.createElement("div");
            placeholder.className = "cal-agenda-thumb is-missing";
            placeholder.textContent = "✦";
            image.replaceWith(placeholder);
        }
    }, true);
    renderAll();
});
