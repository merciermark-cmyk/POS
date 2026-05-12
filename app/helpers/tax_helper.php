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
 * Canadian nickel rounding — round to nearest $0.05.
 * Canada eliminated the penny in 2013; cash transactions round to the nearest nickel.
 */
function nickelRound(float $amount): float {
    return round($amount * 20) / 20;
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
        $qty       = (float)$item['quantity'];
        if ($qty <= 0) continue;
        $modExtra  = modifierTotal($item['modifiers'] ?? []);
        $isLooseTea = !empty($item['loose_tea']);

        // Loose tea: don't scale tin price by qty
        if ($isLooseTea) {
            $teaSubtotal = round($basePrice * $qty, 2);
            // Tin price is flat (not multiplied by quantity)
            $lineSubtotal = $teaSubtotal + $modExtra;
            $effectivePrice = round($lineSubtotal / $qty, 4); // for display consistency
        } else {
            $effectivePrice = $basePrice + $modExtra;
        }

        // Determine discount for this item
        $discountPercent = 0;
        $fixedWholesale  = ($wholesale && !empty($item['wholesale_price'])) ? (float)$item['wholesale_price'] : null;

        if ($fixedWholesale !== null) {
            // Fixed wholesale price overrides percentage discount
            $effectivePrice = $fixedWholesale;
            if ($isLooseTea) {
                $lineSubtotal = round($fixedWholesale * $qty, 2);
                $effectivePrice = $fixedWholesale;
            }
        } elseif ($isLooseTea) {
            // Apply discount to the computed line subtotal
            if ($wholesale) {
                $lineSubtotal = round($lineSubtotal * 0.75, 2);
                $effectivePrice = round($lineSubtotal / $qty, 4);
            } elseif ($cartDiscount || !empty($item['discount'])) {
                $lineSubtotal = round($lineSubtotal * 0.90, 2);
                $effectivePrice = round($lineSubtotal / $qty, 4);
                $discountPercent = 10;
            }
        } else {
            if ($wholesale) {
                $effectivePrice = round($effectivePrice * 0.75, 2);
            } elseif ($cartDiscount || !empty($item['discount'])) {
                $effectivePrice = round($effectivePrice * 0.90, 2);
                $discountPercent = 10;
            }
        }

        // Dollar amount override: use the entered dollar amount as subtotal
        $dollarAmount = !empty($item['dollar_amount']) ? (float)$item['dollar_amount'] : null;
        if ($dollarAmount !== null && $fixedWholesale !== null) {
            // Fixed wholesale price: don't apply percentage to dollar amount
        } elseif ($dollarAmount !== null && $wholesale) {
            $dollarAmount = round($dollarAmount * 0.75, 2);
        } elseif ($dollarAmount !== null && ($cartDiscount || !empty($item['discount']))) {
            $dollarAmount = round($dollarAmount * 0.90, 2);
        }

        if ($dollarAmount !== null) {
            // Use dollar amount directly as subtotal; calculate tax on it
            $line = calculateLineTax($dollarAmount, 1.0, $item['tax_profile'] ?? 'tax_free');
            // Adjust effective_unit_price so stored records are consistent
            $effectivePrice = round($dollarAmount / $qty, 4);
        } elseif ($isLooseTea) {
            // Loose tea: we already computed the line subtotal, use qty=1 to avoid double-multiplying
            $line = calculateLineTax($lineSubtotal, 1.0, $item['tax_profile'] ?? 'tax_free');
        } else {
            $line = calculateLineTax(
                $effectivePrice,
                $qty,
                $item['tax_profile'] ?? 'tax_free'
            );
        }

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
