document.addEventListener("DOMContentLoaded", function () {
    const report = document.getElementById("companyComparison");
    if (!report) return;

    const searchInput = document.getElementById("bhSearch");
    const exportButton = document.getElementById("bhExport");
    const table = document.getElementById("bhHealthTable");
    const resultCount = document.getElementById("bhResultCount");

    if (!table) return;

    const rows = Array.from(table.querySelectorAll("tbody tr.bh-data-row"));

    const sum = {
        companies: document.getElementById("bhSumCompanies"),
        creatives: document.getElementById("bhSumCreatives"),
        approved: document.getElementById("bhSumApproved"),
        delayed: document.getElementById("bhSumDelayed")
    };

    function cellNumber(row, col) {
        const cell = row.querySelector('td[data-col="' + col + '"]');
        return parseInt((cell ? cell.textContent : "0").replace(/[^0-9]/g, ""), 10) || 0;
    }

    function cellText(row, col) {
        const cell = row.querySelector('td[data-col="' + col + '"]');
        return cell ? cell.textContent.trim() : "";
    }

    function companyName(row) {
        const el = row.querySelector(".bh-company-info strong");
        return el ? el.textContent.trim() : "";
    }

    function visibleRows() {
        return rows.filter(function (row) {
            return !row.classList.contains("bh-hidden");
        });
    }

    // Top boxes + footer follow whatever is currently shown in the table.
    function updateSummary() {
        const shown = visibleRows();
        const total = function (col) {
            return shown.reduce(function (n, r) { return n + cellNumber(r, col); }, 0);
        };

        if (sum.companies) sum.companies.textContent = shown.length;
        if (sum.creatives) sum.creatives.textContent = total("total");
        if (sum.approved) sum.approved.textContent = total("approved");
        if (sum.delayed) sum.delayed.textContent = total("delayed");

        if (resultCount) {
            resultCount.textContent =
                "Showing " + shown.length + " of " + rows.length + " assigned companies";
        }
    }

    function updateReport() {
        const term = (searchInput ? searchInput.value : "").trim().toLowerCase();

        rows.forEach(function (row) {
            row.classList.toggle(
                "bh-hidden",
                term !== "" && !companyName(row).toLowerCase().includes(term)
            );
        });

        updateSummary();
    }

    if (searchInput) searchInput.addEventListener("input", updateReport);

    function csvEscape(value) {
        return '"' + String(value == null ? "" : value).replace(/"/g, '""') + '"';
    }

    if (exportButton) {
        exportButton.addEventListener("click", function () {
            const shown = visibleRows();

            if (shown.length === 0) {
                alert("No company records available to export.");
                return;
            }

            const lines = [
                ["Company", "Total", "Approved", "Pending", "Redo", "Rejected", "Delayed", "Approval %"]
                    .map(csvEscape).join(",")
            ];

            shown.forEach(function (row) {
                lines.push([
                    companyName(row),
                    cellNumber(row, "total"),
                    cellNumber(row, "approved"),
                    cellNumber(row, "pending"),
                    cellNumber(row, "revision"),
                    cellNumber(row, "rejected"),
                    cellNumber(row, "delayed"),
                    cellText(row, "approval").replace("%", "")
                ].map(csvEscape).join(","));
            });

            const blob = new Blob(["\uFEFF" + lines.join("\r\n")], {
                type: "text/csv;charset=utf-8;"
            });
            const url = URL.createObjectURL(blob);
            const link = document.createElement("a");

            link.href = url;
            link.download = "company-comparison.csv";
            document.body.appendChild(link);
            link.click();
            link.remove();

            setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        });
    }

    updateReport();
});
