document.addEventListener("DOMContentLoaded", () => {
    const dashboard = document.getElementById("smeDashboard");
    if (!dashboard) {
        return;
    }
    const logos = dashboard.querySelectorAll(".sme-company-logo");
    logos.forEach((logo) => {
        const showFallback = () => {
            const logoCircle = logo.closest(".sme-company-logo-circle");
            if (!logoCircle) {
                return;
            }
            const fallback = logoCircle.querySelector(
                ".sme-company-logo-fallback"
            );
            logo.hidden = true;
            if (fallback) {
                fallback.hidden = false;
            }
        };
        logo.addEventListener("error", showFallback);
        if (logo.complete && logo.naturalWidth === 0) {
            showFallback();
        }
    });
    const companyCards = dashboard.querySelectorAll(".sme-company-card");
    companyCards.forEach((card) => {
        const companyName = card.dataset.companyName;
        if (companyName) {
            card.setAttribute("aria-label", companyName);
        }
    });
    // ---- SOP tick marks: tick = save, daily/weekly ticks refresh on their own ----
    const sopSection = document.getElementById("smeSopsSection");
    if (sopSection) {
        const sopEndpoint = sopSection.dataset.endpoint;
        const sopCsrf = sopSection.dataset.csrf || "";
        const sopToast = document.getElementById("sopToast");
        let sopToastTimer = null;

        const showSopToast = (text, isError) => {
            if (!sopToast) {
                return;
            }
            sopToast.textContent = text;
            sopToast.classList.toggle("is-error", Boolean(isError));
            window.clearTimeout(sopToastTimer);
            sopToastTimer = window.setTimeout(() => {
                sopToast.textContent = "";
            }, 3500);
        };

        const refreshSopCount = (box) => {
            if (!box) {
                return;
            }
            const counter = box.querySelector(".sme-sop-done-count");
            if (counter) {
                counter.textContent = box.querySelectorAll(
                    ".sme-sop-checkbox:checked"
                ).length;
            }
        };

        const periodWord = {
            daily: "today",
            weekly: "this week",
            monthly: "this month"
        };

        sopSection.addEventListener("change", async (event) => {
            const checkbox = event.target.closest(".sme-sop-checkbox");
            if (!checkbox || !sopEndpoint) {
                return;
            }
            const item = checkbox.closest(".sme-sop-item");
            const box = checkbox.closest(".sme-sop-box");
            const note = item
                ? item.querySelector(".sme-sop-saved-note")
                : null;
            const wantChecked = checkbox.checked;
            const type = box ? box.dataset.sopType : "";

            const body = new URLSearchParams();
            body.set("sop_id", checkbox.dataset.sopId || "");
            body.set("checked", wantChecked ? "1" : "0");
            body.set("csrf_token", sopCsrf);

            if (item) {
                item.classList.add("is-busy");
            }
            try {
                const response = await fetch(sopEndpoint, {
                    method: "POST",
                    credentials: "same-origin",
                    headers: {
                        "Content-Type":
                            "application/x-www-form-urlencoded; charset=UTF-8"
                    },
                    body: body.toString()
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || "Unable to save.");
                }
                if (item) {
                    item.classList.toggle("is-done", wantChecked);
                }
                if (note) {
                    note.hidden = !wantChecked;
                    const timeEl = note.querySelector(".sme-sop-saved-time");
                    if (timeEl) {
                        timeEl.textContent = wantChecked && data.saved_time
                            ? " · " + data.saved_time
                            : "";
                    }
                    const word = periodWord[type] || "";
                    const textNode = Array.from(note.childNodes).find(
                        (n) => n.nodeType === Node.TEXT_NODE &&
                            n.textContent.trim() !== ""
                    );
                    if (textNode) {
                        textNode.textContent = " Saved " + word;
                    }
                }
                refreshSopCount(box);
                showSopToast(data.message || "Saved.", false);
            } catch (error) {
                // Put the tick back the way it was.
                checkbox.checked = !wantChecked;
                showSopToast(
                    error && error.message
                        ? error.message
                        : "Unable to save. Please try again.",
                    true
                );
            } finally {
                if (item) {
                    item.classList.remove("is-busy");
                }
            }
        });
    }
    const meetingsSection = document.getElementById(
        "smeMeetingsSection"
    );
    if (!meetingsSection) {
        return;
    }
    const openButton = document.getElementById("openMeetingForm");
    const cancelButton = document.getElementById("cancelMeetingForm");
    const form = document.getElementById("addMeetingForm");
    const saveButton = document.getElementById("saveMeetingBtn");
    const message = document.getElementById("meetingMessage");
    const meetingsList = document.getElementById("smeMeetingsList");
    const dateInput = document.getElementById("meetingDate");
    const endpoint = meetingsSection.dataset.endpoint;
    if (
        !openButton ||
        !cancelButton ||
        !form ||
        !saveButton ||
        !message ||
        !meetingsList ||
        !endpoint
    ) {
        return;
    }
    const saveLabel = saveButton.textContent.trim() || "Create Link";
    const today = new Date();
    const localToday = [
        today.getFullYear(),
        String(today.getMonth() + 1).padStart(2, "0"),
        String(today.getDate()).padStart(2, "0")
    ].join("-");
    const nextDateInput = form.querySelector('[name="next_meeting_date"]');
    dateInput.min = localToday;
    if (nextDateInput) {
        nextDateInput.min = localToday;
    }

    const showMessage = (text, type = "") => {
        message.textContent = text;
        message.className = "sme-meeting-message";
        if (type) {
            message.classList.add(`is-${type}`);
        }
    };
    const closeForm = () => {
        form.hidden = true;
        form.reset();
        resetRows();
        dateInput.min = localToday;
        if (nextDateInput) {
            nextDateInput.min = localToday;
        }
        openButton.disabled = false;
    };
    openButton.addEventListener("click", () => {
        form.hidden = false;
        openButton.disabled = true;
        showMessage("");
        form.querySelector('[name="subject"]').focus();
    });
    cancelButton.addEventListener("click", () => {
        closeForm();
        showMessage("");
    });

    // The next meeting can not start before this one.
    dateInput.addEventListener("change", () => {
        if (nextDateInput) {
            nextDateInput.min =
                dateInput.value && dateInput.value > localToday
                    ? dateInput.value
                    : localToday;
        }
    });

    /* ---------- Insert the server-rendered card in date order ---------- */
    const addMeetingCard = (cardHtml) => {
        const emptyState = meetingsList.querySelector(
            "#noMeetingsMessage"
        );
        if (emptyState) {
            emptyState.remove();
        }
        const holder = document.createElement("template");
        holder.innerHTML = cardHtml.trim();
        const card = holder.content.firstElementChild;
        if (!card) {
            return;
        }
        const newDateTime = card.dataset.meetingDatetime || "";
        const nextCard = Array.from(
            meetingsList.querySelectorAll(".sme-meeting-card")
        ).find((existing) => {
            return (existing.dataset.meetingDatetime || "") > newDateTime;
        });
        if (nextCard) {
            meetingsList.insertBefore(card, nextCard);
        } else {
            meetingsList.appendChild(card);
        }
    };


    /* ---------- Join only on time: unlock the button at the start time ---------- */
    // Use the server clock so a wrong PC clock can not unlock a meeting early.
    const serverNow = Number(meetingsSection.dataset.serverNow) * 1000;
    const clockOffset = serverNow ? serverNow - Date.now() : 0;
    const nowMs = () => Date.now() + clockOffset;

    const unlockCard = (card) => {
        const locked = card.querySelector(".sme-join-btn.is-locked");
        if (locked) {
            const url = locked.dataset.joinUrl || "";
            if (/^https:\/\//i.test(url)) {
                const link = document.createElement("a");
                link.className = "sme-join-btn";
                link.href = url;
                link.target = "_blank";
                link.rel = "noopener noreferrer";
                link.textContent = "Join now";
                locked.replaceWith(link);
            }
        }
        const momBtn = card.querySelector("[data-mom-request]");
        if (momBtn) {
            momBtn.hidden = false;
        }
        card.classList.remove("meeting-upcoming");
        card.classList.add("meeting-live");
        const pill = card.querySelector(".sme-meeting-pill");
        if (pill) {
            pill.textContent = "In progress";
        }
    };
    const checkMeetingStarts = () => {
        // Cards still waiting for their start time (with or without a meeting link).
        meetingsList
            .querySelectorAll(".sme-meeting-card[data-start-ts]")
            .forEach((card) => {
                const waiting =
                    card.querySelector(".sme-join-btn.is-locked") ||
                    card.querySelector("[data-mom-request][hidden]");
                const start = Number(card.dataset.startTs) * 1000;
                if (waiting && start && start <= nowMs()) {
                    unlockCard(card);
                }
            });
    };
    checkMeetingStarts();
    setInterval(checkMeetingStarts, 1000);

    /* ---------- Send MOM (attendees + points) -> admin approves -> saved to Records ---------- */
    const momEndpoint = meetingsSection.dataset.momEndpoint;
    const detailsEndpoint = meetingsSection.dataset.detailsEndpoint;
    const csrfToken = () => form.querySelector('[name="csrf_token"]').value;
    const swapCard = (card, cardHtml) => {
        const holder = document.createElement("template");
        holder.innerHTML = cardHtml.trim();
        card.replaceWith(holder.content.firstElementChild);
    };

    const removeCard = (card) => {
        card.classList.add("is-leaving");
        window.setTimeout(() => {
            card.remove();
            if (!meetingsList.querySelector(".sme-meeting-card")) {
                const empty = document.createElement("div");
                empty.className = "sme-meeting-empty";
                empty.id = "noMeetingsMessage";
                empty.innerHTML =
                    "<h3>No Upcoming Meetings</h3>" +
                    "<p>Your scheduled meetings will appear here. Finished meetings are in " +
                    '<a class="sme-records-link" href="sme.php?page=recordings">Records</a>.</p>';
                meetingsList.appendChild(empty);
            }
        }, 280);
    };

    meetingsList.addEventListener("click", async (event) => {
        const card = event.target.closest(".sme-meeting-card");
        if (!card) {
            return;
        }

        const momForm = card.querySelector("[data-mom-form]");
        if (!momForm) {
            return;
        }
        const openBtn = event.target.closest("[data-mom-open]");
        if (openBtn) {
            momForm.hidden = false;
            openBtn.hidden = true;
            const firstField = momForm.querySelector("input");
            if (firstField) {
                firstField.focus();
            }
            return;
        }
        if (event.target.closest("[data-mom-cancel]")) {
            momForm.hidden = true;
            const reopen = card.querySelector("[data-mom-open]");
            if (reopen) {
                reopen.hidden = false;
            }
            return;
        }

        // + button: add another attendee / point row (copy of the last row, emptied)
        const addBtn = event.target.closest("[data-mom-add]");
        if (addBtn) {
            const type = addBtn.dataset.momAdd;
            const box = momForm.querySelector(`[data-mom-rows="${type}"]`);
            const max = type === "attendee" ? 20 : 30;
            if (box && box.children.length < max) {
                const row = box.lastElementChild.cloneNode(true);
                row.querySelectorAll("input").forEach((input) => {
                    input.value = "";
                });
                box.appendChild(row);
                row.querySelector("input").focus();
            }
            addBtn.disabled = !!box && box.children.length >= max;
            return;
        }

        const removeBtn = event.target.closest(".sme-mt-remove");
        if (removeBtn) {
            const row = removeBtn.closest(".sme-mt-row");
            const box = row && row.parentElement;
            if (!box) {
                return;
            }
            if (box.children.length === 1) {
                row.querySelectorAll("input").forEach((input) => {
                    input.value = "";
                });
            } else {
                row.remove();
            }
            const add = momForm.querySelector(`[data-mom-add="${box.dataset.momRows}"]`);
            if (add) {
                add.disabled = false;
            }
        }
    });

    /* ---------- Watch MOM requests: refresh the card when the admin answers ---------- */
    const pollEndpoint = meetingsSection.dataset.pollEndpoint;
    const pollPendingCards = async () => {
        if (!pollEndpoint || document.hidden) {
            return;
        }
        const pendingCards = Array.from(
            meetingsList.querySelectorAll(".sme-meeting-card")
        ).filter((card) => card.querySelector(".sme-mom-box.is-pending"));
        if (!pendingCards.length) {
            return;
        }
        const ids = pendingCards.map((card) => card.dataset.meetingId).join(",");
        try {
            const response = await fetch(
                `${pollEndpoint}?ids=${encodeURIComponent(ids)}`,
                { credentials: "same-origin", headers: { Accept: "application/json" } }
            );
            const data = await response.json();
            (data.cards || []).forEach((item) => {
                const card = meetingsList.querySelector(
                    `.sme-meeting-card[data-meeting-id="${item.id}"]`
                );
                const stillPending = item.card_html.includes("is-pending");
                if (!card || stillPending) {
                    return;
                }
                if (item.card_html.includes("is-approved")) {
                    // Approved: the details were sent with the MOM, so the meeting now lives in Records.
                    removeCard(card);
                    showMessage("The admin approved the MOM. The meeting is saved in Records.", "success");
                } else {
                    swapCard(card, item.card_html);
                    showMessage("The admin sent the MOM back. See the reason on the card, update it and send it again.", "success");
                }
            });
        } catch (error) {
            /* ignore: try again on the next tick */
        }
    };
    setInterval(pollPendingCards, 8000);

    meetingsList.addEventListener("submit", async (event) => {
        const momForm = event.target.closest("[data-mom-form]");
        if (!momForm) {
            return;
        }
        event.preventDefault();
        // "send": attendees + points go to the admin together with the MOM.
        // "save": legacy meetings the admin already marked done without details.
        const sending = momForm.dataset.momMode === "send";
        const endpointUrl = sending ? momEndpoint : detailsEndpoint;
        if (!endpointUrl) {
            return;
        }
        const card = momForm.closest(".sme-meeting-card");
        const submit = momForm.querySelector(".sme-mom-send");
        const label = momForm.dataset.submitLabel || submit.textContent;
        const hasPoint = Array.from(momForm.querySelectorAll('[name="point[]"]')).some(
            (input) => input.value.trim() !== ""
        );
        if (!hasPoint) {
            showMessage("Add at least one point discussed.", "error");
            return;
        }
        if (sending && !window.confirm("Send the MOM with these attendees and points to the admin? The meeting is over once the admin approves it.")) {
            return;
        }
        const formData = new FormData(momForm);
        formData.append("csrf_token", csrfToken());
        formData.append("meeting_id", card.dataset.meetingId);
        submit.disabled = true;
        submit.textContent = sending ? "Sending..." : "Saving...";
        try {
            const response = await fetch(endpointUrl, {
                method: "POST",
                body: formData,
                credentials: "same-origin",
                headers: { Accept: "application/json" }
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || "Unable to save the details.");
            }
            if (sending) {
                swapCard(card, result.card_html);
            } else {
                removeCard(card);
            }
            showMessage(result.message || "Saved.", "success");
        } catch (error) {
            showMessage(error.message || "Something went wrong.", "error");
            submit.disabled = false;
            submit.textContent = label;
        }
    });

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) {
            return;
        }
        if (dateInput.value < localToday) {
            showMessage("Select today or a future date.", "error");
            return;
        }
        const formData = new FormData(form);
        saveButton.disabled = true;
        saveButton.textContent = "Creating...";
        showMessage("");
        try {
            const response = await fetch(endpoint, {
                method: "POST",
                body: formData,
                credentials: "same-origin",
                headers: {
                    Accept: "application/json"
                }
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || "Unable to add meeting."
                );
            }
            addMeetingCard(result.card_html || "");
            closeForm();
            showMessage(
                result.message || "Meeting added successfully.",
                "success"
            );
        } catch (error) {
            showMessage(
                error.message || "Something went wrong. Please try again.",
                "error"
            );
        } finally {
            saveButton.disabled = false;
            saveButton.textContent = saveLabel;
        }
    });
});
