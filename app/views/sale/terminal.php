<?php
$pageTitle = 'Terminal';
$scripts = ['public/js/pos.js', 'public/js/payment.js', 'public/js/idle-timer.js'];
ob_start();
?>
<meta name="operator-timeout" content="<?= OPERATOR_TIMEOUT ?>">

<div class="pos-terminal">
    <!-- Header Bar -->
    <div class="pos-header">
        <div class="d-flex align-items-center justify-content-between px-3 py-2">
            <div class="d-flex align-items-center gap-3">
                <strong class="text-white fs-5"><?= e($settings['store_name'] ?? APP_NAME) ?></strong>
                <span class="text-light opacity-75">Cashier: <?= e(currentOperator()['username']) ?></span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= baseUrl('transactions') ?>" class="btn btn-outline-light btn-sm">History</a>
                <?php if (isManager()): ?>
                    <a href="<?= baseUrl('reports/daily') ?>" class="btn btn-outline-light btn-sm">Reports</a>
                <?php endif; ?>
                <a href="<?= baseUrl('shift/close') ?>" class="btn btn-outline-warning btn-sm">Close Shift</a>
                <a href="<?= baseUrl('switch-user') ?>" class="btn btn-outline-info btn-sm">Switch User</a>
                <a href="<?= baseUrl('lock') ?>" class="btn btn-outline-secondary btn-sm">Lock</a>
                <button class="btn btn-outline-light btn-sm" id="fullScreenToggle"></button>
                <script>
                (function(){
                    var btn = document.getElementById('fullScreenToggle');
                    function update(){
                        btn.textContent = document.fullscreenElement ? 'Exit Full Screen' : 'Full Screen';
                    }
                    btn.addEventListener('click', function(){
                        if(document.fullscreenElement){ document.exitFullscreen(); }
                        else { document.documentElement.requestFullscreen(); }
                    });
                    document.addEventListener('fullscreenchange', update);
                    update();
                })();
                </script>
            </div>
        </div>
    </div>

    <?php $flash = getFlash('error'); if ($flash): ?>
        <div class="alert alert-danger m-2 mb-0"><?= e($flash) ?></div>
    <?php endif; ?>

    <div class="pos-main">
        <!-- Left: Products -->
        <div class="pos-products">
            <!-- Category Tabs -->
            <div class="pos-categories">
                <button class="btn btn-primary category-btn active" data-category="">All</button>
                <?php foreach ($categories as $cat): ?>
                    <button class="btn btn-outline-primary category-btn"
                            data-category="<?= $cat['id'] ?>"><?= e($cat['name']) ?></button>
                <?php endforeach; ?>
            </div>

            <!-- Search -->
            <div class="pos-search px-2 py-2">
                <input type="text" id="productSearch" class="form-control"
                       placeholder="Search products..." autocomplete="off">
            </div>

            <!-- Product Grid -->
            <div class="pos-grid" id="productGrid">
                <?php foreach ($products as $p): ?>
                    <div class="pos-product-card" data-id="<?= $p['id'] ?>"
                         data-category="<?= $p['category_id'] ?? '' ?>"
                         data-name="<?= e(strtolower($p['name'])) ?>"
                         data-code="<?= e(strtolower($p['product_code'] ?? '')) ?>">
                        <div class="pos-product-img">
                            <?php if ($p['image']): ?>
                                <img src="<?= baseUrl('public/uploads/pos/' . $p['image']) ?>"
                                     alt="<?= e($p['name']) ?>" loading="lazy">
                            <?php else: ?>
                                <div class="pos-product-placeholder"><?= e(mb_substr($p['name'], 0, 2)) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="pos-product-name"><?= e($p['name']) ?></div>
                        <div class="pos-product-price">$<?= number_format((float)$p['unit_price'], 2) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Right: Cart -->
        <div class="pos-cart">
            <div class="pos-cart-header">
                <div class="d-flex align-items-center gap-2">
                    <h5 class="mb-0">Cart</h5>
                    <span class="badge bg-secondary" id="cartCount"><?= count($cartTotals['items']) ?></span>
                </div>
                <button class="btn btn-sm <?= $wholesale ? 'btn-purple' : 'btn-outline-purple' ?>"
                        id="wholesaleToggle" title="Toggle 25% wholesale discount">
                    WHOLESALE<?php if ($wholesale): ?> <span class="badge bg-light text-purple">-25%</span><?php endif; ?>
                </button>
            </div>

            <div class="pos-cart-items" id="cartItems">
                <?php if (empty($cartTotals['items'])): ?>
                    <div class="pos-cart-empty" id="cartEmpty">
                        <p class="text-muted">Cart is empty</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($cartTotals['items'] as $item): ?>
                        <div class="pos-cart-item" data-product-id="<?= $item['product_id'] ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="fw-bold"><?= e($item['product_name']) ?></div>
                                    <div class="d-flex align-items-center gap-2 mt-1">
                                        <button class="btn btn-sm btn-outline-secondary qty-btn" data-action="decrease"
                                                data-product-id="<?= $item['product_id'] ?>">−</button>
                                        <span class="qty-display"><?= $item['quantity'] ?></span>
                                        <button class="btn btn-sm btn-outline-secondary qty-btn" data-action="increase"
                                                data-product-id="<?= $item['product_id'] ?>">+</button>
                                        <span class="text-muted ms-2">× $<?= number_format($item['unit_price'], 2) ?></span>
                                    </div>
                                    <?php if ($item['gst'] > 0): ?>
                                        <small class="text-muted">GST: $<?= number_format($item['gst'], 2) ?></small>
                                    <?php endif; ?>
                                    <?php if ($item['pst'] > 0): ?>
                                        <small class="text-muted ms-2">PST: $<?= number_format($item['pst'], 2) ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold">$<?= number_format($item['line_total'], 2) ?></div>
                                    <button class="btn btn-sm btn-outline-danger mt-1 remove-btn"
                                            data-product-id="<?= $item['product_id'] ?>">×</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="pos-cart-totals">
                <div class="d-flex justify-content-between">
                    <span>Subtotal</span>
                    <span id="cartSubtotal">$<?= number_format($cartTotals['subtotal'], 2) ?></span>
                </div>
                <div class="d-flex justify-content-between text-muted">
                    <span>GST (5%)</span>
                    <span id="cartGst">$<?= number_format($cartTotals['gst'], 2) ?></span>
                </div>
                <div class="d-flex justify-content-between text-muted">
                    <span>PST (7%)</span>
                    <span id="cartPst">$<?= number_format($cartTotals['pst'], 2) ?></span>
                </div>
                <hr class="my-1">
                <div class="d-flex justify-content-between fw-bold fs-4">
                    <span>TOTAL</span>
                    <span id="cartTotal">$<?= number_format($cartTotals['total'], 2) ?></span>
                </div>
            </div>

            <div class="pos-cart-actions">
                <button class="btn btn-outline-danger btn-lg flex-fill" id="clearCartBtn">CLEAR</button>
                <button class="btn btn-success btn-lg flex-fill" id="payBtn"
                        <?= empty($cartTotals['items']) ? 'disabled' : '' ?>>PAY</button>
            </div>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h4 class="mb-3">Amount Due: <span id="modalTotal" class="text-success">$0.00</span></h4>
                        <h5>Remaining: <span id="modalRemaining" class="text-danger">$0.00</span></h5>

                        <div id="paymentEntries">
                            <!-- Payment entries added dynamically -->
                        </div>

                        <div class="mt-3">
                            <label class="form-label fw-bold">Add Payment</label>
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn btn-outline-success btn-lg add-payment-btn" data-method="cash">Cash</button>
                                <button class="btn btn-outline-primary btn-lg add-payment-btn" data-method="card">Card</button>
                                <button class="btn btn-outline-warning btn-lg add-payment-btn" data-method="gift_card">Gift Card</button>
                                <button class="btn btn-outline-info btn-lg add-payment-btn" data-method="web_gift_card">Web GC</button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <!-- Quick cash buttons -->
                        <div id="quickCashPanel" style="display:none">
                            <label class="form-label fw-bold">Quick Cash</label>
                            <div class="d-flex gap-2 flex-wrap mb-3" id="quickCashBtns">
                                <!-- Populated by JS -->
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Custom Amount</label>
                                <input type="number" class="form-control form-control-lg" id="customCashAmount"
                                       step="0.01" min="0" placeholder="0.00">
                                <button class="btn btn-success mt-2" id="applyCustomCash">Apply</button>
                            </div>
                        </div>

                        <!-- Gift card lookup -->
                        <div id="giftCardPanel" style="display:none">
                            <label class="form-label fw-bold">Web Gift Card Code</label>
                            <div class="input-group mb-3">
                                <input type="text" class="form-control" id="gcCode" placeholder="Enter code">
                                <button class="btn btn-info" id="gcCheckBtn">Check Balance</button>
                            </div>
                            <div id="gcResult"></div>
                        </div>

                        <div id="changeDisplay" class="mt-3" style="display:none">
                            <div class="alert alert-success fs-3 text-center">
                                Change: <strong id="changeAmount">$0.00</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <form method="post" action="<?= baseUrl('sale/complete') ?>" id="completeForm">
                    <?= csrfField() ?>
                    <div id="paymentHiddenFields"></div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-lg" id="completeSaleBtn" disabled>
                        Complete Sale
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Idle warning banner -->
<div id="idleWarning" class="idle-warning" style="display:none">
    Returning to staff picker in <span id="idleWarningText"></span>s...
</div>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/pos.php';
