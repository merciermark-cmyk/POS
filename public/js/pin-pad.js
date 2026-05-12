/**
 * PIN pad widget for quick login (1–3 digit PINs).
 */
(function() {
    const form = document.getElementById('pinForm');
    const input = document.getElementById('pinInput');
    const dots = document.querySelectorAll('.pin-dot');
    let pin = '';
    const maxLen = 3;

    function updateDots() {
        dots.forEach((dot, i) => {
            dot.classList.toggle('filled', i < pin.length);
        });
    }

    document.querySelectorAll('.btn-pin').forEach(btn => {
        btn.addEventListener('click', function() {
            const digit = this.dataset.digit;
            const action = this.dataset.action;

            if (action === 'clear') {
                pin = '';
            } else if (action === 'backspace') {
                pin = pin.slice(0, -1);
            } else if (action === 'submit') {
                if (pin.length > 0) {
                    input.value = pin;
                    form.submit();
                }
                return;
            } else if (digit !== undefined && pin.length < maxLen) {
                pin += digit;
            }

            updateDots();
            input.value = pin;

            // Auto-submit when max digits entered
            if (pin.length === maxLen) {
                setTimeout(() => form.submit(), 150);
            }
        });
    });

    // Keyboard support
    document.addEventListener('keydown', function(e) {
        if (e.key >= '0' && e.key <= '9' && pin.length < maxLen) {
            pin += e.key;
            updateDots();
            input.value = pin;
            if (pin.length === maxLen) {
                setTimeout(() => form.submit(), 150);
            }
        } else if (e.key === 'Backspace') {
            pin = pin.slice(0, -1);
            updateDots();
            input.value = pin;
        } else if (e.key === 'Enter' && pin.length > 0) {
            input.value = pin;
            form.submit();
        } else if (e.key === 'Escape') {
            pin = '';
            updateDots();
            input.value = '';
        }
    });
})();
