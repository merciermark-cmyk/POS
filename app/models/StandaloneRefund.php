<?php
class StandaloneRefund extends BaseModel {

    public function create(array $data): int {
        return (int)$this->insert(
            'INSERT INTO pos_standalone_refunds
             (shift_id, terminal_id, processed_by, authorized_by, amount, payment_method, reason, customer_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['shift_id'],
                $data['terminal_id'] ?? null,
                $data['processed_by'],
                $data['authorized_by'] ?? null,
                $data['amount'],
                $data['payment_method'],
                $data['reason'],
                $data['customer_name'] ?? null,
            ]
        );
    }

    public function findById(int $id): ?array {
        return $this->findOne(
            'SELECT sr.*, u.username AS processed_by_name, au.username AS authorized_by_name
             FROM pos_standalone_refunds sr
             JOIN pos_users u ON sr.processed_by = u.id
             LEFT JOIN pos_users au ON sr.authorized_by = au.id
             WHERE sr.id = ?',
            [$id]
        );
    }

    public function getForShift(int $shiftId): array {
        return $this->findAll(
            'SELECT sr.*, u.username AS processed_by_name, au.username AS authorized_by_name
             FROM pos_standalone_refunds sr
             JOIN pos_users u ON sr.processed_by = u.id
             LEFT JOIN pos_users au ON sr.authorized_by = au.id
             WHERE sr.shift_id = ?
             ORDER BY sr.created_at DESC',
            [$shiftId]
        );
    }

    /** Total standalone cash refunds for a shift (used in expected cash calc). */
    public function getCashRefundsTotal(int $shiftId): float {
        $row = $this->findOne(
            'SELECT COALESCE(SUM(amount), 0) AS total
             FROM pos_standalone_refunds
             WHERE shift_id = ? AND payment_method = ?',
            [$shiftId, 'cash']
        );
        return (float)($row['total'] ?? 0);
    }

    /** Total standalone card refunds for a shift. */
    public function getCardRefundsTotal(int $shiftId): float {
        $row = $this->findOne(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM pos_standalone_refunds
             WHERE shift_id = ? AND payment_method IN ('card','moneris')",
            [$shiftId]
        );
        return (float)($row['total'] ?? 0);
    }

    /** Count + total for shift summary. */
    public function getShiftTotals(int $shiftId): array {
        $row = $this->findOne(
            'SELECT COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total
             FROM pos_standalone_refunds WHERE shift_id = ?',
            [$shiftId]
        );
        return [
            'count' => (int)($row['count'] ?? 0),
            'total' => (float)($row['total'] ?? 0),
        ];
    }

    /** Payment method breakdown for shift summary. */
    public function getShiftPaymentBreakdown(int $shiftId): array {
        return $this->findAll(
            'SELECT payment_method AS method, SUM(amount) AS total
             FROM pos_standalone_refunds
             WHERE shift_id = ?
             GROUP BY payment_method',
            [$shiftId]
        );
    }
}
