(() => {
    'use strict';
    const root = document.getElementById('adminCalendar');
    // Prevent duplicate initialization if the script is included twice.
    if (!root || root.dataset.initialized === 'true') return;
    root.dataset.initialized = 'true';
    const $ = (selector) => root.querySelector(selector);
    const grid = $('#acGrid');
    const monthLabel = $('#acMonthLabel');
    const selectedDateLabel = $('#acSelectedDate');
    const creativeList = $('#acCreativeList');
    const dateCount = $('#acDateCount');
    const state = {
        month: new Date(),
        selectedDate: null,
        events: [],
        eventsByDate: new Map(),
        requestId: 0
    };
    const platformNames = {
        instagram: 'Instagram',
        facebook: 'Facebook',
        linkedin: 'LinkedIn',
        google: 'Google',
        youtube: 'YouTube',
        x: 'X',
        pinterest: 'Pinterest',
        threads: 'Threads'
    };
    const statusNames = {
        draft: 'Draft',
        pending: 'Pending',
        approved: 'Approved',
        rejected: 'Rejected',
        revision: 'Revision'
    };
    function escapeHTML(value) {
        return String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        })[char]);
    }
    function dateKey(year, monthIndex, day) {
        return [
            year,
            String(monthIndex + 1).padStart(2, '0'),
            String(day).padStart(2, '0')
        ].join('-');
    }
    function parseDate(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(
            String(value || '')
        );
        if (!match) return null;
        const year = Number(match[1]);
        const month = Number(match[2]);
        const day = Number(match[3]);
        const date = new Date(year, month - 1, day);
        if (
            date.getFullYear() !== year ||
            date.getMonth() !== month - 1 ||
            date.getDate() !== day
        ) {
            return null;
        }
        return date;
    }
    function formatDate(value, options = {}) {
        const date = parseDate(value);
        if (!date) return value || 'Not provided';
        return date.toLocaleDateString('en-IN', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            ...options
        });
    }
    function formatMonth(date) {
        return date.toLocaleDateString('en-IN', {
            month: 'long',
            year: 'numeric'
        });
    }
    function safeImagePath(value) {
        const path = String(value || '').trim();
        if (!path) return '';
        // Reject javascript:, data:, and other non-HTTP schemes.
        if (/^(javascript|data|vbscript):/i.test(path)) return '';
        // Allow external HTTPS/HTTP images.
        if (/^https?:\/\//i.test(path)) {
            try {
                const url = new URL(path);
                if (!['http:', 'https:'].includes(url.protocol)) {
                    return '';
                }
                return url.href;
            } catch {
                return '';
            }
        }
        // Keep root-relative paths and paths relative to the website.
        if (path.startsWith('//')) return '';
        if (path.startsWith('/')) {
            return path;
        }
        // The project stores relative upload paths such as uploads/...
        // admincalendar.php is inside the admin folder.
        const normalized = path.replace(/\\/g, '/');
        if (
            normalized.split('/').includes('..') ||
            normalized.startsWith('./')
        ) {
            return '';
        }
        return '../' + normalized;
    }
    function safeExternalLink(value) {
        const link = String(value || '').trim();
        if (!link) return '';
        try {
            const url = new URL(link);
            if (!['http:', 'https:'].includes(url.protocol)) {
                return '';
            }
            return url.href;
        } catch {
            return '';
        }
    }
    function showToast(message) {
        const toast = $('#acToast');
        toast.textContent = message;
        toast.classList.add('ac-toast-show');
        clearTimeout(showToast.timeout);
        showToast.timeout = setTimeout(() => {
            toast.classList.remove('ac-toast-show');
        }, 3200);
    }
    function groupEvents(events) {
        const grouped = new Map();
        events.forEach((event) => {
            const key = event.creative_date;
            if (!parseDate(key)) return;
            if (!grouped.has(key)) {
                grouped.set(key, []);
            }
            grouped.get(key).push(event);
        });
        state.eventsByDate = grouped;
    }
    function updateStats() {
        $('#acTotal').textContent = state.events.length;
        $('#acActiveDates').textContent =
            state.eventsByDate.size;
        $('#acApproved').textContent =
            state.events.filter(
                event => event.status === 'approved'
            ).length;
        $('#acPending').textContent =
            state.events.filter(
                event => event.status === 'pending'
            ).length;
    }
    async function loadMonth() {
        const year = state.month.getFullYear();
        const monthIndex = state.month.getMonth();
        const monthKey = [
            year,
            String(monthIndex + 1).padStart(2, '0')
        ].join('-');
        const requestId = ++state.requestId;
        root.classList.add('ac-loading');
        monthLabel.textContent = formatMonth(state.month);
        grid.innerHTML = '';
        try {
            const response = await fetch(
                'admincalendar.php?action=events&month=' +
                encodeURIComponent(monthKey),
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json'
                    },
                    cache: 'no-store'
                }
            );
            if (requestId !== state.requestId) return;
            if (!response.ok) {
                throw new Error(
                    response.status === 403
                        ? 'Admin access denied. Please check your login role.'
                        : response.status === 401
                            ? 'Session expired. Please log in again.'
                            : 'Unable to load creatives (HTTP ' +
                            response.status + ').'
                );
            }
            const data = await response.json();
            if (requestId !== state.requestId) return;
            if (!Array.isArray(data.events)) {
                throw new Error(
                    data.error || 'Invalid calendar response.'
                );
            }
            state.events = data.events;
            groupEvents(state.events);
            updateStats();
            renderCalendar();
            if (!state.selectedDate) {
                const today = new Date();
                const todayIsVisible =
                    today.getFullYear() === year &&
                    today.getMonth() === monthIndex;
                state.selectedDate = todayIsVisible
                    ? dateKey(year, monthIndex, today.getDate())
                    : dateKey(year, monthIndex, 1);
            }
            const selected = parseDate(state.selectedDate);
            if (
                !selected ||
                selected.getFullYear() !== year ||
                selected.getMonth() !== monthIndex
            ) {
                state.selectedDate = dateKey(year, monthIndex, 1);
            }
            renderCalendar();
            renderSelectedDate();
        } catch (error) {
            if (requestId !== state.requestId) return;
            console.error('Admin calendar error:', error);
            grid.innerHTML = `
                <div class="ac-error"
                     style="grid-column:1/-1">
                    ${escapeHTML(error.message)}
                    <br>
                    <button type="button" id="acRetry"
                        style="margin-top:12px;padding:9px 14px;
                        border-radius:8px;border:1px solid #a78bfa;
                        background:#251747;color:#fff;cursor:pointer">
                        Retry
                    </button>
                </div>
            `;
            $('#acTotal').textContent = '—';
            $('#acActiveDates').textContent = '—';
            $('#acApproved').textContent = '—';
            $('#acPending').textContent = '—';
            selectedDateLabel.textContent = 'Calendar unavailable';
            dateCount.textContent = '!';
            creativeList.innerHTML = `
                <div class="ac-error">
                    ${escapeHTML(error.message)}
                </div>
            `;
        } finally {
            if (requestId === state.requestId) {
                root.classList.remove('ac-loading');
            }
        }
    }
    function renderCalendar() {
        const year = state.month.getFullYear();
        const monthIndex = state.month.getMonth();
        const firstDay = new Date(year, monthIndex, 1);
        const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();
        // Monday-first calendar: Monday = 0, Sunday = 6.
        const startOffset = (firstDay.getDay() + 6) % 7;
        const today = new Date();
        const todayKey = dateKey(
            today.getFullYear(),
            today.getMonth(),
            today.getDate()
        );
        monthLabel.textContent = formatMonth(state.month);
        let html = '';
        // Empty cells before the first day.
        for (let i = 0; i < startOffset; i++) {
            html += `
            <div class="ac-day ac-day-muted"
                 aria-hidden="true"></div>
        `;
        }
        for (let day = 1; day <= daysInMonth; day++) {
            const key = dateKey(year, monthIndex, day);
            const events = state.eventsByDate.get(key) || [];
            const isToday = key === todayKey;
            const isSelected = key === state.selectedDate;
            const hasEvents = events.length > 0;
            const classes = [
                'ac-day',
                isToday ? 'ac-day-today' : '',
                isSelected ? 'ac-day-selected' : '',
                hasEvents ? 'ac-day-has-events' : ''
            ].filter(Boolean).join(' ');
            const accessibleLabel =
                formatDate(key, {
                    weekday: 'long',
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric'
                }) +
                (hasEvents
                    ? `, ${events.length} creatives`
                    : ', no creatives');
            /*
             * Show up to 2 creative image thumbnails per date.
             * Remaining creatives are indicated with a +N badge.
             */
            const imageEvents = events
                .filter(event => safeImagePath(event.image_path))
                .slice(0, 2);
            const missingImageCount = events.length - imageEvents.length;
            let previewHTML = '';
            if (imageEvents.length > 0) {
                previewHTML = `
                <span class="ac-day-previews">
                    ${imageEvents.map((event, index) => {
                    const imagePath = safeImagePath(event.image_path);
                    return `
                            <img
                                class="ac-day-thumbnail"
                                src="${escapeHTML(imagePath)}"
                                alt="${escapeHTML(event.title || 'Creative preview')}"
                                loading="lazy"
                                title="${escapeHTML(event.title || 'Creative')}"
                                onerror="this.classList.add('ac-image-failed')">
                        `;
                }).join('')}
                    ${events.length > imageEvents.length ? `
                        <span class="ac-day-more">
                            +${events.length - imageEvents.length}
                        </span>
                    ` : ''}
                </span>
            `;
            } else if (hasEvents) {
                // Keep a visible indicator if creatives have no usable image.
                previewHTML = `
                <span class="ac-day-count">
                    <i></i>
                    ${events.length}
                    ${events.length === 1 ? 'item' : 'items'}
                </span>
            `;
            }
            html += `
            <button
                type="button"
                class="${classes}"
                data-date="${key}"
                aria-label="${escapeHTML(accessibleLabel)}"
                aria-pressed="${isSelected}"
                title="${escapeHTML(accessibleLabel)}">
                <span class="ac-day-number">${day}</span>
                ${previewHTML}
            </button>
        `;
        }
        grid.innerHTML = html;
    }
    function metaRow(label, value, allowHTML = false) {
        if (
            value === null ||
            value === undefined ||
            String(value).trim() === ''
        ) {
            return '';
        }
        return `
            <div class="ac-meta-row">
                <span class="ac-meta-label">
                    ${escapeHTML(label)}
                </span>
                <span class="ac-meta-value">
                    ${allowHTML ? value : escapeHTML(value)}
                </span>
            </div>
        `;
    }
    function renderCreative(event, index) {
        const title = event.title || 'Untitled creative';
        const platform =
            platformNames[event.platform] || event.platform || 'Unknown';
        const status = String(event.status || 'draft').toLowerCase();
        const allowedStatuses = [
            'draft',
            'pending',
            'approved',
            'rejected',
            'revision'
        ];
        const statusClass = allowedStatuses.includes(status)
            ? status
            : 'draft';
        const statusLabel = statusNames[status] || status;
        const imagePath = safeImagePath(event.image_path);
        const creatorImage = safeImagePath(event.creator_image);
        const referenceLink = safeExternalLink(event.reference_link);
        const creatorName = event.creator_name || 'Unknown creator';
        const initials = creatorName
            .trim()
            .split(/\s+/)
            .slice(0, 2)
            .map(part => part.charAt(0))
            .join('')
            .toUpperCase() || 'U';
        const companyName = event.company_name || 'Not assigned';
        const description = event.description || '';
        const caption = event.caption || '';
        const keywords = event.keywords || '';
        const creatorContact = event.creator_contact || '';
        const creatorDetails = [
            event.creator_email || '',
            creatorContact ? 'Contact: ' + creatorContact : ''
        ].filter(Boolean).join(' · ');
        const imageHTML = imagePath ? `
            <img
                class="ac-creative-image"
                src="${escapeHTML(imagePath)}"
                alt="${escapeHTML(title)}"
                loading="lazy"
                onerror="this.style.display='none'">
        ` : '';
        const avatarHTML = creatorImage ? `
            <img
                src="${escapeHTML(creatorImage)}"
                alt=""
                loading="lazy"
                onerror="this.style.display='none'">
        ` : '';
        const referenceHTML = referenceLink ? `
            <a
                class="ac-reference-btn"
                href="${escapeHTML(referenceLink)}"
                target="_blank"
                rel="noopener noreferrer">
                Open Reference Link ↗
            </a>
        ` : '';
        return `
            <article class="ac-creative"
                     style="animation-delay:${Math.min(index * 45, 250)}ms">
                <div class="ac-creative-top">
                    <div>
                        <h4>${escapeHTML(title)}</h4>
                        <span class="ac-platform">
                            ${escapeHTML(platform)}
                        </span>
                    </div>
                    <span class="ac-status ac-status-${statusClass}">
                        ${escapeHTML(statusLabel)}
                    </span>
                </div>
                ${imageHTML}
                ${description ? `
                    <p class="ac-creative-description">
                        ${escapeHTML(description)}
                    </p>
                ` : ''}
                <div class="ac-meta">
                    ${metaRow('Creative ID', '#' + event.id)}
                    ${metaRow('Company', companyName)}
                    ${metaRow('Brand', event.creator_brand || '')}
                    ${metaRow('Creative Date', formatDate(event.creative_date))}
                    ${metaRow('Created At', event.created_at || '')}
                    ${metaRow('User ID', event.user_id)}
                    ${metaRow('Company ID', event.company_id)}
                    ${metaRow('SOP ID', event.sop_id)}
                    ${metaRow('Caption', caption)}
                    ${metaRow('Keywords', keywords)}
                    ${metaRow('Revision Reason', event.revision_reason)}
                    ${metaRow('Image Path', event.image_path)}
                </div>
                <div class="ac-creator">
                    <div class="ac-avatar">
                        ${avatarHTML || escapeHTML(initials)}
                    </div>
                    <div class="ac-creator-info">
                        <strong>${escapeHTML(creatorName)}</strong>
                        <span>${escapeHTML(creatorDetails || 'No contact information')}</span>
                    </div>
                </div>
                ${referenceHTML}
            </article>
        `;
    }
    function renderSelectedDate() {
        const key = state.selectedDate;
        const date = parseDate(key);
        if (!date) {
            selectedDateLabel.textContent = 'Select a date';
            dateCount.textContent = '0';
            return;
        }
        selectedDateLabel.textContent = formatDate(key, {
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        });
        const events = state.eventsByDate.get(key) || [];
        dateCount.textContent = String(events.length);
        if (!events.length) {
            creativeList.innerHTML = `
                <div class="ac-empty">
                    <div class="ac-empty-icon">✦</div>
                    <h4>No creatives on this date</h4>
                    <p>
                        There are no creatives scheduled for
                        ${escapeHTML(formatDate(key))}.
                        Select another highlighted date.
                    </p>
                </div>
            `;
            return;
        }
        creativeList.innerHTML = events
            .map((event, index) => renderCreative(event, index))
            .join('');
    }
    function changeMonth(offset) {
        const current = state.month;
        state.month = new Date(
            current.getFullYear(),
            current.getMonth() + offset,
            1
        );
        state.selectedDate = null;
        loadMonth();
    }
    grid.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-date]');
        if (!button || !grid.contains(button)) return;
        state.selectedDate = button.dataset.date;
        renderCalendar();
        renderSelectedDate();
    });
    $('#acPrev').addEventListener('click', () => {
        changeMonth(-1);
    });
    $('#acNext').addEventListener('click', () => {
        changeMonth(1);
    });
    $('#acToday').addEventListener('click', () => {
        const today = new Date();
        state.month = new Date(
            today.getFullYear(),
            today.getMonth(),
            1
        );
        state.selectedDate = dateKey(
            today.getFullYear(),
            today.getMonth(),
            today.getDate()
        );
        loadMonth();
    });
    grid.addEventListener('click', (event) => {
        if (event.target.id === 'acRetry') {
            loadMonth();
        }
    });
    loadMonth();
})();
