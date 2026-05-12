<?php
/**
 * History view — date range of daily counts.
 * Variables: $counts, $from, $to
 */
?>
<div class="container py-4" style="max-width:700px;">
    <h4 class="mb-3" style="color:var(--dc-olive);font-weight:700;">Day Close History</h4>

    <form method="GET" action="<?= baseUrl('dayclose/history') ?>" class="row g-2 mb-4 align-items-end">
        <div class="col-auto">
            <label class="form-label fw-semibold mb-1">From</label>
            <input type="date" name="from" class="form-control" value="<?= e($from) ?>">
        </div>
        <div class="col-auto">
            <label class="form-label fw-semibold mb-1">To</label>
            <input type="date" name="to" class="form-control" value="<?= e($to) ?>">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-dc-tan">Filter</button>
        </div>
    </form>

    <?php if (empty($counts)): ?>
        <div class="text-muted">No counts found for this date range.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Closed By</th>
                        <th class="text-end">CAD Total</th>
                        <th class="text-end">Deposit</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($counts as $c): ?>
                    <tr>
                        <td><strong><?= e($c['close_date']) ?></strong></td>
                        <td><?= e($c['staff_name'] ?? '—') ?></td>
                        <td class="text-end">$<?= number_format((float)$c['grand_total_cad'], 2) ?></td>
                        <td class="text-end">$<?= number_format((float)$c['deposit_total'], 2) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= baseUrl('dayclose/summary?date=' . urlencode($c['close_date'])) ?>"
                               class="btn btn-sm btn-outline-secondary">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="mt-3">
        <a href="<?= baseUrl('dayclose') ?>" class="btn btn-outline-secondary">Back</a>
    </div>
</div>
