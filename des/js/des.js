
/* ======================================================
   CREATIVE MANAGER | SME DASHBOARD
   File: sme/des.js
====================================================== */

"use strict";

document.addEventListener("DOMContentLoaded", () => {
    const sidebar = document.getElementById("sidebar");
    const toggleButton = document.getElementById("sidebarToggle");
    const closeButton = document.getElementById("sidebarClose");
    const backdrop = document.getElementById("sidebarBackdrop");
    const liveClock = document.getElementById("liveClock");

    if (!sidebar) {
        return;
    }

    /* ==============================================
       MOBILE SIDEBAR
    ============================================== */

    function openSidebar() {
        sidebar.classList.add("is-open");

        if (backdrop) {
            backdrop.classList.add("is-visible");
        }

        if (toggleButton) {
            toggleButton.setAttribute("aria-expanded", "true");
            toggleButton.setAttribute("aria-label", "Close sidebar");
        }

        document.body.style.overflow = "hidden";

        if (closeButton) {
            closeButton.focus();
        }
    }

    function closeSidebar(restoreFocus = true) {
        sidebar.classList.remove("is-open");

        if (backdrop) {
            backdrop.classList.remove("is-visible");
        }

        if (toggleButton) {
            toggleButton.setAttribute("aria-expanded", "false");
            toggleButton.setAttribute("aria-label", "Open sidebar");
        }

        document.body.style.overflow = "";

        if (
            restoreFocus &&
            toggleButton &&
            window.innerWidth <= 768
        ) {
            toggleButton.focus();
        }
    }

    if (toggleButton) {
        toggleButton.addEventListener("click", () => {
            if (sidebar.classList.contains("is-open")) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    if (closeButton) {
        closeButton.addEventListener("click", () => closeSidebar());
    }

    if (backdrop) {
        backdrop.addEventListener("click", () => closeSidebar());
    }

    document.addEventListener("keydown", (event) => {
        if (
            event.key === "Escape" &&
            sidebar.classList.contains("is-open")
        ) {
            closeSidebar();
        }
    });

    sidebar.querySelectorAll("a").forEach((link) => {
        link.addEventListener("click", () => {
            if (window.innerWidth <= 768) {
                closeSidebar(false);
            }
        });
    });

    window.addEventListener("resize", () => {
        if (window.innerWidth > 768) {
            closeSidebar(false);
        }
    });

    /* ==============================================
       LIVE CLOCK — INDIA STANDARD TIME
    ============================================== */

    function updateClock() {
        if (!liveClock) {
            return;
        }

        liveClock.textContent = new Intl.DateTimeFormat("en-IN", {
            timeZone: "Asia/Kolkata",
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit",
            hour12: false
        }).format(new Date());
    }

    updateClock();
    window.setInterval(updateClock, 1000);

    /* ==============================================
       ACTIVE NAVIGATION
    ============================================== */

    const currentUrl = new URL(window.location.href);

    const currentPage = (
        currentUrl.searchParams.get("page") || "dashboard"
    ).toLowerCase();

    sidebar.querySelectorAll(".nav-link").forEach((link) => {
        const linkUrl = new URL(link.href, window.location.origin);

        const linkPage = (
            linkUrl.searchParams.get("page") || "dashboard"
        ).toLowerCase();

        const active = linkPage === currentPage;

        link.classList.toggle("active", active);

        if (active) {
            link.setAttribute("aria-current", "page");
        } else {
            link.removeAttribute("aria-current");
        }
    });

    /* ==============================================
       PROFILE IMAGE FALLBACK
    ============================================== */

    const profileImage = document.getElementById("profileImage");
    const profileFallback = document.getElementById("profileFallback");

    if (profileImage && profileFallback) {
        function showFallback() {
            profileImage.hidden = true;
            profileFallback.hidden = false;
        }

        profileImage.addEventListener("error", showFallback);

        profileImage.addEventListener("load", () => {
            if (profileImage.naturalWidth === 0) {
                showFallback();
            } else {
                profileImage.hidden = false;
                profileFallback.hidden = true;
            }
        });

        if (profileImage.complete && profileImage.naturalWidth === 0) {
            showFallback();
        }
    }
});
