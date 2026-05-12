<?php
class GiftCardSale extends BaseModel {

    public function create(array $data): int {
        return (int)$this->insert(
            'INSERT INTO pos_gift_card_sales
             (shift_id, terminal_id, user_id, amount, payment_method, notes)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $data['shift_id'],
                $data['terminal_id'] ?? null,
                $data['user_id'],
                $data['amount'],
                $data['payment_method'] ?? 'card',
                $data['notes'] ?? null,
            ]
        );
    }

    public function getForShift(int $shiftId): array {
        return $this->findAll(
            'SELECT gc.*, u.username AS user_name
             FROM pos_gift_card_sales gc
             JOIN pos_users u ON gc.user_id = u.id
             WHERE gc.shift_id = ?
             ORDER BY gc.created_at ASC',
            [$shiftId]
        );
    }

    public function getShiftTotal(int $shiftId): float {
        $row = $this->findOne(
            'SELECT COALESCE(SUM(amount), 0) AS total
             FROM pos_gift_card_sales WHERE shift_id = ?',
            [$shiftId]
        );
        return (float)($row['total'] ?? 0);
    }

    public function getCardTotal(int $shiftId): float {
        $row = $this->findOne(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM pos_gift_card_sales WHERE shift_id = ? AND payment_method = 'card'",
            [$shiftId]
        );
        return (float)($row['total'] ?? 0);
    }

    public function getCashTotal(int $shiftId): float {
        $row = $this->findOne(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM pos_gift_card_sales WHERE shift_id = ? AND payment_method = 'cash'",
            [$shiftId]
        );
        return (float)($row['total'] ?? 0);
    }

    public function getShiftSummary(int $shiftId): array {
        $row = $this->findOne(
            'SELECT COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total,
                    COALESCE(SUM(CASE WHEN payment_method = \'card\' THEN amount ELSE 0 END), 0) AS card_total,
                    COALESCE(SUM(CASE WHEN payment_method = \'cash\' THEN amount ELSE 0 END), 0) AS cash_total
             FROM pos_gift_card_sales WHERE shift_id = ?',
            [$shiftId]
        );
        return [
            'count'      => (int)($row['count'] ?? 0),
            'total'      => (float)($row['total'] ?? 0),
            'card_total' => (float)($row['card_total'] ?? 0),
            'cash_total' => (float)($row['cash_total'] ?? 0),
            'entries'    => $this->getForShift($shiftId),
        ];
    }
}
