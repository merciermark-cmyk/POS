<?php
class Inventory extends BaseModel {

    public function getStock(int $productId, int $locationId): float {
        $row = $this->findOne(
            'SELECT quantity FROM inventory WHERE product_id = ? AND location_id = ?',
            [$productId, $locationId]
        );
        return $row ? (float)$row['quantity'] : 0.0;
    }

    /**
     * Adjust stock by a signed delta.
     * POS version: allows negative stock (never refuse a sale).
     * Must be called inside a transaction.
     */
    public function adjustStock(int $productId, int $locationId, float $delta): array {
        $row = $this->findOne(
            'SELECT quantity FROM inventory
             WHERE product_id = ? AND location_id = ?
             FOR UPDATE',
            [$productId, $locationId]
        );
        $before = $row ? (float)$row['quantity'] : 0.0;
        $after  = round($before + $delta, 2);

        // POS: allow negative stock — never refuse a sale

        if ($row) {
            $this->execute(
                'UPDATE inventory SET quantity = ?, updated_at = NOW()
                 WHERE product_id = ? AND location_id = ?',
                [$after, $productId, $locationId]
            );
        } else {
            $this->execute(
                'INSERT INTO inventory (product_id, location_id, quantity)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantity = quantity + ?, updated_at = NOW()',
                [$productId, $locationId, $after, $delta]
            );
        }

        return [$before, $after];
    }
}
