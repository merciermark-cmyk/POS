<?php
class PettyCash extends BaseModel {

    public function create(array $data): int {
        return (int)$this->insert(
            'INSERT INTO pos_petty_cash
             (shift_id, terminal_id, user_id, authorized_by, amount, description)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $data['shift_id'],
                $data['terminal_id'] ?? null,
                $data['user_id'],
                $data['authorized_by'] ?? null,
                $data['amount'],
                $data['description'],
            ]
        );
    }

    public function getForShift(int $shiftId): array {
        return $this->findAll(
            'SELECT pc.*, u.username AS user_name, au.username AS authorized_by_name
             FROM pos_petty_cash pc
             JOIN pos_users u ON pc.user_id = u.id
             LEFT JOIN pos_users au ON pc.authorized_by = au.id
             WHERE pc.shift_id = ?
             ORDER BY pc.created_at ASC',
            [$shiftId]
        );
    }

    public function getShiftTotal(int $shiftId): float {
        $row = $this->findOne(
            'SELECT COALESCE(SUM(amount), 0) AS total
             FROM pos_petty_cash WHERE shift_id = ?',
            [$shiftId]
        );
        return (float)($row['total'] ?? 0);
    }

    public function getShiftSummary(int $shiftId): array {
        $row = $this->findOne(
            'SELECT COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total
             FROM pos_petty_cash WHERE shift_id = ?',
            [$shiftId]
        );
        return [
            'count'   => (int)($row['count'] ?? 0),
            'total'   => (float)($row['total'] ?? 0),
            'entries' => $this->getForShift($shiftId),
        ];
    }
}
