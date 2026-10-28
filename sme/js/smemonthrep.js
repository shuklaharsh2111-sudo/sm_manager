
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


    /*
     * Overall summary cards.
     */
    const renderSummary = (report) => {
        const stats = report.stats || {};
        const previous = report.previous_stats || {};

        setText("mrPeriodLabel", report.month || "Monthly Report");

        setText(
            "mrCompanyCount",
            `${number((report.companies || []).length)} assigned companies`
        );

        setText("mrTotal", number(stats.total));
        setText("mrApproved", number(stats.approved));
        setText("mrPending", number(stats.pending));
        setText("mrRevision", number(stats.revision));
        setText("mrRejected", number(stats.rejected));
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
     * Company-wise table.
     */
    const renderCompanies = (rows) => {
        const body = $("mrCompanyRows");

        if (!rows || rows.length === 0) {
            body.innerHTML = emptyRow(
                8,
                "No assigned companies found. Ask your admin to assign a company."
            );
            return;
        }

        body.innerHTML = rows.map((row) => {
            const total = Number(row.total || 0);
            const approved = Number(row.approved || 0);

            const rate = total > 0
                ? (approved / total) * 100
                : 0;

            const safeRate = Math.max(0, Math.min(100, rate));

            return `
                <tr>
                    <td class="mr-company-name">
                        ${escapeHTML(row.comp_name)}
                    </td>
                    <td>${number(total)}</td>
                    <td>${number(approved)}</td>
                    <td>${number(row.pending)}</td>
                    <td>${number(row.revision)}</td>
                    <td>${number(row.rejected)}</td>
                    <td>${number(row.draft)}</td>
                    <td>
                        <div class="mr-rate">
                            <span>${rate.toFixed(1)}%</span>
                            <div class="mr-rate-track">
                                <div
                                    class="mr-rate-fill"
                                    style="width:${safeRate}%"
                                ></div>
                            </div>
                        </div>
                    </td>
                </tr>
            `;
        }).join("");
    };

    /*
     * Platform distribution and approval counts.
     */
    const renderPlatforms = (rows) => {
        const container = $("mrPlatformRows");

        if (!rows || rows.length === 0) {
            container.innerHTML = `
                <div class="mr-empty">
                    No creatives recorded for this month.
                </div>
            `;
            return;
        }

        const maximum = Math.max(
            1,
            ...rows.map((row) => Number(row.total || 0))
        );

        container.innerHTML = rows.map((row) => {
            const total = Number(row.total || 0);
            const width = Math.max(
                0,
                Math.min(100, (total / maximum) * 100)
            );

            return `
                <div class="mr-platform-item">
                    <div class="mr-platform-top">
                        <strong>${escapeHTML(row.platform)}</strong>
                        <span>${number(total)} creatives</span>
                    </div>

                    <div class="mr-platform-track">
                        <div
                            class="mr-platform-fill"
                            style="width:${width}%"
                        ></div>
                    </div>

                    <div class="mr-platform-sub">
                        Approved: ${number(row.approved)}
                        &nbsp; | &nbsp;
                        Pending: ${number(row.pending)}
                        &nbsp; | &nbsp;
                        Revision: ${number(row.revision)}
                    </div>
                </div>
            `;
        }).join("");
    };

    /*
     * SOP schedule totals.
     */
    const renderSops = (stats) => {
        setText("mrSopTotal", number(stats.total));
        setText("mrSopDaily", number(stats.daily));
        setText("mrSopWeekly", number(stats.weekly));
        setText("mrSopMonthly", number(stats.monthly));
    };

    /*
     * Meeting totals and company breakdown.
     */
    const renderMeetings = (stats, rows) => {
        setText("mrMeetingTotal", number(stats.total));
        setText("mrMeetingDone", number(stats.done));
        setText("mrMeetingPending", number(stats.not_done));

        const body = $("mrMeetingRows");

        if (!rows || rows.length === 0) {
            body.innerHTML = emptyRow(
                4,
                "No assigned companies available for meeting reports."
            );
            return;
        }

        body.innerHTML = rows.map((row) => `
            <tr>
                <td class="mr-company-name">
                    ${escapeHTML(row.comp_name)}
                </td>
                <td>${number(row.total)}</td>
                <td>${number(row.done)}</td>
                <td>${number(row.not_done)}</td>
            </tr>
        `).join("");
    };

    /*
     * Latest creative records.
     */
    const renderRecentCreatives = (rows) => {
        const body = $("mrRecentRows");

        if (!rows || rows.length === 0) {
            body.innerHTML = emptyRow(
                5,
                "No creatives found for this month."
            );
            return;
        }

        body.innerHTML = rows.map((row) => `
            <tr>
                <td>${escapeHTML(row.creative_date)}</td>
                <td class="mr-creative-title">
                    ${escapeHTML(row.title)}
                </td>
                <td>${escapeHTML(row.comp_name)}</td>
                <td>${escapeHTML(row.platform)}</td>
                <td>${statusPill(row.status)}</td>
            </tr>
        `).join("");
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
            renderCompanies(report.company_reports);
            renderPlatforms(report.platform_reports);
            renderSops(report.sop_stats || {});
            renderMeetings(
                report.meeting_stats || {},
                report.meeting_reports || []
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

    printButton.addEventListener("click", () => {
        if (!reportContent.hidden) {
            window.print();
        }
    });

    // Automatically load the selected month on page load.
    loadReport();
})();
