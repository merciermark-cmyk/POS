/**
 * PIN pad widget for quick login.
 */
(function() {
    const form = document.getElementById('pinForm');
    const input = document.getElementById('pinInput');
    const dots = document.querySelectorAll('.pin-dot');
    let pin = '';

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
            } else if (digit !== undefined && pin.length < 4) {
                pin += digit;
            }

            updateDots();
            input.value = pin;

            // Auto-submit when 4 digits entered
            if (pin.length === 4) {
                setTimeout(() => form.submit(), 150);
            }
        });
    });

    // Keyboard support
    document.addEventListener('keydown', function(e) {
        if (e.key >= '0' && e.key <= '9' && pin.length < 4) {
            pin += e.key;
            updateDots();
            input.value = pin;
            if (pin.length === 4) {
                setTimeout(() => form.submit(), 150);
            }
        } else if (e.key === 'Backspace') {
            pin = pin.slice(0, -1);
            updateDots();
            input.value = pin;
        } else if (e.key === 'Escape') {
            pin = '';
            updateDots();
            input.value = '';
        }
    });
})();
