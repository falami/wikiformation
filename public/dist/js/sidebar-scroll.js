(() => {
    const sidebar = document.querySelector('.sidebar[data-scroll-key]');
    if (!sidebar) return;

    const key = sidebar.dataset.scrollKey;
    const savePosition = () => {
        try {
            sessionStorage.setItem(key, String(sidebar.scrollTop));
        } catch (_) {
            // Navigation must still work if browser storage is unavailable.
        }
    };
    const restorePosition = () => {
        try {
            const saved = sessionStorage.getItem(key);
            if (saved === null) return;
            const top = Number(saved);
            if (Number.isFinite(top) && top >= 0) sidebar.scrollTop = top;
        } catch (_) {
            // Keep the default position when browser storage is unavailable.
        }
    };

    // Run immediately after the menu markup to avoid a visible jump on load.
    restorePosition();
    sidebar.addEventListener('scroll', savePosition, { passive: true });
    sidebar.addEventListener('click', savePosition, { capture: true });
    window.addEventListener('pagehide', savePosition);
    window.addEventListener('pageshow', restorePosition);
})();
