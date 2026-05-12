<?php
$pageTitle = 'Products';
ob_start();

// Flatten grouped products into a single array with category name on each row
$allProducts = [];
foreach ($grouped as $catName => $products) {
    foreach ($products as $p) {
        $p['category_display'] = $catName;
        $allProducts[] = $p;
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h3 class="mb-0">Product Prices</h3>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <input type="text" id="productSearch" class="form-control form-control-sm" style="width:220px"
               placeholder="Search products..." autofocus>
        <select id="categoryFilter" class="form-select form-select-sm" style="width:auto">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= e($cat['name']) ?>" <?= $filterCat === $cat['name'] ? 'selected' : '' ?>>
                    <?= e($cat['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select id="priceFilter" class="form-select form-select-sm" style="width:auto">
            <option value="">All Prices</option>
            <option value="zero">No Price ($0)</option>
            <option value="has">Has Price</option>
        </select>
        <span class="text-muted small" id="resultCount"><?= count($allProducts) ?> products</span>
    </div>
</div>

<table class="table table-striped table-sm" id="productsTable">
    <thead>
        <tr>
            <th class="sortable" data-sort="name" style="cursor:pointer">Product <span class="sort-icon">&#8597;</span></th>
            <th class="sortable" data-sort="code" style="cursor:pointer">Code <span class="sort-icon">&#8597;</span></th>
            <th class="sortable" data-sort="category" style="cursor:pointer">Category <span class="sort-icon">&#8597;</span></th>
            <th>Tax</th>
            <th class="text-end sortable" data-sort="price" style="width:160px; cursor:pointer">Price <span class="sort-icon">&#8597;</span></th>
            <th class="text-end" style="width:140px">W/S Price</th>
            <th class="text-center" style="width:80px">Visible</th>
            <th style="width:80px"></th>
        </tr>
    </thead>
    <tbody id="productsBody">
        <?php foreach ($allProducts as $p): ?>
            <tr data-product-id="<?= $p['id'] ?>"
                data-name="<?= e(strtolower($p['name'])) ?>"
                data-code="<?= e(strtolower($p['product_code'] ?? '')) ?>"
                data-category="<?= e($p['category_display']) ?>"
                data-price="<?= (float)$p['retail_price'] ?>"
                <?= !$p['pos_visible'] ? 'class="table-secondary text-muted"' : '' ?>>
                <td><?= e($p['name']) ?></td>
                <td><code><?= e($p['product_code'] ?? '') ?></code></td>
                <td><small><?= e($p['category_display']) ?></small></td>
                <td><small><?= e($p['tax_profile'] ?? 'tax_free') ?></small></td>
                <td class="text-end">
                    <div class="input-group input-group-sm justify-content-end">
                        <span class="input-group-text">$</span>
                        <input type="number" step="0.01" min="0"
                               class="form-control form-control-sm text-end price-input"
                               style="max-width:100px"
                               value="<?= number_format((float)$p['retail_price'], 2, '.', '') ?>"
                               data-original="<?= number_format((float)$p['retail_price'], 2, '.', '') ?>">
                    </div>
                </td>
                <td class="text-end">
                    <div class="input-group input-group-sm justify-content-end">
                        <span class="input-group-text">$</span>
                        <input type="number" step="0.01" min="0"
                               class="form-control form-control-sm text-end ws-price-input"
                               style="max-width:90px"
                               placeholder="—"
                               value="<?= $p['wholesale_price'] !== null ? number_format((float)$p['wholesale_price'], 2, '.', '') : '' ?>"
                               data-original="<?= $p['wholesale_price'] !== null ? number_format((float)$p['wholesale_price'], 2, '.', '') : '' ?>">
                    </div>
                </td>
                <td class="text-center">
                    <div class="form-check form-switch d-flex justify-content-center mb-0">
                        <input type="checkbox" class="form-check-input visibility-toggle"
                               <?= $p['pos_visible'] ? 'checked' : '' ?>>
                    </div>
                </td>
                <td>
                    <button class="btn btn-sm btn-success save-price-btn d-none">Save</button>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if (empty($allProducts)): ?>
    <div class="alert alert-info">No products found.</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const tbody = document.getElementById('productsBody');
    const rows = Array.from(tbody.querySelectorAll('tr'));

    // ── Search + Filter ──────────────────────────────────────────────
    const searchInput = document.getElementById('productSearch');
    const categoryFilter = document.getElementById('categoryFilter');
    const priceFilter = document.getElementById('priceFilter');
    const resultCount = document.getElementById('resultCount');

    function applyFilters() {
        const search = searchInput.value.toLowerCase().trim();
        const cat = categoryFilter.value;
        const price = priceFilter.value;
        let visible = 0;

        rows.forEach(row => {
            const name = row.dataset.name;
            const code = row.dataset.code;
            const rowCat = row.dataset.category;
            const rowPrice = parseFloat(row.dataset.price);

            let show = true;
            if (search && !name.includes(search) && !code.includes(search)) show = false;
            if (cat && rowCat !== cat) show = false;
            if (price === 'zero' && rowPrice > 0) show = false;
            if (price === 'has' && rowPrice <= 0) show = false;

            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        resultCount.textContent = visible + ' product' + (visible !== 1 ? 's' : '');
    }

    searchInput.addEventListener('input', applyFilters);
    categoryFilter.addEventListener('change', applyFilters);
    priceFilter.addEventListener('change', applyFilters);

    // ── Sortable columns ─────────────────────────────────────────────
    let currentSort = { col: null, asc: true };

    document.querySelectorAll('.sortable').forEach(th => {
        th.addEventListener('click', function() {
            const col = this.dataset.sort;
            if (currentSort.col === col) {
                currentSort.asc = !currentSort.asc;
            } else {
                currentSort.col = col;
                currentSort.asc = true;
            }

            // Update icons
            document.querySelectorAll('.sort-icon').forEach(i => i.innerHTML = '&#8597;');
            this.querySelector('.sort-icon').innerHTML = currentSort.asc ? '&#9650;' : '&#9660;';

            rows.sort((a, b) => {
                let va, vb;
                switch (col) {
                    case 'name':     va = a.dataset.name;     vb = b.dataset.name;     break;
                    case 'code':     va = a.dataset.code;     vb = b.dataset.code;     break;
                    case 'category': va = a.dataset.category; vb = b.dataset.category; break;
                    case 'price':    return currentSort.asc
                        ? parseFloat(a.dataset.price) - parseFloat(b.dataset.price)
                        : parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
                }
                const cmp = va.localeCompare(vb);
                return currentSort.asc ? cmp : -cmp;
            });

            rows.forEach(r => tbody.appendChild(r));
        });
    });

    // ── Show/hide Save button when price or W/S price changes ────────
    document.querySelectorAll('.price-input, .ws-price-input').forEach(input => {
        input.addEventListener('input', function() {
            const row = this.closest('tr');
            const btn = row.querySelector('.save-price-btn');
            const priceChanged = row.querySelector('.price-input').value !== row.querySelector('.price-input').dataset.original;
            const wsChanged = row.querySelector('.ws-price-input').value !== row.querySelector('.ws-price-input').dataset.original;
            btn.classList.toggle('d-none', !priceChanged && !wsChanged);
        });
    });

    // ── Save price via AJAX ──────────────────────────────────────────
    document.querySelectorAll('.save-price-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const row     = this.closest('tr');
            const id      = row.dataset.productId;
            const input   = row.querySelector('.price-input');
            const wsInput = row.querySelector('.ws-price-input');
            const price   = input.value;
            const wsPrice = wsInput.value;
            const self    = this;

            self.disabled = true;
            self.textContent = '...';

            // Save retail price
            const saveRetail = fetch('<?= baseUrl('products/update-price') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
                body: 'id=' + id + '&price=' + encodeURIComponent(price)
            }).then(r => r.json());

            // Save wholesale price
            const saveWholesale = fetch('<?= baseUrl('products/update-wholesale-price') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
                body: 'id=' + id + '&wholesale_price=' + encodeURIComponent(wsPrice)
            }).then(r => r.json());

            Promise.all([saveRetail, saveWholesale])
                .then(([retData, wsData]) => {
                    if (retData.ok && wsData.ok) {
                        input.dataset.original = input.value;
                        wsInput.dataset.original = wsInput.value;
                        row.dataset.price = input.value;
                        self.classList.add('d-none');
                        row.style.backgroundColor = '#d4edda';
                        setTimeout(() => row.style.backgroundColor = '', 600);
                    } else {
                        alert(retData.error || wsData.error || 'Save failed');
                    }
                    self.disabled = false;
                    self.textContent = 'Save';
                })
                .catch(() => {
                    alert('Network error');
                    self.disabled = false;
                    self.textContent = 'Save';
                });
        });
    });

    // ── Toggle visibility via AJAX ───────────────────────────────────
    document.querySelectorAll('.visibility-toggle').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const row     = this.closest('tr');
            const id      = row.dataset.productId;
            const visible = this.checked ? 1 : 0;

            fetch('<?= baseUrl('products/toggle-visibility') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrfToken
                },
                body: 'id=' + id + '&visible=' + visible
            })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    row.classList.toggle('table-secondary', !visible);
                    row.classList.toggle('text-muted', !visible);
                    row.style.backgroundColor = '#d4edda';
                    setTimeout(() => row.style.backgroundColor = '', 600);
                } else {
                    alert(data.error || 'Toggle failed');
                    this.checked = !this.checked;
                }
            })
            .catch(() => {
                alert('Network error');
                this.checked = !this.checked;
            });
        });
    });

    // Apply initial category filter from URL if present
    if (categoryFilter.value) applyFilters();
});
</script>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
