(function () {
    "use strict";
    function initializeTaskBoard() {
        const board = document.getElementById("task-board-module");
        if (!board || board.dataset.initialized === "true") return;
        const newTaskList = board.querySelector('#task-list');
        const wipTaskList = board.querySelector('#wip-task-list');
        async function sendTaskAction(action, taskId, file = null) {
            const data = new FormData();
            data.append('taskboard_action', action);
            data.append('task_id', taskId);
            data.append('taskboard_csrf', board.dataset.csrfToken || '');
            if (file) data.append('start_file', file);
            const response = await fetch(board.dataset.apiUrl, {
                method: 'POST',
                body: data,
                credentials: 'same-origin'
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Task action failed.');
            return result;
        }
        board.addEventListener('click', async function (event) {
            const button = event.target.closest('[data-task-action]');
            if (!button || !board.contains(button)) return;
            const action = button.dataset.taskAction;
            const taskId = button.dataset.taskId;
            if (action === 'upload') {
                const item = button.closest('.task-list-item');
                item?.querySelector('[data-task-file-input]')?.click();
                return;
            }
            if (!taskId || button.disabled) return;
            button.disabled = true;
            try {
                const result = await sendTaskAction(action, taskId);
                if (action === 'start') {
                    const taskItem = newTaskList?.querySelector(`[data-task-id="${taskId}"]`);
                    if (taskItem && wipTaskList) {
                        newTaskList.querySelector('#task-empty-state')?.remove();
                        wipTaskList.querySelector('#wip-empty-state')?.remove();
                        taskItem.dataset.taskStatus = 'in_progress';
                        const actions = taskItem.querySelector('.task-actions');
                        if (actions) {
                            actions.innerHTML = `
                                <button type="button" class="task-action-btn task-upload-btn" data-task-action="upload" data-task-id="${taskId}" title="Add File" aria-label="Add File">
                                    <i class="fa-solid fa-plus"></i>
                                </button>
                                <input type="file" hidden data-task-file-input data-task-id="${taskId}" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp">
                                <button type="button" class="task-action-btn task-end-btn" data-task-action="end" data-task-id="${taskId}" title="End Task" aria-label="End Task">
                                    <i class="fa-solid fa-flag-checkered"></i>
                                </button>
                            `;
                        }
                        wipTaskList.prepend(taskItem);
                    }
                }
                if (action === 'end') {
                    const taskItem = board.querySelector(`.task-list-item[data-task-id="${taskId}"]`);
                    taskItem?.remove();
                    if (wipTaskList && !wipTaskList.querySelector('.task-list-item')) {
                        wipTaskList.innerHTML = `
                            <div class="task-empty-state" id="wip-empty-state">
                                <i class="fa-solid fa-check-circle"></i>
                                <p>No tasks in progress.</p>
                                <span>All tasks are completed or not started yet.</span>
                            </div>`;
                    }
                }
                if (typeof showToast === 'function') showToast(result.message);
            } catch (error) {
                if (typeof showToast === 'function') showToast(error.message);
                else alert(error.message);
            } finally {
                button.disabled = false;
            }
        });
        board.addEventListener('change', async function (event) {
            const fileInput = event.target.closest('[data-task-file-input]');
            if (!fileInput || !fileInput.files?.length) return;
            const file = fileInput.files[0];
            const taskId = fileInput.dataset.taskId;
            try {
                const result = await sendTaskAction('upload_start_file', taskId, file);
                const taskItem = fileInput.closest('.task-list-item');
                let fileLink = taskItem?.querySelector('.task-file-link');
                if (taskItem && !fileLink) {
                    fileLink = document.createElement('div');
                    fileLink.className = 'task-file-link';
                    taskItem.appendChild(fileLink);
                }
                if (fileLink) {
                    fileLink.replaceChildren();
                    const icon = document.createElement('i');
                    icon.className = 'fa-solid fa-paperclip';
                    const link = document.createElement('a');
                    link.href = result.start_file;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.textContent = 'View uploaded file';
                    fileLink.append(icon, link);
                }
                if (typeof showToast === 'function') showToast(result.message);
            } catch (error) {
                alert(error.message);
            } finally {
                fileInput.value = '';
            }
        });
        board.dataset.initialized = "true";
        // Modal & Form Logic
        const overlay = board.querySelector("#task-modal-overlay");
        const form = board.querySelector("#task-create-form");
        const openButton = board.querySelector("[data-open-task-modal]");
        const closeButtons = board.querySelectorAll("[data-close-task-modal]");
        const companySelect = board.querySelector("#task-company");
        const companyPreview = board.querySelector("#task-company-preview");
        const companyLogo = board.querySelector("#task-company-logo");
        const companyName = board.querySelector("#task-company-name");
        const submitButton = board.querySelector("#task-submit-button");
        const message = board.querySelector("#task-form-message");
        const toast = board.querySelector("#task-board-toast");
        let previousFocus = null, toastTimer = null;
        function showMessage(text, isError) {
            message.textContent = text;
            message.hidden = false;
            message.classList.toggle("is-error", Boolean(isError));
        }
        function showToast(text) {
            window.clearTimeout(toastTimer);
            toast.textContent = text;
            toast.hidden = false;
            toastTimer = window.setTimeout(() => { toast.hidden = true; }, 3500);
        }
        function openModal() {
            previousFocus = document.activeElement;
            overlay.hidden = false;
            document.body.classList.add("task-modal-open");
            message.hidden = true;
            window.setTimeout(() => companySelect.focus(), 50);
        }
        function closeModal() {
            overlay.hidden = true;
            document.body.classList.remove("task-modal-open");
            if (previousFocus && typeof previousFocus.focus === "function") previousFocus.focus();
        }
        function updateCompanyPreview() {
            const selected = companySelect.options[companySelect.selectedIndex];
            if (!selected || !selected.value) {
                companyPreview.hidden = true;
                companyLogo.removeAttribute("src");
                companyName.textContent = "";
                return;
            }
            companyName.textContent = selected.textContent.trim();
            const logoPath = selected.dataset.logo || "";
            if (!logoPath) {
                companyLogo.hidden = true;
                companyPreview.hidden = false;
                return;
            }
            const cleanPath = logoPath.replace(/^\/+/, "");
            companyLogo.onerror = () => { companyLogo.hidden = true; };
            companyLogo.onload = () => { companyLogo.hidden = false; };
            companyLogo.src = new URL(cleanPath, new URL(".", window.location.href)).href;
            companyPreview.hidden = false;
        }
        if (openButton) openButton.addEventListener("click", openModal);
        closeButtons.forEach(btn => btn.addEventListener("click", closeModal));
        overlay.addEventListener("click", e => { if (e.target === overlay) closeModal(); });
        document.addEventListener("keydown", e => { if (e.key === "Escape" && !overlay.hidden) closeModal(); });
        companySelect.addEventListener("change", updateCompanyPreview);
        function addTaskToList(task) {
            const taskList = board.querySelector("#task-list");
            if (!taskList) return;
            taskList.querySelector("#task-empty-state")?.remove();
            const article = document.createElement("article");
            article.className = "task-list-item";
            article.dataset.taskId = String(task.id);
            const top = document.createElement("div");
            top.className = "task-list-item-top";
            const details = document.createElement("div");
            details.className = "task-list-item-details";
            const title = document.createElement("h3");
            title.textContent = task.task_title;
            const company = document.createElement("span");
            company.className = "task-company-name";
            const selected = companySelect.options[companySelect.selectedIndex];
            company.textContent = selected ? selected.textContent.trim() : "";
            details.append(title, company);
            top.appendChild(details);
            if (Number(task.is_priority) === 1) {
                const priority = document.createElement("span");
                priority.className = "task-priority-badge";
                priority.textContent = "Priority";
                top.appendChild(priority);
            }
            const actions = document.createElement("div");
            actions.className = "task-actions";
            const startBtn = document.createElement("button");
            startBtn.type = "button";
            startBtn.className = "task-action-btn task-start-btn";
            startBtn.dataset.taskAction = "start";
            startBtn.dataset.taskId = String(task.id);
            startBtn.title = "Start Task";
            startBtn.innerHTML = '<i class="fa-solid fa-play"></i>';
            actions.appendChild(startBtn);
            top.appendChild(actions);
            const meta = document.createElement("div");
            meta.className = "task-list-meta";
            meta.innerHTML = `<span>${task.task_type}</span><span>${task.objective}</span>`;
            const footer = document.createElement("div");
            footer.className = "task-list-footer";
            footer.innerHTML = `<span>Created by ${task.created_by_name}</span><span>${new Date().toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" })}</span>`;
            article.append(top, meta, footer);
            taskList.prepend(article);
        }
        form.addEventListener("submit", async function (event) {
            event.preventDefault();
            if (!form.reportValidity()) return;
            const origText = submitButton.innerHTML;
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
            message.hidden = true;
            try {
                const formData = new FormData(form);
                const response = await fetch(board.dataset.apiUrl, {
                    method: "POST",
                    headers: { Accept: "application/json" },
                    body: formData
                });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || "Unable to create task.");
                closeModal();
                form.reset();
                updateCompanyPreview();
                if (result.task) addTaskToList(result.task);
                showToast(result.message || "Task created successfully!");
            } catch (error) {
                showMessage(error.message || "Something went wrong.", true);
            } finally {
                submitButton.disabled = false;
                submitButton.innerHTML = origText;
            }
        });
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initializeTaskBoard, { once: true });
    } else {
        initializeTaskBoard();
    }
})();