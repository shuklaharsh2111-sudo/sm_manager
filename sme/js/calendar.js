(() => {
    'use strict';
    const $ = (selector) => document.querySelector(selector);
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
    const state = {
        platform: 'instagram',
        month: new Date(
            new Date().getFullYear(),
            new Date().getMonth(),
            1
        ),
        selectedDate: null,
        creatives: [],
        loading: false,
        requestNumber: 0
    };
    let previewObjectUrl = null;
    let previousFocusedElement = null;
    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }
    function parseDate(value) {
        const parts = String(value).split('-').map(Number);
        if (parts.length !== 3 || parts.some(Number.isNaN)) {
            return new Date();
        }
        return new Date(parts[0], parts[1] - 1, parts[2]);
    }
    function escapeHTML(value) {
        return String(value ?? '').replace(/[&<>"']/g, (character) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        })[character]);
    }
    function formatLongDate(value) {
        return parseDate(value).toLocaleDateString('en-IN', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        });
    }
    function formatMonth(date) {
        return date.toLocaleDateString('en-IN', {
            month: 'long',
            year: 'numeric'
        });
    }
    function getMonthKey(date = state.month) {
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
    }
    function getImageUrl(path) {
        if (!path) return '';
        const value = String(path).trim();
        // Allow existing relative upload paths and valid HTTP(S) image URLs.
        if (/^https?:\/\//i.test(value)) {
            try {
                const url = new URL(value);
                return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
            } catch {
                return '';
            }
        }
        if (value.startsWith('/') || value.includes('\\') || value.split('/').includes('..')) {
            return '';
        }
        return `../${value.replace(/^\.\/+/, '')}`;
    }
    function showToast(message, type = 'success') {
        const container = $('#toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast${type === 'error' ? ' error' : ''}`;
        toast.textContent = message;
        container.appendChild(toast);
        window.setTimeout(() => {
            toast.remove();
        }, 4200);
    }
    function setLoading(isLoading) {
        state.loading = isLoading;
        $('#calendarLoading').hidden = !isLoading;
        if (isLoading) {
            $('#calendarError').hidden = true;
        }
        $('#prevMonth').disabled = isLoading;
        $('#nextMonth').disabled = isLoading;
        $('#goToday').disabled = isLoading;
    }
    function updateStats() {
        const creatives = state.creatives;
        $('#totalCount').textContent = creatives.length;
        $('#pendingCount').textContent =
            creatives.filter((item) => item.status === 'pending').length;
        $('#approvedCount').textContent =
            creatives.filter((item) => item.status === 'approved').length;
        $('#readyCount').textContent =
            creatives.filter((item) => item.status === 'approved').length;
    }
    function groupByDate() {
        const grouped = {};
        state.creatives.forEach((creative) => {
            if (!grouped[creative.creative_date]) {
                grouped[creative.creative_date] = [];
            }
            grouped[creative.creative_date].push(creative);
        });
        return grouped;
    }
    async function loadCreatives() {
        const requestNumber = ++state.requestNumber;
        setLoading(true);
        $('#calendarGrid').innerHTML = '';
        const query = new URLSearchParams({
            calendar_ajax: '1',
            platform: state.platform,
            month: getMonthKey()
        });
        try {
            const response = await fetch(`calendar.php?${query.toString()}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store'
            });
            const result = await response.json();
            if (requestNumber !== state.requestNumber) return;
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Could not load creatives.');
            }
            state.creatives = Array.isArray(result.creatives)
                ? result.creatives
                : [];
            $('#calendarHeading').textContent =
                `${platformNames[state.platform]} Calendar`;
            $('#calendarSubheading').textContent =
                `View ${platformNames[state.platform]} creatives by date.`;
            $('#monthLabel').textContent = formatMonth(state.month);
            updateStats();
            renderCalendar();
            renderSelectedDay();
        } catch (error) {
            if (requestNumber !== state.requestNumber) return;
            state.creatives = [];
            updateStats();
            renderCalendar();
            renderSelectedDay();
            $('#calendarError').textContent =
                error.message || 'Unable to load calendar data. Please try again.';
            $('#calendarError').hidden = false;
        } finally {
            if (requestNumber === state.requestNumber) {
                setLoading(false);
            }
        }
    }
    function renderCalendar() {
        const grid = $('#calendarGrid');
        const grouped = groupByDate();
        grid.innerHTML = '';
        const firstOfMonth = new Date(
            state.month.getFullYear(),
            state.month.getMonth(),
            1
        );
        // Monday-first calendar.
        const offset = (firstOfMonth.getDay() + 6) % 7;
        const startDate = new Date(
            firstOfMonth.getFullYear(),
            firstOfMonth.getMonth(),
            1 - offset
        );
        const today = formatDate(new Date());
        for (let index = 0; index < 42; index++) {
            const date = new Date(
                startDate.getFullYear(),
                startDate.getMonth(),
                startDate.getDate() + index
            );
            const dateKey = formatDate(date);
            const items = grouped[dateKey] || [];
            const outsideMonth = date.getMonth() !== state.month.getMonth();
            const cell = document.createElement('div');
            cell.className = [
                'calendar-day',
                outsideMonth ? 'outside-month' : '',
                dateKey === today ? 'is-today' : '',
                dateKey === state.selectedDate ? 'is-selected' : ''
            ].filter(Boolean).join(' ');
            cell.dataset.date = dateKey;
            cell.setAttribute('role', 'button');
            cell.setAttribute('tabindex', '0');
            cell.setAttribute('aria-label', `${formatLongDate(dateKey)}, ${items.length} ${items.length === 1 ? 'creative' : 'creatives'}`);
            const top = document.createElement('div');
            top.className = 'day-top';
            const number = document.createElement('span');
            number.className = 'day-number';
            number.textContent = date.getDate();
            top.appendChild(number);
            if (items.length) {
                const total = document.createElement('span');
                total.className = 'day-total';
                total.textContent = `${items.length}`;
                top.appendChild(total);
            }
            cell.appendChild(top);
            items.slice(0, 3).forEach((creative) => {
                const event = document.createElement('button');
                event.type = 'button';
                event.className = `day-event status-${creative.status}`;
                event.title = `${creative.title} · ${statusNames[creative.status] || creative.status}`;
                event.innerHTML = `
                    <span class="event-indicator"></span>
                    <span class="event-title">${escapeHTML(creative.title)}</span>
                `;
                event.addEventListener('click', (eventObject) => {
                    eventObject.stopPropagation();
                    selectDate(dateKey);
                });
                cell.appendChild(event);
            });
            if (items.length > 3) {
                const more = document.createElement('div');
                more.className = 'more-events';
                more.textContent = `+${items.length - 3} more`;
                cell.appendChild(more);
            }
            cell.addEventListener('click', () => selectDate(dateKey));
            cell.addEventListener('keydown', (event) => {
                if (event.target !== cell) return;
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    selectDate(dateKey);
                }
            });
            grid.appendChild(cell);
        }
    }
    function selectDate(dateKey) {
        state.selectedDate = dateKey;
        const selected = parseDate(dateKey);
        if (
            selected.getMonth() !== state.month.getMonth() ||
            selected.getFullYear() !== state.month.getFullYear()
        ) {
            state.month = new Date(
                selected.getFullYear(),
                selected.getMonth(),
                1
            );
            loadCreatives().then(() => {
                renderSelectedDay();
                openDayCreativesModal();
            });
            return;
        }
        renderCalendar();
        renderSelectedDay();
        openDayCreativesModal();
    }
    function renderSelectedDay() {
        const container = document.querySelector('#dayCreatives');
        if (!state.selectedDate || !container) {
            return;
        }
        const items = state.creatives.filter(
            (creative) => creative.creative_date === state.selectedDate
        );
        document.querySelector('#selectedDayTitle').textContent =
            formatLongDate(state.selectedDate);
        document.querySelector('#selectedDaySubtitle').textContent =
            'Creatives scheduled for this date.';
        document.querySelector('#selectedDayCount').textContent =
            `${items.length} ${items.length === 1 ? 'creative' : 'creatives'}`;
        container.innerHTML = '';
        if (items.length === 0) {
            container.innerHTML = `
            <div class="empty-day">
                <strong>No creatives on this date</strong>
                <p>Choose another date to view its creatives.</p>
            </div>
        `;
            return;
        }
        items.forEach((creative) => {
            container.appendChild(createCreativeCard(creative));
        });
    }
    function createCreativeCard(creative) {
        const card = document.createElement('article');
        card.className = 'creative-card';
        const imageUrl = getImageUrl(creative.image_path);
        const status = String(creative.status || 'draft').toLowerCase();
        const statusLabel = statusNames[status] || status;
        const thumbnail = document.createElement('div');
        thumbnail.className = 'creative-thumbnail';
        if (imageUrl) {
            const image = document.createElement('img');
            image.src = imageUrl;
            image.alt = creative.title || 'Creative preview';
            image.loading = 'lazy';
            image.addEventListener('error', () => {
                thumbnail.innerHTML = '<div class="thumbnail-placeholder">◇</div>';
            }, { once: true });
            thumbnail.appendChild(image);
        } else {
            thumbnail.innerHTML = '<div class="thumbnail-placeholder">◇</div>';
        }
        const details = document.createElement('div');
        details.className = 'creative-details';
        const top = document.createElement('div');
        top.className = 'creative-card-top';
        const badge = document.createElement('span');
        badge.className = `status-pill status-${status}`;
        badge.textContent = statusLabel;
        const company = document.createElement('span');
        company.className = 'creative-company';
        company.textContent = creative.company_name || 'Unassigned company';
        top.append(badge, company);
        const title = document.createElement('h3');
        title.textContent = creative.title || 'Untitled creative';
        details.append(top, title);
        if (creative.caption) {
            const caption = document.createElement('p');
            caption.className = 'creative-caption';
            caption.textContent = creative.caption;
            details.appendChild(caption);
        }
        const meta = document.createElement('div');
        meta.className = 'creative-meta';
        const platform = document.createElement('span');
        platform.textContent = platformNames[creative.platform] || creative.platform;
        const date = document.createElement('span');
        date.textContent = creative.creative_date;
        meta.append(platform, date);
        if (creative.reference_link) {
            try {
                const reference = new URL(creative.reference_link);
                if (['http:', 'https:'].includes(reference.protocol)) {
                    const link = document.createElement('a');
                    link.href = reference.href;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.textContent = 'Reference ↗';
                    meta.appendChild(link);
                }
            } catch {
                // Ignore invalid legacy reference URLs.
            }
        }
        details.appendChild(meta);
        if (status === 'approved') {
            const uploadButton = document.createElement('button');
            uploadButton.type = 'button';
            uploadButton.className = 'upload-button';
            uploadButton.innerHTML = '<span>↑</span> Upload Creative';
            uploadButton.addEventListener('click', () => openUploadModal(creative));
            details.appendChild(uploadButton);
        } else {
            const note = document.createElement('span');
            note.className = 'not-approved-note';
            note.textContent = status === 'revision'
                ? `Revision required${creative.revision_reason ? ': ' + creative.revision_reason : ''}`
                : 'Upload is available after approval.';
            details.appendChild(note);
        }
        card.append(thumbnail, details);
        return card;
    }
    function isModalOpen(selector) {
        return Boolean(document.querySelector(selector)?.classList.contains('is-open'));
    }
    // Page scroll stays locked while ANY modal is open.
    function syncBodyScroll() {
        const anyOpen = isModalOpen('#dayCreativesModal') || isModalOpen('#creativeModal');
        document.body.style.overflow = anyOpen ? 'hidden' : '';
    }
    function openDayCreativesModal() {
        const modal = document.querySelector('#dayCreativesModal');
        if (!modal) return;
        if (!modal.classList.contains('is-open') && document.activeElement instanceof HTMLElement) {
            previousFocusedElement = document.activeElement;
        }
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        syncBodyScroll();
        document.querySelector('#closeDayCreativesModal')?.focus();
    }
    function closeDayCreativesModal() {
        const modal = document.querySelector('#dayCreativesModal');
        if (!modal || !modal.classList.contains('is-open')) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        syncBodyScroll();
        if (previousFocusedElement && document.contains(previousFocusedElement)) {
            previousFocusedElement.focus();
        }
        previousFocusedElement = null;
    }
    function openUploadModal(creative) {
        const modal = document.querySelector('#creativeModal');
        const form = document.querySelector('#creativeUploadForm');
        if (!modal || !form) {
            console.error('Creative upload modal or form not found.');
            return;
        }
        form.reset();
        const creativeId = document.querySelector('#creativeId');
        const title = document.querySelector('#creativeTitle');
        const platform = document.querySelector('#creativePlatform');
        const date = document.querySelector('#creativeDate');
        const caption = document.querySelector('#creativeCaption');
        const description = document.querySelector('#creativeDescription');
        const reference = document.querySelector('#creativeReference');
        const keywords = document.querySelector('#creativeKeywords');
        const currentImage = document.querySelector('#currentCreativeImage');
        const noCurrentImage = document.querySelector('#noCurrentImage');
        const preview = document.querySelector('#newImagePreview');
        const selectedFileName = document.querySelector('#selectedFileName');
        if (creativeId) creativeId.value = creative.id ?? '';
        if (title) title.value = creative.title ?? '';
        if (platform) platform.value = creative.platform ?? state.platform;
        if (date) date.value = creative.creative_date ?? creative.date ?? '';
        if (caption) caption.value = creative.caption ?? '';
        if (description) description.value = creative.description ?? '';
        if (reference) reference.value = creative.reference_link ?? creative.reference ?? '';
        if (keywords) keywords.value = creative.keywords ?? '';
        const currentImageUrl = getImageUrl(creative.image_path);
        if (currentImage && currentImageUrl) {
            currentImage.src = currentImageUrl;
            currentImage.hidden = false;
        } else if (currentImage) {
            currentImage.removeAttribute('src');
            currentImage.hidden = true;
        }
        if (noCurrentImage) {
            noCurrentImage.hidden = Boolean(currentImageUrl);
        }
        if (previewObjectUrl) {
            URL.revokeObjectURL(previewObjectUrl);
            previewObjectUrl = null;
        }
        const notice = document.querySelector('#modalNotice');
        if (notice) notice.hidden = true;
        if (preview) {
            preview.removeAttribute('src');
            preview.hidden = true;
        }
        if (selectedFileName) {
            selectedFileName.textContent = 'No file selected';
        }
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        syncBodyScroll();
        title?.focus();
    }
    function closeUploadModal() {
        const modal = document.querySelector('#creativeModal');
        if (!modal || !modal.classList.contains('is-open')) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        // Keep the page locked if the day modal is still open behind it.
        syncBodyScroll();
    }
    function showModalNotice(message, type = 'error') {
        const notice = $('#modalNotice');
        notice.textContent = message;
        notice.classList.toggle('success', type === 'success');
        notice.hidden = false;
    }
    function handleFile(file) {
        const input = $('#creativeImage');
        if (!file) return;
        const allowedTypes = [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif'
        ];
        if (!allowedTypes.includes(file.type)) {
            input.value = '';
            showModalNotice('Choose a JPG, PNG, WEBP or GIF image.');
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            input.value = '';
            showModalNotice('Image size must be under 5 MB.');
            return;
        }
        if (previewObjectUrl) {
            URL.revokeObjectURL(previewObjectUrl);
        }
        previewObjectUrl = URL.createObjectURL(file);
        $('#selectedFileName').textContent = file.name;
        const preview = $('#newImagePreview');
        preview.src = previewObjectUrl;
        preview.hidden = false;
        $('#modalNotice').hidden = true;
    }
    async function submitUpload(event) {
        event.preventDefault();
        const form = $('#creativeUploadForm');
        const submitButton = $('#submitCreative');
        if (!form.reportValidity()) return;
        const imageFile = $('#creativeImage').files[0];
        if (!imageFile) {
            showModalNotice('Please choose the new image before uploading.');
            return;
        }
        const originalButtonText = submitButton.innerHTML;
        submitButton.disabled = true;
        submitButton.textContent = 'Uploading image...';
        $('#cancelModal').disabled = true;
        $('#closeModal').disabled = true;
        $('#modalNotice').hidden = true;
        try {
            const response = await fetch('calendar.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: new FormData(form),
                cache: 'no-store',
                headers: { Accept: 'application/json' }
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Upload failed. Please try again.');
            }
            showToast(result.message || 'Creative uploaded successfully.');
            state.platform = $('#creativePlatform').value;
            state.month = new Date(
                parseDate($('#creativeDate').value).getFullYear(),
                parseDate($('#creativeDate').value).getMonth(),
                1
            );
            state.selectedDate = $('#creativeDate').value;
            document.querySelectorAll('.platform-tab').forEach((tab) => {
                const active = tab.dataset.platform === state.platform;
                tab.classList.toggle('active', active);
                tab.setAttribute('aria-selected', String(active));
            });
            closeUploadModal();
            await loadCreatives();
        } catch (error) {
            showModalNotice(error.message || 'Something went wrong. Please try again.');
        } finally {
            submitButton.disabled = false;
            submitButton.innerHTML = originalButtonText;
            $('#cancelModal').disabled = false;
            $('#closeModal').disabled = false;
        }
    }
    function changeMonth(offset) {
        state.month = new Date(
            state.month.getFullYear(),
            state.month.getMonth() + offset,
            1
        );
        state.selectedDate = null;
        loadCreatives();
    }
    function bindEvents() {
        // Social media platform tabs
        const platformTabs = document.querySelectorAll('.platform-tab');
        platformTabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                const selectedPlatform = tab.dataset.platform;
                if (!selectedPlatform || selectedPlatform === state.platform) {
                    return;
                }
                state.platform = selectedPlatform;
                state.selectedDate = null;
                // Update active tab styling and accessibility state
                platformTabs.forEach((item) => {
                    const isActive = item.dataset.platform === selectedPlatform;
                    item.classList.toggle('active', isActive);
                    item.setAttribute('aria-selected', String(isActive));
                });
                // Close date modal when switching platforms
                closeDayCreativesModal();
                // Load creatives for the selected platform
                loadCreatives();
            });
        });
        $('#goToday').addEventListener('click', () => {
            const today = new Date();
            state.month = new Date(
                today.getFullYear(),
                today.getMonth(),
                1
            );
            state.selectedDate = null;
            loadCreatives();
        });
        $('#prevMonth').addEventListener('click', () => changeMonth(-1));
        $('#nextMonth').addEventListener('click', () => changeMonth(1));
        $('#closeDayCreativesModal')?.addEventListener('click', closeDayCreativesModal);
        document.querySelectorAll('[data-close-day-modal]').forEach((element) => {
            element.addEventListener('click', closeDayCreativesModal);
        });
        $('#closeModal')?.addEventListener('click', closeUploadModal);
        $('#cancelModal')?.addEventListener('click', closeUploadModal);
        document.querySelectorAll('[data-close-upload-modal]').forEach((element) => {
            element.addEventListener('click', closeUploadModal);
        });
        // Escape closes only the top-most modal (upload sits above the day list).
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            if (isModalOpen('#creativeModal')) {
                if (!$('#closeModal').disabled) closeUploadModal();
            } else {
                closeDayCreativesModal();
            }
        });
        // Do not let missing upload-form fields stop calendar initialization.
        if (!$('#creativeImage') || !$('#fileDropZone') || !$('#creativeUploadForm') || !$('#creativeReference')) {
            return;
        }
        $('#creativeImage').addEventListener('change', (event) => {
            handleFile(event.target.files[0]);
        });
        const dropZone = $('#fileDropZone');
        ['dragenter', 'dragover'].forEach((eventName) => {
            dropZone.addEventListener(eventName, (event) => {
                event.preventDefault();
                dropZone.classList.add('drag-over');
            });
        });
        ['dragleave', 'drop'].forEach((eventName) => {
            dropZone.addEventListener(eventName, (event) => {
                event.preventDefault();
                dropZone.classList.remove('drag-over');
            });
        });
        dropZone.addEventListener('drop', (event) => {
            const file = event.dataTransfer?.files?.[0];
            if (!file) return;
            try {
                const transfer = new DataTransfer();
                transfer.items.add(file);
                $('#creativeImage').files = transfer.files;
                handleFile(file);
            } catch {
                showModalNotice('Please use Choose a new creative image to select the file.');
            }
        });
        $('#creativeUploadForm').addEventListener('submit', submitUpload);
        $('#creativeReference').addEventListener('input', () => {
            $('#modalNotice').hidden = true;
        });
    }
    function init() {
        // SME's .dashboard-content has a transform animation, which would make
        // position:fixed modals position against it instead of the viewport.
        ['#dayCreativesModal', '#creativeModal', '#toastContainer'].forEach((selector) => {
            const element = document.querySelector(selector);
            if (element && element.parentElement !== document.body) {
                document.body.appendChild(element);
            }
        });
        $('#todayLabel').textContent = new Date().toLocaleDateString('en-IN', {
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        });
        $('#monthLabel').textContent = formatMonth(state.month);
        bindEvents();
        loadCreatives();
    }
    init();
})();
