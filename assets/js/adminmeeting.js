document.addEventListener("DOMContentLoaded", () => {
    const tableBody = document.getElementById("meetingsTableBody");
    const messageElement = document.getElementById("meetingMessage");
    const csrfInput = document.getElementById("meetingCsrfToken");
    const totalElement = document.getElementById("totalMeetings");
    const doneElement = document.getElementById("doneMeetings");
    const notDoneElement = document.getElementById("notDoneMeetings");
    if (!tableBody || !csrfInput) {
        return;
    }
    let requestInProgress = false;
    function showMessage(message) {
        if (messageElement) {
            messageElement.textContent = message;
        }
    }
    function updateCounts() {
        const rows = tableBody.querySelectorAll(".am-card[data-meeting-id]");
        let doneCount = 0;
        let notDoneCount = 0;
        rows.forEach((row) => {
            if (row.dataset.status === "done") {
                doneCount++;
            } else {
                notDoneCount++;
            }
        });
        if (totalElement) {
            totalElement.textContent = rows.length;
        }
        if (doneElement) {
            doneElement.textContent = doneCount;
        }
        if (notDoneElement) {
            notDoneElement.textContent = notDoneCount;
        }
        applyFilter();
    }

    /* ---------- Filter tabs ---------- */
    let currentFilter = "all";
    function applyFilter() {
        let shown = 0;
        tableBody.querySelectorAll(".am-card[data-meeting-id]").forEach((card) => {
            const match =
                currentFilter === "all" ||
                (currentFilter === "pending" && card.dataset.mom === "pending") ||
                (currentFilter !== "pending" && card.dataset.status === currentFilter);
            card.hidden = !match;
            if (match) {
                shown++;
            }
        });
        const none = document.getElementById("amNoMatch");
        if (none) {
            none.hidden = shown > 0;
        }
    }
    document.querySelectorAll("[data-am-filter]").forEach((btn) => {
        btn.addEventListener("click", () => {
            currentFilter = btn.dataset.amFilter;
            document.querySelectorAll("[data-am-filter]").forEach((b) => {
                b.classList.toggle("is-active", b === btn);
            });
            applyFilter();
        });
    });
    function updateRowStatus(meetingId, status) {
        const row = document.getElementById(`meeting-row-${meetingId}`);
        if (!row) {
            return;
        }
        row.dataset.status = status;
        row.classList.remove("needs-approval");
        const statusElement = document.getElementById(
            `meeting-status-${meetingId}`
        );
        if (statusElement) {
            statusElement.textContent =
                status === "done" ? "Done" : "Not Done";
            statusElement.className =
                "meeting-status am-status am-status-" + status;
        }
        const buttons = row.querySelectorAll(".meeting-status-button");
        buttons.forEach((button) => {
            button.disabled = button.dataset.status === status;
        });
        updateCounts();
    }
    tableBody.addEventListener("click", async (event) => {
        const button = event.target.closest(".meeting-status-button");
        if (!button || requestInProgress || button.disabled) {
            return;
        }
        const meetingId = button.dataset.meetingId;
        const requestedStatus = button.dataset.status;
        if (
            !meetingId ||
            !["done", "not_done"].includes(requestedStatus)
        ) {
            showMessage("Invalid meeting details.");
            return;
        }
        const confirmationMessage =
            requestedStatus === "done"
                ? "Are you sure this meeting has been completed?"
                : "Are you sure this meeting should be marked Not Done?";
        if (!window.confirm(confirmationMessage)) {
            return;
        }
        const originalText = button.textContent;
        requestInProgress = true;
        showMessage("Saving meeting status...");
        const buttons = tableBody.querySelectorAll(
            ".meeting-status-button"
        );
        buttons.forEach((item) => {
            item.disabled = true;
        });
        button.textContent = "Saving...";
        try {
            const formData = new URLSearchParams();
            formData.append("meeting_id", meetingId);
            formData.append("meeting_status", requestedStatus);
            formData.append("csrf_token", csrfInput.value);
            const response = await fetch("adminmeeting.php", {
                method: "POST",
                headers: {
                    "Content-Type":
                        "application/x-www-form-urlencoded; charset=UTF-8",
                    "X-Requested-With": "XMLHttpRequest"
                },
                body: formData.toString(),
                credentials: "same-origin"
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || "Unable to update meeting status."
                );
            }
            updateRowStatus(meetingId, result.meeting_status);
            showMessage(result.message);
        } catch (error) {
            showMessage(
                error.message ||
                "Something went wrong while saving the meeting status."
            );
        } finally {
            requestInProgress = false;
            buttons.forEach((item) => {
                const row = item.closest(".am-card[data-meeting-id]");
                if (row) {
                    item.disabled =
                        item.dataset.status === row.dataset.status;
                }
            });
            button.textContent = originalText;
        }
    });

    /* ---------- MOM: view, approve, send back ---------- */
    tableBody.addEventListener("click", async (event) => {
        const toggle = event.target.closest(".mom-toggle");
        if (toggle) {
            const row = document.getElementById(`mom-row-${toggle.dataset.meetingId}`);
            if (row) {
                row.hidden = !row.hidden;
                toggle.textContent = row.hidden ? "View details" : "Hide details";
            }
            return;
        }
        const actionButton = event.target.closest("[data-mom-action]");
        if (!actionButton || requestInProgress) {
            return;
        }
        const review = actionButton.closest(".mom-review");
        if (!review) {
            return;
        }
        const meetingId = review.dataset.meetingId;
        const action = actionButton.dataset.momAction;
        const rejectBox = review.querySelector(".mom-reject-box");
        const noteInput = review.querySelector(".mom-note");

        // "Send back" first opens the reason box; nothing is sent yet.
        if (action === "reject-open") {
            if (rejectBox) {
                rejectBox.hidden = false;
            }
            if (noteInput) {
                noteInput.focus();
            }
            return;
        }
        if (action === "reject-cancel") {
            if (rejectBox) {
                rejectBox.hidden = true;
            }
            return;
        }

        let note = "";
        if (action === "reject") {
            note = noteInput ? noteInput.value.trim() : "";
            if (note === "") {
                showMessage("Type a short reason so the SME knows what to fix.");
                if (noteInput) {
                    noteInput.focus();
                }
                return;
            }
        }

        requestInProgress = true;
        review.querySelectorAll("button, input").forEach((el) => { el.disabled = true; });
        showMessage(action === "approve" ? "Approving..." : "Sending back...");
        try {
            const body = new URLSearchParams();
            body.append("meeting_id", meetingId);
            body.append("mom_action", action);
            body.append("note", note);
            body.append("csrf_token", csrfInput.value);
            const response = await fetch("adminmeeting.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                    "X-Requested-With": "XMLHttpRequest"
                },
                body: body.toString(),
                credentials: "same-origin"
            });
            const raw = await response.text();
            let result;
            try {
                result = JSON.parse(raw);
            } catch (parseError) {
                // Tolerate a stray PHP notice printed before the JSON.
                const start = raw.indexOf("{");
                const end = raw.lastIndexOf("}");
                result = start >= 0 && end > start ? JSON.parse(raw.slice(start, end + 1)) : null;
            }
            if (!result) {
                throw new Error("The server sent an unexpected reply. Refresh the page and try again.");
            }
            if (!result.success) {
                throw new Error(result.message || "Unable to review the MOM.");
            }
            if (result.mom_status === "approved") {
                showMessage(result.message);
                window.location.reload(); // shows "View details" for the approved meeting
                return;
            }
            const rejectedCard = document.getElementById(`meeting-row-${meetingId}`);
            if (rejectedCard) {
                rejectedCard.dataset.mom = "rejected";
            }
            updateRowStatus(meetingId, result.meeting_status);
            const state = document.getElementById(`mom-state-${meetingId}`);
            if (state) {
                state.textContent = "Sent back";
                state.className = "mom-state mom-rejected";
            }
            review.remove();
            const pending = document.getElementById("momPendingMeetings");
            if (pending) {
                pending.textContent = Math.max(0, parseInt(pending.textContent, 10) - 1);
            }
            showMessage(result.message);
        } catch (error) {
            showMessage(error.message || "Something went wrong.");
            review.querySelectorAll("button, input").forEach((el) => { el.disabled = false; });
        } finally {
            requestInProgress = false;
        }
    });

    /* ---------- Pick up new MOM requests without a manual refresh ---------- */
    const pendingNow = () => {
        const el = document.getElementById("momPendingMeetings");
        return el ? parseInt(el.textContent, 10) || 0 : 0;
    };
    let lastTotal = totalElement ? parseInt(totalElement.textContent, 10) || 0 : 0;
    setInterval(async () => {
        if (document.hidden || requestInProgress) {
            return;
        }
        const typing = document.querySelector(".mom-reject-box:not([hidden]) .mom-note");
        if (typing) {
            return; // do not reload while the admin is writing a reason
        }
        try {
            const response = await fetch("adminmeeting.php?mom_poll=1", {
                credentials: "same-origin",
                headers: { Accept: "application/json" }
            });
            const data = JSON.parse(await response.text());
            if (data && data.success && (data.pending !== pendingNow() || data.total !== lastTotal)) {
                window.location.reload();
            }
        } catch (error) {
            /* ignore: try again on the next tick */
        }
    }, 10000);
    updateCounts();
});
