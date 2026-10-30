
/* =========================================================
   SME MONTHLY REPORTS
   File: sme/monthly-reports.js
   ========================================================= */

(() => {
    "use strict";

    const root = document.getElementById("smeMonthlyReports");

    // This script can safely exist on pages without the report.
    if (!root || root.dataset.initialized === "true") {
        return;
    }

    root.dataset.initialized = "true";

    const $ = (id) => document.getElementById(id);

    const monthInput = $("mrMonth");
    const refreshButton = $("mrRefresh");
    const printButton = $("mrPrint");
    const message = $("mrMessage");
    const reportContent = $("mrReportContent");

    if (!monthInput || !refreshButton || !printButton) {
        return;
    }

    /*
     * Escape database values before placing them in HTML.
     */
    const escapeHTML = (value) => {
        return String(value ?? "").replace(/[&<>"']/g, (char) => ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#39;"
        })[char]);
    };

    const number = (value) => {
        const parsed = Number(value ?? 0);
        return Number.isFinite(parsed)
            ? parsed.toLocaleString("en-IN")
            : "0";
    };

    const statusClass = (status) => {
        const allowed = [
            "approved",
            "pending",
            "revision",
            "rejected",
            "draft"
        ];

        return allowed.includes(String(status).toLowerCase())
            ? `mr-pill-${String(status).toLowerCase()}`
            : "mr-pill-default";
    };

    const statusPill = (status) => {
        const label = String(status || "unknown").replaceAll("_", " ");

        return `
            <span class="mr-pill ${statusClass(status)}">
                ${escapeHTML(label)}
            </span>
        `;
    };

    const emptyRow = (columns, text) => `
        <tr>
            <td colspan="${columns}" class="mr-empty">
                ${escapeHTML(text)}
            </td>
        </tr>
    `;

    const setText = (id, value) => {
        const element = $(id);

        if (element) {
            element.textContent = String(value ?? "");
        }
    };

    const setMessage = (text, isError = false) => {
        message.textContent = text;
        message.classList.toggle("mr-error", isError);
        message.hidden = false;
    };

    /*
     * Build the endpoint from the current SME folder.
     *
     * Keep monthly_reports.php in the same folder as sme.php.
     */

    const getEndpoint = (month) => {
        const phpFile = root.dataset.reportUrl;

        if (!phpFile) {
            throw new Error(
                "Report PHP filename missing. Check monthly_reports.php."
            );
        }

        const url = new URL(phpFile, window.location.href);

        url.searchParams.set("report_api", "1");
        url.searchParams.set("month", month);

        return url.toString();
    };


    const pct = (part, whole) => {
        const w = Number(whole || 0);
        return w > 0 ? (Number(part || 0) / w) * 100 : 0;
    };

    const formatDate = (value) => {
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ""));

        if (!m) {
            return String(value || "-");
        }

        return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
            .toLocaleDateString("en-GB", {
                day: "2-digit",
                month: "short",
                year: "numeric"
            });
    };

    const formatTime = (value) => {
        const m = /^(\d{2}):(\d{2})/.exec(String(value || ""));

        if (!m) {
            return "-";
        }

        const h = Number(m[1]);
        return `${((h + 11) % 12) + 1}:${m[2]} ${h >= 12 ? "PM" : "AM"}`;
    };

    const healthBadge = (key, label) => {
        const safe = ["healthy", "risk", "critical", "nodata"].includes(key)
            ? key
            : "nodata";

        return `<span class="mr-health mr-health-${safe}">${escapeHTML(label || "No data")}</span>`;
    };

    const STATUS_ORDER = ["approved", "pending", "revision", "rejected", "draft"];
    const STATUS_LABEL = {
        approved: "Approved",
        pending: "Pending",
        revision: "Revision",
        rejected: "Rejected",
        draft: "Draft"
    };

    /*
     * Overall summary cards and report header.
     */
    const renderSummary = (report) => {
        const stats = report.stats || {};
        const previous = report.previous_stats || {};

        setText("mrPeriodLabel", report.month || "Monthly Report");
        setText("mrPeriodRange", report.period_text || "-");
        setText("mrSmeName", report.sme_name || "-");
        setText("mrSmeEmail", report.sme_email || "-");
        setText("mrSmePosition", report.sme_position || "-");
        setText("mrSmeDepartment", report.sme_department || "-");
        setText("mrManagerName", report.reporting_manager || "-");
        setText("mrSignSme", [report.sme_name, report.sme_position].filter(Boolean).join(" - "));
        setText("mrSignManager", report.reporting_manager || "");
        setText("mrPrevLabel", report.previous_month || "-");
        setText("mrGeneratedAt", report.generated_at || "-");

        setText(
            "mrCompanyCount",
            `${number((report.companies || []).length)} compan${(report.companies || []).length === 1 ? "y" : "ies"}`
        );

        const overall = $("mrOverallHealth");

        if (overall) {
            overall.outerHTML = healthBadge(
                report.overall_health,
                `Overall: ${report.overall_health_label || "No data"}`
            ).replace("<span ", '<span id="mrOverallHealth" ');
        }

        setText("mrTotal", number(stats.total));
        setText("mrApproved", number(stats.approved));
        setText("mrPending", number(stats.pending));
        setText("mrRevision", number(stats.revision));
        setText("mrRejected", number(stats.rejected));
        setText("mrDelays", number(stats.delays));
        setText("mrOverdue", number(stats.overdue));
        setText("mrDraft", number(stats.draft));

        setText(
            "mrApprovalRate",
            `${Number(report.approval_rate || 0).toFixed(1)}% of this month's creatives`
        );

        const change = report.creative_change;

        if (change === null || change === undefined) {
            setText(
                "mrTotalChange",
                `Previous month: ${number(previous.total)} creatives`
            );
        } else {
            const sign = Number(change) > 0 ? "+" : "";

            setText(
                "mrTotalChange",
                `${sign}${Number(change).toFixed(1)}% vs previous month (${number(previous.total)})`
            );
        }
    };

    /*
     * Written summary and key observations, generated from the figures.
     */
    const renderNarrative = (report) => {
        const stats = report.stats || {};
        const prev = report.previous_stats || {};
        const companies = report.companies || [];
        const total = Number(stats.total || 0);
        const draft = Number(stats.draft || 0);
        const month = report.month || "this month";

        const plural = (n, one, many) => `${number(n)} ${Number(n) === 1 ? one : many}`;

        if (total === 0) {
            setText(
                "mrNarrative",
                `No creatives were scheduled in ${month} for the ${plural(companies.length, "company", "companies")} assigned to you.`
            );
        } else {
            const parts = [
                `In ${month}, ${plural(companies.length, "assigned company", "assigned companies")} had ${plural(total, "creative", "creatives")} scheduled (${number(total - draft)} finalised, ${number(draft)} in draft).`,
                `${number(stats.approved)} ${Number(stats.approved) === 1 ? "was" : "were"} approved (${Number(report.approval_rate || 0).toFixed(1)}% of all creatives), ${number(stats.pending)} ${Number(stats.pending) === 1 ? "is" : "are"} pending review, ${number(stats.revision)} ${Number(stats.revision) === 1 ? "needs" : "need"} revision and ${number(stats.rejected)} ${Number(stats.rejected) === 1 ? "was" : "were"} rejected.`
            ];

            if (Number(stats.delays) > 0 || Number(stats.overdue) > 0) {
                parts.push(
                    `${plural(stats.delays, "creative was", "creatives were")} submitted late and ${plural(stats.overdue, "creative is", "creatives are")} overdue.`
                );
            } else {
                parts.push("No creatives were submitted late and none are overdue.");
            }

            if (report.creative_change !== null && report.creative_change !== undefined) {
                const c = Number(report.creative_change);
                parts.push(
                    `Volume is ${c === 0 ? "unchanged" : `${Math.abs(c).toFixed(1)}% ${c > 0 ? "higher" : "lower"}`} than ${report.previous_month || "the previous month"} (${number(prev.total)} creatives).`
                );
            }

            parts.push(`Overall health for the month is ${report.overall_health_label || "not available"}.`);
            setText("mrNarrative", parts.join(" "));
        }

        const items = [];
        const rows = report.company_reports || [];
        const attention = rows.filter((r) => r.health === "critical" || r.health === "risk");

        if (attention.length) {
            items.push(
                `<strong>Needs attention:</strong> ${attention.map((r) => `${escapeHTML(r.comp_name)} (${escapeHTML(r.health_label)})`).join(", ")}.`
            );
        } else if (rows.some((r) => Number(r.total) - Number(r.draft) > 0)) {
            items.push("<strong>Company health:</strong> every company is within the healthy limits.");
        }

        const platforms = (report.platform_reports || []).filter((r) => Number(r.total) > 0);

        if (platforms.length) {
            const best = platforms.slice().sort((a, b) => pct(b.approved, b.total) - pct(a.approved, a.total))[0];
            const busiest = platforms[0];

            items.push(
                `<strong>Platforms:</strong> <span class="mr-cap">${escapeHTML(busiest.platform)}</span> carries the most creatives (${number(busiest.total)}); <span class="mr-cap">${escapeHTML(best.platform)}</span> has the highest approval rate (${pct(best.approved, best.total).toFixed(0)}%).`
            );
        }

        if (Number(stats.overdue) > 0) {
            items.push(
                `<strong>Follow up:</strong> ${plural(stats.overdue, "creative is", "creatives are")} still pending or in revision past ${Number(stats.overdue) === 1 ? "its" : "their"} date.`
            );
        }

        const mt = report.meeting_stats || {};

        if (Number(mt.total) > 0) {
            items.push(
                `<strong>Meetings:</strong> ${number(mt.done)} of ${number(mt.total)} completed (${pct(mt.done, mt.total).toFixed(0)}%).`
            );
        }

        $("mrObservations").innerHTML = items.length
            ? items.map((t) => `<li>${t}</li>`).join("")
            : "<li>No notable observations for this period.</li>";
    };

    /*
     * Month-over-month comparison table.
     */
    const renderComparison = (report) => {
        const now = report.stats || {};
        const prev = report.previous_stats || {};

        setText("mrCmpThis", report.month || "This month");
        setText("mrCmpPrev", report.previous_month || "Previous month");

        const metrics = [
            ["Total creatives", "total", null],
            ["Approved", "approved", "good"],
            ["Pending", "pending", null],
            ["Revision", "revision", "bad"],
            ["Rejected", "rejected", "bad"],
            ["Delays", "delays", "bad"],
            ["Overdue", "overdue", "bad"],
            ["Drafts", "draft", null]
        ];

        $("mrCompareRows").innerHTML = metrics.map(([label, key, tone]) => {
            const a = Number(now[key] || 0);
            const b = Number(prev[key] || 0);
            const diff = a - b;
            const sign = diff > 0 ? "+" : "";
            let cls = "mr-delta-flat";

            if (diff !== 0 && tone) {
                const improved = tone === "good" ? diff > 0 : diff < 0;
                cls = improved ? "mr-delta-good" : "mr-delta-bad";
            }

            const percent = b > 0 ? ` (${sign}${((diff / b) * 100).toFixed(0)}%)` : "";

            return `
                <tr>
                    <td class="mr-company-name">${escapeHTML(label)}</td>
                    <td class="mr-num">${number(a)}</td>
                    <td class="mr-num">${number(b)}</td>
                    <td class="mr-num ${cls}">${sign}${number(diff)}${percent}</td>
                </tr>
            `;
        }).join("");
    };

    /*
     * Company-wise table.
     */
    const renderCompanies = (rows) => {
        const body = $("mrCompanyRows");
        const foot = $("mrCompanyFoot");

        if (!rows || rows.length === 0) {
            body.innerHTML = emptyRow(
                11,
                "No assigned companies found. Ask your admin to assign a company."
            );
            foot.innerHTML = "";
            return;
        }

        const keys = ["total", "approved", "pending", "revision", "rejected", "delays", "overdue", "draft"];
        const sums = Object.fromEntries(keys.map((k) => [k, 0]));

        body.innerHTML = rows.map((row) => {
            keys.forEach((k) => { sums[k] += Number(row[k] || 0); });

            const rate = pct(row.approved, row.total);
            const safeRate = Math.max(0, Math.min(100, rate));

            return `
                <tr>
                    <td class="mr-company-name">${escapeHTML(row.comp_name)}</td>
                    <td class="mr-num">${number(row.total)}</td>
                    <td class="mr-num mr-c-approved">${number(row.approved)}</td>
                    <td class="mr-num mr-c-pending">${number(row.pending)}</td>
                    <td class="mr-num mr-c-revision">${number(row.revision)}</td>
                    <td class="mr-num mr-c-rejected">${number(row.rejected)}</td>
                    <td class="mr-num mr-c-delays">${number(row.delays)}</td>
                    <td class="mr-num mr-c-overdue">${number(row.overdue)}</td>
                    <td class="mr-num">${number(row.draft)}</td>
                    <td>
                        <div class="mr-rate">
                            <span>${rate.toFixed(1)}%</span>
                            <div class="mr-rate-track">
                                <div class="mr-rate-fill" style="width:${safeRate}%"></div>
                            </div>
                        </div>
                    </td>
                    <td class="mr-num">${healthBadge(row.health, row.health_label)}</td>
                </tr>
            `;
        }).join("");

        foot.innerHTML = `
            <tr>
                <td class="mr-company-name">Total</td>
                <td class="mr-num">${number(sums.total)}</td>
                <td class="mr-num">${number(sums.approved)}</td>
                <td class="mr-num">${number(sums.pending)}</td>
                <td class="mr-num">${number(sums.revision)}</td>
                <td class="mr-num">${number(sums.rejected)}</td>
                <td class="mr-num">${number(sums.delays)}</td>
                <td class="mr-num">${number(sums.overdue)}</td>
                <td class="mr-num">${number(sums.draft)}</td>
                <td>${pct(sums.approved, sums.total).toFixed(1)}%</td>
                <td></td>
            </tr>
        `;
    };

    /*
     * Platform distribution: one stacked bar per platform, one colour per status.
     */
    const renderPlatforms = (rows) => {
        const container = $("mrPlatformRows");
        const legendBox = $("mrPlatformLegend");

        legendBox.innerHTML = STATUS_ORDER.map((k) => `
            <li><span class="mr-sw mr-seg-${k}"></span>${STATUS_LABEL[k]}</li>
        `).join("");

        if (!rows || rows.length === 0) {
            container.innerHTML = `
                <div class="mr-empty">No creatives recorded for this month.</div>
            `;
            legendBox.hidden = true;
            return;
        }

        legendBox.hidden = false;

        const maximum = Math.max(1, ...rows.map((row) => Number(row.total || 0)));

        container.innerHTML = rows.map((row) => {
            const total = Number(row.total || 0);
            const approvedPct = pct(row.approved, total).toFixed(0);

            const segments = STATUS_ORDER.map((k) => {
                const n = Number(row[k] || 0);
                return n > 0
                    ? `<span class="mr-seg mr-seg-${k}" style="width:${(n / maximum) * 100}%" title="${STATUS_LABEL[k]}: ${n}"></span>`
                    : "";
            }).join("");

            const chips = STATUS_ORDER.map((k) => `
                <span class="mr-chip mr-chip-${k}">${STATUS_LABEL[k]} ${number(row[k])}</span>
            `).join("");

            return `
                <div class="mr-platform-item">
                    <div class="mr-platform-top">
                        <strong>${escapeHTML(row.platform)}</strong>
                        <span>${number(total)} creatives &middot; ${approvedPct}% approved</span>
                    </div>

                    <div class="mr-platform-track">${segments}</div>

                    <div class="mr-platform-sub">
                        ${chips}
                        <span class="mr-chip mr-chip-delays">Delays ${number(row.delays)}</span>
                        <span class="mr-chip mr-chip-overdue">Overdue ${number(row.overdue)}</span>
                    </div>
                </div>
            `;
        }).join("");
    };

    /*
     * SOP schedule totals.
     */
    const renderSops = (stats, done) => {
        setText("mrSopTotal", number(stats.total));
        setText("mrSopDaily", number(stats.daily));
        setText("mrSopWeekly", number(stats.weekly));
        setText("mrSopMonthly", number(stats.monthly));
        setText("mrSopDone", done === null || done === undefined ? "n/a" : number(done));
    };

    /*
     * Meeting totals, company breakdown and individual meetings.
     */
    const renderMeetings = (stats, rows, list) => {
        setText("mrMeetingTotal", number(stats.total));
        setText("mrMeetingDone", number(stats.done));
        setText("mrMeetingPending", number(stats.not_done));
        setText("mrMeetingRate", `${pct(stats.done, stats.total).toFixed(0)}%`);

        const body = $("mrMeetingRows");

        if (!rows || rows.length === 0) {
            body.innerHTML = emptyRow(
                4,
                "No assigned companies available for meeting reports."
            );
        } else {
            body.innerHTML = rows.map((row) => `
                <tr>
                    <td class="mr-company-name">${escapeHTML(row.comp_name)}</td>
                    <td class="mr-num">${number(row.total)}</td>
                    <td class="mr-num mr-c-approved">${number(row.done)}</td>
                    <td class="mr-num mr-c-pending">${number(row.not_done)}</td>
                </tr>
            `).join("");
        }

        const listBody = $("mrMeetingList");

        if (!list || list.length === 0) {
            listBody.innerHTML = emptyRow(6, "No meetings scheduled in this month.");
            return;
        }

        const momLabel = {
            none: "Not sent",
            pending: "Awaiting review",
            approved: "Approved",
            rejected: "Rejected",
            sent: "Sent"
        };

        listBody.innerHTML = list.map((row) => {
            const done = row.meeting_status === "done";
            const mom = String(row.mom_status || "none").toLowerCase();

            return `
                <tr>
                    <td>${escapeHTML(formatDate(row.meeting_date))}</td>
                    <td>${escapeHTML(formatTime(row.meeting_time))}</td>
                    <td>${escapeHTML(row.comp_name)}</td>
                    <td class="mr-creative-title">${escapeHTML(row.subject)}</td>
                    <td><span class="mr-pill ${done ? "mr-pill-approved" : "mr-pill-pending"}">${done ? "Completed" : "Pending"}</span></td>
                    <td>${escapeHTML(momLabel[mom] || mom)}</td>
                </tr>
            `;
        }).join("");
    };

    /*
     * Every creative of the month.
     */
    const renderRecentCreatives = (rows) => {
        const body = $("mrRecentRows");
        const sub = $("mrCreativeSub");

        if (!rows || rows.length === 0) {
            body.innerHTML = emptyRow(7, "No creatives found for this month.");
            sub.textContent = "Every creative of the selected month.";
            return;
        }

        sub.textContent = rows.length >= 300
            ? "First 300 creatives of the selected month, in date order."
            : `${number(rows.length)} creative${rows.length === 1 ? "" : "s"} of the selected month, in date order.`;

        body.innerHTML = rows.map((row) => {
            const overdue = Number(row.is_overdue) === 1;
            const delayed = Number(row.is_delayed) === 1;
            const timing = [];

            if (overdue) {
                timing.push('<span class="mr-flag mr-flag-overdue">Overdue</span>');
            }

            if (delayed) {
                timing.push('<span class="mr-flag mr-flag-delays">Delayed</span>');
            }

            if (!timing.length) {
                timing.push(
                    row.status === "draft"
                        ? '<span class="mr-flag mr-flag-none">-</span>'
                        : '<span class="mr-flag mr-flag-ok">On time</span>'
                );
            }

            return `
                <tr>
                    <td class="mr-nowrap">${escapeHTML(formatDate(row.creative_date))}</td>
                    <td class="mr-creative-title">${escapeHTML(row.title)}</td>
                    <td>${escapeHTML(row.comp_name)}</td>
                    <td class="mr-cap">${escapeHTML(row.platform)}</td>
                    <td>${statusPill(row.status)}</td>
                    <td>${timing.join(" ")}</td>
                    <td class="mr-remark">${escapeHTML(row.revision_reason || "")}</td>
                </tr>
            `;
        }).join("");
    };

    /*
     * Fetch and render the complete report.
     */
    let activeRequest = 0;

    const loadReport = async () => {
        const month = monthInput.value;

        if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(month)) {
            setMessage("Please select a valid reporting month.", true);
            return;
        }

        const requestId = ++activeRequest;

        refreshButton.disabled = true;
        setMessage("Loading your monthly report...");
        reportContent.hidden = true;

        try {
            const response = await fetch(getEndpoint(month), {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                headers: {
                    "Accept": "application/json"
                }
            });

            let result;

            try {
                result = await response.json();
            } catch {
                throw new Error(
                    "The server returned an invalid response. Check the PHP file and endpoint."
                );
            }

            if (requestId !== activeRequest) {
                return;
            }

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || "Unable to load monthly report."
                );
            }

            const report = result.report;

            renderSummary(report);
            renderNarrative(report);
            renderComparison(report);
            renderCompanies(report.company_reports);
            renderPlatforms(report.platform_reports);
            renderSops(report.sop_stats || {}, report.sop_done);
            renderMeetings(
                report.meeting_stats || {},
                report.meeting_reports || [],
                report.meeting_list || []
            );
            renderRecentCreatives(report.recent_creatives || []);

            reportContent.hidden = false;
            message.hidden = true;
        } catch (error) {
            if (requestId !== activeRequest) {
                return;
            }

            console.error("Monthly Reports:", error);

            setMessage(
                error.message || "Something went wrong while loading reports.",
                true
            );
        } finally {
            if (requestId === activeRequest) {
                refreshButton.disabled = false;
            }
        }
    };

    refreshButton.addEventListener("click", loadReport);

    monthInput.addEventListener("change", loadReport);

    /*
     * Print from an isolated, clean document (hidden iframe) so the app's
     * sidebar, header and layout rules can never affect the printed pages.
     */
    printButton.addEventListener("click", () => {
        const doc = $("mrDoc");

        if (reportContent.hidden || !doc) {
            return;
        }

        const title = `SME Monthly Report - ${monthInput.value}`;
        const originalTitle = document.title;
        const cssUrl = new URL(
            root.dataset.cssUrl || "css/smemonthrep.css",
            window.location.href
        ).toString();
        const base = new URL(".", window.location.href).toString();

        // Remove any frame left behind by an earlier print.
        document.querySelectorAll("iframe.mr-print-frame").forEach((f) => f.remove());

        const frame = document.createElement("iframe");
        frame.className = "mr-print-frame";
        frame.setAttribute("aria-hidden", "true");
        frame.style.cssText =
            "position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;";
        document.body.appendChild(frame);

        const frameDoc = frame.contentDocument;
        frameDoc.open();
        frameDoc.write(
            `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">` +
            `<base href="${base}"><title>${escapeHTML(title)}</title>` +
            `<link rel="stylesheet" href="${cssUrl}"></head>` +
            `<body class="mr-frame-body"><div class="mr-page mr-frame">${doc.outerHTML}</div></body></html>`
        );
        frameDoc.close();

        let printed = false;

        const cleanup = () => {
            document.title = originalTitle;
            setTimeout(() => frame.remove(), 500);
        };

        const go = () => {
            if (printed) {
                return;
            }

            printed = true;
            document.title = title;

            const win = frame.contentWindow;
            win.addEventListener("afterprint", cleanup);
            window.addEventListener("afterprint", cleanup, { once: true });

            win.focus();
            win.print();
        };

        // Print once the stylesheet and the logo have loaded.
        const link = frameDoc.querySelector("link[rel=stylesheet]");
        const images = Array.from(frameDoc.images).filter((img) => !img.complete);
        const waits = [link, ...images].map((el) => new Promise((resolve) => {
            el.addEventListener("load", resolve, { once: true });
            el.addEventListener("error", resolve, { once: true });
        }));

        Promise.race([
            Promise.all(waits),
            new Promise((resolve) => setTimeout(resolve, 2500))
        ]).then(() => setTimeout(go, 150));
    });

    // Automatically load the selected month on page load.
    loadReport();
})();
