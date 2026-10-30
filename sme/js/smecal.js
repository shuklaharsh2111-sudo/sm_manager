document.addEventListener("DOMContentLoaded", () => {
    const modal = document.getElementById("smeCalendarModal");
    if (!modal) {
        return;
    }
    // The dashboard content area has a transform animation, which turns
    // position:fixed into "fixed inside the content area" and pushed this
    // calendar to the right/down. Attach it to <body> so it is centred on
    // the screen. (Done before the creative modals so they stay on top.)
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    const dialog = modal.querySelector(".sme-calendar-dialog");
    const companyNameElement = document.getElementById(
        "smeCalendarCompanyName"
    );
    const monthElement = document.getElementById("smeCalendarMonth");
    const grid = document.getElementById("smeCalendarGrid");
    const selectedDateElement = document.getElementById(
        "smeCalendarSelectedDate"
    );
    const selectedCountElement = document.getElementById(
        "smeCalendarSelectedCount"
    );
    const sopList = document.getElementById("smeCalendarSopList");
    const statusElement = document.getElementById("smeCalendarStatus");
    const previousButton = document.getElementById("smeCalendarPrev");
    const nextButton = document.getElementById("smeCalendarNext");
    const todayButton = document.getElementById("smeCalendarToday");
    const endpoint = "../sme/smecal.php";
    // Add Creative modal elements
    const creativeModal = document.getElementById("creativeModal");
    if (creativeModal && creativeModal.parentElement !== document.body) {
        document.body.appendChild(creativeModal);
    }
    const creativeDialog = creativeModal?.querySelector(
        ".creative-modal-dialog"
    );
    const creativeForm = document.getElementById("creativeForm");
    const creativeSopId = document.getElementById("creativeSopId");
    const creativeCompanyId = document.getElementById("creativeCompanyId");
    const creativeSopLabel = document.getElementById("creativeSopLabel");
    const creativeMessage = document.getElementById("creativeMessage");
    const saveCreativeButton = document.getElementById("saveCreativeButton");
    const creativeImage = document.getElementById("creativeImage");
    const creativePreviewImg = document.getElementById("creativePreviewImg");
    const creativeImagePlaceholder = document.getElementById(
        "creativeImagePlaceholder"
    );
    let creativePreviewUrl = null;
    let previousCreativeFocus = null;
    let companyId = null;
    let companyName = "";
    let currentMonth = new Date(
        new Date().getFullYear(),
        new Date().getMonth(),
        1
    );
    let selectedDate = getLocalDate(new Date());
    let sops = [];
    let isLoading = false;
    let requestNumber = 0;
    let previousFocus = null;
    let lastRenderedMonth = "";
    function getLocalDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, "0");
        const day = String(date.getDate()).padStart(2, "0");
        return `${year}-${month}-${day}`;
    }
    function getMonthString(date) {
        return [
            date.getFullYear(),
            String(date.getMonth() + 1).padStart(2, "0")
        ].join("-");
    }
    function resolveCreativeImageUrl(imagePath) {
        const path = String(imagePath ?? "").trim();
        if (!path) {
            console.warn("Creative image path is empty.");
            return "";
        }
        // External image URL
        if (/^https?:\/\//i.test(path)) {
            return path;
        }
        // Reject Windows local file paths
        if (/^[a-z]:[\\/]/i.test(path) || path.startsWith("\\\\")) {
            console.error("Invalid browser image path:", path);
            return "";
        }
        // Remove leading slashes and relative prefixes
        const cleanPath = path
            .replace(/\\/g, "/")
            .replace(/^(?:\.\/|\/|(?:\.\.\/)+)+/, "");
        // Build URL relative to the project root: /usp/
        const projectRoot = new URL(
            "../",
            window.location.origin + window.location.pathname
        );
        return new URL(cleanPath, projectRoot).href;
    }
    function getMonthLabel(date) {
        return date.toLocaleDateString("en-IN", {
            month: "long",
            year: "numeric"
        });
    }
    function formatDate(dateString, options = {}) {
        const parts = dateString.split("-").map(Number);
        const date = new Date(
            parts[0],
            parts[1] - 1,
            parts[2]
        );
        return date.toLocaleDateString("en-IN", {
            day: "numeric",
            month: "short",
            year: "numeric",
            ...options
        });
    }
    function escapeText(value) {
        return String(value ?? "");
    }
    function showStatus(message, type = "info") {
        statusElement.textContent = message;
        statusElement.className = `sme-calendar-status is-${type}`;
        statusElement.hidden = !message;
    }
    function clearStatus() {
        statusElement.textContent = "";
        statusElement.hidden = true;
        statusElement.className = "sme-calendar-status";
    }
    function setLoading(loading) {
        isLoading = loading;
        previousButton.disabled = loading;
        nextButton.disabled = loading;
        todayButton.disabled = loading;
        grid.setAttribute("aria-busy", String(loading));
        if (loading) {
            sopList.replaceChildren();
            const loadingMessage = document.createElement("div");
            loadingMessage.className = "sme-calendar-empty";
            loadingMessage.textContent = "Loading assigned SOPs...";
            sopList.appendChild(loadingMessage);
        }
    }
    function createElement(tag, className, text = "") {
        const element = document.createElement(tag);
        if (className) {
            element.className = className;
        }
        element.textContent = escapeText(text);
        return element;
    }
    function getSopsForDate(dateString) {
        return sops.filter((sop) => sop.sop_date === dateString);
    }
    function renderCalendar() {
        const year = currentMonth.getFullYear();
        const month = currentMonth.getMonth();
        monthElement.textContent = getMonthLabel(currentMonth);
        const fragment = document.createDocumentFragment();
        const firstWeekday = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        for (let i = 0; i < firstWeekday; i++) {
            const emptyCell = createElement(
                "div",
                "sme-calendar-day is-empty"
            );
            emptyCell.setAttribute("aria-hidden", "true");
            fragment.appendChild(emptyCell);
        }
        const today = getLocalDate(new Date());
        for (let day = 1; day <= daysInMonth; day++) {
            const dateString = [
                year,
                String(month + 1).padStart(2, "0"),
                String(day).padStart(2, "0")
            ].join("-");
            const daySops = getSopsForDate(dateString);
            const dateButton = createElement(
                "button",
                "sme-calendar-day",
                ""
            );
            dateButton.type = "button";
            dateButton.dataset.date = dateString;
            dateButton.setAttribute(
                "aria-label",
                `${formatDate(dateString)}, ${daySops.length} SOPs`
            );
            if (dateString === today) {
                dateButton.classList.add("is-today");
            }
            if (dateString === selectedDate) {
                dateButton.classList.add("is-selected");
                dateButton.setAttribute("aria-pressed", "true");
            } else {
                dateButton.setAttribute("aria-pressed", "false");
            }
            const number = createElement(
                "span",
                "sme-calendar-day-number",
                day
            );
            dateButton.appendChild(number);
            if (daySops.length > 0) {
                dateButton.classList.add("has-sops");
                const indicators = createElement(
                    "div",
                    "sme-calendar-day-indicators"
                );
                const types = [
                    "daily",
                    "weekly",
                    "monthly"
                ];
                types.forEach((type) => {
                    if (daySops.some((sop) => sop.sop_type === type)) {
                        const dot = createElement(
                            "span",
                            `sme-calendar-mini-dot ${type}`
                        );
                        dot.setAttribute("aria-hidden", "true");
                        indicators.appendChild(dot);
                    }
                });
                dateButton.appendChild(indicators);
                // Creative thumbnail preview for this calendar date.
                const creativeSops = daySops.filter(
                    (sop) => sop.creative_image && sop.creative_image.trim() !== ""
                );
                if (creativeSops.length > 0) {
                    const thumbnailContainer = createElement(
                        "div",
                        "sme-calendar-day-thumbnails"
                    );
                    creativeSops.slice(0, 2).forEach((sop) => {
                        const thumbnail = document.createElement("img");
                        thumbnail.className = "sme-calendar-day-thumbnail";
                        thumbnail.alt = "Creative: " + (sop.sop_title || "Uploaded creative");
                        thumbnail.loading = "lazy";
                        thumbnail.decoding = "async";
                        const imageUrl = resolveCreativeImageUrl(sop.creative_image);
                        if (!imageUrl) {
                            console.warn("No image URL for SOP:", sop.id);
                            return;
                        }
                        thumbnail.style.display = "block";
                        thumbnail.style.width = "100%";
                        thumbnail.style.height = "70px";
                        thumbnail.style.maxWidth = "100%";
                        thumbnail.style.objectFit = "cover";
                        thumbnail.style.borderRadius = "8px";
                        thumbnail.onerror = function () {
                            console.error("Creative image failed to load:", {
                                sopId: sop.id,
                                databasePath: sop.creative_image,
                                requestedURL: this.src
                            });
                        };
                        thumbnail.src = imageUrl;
                        thumbnailContainer.appendChild(thumbnail);
                    });
                    if (thumbnailContainer.childElementCount > 0) {
                        dateButton.appendChild(thumbnailContainer);
                        dateButton.classList.add("has-creatives");
                    }
                }
                // Existing SOP title preview.
                const preview = createElement(
                    "div",
                    "sme-calendar-day-preview"
                );
                daySops.slice(0, 2).forEach((sop) => {
                    const title = createElement(
                        "span",
                        "sme-calendar-preview-title",
                        sop.sop_title
                    );
                    preview.appendChild(title);
                });
                if (daySops.length > 2) {
                    preview.appendChild(
                        createElement(
                            "span",
                            "sme-calendar-more",
                            `+${daySops.length - 2} more`
                        )
                    );
                }
                dateButton.appendChild(preview);
                const count = createElement(
                    "span",
                    "sme-calendar-day-count",
                    daySops.length
                );
                count.setAttribute("aria-hidden", "true");
                dateButton.appendChild(count);
            }
            fragment.appendChild(dateButton);
        }
        grid.replaceChildren(fragment);
        const totalCells = firstWeekday + daysInMonth;
        const remainder = totalCells % 7;
        if (remainder !== 0) {
            const extraCells = 7 - remainder;
            for (let i = 0; i < extraCells; i++) {
                const emptyCell = createElement(
                    "div",
                    "sme-calendar-day is-empty"
                );
                emptyCell.setAttribute("aria-hidden", "true");
                grid.appendChild(emptyCell);
            }
        }
        lastRenderedMonth = getMonthString(currentMonth);
    }
    function renderSelectedDate() {
        const selectedSops = getSopsForDate(selectedDate);
        selectedDateElement.textContent = formatDate(
            selectedDate,
            { weekday: "long" }
        );
        selectedCountElement.textContent =
            selectedSops.length === 1
                ? "1 assigned SOP"
                : `${selectedSops.length} assigned SOPs`;
        if (selectedSops.length === 0) {
            const empty = createElement(
                "div",
                "sme-calendar-empty"
            );
            empty.appendChild(
                createElement("span", "sme-calendar-empty-icon", "—")
            );
            empty.appendChild(
                createElement(
                    "h4",
                    "",
                    "No SOPs assigned"
                )
            );
            empty.appendChild(
                createElement(
                    "p",
                    "",
                    "There are no dated SOPs for this company on this day."
                )
            );
            sopList.replaceChildren(empty);
            return;
        }
        const fragment = document.createDocumentFragment();
        selectedSops.forEach((sop) => {
            const card = createElement(
                "article",
                `sme-calendar-sop-card type-${sop.sop_type}`
            );
            const top = createElement(
                "div",
                "sme-calendar-sop-top"
            );
            const badge = createElement(
                "span",
                `sme-calendar-type-badge type-${sop.sop_type}`,
                sop.sop_type
            );
            top.appendChild(badge);
            const title = createElement(
                "h4",
                "sme-calendar-sop-title",
                sop.sop_title || "Untitled SOP"
            );
            card.append(top, title);
            if ((sop.sop_description || "").trim() !== "") {
                card.appendChild(
                    createElement(
                        "p",
                        "sme-calendar-sop-description",
                        sop.sop_description
                    )
                );
            } else {
                card.appendChild(
                    createElement(
                        "p",
                        "sme-calendar-sop-description is-muted",
                        "No description provided."
                    )
                );
            }
            // Container for both buttons side by side
            const actionsContainer = createElement("div", "creative-calendar-actions");
            actionsContainer.style.display = "flex";
            actionsContainer.style.gap = "8px";
            actionsContainer.style.marginTop = "12px";
            // Add Creative Button
            const creativeButton = createElement(
                "button",
                "sme-add-creative-button",
                "Add Creative"
            );
            creativeButton.type = "button";
            creativeButton.dataset.sopId = String(sop.id);
            creativeButton.dataset.companyId = String(sop.company_id);
            creativeButton.dataset.sopTitle = String(
                sop.sop_title || "Untitled SOP"
            );
            creativeButton.setAttribute(
                "aria-label",
                `Add creative for ${sop.sop_title || "this SOP"}`
            );
            actionsContainer.appendChild(creativeButton);
            // View Creative Button
            const viewCreativeButton = createElement(
                "button",
                "sme-view-creative-button",
                "View Creative"
            );
            viewCreativeButton.type = "button";
            viewCreativeButton.dataset.sopId = String(sop.id);
            viewCreativeButton.dataset.companyId = String(sop.company_id);
            viewCreativeButton.dataset.sopTitle = String(
                sop.sop_title || "Untitled SOP"
            );
            viewCreativeButton.setAttribute(
                "aria-label",
                `View creative for ${sop.sop_title || "this SOP"}`
            );
            actionsContainer.appendChild(viewCreativeButton);
            card.appendChild(actionsContainer);
            fragment.appendChild(card);
        });
        sopList.replaceChildren(fragment);
    }
    async function loadMonth() {
        if (!companyId || isLoading) {
            return;
        }
        const thisRequest = ++requestNumber;
        const requestedMonth = getMonthString(currentMonth);
        clearStatus();
        setLoading(true);
        try {
            const url = new URL(endpoint, window.location.href);
            url.searchParams.set("action", "fetch_sops");
            url.searchParams.set("company_id", companyId);
            url.searchParams.set("month", requestedMonth);
            const response = await fetch(url.toString(), {
                method: "GET",
                credentials: "same-origin",
                headers: {
                    Accept: "application/json"
                },
                cache: "no-store"
            });
            const contentType = response.headers.get("content-type") || "";
            if (!contentType.includes("application/json")) {
                throw new Error(
                    "The server returned an unexpected response. Check your PHP session and endpoint path."
                );
            }
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || "Unable to load calendar SOPs."
                );
            }
            if (
                thisRequest !== requestNumber ||
                !modal.classList.contains("is-open")
            ) {
                return;
            }
            sops = Array.isArray(result.sops) ? result.sops : [];
            console.table(
                sops.map((sop) => ({
                    sop_id: sop.id,
                    sop_title: sop.sop_title,
                    creative_image: sop.creative_image,
                    resolved_url: resolveCreativeImageUrl(sop.creative_image)
                }))
            );
            companyName = result.company.name || companyName;
            companyNameElement.textContent = companyName;
            renderCalendar();
            renderSelectedDate();
            showStatus(
                `${sops.length} SOP${sops.length === 1 ? "" : "s"} found for ${getMonthLabel(currentMonth)}.`,
                "success"
            );
        } catch (error) {
            if (thisRequest !== requestNumber) {
                return;
            }
            sops = [];
            renderCalendar();
            renderSelectedDate();
            showStatus(
                error.message || "Unable to load calendar SOPs.",
                "error"
            );
        } finally {
            if (thisRequest === requestNumber) {
                setLoading(false);
            }
        }
    }
    function openCalendar(card) {
        if (modal.classList.contains("is-open")) {
            return;
        }
        const id = Number(card.dataset.companyId);
        if (!Number.isInteger(id) || id <= 0) {
            return;
        }
        companyId = id;
        companyName = card.dataset.companyName || "Company Calendar";
        previousFocus = card;
        currentMonth = new Date(
            new Date().getFullYear(),
            new Date().getMonth(),
            1
        );
        selectedDate = getLocalDate(new Date());
        sops = [];
        companyNameElement.textContent = companyName;
        monthElement.textContent = getMonthLabel(currentMonth);
        modal.hidden = false;
        modal.setAttribute("aria-hidden", "false");
        requestAnimationFrame(() => {
            modal.classList.add("is-open");
            document.body.classList.add("sme-calendar-modal-open");
            dialog.focus();
        });
        renderCalendar();
        renderSelectedDate();
        loadMonth();
    }
    function closeCalendar() {
        if (modal.hidden) {
            return;
        }
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
        document.body.classList.remove("sme-calendar-modal-open");
        requestNumber++;
        isLoading = false;
        previousButton.disabled = false;
        nextButton.disabled = false;
        todayButton.disabled = false;
        window.setTimeout(() => {
            modal.hidden = true;
            if (previousFocus && document.contains(previousFocus)) {
                previousFocus.focus();
            }
        }, 180);
    }
    document.querySelectorAll("#smeDashboard .sme-company-card")
        .forEach((card) => {
            card.setAttribute("role", "button");
            card.setAttribute("tabindex", "0");
            card.setAttribute(
                "aria-haspopup",
                "dialog"
            );
            card.addEventListener("click", () => {
                openCalendar(card);
            });
            card.addEventListener("keydown", (event) => {
                if (
                    event.target !== card ||
                    (event.key !== "Enter" && event.key !== " ")
                ) {
                    return;
                }
                event.preventDefault();
                openCalendar(card);
            });
        });
    grid.addEventListener("click", (event) => {
        const dateButton = event.target.closest(
            ".sme-calendar-day[data-date]"
        );
        if (!dateButton || isLoading) {
            return;
        }
        selectedDate = dateButton.dataset.date;
        renderCalendar();
        renderSelectedDate();
    });
    previousButton.addEventListener("click", () => {
        if (isLoading) {
            return;
        }
        currentMonth = new Date(
            currentMonth.getFullYear(),
            currentMonth.getMonth() - 1,
            1
        );
        selectedDate = getLocalDate(currentMonth);
        loadMonth();
    });
    nextButton.addEventListener("click", () => {
        if (isLoading) {
            return;
        }
        currentMonth = new Date(
            currentMonth.getFullYear(),
            currentMonth.getMonth() + 1,
            1
        );
        selectedDate = getLocalDate(currentMonth);
        loadMonth();
    });
    todayButton.addEventListener("click", () => {
        if (isLoading) {
            return;
        }
        const today = new Date();
        currentMonth = new Date(
            today.getFullYear(),
            today.getMonth(),
            1
        );
        selectedDate = getLocalDate(today);
        loadMonth();
    });
    modal.querySelectorAll("[data-calendar-close]").forEach((button) => {
        button.addEventListener("click", closeCalendar);
    });
    document.addEventListener("keydown", (event) => {
        if (modal.hidden) {
            return;
        }
        if (event.key === "Escape") {
            closeCalendar();
        }
    });
    // Open the creative form for the clicked SOP.
    sopList.addEventListener("click", (event) => {
        const button = event.target.closest(".sme-add-creative-button");
        if (!button || !creativeModal || !creativeForm) {
            return;
        }
        event.stopPropagation();
        const sopId = Number(button.dataset.sopId);
        const selectedCompanyId = Number(button.dataset.companyId);
        if (
            !Number.isInteger(sopId) || sopId <= 0 ||
            !Number.isInteger(selectedCompanyId) || selectedCompanyId <= 0
        ) {
            showStatus("Invalid SOP details.", "error");
            return;
        }
        previousCreativeFocus = button;
        creativeForm.reset();
        resetCreativeImagePreview();
        creativeSopId.value = String(sopId);
        creativeCompanyId.value = String(selectedCompanyId);
        creativeSopLabel.textContent =
            button.dataset.sopTitle || "Selected SOP";
        creativeMessage.textContent = "";
        const selectedDateInput = document.getElementById("creativeDate");
        if (selectedDateInput) {
            selectedDateInput.value = selectedDate;
        }
        creativeModal.hidden = false;
        creativeModal.setAttribute("aria-hidden", "false");
        requestAnimationFrame(() => {
            creativeModal.classList.add("is-open");
            document.body.classList.add("creative-modal-open");
            creativeDialog?.focus();
        });
    });
    function closeCreativeModal() {
        if (!creativeModal || creativeModal.hidden) {
            return;
        }
        creativeModal.classList.remove("is-open");
        creativeModal.setAttribute("aria-hidden", "true");
        document.body.classList.remove("creative-modal-open");
        window.setTimeout(() => {
            creativeModal.hidden = true;
            if (
                previousCreativeFocus &&
                document.contains(previousCreativeFocus)
            ) {
                previousCreativeFocus.focus();
            }
        }, 180);
    }
    creativeModal?.querySelectorAll("[data-creative-close]").forEach(
        (button) => {
            button.addEventListener("click", closeCreativeModal);
        }
    );
    function resetCreativeImagePreview() {
        if (creativePreviewUrl) {
            URL.revokeObjectURL(creativePreviewUrl);
            creativePreviewUrl = null;
        }
        if (creativePreviewImg) {
            creativePreviewImg.removeAttribute("src");
            creativePreviewImg.hidden = true;
        }
        if (creativeImagePlaceholder) {
            creativeImagePlaceholder.hidden = false;
        }
    }
    creativeImage?.addEventListener("change", () => {
        const file = creativeImage.files?.[0];
        if (creativePreviewUrl) {
            URL.revokeObjectURL(creativePreviewUrl);
            creativePreviewUrl = null;
        }
        if (!file) {
            resetCreativeImagePreview();
            return;
        }
        const allowedTypes = ["image/jpeg", "image/png", "image/webp"];
        if (!allowedTypes.includes(file.type)) {
            creativeImage.value = "";
            creativeMessage.textContent = "Choose a JPG, PNG or WebP image.";
            creativeMessage.style.color = "#fca5a5";
            resetCreativeImagePreview();
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            creativeImage.value = "";
            creativeMessage.textContent = "Image must be 5 MB or smaller.";
            creativeMessage.style.color = "#fca5a5";
            resetCreativeImagePreview();
            return;
        }
        creativeMessage.textContent = "";
        creativeMessage.style.color = "";
        creativePreviewUrl = URL.createObjectURL(file);
        creativePreviewImg.src = creativePreviewUrl;
        creativePreviewImg.hidden = false;
        creativeImagePlaceholder.hidden = true;
    });
    // Submit creative without reloading the calendar.
    creativeForm?.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (!creativeForm.reportValidity()) {
            return;
        }
        if (!creativeImage?.files?.length) {
            creativeMessage.textContent = "Please select an image.";
            return;
        }
        saveCreativeButton.disabled = true;
        creativeMessage.textContent = "Saving creative...";
        try {
            const response = await fetch(creativeForm.action, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    Accept: "application/json"
                },
                body: new FormData(creativeForm)
            });
            const responseText = await response.text();
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                throw new Error(
                    `Server returned an invalid response (HTTP ${response.status}).`
                );
            }
            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || "Unable to save creative."
                );
            }
            creativeMessage.textContent =
                result.message || "Creative saved successfully.";
            window.setTimeout(() => {
                closeCreativeModal();
                loadMonth();
            }, 900);
        } catch (error) {
            creativeMessage.textContent =
                error.message || "Unable to save creative.";
        } finally {
            saveCreativeButton.disabled = false;
        }
    });
    // View Creative Modal Logic & Event Listener
    const viewModal = document.getElementById("viewCreativeModal");
    if (viewModal && viewModal.parentElement !== document.body) {
        document.body.appendChild(viewModal);
    }
    const viewModalDialog = viewModal?.querySelector(".creative-modal-dialog");
    const viewPreviewImg = document.getElementById("viewCreativePreviewImg");
    const viewImagePlaceholder = document.getElementById("viewCreativeImagePlaceholder");
    const viewSopLabel = document.getElementById("viewCreativeSopLabel");
    const viewPlatform = document.getElementById("viewCreativePlatform");
    const viewTitle = document.getElementById("viewCreativeTitle");
    const viewCaption = document.getElementById("viewCreativeCaption");
    const viewRefLink = document.getElementById("viewCreativeRefLink");
    sopList.addEventListener("click", async (event) => {
        const viewBtn = event.target.closest(".sme-view-creative-button");
        if (!viewBtn || !viewModal) return;
        event.stopPropagation();
        const sopId = viewBtn.dataset.sopId;
        viewSopLabel.textContent = viewBtn.dataset.sopTitle;
        viewPlatform.textContent = "Loading...";
        viewTitle.textContent = "Loading...";
        viewCaption.textContent = "Loading...";
        viewRefLink.textContent = "-";
        viewRefLink.href = "#";
        viewPreviewImg.hidden = true;
        viewPreviewImg.removeAttribute("src");
        viewImagePlaceholder.hidden = false;
        viewImagePlaceholder.textContent = "Loading creative...";
        viewModal.hidden = false;
        viewModal.setAttribute("aria-hidden", "false");
        requestAnimationFrame(() => {
            viewModal.classList.add("is-open");
            document.body.classList.add("creative-modal-open");
            viewModalDialog?.focus();
        });
        try {
            const response = await fetch(
                `${endpoint}?action=fetch_creative&sop_id=${encodeURIComponent(sopId)}`,
                {
                    credentials: "same-origin",
                    headers: { "Accept": "application/json" }
                }
            );
            const result = await response.json();
            if (!response.ok || !result.success || !result.creative) {
                viewImagePlaceholder.textContent = "No creative uploaded";
                viewSopLabel.textContent = "No creative uploaded";
                viewPlatform.textContent = "-";
                viewTitle.textContent = "-";
                viewCaption.textContent = "No data saved for this SOP yet.";
                return;
            }
            const data = result.creative;
            viewSopLabel.textContent = "Uploaded creative";
            viewPlatform.textContent = data.platform || "-";
            viewTitle.textContent = data.title || "-";
            viewCaption.textContent = data.caption || "-";
            const reference = data.reference_link || "";
            if (viewRefLink) {
                viewRefLink.textContent = reference || "-";
                viewRefLink.href = reference || "#";
                viewRefLink.hidden = !reference;
            }
            const imagePath = data.image_path || "";
            const imageUrl = resolveCreativeImageUrl(imagePath);
            viewPreviewImg.onerror = () => {
                console.error("View Creative image failed:", {
                    databasePath: imagePath,
                    browserUrl: viewPreviewImg.src
                });
                viewPreviewImg.hidden = true;
                viewImagePlaceholder.hidden = false;
                viewImagePlaceholder.textContent =
                    "Image could not be loaded. Check the saved image path.";
            };
            if (imageUrl) {
                viewPreviewImg.hidden = true;
                viewImagePlaceholder.hidden = false;
                viewImagePlaceholder.textContent = "Loading image...";
                viewPreviewImg.src = imageUrl;
            } else {
                viewPreviewImg.removeAttribute("src");
                viewPreviewImg.hidden = true;
                viewImagePlaceholder.hidden = false;
                viewImagePlaceholder.textContent = "No valid image path found.";
            }
        } catch (error) {
            console.error("View creative error:", error);
            viewImagePlaceholder.textContent = "Unable to load creative. Please try again.";
        }
    });
    function closeViewModal() {
        if (!viewModal || viewModal.hidden) return;
        viewModal.classList.remove("is-open");
        viewModal.setAttribute("aria-hidden", "true");
        document.body.classList.remove("creative-modal-open");
        window.setTimeout(() => { viewModal.hidden = true; }, 180);
    }
    viewModal?.querySelectorAll("[data-view-creative-close]").forEach((btn) => {
        btn.addEventListener("click", closeViewModal);
    });
    // Escape closes modals
    document.addEventListener("keydown", (event) => {
        if (event.key !== "Escape") {
            return;
        }
        if (creativeModal && !creativeModal.hidden) {
            closeCreativeModal();
        }
        if (viewModal && !viewModal.hidden) {
            closeViewModal();
        }
    });
});