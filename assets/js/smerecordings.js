document.addEventListener("DOMContentLoaded", () => {
    const post = async (endpoint, csrf, fields) => {
        const body = new FormData();
        body.append("csrf_token", csrf);
        Object.entries(fields).forEach(([key, value]) => body.append(key, value));
        const response = await fetch(endpoint, {
            method: "POST",
            body,
            credentials: "same-origin",
            headers: { Accept: "application/json" }
        });
        let result = {};
        try {
            result = await response.json();
        } catch (error) {
            result = { success: false, message: "Unexpected server response." };
        }
        return result;
    };

    /* ---------- Recordings page ---------- */
    const page = document.getElementById("smeRecordings");
    if (page) {
        // Tabs: Meeting Records | Recordings & Summaries
        const tabs = page.querySelectorAll("[data-rec-tab]");
        const panels = page.querySelectorAll("[data-rec-panel]");
        const searchBox = document.getElementById("recSearch");
        tabs.forEach((tab) => {
            tab.addEventListener("click", () => {
                tabs.forEach((t) => {
                    const on = t === tab;
                    t.classList.toggle("is-active", on);
                    t.setAttribute("aria-selected", on ? "true" : "false");
                });
                panels.forEach((panel) => {
                    panel.hidden = panel.dataset.recPanel !== tab.dataset.recTab;
                });
                if (searchBox) {
                    searchBox.hidden = tab.dataset.recTab !== "meetings";
                }
            });
        });
        // Search the saved meeting records
        if (searchBox) {
            searchBox.addEventListener("input", () => {
                const term = searchBox.value.trim().toLowerCase();
                let shown = 0;
                page.querySelectorAll(".meeting-record").forEach((card) => {
                    const hit = term === "" || (card.dataset.search || "").includes(term);
                    card.hidden = !hit;
                    if (hit) {
                        shown++;
                    }
                });
                const none = document.getElementById("recNoMatch");
                if (none) {
                    none.hidden = shown > 0;
                }
            });
        }

        const endpoint = page.dataset.endpoint;
        const csrf = page.dataset.csrf;
        const message = document.getElementById("recMessage");
        const syncBtn = document.getElementById("recSyncBtn");

        if (syncBtn) {
            syncBtn.addEventListener("click", async () => {
                syncBtn.disabled = true;
                syncBtn.textContent = "Syncing...";
                const result = await post(endpoint, csrf, { action: "sync" });
                message.textContent = result.message || "";
                message.className = "rec-message " + (result.success ? "is-success" : "is-error");
                if (result.success && result.count > 0) {
                    window.setTimeout(() => window.location.reload(), 700);
                    return;
                }
                syncBtn.disabled = false;
                syncBtn.textContent = "Sync now";
            });
        }

        // Transcripts load only when opened (they can be large).
        page.addEventListener("toggle", async (event) => {
            const details = event.target;
            if (!(details instanceof HTMLDetailsElement) || !details.open || details.dataset.loadTranscript !== "1") {
                return;
            }
            details.dataset.loadTranscript = "0";
            const body = details.querySelector(".rec-transcript-body");
            const card = details.closest(".rec-card");
            body.innerHTML = "";
            const note = document.createElement("p");
            note.className = "rec-muted";
            note.textContent = "Loading transcript...";
            body.appendChild(note);
            const result = await post(endpoint, csrf, { action: "transcript", id: card.dataset.recordingId });
            if (!result.success) {
                note.textContent = result.message || "Unable to load the transcript.";
                details.dataset.loadTranscript = "1";
                return;
            }
            body.innerHTML = "";
            if (!result.lines.length) {
                note.textContent = "No transcript is available for this recording.";
                body.appendChild(note);
                return;
            }
            result.lines.forEach((line) => {
                const row = document.createElement("p");
                row.className = "rec-line";
                const who = document.createElement("strong");
                who.textContent = (line.speaker || "Speaker") + (line.time ? " (" + line.time + ")" : "") + ": ";
                row.appendChild(who);
                row.appendChild(document.createTextNode(line.text));
                body.appendChild(row);
            });
        }, true);
    }
});
