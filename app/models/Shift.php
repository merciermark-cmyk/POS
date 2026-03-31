<?php
class Shift extends BaseModel {

    public function open(int $userId, float $openingFloat, ?int $terminalId = null): int {
        return (int)$this->insert(
            'INSERT INTO pos_shifts (user_id, terminal_id, opening_float, status)
             VALUES (?, ?, ?, ?)',
            [$userId, $terminalId, $openingFloat, 'open']
        );
    }

    public function close(int $shiftId, float $closingCash, ?string $notes = null): array {
        $shift = $this->findById($shiftId);
        if (!$shift) throw new RuntimeException('Shift not found.');

        // Calculate expected cash: float + cash payments - cash refunds
        $cashPayments = $this->getCashPaymentsTotal($shiftId);
        $cashRefunds  = $this->getCashRefundsTotal($shiftId);
        $expected = round($shift['opening_float'] + $cashPayments - $cashRefunds, 2);
        $overShort = round($closingCash - $expected, 2);

        $this->execute(
            'UPDATE pos_shifts
             SET closed_at = NOW(), closing_cash = ?, expected_cash = ?,
                 over_short = ?, status = ?, notes = ?
             WHERE id = ?',
            [$closingCash, $expected, $overShort, 'closed', $notes, $shiftId]
        );

        return [
            'expected_cash' => $expected,
            'closing_cash'  => $closingCash,
            'over_short'    => $overShort,
        ];
    }

    public function findById(int $id): ?array {
        return $this->findOne('SELECT * FROM pos_shifts WHERE id = ?', [$id]);
    }

    public function getOpen(int $userId): ?array {
        return $this->findOne(
            'SELECT * FROM pos_shifts WHERE user_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
            [$userId, 'open']
        );
    }

    public function getAnyOpen(?int $terminalId = null): ?array {
        if ($terminalId) {
            return $this->getOpenForTerminal($terminalId);
        }
        return $this->findOne(
            'SELECT s.*, u.username, tm.name AS terminal_name FROM pos_shifts s
             JOIN pos_users u ON s.user_id = u.id
             LEFT JOIN pos_terminals tm ON s.terminal_id = tm.id
             WHERE s.status = ? ORDER BY s.id DESC LIMIT 1',
            ['open']
        );
    }

    public function getOpenForTerminal(int $terminalId): ?array {
        return $this->findOne(
            'SELECT s.*, u.username, tm.name AS terminal_name FROM pos_shifts s
             JOIN pos_users u ON s.user_id = u.id
             LEFT JOIN pos_terminals tm ON s.terminal_id = tm.id
             WHERE s.terminal_id = ? AND s.status = ? ORDER BY s.id DESC LIMIT 1',
            [$terminalId, 'open']
        );
    }

    public function getHistory(int $limit = 50, ?int $terminalId = null): array {
        $sql = "SELECT s.*, u.username, tm.name AS terminal_name,
                    (SELECT COUNT(*) FROM pos_transactions t WHERE t.shift_id = s.id AND t.status IN ('completed','partial_refund')) AS transaction_count,
                    (SELECT COALESCE(SUM(t.total), 0) FROM pos_transactions t WHERE t.shift_id = s.id AND t.status IN ('completed','partial_refund')) AS total_sales
             FROM pos_shifts s
             JOIN pos_users u ON s.user_id = u.id
             LEFT JOIN pos_terminals tm ON s.terminal_id = tm.id";
        $params = [];

        if ($terminalId) {
            $sql .= ' WHERE s.terminal_id = ?';
            $params[] = $terminalId;
        }

        $sql .= ' ORDER BY s.opened_at DESC LIMIT ?';
        $params[] = $limit;

        return $this->findAll($sql, $params);
    }

    public function getCashPaymentsTotal(int $shiftId): float {
        $row = $this->findOne(
            "SELECT COALESCE(SUM(p.amount), 0) AS total
             FROM pos_payments p
             JOIN pos_transactions t ON p.transaction_id = t.id
             WHERE t.shift_id = ? AND t.status IN ('completed','partial_refund') AND p.method = ?",
            [$shiftId, 'cash']
        );
        return (float)($row['total'] ?? 0);
    }

    /** Total cash refunds for a shift. */
    public function getCashRefundsTotal(int $shiftId): float {
        $row = $this->findOne(
            'SELECT COALESCE(SUM(rp.amount), 0) AS total
             FROM pos_refund_payments rp
             JOIN pos_refunds r ON rp.refund_id = r.id
             WHERE r.shift_id = ? AND rp.method = ?',
            [$shiftId, 'cash']
        );
        return (float)($row['total'] ?? 0);
    }

    public function getShiftSummary(int $shiftId): array {
        $payments = $this->findAll(
            "SELECT p.method, SUM(p.amount) AS total
             FROM pos_payments p
             JOIN pos_transactions t ON p.transaction_id = t.id
             WHERE t.shift_id = ? AND t.status IN ('completed','partial_refund')
             GROUP BY p.method",
            [$shiftId]
        );

        $totals = $this->findOne(
            "SELECT COUNT(*) AS count,
                    COALESCE(SUM(subtotal), 0) AS subtotal,
                    COALESCE(SUM(gst_amount), 0) AS gst,
                    COALESCE(SUM(pst_amount), 0) AS pst,
                    COALESCE(SUM(total), 0) AS total
             FROM pos_transactions
             WHERE shift_id = ? AND status IN ('completed','partial_refund')",
            [$shiftId]
        );

        $voids = $this->count(
            'SELECT COUNT(*) FROM pos_transactions WHERE shift_id = ? AND status = ?',
            [$shiftId, 'voided']
        );

        // Refund totals
        $refundTotals = $this->findOne(
            'SELECT COUNT(*) AS count, COALESCE(SUM(total), 0) AS total
             FROM pos_refunds WHERE shift_id = ?',
            [$shiftId]
        );

        $refundPayments = $this->findAll(
            'SELECT rp.method, SUM(rp.amount) AS total
             FROM pos_refund_payments rp
             JOIN pos_refunds r ON rp.refund_id = r.id
             WHERE r.shift_id = ?
             GROUP BY rp.method',
            [$shiftId]
        );

        return [
            'payments'          => $payments,
            'transaction_count' => (int)($totals['count'] ?? 0),
            'subtotal'          => (float)($totals['subtotal'] ?? 0),
            'gst'               => (float)($totals['gst'] ?? 0),
            'pst'               => (float)($totals['pst'] ?? 0),
            'total'             => (float)($totals['total'] ?? 0),
            'void_count'        => $voids,
            'refund_count'      => (int)($refundTotals['count'] ?? 0),
            'refund_total'      => (float)($refundTotals['total'] ?? 0),
            'refund_payments'   => $refundPayments,
        ];
    }
}
