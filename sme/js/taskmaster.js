/* ==========================================
   TASK BOARD - COMPLETE JAVASCRIPT
========================================== */
(function () {
    "use strict";
    function initializeTaskBoard() {
        const board = document.getElementById("task-board-module");
        if (!board || board.dataset.initialized === "true") {
            return;
        }
        board.dataset.initialized = "true";
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
        let previousFocus = null;
        let toastTimer = null;
        function showMessage(text, isError) {
            message.textContent = text;
            message.hidden = false;
            message.classList.toggle("is-error", Boolean(isError));
            message.classList.toggle("is-success", !isError);
        }
        function showToast(text) {
            window.clearTimeout(toastTimer);
            toast.textContent = text;
            toast.hidden = false;
            toastTimer = window.setTimeout(function () {
                toast.hidden = true;
            }, 3500);
        }
        function openModal() {
            previousFocus = document.activeElement;
            overlay.hidden = false;
            document.body.classList.add("task-modal-open");
            message.hidden = true;
            window.setTimeout(function () {
                companySelect.focus();
            }, 50);
        }
        function closeModal() {
            overlay.hidden = true;
            document.body.classList.remove("task-modal-open");
            if (previousFocus && typeof previousFocus.focus === "function") {
                previousFocus.focus();
            }
        }
        function updateCompanyPreview() {
            const selectedOption =
                companySelect.options[companySelect.selectedIndex];
            if (!selectedOption || !selectedOption.value) {
                companyPreview.hidden = true;
                companyLogo.removeAttribute("src");
                companyName.textContent = "";
                return;
            }
            companyName.textContent = selectedOption.textContent.trim();
            const logoPath = selectedOption.dataset.logo || "";
            if (!logoPath) {
                companyLogo.removeAttribute("src");
                companyLogo.hidden = true;
                companyPreview.hidden = false;
                return;
            }
            /*
             * For an app installed in /usp, store logo paths such as:
             * uploads/companies/logo.png
             *
             * If your database stores complete URLs, this logic should
             * be adjusted to validate and use those URLs.
             */
            const projectBase = new URL(".", window.location.href);
            const cleanPath = logoPath.replace(/^\/+/, "");
            companyLogo.onerror = function () {
                companyLogo.hidden = true;
            };
            companyLogo.onload = function () {
                companyLogo.hidden = false;
            };
            companyLogo.src = new URL(cleanPath, projectBase).href;
            companyPreview.hidden = false;
        }
        if (openButton) {
            openButton.addEventListener("click", openModal);
        }
        closeButtons.forEach(function (button) {
            button.addEventListener("click", closeModal);
        });
        overlay.addEventListener("click", function (event) {
            if (event.target === overlay) {
                closeModal();
            }
        });
        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && !overlay.hidden) {
                closeModal();
            }
        });
        companySelect.addEventListener("change", updateCompanyPreview);
        function addTaskToList(task) {
            const taskList = board.querySelector("#task-list");

            if (!taskList) {
                return;
            }

            const emptyState = taskList.querySelector("#task-empty-state");

            if (emptyState) {
                emptyState.remove();
            }

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

            const selectedOption = companySelect.options[
                companySelect.selectedIndex
            ];

            company.textContent = selectedOption
                ? selectedOption.textContent.trim()
                : "";

            details.append(title, company);
            top.appendChild(details);

            if (Number(task.is_priority) === 1) {
                const priority = document.createElement("span");
                priority.className = "task-priority-badge";
                priority.textContent = "Priority";
                top.appendChild(priority);
            }

            const meta = document.createElement("div");
            meta.className = "task-list-meta";

            const type = document.createElement("span");
            type.textContent = task.task_type;

            const objective = document.createElement("span");
            objective.textContent = task.objective;

            meta.append(type, objective);

            const footer = document.createElement("div");
            footer.className = "task-list-footer";

            const creator = document.createElement("span");
            creator.textContent = "Created by " + task.created_by_name;

            const date = document.createElement("span");
            date.textContent = new Date().toLocaleDateString("en-GB", {
                day: "2-digit",
                month: "short",
                year: "numeric"
            });

            footer.append(creator, date);

            article.append(top, meta, footer);

            taskList.prepend(article);
        }
        form.addEventListener("submit", async function (event) {
            event.preventDefault();
            if (!form.reportValidity()) {
                return;
            }
            const originalButton = submitButton.innerHTML;
            submitButton.disabled = true;
            submitButton.innerHTML =
                '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
            message.hidden = true;
            try {
                const formData = new FormData(form);
                // Use the current PHP page directly.
                const response = await fetch(board.dataset.apiUrl, {
                    method: "POST",
                    credentials: "same-origin",
                    headers: {
                        Accept: "application/json"
                    },
                    body: formData
                });
                const responseText = await response.text();
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch {
                    console.error("Task Board response:", responseText);
                    throw new Error(
                        "Server response: " + responseText.substring(0, 250)
                    );
                }
                if (!response.ok || !result.success) {
                    throw new Error(
                        result.message || "Unable to create task."
                    );
                }
                closeModal();
                form.reset();
                updateCompanyPreview();

                if (result.task) {
                    addTaskToList(result.task);
                }

                showToast(result.message || "Task created successfully!");
            } catch (error) {
                console.error("Task Board error:", error);
                showMessage(
                    error.message || "Something went wrong. Try again.",
                    true
                );
            } finally {
                submitButton.disabled = false;
                submitButton.innerHTML = originalButton;
            }
        });
    }
    if (document.readyState === "loading") {
        document.addEventListener(
            "DOMContentLoaded",
            initializeTaskBoard,
            { once: true }
        );
    } else {
        initializeTaskBoard();
    }
})();
