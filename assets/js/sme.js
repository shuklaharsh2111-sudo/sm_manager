document.addEventListener("DOMContentLoaded", function () {
    const sidebar = document.getElementById("sidebar");
    const toggle = document.getElementById("sidebarToggle");
    const backdrop = document.getElementById("sidebarBackdrop");
    if (!sidebar || !toggle || !backdrop) return;
    function openSidebar() {
        sidebar.classList.add("is-open");
        backdrop.classList.add("is-visible");
        toggle.setAttribute("aria-expanded", "true");
        document.body.classList.add("sidebar-open");
    }
    function closeSidebar() {
        sidebar.classList.remove("is-open");
        backdrop.classList.remove("is-visible");
        toggle.setAttribute("aria-expanded", "false");
        document.body.classList.remove("sidebar-open");
    }
    toggle.addEventListener("click", function () {
        if (sidebar.classList.contains("is-open")) closeSidebar();
        else openSidebar();
    });
    backdrop.addEventListener("click", closeSidebar);
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") closeSidebar();
    });
    sidebar.querySelectorAll(".nav-link").forEach(function (link) {
        link.addEventListener("click", closeSidebar);
    });
    window.addEventListener("resize", function () {
        if (window.innerWidth > 768) closeSidebar();
    });
});
// Live clock update logic
document.addEventListener('DOMContentLoaded', function () {
    const clock = document.getElementById('liveClock');
    function updateClock() {
        const now = new Date();
        const use12Hour = document.body.dataset.clockFormat === '12h';
        const time = new Intl.DateTimeFormat(use12Hour ? 'en-US' : 'en-GB', {
            timeZone: 'Asia/Kolkata',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: use12Hour
        }).format(now);
        if (clock) clock.textContent = time;
    }
    updateClock();
    setInterval(updateClock, 1000);
});