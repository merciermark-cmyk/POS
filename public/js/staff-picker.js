/**
 * Staff picker — code entry overlay.
 * Cards are plain divs with data attributes; clicking opens a 3-digit code overlay
 * (or submits immediately if the user has no code set).
 */
(function() {
    const baseUrl = document.querySelector('meta[name="base-url"]')?.content || '/';
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const overlay = document.getElementById('codeOverlay');
    const overlayName = document.getElementById('codeOverlayName');
    const dots = overlay?.querySelectorAll('.code-dot');
    const form = document.getElementById('staffPickForm');
    const errorEl = document.getElementById('codeError');
    let code = '';
    let activeUserId = null;

    // Card click handler
    document.querySelectorAll('.staff-card').forEach(card => {
        card.addEventListener('click', function() {
            const userId = this.dataset.userId;
            const hasCode = this.dataset.hasCode === '1';
            const username = this.dataset.username;

            if (!hasCode) {
                // No code — submit immediately
                submitPick(userId, '');
                return;
            }

            // Show code overlay
            activeUserId = userId;
            code = '';
            updateDots();
            overlayName.textContent = username;
            overlay.classList.add('active');
            if (errorEl) errorEl.style.display = 'none';
        });
    });

    // Number pad buttons
    overlay?.querySelectorAll('.btn-code-digit').forEach(btn => {
        btn.addEventListener('click', function() {
            if (code.length >= 3) return;
            code += this.dataset.digit;
            updateDots();
            if (code.length === 3) {
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

    // Cancel button / Escape
    overlay?.querySelector('[data-action="cancel"]')?.addEventListener('click', closeOverlay);
    document.addEventListener('keydown', function(e) {
        if (!overlay?.classList.contains('active')) return;

        if (e.key === 'Escape') {
            closeOverlay();
        } else if (e.key === 'Backspace') {
            e.preventDefault();
            code = code.slice(0, -1);
            updateDots();
        } else if (/^\d$/.test(e.key) && code.length < 3) {
            code += e.key;
            updateDots();
            if (code.length === 3) {
                submitPick(activeUserId, code);
            }
        }
    });

    function updateDots() {
        if (!dots) return;
        dots.forEach((dot, i) => {
            dot.classList.toggle('filled', i < code.length);
        });
    }

    function closeOverlay() {
        overlay?.classList.remove('active');
        code = '';
        activeUserId = null;
        updateDots();
    }

    function submitPick(userId, staffCode) {
        // Build and submit a hidden form
        form.querySelector('[name="user_id"]').value = userId;
        form.querySelector('[name="staff_code"]').value = staffCode;
        form.submit();
    }
})();
