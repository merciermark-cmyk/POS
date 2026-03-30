/**
 * POS terminal — cart management and product search.
 */
(function() {
    const baseUrl = document.querySelector('meta[name="base-url"]')?.content || '/';
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // ── API helpers ──────────────────────────────────────────────────
    async function apiPost(endpoint, data = {}) {
        const params = new URLSearchParams(data);
        params.append('csrf_token', csrfToken);
        const resp = await fetch(baseUrl + endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        });
        // Reset idle timer on any API activity
        window.resetIdleTimer?.();
        return resp.json();
    }

    // ── Category filter ──────────────────────────────────────────────
    document.querySelectorAll('.category-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.category-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            filterProducts();
        });
    });

    // ── Product search ───────────────────────────────────────────────
    const searchInput = document.getElementById('productSearch');
    if (searchInput) {
        searchInput.addEventListener('input', () => filterProducts());
    }

    function filterProducts() {
        const activeCat = document.querySelector('.category-btn.active')?.dataset.category || '';
        const search = (searchInput?.value || '').toLowerCase().trim();

        document.querySelectorAll('.pos-product-card').forEach(card => {
            const catMatch = !activeCat || card.dataset.category === activeCat;
            const nameMatch = !search ||
                card.dataset.name.includes(search) ||
                card.dataset.code.includes(search);
            card.classList.toggle('hidden', !(catMatch && nameMatch));
        });
    }

    // ── Add to cart ──────────────────────────────────────────────────
    document.querySelectorAll('.pos-product-card').forEach(card => {
        card.addEventListener('click', async function() {
            const productId = this.dataset.id;
            const result = await apiPost('api/cart/add', { product_id: productId, quantity: 1 });
            if (result.error) {
                alert(result.error);
                return;
            }
            renderCart(result);
            updatePoleDisplay(result);
        });
    });

    // ── Cart rendering ───────────────────────────────────────────────
    function renderCart(data) {
        const container = document.getElementById('cartItems');
        const items = data.items || [];

        if (items.length === 0) {
            container.innerHTML = '<div class="pos-cart-empty" id="cartEmpty"><p class="text-muted">Cart is empty</p></div>';
        } else {
            container.innerHTML = items.map(item => `
                <div class="pos-cart-item" data-product-id="${item.product_id}">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="fw-bold">${escHtml(item.product_name)}</div>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <button class="btn btn-sm btn-outline-secondary qty-btn" data-action="decrease"
                                        data-product-id="${item.product_id}">\u2212</button>
                                <span class="qty-display">${item.quantity}</span>
                                <button class="btn btn-sm btn-outline-secondary qty-btn" data-action="increase"
                                        data-product-id="${item.product_id}">+</button>
                                <span class="text-muted ms-2">\u00d7 $${parseFloat(item.unit_price).toFixed(2)}</span>
                            </div>
                            ${item.gst > 0 ? `<small class="text-muted">GST: $${parseFloat(item.gst).toFixed(2)}</small>` : ''}
                            ${item.pst > 0 ? `<small class="text-muted ms-2">PST: $${parseFloat(item.pst).toFixed(2)}</small>` : ''}
                        </div>
                        <div class="text-end">
                            <div class="fw-bold">$${parseFloat(item.line_total).toFixed(2)}</div>
                            <button class="btn btn-sm btn-outline-danger mt-1 remove-btn"
                                    data-product-id="${item.product_id}">\u00d7</button>
                        </div>
                    </div>
                </div>
            `).join('');
        }

        // Update totals
        document.getElementById('cartSubtotal').textContent = '$' + parseFloat(data.subtotal).toFixed(2);
        document.getElementById('cartGst').textContent = '$' + parseFloat(data.gst).toFixed(2);
        document.getElementById('cartPst').textContent = '$' + parseFloat(data.pst).toFixed(2);
        document.getElementById('cartTotal').textContent = '$' + parseFloat(data.total).toFixed(2);
        document.getElementById('cartCount').textContent = items.length;

        const payBtn = document.getElementById('payBtn');
        if (payBtn) payBtn.disabled = items.length === 0;

        // Update wholesale button state
        if (typeof data.wholesale !== 'undefined') {
            updateWholesaleBtn(data.wholesale);
        }

        // Rebind cart item buttons
        bindCartButtons();
    }

    // ── Wholesale toggle ──────────────────────────────────────────────
    function updateWholesaleBtn(active) {
        const btn = document.getElementById('wholesaleToggle');
        if (!btn) return;
        if (active) {
            btn.className = 'btn btn-sm btn-purple';
            btn.innerHTML = 'WHOLESALE <span class="badge bg-light text-purple">-25%</span>';
        } else {
            btn.className = 'btn btn-sm btn-outline-purple';
            btn.textContent = 'WHOLESALE';
        }
    }

    const wholesaleBtn = document.getElementById('wholesaleToggle');
    if (wholesaleBtn) {
        wholesaleBtn.addEventListener('click', async function() {
            const result = await apiPost('api/wholesale/toggle');
            if (!result.error) {
                renderCart(result);
            }
        });
    }

    function bindCartButtons() {
        // Quantity buttons
        document.querySelectorAll('.qty-btn').forEach(btn => {
            btn.addEventListener('click', async function(e) {
                e.stopPropagation();
                const productId = this.dataset.productId;
                const action = this.dataset.action;
                const qtyEl = this.parentElement.querySelector('.qty-display');
                let qty = parseInt(qtyEl.textContent);

                qty = action === 'increase' ? qty + 1 : qty - 1;
                const result = await apiPost('api/cart/update', { product_id: productId, quantity: qty });
                renderCart(result);
            });
        });

        // Remove buttons
        document.querySelectorAll('.remove-btn').forEach(btn => {
            btn.addEventListener('click', async function(e) {
                e.stopPropagation();
                const productId = this.dataset.productId;
                const result = await apiPost('api/cart/remove', { product_id: productId });
                renderCart(result);
            });
        });
    }

    // Initial binding
    bindCartButtons();

    // ── Clear cart ───────────────────────────────────────────────────
    const clearBtn = document.getElementById('clearCartBtn');
    if (clearBtn) {
        clearBtn.addEventListener('click', async function() {
            if (!confirm('Clear cart?')) return;
            const result = await apiPost('api/cart/clear');
            renderCart(result);
        });
    }

    // ── Pay button → open payment modal ──────────────────────────────
    const payBtn = document.getElementById('payBtn');
    if (payBtn) {
        payBtn.addEventListener('click', function() {
            const total = parseFloat(document.getElementById('cartTotal').textContent.replace('$', ''));
            if (total <= 0) return;
            if (typeof openPaymentModal === 'function') {
                openPaymentModal(total);
            }
        });
    }

    // ── Local print service (runs on POS terminal at localhost:5000) ──
    const PRINT_SERVICE = 'http://localhost:5000';

    function localPrint(endpoint, data) {
        fetch(PRINT_SERVICE + endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        }).catch(() => {});
    }

    // ── Pole display ─────────────────────────────────────────────────
    function updatePoleDisplay(cartData) {
        const items = cartData.items || [];
        if (items.length === 0) return;
        const last = items[items.length - 1];
        const line1 = last.product_name.substring(0, 20);
        const line2 = '$' + parseFloat(cartData.total).toFixed(2);

        localPrint('/pole-display', { line1, line2 });
    }

    // ── Utility ──────────────────────────────────────────────────────
    function escHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // Expose for payment.js
    window.POS = { apiPost, renderCart, baseUrl, csrfToken, localPrint };
})();
