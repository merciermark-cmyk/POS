/**
 * Payment modal — split tender support.
 */
(function() {
    let totalDue = 0;
    let payments = [];

    window.openPaymentModal = function(total) {
        totalDue = total;
        payments = [];

        document.getElementById('modalTotal').textContent = '$' + total.toFixed(2);
        document.getElementById('modalRemaining').textContent = '$' + total.toFixed(2);
        document.getElementById('paymentEntries').innerHTML = '';
        document.getElementById('paymentHiddenFields').innerHTML = '';
        document.getElementById('completeSaleBtn').disabled = true;
        document.getElementById('changeDisplay').style.display = 'none';
        document.getElementById('quickCashPanel').style.display = 'none';
        document.getElementById('giftCardPanel').style.display = 'none';

        const modal = new bootstrap.Modal(document.getElementById('paymentModal'));
        modal.show();
    };

    // Add payment method buttons
    document.querySelectorAll('.add-payment-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const method = this.dataset.method;

            // Hide all panels
            document.getElementById('quickCashPanel').style.display = 'none';
            document.getElementById('giftCardPanel').style.display = 'none';

            if (method === 'cash') {
                showQuickCash();
            } else if (method === 'web_gift_card') {
                document.getElementById('giftCardPanel').style.display = 'block';
            } else {
                // Card or gift_card: add remaining amount
                const remaining = getRemaining();
                if (remaining > 0) {
                    addPayment(method, remaining);
                }
            }
        });
    });

    function showQuickCash() {
        const remaining = getRemaining();
        const panel = document.getElementById('quickCashPanel');
        panel.style.display = 'block';

        // Quick cash denominations
        const quickAmounts = [5, 10, 20, 50, 100];
        const container = document.getElementById('quickCashBtns');
        container.innerHTML = '';

        // Exact amount button
        const exactBtn = document.createElement('button');
        exactBtn.className = 'btn btn-success btn-lg';
        exactBtn.textContent = 'Exact $' + remaining.toFixed(2);
        exactBtn.addEventListener('click', () => addPayment('cash', remaining));
        container.appendChild(exactBtn);

        // Denomination buttons
        quickAmounts.forEach(amt => {
            if (amt >= remaining) {
                const btn = document.createElement('button');
                btn.className = 'btn btn-outline-success btn-lg';
                btn.textContent = '$' + amt;
                btn.addEventListener('click', () => addPayment('cash', amt));
                container.appendChild(btn);
            }
        });

        // Custom amount
        document.getElementById('customCashAmount').value = remaining.toFixed(2);
    }

    // Custom cash button
    const applyCustom = document.getElementById('applyCustomCash');
    if (applyCustom) {
        applyCustom.addEventListener('click', function() {
            const amt = parseFloat(document.getElementById('customCashAmount').value);
            if (amt > 0) {
                addPayment('cash', amt);
            }
        });
    }

    // Gift card check
    const gcCheckBtn = document.getElementById('gcCheckBtn');
    if (gcCheckBtn) {
        gcCheckBtn.addEventListener('click', async function() {
            const code = document.getElementById('gcCode').value.trim();
            if (!code) return;

            const result = document.getElementById('gcResult');
            result.innerHTML = '<div class="spinner-border spinner-border-sm"></div>';

            try {
                const resp = await fetch(window.POS.baseUrl + 'api/gift-card/check?code=' + encodeURIComponent(code));
                const data = await resp.json();

                if (data.error) {
                    result.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
                } else {
                    const remaining = getRemaining();
                    const useAmt = Math.min(data.balance, remaining);
                    result.innerHTML = `
                        <div class="alert alert-success">
                            Balance: $${data.balance.toFixed(2)}
                            <button class="btn btn-sm btn-success ms-2" id="gcApplyBtn">
                                Apply $${useAmt.toFixed(2)}
                            </button>
                        </div>`;
                    document.getElementById('gcApplyBtn').addEventListener('click', () => {
                        addPayment('web_gift_card', useAmt, code);
                    });
                }
            } catch (e) {
                result.innerHTML = '<div class="alert alert-danger">Connection error</div>';
            }
        });
    }

    function addPayment(method, amount, reference = '') {
        amount = Math.round(amount * 100) / 100;
        payments.push({ method, amount, reference });
        renderPayments();
    }

    function renderPayments() {
        const container = document.getElementById('paymentEntries');
        const hiddenFields = document.getElementById('paymentHiddenFields');

        container.innerHTML = payments.map((p, i) => `
            <div class="payment-entry d-flex justify-content-between align-items-center">
                <div>
                    <span class="method-label">${p.method.replace('_', ' ')}</span>
                    ${p.reference ? `<small class="text-muted ms-2">${escHtml(p.reference)}</small>` : ''}
                </div>
                <div class="d-flex align-items-center gap-2">
                    <strong>$${p.amount.toFixed(2)}</strong>
                    <button class="btn btn-sm btn-outline-danger remove-payment" data-index="${i}">&times;</button>
                </div>
            </div>
        `).join('');

        // Hidden form fields
        hiddenFields.innerHTML = payments.map((p, i) => `
            <input type="hidden" name="pay_method[]" value="${p.method}">
            <input type="hidden" name="pay_amount[]" value="${p.amount}">
            <input type="hidden" name="pay_reference[]" value="${escHtml(p.reference)}">
        `).join('');

        // Bind remove buttons
        container.querySelectorAll('.remove-payment').forEach(btn => {
            btn.addEventListener('click', function() {
                payments.splice(parseInt(this.dataset.index), 1);
                renderPayments();
            });
        });

        // Update remaining
        const paid = payments.reduce((sum, p) => sum + p.amount, 0);
        const remaining = Math.max(0, totalDue - paid);
        document.getElementById('modalRemaining').textContent = '$' + remaining.toFixed(2);
        document.getElementById('modalRemaining').className = remaining > 0 ? 'text-danger' : 'text-success';

        // Change
        const change = Math.max(0, paid - totalDue);
        const changeDisplay = document.getElementById('changeDisplay');
        if (change > 0) {
            changeDisplay.style.display = 'block';
            document.getElementById('changeAmount').textContent = '$' + change.toFixed(2);
        } else {
            changeDisplay.style.display = 'none';
        }

        // Enable/disable complete button
        document.getElementById('completeSaleBtn').disabled = paid < totalDue;
    }

    function getRemaining() {
        const paid = payments.reduce((sum, p) => sum + p.amount, 0);
        return Math.max(0, Math.round((totalDue - paid) * 100) / 100);
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }
})();
