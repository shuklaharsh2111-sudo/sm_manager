/*
 * SME "Your SOPs": Daily / Weekly / Monthly toggle, tick-to-save,
 * and auto-refresh so SOPs the admin assigns appear without a reload.
 * Uses event delegation on the section, so it keeps working after the
 * list is re-rendered.
 */
(function () {
    "use strict";

    var PERIOD_WORD = { daily: "today", weekly: "this week", monthly: "this month" };
    var TAB_KEY = "smeSopActiveTab";

    function init() {
        var section = document.getElementById("smeSopsSection");
        if (!section || section.dataset.sopReady === "1") {
            return;
        }
        section.dataset.sopReady = "1";

        var endpoint = section.dataset.endpoint || "";
        var csrf = section.dataset.csrf || "";
        var host = document.getElementById("smeSopsTabs");
        var toast = document.getElementById("sopToast");
        var toastTimer = null;
        var refreshing = false;

        function say(text, isError) {
            if (!toast) { return; }
            toast.textContent = text;
            toast.classList.toggle("is-error", !!isError);
            window.clearTimeout(toastTimer);
            toastTimer = window.setTimeout(function () { toast.textContent = ""; }, 3500);
        }

        function storedTab() {
            try { return window.sessionStorage.getItem(TAB_KEY) || ""; } catch (e) { return ""; }
        }
        function storeTab(type) {
            try { window.sessionStorage.setItem(TAB_KEY, type); } catch (e) { /* ignore */ }
        }

        function activeTab() {
            var btn = section.querySelector(".sme-sop-toggle-btn.is-active");
            return btn ? btn.dataset.sopTab : "daily";
        }

        function showTab(type) {
            var buttons = section.querySelectorAll(".sme-sop-toggle-btn");
            var panels = section.querySelectorAll(".sme-sop-panel");
            if (!buttons.length) { return; }
            var found = false;
            buttons.forEach(function (b) { if (b.dataset.sopTab === type) { found = true; } });
            if (!found) { type = "daily"; }
            buttons.forEach(function (b) {
                var on = b.dataset.sopTab === type;
                b.classList.toggle("is-active", on);
                b.setAttribute("aria-selected", on ? "true" : "false");
            });
            panels.forEach(function (p) {
                p.hidden = p.dataset.sopType !== type;
            });
            storeTab(type);
        }

        function updateCount(panel) {
            if (!panel) { return; }
            var counter = section.querySelector(
                '[data-sop-count="' + panel.dataset.sopType + '"] .sme-sop-done-count'
            );
            if (counter) {
                counter.textContent = panel.querySelectorAll(".sme-sop-checkbox:checked").length;
            }
        }

        // ---- toggle ----
        section.addEventListener("click", function (event) {
            var btn = event.target.closest(".sme-sop-toggle-btn");
            if (btn && section.contains(btn)) {
                event.preventDefault();
                showTab(btn.dataset.sopTab);
            }
        });

        // ---- tick = save ----
        section.addEventListener("change", function (event) {
            var checkbox = event.target.closest(".sme-sop-checkbox");
            if (!checkbox || !endpoint) { return; }
            var item = checkbox.closest(".sme-sop-item");
            var panel = checkbox.closest(".sme-sop-panel");
            var note = item ? item.querySelector(".sme-sop-saved-note") : null;
            var want = checkbox.checked;
            var type = panel ? panel.dataset.sopType : "";

            var body = new URLSearchParams();
            body.set("sop_id", checkbox.dataset.sopId || "");
            body.set("checked", want ? "1" : "0");
            body.set("csrf_token", csrf);

            if (item) { item.classList.add("is-busy"); }
            fetch(endpoint, {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
                body: body.toString()
            })
                .then(function (r) {
                    return r.json().then(function (data) { return { ok: r.ok, data: data }; });
                })
                .then(function (res) {
                    if (!res.ok || !res.data.success) {
                        throw new Error(res.data.message || "Unable to save.");
                    }
                    if (item) { item.classList.toggle("is-done", want); }
                    if (note) {
                        note.hidden = !want;
                        var time = note.querySelector(".sme-sop-saved-time");
                        if (time) {
                            time.textContent = want && res.data.saved_time ? " \u00b7 " + res.data.saved_time : "";
                        }
                    }
                    updateCount(panel);
                    say(res.data.message || "Saved.", false);
                })
                .catch(function (err) {
                    checkbox.checked = !want;
                    say(err && err.message ? err.message : "Unable to save. Please try again.", true);
                })
                .then(function () {
                    if (item) { item.classList.remove("is-busy"); }
                });
        });

        // ---- auto-refresh: pick up SOPs the admin assigned ----
        function signature(html) {
            var m = html.match(/data-sop-id="\d+"|sme-sop-title">[^<]*<|Date:<\/span>[^<]*</g);
            return (m || []).join("|");
        }

        function reload(force) {
            if (refreshing || !host || !endpoint || (document.hidden && !force)) { return; }
            if (section.querySelector(".sme-sop-item.is-busy")) { return; }
            refreshing = true;
            fetch(endpoint + "?action=list&active=" + encodeURIComponent(activeTab()) + "&_=" + Date.now(), {
                credentials: "same-origin",
                cache: "no-store"
            })
                .then(function (r) {
                    return r.json().then(function (data) { return { ok: r.ok, data: data }; });
                })
                .then(function (res) {
                    if (!res.ok || !res.data.success || typeof res.data.html !== "string") { return; }
                    var fresh = signature(res.data.html);
                    var first = host.dataset.snapshot === undefined;
                    if (host.dataset.snapshot !== fresh) {
                        host.dataset.snapshot = fresh;
                        if (!first) {
                            var keep = activeTab();
                            host.innerHTML = res.data.html;
                            showTab(keep);
                            say("SOP list updated.", false);
                        }
                    }
                })
                .catch(function () { /* offline / session ended: keep current list */ })
                .then(function () { refreshing = false; });
        }

        // restore the tab the user was on (default Daily)
        showTab(storedTab() || "daily");
        reload(true);
        window.setInterval(reload, 30000);
        document.addEventListener("visibilitychange", function () {
            if (!document.hidden) { reload(true); }
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
