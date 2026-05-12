/**
 * POS terminal — cart management, product search, beverage modifier modal, and quantity keypad.
 */
(function() {
    const baseUrl = document.querySelector('meta[name="base-url"]')?.content || '/';
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // Category tree + modifier globals (set in terminal.php)
    const categoryTree = window.POS_CATEGORY_TREE || [];
    const beverageCatIds = window.POS_BEVERAGE_CAT_IDS || [];
    const looseTeaCatIds = window.POS_LOOSE_TEA_CAT_IDS || [];
    const modifiersByGroup = window.POS_MODIFIERS || {};

    /** Determine which modifier group a category belongs to, or null */
    function getModifierGroup(categoryId) {
        const catId = parseInt(categoryId);
        if (beverageCatIds.includes(catId) && modifiersByGroup.beverage?.length) return 'beverage';
        if (looseTeaCatIds.includes(catId) && modifiersByGroup.loose_tea?.length) return 'loose_tea';
        return null;
    }

    // Track active subcategory filter
    let activeSubCategory = null;

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

    // ── Category filter (two-level) ──────────────────────────────────
    function buildSubCategoryRow(parentId) {
        const subRow = document.getElementById('subCategoryRow');
        if (!subRow) return;

        // Find parent in tree
        const parent = categoryTree.find(c => String(c.id) === String(parentId));
        if (!parent || !parent.children || parent.children.length === 0) {
            subRow.style.display = 'none';
            activeSubCategory = null;
            return;
        }

        let html = '<button class="btn btn-primary sub-cat-btn active" data-subcategory="">All ' + escHtml(parent.name) + '</button>';
        parent.children.forEach(child => {
            html += '<button class="btn btn-outline-primary sub-cat-btn" data-subcategory="' + child.id + '">' + escHtml(child.name) + '</button>';
        });
        subRow.innerHTML = html;
        subRow.style.display = '';
        activeSubCategory = null;

        // Bind subcategory clicks
        subRow.querySelectorAll('.sub-cat-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                subRow.querySelectorAll('.sub-cat-btn').forEach(b => {
                    b.classList.remove('active', 'btn-primary');
                    b.classList.add('btn-outline-primary');
                });
                this.classList.remove('btn-outline-primary');
                this.classList.add('active', 'btn-primary');
                activeSubCategory = this.dataset.subcategory || null;
                filterProducts();
            });
        });
    }

    document.querySelectorAll('.parent-cat-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.parent-cat-btn').forEach(b => {
                b.classList.remove('active');
            });
            this.classList.add('active');

            const parentId = this.dataset.category;
            const hasChildren = this.dataset.hasChildren === '1';

            if (parentId && hasChildren) {
                buildSubCategoryRow(parentId);
            } else {
                document.getElementById('subCategoryRow').style.display = 'none';
                activeSubCategory = null;
            }
            filterProducts();
        });
    });

    // ── Product search (created via JS to prevent browser autofill) ──
    const searchWrap = document.getElementById('pqw');
    const searchForm = document.createElement('form');
    searchForm.autocomplete = 'off';
    searchForm.setAttribute('role', 'presentation');
    searchForm.addEventListener('submit', e => e.preventDefault());
    searchForm.style.cssText = 'flex:1;display:flex';
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.name = 'pqw_f';
    searchInput.id = 'productSearch';
    searchInput.className = 'form-control';
    searchInput.placeholder = 'Search products...';
    searchInput.autocomplete = 'one-time-code';
    searchInput.setAttribute('data-lpignore', 'true');
    searchInput.setAttribute('data-1p-ignore', 'true');
    const searchClear = document.createElement('button');
    searchClear.type = 'button';
    searchClear.innerHTML = '&times;';
    searchClear.className = 'btn btn-outline-secondary';
    searchClear.style.cssText = 'display:none;font-size:1.3rem;line-height:1;padding:0.25rem 0.75rem;margin-left:-1px;border-top-left-radius:0;border-bottom-left-radius:0';
    searchClear.addEventListener('click', () => {
        searchInput.value = '';
        searchClear.style.display = 'none';
        searchInput.style.borderTopRightRadius = '';
        searchInput.style.borderBottomRightRadius = '';
        filterProducts();
        searchInput.focus();
    });
    searchForm.appendChild(searchInput);
    searchForm.appendChild(searchClear);
    searchWrap.appendChild(searchForm);
    searchInput.addEventListener('input', () => {
        const hasText = searchInput.value.length > 0;
        searchClear.style.display = hasText ? '' : 'none';
        searchInput.style.borderTopRightRadius = hasText ? '0' : '';
        searchInput.style.borderBottomRightRadius = hasText ? '0' : '';
        filterProducts();
    });

    function filterProducts() {
        const activeParent = document.querySelector('.parent-cat-btn.active')?.dataset.category || '';
        const search = (searchInput?.value || '').toLowerCase().trim();

        document.querySelectorAll('.pos-product-card').forEach(card => {
            let catMatch;
            if (!activeParent) {
                // "All" — show everything
                catMatch = true;
            } else if (activeSubCategory) {
                // Specific subcategory selected
                catMatch = card.dataset.category === activeSubCategory;
            } else {
                // Parent selected (no subcategory filter) — match parent_category_id
                catMatch = card.dataset.parentCategory === activeParent;
            }

            const nameMatch = !search ||
                card.dataset.name.includes(search) ||
                card.dataset.code.includes(search);
            card.classList.toggle('hidden', !(catMatch && nameMatch));
        });
    }

    // ── PLU keypad ────────────────────────────────────────────────────
    let pluKeypadInput = '';
    let pluKeypadInstance = null;
    const pluDisplay = document.getElementById('pluKeypadDisplay');
    const pluBtn = document.getElementById('pluBtn');

    function updatePluDisplay() {
        if (pluDisplay) pluDisplay.textContent = pluKeypadInput || '_';
    }

    async function submitPlu() {
        const plu = pluKeypadInput.trim().toLowerCase();
        if (!plu) return;

        const card = document.querySelector('.pos-product-card[data-code="' + CSS.escape(plu) + '"]');
        if (!card) {
            if (pluDisplay) {
                pluDisplay.classList.add('text-danger');
                pluDisplay.textContent = 'Not found';
                setTimeout(() => { pluDisplay.classList.remove('text-danger'); pluKeypadInput = ''; updatePluDisplay(); }, 1000);
            }
            return;
        }

        pluKeypadInput = '';
        updatePluDisplay();
        pluKeypadInstance?.hide();

        const productId = card.dataset.id;
        const productCat = card.dataset.category;
        const productName = card.querySelector('.pos-product-name')?.textContent || '';
        const productPrice = parseFloat(card.dataset.price) || 0;

        const pluGroup = getModifierGroup(productCat);
        if (pluGroup === 'beverage') {
            openModifierModal(productId, productName, productPrice, 'beverage');
            return;
        }

        const data = { product_id: productId, quantity: 1 };
        if (pluGroup === 'loose_tea') data.loose_tea = 1;
        const result = await apiPost('api/cart/add', data);
        if (result.error) { alert(result.error); return; }
        renderCart(result);
        updatePoleDisplay(result);
    }

    if (pluBtn) {
        pluBtn.addEventListener('click', function() {
            pluKeypadInput = '';
            updatePluDisplay();
            if (!pluKeypadInstance) {
                pluKeypadInstance = new bootstrap.Modal(document.getElementById('pluKeypadModal'));
            }
            pluKeypadInstance.show();
        });
    }

    document.querySelectorAll('.plu-key').forEach(btn => {
        btn.addEventListener('click', function() {
            const key = this.dataset.key;
            switch (key) {
                case '0': case '1': case '2': case '3': case '4':
                case '5': case '6': case '7': case '8': case '9':
                    pluKeypadInput += key;
                    break;
                case '.':
                    pluKeypadInput += '.';
                    break;
                case 'backspace':
                    pluKeypadInput = pluKeypadInput.slice(0, -1);
                    break;
                case 'clear':
                    pluKeypadInput = '';
                    break;
                case 'enter':
                    submitPlu();
                    return;
            }
            updatePluDisplay();
        });
    });

    // ── Modifier modal state ─────────────────────────────────────────
    let modModalProduct = null;      // { id, name, price, group }
    let modModalSelected = [];       // [{ id, name, price, qty }]
    let modModalInstance = null;

    function openModifierModal(productId, productName, productPrice, group) {
        modModalProduct = { id: productId, name: productName, price: productPrice, group: group };
        modModalSelected = [];

        const isLooseTea = group === 'loose_tea';
        document.getElementById('modifierModalTitle').textContent = isLooseTea ? 'Select Tin' : productName;
        const header = document.querySelector('#modifierModal .modal-header');
        header.className = 'modal-header text-white ' + (isLooseTea ? 'bg-success' : 'bg-info');
        const addBtn = document.getElementById('addWithModsBtn');
        addBtn.className = 'btn btn-lg ' + (isLooseTea ? 'btn-success' : 'btn-info');
        renderModifierButtons();
        renderSelectedModifiers();

        if (!modModalInstance) {
            modModalInstance = new bootstrap.Modal(document.getElementById('modifierModal'));
        }
        modModalInstance.show();
    }

    /** Determine tin size from modifier name: '50g' → 0.5, '100g' → 1, else null */
    function tinSizeQty(name) {
        if (/\b50g\b/i.test(name)) return 0.5;
        if (/\b100g\b/i.test(name)) return 1;
        return null;
    }

    function renderModifierButtons() {
        const container = document.getElementById('modifierButtons');
        const group = modModalProduct ? modModalProduct.group : 'beverage';
        const groupMods = modifiersByGroup[group] || [];
        const isLooseTea = group === 'loose_tea';
        const btnClass = isLooseTea ? 'btn-outline-success' : 'btn-outline-info';

        if (isLooseTea) {
            // Group tins by size: 50g, 100g, other
            const tins50 = groupMods.filter(m => /\b50g\b/i.test(m.name));
            const tins100 = groupMods.filter(m => /\b100g\b/i.test(m.name));
            const tinsOther = groupMods.filter(m => !(/\b50g\b/i.test(m.name) || /\b100g\b/i.test(m.name)));

            let html = '';
            if (tins50.length) {
                html += '<div class="w-100 mb-1"><small class="text-muted fw-bold">50g Tins</small></div>';
                html += tins50.map(m => `
                    <button class="btn ${btnClass} btn-lg mod-select-btn"
                            data-mod-id="${m.id}" data-mod-name="${escHtml(m.name)}" data-mod-price="${m.price}"
                            data-tin-qty="0.5">
                        ${escHtml(m.name)} +$${parseFloat(m.price).toFixed(2)}
                    </button>
                `).join('');
            }
            if (tins100.length) {
                html += '<div class="w-100 mb-1 mt-2"><small class="text-muted fw-bold">100g Tins</small></div>';
                html += tins100.map(m => `
                    <button class="btn ${btnClass} btn-lg mod-select-btn"
                            data-mod-id="${m.id}" data-mod-name="${escHtml(m.name)}" data-mod-price="${m.price}"
                            data-tin-qty="1">
                        ${escHtml(m.name)} +$${parseFloat(m.price).toFixed(2)}
                    </button>
                `).join('');
            }
            if (tinsOther.length) {
                html += '<div class="w-100 mb-1 mt-2"><small class="text-muted fw-bold">Other Tins</small></div>';
                html += tinsOther.map(m => `
                    <button class="btn ${btnClass} btn-lg mod-select-btn"
                            data-mod-id="${m.id}" data-mod-name="${escHtml(m.name)}" data-mod-price="${m.price}">
                        ${escHtml(m.name)} +$${parseFloat(m.price).toFixed(2)}
                    </button>
                `).join('');
            }
            container.innerHTML = html;
        } else {
            container.innerHTML = groupMods.map(m => `
                <button class="btn ${btnClass} btn-lg mod-select-btn"
                        data-mod-id="${m.id}" data-mod-name="${escHtml(m.name)}" data-mod-price="${m.price}">
                    ${escHtml(m.name)} +$${parseFloat(m.price).toFixed(2)}
                </button>
            `).join('');
        }

        container.querySelectorAll('.mod-select-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const modId = parseInt(this.dataset.modId);

                if (isLooseTea) {
                    // For loose tea, only one tin allowed — replace selection
                    modModalSelected = [{
                        id: modId,
                        name: this.dataset.modName,
                        price: parseFloat(this.dataset.modPrice),
                        qty: 1,
                        tinQty: this.dataset.tinQty ? parseFloat(this.dataset.tinQty) : null
                    }];
                } else {
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
                }
                renderSelectedModifiers();
            });
        });
    }

    function renderSelectedModifiers() {
        const container = document.getElementById('modifierSelected');
        const isLooseTea = modModalProduct && modModalProduct.group === 'loose_tea';

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
        const basePrice = modModalProduct ? modModalProduct.price : 0;
        let total;
        if (isLooseTea && modModalSelected.length > 0) {
            // Loose tea: compute tea cost at tin quantity + flat tin price
            const sel = modModalSelected[0];
            const qty = sel.tinQty || 1;
            const teaCost = round2(basePrice * qty);
            total = teaCost + sel.price;
        } else {
            total = basePrice;
            modModalSelected.forEach(m => { total += m.price * m.qty; });
        }
        document.getElementById('modifierItemTotal').textContent = '$' + total.toFixed(2);
    }

    function round2(n) { return Math.round(n * 100) / 100; }

    // Add Plain button
    document.getElementById('addPlainBtn')?.addEventListener('click', async function() {
        if (!modModalProduct) return;
        modModalInstance?.hide();
        const data = {
            product_id: modModalProduct.id,
            quantity: 1
        };
        // Flag loose tea for correct pricing even when plain
        if (modModalProduct.group === 'loose_tea') {
            data.loose_tea = 1;
        }
        const result = await apiPost('api/cart/add', data);
        if (!result.error) {
            renderCart(result);
            updatePoleDisplay(result);
        }
    });

    // Add to Cart with modifiers
    document.getElementById('addWithModsBtn')?.addEventListener('click', async function() {
        if (!modModalProduct) return;
        modModalInstance?.hide();
        const isLooseTea = modModalProduct.group === 'loose_tea';

        // For loose tea, tin selection drives the quantity
        let qty = 1;
        if (isLooseTea && modModalSelected.length > 0) {
            const tinQty = modModalSelected[0].tinQty;
            if (tinQty) qty = tinQty;
        }

        const data = {
            product_id: modModalProduct.id,
            quantity: qty
        };
        if (modModalSelected.length > 0) {
            data.modifiers = JSON.stringify(modModalSelected);
        }
        if (isLooseTea) {
            data.loose_tea = 1;
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

            // Beverages → open modifier modal; loose tea direct-add (tin button handles modal)
            const modGroup = getModifierGroup(productCat);
            if (modGroup === 'beverage') {
                openModifierModal(productId, productName, productPrice, 'beverage');
                return;
            }

            // Non-tracked loose tea → prompt for tea name + price
            const productCode = (this.dataset.code || '').toLowerCase();
            if (productCode === 'loose-tea') {
                const teaName = prompt('Tea name:');
                if (teaName === null) return; // cancelled
                const priceStr = prompt('Price ($):');
                if (priceStr === null) return; // cancelled
                const price = parseFloat(priceStr);
                if (!price || price <= 0) { alert('Please enter a valid price.'); return; }
                const data = { product_id: productId, quantity: 1, custom_price: price.toFixed(2) };
                if (teaName.trim()) data.custom_name = teaName.trim();
                const result = await apiPost('api/cart/add', data);
                if (result.error) { alert(result.error); return; }
                renderCart(result);
                updatePoleDisplay(result);
                return;
            }

            // Otherwise direct add
            const data = { product_id: productId, quantity: 1 };
            if (modGroup === 'loose_tea') data.loose_tea = 1;
            const result = await apiPost('api/cart/add', data);
            if (result.error) {
                alert(result.error);
                return;
            }
            renderCart(result);
            updatePoleDisplay(result);
        });
    });

    // ── Tin button overlay on loose tea product cards ─────────────────
    if (looseTeaCatIds.length && modifiersByGroup.loose_tea?.length) {
        document.querySelectorAll('.pos-product-card').forEach(card => {
            const catId = parseInt(card.dataset.category);
            if (!looseTeaCatIds.includes(catId)) return;

            card.style.position = 'relative';
            const btn = document.createElement('button');
            btn.className = 'btn btn-success btn-sm pos-tin-btn';
            btn.textContent = 'Tin';
            card.appendChild(btn);

            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const productId = card.dataset.id;
                const productName = card.querySelector('.pos-product-name')?.textContent || '';
                const productPrice = parseFloat(card.dataset.price) || 0;
                openModifierModal(productId, productName, productPrice, 'loose_tea');
            });
        });
    }

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
        // Show/hide wholesale-only products
        document.querySelectorAll('.pos-product-card[data-wholesale-only]').forEach(function(card) {
            card.style.display = active ? '' : 'none';
        });
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
                const step = qtyStepMap[cartKey] || 1;

                qty = action === 'increase' ? qty + step : qty - step;
                const result = await apiPost('api/cart/update', { cart_key: cartKey, quantity: qty });
                renderCart(result);
                updatePoleDisplay(result);
            });
        });

        // Remove buttons (use cart_key)
        document.querySelectorAll('.remove-btn').forEach(btn => {
            btn.addEventListener('click', async function(e) {
                e.stopPropagation();
                const cartKey = this.dataset.cartKey;
                const result = await apiPost('api/cart/remove', { cart_key: cartKey });
                renderCart(result);
                updatePoleDisplay(result);
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

    // ── Per-item +/− step sizes (set via keypad, persist across re-renders) ──
    const qtyStepMap = {};   // cartKey → step size

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

    /**
     * Calculate the smallest quantity (to 2 dp) so that qty * unitPrice >= desired dollars.
     * This ensures the checkout total matches or just exceeds the entered dollar amount.
     */
    function qtyForDollars(dollars, unitPrice) {
        const raw = dollars / unitPrice;
        const rounded = Math.round(raw * 100) / 100;
        // Check if rounding down lost a cent
        if (Math.round(rounded * unitPrice * 100) / 100 < dollars) {
            return Math.ceil(raw * 100) / 100;
        }
        return rounded;
    }

    function updateKeypadDisplay() {
        const inputEl = document.getElementById('qtyKeypadInput');
        const previewEl = document.getElementById('qtyKeypadPreview');
        const input = qtyKeypadState.input || '0';

        if (qtyKeypadState.mode === 'dollar') {
            inputEl.textContent = '$' + input;
            const dollars = parseFloat(input) || 0;
            if (dollars > 0 && qtyKeypadState.unitPrice > 0) {
                const calcQty = qtyForDollars(dollars, qtyKeypadState.unitPrice);
                const actualTotal = (Math.round(calcQty * qtyKeypadState.unitPrice * 100) / 100).toFixed(2);
                previewEl.textContent = '$' + dollars.toFixed(2) + ' \u00f7 $' + qtyKeypadState.unitPrice.toFixed(2) + ' = ' + fmtQty(calcQty) + ' units ($' + actualTotal + ')';
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
        let dollarAmount = null;
        const input = qtyKeypadState.input;
        const value = parseFloat(input) || 0;

        if (qtyKeypadState.mode === 'dollar') {
            if (value <= 0 || qtyKeypadState.unitPrice <= 0) return;
            dollarAmount = Math.round(value * 100) / 100;
            qty = qtyForDollars(value, qtyKeypadState.unitPrice);
            if (qty < 0.01) {
                alert('Amount too small — quantity rounds to 0.');
                return;
            }
        } else {
            qty = value;
            if (qty < 0.01) return;
        }

        qtyKeypadState.instance?.hide();

        // Remember the keypad-entered qty as the +/− step for this item
        qtyStepMap[qtyKeypadState.cartKey] = qty;

        const postData = {
            cart_key: qtyKeypadState.cartKey,
            quantity: qty
        };
        if (dollarAmount !== null) {
            postData.dollar_amount = dollarAmount;
        }
        const result = await apiPost('api/cart/update', postData);
        renderCart(result);
        updatePoleDisplay(result);
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
    // ── Subtotal button → push current total to pole display ────────
    const subtotalBtn = document.getElementById('subtotalBtn');
    if (subtotalBtn) {
        subtotalBtn.addEventListener('click', function() {
            const totalEl = document.getElementById('cartTotal');
            const total = totalEl ? totalEl.textContent.trim() : '$0.00';
            localPrint('/pole-display', { line1: 'SUBTOTAL', line2: total });
        });
    }

    const clearBtn = document.getElementById('clearCartBtn');
    if (clearBtn) {
        clearBtn.addEventListener('click', async function() {
            if (!confirm('Clear cart?')) return;
            const result = await apiPost('api/cart/clear');
            renderCart(result);
        });
    }

    // ── Hold Order ───────────────────────────────────────────────────
    let heldModalInstance = null;

    function updateHoldBadge(count) {
        const badge = document.getElementById('holdBadge');
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count;
            badge.style.display = '';
        } else {
            badge.style.display = 'none';
        }
    }

    function timeAgo(dateStr) {
        const diff = Math.floor((Date.now() - new Date(dateStr + ' UTC').getTime()) / 1000);
        if (diff < 60) return diff + 's ago';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        return Math.floor(diff / 3600) + 'h ago';
    }

    async function openHeldOrdersModal() {
        if (!heldModalInstance) {
            heldModalInstance = new bootstrap.Modal(document.getElementById('heldOrdersModal'));
        }
        heldModalInstance.show();
        await refreshHeldOrdersList();
    }

    async function refreshHeldOrdersList() {
        const resp = await fetch(baseUrl + 'api/hold/list', { credentials: 'same-origin' });
        const data = await resp.json();
        const container = document.getElementById('heldOrdersList');
        const orders = data.orders || [];

        if (orders.length === 0) {
            container.innerHTML = '<p class="text-muted text-center">No held orders.</p>';
            return;
        }

        container.innerHTML = orders.map(o => `
            <div class="card mb-2">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${o.label ? escHtml(o.label) : 'Order #' + o.id}</strong>
                            <span class="text-muted ms-2">${o.item_count} item(s)</span>
                            <span class="fw-bold ms-2">$${parseFloat(o.cart_total).toFixed(2)}</span>
                            <br><small class="text-muted">Held by ${escHtml(o.held_by_name || '?')} &mdash; ${timeAgo(o.created_at)}</small>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-success btn-sm held-resume-btn" data-id="${o.id}">Resume</button>
                            <button class="btn btn-outline-danger btn-sm held-discard-btn" data-id="${o.id}">Discard</button>
                        </div>
                    </div>
                </div>
            </div>
        `).join('');

        container.querySelectorAll('.held-resume-btn').forEach(btn => {
            btn.addEventListener('click', async function() {
                const orderId = this.dataset.id;
                // If current cart has items, auto-hold it first
                const cartItems = document.querySelectorAll('.pos-cart-item');
                if (cartItems.length > 0) {
                    if (!confirm('Your current cart will be held automatically. Continue?')) return;
                    await apiPost('api/hold/save', { label: '' });
                }
                const result = await apiPost('api/hold/resume', { id: orderId });
                if (result.error) {
                    alert(result.error);
                    return;
                }
                renderCart(result);
                if (typeof result.held_count !== 'undefined') updateHoldBadge(result.held_count);
                heldModalInstance?.hide();
            });
        });

        container.querySelectorAll('.held-discard-btn').forEach(btn => {
            btn.addEventListener('click', async function() {
                if (!confirm('Discard this held order?')) return;
                const result = await apiPost('api/hold/delete', { id: this.dataset.id });
                if (typeof result.held_count !== 'undefined') updateHoldBadge(result.held_count);
                await refreshHeldOrdersList();
            });
        });
    }

    const holdBtn = document.getElementById('holdBtn');
    if (holdBtn) {
        holdBtn.addEventListener('click', async function() {
            const cartItems = document.querySelectorAll('.pos-cart-item');
            if (cartItems.length > 0) {
                // Cart has items — prompt for label, save, then show modal
                const label = prompt('Customer name (optional):') ?? '';
                const result = await apiPost('api/hold/save', { label });
                if (result.error) {
                    alert(result.error);
                    return;
                }
                // Clear cart UI
                renderCart({ items: [], subtotal: 0, gst: 0, pst: 0, total: 0, wholesale: false, cart_discount: false });
                if (typeof result.held_count !== 'undefined') updateHoldBadge(result.held_count);
            }
            // Open held orders modal (whether cart was empty or just held)
            await openHeldOrdersModal();
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
        const line2 = '$' + parseFloat(last.subtotal || last.line_total || 0).toFixed(2);

        localPrint('/pole-display', { line1, line2 });
    }

    // Expose for payment.js
    window.POS = { apiPost, renderCart, baseUrl, csrfToken, localPrint };
})();
