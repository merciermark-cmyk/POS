<?php
/**
 * Summary view — read-only display of a saved day-close count.
 * Variables: $count, $details, $floats, $shifts
 */
$REGISTERS = DayClose::REGISTERS;
$BILLS     = DayClose::BILLS;
$COINS     = DayClose::COINS;
$FLOAT_TARGETS = DayClose::FLOAT_TARGETS;
$dcModel   = new DayClose();

// Build structured data
$regData = [];
foreach ($REGISTERS as $regId => $reg) {
    $regData[$regId] = ['bills' => [], 'coins' => [], 'usd' => 0, 'bill_total' => 0, 'coin_total' => 0, 'cad_total' => 0];
}
foreach ($details as $d) {
    $r = $d['register'];
    switch ($d['denomination_type']) {
        case 'bill':
            $regData[$r]['bills'][$d['denomination']] = ['count' => (int)$d['value'], 'amount' => (float)$d['calculated_amount']];
            $regData[$r]['bill_total'] += (float)$d['calculated_amount'];
            $regData[$r]['cad_total'] += (float)$d['calculated_amount'];
            break;
        case 'coin':
            $calc = $dcModel->coinCalc((float)$d['value'], $d['denomination']);
            $regData[$r]['coins'][$d['denomination']] = ['weight' => (float)$d['value'], 'count' => $calc['count'], 'amount' => $calc['value']];
            $regData[$r]['coin_total'] += $calc['value'];
            $regData[$r]['cad_total'] += $calc['value'];
            break;
        case 'usd':
            $regData[$r]['usd'] = (float)$d['calculated_amount'];
            break;
    }
}

// Float data
$floatData = [];
foreach ($REGISTERS as $regId => $reg) {
    $floatData[$regId] = ['bills' => [], 'banknote_total' => 0, 'coin_total' => $regData[$regId]['coin_total'], 'total' => 0];
}
foreach ($floats as $f) {
    $r = $f['register'];
    $den = $f['denomination'];
    $qty = (int)$f['quantity'];
    $amt = $qty * (int)$den;
    $floatData[$r]['bills'][$den] = ['quantity' => $qty, 'amount' => $amt];
    $floatData[$r]['banknote_total'] += $amt;
}
foreach ($REGISTERS as $regId => $reg) {
    $floatData[$regId]['total'] = $floatData[$regId]['banknote_total'] + $floatData[$regId]['coin_total'];
}

// Deposit breakdown
$billPool = [];
foreach ($BILLS as $b) $billPool[$b] = 0;
foreach ($details as $d) {
    if ($d['denomination_type'] === 'bill') {
        $billPool[(int)$d['denomination']] += (int)$d['value'];
    }
}
$floatBillsArr = [];
foreach ($BILLS as $b) $floatBillsArr[$b] = 0;
foreach ($floats as $f) {
    $floatBillsArr[(int)$f['denomination']] += (int)$f['quantity'];
}
?>

<div class="container py-4" style="max-width:700px;">

    <div class="text-center mb-4">
        <i class="bi bi-check-circle-fill" style="font-size:3rem;color:#28a745;"></i>
        <h4 class="mt-2" style="color:var(--dc-olive);font-weight:700;">Close Submitted</h4>
    </div>

    <!-- Header -->
    <div class="dc-summary-section text-center">
        <h6 class="fw-bold" style="color:var(--dc-olive);">DayClose Summary</h6>
        <div><?= e($count['close_date']) ?> — Closed by <?= e($count['staff_name'] ?? 'Unknown') ?></div>
        <div class="text-muted" style="font-size:0.82rem;">Saved <?= e($count['updated_at']) ?></div>
    </div>

    <!-- R1 & R2 — full denomination view -->
    <?php foreach (['r1', 'r2'] as $regId): $reg = $REGISTERS[$regId]; ?>
    <div class="dc-summary-section">
        <h6 class="fw-bold" style="color:var(--dc-olive);"><?= e($reg['short']) ?> — <?= e($reg['name']) ?></h6>
        <div class="row">
            <div class="col-6">
                <strong>Count</strong><br>
                <?php foreach ($BILLS as $b): ?>
                    <?php $bd = $regData[$regId]['bills'][$b] ?? null; if ($bd && $bd['count'] > 0): ?>
                        $<?= $b ?> &times; <?= $bd['count'] ?> = $<?= number_format($bd['amount'], 2) ?><br>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php foreach ($COINS as $key => $c): ?>
                    <?php $cd = $regData[$regId]['coins'][$key] ?? null; if ($cd && $cd['count'] > 0): ?>
                        <?= e($c['label']) ?> &times; <?= $cd['count'] ?> = $<?= number_format($cd['amount'], 2) ?><br>
                    <?php endif; ?>
                <?php endforeach; ?>
                <strong>CAD Total: $<?= number_format($regData[$regId]['cad_total'], 2) ?></strong>
                <?php if ($regData[$regId]['usd'] > 0): ?>
                    <br><span style="color:#856404;">USD: $<?= number_format($regData[$regId]['usd'], 2) ?></span>
                <?php endif; ?>
            </div>
            <div class="col-6">
                <strong>Float</strong><br>
                <?php foreach ($BILLS as $b): ?>
                    <?php $fb = $floatData[$regId]['bills'][$b] ?? null; if ($fb && $fb['quantity'] > 0): ?>
                        $<?= $b ?> &times; <?= $fb['quantity'] ?> = $<?= number_format($fb['amount'], 2) ?><br>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php foreach ($COINS as $key => $c): ?>
                    <?php $cd = $regData[$regId]['coins'][$key] ?? null; if ($cd && $cd['count'] > 0): ?>
                        <?= e($c['label']) ?> &times; <?= $cd['count'] ?> = $<?= number_format($cd['amount'], 2) ?><br>
                    <?php endif; ?>
                <?php endforeach; ?>
                <strong>Float Total: $<?= number_format($floatData[$regId]['total'], 2) ?></strong>
                <?php if ($floatData[$regId]['banknote_total'] >= $FLOAT_TARGETS[$regId]): ?>
                    <i class="bi bi-check-circle-fill" style="color:#28a745;"></i>
                <?php endif; ?>
            </div>
        </div>
        <?php
        $cardKey = $regId . '_card';
        $tipsKey = $regId . '_tips';
        if (!empty($count[$cardKey]) || !empty($count[$tipsKey])): ?>
        <div class="mt-2 pt-2" style="border-top:1px solid #dee2e6;">
            <?php if (!empty($count[$cardKey])): ?>
                <strong>Card Batch:</strong> $<?= number_format((float)$count[$cardKey], 2) ?>
            <?php endif; ?>
            <?php if (!empty($count[$tipsKey])): ?>
                &nbsp;&nbsp;<strong>Tips:</strong> $<?= number_format((float)$count[$tipsKey], 2) ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <!-- R3 — manual entry summary -->
    <div class="dc-summary-section">
        <h6 class="fw-bold" style="color:var(--dc-olive);">R3 — Ice Tea (Manual Entry)</h6>
        <div class="row">
            <div class="col-6">
                <div class="mb-1"><strong>Total Sales:</strong> $<?= number_format((float)($count['r3_total_sales'] ?? 0), 2) ?></div>
                <div class="mb-1"><strong>Transactions:</strong> <?= (int)($count['r3_txn_count'] ?? 0) ?></div>
                <div class="mb-1"><strong>GST:</strong> $<?= number_format((float)($count['r3_gst'] ?? 0), 2) ?></div>
            </div>
            <div class="col-6">
                <div class="mb-1"><strong>Cash:</strong> $<?= number_format((float)($count['r3_cash'] ?? 0), 2) ?></div>
                <div class="mb-1"><strong>Card:</strong> $<?= number_format((float)($count['r3_card'] ?? 0), 2) ?></div>
                <?php if (!empty($count['r3_tips'])): ?>
                <div class="mb-1"><strong>Tips:</strong> $<?= number_format((float)$count['r3_tips'], 2) ?></div>
                <?php endif; ?>
                <div class="mb-1"><strong>Float:</strong> $<?= number_format((float)$FLOAT_TARGETS['r3'], 2) ?> (fixed)</div>
            </div>
        </div>
    </div>

    <!-- Deposit -->
    <div class="dc-summary-section">
        <h6 class="fw-bold" style="color:var(--dc-olive);">Deposit (CAD)</h6>
        <?php $depTotal = 0; foreach ($BILLS as $b):
            $depCount = $billPool[$b] - $floatBillsArr[$b];
            $depAmt = $depCount * $b;
            $depTotal += $depAmt;
            if ($depCount > 0): ?>
                $<?= $b ?> &times; <?= $depCount ?> = $<?= number_format($depAmt, 2) ?><br>
        <?php endif; endforeach; ?>
        <strong>Expected Deposit: $<?= number_format($depTotal, 2) ?></strong>
        <?php if ($count['actual_deposit'] !== null): ?>
            <br><strong>Actual Deposit: $<?= number_format((float)$count['actual_deposit'], 2) ?></strong>
            <?php
            $variance = round((float)$count['actual_deposit'] - $depTotal, 2);
            if (abs($variance) > 0.01):
                $varClass = $variance > 0 ? 'text-success' : 'text-danger';
            ?>
                <span class="<?= $varClass ?> fw-bold"> (<?= $variance > 0 ? '+' : '' ?>$<?= number_format($variance, 2) ?>)</span>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- USD -->
    <?php
    $hasUsd = false;
    foreach (['r1', 'r2'] as $regId) {
        if ($regData[$regId]['usd'] > 0) $hasUsd = true;
    }
    if ($hasUsd): ?>
    <div class="dc-summary-section dc-usd-box">
        <h6 class="fw-bold" style="color:#856404;">US Cash (Held Separately)</h6>
        <?php foreach (['r1', 'r2'] as $regId): ?>
            <?php if ($regData[$regId]['usd'] > 0): ?>
                <?= e($REGISTERS[$regId]['short']) ?>: $<?= number_format($regData[$regId]['usd'], 2) ?> USD<br>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Notes -->
    <?php if (!empty($count['notes'])): ?>
    <div class="dc-summary-section">
        <h6 class="fw-bold" style="color:var(--dc-olive);">Notes</h6>
        <div><?= nl2br(e($count['notes'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Grand totals -->
    <div class="dc-summary-section text-center">
        <div class="d-flex justify-content-between align-items-center">
            <span class="fw-bold" style="font-size:1.1rem;">Grand Total (CAD)</span>
            <span style="font-family:'Courier New',monospace;font-size:1.4rem;font-weight:700;color:var(--dc-olive);">$<?= number_format((float)$count['grand_total_cad'], 2) ?></span>
        </div>
        <?php if ((float)$count['grand_total_usd'] > 0): ?>
        <div class="d-flex justify-content-between align-items-center mt-1">
            <span class="fw-bold" style="font-size:1rem;color:#856404;">Grand Total (USD)</span>
            <span style="font-family:'Courier New',monospace;font-size:1.2rem;font-weight:700;color:#856404;">$<?= number_format((float)$count['grand_total_usd'], 2) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Shift Reconciliation -->
    <?php if (!empty($shifts)): ?>
    <div class="dc-summary-section">
        <h6 class="fw-bold" style="color:var(--dc-olive);">Shift Reconciliation</h6>
        <?php foreach ($shifts as $s): ?>
        <div class="mb-3 pb-2" style="border-bottom:1px solid #dee2e6;">
            <strong><?= e($s['terminal_name'] ?? 'Terminal ' . $s['terminal_id']) ?></strong>
            <div class="row mt-1">
                <div class="col-6">
                    <div class="small">
                        <strong>Cash</strong><br>
                        Expected: $<?= number_format((float)($s['expected_cash'] ?? 0), 2) ?><br>
                        Counted: $<?= number_format((float)($s['closing_cash'] ?? 0), 2) ?><br>
                        <?php
                        $cashOS = (float)($s['over_short'] ?? 0);
                        $cashClass = $cashOS >= 0 ? 'text-success' : 'text-danger';
                        $cashSign = $cashOS >= 0 ? '+' : '';
                        ?>
                        <span class="<?= $cashClass ?> fw-bold">Over/Short: <?= $cashSign ?>$<?= number_format($cashOS, 2) ?></span>
                    </div>
                </div>
                <?php if ($s['expected_card'] !== null): ?>
                <div class="col-6">
                    <div class="small">
                        <strong>Card</strong><br>
                        Expected: $<?= number_format((float)($s['expected_card'] ?? 0), 2) ?><br>
                        Batch: $<?= number_format((float)($s['closing_card'] ?? 0), 2) ?><br>
                        <?php if ((float)($s['closing_tips'] ?? 0) > 0): ?>
                        Tips: $<?= number_format((float)$s['closing_tips'], 2) ?><br>
                        <?php endif; ?>
                        <?php
                        $cardOS = (float)($s['card_over_short'] ?? 0);
                        $cardClass = $cardOS >= 0 ? 'text-success' : 'text-danger';
                        $cardSign = $cardOS >= 0 ? '+' : '';
                        ?>
                        <span class="<?= $cardClass ?> fw-bold">Over/Short: <?= $cardSign ?>$<?= number_format($cardOS, 2) ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="text-center mt-4">
        <a href="<?= baseUrl('dayclose') ?>" class="btn btn-dc-tan px-4 py-2 me-2">New Close</a>
        <a href="<?= baseUrl('dayclose/count?date=' . urlencode($count['close_date']) . '&staff=' . (int)$count['closed_by']) ?>"
           class="btn btn-outline-secondary">Edit</a>
        <a href="<?= baseUrl('dayclose/history') ?>" class="btn btn-outline-secondary ms-2">History</a>
    </div>

</div>
