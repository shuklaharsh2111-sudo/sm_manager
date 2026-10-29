
/* ==========================================
   TASK BOARD - JAVASCRIPT
========================================== */

(function () {
    "use strict";

    function initializeTaskBoard() {
        const board = document.getElementById("task-board-module");

        if (!board || board.dataset.initialized === "true") {
            return;
        }

        board.dataset.initialized = "true";

        const addButtons = board.querySelectorAll(".task-add-button");
        const toast = board.querySelector("#task-board-toast");

        let toastTimeout;

        addButtons.forEach(function (button) {
            button.addEventListener("click", function () {
                const taskType = button.dataset.taskType;

                if (!taskType || !toast) {
                    return;
                }

                window.clearTimeout(toastTimeout);

                toast.textContent =
                    taskType + " selected. The task creation form will be added here.";

                toast.hidden = false;

                toastTimeout = window.setTimeout(function () {
                    toast.hidden = true;
                }, 3000);
            });
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
