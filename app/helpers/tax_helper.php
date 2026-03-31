<?php
/**
 * Tax calculation helpers for POS.
 */

/**
 * Calculate tax for a single line item.
 * Returns ['gst' => float, 'pst' => float, 'line_total' => float]
 */
function calculateLineTax(float $unitPrice, float $quantity, string $taxProfile): array {
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
 * Generate a unique cart key for a product + modifier combination.
 * e.g. "123" (no mods) or "123|1:1,2:1" (with mods sorted by id).
 */
function cartItemKey(int $productId, array $modifiers = []): string {
    if (empty($modifiers)) return (string)$productId;

    // Normalize: sort by modifier id, combine id:qty pairs
    $sorted = [];
    foreach ($modifiers as $m) {
        $id  = (int)$m['id'];
        $qty = (int)($m['qty'] ?? 1);
        $sorted[$id] = ($sorted[$id] ?? 0) + $qty;
    }
    ksort($sorted);

    $parts = [];
    foreach ($sorted as $id => $qty) {
        $parts[] = "$id:$qty";
    }
    return $productId . '|' . implode(',', $parts);
}

/**
 * Calculate modifier total per item (sum of modifier_price * qty).
 */
function modifierTotal(array $modifiers): float {
    $total = 0.0;
    foreach ($modifiers as $m) {
        $total += (float)$m['price'] * (int)($m['qty'] ?? 1);
    }
    return round($total, 2);
}

/**
 * Calculate totals for the entire cart.
 * $items = [['unit_price' => float, 'quantity' => int, 'tax_profile' => string, 'modifiers' => [...]], ...]
 */
function calculateCartTotals(array $items, bool $wholesale = false, bool $cartDiscount = false): array {
    $subtotal = 0.0;
    $totalGst = 0.0;
    $totalPst = 0.0;

    foreach ($items as &$item) {
        $basePrice = (float)$item['unit_price'];
        $modExtra  = modifierTotal($item['modifiers'] ?? []);
        $effectivePrice = $basePrice + $modExtra;

        // Determine discount for this item
        $discountPercent = 0;
        if ($wholesale) {
            $effectivePrice = round($effectivePrice * 0.75, 2);
        } elseif ($cartDiscount || !empty($item['discount'])) {
            $effectivePrice = round($effectivePrice * 0.90, 2);
            $discountPercent = 10;
        }

        $line = calculateLineTax(
            $effectivePrice,
            (float)$item['quantity'],
            $item['tax_profile'] ?? 'tax_free'
        );

        $item['effective_unit_price'] = $effectivePrice;
        $item['discount_percent']     = $discountPercent;
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
        'cart_discount' => $cartDiscount,
    ];
}
