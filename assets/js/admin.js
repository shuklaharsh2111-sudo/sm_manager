document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    const liveClock = document.getElementById('liveClock');
    if (sidebar && sidebarToggle && sidebarBackdrop) {
        function openSidebar() {
            sidebar.classList.add('active');
            sidebarBackdrop.classList.add('active');
            sidebarToggle.setAttribute('aria-expanded', 'true');
            document.body.classList.add('sidebar-open');
        }
        function closeSidebar() {
            sidebar.classList.remove('active');
            sidebarBackdrop.classList.remove('active');
            sidebarToggle.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('sidebar-open');
        }
        function toggleSidebar() {
            if (sidebar.classList.contains('active')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        }
        sidebarToggle.addEventListener('click', toggleSidebar);
        sidebarBackdrop.addEventListener('click', closeSidebar);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeSidebar();
            }
        });
        sidebar.querySelectorAll('.nav-link').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 768) {
                    closeSidebar();
                }
            });
        });
        window.addEventListener('resize', function () {
            if (window.innerWidth > 768) {
                closeSidebar();
            }
        });
    }
    if (liveClock) {
        const clockFormatter = new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Asia/Kolkata',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        });
        function updateClock() {
            liveClock.textContent = clockFormatter.format(new Date());
        }
        updateClock();
        setInterval(updateClock, 1000);
    }
});
