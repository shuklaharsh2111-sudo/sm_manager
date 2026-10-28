document.addEventListener('DOMContentLoaded', function () {
    /*
    |--------------------------------------------------------------------------
    | SHARED MODAL HELPERS
    |--------------------------------------------------------------------------
    */
    let previousFocus = null;
    function openModal(modal) {
        if (!modal) return;
        previousFocus = document.activeElement;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
    }
    function closeModal(modal) {
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        const anotherModalOpen = Array.from(
            document.querySelectorAll('.modal')
        ).some(function (item) {
            return !item.hidden;
        });
        if (!anotherModalOpen) {
            document.body.classList.remove('modal-open');
        }
        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }
    }
    /*
    |--------------------------------------------------------------------------
    | ADD USER MODAL
    |--------------------------------------------------------------------------
    */
    const addModal = document.getElementById('addUserModal');
    const openAddButton = document.getElementById('openAddUserModal');
    const closeAddButton = document.getElementById('closeAddUserModal');
    const cancelAddButton = document.getElementById('cancelAddUser');
    const addForm = document.getElementById('addUserForm');
    const addFormError = document.getElementById('usersFormError');
    const saveUserButton = document.getElementById('saveUserButton');
    function showAddError(message) {
        if (!addFormError) return;
        addFormError.textContent = message;
        addFormError.hidden = false;
    }
    function clearAddError() {
        if (!addFormError) return;
        addFormError.textContent = '';
        addFormError.hidden = true;
    }
    if (addModal && openAddButton && addForm) {
        openAddButton.addEventListener('click', function () {
            clearAddError();
            openModal(addModal);
            const firstInput = addForm.querySelector(
                'input:not([type="hidden"])'
            );
            if (firstInput) firstInput.focus();
        });
        if (closeAddButton) {
            closeAddButton.addEventListener('click', function () {
                closeModal(addModal);
            });
        }
        if (cancelAddButton) {
            cancelAddButton.addEventListener('click', function () {
                closeModal(addModal);
            });
        }
        addModal.addEventListener('click', function (event) {
            if (event.target === addModal) {
                closeModal(addModal);
            }
        });
    }
    if (addForm) {
        const imageInputs = [
            document.getElementById('brand_logo'),
            document.getElementById('prof_image')
        ].filter(Boolean);
        imageInputs.forEach(function (input) {
            input.addEventListener('change', function () {
                clearAddError();
                const file = input.files && input.files[0];
                if (!file) return;
                const allowedTypes = [
                    'image/jpeg',
                    'image/png',
                    'image/webp',
                    'image/gif'
                ];
                if (!allowedTypes.includes(file.type)) {
                    input.value = '';
                    showAddError(
                        'Select a JPG, PNG, WEBP or GIF image.'
                    );
                    return;
                }
                if (file.size > 5 * 1024 * 1024) {
                    input.value = '';
                    showAddError('Image size must not exceed 5 MB.');
                }
            });
        });
        addForm.addEventListener('submit', function (event) {
            clearAddError();
            const requiredInputs = [
                document.getElementById('brand_name'),
                document.getElementById('fullname'),
                document.getElementById('contact'),
                document.getElementById('email'),
                document.getElementById('password')
            ];
            for (const input of requiredInputs) {
                if (!input || !input.value.trim()) {
                    event.preventDefault();
                    showAddError('Please complete all required fields.');
                    if (input) input.focus();
                    return;
                }
            }
            const password = document.getElementById('password');
            if (password.value.length < 8) {
                event.preventDefault();
                showAddError(
                    'Password must contain at least 8 characters.'
                );
                password.focus();
                return;
            }
            const contact = document.getElementById('contact');
            if (!/^[0-9+\-\s()]{7,13}$/.test(contact.value.trim())) {
                event.preventDefault();
                showAddError('Please enter a valid contact number.');
                contact.focus();
                return;
            }
            const selectedRoles = addForm.querySelectorAll(
                'input[name="role[]"]:checked'
            );
            if (selectedRoles.length === 0) {
                event.preventDefault();
                showAddError('Please select at least one role.');
                return;
            }
            const email = document.getElementById('email');
            if (!email.checkValidity()) {
                event.preventDefault();
                showAddError('Please enter a valid email address.');
                email.focus();
                return;
            }
            if (saveUserButton) {
                saveUserButton.disabled = true;
                saveUserButton.textContent = 'Saving...';
            }
        });
    }
    /*
    |--------------------------------------------------------------------------
    | ASSIGN COMPANY MODAL
    |--------------------------------------------------------------------------
    */
    const assignModal = document.getElementById('assignCompanyModal');
    const assignForm = document.getElementById('assignCompanyForm');
    const closeAssignButton = document.getElementById(
        'closeAssignCompanyModal'
    );
    const cancelAssignButton = document.getElementById(
        'cancelAssignCompany'
    );
    const smeUserIdInput = document.getElementById('assignSmeUserId');
    const smeNameElement = document.getElementById('assignSmeName');
    const companyCountElement = document.getElementById(
        'assignedCompanyCount'
    );
    const assignError = document.getElementById('assignCompanyError');
    const saveAssignmentsButton = document.getElementById(
        'saveCompanyAssignments'
    );
    const assignButtons = document.querySelectorAll(
        '.assign-company-button'
    );
    function getCompanyCheckboxes() {
        if (!assignForm) return [];
        return Array.from(
            assignForm.querySelectorAll(
                'input[name="company_ids[]"]'
            )
        );
    }
    function showAssignError(message) {
        if (!assignError) return;
        assignError.textContent = message;
        assignError.hidden = false;
    }
    function clearAssignError() {
        if (!assignError) return;
        assignError.textContent = '';
        assignError.hidden = true;
    }
    /* ---- Company dropdown (multi-select) ---- */
    const companyDropdown = document.getElementById('companyDropdown');
    const companyToggle = document.getElementById('companyDropdownToggle');
    const companyPanel = document.getElementById('companyDropdownPanel');
    const companyLabel = document.getElementById('companyDropdownLabel');
    const companySearch = document.getElementById('companySearch');
    const companyChips = document.getElementById('companyChips');
    const companyNoMatch = document.getElementById('companyNoMatch');
    function setCompanyDropdownOpen(open) {
        if (!companyDropdown || !companyPanel || !companyToggle) return;
        companyPanel.hidden = !open;
        companyDropdown.classList.toggle('is-open', open);
        companyToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open && companySearch) {
            companySearch.focus();
        }
    }
    function filterCompanyOptions() {
        if (!companySearch) return;
        const term = companySearch.value.trim().toLowerCase();
        let visible = 0;
        getCompanyCheckboxes().forEach(function (checkbox) {
            const row = checkbox.closest('.assign-company-option');
            if (!row) return;
            const match = row.textContent.toLowerCase().indexOf(term) !== -1;
            row.hidden = !match;
            if (match) visible++;
        });
        if (companyNoMatch) {
            companyNoMatch.hidden = visible !== 0;
        }
    }
    function refreshCompanyDropdown() {
        if (!companyLabel) return;
        const selected = getCompanyCheckboxes().filter(function (checkbox) {
            return checkbox.checked;
        });
        const names = selected.map(function (checkbox) {
            const nameEl = checkbox.parentElement.querySelector(
                '.assign-company-name'
            );
            return nameEl ? nameEl.textContent.trim() : '';
        });
        if (selected.length === 0) {
            companyLabel.textContent = 'Select companies';
            companyLabel.classList.add('is-placeholder');
        } else {
            companyLabel.textContent = selected.length + ' selected';
            companyLabel.classList.remove('is-placeholder');
        }
        if (companyChips) {
            companyChips.textContent = '';
            selected.forEach(function (checkbox, index) {
                const chip = document.createElement('span');
                chip.className = 'company-chip';
                const text = document.createElement('span');
                text.textContent = names[index];
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'company-chip-remove';
                remove.setAttribute('aria-label', 'Remove ' + names[index]);
                remove.innerHTML = '&times;';
                remove.addEventListener('click', function () {
                    checkbox.checked = false;
                    checkbox.dispatchEvent(
                        new Event('change', { bubbles: true })
                    );
                });
                chip.appendChild(text);
                chip.appendChild(remove);
                companyChips.appendChild(chip);
            });
        }
    }
    if (companyToggle) {
        companyToggle.addEventListener('click', function () {
            setCompanyDropdownOpen(companyPanel.hidden);
        });
    }
    if (companySearch) {
        companySearch.addEventListener('input', filterCompanyOptions);
        companySearch.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') event.preventDefault();
        });
    }
    if (companyDropdown) {
        document.addEventListener('click', function (event) {
            if (
                companyPanel &&
                !companyPanel.hidden &&
                !companyDropdown.contains(event.target)
            ) {
                setCompanyDropdownOpen(false);
            }
        });
        companyDropdown.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !companyPanel.hidden) {
                event.stopPropagation();
                setCompanyDropdownOpen(false);
                companyToggle.focus();
            }
        });
    }
    function updateCompanyCount() {
        const checkboxes = getCompanyCheckboxes();
        const selectedCount = checkboxes.filter(function (checkbox) {
            return checkbox.checked;
        }).length;
        if (companyCountElement) {
            companyCountElement.textContent = String(selectedCount);
        }
        checkboxes.forEach(function (checkbox) {
            const ownerId = checkbox.dataset.ownerId || '';
            const currentSmeId = smeUserIdInput
                ? smeUserIdInput.value
                : '';
            const ownedByAnotherSme =
                ownerId !== '' &&
                ownerId !== currentSmeId;
            checkbox.disabled =
                ownedByAnotherSme ||
                (!checkbox.checked && selectedCount >= 5);
            const row = checkbox.closest('.assign-company-option');
            if (row) {
                row.classList.toggle('is-disabled', checkbox.disabled);
                row.classList.toggle('is-selected', checkbox.checked);
            }
        });
        refreshCompanyDropdown();
    }
    assignButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            if (!assignModal || !assignForm) return;
            clearAssignError();
            const currentSmeId = button.dataset.userId || '';
            const currentSmeName = button.dataset.userName || '';
            let assignedIds = [];
            try {
                assignedIds = JSON.parse(
                    button.dataset.assignedCompanyIds || '[]'
                );
            } catch (error) {
                assignedIds = [];
            }
            smeUserIdInput.value = currentSmeId;
            smeNameElement.textContent = currentSmeName;
            getCompanyCheckboxes().forEach(function (checkbox) {
                const ownerId = checkbox.dataset.ownerId || '';
                checkbox.checked = assignedIds
                    .map(Number)
                    .includes(Number(checkbox.value));
                checkbox.disabled =
                    ownerId !== '' &&
                    ownerId !== currentSmeId;
            });
            if (companySearch) companySearch.value = '';
            filterCompanyOptions();
            setCompanyDropdownOpen(false);
            updateCompanyCount();
            openModal(assignModal);
            if (companyToggle) companyToggle.focus();
        });
    });
    if (assignModal && assignForm) {
        if (closeAssignButton) {
            closeAssignButton.addEventListener('click', function () {
                closeModal(assignModal);
            });
        }
        if (cancelAssignButton) {
            cancelAssignButton.addEventListener('click', function () {
                closeModal(assignModal);
            });
        }
        assignModal.addEventListener('click', function (event) {
            if (event.target === assignModal) {
                closeModal(assignModal);
            }
        });
        getCompanyCheckboxes().forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                clearAssignError();
                const selectedCount = getCompanyCheckboxes().filter(
                    function (item) {
                        return item.checked;
                    }
                ).length;
                if (selectedCount > 5) {
                    checkbox.checked = false;
                    showAssignError(
                        'You can assign a maximum of 5 companies.'
                    );
                }
                updateCompanyCount();
            });
        });
        assignForm.addEventListener('submit', function (event) {
            clearAssignError();
            const selectedCount = getCompanyCheckboxes().filter(
                function (checkbox) {
                    return checkbox.checked;
                }
            ).length;
            if (selectedCount > 5) {
                event.preventDefault();
                showAssignError(
                    'You can assign a maximum of 5 companies.'
                );
                return;
            }
            if (!smeUserIdInput.value) {
                event.preventDefault();
                showAssignError('Please select a valid SME.');
                return;
            }
            if (saveAssignmentsButton) {
                saveAssignmentsButton.disabled = true;
                saveAssignmentsButton.textContent = 'Saving...';
            }
        });
    }
    /*
    |--------------------------------------------------------------------------
    | MANAGE SOPs MODAL
    |--------------------------------------------------------------------------
    */
    const manageSopsModal = document.getElementById('manageSopsModal');
    const manageSopForm = document.getElementById('manageSopForm');
    const closeManageSopsButton = document.getElementById(
        'closeManageSopsModal'
    );
    const sopSmeUserId = document.getElementById('sopSmeUserId');
    const sopSmeName = document.getElementById('sopSmeName');
    const sopIdInput = document.getElementById('sopId');
    const sopCompany = document.getElementById('sopCompany');
    const sopType = document.getElementById('sopType');
    const sopTitle = document.getElementById('sopTitle');
    const sopDescription = document.getElementById('sopDescription');
    const sopDate = document.getElementById('sopDate');
    const sopError = document.getElementById('manageSopError');
    const saveSopButton = document.getElementById('saveSopButton');
    const resetSopFormButton = document.getElementById('resetSopForm');
    const existingSopsList = document.getElementById('existingSopsList');
    const manageSopButtons = document.querySelectorAll(
        '.manage-sops-button'
    );
    let currentSmeSops = [];
    let currentAssignedCompanyIds = [];
    function showSopError(message) {
        if (!sopError) return;
        sopError.textContent = message;
        sopError.hidden = false;
    }
    function clearSopError() {
        if (!sopError) return;
        sopError.textContent = '';
        sopError.hidden = true;
    }
    function resetSopForm() {
        if (!manageSopForm) return;
        manageSopForm.reset();
        sopIdInput.value = '0';
        sopType.value = 'daily';
        if (saveSopButton) {
            saveSopButton.disabled = false;
            saveSopButton.textContent = 'Add SOP';
        }
        clearSopError();
    }
    function updateSopCompanyOptions() {
        if (!sopCompany) return;
        Array.from(sopCompany.options).forEach(function (option) {
            if (option.value === '') {
                option.disabled = false;
                return;
            }
            option.disabled = !currentAssignedCompanyIds.includes(
                Number(option.value)
            );
        });
    }
    function renderExistingSops() {
        if (!existingSopsList) return;
        existingSopsList.replaceChildren();
        const visibleSops = currentSmeSops.filter(function (sop) {
            return currentAssignedCompanyIds.includes(
                Number(sop.company_id)
            );
        });
        if (visibleSops.length === 0) {
            const emptyMessage = document.createElement('p');
            emptyMessage.textContent =
                'No SOPs assigned to this SME yet.';
            existingSopsList.appendChild(emptyMessage);
            return;
        }
        visibleSops.forEach(function (sop) {
            const card = document.createElement('div');
            card.className = 'existing-sop-item';
            const title = document.createElement('h4');
            title.textContent = sop.sop_title || 'Untitled SOP';
            const details = document.createElement('p');
            details.textContent =
                (sop.sop_type || '').toUpperCase() +
                ' | ' +
                (sop.comp_name || 'Company') +
                ' | ' +
                (sop.sop_date || '');
            const editButton = document.createElement('button');
            editButton.type = 'button';
            editButton.textContent = 'Edit SOP';
            editButton.addEventListener('click', function () {
                resetSopForm();
                sopIdInput.value = String(sop.id);
                sopCompany.value = String(sop.company_id);
                sopType.value = sop.sop_type || 'daily';
                sopTitle.value = sop.sop_title || '';
                sopDescription.value = sop.sop_description || '';
                sopDate.value = sop.sop_date || '';
                if (saveSopButton) {
                    saveSopButton.textContent = 'Update SOP';
                }
                sopTitle.focus();
            });
            card.appendChild(title);
            card.appendChild(details);
            if (sop.sop_description) {
                const description = document.createElement('p');
                description.textContent = sop.sop_description;
                card.appendChild(description);
            }
            card.appendChild(editButton);
            existingSopsList.appendChild(card);
        });
    }
    manageSopButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            if (!manageSopsModal || !manageSopForm) return;
            resetSopForm();
            sopSmeUserId.value = button.dataset.userId || '';
            sopSmeName.textContent = button.dataset.userName || '';
            try {
                currentAssignedCompanyIds = JSON.parse(
                    button.dataset.assignedCompanyIds || '[]'
                ).map(Number);
                currentSmeSops = JSON.parse(
                    button.dataset.sops || '[]'
                );
            } catch (error) {
                currentAssignedCompanyIds = [];
                currentSmeSops = [];
            }
            updateSopCompanyOptions();
            renderExistingSops();
            if (currentAssignedCompanyIds.length === 0) {
                showSopError(
                    'Assign at least one company to this SME first.'
                );
            }
            openModal(manageSopsModal);
        });
    });
    if (closeManageSopsButton) {
        closeManageSopsButton.addEventListener('click', function () {
            closeModal(manageSopsModal);
        });
    }
    if (manageSopsModal) {
        manageSopsModal.addEventListener('click', function (event) {
            if (event.target === manageSopsModal) {
                closeModal(manageSopsModal);
            }
        });
    }
    if (resetSopFormButton) {
        resetSopFormButton.addEventListener('click', function () {
            resetSopForm();
        });
    }
    if (manageSopForm) {
        manageSopForm.addEventListener('submit', function (event) {
            clearSopError();
            if (!sopSmeUserId.value) {
                event.preventDefault();
                showSopError('Please select a valid SME.');
                return;
            }
            if (currentAssignedCompanyIds.length === 0) {
                event.preventDefault();
                showSopError(
                    'Assign a company to this SME before adding an SOP.'
                );
                return;
            }
            if (
                !currentAssignedCompanyIds.includes(
                    Number(sopCompany.value)
                )
            ) {
                event.preventDefault();
                showSopError(
                    'Select a company assigned to this SME.'
                );
                return;
            }
            if (!sopTitle.value.trim()) {
                event.preventDefault();
                showSopError('SOP title is required.');
                sopTitle.focus();
                return;
            }
            if (!sopDate.value) {
                event.preventDefault();
                showSopError('Please select an SOP date.');
                sopDate.focus();
                return;
            }
            if (saveSopButton) {
                saveSopButton.disabled = true;
                saveSopButton.textContent = 'Saving...';
            }
        });
    }
    /*
    |--------------------------------------------------------------------------
    | CLOSE MODALS WITH ESCAPE
    |--------------------------------------------------------------------------
    */
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (manageSopsModal && !manageSopsModal.hidden) {
            closeModal(manageSopsModal);
        } else if (assignModal && !assignModal.hidden) {
            closeModal(assignModal);
        } else if (addModal && !addModal.hidden) {
            closeModal(addModal);
        }
    });
});
