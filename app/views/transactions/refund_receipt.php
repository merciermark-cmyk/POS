<?php
$pageTitle = 'Refund Receipt';
ob_start();
?>

<div class="container" style="max-width:500px; margin-top:40px">
    <?php if (!$refund): ?>
        <div class="alert alert-danger">Refund not found.</div>
        <a href="<?= baseUrl('transactions') ?>" class="btn btn-primary">Back to Transactions</a>
    <?php else: ?>
        <div class="card">
            <div class="card-body text-center">
                <div class="alert alert-warning fs-4 fw-bold mb-3">REFUND</div>

                <h4>Refund #<?= $refund['id'] ?></h4>
                <p class="text-muted">
                    Original Transaction #<?= $refund['original_transaction_id'] ?>
                    <br><?= date('M j, Y g:i A', strtotime($refund['created_at'])) ?>
                </p>
                <p class="text-muted small">Reason: <?= e($refund['reason']) ?></p>

                <table class="table table-sm text-start">
                    <thead>
                        <tr><th>Item</th><th class="text-center">Qty</th><th class="text-end">Total</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($refundItems as $item): ?>
                            <tr>
                                <td><?= e($item['product_name']) ?></td>
                                <td class="text-center"><?= $item['quantity'] ?></td>
                                <td class="text-end">$<?= number_format($item['line_total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="2">Subtotal</td><td class="text-end">$<?= number_format($refund['subtotal'], 2) ?></td></tr>
                        <?php if ($refund['gst_amount'] > 0): ?>
                            <tr><td colspan="2">GST</td><td class="text-end">$<?= number_format($refund['gst_amount'], 2) ?></td></tr>
                        <?php endif; ?>
                        <?php if ($refund['pst_amount'] > 0): ?>
                            <tr><td colspan="2">PST</td><td class="text-end">$<?= number_format($refund['pst_amount'], 2) ?></td></tr>
                        <?php endif; ?>
                        <tr class="fw-bold fs-5 text-danger"><td colspan="2">Refund Total</td><td class="text-end">-$<?= number_format($refund['total'], 2) ?></td></tr>
                    </tfoot>
                </table>

                <h6>Refund Payment</h6>
                <?php foreach ($refundPayments as $pay): ?>
                    <div class="d-flex justify-content-between">
                        <span><?= e(ucfirst(str_replace('_', ' ', $pay['method']))) ?></span>
                        <span>$<?= number_format($pay['amount'], 2) ?></span>
                    </div>
                <?php endforeach; ?>

                <div class="mt-4 d-flex gap-2 justify-content-center">
                    <button class="btn btn-outline-secondary btn-lg" onclick="printRefundReceipt()">Print Refund Receipt</button>
                    <a href="<?= baseUrl('transactions/view/' . $refund['original_transaction_id']) ?>" class="btn btn-primary btn-lg">View Transaction</a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($refund): ?>
<script>
var _refundReceiptData = <?= json_encode([
    'is_refund'          => true,
    'store_name'         => $settings['store_name'] ?? 'Granville Island Tea Co.',
    'store_address'      => $settings['store_address'] ?? '',
    'store_phone'        => $settings['store_phone'] ?? '',
    'transaction_id'     => $refund['original_transaction_id'],
    'refund_id'          => $refund['id'],
    'date'               => date('Y-m-d H:i', strtotime($refund['created_at'])),
    'cashier'            => $refund['refunded_by_name'] ?? '',
    'items'              => array_map(fn($i) => [
        'name'       => $i['product_name'],
        'quantity'   => (float)$i['quantity'],
        'unit_price' => (float)$i['unit_price'],
        'line_total' => (float)$i['line_total'],
        'gst'        => (float)$i['gst'],
        'pst'        => (float)$i['pst'],
    ], $refundItems ?? []),
    'subtotal'       => (float)($refund['subtotal'] ?? 0),
    'gst_amount'     => (float)($refund['gst_amount'] ?? 0),
    'pst_amount'     => (float)($refund['pst_amount'] ?? 0),
    'total'          => (float)($refund['total'] ?? 0),
    'payments'       => array_map(fn($p) => [
        'method' => $p['method'],
        'amount' => (float)$p['amount'],
    ], $refundPayments ?? []),
    'change'         => 0,
    'gst_number'     => $settings['gst_number'] ?? '',
    'pst_number'     => $settings['pst_number'] ?? '',
    'receipt_footer'  => 'Refund processed. Thank you.',
]) ?>;

function printRefundReceipt() {
    fetch('http://localhost:5000/print/receipt', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(_refundReceiptData)
    }).catch(() => {});
    // Open drawer for cash refunds
    <?php if (!empty($refundPayments) && $refundPayments[0]['method'] === 'cash'): ?>
    fetch('http://localhost:5000/print/open-drawer', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: '{}'
    }).catch(() => {});
    <?php endif; ?>
}

// Auto-print on first load
printRefundReceipt();
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/pos.php';
