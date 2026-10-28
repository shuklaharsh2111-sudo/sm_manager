document.addEventListener("DOMContentLoaded", () => {
    const root = document.getElementById("adminCreatives");
    if (!root || root.dataset.initialized === "true") return;
    root.dataset.initialized = "true";
    const endpoint = root.dataset.endpoint || "admincreatives.php";
    const csrfToken = root.dataset.csrf || "";
    const grid = root.querySelector("#acGrid");
    const searchInput = root.querySelector("#acSearch");
    const statusFilter = root.querySelector("#acStatusFilter");
    const platformFilter = root.querySelector("#acPlatformFilter");
    const resultsCount = root.querySelector("#acResultsCount");
    const emptyState = root.querySelector("#acEmpty");
    const notice = root.querySelector("#acNotice");
    const viewModal = root.querySelector("#acViewModal");
    const revisionModal = root.querySelector("#acRevisionModal");
    const viewTitle = root.querySelector("#acViewTitle");
    const viewPreview = root.querySelector("#acViewPreview");
    const viewStatus = root.querySelector("#acViewStatus");
    const viewDetails = root.querySelector("#acViewDetails");
    const viewText = root.querySelector("#acViewText");
    const viewReference = root.querySelector("#acViewReference");
    const revisionReason = root.querySelector("#acRevisionReason");
    const reasonCount = root.querySelector("#acReasonCount");
    const submitRevision = root.querySelector("#acSubmitRevision");
    let activeCreativeId = null;
    let noticeTimer = null;
    let requestInProgress = false;
    const cards = () => Array.from(grid.querySelectorAll(".ac-card"));
    function escapeHTML(value) {
        return String(value ?? "").replace(/[&<>"']/g, char => ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#039;"
        })[char]);
    }
    function getCreative(card) {
        try {
            return JSON.parse(card.dataset.creative || "{}");
        } catch (error) {
            console.error("Invalid creative data:", error);
            return {};
        }
    }
    function showNotice(message, type = "success") {
        clearTimeout(noticeTimer);
        notice.textContent = message;
        notice.className = `ac-notice is-visible is-${type}`;
        notice.scrollIntoView({
            behavior: "smooth",
            block: "nearest"
        });
        noticeTimer = setTimeout(() => {
            notice.className = "ac-notice";
            notice.textContent = "";
        }, 5000);
    }
    function getStatus(card) {
        return card.dataset.status || "draft";
    }
    function getStatusLabel(status) {
        const labels = {
            pending: "Pending",
            approved: "Approved",
            revision: "Revision",
            draft: "Draft",
            rejected: "Rejected"
        };
        return labels[status] || "Draft";
    }
    function updateStats() {
        const allCards = cards();
        const counts = {
            all: allCards.length,
            pending: 0,
            approved: 0,
            revision: 0,
            draft: 0,
            rejected: 0
        };
        allCards.forEach(card => {
            const status = getStatus(card);
            if (Object.prototype.hasOwnProperty.call(counts, status)) {
                counts[status]++;
            }
        });
        root.querySelectorAll(".ac-stat").forEach(stat => {
            const filter = stat.dataset.filter;
            const number = stat.querySelector("strong");
            if (number && counts[filter] !== undefined) {
                number.textContent = counts[filter];
            }
            stat.classList.toggle(
                "active",
                filter === statusFilter.value
            );
        });
        const total = root.querySelector(".ac-total-card strong");
        if (total) total.textContent = counts.all;
    }
    function applyFilters() {
        const query = searchInput.value.trim().toLowerCase();
        const selectedStatus = statusFilter.value;
        const selectedPlatform = platformFilter.value;
        let visibleCount = 0;
        cards().forEach(card => {
            const matchesSearch =
                (card.dataset.search || "").includes(query);
            const matchesStatus =
                selectedStatus === "all" ||
                getStatus(card) === selectedStatus;
            const matchesPlatform =
                selectedPlatform === "all" ||
                card.dataset.platform === selectedPlatform;
            const visible =
                matchesSearch && matchesStatus && matchesPlatform;
            card.hidden = !visible;
            if (visible) visibleCount++;
        });
        resultsCount.textContent =
            `${visibleCount} result${visibleCount === 1 ? "" : "s"}`;
        emptyState.hidden = visibleCount !== 0;
        updateStats();
    }
    function openModal(modal) {
        closeModalElements();
        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
        const closeButton = modal.querySelector(".ac-modal-close");
        if (closeButton) closeButton.focus();
    }
    function closeModalElements() {
        [viewModal, revisionModal].forEach(modal => {
            modal.classList.remove("is-open");
            modal.setAttribute("aria-hidden", "true");
        });
        document.body.style.overflow = "";
    }
    function closeModals() {
        closeModalElements();
        activeCreativeId = null;
    }
    function detail(label, value) {
        return `
            <div class="ac-detail">
                <small>${escapeHTML(label)}</small>
                <strong>${escapeHTML(value || "—")}</strong>
            </div>
        `;
    }
    function textSection(title, text) {
        if (!String(text || "").trim()) return "";
        return `
            <section class="ac-view-copy">
                <h3>${escapeHTML(title)}</h3>
                <p>${escapeHTML(text)}</p>
            </section>
        `;
    }
    function openCreative(card) {
        const creative = getCreative(card);
        activeCreativeId = Number(creative.id);
        viewTitle.textContent = creative.title || "Untitled Creative";
        if (creative.image) {
            const image = document.createElement("img");
            image.src = creative.image;
            image.alt = creative.title || "Creative preview";
            image.onerror = () => {
                viewPreview.innerHTML =
                    '<div class="ac-view-placeholder">Preview image unavailable</div>';
            };
            viewPreview.replaceChildren(image);
        } else {
            viewPreview.innerHTML =
                '<div class="ac-view-placeholder">No creative image available</div>';
        }
        const status = creative.status || "draft";
        viewStatus.innerHTML = `
            <span class="ac-status ac-status-${escapeHTML(status)}"
                  style="position:static">
                ${escapeHTML(getStatusLabel(status))}
            </span>
        `;
        viewDetails.innerHTML = [
            detail("Creative ID", `#${creative.id}`),
            detail("Platform", creative.platform),
            detail("Company", creative.company),
            detail("Added by", creative.creator),
            detail("Creator email", creative.email),
            detail("Creative date", creative.date),
            detail("Submitted on", creative.created)
        ].join("");
        viewText.innerHTML =
            textSection("Caption", creative.caption) +
            textSection("Description", creative.description) +
            textSection("Keywords", creative.keywords) +
            textSection("Revision reason", creative.reason);
        if (creative.reference) {
            const link = document.createElement("a");
            link.href = creative.reference;
            link.target = "_blank";
            link.rel = "noopener noreferrer";
            link.className = "ac-view-reference";
            link.textContent = "Open reference link ↗";
            viewReference.replaceChildren(link);
        } else {
            viewReference.replaceChildren();
        }
        const modalApprove = viewModal.querySelector(
            '[data-modal-action="approve"]'
        );
        if (modalApprove) {
            modalApprove.hidden = status === "approved";
        }
        const modalReject = viewModal.querySelector(
            '[data-modal-action="reject"]'
        );
        if (modalReject) {
            modalReject.hidden = status === "rejected";
        }
        openModal(viewModal);
    }
    function openRevisionModal(creativeId) {
        activeCreativeId = Number(creativeId);
        const card = grid.querySelector(
            `.ac-card[data-id="${activeCreativeId}"]`
        );
        if (!card) return;
        const creative = getCreative(card);
        revisionReason.value = creative.reason || "";
        reasonCount.textContent =
            `${revisionReason.value.length} / 5000`;
        openModal(revisionModal);
        revisionReason.focus();
    }
    function updateCard(card, status, reason = "") {
        card.dataset.status = status;
        const badge = card.querySelector("[data-status-badge]");
        if (badge) {
            badge.className = `ac-status ac-status-${status}`;
            badge.textContent = getStatusLabel(status);
        }
        const creative = getCreative(card);
        creative.status = status;
        if (status === "approved" || status === "rejected") {
            creative.reason = "";
        } else if (status === "revision") {
            creative.reason = reason;
        }
        card.dataset.creative = JSON.stringify(creative);
        // Update searchable text so status filtering stays correct.
        card.dataset.search = [
            creative.title,
            creative.company,
            creative.creator,
            creative.platform,
            status
        ].join(" ").toLowerCase();
        const actions = card.querySelector(".ac-card-actions");
        if (actions) {
            const oldApprove = actions.querySelector(
                '[data-action="approve"]'
            );
            if (status === "approved") {
                if (oldApprove) {
                    const approvedLabel = document.createElement("span");
                    approvedLabel.className = "ac-approved-label";
                    approvedLabel.textContent = "✓ Approved";
                    oldApprove.replaceWith(approvedLabel);
                }
            } else if (!oldApprove) {
                const approvedLabel = actions.querySelector(
                    ".ac-approved-label"
                );
                if (approvedLabel) {
                    const approveButton = document.createElement("button");
                    approveButton.type = "button";
                    approveButton.className = "ac-btn ac-btn-approve";
                    approveButton.dataset.action = "approve";
                    approveButton.textContent = "✓ Approve";
                    approvedLabel.replaceWith(approveButton);
                }
            }
            let revisionNote = card.querySelector(".ac-revision-note");
            if (status === "revision" && reason) {
                if (!revisionNote) {
                    revisionNote = document.createElement("div");
                    revisionNote.className = "ac-revision-note";
                    const title = document.createElement("strong");
                    title.textContent = "Revision reason";
                    const paragraph = document.createElement("p");
                    revisionNote.append(title, paragraph);
                    actions.before(revisionNote);
                }
                revisionNote.querySelector("p").textContent = reason;
            } else if (
                revisionNote &&
                (status === "approved" || status === "rejected")
            ) {
                revisionNote.remove();
            }
        }
        // Refresh open detail content if this creative is being viewed.
        if (
            viewModal.classList.contains("is-open") &&
            Number(creative.id) === Number(activeCreativeId)
        ) {
            closeModalElements();
            openCreative(card);
        }
        updateStats();
        applyFilters();
    }
    async function submitAction(action, creativeId, reason = "") {
        if (requestInProgress) return;
        const card = grid.querySelector(
            `.ac-card[data-id="${Number(creativeId)}"]`
        );
        if (!card) {
            showNotice("Creative not found on the page.", "error");
            return;
        }
        if (action === "revision" && !reason.trim()) {
            revisionReason.focus();
            showNotice("Please enter a reason for revision.", "error");
            return;
        }
        if (action === "revision" && reason.trim().length > 5000) {
            showNotice("Revision reason is too long.", "error");
            return;
        }
        const confirmMessages = {
            approve: "Approve this creative?",
            reject: "Reject this creative? The SME will see it as rejected.",
            revision: "Send this creative back for revision?"
        };
        const confirmMessage = confirmMessages[action] || "Update this creative?";
        if (!window.confirm(confirmMessage)) return;
        requestInProgress = true;
        const buttons = Array.from(
            root.querySelectorAll("button")
        );
        buttons.forEach(button => {
            if (
                button.dataset.action === action ||
                button.dataset.modalAction === action ||
                button.id === "acSubmitRevision"
            ) {
                button.disabled = true;
            }
        });
        const body = new URLSearchParams();
        body.set("action", action);
        body.set("creative_id", String(creativeId));
        body.set("csrf_token", csrfToken);
        if (action === "revision") {
            body.set("revision_reason", reason.trim());
        }
        try {
            const response = await fetch(endpoint, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
                    "X-CSRF-Token": csrfToken,
                    "X-Requested-With": "XMLHttpRequest"
                },
                body: body.toString()
            });
            let data;
            try {
                data = await response.json();
            } catch {
                throw new Error(
                    "Server returned an invalid response. Check the PHP error log."
                );
            }
            if (!response.ok || !data.success) {
                throw new Error(
                    data.message || "Unable to update creative."
                );
            }
            updateCard(
                card,
                data.status,
                action === "revision" ? reason.trim() : ""
            );
            closeModalElements();
            showNotice(data.message || "Creative updated successfully.");
        } catch (error) {
            console.error("Creative update failed:", error);
            showNotice(error.message || "Something went wrong.", "error");
        } finally {
            requestInProgress = false;
            buttons.forEach(button => {
                button.disabled = false;
            });
        }
    }
    // Search and filters
    searchInput.addEventListener("input", applyFilters);
    statusFilter.addEventListener("change", applyFilters);
    platformFilter.addEventListener("change", applyFilters);
    root.querySelectorAll(".ac-stat").forEach(stat => {
        stat.addEventListener("click", () => {
            statusFilter.value = stat.dataset.filter;
            applyFilters();
        });
    });
    // Card and modal actions: delegated so dynamically updated cards work.
    root.addEventListener("click", event => {
        const closeButton = event.target.closest("[data-close-modal]");
        if (closeButton) {
            closeModals();
            return;
        }
        const cardAction = event.target.closest("[data-action]");
        if (cardAction && root.contains(cardAction)) {
            const card = cardAction.closest(".ac-card");
            if (!card) return;
            const id = Number(card.dataset.id);
            const action = cardAction.dataset.action;
            if (action === "view") {
                openCreative(card);
            } else if (action === "approve") {
                submitAction("approve", id);
            } else if (action === "revision") {
                openRevisionModal(id);
            } else if (action === "reject") {
                submitAction("reject", id);
            }
            return;
        }
        const modalAction = event.target.closest("[data-modal-action]");
        if (modalAction && viewModal.contains(modalAction)) {
            if (!activeCreativeId) return;
            if (modalAction.dataset.modalAction === "approve") {
                submitAction("approve", activeCreativeId);
            } else if (modalAction.dataset.modalAction === "revision") {
                openRevisionModal(activeCreativeId);
            } else if (modalAction.dataset.modalAction === "reject") {
                submitAction("reject", activeCreativeId);
            }
        }
    });
    revisionReason.addEventListener("input", () => {
        reasonCount.textContent =
            `${revisionReason.value.length} / 5000`;
    });
    submitRevision.addEventListener("click", () => {
        if (!activeCreativeId) return;
        const reason = revisionReason.value.trim();
        if (!reason) {
            revisionReason.focus();
            showNotice("Please enter a reason for revision.", "error");
            return;
        }
        submitAction("revision", activeCreativeId, reason);
    });
    document.addEventListener("keydown", event => {
        if (event.key === "Escape") {
            closeModals();
        }
    });
    // Initial state
    applyFilters();
});
