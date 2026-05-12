<?php
$pageTitle = 'Terminal';
$scripts = ['public/js/pos.js', 'public/js/payment.js', 'public/js/idle-timer.js', 'public/js/standalone-refund.js', 'public/js/admin-menu.js'];
ob_start();
?>
<meta name="operator-timeout" content="<?= OPERATOR_TIMEOUT ?>">

<!-- Category tree + beverage modifier data for JS -->
<script>
var POS_CATEGORY_TREE = <?= json_encode($categoryTree) ?>;
var POS_BEVERAGE_CAT_IDS = <?= json_encode($beverageCatIds) ?>;
var POS_LOOSE_TEA_CAT_IDS = <?= json_encode($looseTeaCatIds) ?>;
var POS_MODIFIERS = <?= json_encode($activeModifiers) ?>;
var POS_PRINT_URL = <?= json_encode($terminalPrintUrl) ?>;
var POS_HELD_COUNT = <?= (int)$heldOrderCount ?>;
var POS_MONERIS_ENABLED = <?= json_encode(($settings['moneris_enabled'] ?? '0') === '1' && !empty(($terminal ?? [])['moneris_terminal_id'] ?? '')) ?>;
var POS_MONERIS_TERMINAL_ID = <?= json_encode(($terminal ?? [])['moneris_terminal_id'] ?? '') ?>;
</script>

<div class="pos-terminal">
    <!-- Header Bar -->
    <div class="pos-header">
        <div class="d-flex align-items-center justify-content-between px-3 py-2">
            <div class="d-flex align-items-center gap-3">
                <strong class="text-white fs-5"><?= e($settings['store_name'] ?? APP_NAME) ?></strong>
                <?php if ($terminalName): ?>
                    <span class="badge bg-light text-dark"><?= e($terminalName) ?></span>
                <?php endif; ?>
                <span class="text-light opacity-75">Cashier: <?= e(currentOperator()['username']) ?></span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-outline-light btn-sm" id="openDrawerBtn" title="Open Cash Drawer">
                    <i class="bi bi-box-arrow-up"></i>
                </button>
                <button class="btn btn-warning btn-sm fw-bold" id="adminMenuBtn">Admin</button>
                <a href="https://labels.granvilletea.com" target="_blank" class="btn btn-outline-light btn-sm">Labels</a>
                <a href="<?= baseUrl('transactions') ?>" class="btn btn-outline-light btn-sm">History</a>
                <a href="<?= baseUrl('switch-user') ?>" class="btn btn-outline-info btn-sm">Switch User</a>
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
                <script>
                document.getElementById('openDrawerBtn').addEventListener('click', function(){
                    var btn = this;
                    btn.disabled = true;
                    fetch('<?= baseUrl("api/print/open-drawer") ?>', {method:'POST'})
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            if(d.error) alert('Drawer error: ' + d.error);
                        })
                        .catch(function(){ alert('Failed to open drawer'); })
                        .finally(function(){ btn.disabled = false; });
                });
                </script>
            </div>
        </div>
    </div>

    <?php $flash = getFlash('error'); if ($flash): ?>
        <div class="alert alert-danger m-2 mb-0"><?= e($flash) ?></div>
    <?php endif; ?>
    <?php $flash = getFlash('success'); if ($flash): ?>
        <div class="alert alert-success m-2 mb-0"><?= e($flash) ?></div>
    <?php endif; ?>

    <div class="pos-main">
        <!-- Left: Products -->
        <div class="pos-products">
            <!-- Parent Category Tabs -->
            <?php
            // Assign a unique color to each category button
            $colorPalette = [
                'cat-forest','cat-emerald','cat-teal','cat-cyan','cat-blue',
                'cat-indigo','cat-purple','cat-violet','cat-rose','cat-red',
                'cat-orange','cat-amber','cat-olive','cat-sage','cat-sea',
                'cat-slate','cat-brown',
            ];
            $catColorMap = [];
            $i = 0;
            foreach ($categoryTree as $cat) {
                $catColorMap[$cat['id']] = $colorPalette[$i % count($colorPalette)];
                $i++;
            }
            ?>
            <div class="pos-categories" id="parentCategoryRow">
                <button class="btn category-btn parent-cat-btn cat-dark active" data-category="" data-has-children="0">All</button>
                <?php foreach ($categoryTree as $cat): ?>
                    <button class="btn category-btn parent-cat-btn <?= $catColorMap[$cat['id']] ?>"
                            data-category="<?= $cat['id'] ?>"
                            data-has-children="<?= !empty($cat['children']) ? '1' : '0' ?>"><?= e($cat['name']) ?></button>
                <?php endforeach; ?>
            </div>
            <!-- Subcategory Tabs (hidden until parent with children is tapped) -->
            <div class="pos-subcategories" id="subCategoryRow" style="display:none">
            </div>

            <!-- Search + PLU -->
            <div class="pos-search px-2 py-2 d-flex gap-2">
                <button type="button" class="btn btn-outline-primary fw-bold" id="pluBtn" style="min-width:100px; font-size:1.1rem">PLU#</button>
                <input type="hidden" id="pluInput">
                <span id="pqw" class="flex-grow-1"></span>
            </div>

            <!-- Product Grid -->
            <div class="pos-grid" id="productGrid">
                <?php foreach ($products as $p): ?>
                    <div class="pos-product-card" data-id="<?= $p['id'] ?>"
                         data-category="<?= $p['category_id'] ?? '' ?>"
                         data-parent-category="<?= $p['parent_category_id'] ?? '' ?>"
                         data-name="<?= e(strtolower($p['name'])) ?>"
                         data-code="<?= e(strtolower($p['product_code'] ?? '')) ?>"
                         data-price="<?= (float)$p['unit_price'] ?>"
                         <?php if ($p['wholesale_only']): ?>data-wholesale-only="1" style="display:none"<?php endif; ?>>
                        <?php if ($p['image']): ?>
                        <div class="pos-product-img">
                            <img src="<?= baseUrl('public/uploads/pos/' . $p['image']) ?>"
                                 alt="<?= e($p['name']) ?>" loading="lazy">
                        </div>
                        <?php endif; ?>
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
                <div class="d-flex align-items-center gap-1">
                    <button class="btn btn-sm <?= !empty($cartDiscount) ? 'btn-teal' : 'btn-outline-teal' ?>"
                            id="discountToggle" title="Toggle 10% discount">
                        10% OFF<?php if (!empty($cartDiscount)): ?> <span class="badge bg-light text-teal">-10%</span><?php endif; ?>
                    </button>
                    <button class="btn btn-sm <?= $wholesale ? 'btn-purple' : 'btn-outline-purple' ?>"
                            id="wholesaleToggle" title="Toggle 25% wholesale discount">
                        WHOLESALE<?php if ($wholesale): ?> <span class="badge bg-light text-purple">-25%</span><?php endif; ?>
                    </button>
                </div>
            </div>

            <div class="pos-cart-items" id="cartItems">
                <?php if (empty($cartTotals['items'])): ?>
                    <div class="pos-cart-empty" id="cartEmpty">
                        <p class="text-muted">Cart is empty</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($cartTotals['items'] as $item): ?>
                        <div class="pos-cart-item" data-cart-key="<?= e($item['cart_key'] ?? $item['product_id']) ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="fw-bold"><?= e($item['product_name']) ?></div>
                                    <?php if (!empty($item['modifiers'])): ?>
                                        <?php foreach ($item['modifiers'] as $mod): ?>
                                            <div class="text-muted small ms-2">+ <?= e($mod['name']) ?> ($<?= number_format((float)$mod['price'], 2) ?>)</div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <div class="d-flex align-items-center gap-2 mt-1">
                                        <button class="btn btn-sm btn-outline-secondary qty-btn" data-action="decrease"
                                                data-cart-key="<?= e($item['cart_key'] ?? $item['product_id']) ?>">&#8722;</button>
                                        <span class="qty-display"><?= $item['quantity'] ?></span>
                                        <button class="btn btn-sm btn-outline-secondary qty-btn" data-action="increase"
                                                data-cart-key="<?= e($item['cart_key'] ?? $item['product_id']) ?>">+</button>
                                        <span class="text-muted ms-2">&times; $<?= number_format($item['effective_unit_price'] ?? $item['unit_price'], 2) ?></span>
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
                                            data-cart-key="<?= e($item['cart_key'] ?? $item['product_id']) ?>">&#215;</button>
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
                <button class="btn btn-outline-info btn-lg flex-fill" id="subtotalBtn">SUBTOTAL</button>
                <button class="btn btn-outline-danger btn-lg flex-fill" id="clearCartBtn">CLEAR</button>
                <button class="btn btn-warning btn-lg flex-fill position-relative" id="holdBtn">
                    HOLD
                    <?php if ($heldOrderCount > 0): ?>
                        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill" id="holdBadge"><?= $heldOrderCount ?></span>
                    <?php else: ?>
                        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill" id="holdBadge" style="display:none"></span>
                    <?php endif; ?>
                </button>
                <button class="btn btn-success btn-lg flex-fill" id="payBtn"
                        <?= empty($cartTotals['items']) ? 'disabled' : '' ?>>PAY</button>
            </div>
        </div>
    </div>
</div>

<!-- Modifier Modal -->
<div class="modal fade" id="modifierModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="modifierModalTitle">Customize Drink</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="modifierButtons" class="d-flex flex-wrap gap-2 mb-3">
                    <!-- Modifier buttons rendered by JS -->
                </div>
                <h6>Selected:</h6>
                <div id="modifierSelected" class="mb-3">
                    <p class="text-muted" id="noModsMsg">None (plain)</p>
                </div>
                <div class="d-flex justify-content-between fw-bold fs-5">
                    <span>Item Total:</span>
                    <span id="modifierItemTotal">$0.00</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-lg" id="addPlainBtn">Add Plain</button>
                <button type="button" class="btn btn-info btn-lg" id="addWithModsBtn">Add to Cart</button>
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
                                <button class="btn btn-outline-secondary btn-lg add-payment-btn" data-method="usd_cash">USD Cash</button>
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

                        <!-- Card amount panel -->
                        <div id="cardAmountPanel" style="display:none">
                            <label class="form-label fw-bold">Card Amount</label>
                            <div class="d-flex gap-2 flex-wrap mb-3">
                                <button class="btn btn-primary btn-lg" id="cardExactBtn">Exact</button>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Custom Amount</label>
                                <input type="number" class="form-control form-control-lg" id="customCardAmount"
                                       step="0.01" min="0" placeholder="0.00">
                                <button class="btn btn-primary mt-2" id="applyCustomCard">Apply</button>
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

                        <!-- USD Cash panel -->
                        <div id="usdCashPanel" style="display:none">
                            <label class="form-label fw-bold">USD Cash Payment</label>
                            <div class="alert alert-secondary py-2 mb-2" id="usdRateInfo">
                                Loading rate...
                            </div>
                            <div class="mb-2">
                                <small class="text-muted">Amount due: <strong id="usdAmountDue">--</strong></small>
                            </div>
                            <div class="d-flex gap-2 flex-wrap mb-3" id="usdDenomBtns">
                                <!-- Populated by JS -->
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Custom USD Amount</label>
                                <input type="number" class="form-control form-control-lg" id="customUsdAmount"
                                       step="0.01" min="0" placeholder="0.00">
                                <button class="btn btn-secondary mt-2" id="applyCustomUsd">Apply</button>
                            </div>
                        </div>

                        <div id="changeDisplay" class="mt-3" style="display:none">
                            <div class="alert alert-success fs-3 text-center">
                                Change: <strong id="changeAmount">$0.00</strong>
                            </div>
                        </div>

                        <!-- Moneris processing panel -->
                        <div id="monerisPanel" style="display:none">
                            <div id="monerisProcessing" class="text-center py-4" style="display:none">
                                <div class="spinner-border text-primary mb-3" style="width:3rem;height:3rem"></div>
                                <h5>Processing on Terminal...</h5>
                                <p class="text-muted">Customer is interacting with the card reader.<br>Please wait.</p>
                            </div>
                            <div id="monerisResult" style="display:none"></div>
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

<!-- Quantity Keypad Modal -->
<div class="modal fade" id="qtyKeypadModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h5 class="modal-title" id="qtyKeypadTitle">Quantity</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <!-- Mode toggle -->
                <div class="qty-mode-toggle d-flex mb-2">
                    <button class="btn btn-primary flex-fill qty-mode-btn active" data-mode="qty">QTY</button>
                    <button class="btn btn-outline-primary flex-fill qty-mode-btn" data-mode="dollar">$</button>
                </div>

                <!-- Display -->
                <div class="qty-keypad-display text-center mb-2">
                    <div id="qtyKeypadInput" class="fs-1 fw-bold font-monospace">0</div>
                    <div id="qtyKeypadPreview" class="text-muted small" style="display:none"></div>
                </div>

                <!-- Quick dollar buttons ($ mode only) -->
                <div id="qtyQuickDollars" class="d-flex gap-2 mb-2" style="display:none !important">
                    <button class="btn btn-outline-success flex-fill quick-dollar-btn" data-amount="5">$5</button>
                    <button class="btn btn-outline-success flex-fill quick-dollar-btn" data-amount="10">$10</button>
                    <button class="btn btn-outline-success flex-fill quick-dollar-btn" data-amount="20">$20</button>
                </div>

                <!-- Numpad grid -->
                <div class="qty-keypad-grid">
                    <button class="btn btn-light qty-keypad-btn" data-key="7">7</button>
                    <button class="btn btn-light qty-keypad-btn" data-key="8">8</button>
                    <button class="btn btn-light qty-keypad-btn" data-key="9">9</button>
                    <button class="btn btn-outline-danger qty-keypad-btn" data-key="backspace">&#9003;</button>
                    <button class="btn btn-light qty-keypad-btn" data-key="4">4</button>
                    <button class="btn btn-light qty-keypad-btn" data-key="5">5</button>
                    <button class="btn btn-light qty-keypad-btn" data-key="6">6</button>
                    <button class="btn btn-outline-secondary qty-keypad-btn" data-key="clear">C</button>
                    <button class="btn btn-light qty-keypad-btn" data-key="1">1</button>
                    <button class="btn btn-light qty-keypad-btn" data-key="2">2</button>
                    <button class="btn btn-light qty-keypad-btn" data-key="3">3</button>
                    <button class="btn btn-info text-white qty-keypad-btn" data-key="half">&frac12;</button>
                    <button class="btn btn-light qty-keypad-btn" data-key="0">0</button>
                    <button class="btn btn-light qty-keypad-btn" data-key=".">.</button>
                    <button class="btn btn-success text-white qty-keypad-btn qty-keypad-confirm" data-key="confirm" style="grid-column: span 2;">OK</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Standalone Refund Modal -->
<div class="modal fade" id="standaloneRefundModal" tabindex="-1" data-bs-backdrop="static"
     data-threshold="<?= $standaloneRefundThreshold ?>">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Process Refund</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <span class="badge bg-success mb-3" id="authBadge" style="display:none"></span>
                <div class="mb-3">
                    <label class="form-label fw-bold">Refund Amount ($)</label>
                    <input type="number" class="form-control form-control-lg" id="refundAmount"
                           step="0.01" min="0.01" placeholder="0.00" inputmode="decimal">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Customer Name</label>
                    <input type="text" class="form-control" id="refundCustomerName"
                           placeholder="Customer name for records" maxlength="255" autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Reason</label>
                    <input type="text" class="form-control" id="refundReason"
                           placeholder="e.g. Wrong tea given" maxlength="255" autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Payment Method</label>
                    <select class="form-select" id="refundPaymentMethod">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                    </select>
                </div>
                <div class="alert alert-warning" id="thresholdWarning" style="display:none"></div>
                <button class="btn btn-outline-primary" id="requestPinBtn" style="display:none">
                    Enter Manager PIN
                </button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger btn-lg" id="processRefundBtn">Process Refund</button>
            </div>
        </div>
    </div>
</div>

<!-- Manager PIN Modal -->
<div class="modal fade" id="managerPinModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h5 class="modal-title">Manager PIN</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <input type="password" class="form-control form-control-lg text-center mb-2"
                       id="managerPinInput" placeholder="Enter PIN" inputmode="numeric" autocomplete="off">
                <div class="alert alert-danger py-1 mb-2" id="pinError" style="display:none"></div>
                <div class="qty-keypad-grid">
                    <button class="btn btn-light pin-keypad-btn" data-key="1">1</button>
                    <button class="btn btn-light pin-keypad-btn" data-key="2">2</button>
                    <button class="btn btn-light pin-keypad-btn" data-key="3">3</button>
                    <button class="btn btn-outline-danger pin-keypad-btn" data-key="backspace">&#9003;</button>
                    <button class="btn btn-light pin-keypad-btn" data-key="4">4</button>
                    <button class="btn btn-light pin-keypad-btn" data-key="5">5</button>
                    <button class="btn btn-light pin-keypad-btn" data-key="6">6</button>
                    <button class="btn btn-outline-secondary pin-keypad-btn" data-key="clear">C</button>
                    <button class="btn btn-light pin-keypad-btn" data-key="7">7</button>
                    <button class="btn btn-light pin-keypad-btn" data-key="8">8</button>
                    <button class="btn btn-light pin-keypad-btn" data-key="9">9</button>
                    <button class="btn btn-light pin-keypad-btn" data-key="0">0</button>
                </div>
                <button class="btn btn-primary w-100 btn-lg mt-2" id="verifyPinBtn">Verify</button>
            </div>
        </div>
    </div>
</div>

<!-- Admin Menu Modal -->
<div class="modal fade" id="adminMenuModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold">Admin Menu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <span class="badge bg-success mb-3" id="adminAuthBadge"></span>
                <div class="d-grid gap-3">
                    <button class="btn btn-danger btn-lg py-3 fs-5" id="adminRefundBtn">
                        Standalone Refund
                    </button>
                    <a href="<?= baseUrl('reports/daily') ?>" class="btn btn-primary btn-lg py-3 fs-5">
                        Reports
                    </a>
                    <button class="btn btn-info btn-lg py-3 fs-5 text-white" id="adminPettyCashBtn">
                        Petty Cash
                    </button>
                    <button class="btn btn-purple btn-lg py-3 fs-5 text-white" id="adminGiftCardSaleBtn">
                        Gift Card Sale
                    </button>
                    <?php if (isManager()): ?>
                    <a href="<?= baseUrl('products') ?>" class="btn btn-outline-dark btn-lg py-3 fs-5">
                        Products &amp; Pricing
                    </a>
                    <?php endif; ?>
                    <a href="<?= baseUrl('lock') ?>" class="btn btn-secondary btn-lg py-3 fs-5">
                        Lock Screen
                    </a>
                    <a href="<?= baseUrl('dayclose') ?>" class="btn btn-warning btn-lg py-3 fs-5">
                        Close Registers
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Petty Cash Modal -->
<div class="modal fade" id="pettyCashModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">Petty Cash</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-5">
                        <h6 class="fw-bold">Record Expenditure</h6>
                        <div class="mb-3">
                            <label class="form-label">Amount ($)</label>
                            <input type="number" class="form-control form-control-lg" id="pettyCashAmount"
                                   step="0.01" min="0.01" placeholder="0.00" inputmode="decimal">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <input type="text" class="form-control" id="pettyCashDescription"
                                   placeholder="e.g. Milk for shop" maxlength="255" autocomplete="off">
                        </div>
                        <button class="btn btn-info text-white w-100 btn-lg" id="addPettyCashBtn">Add Entry</button>
                    </div>
                    <div class="col-md-7">
                        <h6 class="fw-bold">This Shift</h6>
                        <div class="alert alert-secondary py-2 text-center">
                            Running Total: <strong id="pettyCashRunningTotal">$0.00</strong>
                        </div>
                        <div id="pettyCashList" style="max-height:300px; overflow-y:auto">
                            <p class="text-muted" id="pettyCashEmpty">No entries yet.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Gift Card Sale Modal -->
<div class="modal fade" id="giftCardSaleModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-purple text-white">
                <h5 class="modal-title">Gift Card Sale</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-5">
                        <h6 class="fw-bold">Record Gift Card Sale</h6>
                        <div class="mb-3">
                            <label class="form-label">Amount ($)</label>
                            <input type="number" class="form-control form-control-lg" id="gcSaleAmount"
                                   step="0.01" min="0.01" placeholder="0.00" inputmode="decimal">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Payment Method</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="gcSaleMethod" id="gcSaleCard" value="card" checked>
                                <label class="btn btn-outline-primary btn-lg" for="gcSaleCard">Card</label>
                                <input type="radio" class="btn-check" name="gcSaleMethod" id="gcSaleCash" value="cash">
                                <label class="btn btn-outline-success btn-lg" for="gcSaleCash">Cash</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes <small class="text-muted">(optional)</small></label>
                            <input type="text" class="form-control" id="gcSaleNotes"
                                   placeholder="e.g. Customer name" maxlength="255" autocomplete="off">
                        </div>
                        <button class="btn btn-purple text-white w-100 btn-lg" id="addGiftCardSaleBtn">Add Entry</button>
                    </div>
                    <div class="col-md-7">
                        <h6 class="fw-bold">This Shift</h6>
                        <div class="alert alert-secondary py-2 text-center">
                            Running Total: <strong id="gcSaleRunningTotal">$0.00</strong>
                            <span class="text-muted small ms-2">
                                (Card: <span id="gcSaleCardTotal">$0.00</span> | Cash: <span id="gcSaleCashTotal">$0.00</span>)
                            </span>
                        </div>
                        <div id="gcSaleList" style="max-height:300px; overflow-y:auto">
                            <p class="text-muted" id="gcSaleEmpty">No entries yet.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Held Orders Modal -->
<div class="modal fade" id="heldOrdersModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold">Held Orders</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="heldOrdersList">
                    <p class="text-muted text-center" id="heldOrdersEmpty">No held orders.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- PLU Keypad Modal -->
<div class="modal fade" id="pluKeypadModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h5 class="modal-title">Enter PLU Code</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <div class="text-center mb-2">
                    <div id="pluKeypadDisplay" class="fs-1 fw-bold font-monospace py-2" style="min-height:50px">_</div>
                </div>
                <div class="qty-keypad-grid">
                    <button class="btn btn-light plu-key" data-key="7">7</button>
                    <button class="btn btn-light plu-key" data-key="8">8</button>
                    <button class="btn btn-light plu-key" data-key="9">9</button>
                    <button class="btn btn-outline-danger plu-key" data-key="backspace">&#9003;</button>
                    <button class="btn btn-light plu-key" data-key="4">4</button>
                    <button class="btn btn-light plu-key" data-key="5">5</button>
                    <button class="btn btn-light plu-key" data-key="6">6</button>
                    <button class="btn btn-outline-secondary plu-key" data-key="clear">C</button>
                    <button class="btn btn-light plu-key" data-key="1">1</button>
                    <button class="btn btn-light plu-key" data-key="2">2</button>
                    <button class="btn btn-light plu-key" data-key="3">3</button>
                    <button class="btn btn-success text-white plu-key" data-key="enter" style="grid-row: span 2;">GO</button>
                    <button class="btn btn-light plu-key" data-key="0" style="grid-column: span 2;">0</button>
                    <button class="btn btn-light plu-key" data-key=".">.</button>
                </div>
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
