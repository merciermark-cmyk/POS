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
        stopBeep();
        stopFlash();
        if (warningEl) warningEl.style.display = 'none';
    }

    function cartHasItems() {
        return document.querySelectorAll('.pos-cart-item').length > 0;
    }

    // Audio beep (works if speaker is connected)
    let beepInterval = null;
    function startBeep() {
        if (beepInterval) return;
        function playBeep() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.value = 880;
                gain.gain.value = 1.0;
                osc.start();
                osc.stop(ctx.currentTime + 0.15);
            } catch(e) {}
        }
        playBeep();
        beepInterval = setInterval(playBeep, 2000);
    }
    function stopBeep() {
        if (beepInterval) { clearInterval(beepInterval); beepInterval = null; }
    }

    // Visual flash alarm for abandoned cart
    let flashInterval = null;
    let flashOverlay = null;
    function startFlash() {
        if (flashInterval) return;
        // Create full-screen overlay
        flashOverlay = document.createElement('div');
        flashOverlay.id = 'cartAlarmOverlay';
        flashOverlay.innerHTML = '<div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center">'
            + '<div style="font-size:4rem;margin-bottom:0.5rem">\u26a0\ufe0f</div>'
            + '<div style="font-size:2rem;font-weight:700">CART HAS ITEMS</div>'
            + '<div style="font-size:1.1rem;margin-top:0.5rem">Tap anywhere to continue</div></div>';
        flashOverlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;'
            + 'display:flex;align-items:center;justify-content:center;'
            + 'color:#fff;pointer-events:none;transition:opacity 0.1s';
        document.body.appendChild(flashOverlay);

        let on = true;
        flashOverlay.style.background = 'rgba(220,53,69,0.85)';
        flashInterval = setInterval(function() {
            on = !on;
            flashOverlay.style.background = on ? 'rgba(220,53,69,0.85)' : 'rgba(220,53,69,0.35)';
        }, 600);
    }
    function stopFlash() {
        if (flashInterval) { clearInterval(flashInterval); flashInterval = null; }
        if (flashOverlay) { flashOverlay.remove(); flashOverlay = null; }
    }

    function tick() {
        remaining--;

        // If cart has items, inhibit timeout and beep as warning
        if (cartHasItems()) {
            if (remaining <= 0) {
                remaining = 1; // hold at 1, never redirect
            }
            if (remaining <= warningAt) {
                startBeep();
                startFlash();
                if (warningEl) {
                    warningEl.style.display = 'block';
                    if (warningText) warningText.textContent = 'Cart has items!';
                }
            }
            return;
        }

        stopBeep();
        stopFlash();

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

// ── Heartbeat — keeps terminal locked while browser is active ──
(function() {
    const baseUrl = document.querySelector('meta[name="base-url"]')?.content || '/';
    function sendHeartbeat() {
        fetch(baseUrl + 'api/heartbeat', { method: 'POST', credentials: 'same-origin' }).catch(function(){});
    }
    sendHeartbeat();
    setInterval(sendHeartbeat, 30000);
})();
