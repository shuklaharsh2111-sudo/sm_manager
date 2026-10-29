<?php
// Task Board - Included Component
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.3.1/css/all.css" integrity="sha512-x9WwyMYBnlXMNQ6kQ/Lyzu1NqIhLQKL5Oq6xByfXuRj7s9CskyCbLv/1IjqzJmXwFXWr0ov6jBV7Qbc0hh9nHg==" crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" type="text/css" href="css/taskmaster.css">
<section class="task-board-module" id="task-board-module">
    <!-- Header -->
    <div class="task-board-header">
        <div class="task-board-heading">
            <span class="task-board-eyebrow">WORKSPACE MANAGEMENT</span>
            <h1>Task Board</h1>
            <p>Organize your tasks and keep your workflow moving.</p>
        </div>
        <div class="task-board-header-icon">
            <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
        </div>
    </div>
    <!-- Task Categories -->
    <div class="task-board-grid">
        <!-- New Task -->
        <article class="task-category-card new-task-card">
            <div class="task-card-top">
                <div class="task-card-icon">
                    <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                </div>
                <span class="task-card-number">01</span>
            </div>
            <div class="task-card-content">
                <h2>New Task</h2>
                <p>Manage newly created tasks and upcoming work.</p>
            </div>
            <div class="task-card-footer">
                <span class="task-card-status">
                    <span class="status-dot"></span>
                    Ready to start
                </span>
                <button
                    type="button"
                    class="task-add-button"
                    data-task-type="New Task"
                    aria-label="Add new task"
                    title="Add New Task"
                >
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                </button>
            </div>
        </article>
        <!-- Work in Progress -->
        <article class="task-category-card wip-task-card">
            <div class="task-card-top">
                <div class="task-card-icon">
                    <i class="fa-solid fa-spinner" aria-hidden="true"></i>
                </div>
                <span class="task-card-number">02</span>
            </div>
            <div class="task-card-content">
                <h2>Work in Progress <span>(WIP)</span></h2>
                <p>Keep track of tasks currently being worked on.</p>
            </div>
            <div class="task-card-footer">
                <span class="task-card-status">
                    <span class="status-dot"></span>
                    In progress
                </span>
                <button
                    type="button"
                    class="task-add-button"
                    data-task-type="Work in Progress (WIP)"
                    aria-label="Add work in progress task"
                    title="Add WIP Task"
                >
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                </button>
            </div>
        </article>
        <!-- Recurring Task -->
        <article class="task-category-card recurring-task-card">
            <div class="task-card-top">
                <div class="task-card-icon">
                    <i class="fa-solid fa-repeat" aria-hidden="true"></i>
                </div>
                <span class="task-card-number">03</span>
            </div>
            <div class="task-card-content">
                <h2>Recurring Task</h2>
                <p>Organize repetitive tasks and regular activities.</p>
            </div>
            <div class="task-card-footer">
                <span class="task-card-status">
                    <span class="status-dot"></span>
                    Scheduled work
                </span>
                <button
                    type="button"
                    class="task-add-button"
                    data-task-type="Recurring Task"
                    aria-label="Add recurring task"
                    title="Add Recurring Task"
                >
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                </button>
            </div>
        </article>
    </div>
    <!-- Temporary Feedback -->
    <div
        class="task-board-toast"
        id="task-board-toast"
        role="status"
        aria-live="polite"
        hidden
    ></div>
</section>
<!-- Component Assets -->
<script src="js/taskmaster.js" defer></script>
