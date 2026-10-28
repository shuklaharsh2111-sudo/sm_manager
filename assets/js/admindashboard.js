
/* =========================================
   Creative Manager - Admin Dashboard
========================================= */

(() => {
    'use strict';

    const dashboard = document.getElementById('cmDashboard');

    if (!dashboard) {
        return;
    }

    /* -----------------------------
       Update dashboard date
    ----------------------------- */

    const dateElement = document.getElementById('cmCurrentDate');

    if (dateElement) {
        const now = new Date();

        dateElement.textContent = new Intl.DateTimeFormat('en-IN', {
            weekday: 'long',
            day: '2-digit',
            month: 'long',
            year: 'numeric'
        }).format(now);
    }

    /* -----------------------------
       Recent creatives search/filter
    ----------------------------- */

    const table = dashboard.querySelector('.cm-data-table');
    const tableWrap = dashboard.querySelector('.cm-table-wrap');

    if (!table || !tableWrap) {
        return;
    }

    const tableBody = table.querySelector('tbody');

    if (!tableBody) {
        return;
    }

    const rows = Array.from(tableBody.querySelectorAll('tr'));

    if (!rows.length) {
        return;
    }

    const filterBar = document.createElement('div');
    filterBar.className = 'cm-table-filters';

    filterBar.innerHTML = `
        <label class="cm-search-box">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input
                type="search"
                id="cmCreativeSearch"
                placeholder="Search title, company, creator..."
                aria-label="Search recent creatives"
                autocomplete="off"
            >
        </label>

        <label class="cm-filter-select">
            <span class="cm-filter-label">Status</span>
            <select id="cmCreativeStatusFilter" aria-label="Filter creatives by status">
                <option value="all">All statuses</option>
                <option value="draft">Draft</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
                <option value="revision">Revision</option>
            </select>
        </label>

        <button type="button" class="cm-filter-reset" id="cmCreativeFilterReset">
            Reset
        </button>
    `;

    tableWrap.parentNode.insertBefore(filterBar, tableWrap);

    const searchInput = filterBar.querySelector('#cmCreativeSearch');
    const statusSelect = filterBar.querySelector('#cmCreativeStatusFilter');
    const resetButton = filterBar.querySelector('#cmCreativeFilterReset');

    const resultMessage = document.createElement('p');
    resultMessage.className = 'cm-filter-result';
    resultMessage.setAttribute('aria-live', 'polite');

    filterBar.insertAdjacentElement('afterend', resultMessage);

    function filterCreatives() {
        const searchTerm = searchInput.value.trim().toLowerCase();
        const selectedStatus = statusSelect.value;

        let visibleCount = 0;

        rows.forEach((row) => {
            const rowText = row.textContent.toLowerCase();
            const rowStatus = (
                row.dataset.creativeStatus || ''
            ).toLowerCase();

            const matchesSearch = rowText.includes(searchTerm);
            const matchesStatus =
                selectedStatus === 'all' ||
                rowStatus === selectedStatus;

            const isVisible = matchesSearch && matchesStatus;

            row.hidden = !isVisible;

            if (isVisible) {
                visibleCount++;
            }
        });

        resultMessage.textContent =
            `Showing ${visibleCount} of ${rows.length} recent creatives`;

        let emptyMessage = tableBody.querySelector('.cm-filter-empty');

        if (visibleCount === 0) {
            if (!emptyMessage) {
                const emptyRow = document.createElement('tr');
                emptyRow.className = 'cm-filter-empty';

                const emptyCell = document.createElement('td');
                emptyCell.colSpan = table.querySelectorAll('thead th').length;
                emptyCell.className = 'cm-filter-empty-cell';
                emptyCell.textContent = 'No creatives match your search or status filter.';

                emptyRow.appendChild(emptyCell);
                tableBody.appendChild(emptyRow);
            }
        } else if (emptyMessage) {
            emptyMessage.remove();
        }
    }

    searchInput.addEventListener('input', filterCreatives);
    statusSelect.addEventListener('change', filterCreatives);

    resetButton.addEventListener('click', () => {
        searchInput.value = '';
        statusSelect.value = 'all';
        filterCreatives();
        searchInput.focus();
    });

    filterCreatives();

})();
