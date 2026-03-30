<?php
/**
 * Tax calculation helpers for POS.
 */

/**
 * Calculate tax for a single line item.
 * Returns ['gst' => float, 'pst' => float, 'line_total' => float]
 */
function calculateLineTax(float $unitPrice, int $quantity, string $taxProfile): array {
    $subtotal = round($unitPrice * $quantity, 2);
    $gst = 0.0;
    $pst = 0.0;

    switch ($taxProfile) {
        case 'gst_only':
            $gst = round($subtotal * TAX_GST_RATE, 2);
            break;
        case 'gst_pst':
            $gst = round($subtotal * TAX_GST_RATE, 2);
            $pst = round($subtotal * TAX_PST_RATE, 2);
            break;
        case 'tax_free':
        default:
            break;
    }

    return [
        'subtotal'   => $subtotal,
        'gst'        => $gst,
        'pst'        => $pst,
        'line_total' => round($subtotal + $gst + $pst, 2),
    ];
}

/**
 * Calculate totals for the entire cart.
 * $items = [['unit_price' => float, 'quantity' => int, 'tax_profile' => string], ...]
 */
function calculateCartTotals(array $items, bool $wholesale = false): array {
    $subtotal = 0.0;
    $totalGst = 0.0;
    $totalPst = 0.0;

    foreach ($items as &$item) {
        $effectivePrice = (float)$item['unit_price'];
        if ($wholesale) {
            $effectivePrice = round($effectivePrice * 0.75, 2);
        }

        $line = calculateLineTax(
            $effectivePrice,
            (int)$item['quantity'],
            $item['tax_profile'] ?? 'tax_free'
        );
        $item['unit_price'] = $effectivePrice;
        $item['gst']        = $line['gst'];
        $item['pst']        = $line['pst'];
        $item['line_total'] = $line['line_total'];
        $item['subtotal']   = $line['subtotal'];

        $subtotal += $line['subtotal'];
        $totalGst += $line['gst'];
        $totalPst += $line['pst'];
    }
    unset($item);

    return [
        'items'    => $items,
        'subtotal' => round($subtotal, 2),
        'gst'      => round($totalGst, 2),
        'pst'      => round($totalPst, 2),
        'total'    => round($subtotal + $totalGst + $totalPst, 2),
        'wholesale' => $wholesale,
    ];
}
