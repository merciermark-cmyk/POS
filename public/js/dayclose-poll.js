/**
 * DayClose polling — shows full-screen overlay on POS stations when DayClose completes.
 * Only activates on devices with a pos_terminal_id cookie (physical POS stations).
 */
(function () {
    // Guard: only run on POS stations (have terminal cookie)
    var match = document.cookie.match(/(?:^|;\s*)pos_terminal_id=(\d+)/);
    if (!match) return;

    var base = document.querySelector('meta[name="base-url"]')?.content || '/';
    var polling = null;
    var overlayShown = false;
    // Track whether this polling session has ever seen the shift open.
    // Only fire the overlay if we previously saw an open shift on this terminal
    // (i.e., the close happened during this staff session). Suppresses the
    // overlay on a fresh login the day of an already-completed close.
    var sawShiftOpen = false;

    function checkStatus() {
        fetch(base + 'dayclose-status', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.shift_open) sawShiftOpen = true;
                if (data.dayclose_complete && sawShiftOpen && !overlayShown) {
                    clearInterval(polling);
                    showOverlay(data.shift_id);
                }
            })
            .catch(function () { /* silent — keep polling */ });
    }

    function showOverlay(shiftId) {
        overlayShown = true;

        var overlay = document.createElement('div');
        overlay.id = 'dayclose-overlay';
        overlay.style.cssText =
            'position:fixed;top:0;left:0;width:100%;height:100%;' +
            'background:rgba(0,0,0,0.92);z-index:99999;' +
            'display:flex;flex-direction:column;align-items:center;justify-content:center;' +
            'color:#fff;font-family:system-ui,sans-serif;';

        overlay.innerHTML =
            '<div style="text-align:center;padding:2rem">' +
                '<div style="font-size:2.5rem;font-weight:700;margin-bottom:1rem">Close Registers</div>' +
                '<p style="font-size:1.3rem;opacity:0.8;margin-bottom:2.5rem">Registers have been counted. Tap below to complete the close.</p>' +
                '<button id="dayclose-close-btn" style="' +
                    'font-size:1.8rem;padding:1.2rem 3rem;border:none;border-radius:12px;' +
                    'background:#198754;color:#fff;font-weight:600;cursor:pointer;' +
                    'min-width:320px;touch-action:manipulation' +
                '">Complete Close</button>' +
                '<div id="dayclose-error" style="margin-top:1.5rem;color:#f8d7da;font-size:1.1rem;display:none"></div>' +
            '</div>';

        document.body.appendChild(overlay);

        document.getElementById('dayclose-close-btn').addEventListener('click', function () {
            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Completing...';

            fetch(base + 'dayclose-close', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    window.location.href = base + 'shift/open';
                } else {
                    showError(data.error || 'Close failed. Try again.');
                    btn.disabled = false;
                    btn.textContent = 'Complete Close';
                }
            })
            .catch(function () {
                showError('Network error. Try again.');
                btn.disabled = false;
                btn.textContent = 'Complete Close';
            });
        });
    }

    function showError(msg) {
        var el = document.getElementById('dayclose-error');
        if (el) {
            el.textContent = msg;
            el.style.display = 'block';
        }
    }

    // Start polling after 3-second delay (let page load)
    setTimeout(function () {
        checkStatus();
        polling = setInterval(checkStatus, 20000);
    }, 3000);
})();
