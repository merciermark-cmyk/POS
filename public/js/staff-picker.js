/**
 * Staff picker — PIN entry overlay.
 * Cards are plain divs with data attributes; clicking opens a PIN overlay
 * (or submits immediately if the user has no PIN set).
 */
(function() {
    const overlay = document.getElementById('codeOverlay');
    const overlayName = document.getElementById('codeOverlayName');
    const dotsContainer = document.getElementById('codeDots');
    const form = document.getElementById('staffPickForm');
    const errorEl = document.getElementById('codeError');
    let code = '';
    let activeUserId = null;
    let requiredLength = 0;

    // Card click handler
    document.querySelectorAll('.staff-card').forEach(card => {
        card.addEventListener('click', function() {
            const userId = this.dataset.userId;
            const hasCode = this.dataset.hasCode === '1';
            const username = this.dataset.username;
            const pinLength = parseInt(this.dataset.pinLength) || 0;

            if (!hasCode) {
                submitPick(userId, '');
                return;
            }

            // Show PIN overlay
            activeUserId = userId;
            requiredLength = pinLength;
            code = '';
            buildDots(pinLength);
            updateDots();
            overlayName.textContent = username;
            overlay.classList.add('active');
            if (errorEl) errorEl.style.display = 'none';
        });
    });

    // Number pad buttons
    overlay?.querySelectorAll('.btn-code-digit').forEach(btn => {
        btn.addEventListener('click', function() {
            if (code.length >= requiredLength) return;
            code += this.dataset.digit;
            updateDots();
            if (code.length === requiredLength) {
                submitPick(activeUserId, code);
            }
        });
    });

    // Backspace button
    overlay?.querySelector('[data-action="backspace"]')?.addEventListener('click', function() {
        code = code.slice(0, -1);
        updateDots();
    });

    // Clear button
    overlay?.querySelector('[data-action="clear"]')?.addEventListener('click', function() {
        code = '';
        updateDots();
    });

    // Cancel button / Escape / keyboard digits
    overlay?.querySelector('[data-action="cancel"]')?.addEventListener('click', closeOverlay);
    document.addEventListener('keydown', function(e) {
        if (!overlay?.classList.contains('active')) return;

        if (e.key === 'Escape') {
            closeOverlay();
        } else if (e.key === 'Backspace') {
            e.preventDefault();
            code = code.slice(0, -1);
            updateDots();
        } else if (/^\d$/.test(e.key) && code.length < requiredLength) {
            code += e.key;
            updateDots();
            if (code.length === requiredLength) {
                submitPick(activeUserId, code);
            }
        }
    });

    function buildDots(count) {
        dotsContainer.innerHTML = '';
        for (let i = 0; i < count; i++) {
            const dot = document.createElement('span');
            dot.className = 'code-dot';
            dotsContainer.appendChild(dot);
        }
    }

    function updateDots() {
        const dots = dotsContainer?.querySelectorAll('.code-dot');
        if (!dots) return;
        dots.forEach((dot, i) => {
            dot.classList.toggle('filled', i < code.length);
        });
    }

    function closeOverlay() {
        overlay?.classList.remove('active');
        code = '';
        activeUserId = null;
        requiredLength = 0;
        updateDots();
    }

    function submitPick(userId, pin) {
        form.querySelector('[name="user_id"]').value = userId;
        form.querySelector('[name="pin"]').value = pin;
        form.submit();
    }
})();
