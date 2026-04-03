/**
 * Standalone Refund — modal logic + manager PIN authorization
 */
(function() {
    'use strict';

    var baseUrl = document.querySelector('meta[name="base-url"]')?.content || '/';
    var refundModal, pinModal;
    var managerAuth = null; // {id, username} when authorized

    document.addEventListener('DOMContentLoaded', function() {
        refundModal = new bootstrap.Modal(document.getElementById('standaloneRefundModal'));
        pinModal    = new bootstrap.Modal(document.getElementById('managerPinModal'));

        // Open refund modal
        document.getElementById('refundBtn').addEventListener('click', function() {
            resetRefundModal();
            refundModal.show();
        });

        // Amount input — check threshold
        var amountInput = document.getElementById('refundAmount');
        amountInput.addEventListener('input', function() {
            checkThreshold();
        });

        // Request manager PIN button
        document.getElementById('requestPinBtn').addEventListener('click', function() {
            refundModal.hide();
            resetPinModal();
            pinModal.show();
            setTimeout(function() {
                document.getElementById('managerPinInput').focus();
            }, 300);
        });

        // PIN keypad
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

        // Verify PIN
        document.getElementById('verifyPinBtn').addEventListener('click', verifyPin);
        document.getElementById('managerPinInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') verifyPin();
        });

        // Cancel PIN — go back to refund modal
        document.getElementById('managerPinModal').addEventListener('hidden.bs.modal', function() {
            // Only re-open refund if we didn't just authorize
            if (!managerAuth) {
                refundModal.show();
            }
        });

        // Process refund
        document.getElementById('processRefundBtn').addEventListener('click', processRefund);
    });

    function resetRefundModal() {
        document.getElementById('refundAmount').value = '';
        document.getElementById('refundCustomerName').value = '';
        document.getElementById('refundReason').value = '';
        document.getElementById('refundPaymentMethod').value = 'cash';
        document.getElementById('authBadge').style.display = 'none';
        document.getElementById('requestPinBtn').style.display = 'none';
        document.getElementById('thresholdWarning').style.display = 'none';
        document.getElementById('processRefundBtn').disabled = false;
        managerAuth = null;
    }

    function resetPinModal() {
        document.getElementById('managerPinInput').value = '';
        document.getElementById('pinError').style.display = 'none';
        document.getElementById('verifyPinBtn').disabled = false;
    }

    function checkThreshold() {
        var amount = parseFloat(document.getElementById('refundAmount').value) || 0;
        var threshold = parseFloat(document.getElementById('standaloneRefundModal').dataset.threshold) || 50;
        var needsAuth = amount > threshold;
        var warning = document.getElementById('thresholdWarning');
        var pinBtn  = document.getElementById('requestPinBtn');

        if (needsAuth && !managerAuth) {
            warning.style.display = '';
            pinBtn.style.display = '';
            warning.textContent = 'Refunds over $' + threshold.toFixed(2) + ' require manager authorization.';
        } else {
            warning.style.display = 'none';
            pinBtn.style.display = 'none';
        }
    }

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
                // Success
                managerAuth = { id: data.id, username: data.username };
                pinModal.hide();
                // Show auth badge on refund modal
                document.getElementById('authBadge').textContent = 'Authorized by: ' + data.username;
                document.getElementById('authBadge').style.display = '';
                document.getElementById('thresholdWarning').style.display = 'none';
                document.getElementById('requestPinBtn').style.display = 'none';
                refundModal.show();
            })
            .catch(function() {
                document.getElementById('pinError').textContent = 'Network error. Try again.';
                document.getElementById('pinError').style.display = '';
                btn.disabled = false;
            });
    }

    function processRefund() {
        var amount       = parseFloat(document.getElementById('refundAmount').value) || 0;
        var customerName = document.getElementById('refundCustomerName').value.trim();
        var reason       = document.getElementById('refundReason').value.trim();
        var method       = document.getElementById('refundPaymentMethod').value;
        var threshold    = parseFloat(document.getElementById('standaloneRefundModal').dataset.threshold) || 50;

        // Validate
        if (amount <= 0) {
            alert('Enter a valid refund amount.');
            return;
        }
        if (!customerName) {
            alert('Enter the customer name.');
            return;
        }
        if (!reason) {
            alert('Enter a reason for the refund.');
            return;
        }
        if (amount > threshold && !managerAuth) {
            alert('Manager authorization required for refunds over $' + threshold.toFixed(2) + '.');
            return;
        }

        if (!confirm('Process ' + method.toUpperCase() + ' refund of $' + amount.toFixed(2) + '?')) {
            return;
        }

        var btn = document.getElementById('processRefundBtn');
        btn.disabled = true;
        btn.textContent = 'Processing...';

        var form = new FormData();
        form.append('amount', amount);
        form.append('customer_name', customerName);
        form.append('reason', reason);
        form.append('payment_method', method);
        if (managerAuth) {
            form.append('authorized_by', managerAuth.id);
        }

        fetch(baseUrl + 'api/standalone-refund', { method: 'POST', body: form })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) {
                    alert('Refund failed: ' + data.error);
                    btn.disabled = false;
                    btn.textContent = 'Process Refund';
                    return;
                }

                // Print receipt
                if (data.receipt) {
                    var noDraw = (method === 'card');
                    data.receipt.no_drawer = noDraw;

                    fetch(POS_PRINT_URL + '/print/receipt', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(data.receipt)
                    }).catch(function() { /* print failure is non-blocking */ });

                    // For cash refunds, also kick drawer
                    if (method === 'cash') {
                        fetch(POS_PRINT_URL + '/print/open-drawer', { method: 'POST' })
                            .catch(function() {});
                    }
                }

                refundModal.hide();
                // Show success toast/alert
                var alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-success m-2 mb-0';
                alertDiv.textContent = 'Refund #' + data.refund_id + ' processed: $' + amount.toFixed(2) + ' (' + method + ')';
                var flash = document.querySelector('.pos-header');
                flash.parentNode.insertBefore(alertDiv, flash.nextSibling);
                setTimeout(function() { alertDiv.remove(); }, 5000);
            })
            .catch(function() {
                alert('Network error processing refund.');
                btn.disabled = false;
                btn.textContent = 'Process Refund';
            });
    }
})();
