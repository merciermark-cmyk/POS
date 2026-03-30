/**
 * Idle timer — redirects to switch-user after OPERATOR_TIMEOUT seconds of inactivity.
 * Reads timeout from <meta name="operator-timeout"> in seconds.
 */
(function() {
    const baseUrl = document.querySelector('meta[name="base-url"]')?.content || '/';
    const timeout = parseInt(document.querySelector('meta[name="operator-timeout"]')?.content || '45', 10);
    const warningAt = 10; // show warning when this many seconds remain
    const warningEl = document.getElementById('idleWarning');
    const warningText = document.getElementById('idleWarningText');
    let remaining = timeout;
    let intervalId = null;

    function resetTimer() {
        remaining = timeout;
        if (warningEl) warningEl.style.display = 'none';
    }

    function tick() {
        remaining--;
        if (remaining <= warningAt && remaining > 0) {
            if (warningEl) {
                warningEl.style.display = 'block';
                if (warningText) warningText.textContent = remaining;
            }
        }
        if (remaining <= 0) {
            clearInterval(intervalId);
            window.location = baseUrl + 'switch-user';
        }
    }

    // Start
    intervalId = setInterval(tick, 1000);

    // Reset on user interaction
    ['click', 'keydown', 'touchstart', 'mousemove', 'scroll'].forEach(evt => {
        document.addEventListener(evt, resetTimer, { passive: true });
    });

    // Expose for pos.js to call after API activity
    window.resetIdleTimer = resetTimer;
})();
