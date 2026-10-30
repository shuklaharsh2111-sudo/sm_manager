(() => {
    'use strict';
    const $ = (s) => document.querySelector(s);
    const state = { 
        platform: 'instagram', 
        selectedCompanyId: null, 
        companies: [], 
        month: new Date(), 
        selectedDate: null, 
        creatives: [] 
    };
    
    function formatDate(date) {
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    }
    
    function getMonthKey() {
        return `${state.month.getFullYear()}-${String(state.month.getMonth() + 1).padStart(2, '0')}`;
    }

    function getImageUrl(path) {
        if (!path) return '';
        if (/^https?:\/\//i.test(path)) return path;
        return `../${path.replace(/^\.\/+/, '')}`;
    }

    function showToast(msg) {
        const c = $('#toastContainer');
        const t = document.createElement('div');
        t.className = 'toast';
        t.textContent = msg;
        c.appendChild(t);
        setTimeout(() => t.remove(), 4000);
    }

    async function loadCompanies() {
        try {
            const res = await fetch('calendar.php?fetch_assigned_companies=1');
            const data = await res.json();
            if (data.success && data.companies.length > 0) {
                state.companies = data.companies;
                state.selectedCompanyId = data.companies[0].id;
                renderCompanyBar();
                loadCreatives();
            } else {
                $('#companyBar').innerHTML = '<p style="color:var(--muted);font-size:11px;">No assigned companies found.</p>';
            }
        } catch (e) {
            $('#companyBar').innerHTML = '<p style="color:var(--red);font-size:11px;">Error loading companies.</p>';
        }
    }

    function renderCompanyBar() {
        const bar = $('#companyBar');
        bar.innerHTML = '';
        state.companies.forEach(comp => {
            const item = document.createElement('div');
            item.className = `company-item ${comp.id === state.selectedCompanyId ? 'active' : ''}`;
            
            let logoHtml = '';
            if (comp.comp_logo) {
                logoHtml = `<img src="${getImageUrl(comp.comp_logo)}" alt="${comp.comp_name}">`;
            } else {
                logoHtml = `<span>${comp.comp_name.charAt(0).toUpperCase()}</span>`;
            }

            item.innerHTML = `
                <div class="company-avatar">${logoHtml}</div>
                <div class="company-name">${comp.comp_name}</div>
            `;

            item.addEventListener('click', () => {
                if (state.selectedCompanyId !== comp.id) {
                    state.selectedCompanyId = comp.id;
                    document.querySelectorAll('.company-item').forEach(el => el.classList.remove('active'));
                    item.classList.add('active');
                    loadCreatives();
                }
            });

            bar.appendChild(item);
        });
    }

    async function loadCreatives() {
        if (!state.selectedCompanyId) {
            state.creatives = [];
            renderCalendar();
            return;
        }

        const query = new URLSearchParams({ 
            calendar_ajax: '1', 
            platform: state.platform, 
            company_id: state.selectedCompanyId, 
            month: getMonthKey() 
        });

        try {
            const res = await fetch(`calendar.php?${query}`);
            const data = await res.json();
            state.creatives = data.success ? data.creatives : [];
            $('#monthLabel').textContent = state.month.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
            renderCalendar();
        } catch (e) {
            state.creatives = [];
            renderCalendar();
        }
    }

    function renderCalendar() {
        const grid = $('#calendarGrid');
        grid.innerHTML = '';
        const grouped = {};
        state.creatives.forEach(c => {
            if (!grouped[c.creative_date]) grouped[c.creative_date] = [];
            grouped[c.creative_date].push(c);
        });

        const first = new Date(state.month.getFullYear(), state.month.getMonth(), 1);
        const offset = (first.getDay() + 6) % 7;
        const start = new Date(first.getFullYear(), first.getMonth(), 1 - offset);
        const today = formatDate(new Date());

        for (let i = 0; i < 42; i++) {
            const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
            const dateKey = formatDate(d);
            const items = grouped[dateKey] || [];
            
            const cell = document.createElement('div');
            cell.className = `calendar-day ${d.getMonth() !== state.month.getMonth() ? 'outside-month' : ''} ${dateKey === today ? 'is-today' : ''}`;
            
            const top = document.createElement('span');
            top.className = 'day-number';
            top.textContent = d.getDate();
            cell.appendChild(top);

            const approvedItem = items.find(item => item.status === 'approved' && item.image_path);
            if (approvedItem) {
                const imgContainer = document.createElement('div');
                imgContainer.className = 'cell-image-container';
                imgContainer.innerHTML = `<img src="${getImageUrl(approvedItem.image_path)}" class="cell-approved-img" alt="">`;
                cell.appendChild(imgContainer);
            }

            // Date par click karne par seedha Add Creative Form Modal open hoga
            cell.addEventListener('click', () => {
                if (!state.selectedCompanyId) {
                    alert('Please select a company first.');
                    return;
                }
                state.selectedDate = dateKey;
                openAddModal(dateKey);
            });
            grid.appendChild(cell);
        }
    }

    function openAddModal(dateKey) {
        const currentComp = state.companies.find(c => c.id === state.selectedCompanyId);
        const compName = currentComp ? currentComp.comp_name : '';

        $('#addModalTitle').textContent = `Add Creative (${compName})`;
        $('#addModalSubtitle').textContent = `Date: ${dateKey}`;
        $('#addCreativeDate').value = dateKey;
        $('#addCompanyId').value = state.selectedCompanyId;
        $('#addPlatformSelect').value = state.platform;

        // Reset image preview state
        $('#creativeImageInput').value = '';
        $('#dropzonePlaceholder').style.display = 'flex';
        $('#imagePreviewContainer').style.display = 'none';
        $('#imagePreview').src = '';

        $('#addCreativeForm').reset();
        $('#addPlatformSelect').value = state.platform; // reset ke baad platform re-apply karein

        $('#addCreativeModal').classList.add('is-open');
    }

    document.addEventListener('DOMContentLoaded', () => {
        $('#todayLabel').textContent = new Date().toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
        
        document.querySelectorAll('.platform-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.platform-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                state.platform = tab.dataset.platform;
                loadCreatives();
            });
        });

        $('#prevMonth').addEventListener('click', () => { state.month.setMonth(state.month.getMonth() - 1); loadCreatives(); });
        $('#nextMonth').addEventListener('click', () => { state.month.setMonth(state.month.getMonth() + 1); loadCreatives(); });
        $('#goToday').addEventListener('click', () => { state.month = new Date(); loadCreatives(); });

        // Image upload preview listener
        $('#creativeImageInput').addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    $('#imagePreview').src = e.target.result;
                    $('#dropzonePlaceholder').style.display = 'none';
                    $('#imagePreviewContainer').style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });

        $('#removeImageBtn').addEventListener('click', (e) => {
            e.stopPropagation();
            $('#creativeImageInput').value = '';
            $('#imagePreview').src = '';
            $('#imagePreviewContainer').style.display = 'none';
            $('#dropzonePlaceholder').style.display = 'flex';
        });

        $('#closeAddModal').addEventListener('click', () => $('#addCreativeModal').classList.remove('is-open'));
        $('#cancelAddModal').addEventListener('click', () => $('#addCreativeModal').classList.remove('is-open'));

        $('#addCreativeForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const res = await fetch('calendar.php', { method: 'POST', body: new FormData(e.target) });
            const data = await res.json();
            if (data.success) {
                showToast(data.message);
                $('#addCreativeModal').classList.remove('is-open');
                e.target.reset();
                state.platform = $('#addPlatformSelect').value;
                document.querySelectorAll('.platform-tab').forEach(t => {
                    t.classList.toggle('active', t.dataset.platform === state.platform);
                });
                loadCreatives();
            } else {
                alert(data.message || 'Error saving creative');
            }
        });

        loadCompanies();
    });
})();