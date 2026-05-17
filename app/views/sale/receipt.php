<?php
$pageTitle = 'Receipt';
ob_start();
?>

<div class="container" style="max-width:500px; margin-top:40px">
    <?php if (!$transaction): ?>
        <div class="alert alert-danger">Transaction not found.</div>
        <a href="<?= baseUrl('sale') ?>" class="btn btn-primary">Back to Terminal</a>
    <?php else: ?>
        <div class="card">
            <div class="card-body text-center">
                <?php if ($change > 0): ?>
                    <div class="alert alert-success fs-3">
                        Change: <strong>$<?= number_format($change, 2) ?></strong>
                    </div>
                <?php endif; ?>

                <h4>Sale #<?= $transaction['daily_number'] ?? $transaction['id'] ?></h4>
                <p class="text-muted mb-0 small">Txn #<?= $transaction['id'] ?></p>
                <p class="text-muted"><?= date('M j, Y g:i A', strtotime($transaction['created_at'])) ?></p>

                <table class="table table-sm text-start">
                    <thead>
                        <tr><th>Item</th><th class="text-center">Qty</th><th class="text-end">Total</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <?= e($item['product_name']) ?>
                                    <?php if (($item['discount_percent'] ?? 0) > 0): ?>
                                        <span class="badge bg-success">-<?= (int)$item['discount_percent'] ?>%</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= $item['quantity'] ?></td>
                                <td class="text-end">$<?= number_format($item['line_total'], 2) ?></td>
                            </tr>
                            <?php if (!empty($item['modifiers'])): ?>
                                <?php foreach ($item['modifiers'] as $mod): ?>
                                    <tr class="text-muted">
                                        <td class="ps-4 small">+ <?= e($mod['modifier_name']) ?> ($<?= number_format($mod['modifier_price'], 2) ?>)</td>
                                        <td></td>
                                        <td></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="2">Subtotal</td><td class="text-end">$<?= number_format($transaction['subtotal'], 2) ?></td></tr>
                        <?php if ($transaction['gst_amount'] > 0): ?>
                            <tr><td colspan="2">GST</td><td class="text-end">$<?= number_format($transaction['gst_amount'], 2) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($transaction['pst_amount'] > 0): ?>
                            <tr><td colspan="2">PST</td><td class="text-end">$<?= number_format($transaction['pst_amount'], 2) ?></td></tr>
                        <?php endif; ?>
                        <tr class="fw-bold fs-5"><td colspan="2">Total</td><td class="text-end">$<?= number_format($transaction['total'], 2) ?></td></tr>
                    </tfoot>
                </table>

                <h6>Payments</h6>
                <?php foreach ($payments as $pay): ?>
                    <div class="d-flex justify-content-between">
                        <span>
                            <?= e(ucfirst(str_replace('_', ' ', $pay['method']))) ?>
                            <?php if (!empty($pay['reference'])): ?>
                                <small class="text-muted">(<?= e($pay['reference']) ?>)</small>
                            <?php endif; ?>
                        </span>
                        <span>$<?= number_format($pay['amount'], 2) ?></span>
                    </div>
                <?php endforeach; ?>

                <?php if ($autoPrint): ?>
                <div id="receiptPrompt" class="mt-3 mb-3">
                    <div class="d-flex gap-2 justify-content-center">
                        <button class="btn btn-success btn-lg" id="btnPrintReceipt" onclick="doPrintAndContinue()">Print Receipt</button>
                        <button class="btn btn-outline-secondary btn-lg" id="btnNoReceipt" onclick="doSkipAndContinue()">No Receipt</button>
                    </div>
                </div>
                <?php endif; ?>

                <div class="receipt-countdown text-center mt-3 mb-3" id="receiptCountdown" <?php if ($autoPrint): ?>style="display:none"<?php endif; ?>>
                    Returning to staff picker in <span id="countdownNum"><?= RECEIPT_REDIRECT_SECONDS ?></span>...
                </div>

                <div class="mt-2 d-flex gap-2 justify-content-center" id="receiptNav" <?php if ($autoPrint): ?>style="display:none"<?php endif; ?>>
                    <form method="post" action="<?= baseUrl('next-customer') ?>" style="display:inline">
                        <?= csrfField() ?>
                        <button type="submit" class="btn btn-success btn-lg">Next Customer</button>
                    </form>
                    <a href="<?= baseUrl('switch-user') ?>" class="btn btn-primary btn-lg">Done</a>
                    <button class="btn btn-outline-secondary btn-lg" onclick="printReceipt()">Reprint</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
var _printUrl = <?= json_encode(rtrim($terminalPrintUrl ?? PRINT_SERVICE_URL, '/')) ?>;
var _receiptData = <?= json_encode([
    'store_name'    => $settings['store_name'] ?? 'Granville Island Tea Co.',
    'store_address' => $settings['store_address'] ?? '',
    'store_phone'   => $settings['store_phone'] ?? '',
    'transaction_id'=> $transaction['id'] ?? 0,
    'daily_number'  => $transaction['daily_number'] ?? null,
    'annual_number' => $transaction['annual_number'] ?? null,
    'date'          => isset($transaction['created_at']) ? date('Y-m-d H:i', strtotime($transaction['created_at'])) : '',
    'cashier'       => $transaction['username'] ?? '',
    'items'         => array_map(fn($i) => [
        'name'             => $i['product_name'],
        'quantity'         => (float)$i['quantity'],
        'unit_price'       => (float)$i['unit_price'],
        'line_total'       => (float)$i['line_total'],
        'gst'              => (float)$i['gst'],
        'pst'              => (float)$i['pst'],
        'discount_percent' => (float)($i['discount_percent'] ?? 0),
        'modifiers'  => array_map(fn($m) => [
            'name'  => $m['modifier_name'],
            'price' => (float)$m['modifier_price'],
            'qty'   => (int)$m['quantity'],
        ], $i['modifiers'] ?? []),
    ], $items ?? []),
    'subtotal'       => (float)($transaction['subtotal'] ?? 0),
    'gst_amount'     => (float)($transaction['gst_amount'] ?? 0),
    'pst_amount'     => (float)($transaction['pst_amount'] ?? 0),
    'total'          => (float)($transaction['total'] ?? 0),
    'payments'       => array_map(fn($p) => [
        'method'    => $p['method'],
        'amount'    => (float)$p['amount'],
        'reference' => $p['reference'] ?? '',
    ], $payments ?? []),
    'change'         => (float)($change ?? 0),
    'gst_number'     => $settings['gst_number'] ?? '',
    'pst_number'     => $settings['pst_number'] ?? '',
    'receipt_footer'  => $settings['receipt_footer'] ?? 'Thank you for your purchase!',
]) ?>;
var _hasCashPayment = <?= json_encode(!empty(array_filter($payments ?? [], fn($p) => $p['method'] === 'cash'))) ?>;

function printReceipt() {
    fetch(_printUrl + '/print/receipt', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(_receiptData)
    }).catch(() => {});
}

function openDrawer() {
    fetch(_printUrl + '/print/open-drawer', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: '{}'
    }).catch(() => {});
}

function showNavAndCountdown() {
    var prompt = document.getElementById('receiptPrompt');
    if (prompt) prompt.style.display = 'none';
    document.getElementById('receiptCountdown').style.display = '';
    document.getElementById('receiptNav').style.display = '';
    startCountdown();
}

function doPrintAndContinue() {
    printReceipt();
    openDrawer();
    showNavAndCountdown();
}

function doSkipAndContinue() {
    // Still open drawer for cash transactions
    if (_hasCashPayment) openDrawer();
    showNavAndCountdown();
}

<?php if (!empty($autoPrint) && $transaction): ?>
// Show total on pole display (always, regardless of receipt choice)
fetch(_printUrl + '/pole-display', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ line1: 'TOTAL', line2: '$<?= number_format($transaction['total'], 2) ?>' })
}).catch(() => {});
<?php endif; ?>

// Receipt auto-redirect countdown
function startCountdown() {
    var baseUrl = document.querySelector('meta[name="base-url"]')?.content || '/';
    var countdownEl = document.getElementById('countdownNum');
    var containerEl = document.getElementById('receiptCountdown');
    if (!countdownEl) return;

    var remaining = <?= RECEIPT_REDIRECT_SECONDS ?>;
    var paused = false;

    var interval = setInterval(function() {
        if (paused) return;
        remaining--;
        countdownEl.textContent = remaining;
        if (remaining <= 0) {
            clearInterval(interval);
            window.location = baseUrl + 'switch-user';
        }
    }, 1000);

    // Pause countdown on interaction
    ['click', 'touchstart', 'keydown'].forEach(function(evt) {
        document.addEventListener(evt, function() {
            if (!paused) {
                paused = true;
                if (containerEl) containerEl.textContent = 'Auto-redirect paused.';
            }
        }, { once: true });
    });
}

<?php if (empty($autoPrint)): ?>
// Not auto-print (e.g. viewing old receipt) — show nav immediately
startCountdown();
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/pos.php';
