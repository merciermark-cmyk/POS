/**
 * POS terminal — cart management, product search, beverage modifier modal, and quantity keypad.
 */
(function() {
    const baseUrl = document.querySelector('meta[name="base-url"]')?.content || '/';
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // Beverage modifier globals (set in terminal.php)
    const beverageCatId = window.POS_BEVERAGE_CAT_ID || null;
    const modifiers = window.POS_MODIFIERS || [];

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

    // ── Utility ──────────────────────────────────────────────────────
    function escHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    /** Format quantity: 6.5 → "6.5", 1.00 → "1", 0.50 → "0.5" */
    function fmtQty(n) {
        const f = parseFloat(n);
        if (Number.isInteger(f)) return f.toString();
        // Remove trailing zeros: 6.50 → 6.5
        return parseFloat(f.toFixed(2)).toString();
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

    // ── Modifier modal state ─────────────────────────────────────────
    let modModalProduct = null;      // { id, name, price }
    let modModalSelected = [];       // [{ id, name, price, qty }]
    let modModalInstance = null;

    function openModifierModal(productId, productName, productPrice) {
        modModalProduct = { id: productId, name: productName, price: productPrice };
        modModalSelected = [];

        document.getElementById('modifierModalTitle').textContent = productName;
        renderModifierButtons();
        renderSelectedModifiers();

        if (!modModalInstance) {
            modModalInstance = new bootstrap.Modal(document.getElementById('modifierModal'));
        }
        modModalInstance.show();
    }

    function renderModifierButtons() {
        const container = document.getElementById('modifierButtons');
        container.innerHTML = modifiers.map(m => `
            <button class="btn btn-outline-info btn-lg mod-select-btn"
                    data-mod-id="${m.id}" data-mod-name="${escHtml(m.name)}" data-mod-price="${m.price}">
                ${escHtml(m.name)} +$${parseFloat(m.price).toFixed(2)}
            </button>
        `).join('');

        container.querySelectorAll('.mod-select-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const modId = parseInt(this.dataset.modId);
                const existing = modModalSelected.find(s => s.id === modId);
                if (existing) {
                    existing.qty++;
                } else {
                    modModalSelected.push({
                        id: modId,
                        name: this.dataset.modName,
                        price: parseFloat(this.dataset.modPrice),
                        qty: 1
                    });
                }
                renderSelectedModifiers();
            });
        });
    }

    function renderSelectedModifiers() {
        const container = document.getElementById('modifierSelected');

        if (modModalSelected.length === 0) {
            container.innerHTML = '<p class="text-muted" id="noModsMsg">None (plain)</p>';
        } else {
            container.innerHTML = modModalSelected.map((m, idx) => `
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span>
                        ${escHtml(m.name)} ${m.qty > 1 ? '&times; ' + m.qty : ''}
                        <small class="text-muted">+$${(m.price * m.qty).toFixed(2)}</small>
                    </span>
                    <button class="btn btn-sm btn-outline-danger mod-remove-btn" data-idx="${idx}">&times;</button>
                </div>
            `).join('');

            container.querySelectorAll('.mod-remove-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const idx = parseInt(this.dataset.idx);
                    modModalSelected.splice(idx, 1);
                    renderSelectedModifiers();
                });
            });
        }

        // Update item total preview
        let total = modModalProduct ? modModalProduct.price : 0;
        modModalSelected.forEach(m => { total += m.price * m.qty; });
        document.getElementById('modifierItemTotal').textContent = '$' + total.toFixed(2);
    }

    // Add Plain button
    document.getElementById('addPlainBtn')?.addEventListener('click', async function() {
        if (!modModalProduct) return;
        modModalInstance?.hide();
        const result = await apiPost('api/cart/add', {
            product_id: modModalProduct.id,
            quantity: 1
        });
        if (!result.error) {
            renderCart(result);
            updatePoleDisplay(result);
        }
    });

    // Add to Cart with modifiers
    document.getElementById('addWithModsBtn')?.addEventListener('click', async function() {
        if (!modModalProduct) return;
        modModalInstance?.hide();
        const data = {
            product_id: modModalProduct.id,
            quantity: 1
        };
        if (modModalSelected.length > 0) {
            data.modifiers = JSON.stringify(modModalSelected);
        }
        const result = await apiPost('api/cart/add', data);
        if (!result.error) {
            renderCart(result);
            updatePoleDisplay(result);
        }
    });

    // ── Add to cart (product click) ──────────────────────────────────
    document.querySelectorAll('.pos-product-card').forEach(card => {
        card.addEventListener('click', async function() {
            const productId = this.dataset.id;
            const productCat = this.dataset.category;
            const productName = this.querySelector('.pos-product-name')?.textContent || '';
            const productPrice = parseFloat(this.dataset.price) || 0;

            // If beverage category and modifiers exist, open modal
            if (beverageCatId && productCat === String(beverageCatId) && modifiers.length > 0) {
                openModifierModal(productId, productName, productPrice);
                return;
            }

            // Otherwise direct add
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
        const isWholesale = !!data.wholesale;
        const isCartDiscount = !!data.cart_discount;

        if (items.length === 0) {
            container.innerHTML = '<div class="pos-cart-empty" id="cartEmpty"><p class="text-muted">Cart is empty</p></div>';
        } else {
            container.innerHTML = items.map(item => {
                const cartKey = item.cart_key || String(item.product_id);
                const displayPrice = item.effective_unit_price != null ? item.effective_unit_price : item.unit_price;
                const mods = item.modifiers || [];
                const hasDiscount = (item.discount_percent || 0) > 0;

                let modsHtml = '';
                if (mods.length > 0) {
                    modsHtml = mods.map(m =>
                        `<div class="text-muted small ms-2">+ ${escHtml(m.name)} ($${parseFloat(m.price).toFixed(2)}${m.qty > 1 ? ' \u00d7' + m.qty : ''})</div>`
                    ).join('');
                }

                // Price display: show strikethrough original + discounted price when discount active
                let priceHtml;
                if (hasDiscount) {
                    const rawPrice = parseFloat(item.unit_price) + (mods.reduce((s,m) => s + parseFloat(m.price) * (m.qty||1), 0));
                    priceHtml = `<span class="discount-strike">$${rawPrice.toFixed(2)}</span> <span class="text-teal">$${parseFloat(displayPrice).toFixed(2)}</span>`;
                } else {
                    priceHtml = `$${parseFloat(displayPrice).toFixed(2)}`;
                }

                // Per-item discount button (disabled when wholesale active)
                const discBtnClass = hasDiscount ? 'btn-teal' : 'btn-outline-teal';
                const discBtnDisabled = isWholesale ? 'disabled' : '';
                const discountBtn = `<button class="btn btn-sm ${discBtnClass} item-discount-btn ms-1" ${discBtnDisabled}
                    data-cart-key="${escHtml(cartKey)}" title="Toggle 10% discount">%</button>`;

                return `
                <div class="pos-cart-item" data-cart-key="${escHtml(cartKey)}"
                     data-product-name="${escHtml(item.product_name)}"
                     data-unit-price="${parseFloat(displayPrice)}">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="fw-bold">${escHtml(item.product_name)}${hasDiscount ? ' <span class="badge bg-teal discount-badge text-white">-10%</span>' : ''}</div>
                            ${modsHtml}
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <button class="btn btn-sm btn-outline-secondary qty-btn" data-action="decrease"
                                        data-cart-key="${escHtml(cartKey)}">\u2212</button>
                                <span class="qty-display" data-cart-key="${escHtml(cartKey)}">${fmtQty(item.quantity)}</span>
                                <button class="btn btn-sm btn-outline-secondary qty-btn" data-action="increase"
                                        data-cart-key="${escHtml(cartKey)}">+</button>
                                <span class="text-muted ms-2">\u00d7 ${priceHtml}</span>
                            </div>
                            ${item.gst > 0 ? `<small class="text-muted">GST: $${parseFloat(item.gst).toFixed(2)}</small>` : ''}
                            ${item.pst > 0 ? `<small class="text-muted ms-2">PST: $${parseFloat(item.pst).toFixed(2)}</small>` : ''}
                        </div>
                        <div class="text-end">
                            <div class="fw-bold">$${parseFloat(item.line_total).toFixed(2)}</div>
                            <div class="d-flex justify-content-end mt-1">
                                ${discountBtn}
                                <button class="btn btn-sm btn-outline-danger ms-1 remove-btn"
                                        data-cart-key="${escHtml(cartKey)}">\u00d7</button>
                            </div>
                        </div>
                    </div>
                </div>
            `}).join('');
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

        // Update discount button state
        updateDiscountBtn(isCartDiscount, isWholesale);

        // Rebind cart item buttons
        bindCartButtons();
    }

    // ── Discount toggle ───────────────────────────────────────────────
    function updateDiscountBtn(active, wholesaleActive) {
        const btn = document.getElementById('discountToggle');
        if (!btn) return;
        btn.disabled = wholesaleActive;
        if (active) {
            btn.className = 'btn btn-sm btn-teal';
            btn.innerHTML = '10% OFF <span class="badge bg-light text-teal">-10%</span>';
        } else {
            btn.className = 'btn btn-sm btn-outline-teal';
            btn.textContent = '10% OFF';
        }
    }

    const discountBtn = document.getElementById('discountToggle');
    if (discountBtn) {
        discountBtn.addEventListener('click', async function() {
            const result = await apiPost('api/discount/toggle');
            if (!result.error) {
                renderCart(result);
            }
        });
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
        // Quantity +/- buttons (use cart_key)
        document.querySelectorAll('.qty-btn').forEach(btn => {
            btn.addEventListener('click', async function(e) {
                e.stopPropagation();
                const cartKey = this.dataset.cartKey;
                const action = this.dataset.action;
                const qtyEl = this.parentElement.querySelector('.qty-display');
                let qty = parseFloat(qtyEl.textContent);

                qty = action === 'increase' ? qty + 1 : qty - 1;
                const result = await apiPost('api/cart/update', { cart_key: cartKey, quantity: qty });
                renderCart(result);
            });
        });

        // Remove buttons (use cart_key)
        document.querySelectorAll('.remove-btn').forEach(btn => {
            btn.addEventListener('click', async function(e) {
                e.stopPropagation();
                const cartKey = this.dataset.cartKey;
                const result = await apiPost('api/cart/remove', { cart_key: cartKey });
                renderCart(result);
            });
        });

        // Per-item discount buttons
        document.querySelectorAll('.item-discount-btn').forEach(btn => {
            btn.addEventListener('click', async function(e) {
                e.stopPropagation();
                const cartKey = this.dataset.cartKey;
                const result = await apiPost('api/discount/item', { cart_key: cartKey });
                if (!result.error) {
                    renderCart(result);
                }
            });
        });

        // Qty display click → open keypad
        document.querySelectorAll('.qty-display').forEach(el => {
            el.addEventListener('click', function(e) {
                e.stopPropagation();
                const cartItem = this.closest('.pos-cart-item');
                const cartKey = cartItem.dataset.cartKey;
                const productName = cartItem.dataset.productName;
                const unitPrice = parseFloat(cartItem.dataset.unitPrice) || 0;
                const currentQty = parseFloat(this.textContent) || 1;
                openQtyKeypad(cartKey, productName, unitPrice, currentQty);
            });
        });
    }

    // Initial binding
    bindCartButtons();

    // ── Quantity Keypad Modal ─────────────────────────────────────────
    let qtyKeypadState = {
        cartKey: null,
        productName: '',
        unitPrice: 0,
        currentQty: 1,
        input: '',
        mode: 'qty',  // 'qty' or 'dollar'
        instance: null
    };

    function openQtyKeypad(cartKey, productName, unitPrice, currentQty) {
        qtyKeypadState.cartKey = cartKey;
        qtyKeypadState.productName = productName;
        qtyKeypadState.unitPrice = unitPrice;
        qtyKeypadState.currentQty = currentQty;
        qtyKeypadState.input = '';
        qtyKeypadState.mode = 'qty';

        document.getElementById('qtyKeypadTitle').textContent = productName;
        setKeypadMode('qty');
        updateKeypadDisplay();

        if (!qtyKeypadState.instance) {
            qtyKeypadState.instance = new bootstrap.Modal(document.getElementById('qtyKeypadModal'));
        }
        qtyKeypadState.instance.show();
    }

    function setKeypadMode(mode) {
        qtyKeypadState.mode = mode;
        qtyKeypadState.input = '';

        // Toggle button active state
        document.querySelectorAll('.qty-mode-btn').forEach(btn => {
            if (btn.dataset.mode === mode) {
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-primary', 'active');
            } else {
                btn.classList.remove('btn-primary', 'active');
                btn.classList.add('btn-outline-primary');
            }
        });

        // Show/hide quick dollar buttons
        const quickDollars = document.getElementById('qtyQuickDollars');
        if (quickDollars) {
            quickDollars.style.cssText = mode === 'dollar' ? '' : 'display:none !important';
        }

        updateKeypadDisplay();
    }

    function updateKeypadDisplay() {
        const inputEl = document.getElementById('qtyKeypadInput');
        const previewEl = document.getElementById('qtyKeypadPreview');
        const input = qtyKeypadState.input || '0';

        if (qtyKeypadState.mode === 'dollar') {
            inputEl.textContent = '$' + input;
            const dollars = parseFloat(input) || 0;
            if (dollars > 0 && qtyKeypadState.unitPrice > 0) {
                const calcQty = Math.round((dollars / qtyKeypadState.unitPrice) * 100) / 100;
                previewEl.textContent = '$' + dollars.toFixed(2) + ' \u00f7 $' + qtyKeypadState.unitPrice.toFixed(2) + ' = ' + fmtQty(calcQty) + ' units';
                previewEl.style.display = '';
            } else {
                previewEl.style.display = 'none';
            }
        } else {
            inputEl.textContent = input;
            previewEl.style.display = 'none';
        }
    }

    function handleKeypadKey(key) {
        let input = qtyKeypadState.input;

        switch (key) {
            case '0': case '1': case '2': case '3': case '4':
            case '5': case '6': case '7': case '8': case '9':
                // Limit decimal places to 2
                if (input.includes('.') && input.split('.')[1].length >= 2) return;
                if (input === '0') input = key; // replace leading zero
                else input += key;
                break;

            case '.':
                if (input.includes('.')) return;
                if (input === '' || input === '0') input = '0.';
                else input += '.';
                break;

            case 'half':
                input = '0.5';
                break;

            case 'backspace':
                input = input.slice(0, -1);
                break;

            case 'clear':
                input = '';
                break;

            case 'confirm':
                confirmKeypad();
                return;
        }

        qtyKeypadState.input = input;
        updateKeypadDisplay();
    }

    async function confirmKeypad() {
        let qty;
        const input = qtyKeypadState.input;
        const value = parseFloat(input) || 0;

        if (qtyKeypadState.mode === 'dollar') {
            if (value <= 0 || qtyKeypadState.unitPrice <= 0) return;
            qty = Math.round((value / qtyKeypadState.unitPrice) * 100) / 100;
            if (qty < 0.01) {
                alert('Amount too small — quantity rounds to 0.');
                return;
            }
        } else {
            qty = value;
            if (qty < 0.01) return;
        }

        qtyKeypadState.instance?.hide();
        const result = await apiPost('api/cart/update', {
            cart_key: qtyKeypadState.cartKey,
            quantity: qty
        });
        renderCart(result);
    }

    // Bind keypad buttons
    document.querySelectorAll('.qty-keypad-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            handleKeypadKey(this.dataset.key);
        });
    });

    // Bind mode toggle
    document.querySelectorAll('.qty-mode-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            setKeypadMode(this.dataset.mode);
        });
    });

    // Bind quick dollar buttons
    document.querySelectorAll('.quick-dollar-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            qtyKeypadState.input = this.dataset.amount;
            updateKeypadDisplay();
        });
    });

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

    // Expose for payment.js
    window.POS = { apiPost, renderCart, baseUrl, csrfToken, localPrint };
})();
