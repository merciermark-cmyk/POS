/**
 * Payment modal — split tender support + Moneris Go integration.
 */
(function() {
    let totalDue = 0;
    let payments = [];
    let monerisInProgress = false;
    let usdRate = null;  // { rate, base_rate, markup }

    window.openPaymentModal = function(total) {
        totalDue = total;
        payments = [];
        monerisInProgress = false;

        document.getElementById('modalTotal').textContent = '$' + total.toFixed(2);
        document.getElementById('modalRemaining').textContent = '$' + total.toFixed(2);
        document.getElementById('paymentEntries').innerHTML = '';
        document.getElementById('paymentHiddenFields').innerHTML = '';
        document.getElementById('completeSaleBtn').disabled = true;
        document.getElementById('changeDisplay').style.display = 'none';
        document.getElementById('quickCashPanel').style.display = 'none';
        document.getElementById('cardAmountPanel').style.display = 'none';
        document.getElementById('giftCardPanel').style.display = 'none';
        document.getElementById('usdCashPanel').style.display = 'none';
        hideMonerisPanel();

        const modal = new bootstrap.Modal(document.getElementById('paymentModal'));
        modal.show();
    };

    // Add payment method buttons
    document.querySelectorAll('.add-payment-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            if (monerisInProgress) return;

            const method = this.dataset.method;

            // Hide all panels
            document.getElementById('quickCashPanel').style.display = 'none';
            document.getElementById('cardAmountPanel').style.display = 'none';
            document.getElementById('giftCardPanel').style.display = 'none';
            document.getElementById('usdCashPanel').style.display = 'none';
            hideMonerisPanel();

            if (method === 'usd_cash') {
                showUsdCash();
            } else if (method === 'cash') {
                showQuickCash();
            } else if (method === 'web_gift_card') {
                document.getElementById('giftCardPanel').style.display = 'block';
            } else if (method === 'card' && typeof POS_MONERIS_ENABLED !== 'undefined' && POS_MONERIS_ENABLED) {
                // Moneris integrated card payment
                startMonerisPayment();
            } else if (method === 'card') {
                showCardAmount();
            } else {
                // gift_card: add remaining amount
                const remaining = getRemaining();
                if (remaining > 0) {
                    addPayment(method, remaining);
                }
            }
        });
    });

    // ── Moneris Integration ─────────────────────────────────────────

    function startMonerisPayment() {
        const remaining = getRemaining();
        if (remaining <= 0) return;

        monerisInProgress = true;
        showMonerisProcessing();
        disablePaymentButtons(true);

        fetch(window.POS.baseUrl + 'api/moneris/purchase', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'amount=' + remaining.toFixed(2),
        })
        .then(r => r.json())
        .then(data => {
            monerisInProgress = false;
            disablePaymentButtons(false);

            if (data.success && data.approved) {
                // Build a reference string for display
                const ref = [data.card_type, data.masked_pan, data.auth_code, data.form_factor]
                    .filter(Boolean).join(' / ');

                addPayment('moneris', remaining, ref, data.moneris_id);
                showMonerisResult(true,
                    'Approved' +
                    (data.card_type ? ' — ' + data.card_type : '') +
                    (data.masked_pan ? ' ****' + data.masked_pan : '') +
                    (data.auth_code ? ' (Auth: ' + data.auth_code + ')' : '')
                );
            } else {
                // Declined or error
                const msg = data.error || data.message || 'Declined';
                showMonerisResult(false, msg, data.busy, data.timeout);
            }
        })
        .catch(err => {
            monerisInProgress = false;
            disablePaymentButtons(false);
            showMonerisResult(false, 'Connection error: ' + err.message);
        });
    }

    function showMonerisProcessing() {
        const panel = document.getElementById('monerisPanel');
        const processing = document.getElementById('monerisProcessing');
        const result = document.getElementById('monerisResult');
        panel.style.display = 'block';
        processing.style.display = 'block';
        result.style.display = 'none';
    }

    function showMonerisResult(success, message, busy, timeout) {
        const panel = document.getElementById('monerisPanel');
        const processing = document.getElementById('monerisProcessing');
        const result = document.getElementById('monerisResult');
        panel.style.display = 'block';
        processing.style.display = 'none';
        result.style.display = 'block';

        if (success) {
            result.innerHTML = `
                <div class="alert alert-success py-2">
                    <strong>Card Payment Approved</strong><br>
                    <small>${escHtml(message)}</small>
                </div>`;
            // Auto-hide after a moment
            setTimeout(() => { hideMonerisPanel(); }, 3000);
        } else {
            result.innerHTML = `
                <div class="alert alert-danger py-2">
                    <strong>${busy ? 'Terminal Busy' : (timeout ? 'Timeout' : 'Card Declined')}</strong><br>
                    <small>${escHtml(message)}</small>
                </div>
                <div class="d-flex gap-2 mt-2">
                    <button class="btn btn-primary btn-sm" id="monerisRetryBtn">Retry Card</button>
                    <button class="btn btn-outline-secondary btn-sm" id="monerisManualBtn">Enter Manually</button>
                </div>`;

            document.getElementById('monerisRetryBtn').addEventListener('click', () => {
                startMonerisPayment();
            });
            document.getElementById('monerisManualBtn').addEventListener('click', () => {
                // Fall back to manual card entry
                hideMonerisPanel();
                const remaining = getRemaining();
                if (remaining > 0) {
                    addPayment('card', remaining);
                }
            });
        }
    }

    function hideMonerisPanel() {
        const panel = document.getElementById('monerisPanel');
        if (panel) {
            panel.style.display = 'none';
            document.getElementById('monerisProcessing').style.display = 'none';
            document.getElementById('monerisResult').style.display = 'none';
        }
    }

    function disablePaymentButtons(disabled) {
        document.querySelectorAll('.add-payment-btn').forEach(btn => {
            btn.disabled = disabled;
        });
    }

    // ── Quick Cash ──────────────────────────────────────────────────

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

    // ── Card Amount Panel ───────────────────────────────────────────

    function showCardAmount() {
        const remaining = getRemaining();
        const panel = document.getElementById('cardAmountPanel');
        panel.style.display = 'block';

        document.getElementById('cardExactBtn').textContent = 'Exact $' + remaining.toFixed(2);
        document.getElementById('customCardAmount').value = remaining.toFixed(2);
    }

    const cardExactBtn = document.getElementById('cardExactBtn');
    if (cardExactBtn) {
        cardExactBtn.addEventListener('click', function() {
            const remaining = getRemaining();
            if (remaining > 0) {
                addPayment('card', remaining);
                document.getElementById('cardAmountPanel').style.display = 'none';
            }
        });
    }

    const applyCustomCard = document.getElementById('applyCustomCard');
    if (applyCustomCard) {
        applyCustomCard.addEventListener('click', function() {
            const amt = parseFloat(document.getElementById('customCardAmount').value);
            const remaining = getRemaining();
            if (amt > 0 && amt <= remaining) {
                addPayment('card', amt);
                document.getElementById('cardAmountPanel').style.display = 'none';
            } else if (amt > remaining) {
                addPayment('card', remaining);
                document.getElementById('cardAmountPanel').style.display = 'none';
            }
        });
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

    // ── USD Cash ─────────────────────────────────────────────────────

    async function showUsdCash() {
        const panel = document.getElementById('usdCashPanel');
        panel.style.display = 'block';

        const remaining = getRemaining();
        const rateInfo = document.getElementById('usdRateInfo');
        const denomContainer = document.getElementById('usdDenomBtns');

        // Fetch rate if not cached yet
        if (!usdRate) {
            rateInfo.textContent = 'Loading rate...';
            denomContainer.innerHTML = '';
            try {
                const resp = await fetch(window.POS.baseUrl + 'api/currency/usd');
                const data = await resp.json();
                if (data.error) {
                    rateInfo.innerHTML = '<span class="text-danger">Rate unavailable: ' + escHtml(data.error) + '</span>';
                    return;
                }
                usdRate = data;
            } catch (e) {
                rateInfo.innerHTML = '<span class="text-danger">Could not fetch exchange rate</span>';
                return;
            }
        }

        const rate = usdRate.rate;
        rateInfo.innerHTML = '1 USD = <strong>' + rate.toFixed(4) + ' CAD</strong>' +
            ' <small class="text-muted">(Bank: ' + usdRate.base_rate.toFixed(4) + ' + ' + usdRate.markup + '% markup)</small>';

        // Amount due in USD
        const usdAmount = remaining / rate;
        document.getElementById('usdAmountDue').textContent =
            '$' + remaining.toFixed(2) + ' CAD = $' + usdAmount.toFixed(2) + ' USD';

        // Denomination buttons
        const denoms = [5, 10, 20, 50, 100];
        denomContainer.innerHTML = '';
        denoms.forEach(d => {
            const cadEquiv = Math.round(d * rate * 100) / 100;
            const btn = document.createElement('button');
            btn.className = 'btn btn-outline-secondary btn-lg';
            btn.innerHTML = '<strong>$' + d + ' USD</strong><br><small>= $' + cadEquiv.toFixed(2) + ' CAD</small>';
            btn.addEventListener('click', () => {
                addPayment('cash', cadEquiv, 'USD $' + d + '.00 @ ' + rate.toFixed(4));
                panel.style.display = 'none';
            });
            denomContainer.appendChild(btn);
        });

        // Pre-fill custom amount
        document.getElementById('customUsdAmount').value = usdAmount.toFixed(2);
    }

    // Custom USD amount button
    const applyCustomUsd = document.getElementById('applyCustomUsd');
    if (applyCustomUsd) {
        applyCustomUsd.addEventListener('click', function() {
            const usdAmt = parseFloat(document.getElementById('customUsdAmount').value);
            if (usdAmt > 0 && usdRate) {
                const cadAmt = Math.round(usdAmt * usdRate.rate * 100) / 100;
                addPayment('cash', cadAmt, 'USD $' + usdAmt.toFixed(2) + ' @ ' + usdRate.rate.toFixed(4));
                document.getElementById('usdCashPanel').style.display = 'none';
            }
        });
    }

    // ── Payment list ────────────────────────────────────────────────

    function addPayment(method, amount, reference = '', monerisId = null) {
        amount = Math.round(amount * 100) / 100;
        payments.push({ method, amount, reference, monerisId });
        renderPayments();
    }

    function renderPayments() {
        const container = document.getElementById('paymentEntries');
        const hiddenFields = document.getElementById('paymentHiddenFields');

        const methodLabels = {
            'cash': 'Cash',
            'card': 'Card',
            'moneris': 'Card (Moneris)',
            'gift_card': 'Gift Card',
            'web_gift_card': 'Web GC',
        };

        container.innerHTML = payments.map((p, i) => `
            <div class="payment-entry d-flex justify-content-between align-items-center">
                <div>
                    <span class="method-label">${methodLabels[p.method] || p.method.replace('_', ' ')}</span>
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
            <input type="hidden" name="pay_moneris_id[]" value="${p.monerisId || ''}">
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
        const hasCash = payments.some(p => p.method === 'cash');
        const remaining = Math.max(0, totalDue - paid);
        // Show nickel-rounded remaining when cash is involved
        const displayRemaining = (hasCash && remaining > 0) ? Math.round(remaining * 20) / 20 : remaining;
        document.getElementById('modalRemaining').textContent = '$' + displayRemaining.toFixed(2);
        document.getElementById('modalRemaining').className = remaining > 0 ? 'text-danger' : 'text-success';

        // Change — nickel-round when any cash payment
        let change = Math.max(0, paid - totalDue);
        if (hasCash && change > 0) {
            change = Math.round(change * 20) / 20;
        }
        const changeDisplay = document.getElementById('changeDisplay');
        if (change > 0) {
            changeDisplay.style.display = 'block';
            document.getElementById('changeAmount').textContent = '$' + change.toFixed(2);
        } else {
            changeDisplay.style.display = 'none';
        }

        // Enable/disable complete button — only nickel-round when ALL payments are cash
        const allCash = payments.length > 0 && payments.every(p => p.method === 'cash');
        const minRequired = allCash ? Math.round(totalDue * 20) / 20 : totalDue;
        document.getElementById('completeSaleBtn').disabled = paid < minRequired;
    }

    function getRemaining() {
        const paid = payments.reduce((sum, p) => sum + p.amount, 0);
        return Math.max(0, Math.round((totalDue - paid) * 100) / 100);
    }

    // Prevent double-submit on Complete Sale
    const completeForm = document.getElementById('completeForm');
    if (completeForm) {
        completeForm.addEventListener('submit', function() {
            const btn = document.getElementById('completeSaleBtn');
            btn.disabled = true;
            btn.textContent = 'Processing…';
        });
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }
})();
