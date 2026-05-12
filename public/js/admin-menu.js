/**
 * Admin Menu — PIN-gated access to admin functions + petty cash.
 */
(function() {
    'use strict';

    var baseUrl = document.querySelector('meta[name="base-url"]')?.content || '/';
    var adminModal, pinModal, pettyCashModal, giftCardSaleModal;
    var adminAuth = null; // {id, username} after PIN verified

    document.addEventListener('DOMContentLoaded', function() {
        adminModal    = new bootstrap.Modal(document.getElementById('adminMenuModal'));
        pinModal      = new bootstrap.Modal(document.getElementById('managerPinModal'));
        pettyCashModal = new bootstrap.Modal(document.getElementById('pettyCashModal'));
        giftCardSaleModal = new bootstrap.Modal(document.getElementById('giftCardSaleModal'));

        // Admin button → open menu directly (no PIN required)
        document.getElementById('adminMenuBtn').addEventListener('click', function() {
            adminAuth = null;
            document.getElementById('adminAuthBadge').style.display = 'none';
            adminModal.show();
        });

        // Admin menu → Refund
        document.getElementById('adminRefundBtn').addEventListener('click', function() {
            adminModal.hide();
            if (window.openStandaloneRefund) {
                window.openStandaloneRefund(adminAuth);
            }
        });

        // Admin menu → Petty Cash
        document.getElementById('adminPettyCashBtn').addEventListener('click', function() {
            adminModal.hide();
            loadPettyCashEntries();
            pettyCashModal.show();
        });

        // Add petty cash entry
        document.getElementById('addPettyCashBtn').addEventListener('click', addPettyCashEntry);

        // Admin menu → Gift Card Sale
        document.getElementById('adminGiftCardSaleBtn').addEventListener('click', function() {
            adminModal.hide();
            loadGiftCardSaleEntries();
            giftCardSaleModal.show();
        });

        // Add gift card sale entry
        document.getElementById('addGiftCardSaleBtn').addEventListener('click', addGiftCardSaleEntry);

        // PIN keypad buttons
        document.querySelectorAll('.pin-keypad-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var key = this.dataset.key;
                var input = document.getElementById('managerPinInput');
                if (key === 'clear') {
                    input.value = '';
                } else if (key === 'backspace') {
                    input.value = input.value.slice(0, -1);
                } else {
                    input.value += key;
                }
                document.getElementById('pinError').style.display = 'none';
            });
        });

        // Verify PIN button + Enter key
        document.getElementById('verifyPinBtn').addEventListener('click', verifyPin);
        document.getElementById('managerPinInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') verifyPin();
        });

        // PIN modal cancelled
        document.getElementById('managerPinModal').addEventListener('hidden.bs.modal', function() {
            if (!adminAuth && pinCancelCallback) {
                pinCancelCallback();
                pinCancelCallback = null;
            }
        });
    });

    // -- Manager PIN shared interface --
    var pinSuccessCallback = null;
    var pinCancelCallback  = null;

    function showManagerPinModal(onSuccess, onCancel) {
        pinSuccessCallback = onSuccess || null;
        pinCancelCallback  = onCancel || null;
        document.getElementById('managerPinInput').value = '';
        document.getElementById('pinError').style.display = 'none';
        document.getElementById('verifyPinBtn').disabled = false;
        pinModal.show();
        setTimeout(function() {
            document.getElementById('managerPinInput').focus();
        }, 300);
    }

    // Expose for standalone-refund.js
    window.showManagerPinModal = showManagerPinModal;

    function verifyPin() {
        var pin = document.getElementById('managerPinInput').value.trim();
        if (!pin) return;

        var btn = document.getElementById('verifyPinBtn');
        btn.disabled = true;

        var form = new FormData();
        form.append('pin', pin);

        fetch(baseUrl + 'api/verify-manager-pin', { method: 'POST', body: form })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) {
                    document.getElementById('pinError').textContent = data.error;
                    document.getElementById('pinError').style.display = '';
                    btn.disabled = false;
                    document.getElementById('managerPinInput').value = '';
                    document.getElementById('managerPinInput').focus();
                    return;
                }
                adminAuth = { id: data.id, username: data.username };
                pinModal.hide();
                if (pinSuccessCallback) {
                    pinSuccessCallback(adminAuth);
                    pinSuccessCallback = null;
                    pinCancelCallback = null;
                }
            })
            .catch(function() {
                document.getElementById('pinError').textContent = 'Network error. Try again.';
                document.getElementById('pinError').style.display = '';
                btn.disabled = false;
            });
    }

    // -- Petty Cash --
    function loadPettyCashEntries() {
        fetch(baseUrl + 'api/petty-cash/list')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) return;
                renderPettyCashList(data);
            })
            .catch(function() {});
    }

    function renderPettyCashList(data) {
        var list  = document.getElementById('pettyCashList');
        var empty = document.getElementById('pettyCashEmpty');
        var total = document.getElementById('pettyCashRunningTotal');

        total.textContent = '$' + (data.total || 0).toFixed(2);

        if (!data.entries || data.entries.length === 0) {
            list.innerHTML = '';
            list.appendChild(empty);
            empty.style.display = '';
            return;
        }

        if (empty) empty.style.display = 'none';

        var html = '<table class="table table-sm mb-0"><thead><tr><th>Time</th><th>Description</th><th class="text-end">Amount</th><th>By</th></tr></thead><tbody>';
        data.entries.forEach(function(entry) {
            var time = entry.created_at ? new Date(entry.created_at.replace(' ', 'T')).toLocaleTimeString([], {hour:'numeric', minute:'2-digit'}) : '';
            html += '<tr>'
                + '<td class="small">' + time + '</td>'
                + '<td>' + escapeHtml(entry.description) + '</td>'
                + '<td class="text-end">$' + parseFloat(entry.amount).toFixed(2) + '</td>'
                + '<td class="small">' + escapeHtml(entry.user_name || '') + '</td>'
                + '</tr>';
        });
        html += '</tbody></table>';
        list.innerHTML = html;
    }

    function addPettyCashEntry() {
        var amount = parseFloat(document.getElementById('pettyCashAmount').value) || 0;
        var description = document.getElementById('pettyCashDescription').value.trim();

        if (amount <= 0) {
            alert('Enter a valid amount.');
            return;
        }
        if (!description) {
            alert('Enter a description.');
            return;
        }

        var btn = document.getElementById('addPettyCashBtn');
        btn.disabled = true;

        var form = new FormData();
        form.append('amount', amount);
        form.append('description', description);
        if (adminAuth) {
            form.append('authorized_by', adminAuth.id);
        }

        fetch(baseUrl + 'api/petty-cash/add', { method: 'POST', body: form })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                if (data.error) {
                    alert('Error: ' + data.error);
                    return;
                }
                // Clear inputs
                document.getElementById('pettyCashAmount').value = '';
                document.getElementById('pettyCashDescription').value = '';
                // Reload list
                loadPettyCashEntries();
            })
            .catch(function() {
                alert('Network error.');
                btn.disabled = false;
            });
    }

    // -- Gift Card Sales --
    function loadGiftCardSaleEntries() {
        fetch(baseUrl + 'api/gift-card-sales/list')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) return;
                renderGiftCardSaleList(data);
            })
            .catch(function() {});
    }

    function renderGiftCardSaleList(data) {
        var list  = document.getElementById('gcSaleList');
        var empty = document.getElementById('gcSaleEmpty');
        var total = document.getElementById('gcSaleRunningTotal');
        var cardTotal = document.getElementById('gcSaleCardTotal');
        var cashTotal = document.getElementById('gcSaleCashTotal');

        total.textContent = '$' + (data.total || 0).toFixed(2);
        cardTotal.textContent = '$' + (data.card_total || 0).toFixed(2);
        cashTotal.textContent = '$' + (data.cash_total || 0).toFixed(2);

        if (!data.entries || data.entries.length === 0) {
            list.innerHTML = '';
            list.appendChild(empty);
            empty.style.display = '';
            return;
        }

        if (empty) empty.style.display = 'none';

        var html = '<table class="table table-sm mb-0"><thead><tr><th>Time</th><th>Method</th><th class="text-end">Amount</th><th>Notes</th><th>By</th></tr></thead><tbody>';
        data.entries.forEach(function(entry) {
            var time = entry.created_at ? new Date(entry.created_at.replace(' ', 'T')).toLocaleTimeString([], {hour:'numeric', minute:'2-digit'}) : '';
            var methodBadge = entry.payment_method === 'card'
                ? '<span class="badge bg-primary">Card</span>'
                : '<span class="badge bg-success">Cash</span>';
            html += '<tr>'
                + '<td class="small">' + time + '</td>'
                + '<td>' + methodBadge + '</td>'
                + '<td class="text-end">$' + parseFloat(entry.amount).toFixed(2) + '</td>'
                + '<td class="small">' + escapeHtml(entry.notes || '') + '</td>'
                + '<td class="small">' + escapeHtml(entry.user_name || '') + '</td>'
                + '</tr>';
        });
        html += '</tbody></table>';
        list.innerHTML = html;
    }

    function addGiftCardSaleEntry() {
        var amount = parseFloat(document.getElementById('gcSaleAmount').value) || 0;
        var method = document.querySelector('input[name="gcSaleMethod"]:checked').value;
        var notes  = document.getElementById('gcSaleNotes').value.trim();

        if (amount <= 0) {
            alert('Enter a valid amount.');
            return;
        }

        var btn = document.getElementById('addGiftCardSaleBtn');
        btn.disabled = true;

        var form = new FormData();
        form.append('amount', amount);
        form.append('payment_method', method);
        form.append('notes', notes);

        fetch(baseUrl + 'api/gift-card-sales/add', { method: 'POST', body: form })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                if (data.error) {
                    alert('Error: ' + data.error);
                    return;
                }
                // Clear inputs
                document.getElementById('gcSaleAmount').value = '';
                document.getElementById('gcSaleNotes').value = '';
                document.getElementById('gcSaleCard').checked = true;
                // Reload list
                loadGiftCardSaleEntries();
            })
            .catch(function() {
                alert('Network error.');
                btn.disabled = false;
            });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }
})();
