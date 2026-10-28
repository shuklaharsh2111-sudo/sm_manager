document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('addCompanyModal');
    const openButton = document.getElementById('openAddCompanyModal');
    const closeButton = document.getElementById('closeAddCompanyModal');
    const cancelButton = document.getElementById('cancelAddCompany');
    const form = document.getElementById('addCompanyForm');
    const companyName = document.getElementById('comp_name');
    const logoInput = document.getElementById('comp_logo');
    if (!modal || !form) {
        return;
    }
    // Start with the modal closed.
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    function openModal() {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (companyName) {
            companyName.focus();
        }
    }
    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
    if (openButton) {
        openButton.addEventListener('click', openModal);
    }
    if (closeButton) {
        closeButton.addEventListener('click', closeModal);
    }
    if (cancelButton) {
        cancelButton.addEventListener('click', closeModal);
    }
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (
            event.key === 'Escape' &&
            modal.classList.contains('is-open')
        ) {
            closeModal();
        }
    });
    /*
    |--------------------------------------------------------------------------
    | Reset dynamic SOP rows
    |--------------------------------------------------------------------------
    */
    function resetSopRows() {
        const types = ['daily', 'weekly', 'monthly'];
        types.forEach(function (type) {
            const container = document.getElementById(type + 'SopRows');
            if (!container) {
                return;
            }
            container.replaceChildren();
            const row = document.createElement('div');
            row.className = 'sop-row';
            const titleInput = document.createElement('input');
            titleInput.type = 'text';
            titleInput.name = `sop_title[${type}][]`;
            titleInput.placeholder = `Enter ${type} SOP`;
            titleInput.required = true;
            const dateInput = document.createElement('input');
            dateInput.type = 'date';
            dateInput.name = `sop_date[${type}][]`;
            dateInput.setAttribute('aria-label', `${type} SOP date`);
            dateInput.required = true;
            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'remove-sop-row';
            removeButton.textContent = '×';
            removeButton.setAttribute('aria-label', 'Remove SOP');
            row.append(titleInput, dateInput, removeButton);
            container.appendChild(row);
        });
    }
    /*
    |--------------------------------------------------------------------------
    | AJAX form submission
    |--------------------------------------------------------------------------
    */
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (companyName && companyName.value.trim() === '') {
            alert('Please enter the company name.');
            companyName.focus();
            return;
        }
        // Validate the selected logo on the client side.
        if (logoInput && logoInput.files.length > 0) {
            const file = logoInput.files[0];
            const maxSize = 5 * 1024 * 1024;
            const allowedExtensions = [
                'jpg',
                'jpeg',
                'png',
                'webp',
                'gif'
            ];
            const extension = file.name
                .split('.')
                .pop()
                .toLowerCase();
            if (!allowedExtensions.includes(extension)) {
                alert('Please select a JPG, PNG, WEBP or GIF logo.');
                logoInput.focus();
                return;
            }
            if (file.size > maxSize) {
                alert('Company logo must be 5 MB or smaller.');
                logoInput.focus();
                return;
            }
        }
        const submitButton = form.querySelector(
            'button[type="submit"]'
        );
        const originalButtonText = submitButton
            ? submitButton.textContent
            : 'Save Company';
        try {
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Saving...';
            }
            // Explicitly include add_company for PHP's POST check.
            const formData = new FormData(form);
            formData.set('add_company', '1');
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });
            let result;
            try {
                result = await response.json();
            } catch (parseError) {
                throw new Error(
                    'The server returned an invalid response. '
                    + 'Check the PHP error log and admin page wrapper.'
                );
            }
            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || 'Unable to save the company.'
                );
            }
            // Add the new company to the table without reloading.
            if (result.company) {
                addCompanyToTable(result.company);
            }
            // Refresh SME workload counts in the dropdown.
            updateSmeDropdown(result.company);
            // Reset the form and remove any extra SOP rows.
            form.reset();
            resetSopRows();
            // Close the modal only after a successful save.
            closeModal();
            // Show the success message.
            showSuccessMessage(
                result.message || 'Company added successfully.'
            );
        } catch (error) {
            alert(error.message || 'Something went wrong.');
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = originalButtonText;
            }
        }
    });
    /*
    |--------------------------------------------------------------------------
    | Success message
    |--------------------------------------------------------------------------
    */
    function showSuccessMessage(message) {
        let successAlert = document.getElementById('companyAjaxSuccess');
        if (!successAlert) {
            successAlert = document.createElement('div');
            successAlert.id = 'companyAjaxSuccess';
            successAlert.className = 'alert alert-success';
            successAlert.setAttribute('role', 'status');
            const page = document.querySelector('.companies-page');
            const header = page
                ? page.querySelector('.page-header')
                : null;
            if (header) {
                header.insertAdjacentElement('afterend', successAlert);
            } else if (page) {
                page.prepend(successAlert);
            }
        }
        if (successAlert) {
            successAlert.textContent = message;
        }
    }
    /*
    |--------------------------------------------------------------------------
    | Add company to the table
    |--------------------------------------------------------------------------
    */
    function addCompanyToTable(company) {
        const tbody = document.querySelector('.companies-table tbody');
        if (!tbody || !company) {
            return;
        }
        const noData = tbody.querySelector('.no-data');
        if (noData) {
            tbody.replaceChildren();
        }
        const row = document.createElement('tr');
        const idCell = document.createElement('td');
        idCell.className = 'company-id-cell';
        idCell.dataset.label = 'ID';
        idCell.textContent = '#' + (company.id ?? '');
        const companyInitial =
            (String(company.comp_name || '').trim().charAt(0) || '?')
                .toUpperCase();
        const logoCell = document.createElement('td');
        logoCell.dataset.label = 'Logo';
        if (company.comp_logo) {
            const image = document.createElement('img');
            let logoPath = String(company.comp_logo)
                .replace(/\\/g, '/')
                .replace(/^\/+/, '');
            if (!logoPath.startsWith('uploads/')) {
                logoPath = 'uploads/' + logoPath;
            }
            image.src = '../' + logoPath;
            image.alt = String(company.comp_name || 'Company') + ' logo';
            image.className = 'company-logo';
            image.onerror = function () {
                const fallback = document.createElement('span');
                fallback.className = 'no-logo';
                fallback.title = 'Logo unavailable';
                fallback.textContent = companyInitial;
                image.replaceWith(fallback);
            };
            logoCell.appendChild(image);
        } else {
            const fallback = document.createElement('span');
            fallback.className = 'no-logo';
            fallback.title = 'No logo uploaded';
            fallback.textContent = companyInitial;
            logoCell.appendChild(fallback);
        }
        const nameCell = document.createElement('td');
        nameCell.className = 'company-name-cell';
        nameCell.dataset.label = 'Company';
        const nameText = document.createElement('span');
        nameText.className = 'company-name';
        nameText.textContent = company.comp_name || '';
        nameCell.appendChild(nameText);
        const smeCell = document.createElement('td');
        smeCell.className = 'company-sme-cell';
        smeCell.dataset.label = 'Assigned SME';
        if (company.sme_name) {
            const assigned = document.createElement('span');
            assigned.className = 'assigned-sme-name';
            assigned.textContent = company.sme_name;
            smeCell.appendChild(assigned);
        } else {
            const unassigned = document.createElement('span');
            unassigned.className = 'unassigned-label';
            unassigned.textContent = 'Not Assigned';
            const assignButton = document.createElement('button');
            assignButton.type = 'button';
            assignButton.className = 'btn btn-primary assign-sme-button';
            assignButton.dataset.companyId = company.id;
            assignButton.dataset.companyName = company.comp_name || '';
            assignButton.textContent = 'Assign SME';
            assignButton.addEventListener('click', function () {
                openAssignSmeModal(
                    assignButton.dataset.companyId,
                    assignButton.dataset.companyName
                );
            });
            smeCell.append(unassigned, assignButton);
        }
        row.append(idCell, logoCell, nameCell, smeCell);
        // Show the latest company at the top.
        tbody.prepend(row);
        refreshCompanyStats();
        applyCompanySearch();
    }
    /*
    |--------------------------------------------------------------------------
    | Summary counts + search
    |--------------------------------------------------------------------------
    */
    function companyRows() {
        return Array.from(
            document.querySelectorAll('.companies-table tbody tr')
        ).filter(function (row) {
            return !row.querySelector('.no-data');
        });
    }
    function refreshCompanyStats() {
        const rows = companyRows();
        const assigned = rows.filter(function (row) {
            return row.querySelector('.assigned-sme-name');
        }).length;
        const total = document.getElementById('statTotal');
        const assignedEl = document.getElementById('statAssigned');
        const unassignedEl = document.getElementById('statUnassigned');
        if (total) total.textContent = String(rows.length);
        if (assignedEl) assignedEl.textContent = String(assigned);
        if (unassignedEl) {
            unassignedEl.textContent = String(rows.length - assigned);
        }
    }
    function applyCompanySearch() {
        const searchInput = document.getElementById('companySearch');
        const noResults = document.getElementById('companyNoResults');
        const term = searchInput
            ? searchInput.value.trim().toLowerCase()
            : '';
        const rows = companyRows();
        let visible = 0;
        rows.forEach(function (row) {
            const match = row.textContent.toLowerCase().indexOf(term) !== -1;
            row.hidden = !match;
            if (match) visible++;
        });
        if (noResults) {
            noResults.hidden = rows.length === 0 || visible !== 0;
        }
    }
    document.getElementById('companySearch')
        ?.addEventListener('input', applyCompanySearch);
    /*
    |--------------------------------------------------------------------------
    | Update SME assignment counts
    |--------------------------------------------------------------------------
    */
    function updateSmeDropdown(company) {
        const select = document.getElementById('sme_user_id');
        if (!select || !company || !company.sme_user_id) {
            return;
        }
        const selectedOption = select.querySelector(
            `option[value="${Number(company.sme_user_id)}"]`
        );
        if (!selectedOption) {
            return;
        }
        const match = selectedOption.textContent.match(/\((\d+)\/5 companies\)/);
        if (!match) {
            return;
        }
        const newCount = Number(match[1]) + 1;
        const employeeName = selectedOption.textContent
            .replace(/\s*\(\d+\/5 companies\)/, '')
            .trim();
        if (newCount >= 5) {
            selectedOption.remove();
        } else {
            selectedOption.textContent =
                `${employeeName} (${newCount}/5 companies)`;
        }
        select.value = '';
    }
    /*
    |--------------------------------------------------------------------------
    | Add Daily / Weekly / Monthly SOP rows
    |--------------------------------------------------------------------------
    */
    document.querySelectorAll('.add-sop-row').forEach(function (button) {
        button.addEventListener('click', function () {
            const type = button.dataset.type;
            const container = document.getElementById(type + 'SopRows');
            if (!container) {
                return;
            }
            const row = document.createElement('div');
            row.className = 'sop-row';
            const titleInput = document.createElement('input');
            titleInput.type = 'text';
            titleInput.name = `sop_title[${type}][]`;
            titleInput.placeholder = `Enter ${type} SOP`;
            titleInput.required = true;
            const dateInput = document.createElement('input');
            dateInput.type = 'date';
            dateInput.name = `sop_date[${type}][]`;
            dateInput.setAttribute('aria-label', `${type} SOP date`);
            dateInput.required = true;
            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'remove-sop-row';
            removeButton.textContent = '×';
            removeButton.setAttribute('aria-label', 'Remove SOP');
            row.append(titleInput, dateInput, removeButton);
            container.appendChild(row);
            titleInput.focus();
        });
    });
    /*
    |--------------------------------------------------------------------------
    | Remove SOP rows
    |--------------------------------------------------------------------------
    */
    document.addEventListener('click', function (event) {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const removeButton = target.closest('.remove-sop-row');
        if (!removeButton) {
            return;
        }
        const row = removeButton.closest('.sop-row');
        const container = row ? row.parentElement : null;
        if (!row || !container) {
            return;
        }
        // Keep one empty row in each SOP section.
        if (container.querySelectorAll('.sop-row').length === 1) {
            const title = row.querySelector('input[type="text"]');
            const date = row.querySelector('input[type="date"]');
            if (title) {
                title.value = '';
            }
            if (date) {
                date.value = '';
            }
            return;
        }
        row.remove();
    });
    /*
|--------------------------------------------------------------------------
| Assign SME Modal
|--------------------------------------------------------------------------
*/
    const assignSmeModal = document.getElementById('assignSmeModal');
    const assignSmeForm = document.getElementById('assignSmeForm');
    const assignSmeCompanyId = document.getElementById('assignSmeCompanyId');
    const assignSmeCompanyName = document.getElementById('assignSmeCompanyName');
    const assignSmeEmployee = document.getElementById('assignSmeEmployee');
    const confirmAssignSme = document.getElementById('confirmAssignSme');
    function openAssignSmeModal(companyId, companyName) {
        if (!assignSmeModal) return;
        assignSmeCompanyId.value = companyId;
        assignSmeCompanyName.textContent = 'Company: ' + companyName;
        assignSmeModal.classList.add('is-open');
        assignSmeModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
    function closeAssignSmeModal() {
        if (!assignSmeModal) return;
        assignSmeModal.classList.remove('is-open');
        assignSmeModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
    document.querySelectorAll('.assign-sme-button').forEach(function (button) {
        button.addEventListener('click', function () {
            openAssignSmeModal(
                button.dataset.companyId,
                button.dataset.companyName
            );
        });
    });
    document.getElementById('closeAssignSmeModal')
        ?.addEventListener('click', closeAssignSmeModal);
    document.getElementById('cancelAssignSme')
        ?.addEventListener('click', closeAssignSmeModal);
    assignSmeModal?.addEventListener('click', function (event) {
        if (event.target === assignSmeModal) {
            closeAssignSmeModal();
        }
    });
    assignSmeForm?.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!assignSmeEmployee.value) {
            alert('Please select an SME employee.');
            return;
        }
        const originalText = confirmAssignSme.textContent;
        confirmAssignSme.disabled = true;
        confirmAssignSme.textContent = 'Assigning...';
        try {
            const formData = new FormData(assignSmeForm);
            formData.set('assign_sme', '1');
            const response = await fetch(assignSmeForm.action || window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to assign SME.');
            }
            const company = result.company;
            const row = document.querySelector(
                '.assign-sme-button[data-company-id="' +
                Number(company.id) + '"]'
            )?.closest('tr');
            if (row) {
                const smeCell = row.querySelector('.company-sme-cell');
                if (smeCell) {
                    smeCell.replaceChildren();
                    const name = document.createElement('span');
                    name.className = 'assigned-sme-name';
                    name.textContent = company.sme_name || 'SME';
                    smeCell.appendChild(name);
                }
            }
            refreshCompanyStats();
            applyCompanySearch();
            // Update the SME workload in both dropdowns.
            updateSmeDropdown(company);
            // Remove the employee if they have reached the five-company limit.
            const selectedOption = assignSmeEmployee.querySelector(
                'option[value="' + Number(company.sme_user_id) + '"]'
            );
            if (
                selectedOption &&
                Number(result.sme_assigned_count) >= 5
            ) {
                selectedOption.remove();
            } else if (selectedOption) {
                const count = Number(result.sme_assigned_count);
                const employeeName = selectedOption.textContent
                    .replace(/\s*\(\d+\/5 companies\)/, '')
                    .trim();
                selectedOption.textContent =
                    employeeName + ' (' + count + '/5 companies)';
            }
            closeAssignSmeModal();
            assignSmeForm.reset();
            showSuccessMessage(result.message || 'SME assigned successfully.');
        } catch (error) {
            alert(error.message || 'Something went wrong.');
        } finally {
            confirmAssignSme.disabled = false;
            confirmAssignSme.textContent = originalText;
        }
    });
});