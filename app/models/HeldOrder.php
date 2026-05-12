<?php
class HeldOrder extends BaseModel {

    /**
     * Hold the current session cart.
     * Returns the new held order ID.
     */
    public function hold(int $shiftId, ?int $terminalId, int $heldBy, ?string $label, array $cartState, int $itemCount, float $cartTotal): int|string {
        return $this->insert(
            "INSERT INTO pos_held_orders (shift_id, terminal_id, held_by, label, cart_json, item_count, cart_total, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'held')",
            [$shiftId, $terminalId, $heldBy, $label ?: null, json_encode($cartState), $itemCount, $cartTotal]
        );
    }

    /** Get all active (held) orders for a shift. */
    public function getActiveForShift(int $shiftId): array {
        return $this->findAll(
            "SELECT h.*, u.username AS held_by_name
             FROM pos_held_orders h
             LEFT JOIN pos_users u ON u.id = h.held_by
             WHERE h.shift_id = ? AND h.status = 'held'
             ORDER BY h.created_at DESC",
            [$shiftId]
        );
    }

    /** Count active held orders for a shift. */
    public function countActiveForShift(int $shiftId): int {
        return $this->count(
            "SELECT COUNT(*) FROM pos_held_orders WHERE shift_id = ? AND status = 'held'",
            [$shiftId]
        );
    }

    /** Find a single active held order by ID. */
    public function findActiveById(int $id): ?array {
        return $this->findOne(
            "SELECT * FROM pos_held_orders WHERE id = ? AND status = 'held'",
            [$id]
        );
    }

    /** Mark a held order as resumed. */
    public function resume(int $id, int $resumedBy): int {
        return $this->execute(
            "UPDATE pos_held_orders SET status = 'resumed', resumed_by = ?, resumed_at = NOW() WHERE id = ? AND status = 'held'",
            [$resumedBy, $id]
        );
    }

    /** Expire all held orders for a shift (called on shift close). */
    public function expireForShift(int $shiftId): int {
        return $this->execute(
            "UPDATE pos_held_orders SET status = 'expired' WHERE shift_id = ? AND status = 'held'",
            [$shiftId]
        );
    }

    /** Delete (discard) a held order. */
    public function deleteHeld(int $id): int {
        return $this->execute(
            "DELETE FROM pos_held_orders WHERE id = ? AND status = 'held'",
            [$id]
        );
    }
}
