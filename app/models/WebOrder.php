<?php
class WebOrder extends BaseModel {

    /**
     * Get PrestaShop orders that were marked "shipped" on the given date.
     * Excludes gift card line items (PS category 63).
     * Uses cross-DB query against PS database (same pattern as GiftCard).
     */
    public function getShippedOnDate(string $date): array {
        if (!PS_DB_NAME) return [];

        $psDb   = PS_DB_NAME;
        $prefix = PS_DB_PREFIX;

        // Sum order_detail lines excluding gift card products, per order
        return $this->findAll(
            "SELECT o.id_order, o.reference,
                    SUM(od.total_price_tax_incl) AS total
             FROM `$psDb`.`{$prefix}order_history` oh
             JOIN `$psDb`.`{$prefix}orders` o
               ON o.id_order = oh.id_order
             JOIN `$psDb`.`{$prefix}order_detail` od
               ON od.id_order = o.id_order
             WHERE oh.id_order_state = ?
               AND DATE(oh.date_add) = ?
               AND od.product_id NOT IN (
                   SELECT cp.id_product
                   FROM `$psDb`.`{$prefix}category_product` cp
                   WHERE cp.id_category = ?
               )
             GROUP BY o.id_order, o.reference
             HAVING total > 0
             ORDER BY oh.date_add ASC",
            [PS_SHIPPED_STATE_ID, $date, PS_GIFT_CARD_CATEGORY_ID]
        );
    }

    /**
     * Get summary of shipped web orders for a date.
     * Returns ['orders' => [...], 'count' => int, 'total' => float]
     */
    public function getSummaryForDate(string $date): array {
        $orders = $this->getShippedOnDate($date);
        $total  = 0.0;
        foreach ($orders as $o) {
            $total += (float)$o['total'];
        }
        return [
            'orders' => $orders,
            'count'  => count($orders),
            'total'  => round($total, 2),
        ];
    }
}
