<?php
class Shift extends BaseModel {

    public function open(int $userId, float $openingFloat, ?int $terminalId = null): int {
        return (int)$this->insert(
            'INSERT INTO pos_shifts (user_id, terminal_id, opening_float, status)
             VALUES (?, ?, ?, ?)',
            [$userId, $terminalId, $openingFloat, 'open']
        );
    }

    public function close(int $shiftId, float $closingCash, ?string $notes = null, ?float $closingCard = null, ?float $closingTips = null, ?float $cashDeposit = null): array {
        $shift = $this->findById($shiftId);
        if (!$shift) throw new RuntimeException('Shift not found.');

        // Calculate expected cash: float + cash payments - cash refunds - standalone cash refunds - petty cash + gift card cash sales
        $cashPayments = $this->getCashPaymentsTotal($shiftId);
        $cashRefunds  = $this->getCashRefundsTotal($shiftId);
        $standaloneCashRefunds = (new StandaloneRefund())->getCashRefundsTotal($shiftId);
        $pettyCashTotal = (new PettyCash())->getShiftTotal($shiftId);
        $gcCashTotal = (new GiftCardSale())->getCashTotal($shiftId);
        $expected = round($shift['opening_float'] + $cashPayments - $cashRefunds - $standaloneCashRefunds - $pettyCashTotal + $gcCashTotal, 2);
        $overShort = round($closingCash - $expected, 2);

        // Card reconciliation (tips are included in terminal batch total, so subtract them)
        $expectedCard  = null;
        $cardOverShort = null;
        if ($closingCard !== null) {
            $gcCardTotal   = (new GiftCardSale())->getCardTotal($shiftId);
            $expectedCard  = round($this->getCardPaymentsTotal($shiftId) - $this->getCardRefundsTotal($shiftId) - (new StandaloneRefund())->getCardRefundsTotal($shiftId) + $gcCardTotal, 2);
            $tips = $closingTips ?? 0;
            $cardOverShort = round($closingCard - $expectedCard - $tips, 2);
        }

        $this->execute(
            'UPDATE pos_shifts
             SET closed_at = NOW(), closing_cash = ?, expected_cash = ?,
                 over_short = ?, cash_deposit = ?, closing_card = ?, expected_card = ?,
                 card_over_short = ?, closing_tips = ?, status = ?, notes = ?
             WHERE id = ?',
            [$closingCash, $expected, $overShort, $cashDeposit, $closingCard, $expectedCard, $cardOverShort, $closingTips, 'closed', $notes, $shiftId]
        );

        // Reset negative shop stock to zero
        $userId = (int)$shift['user_id'];
        $negativeResets = $this->resetNegativeStock($userId);

        return [
            'expected_cash'    => $expected,
            'closing_cash'     => $closingCash,
            'over_short'       => $overShort,
            'cash_deposit'     => $cashDeposit,
            'expected_card'    => $expectedCard,
            'closing_card'     => $closingCard,
            'card_over_short'  => $cardOverShort,
            'closing_tips'     => $closingTips,
            'negative_resets'  => $negativeResets,
        ];
    }

    /**
     * Reset all negative Shop inventory to zero on shift close.
     * Logs each correction to audit_log for training visibility.
     * Returns array of corrected products: [['name'=>..., 'was'=>..., 'product_id'=>...], ...]
     */
    public function resetNegativeStock(int $userId): array {
        $locationId = (new PosSetting())->getShopLocationId();
        if (!$locationId) return [];

        $negatives = $this->findAll(
            'SELECT i.product_id, i.quantity, p.name
             FROM inventory i
             JOIN products p ON p.id = i.product_id
             WHERE i.location_id = ? AND i.quantity < 0',
            [$locationId]
        );
        if (!$negatives) return [];

        $inventory = new Inventory();
        $audit     = new AuditLog();
        $resets    = [];

        foreach ($negatives as $row) {
            $pid    = (int)$row['product_id'];
            $before = (float)$row['quantity'];
            $delta  = round(0 - $before, 2); // bring to zero

            $this->beginTransaction();
            try {
                $inventory->adjustStock($pid, $locationId, $delta);

                $audit->record(
                    $userId,
                    $pid,
                    $locationId,
                    'adjustment',
                    $before,
                    $delta,
                    0.0,
                    'Shift close: negative stock reset to zero'
                );

                $this->commit();

                $resets[] = [
                    'product_id' => $pid,
                    'name'       => $row['name'],
                    'was'        => $before,
                ];
            } catch (Throwable $e) {
                $this->rollBack();
                // Skip this product, continue with others
            }
        }

        return $resets;
    }

    public function updateHeartbeat(int $shiftId, string $sessionId): void {
        $this->execute(
            'UPDATE pos_shifts SET last_heartbeat = NOW(), heartbeat_session = ? WHERE id = ?',
            [$sessionId, $shiftId]
        );
    }

    public function isInUse(int $shiftId, string $currentSessionId): bool {
        $row = $this->findOne(
            'SELECT last_heartbeat, heartbeat_session FROM pos_shifts WHERE id = ?',
            [$shiftId]
        );
        if (!$row || !$row['last_heartbeat'] || !$row['heartbeat_session']) return false;
        if ($row['heartbeat_session'] === $currentSessionId) return false;
        return (strtotime('now') - strtotime($row['last_heartbeat'])) < 60;
    }

    public function clearHeartbeat(int $shiftId): void {
        $this->execute(
            'UPDATE pos_shifts SET last_heartbeat = NULL, heartbeat_session = NULL WHERE id = ?',
            [$shiftId]
        );
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

    public function getOpenForUserAndTerminal(int $userId, int $terminalId): ?array {
        return $this->findOne(
            'SELECT * FROM pos_shifts WHERE user_id = ? AND terminal_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
            [$userId, $terminalId, 'open']
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

    /** Sum of closing_tips for shifts closed within a date range. */
    public function getTipsForRange(string $from, string $to): float {
        $row = $this->findOne(
            "SELECT COALESCE(SUM(closing_tips), 0) AS total
             FROM pos_shifts
             WHERE status = 'closed' AND DATE(closed_at) BETWEEN ? AND ?",
            [$from, $to]
        );
        return (float)($row['total'] ?? 0);
    }

    public function getExpectedCash(int $shiftId, ?string $cutoffTime = null): array {
        $shift = $this->findById($shiftId);
        if (!$shift) throw new RuntimeException('Shift not found.');

        $openingFloat = (float)$shift['opening_float'];

        // Cash retained = cash tendered minus change given back
        // Change = total payments - transaction total (always returned as cash)
        // Nickel rounding: use ROUND(t.total * 20) / 20 so change matches what was actually given
        $sql = "SELECT COALESCE(SUM(
                    p_cash.cash_paid - GREATEST(0, p_all.total_paid - ROUND(t.total * 20) / 20)
                ), 0) AS total
                FROM pos_transactions t
                JOIN (SELECT transaction_id, SUM(amount) AS cash_paid FROM pos_payments WHERE method = 'cash' GROUP BY transaction_id) p_cash ON p_cash.transaction_id = t.id
                JOIN (SELECT transaction_id, SUM(amount) AS total_paid FROM pos_payments GROUP BY transaction_id) p_all ON p_all.transaction_id = t.id
                WHERE t.shift_id = ? AND t.status IN ('completed','partial_refund')";
        $params = [$shiftId];
        if ($cutoffTime) {
            $sql .= ' AND t.created_at <= ?';
            $params[] = $cutoffTime;
        }
        $cashSales = (float)($this->findOne($sql, $params)['total'] ?? 0);

        // Cash refunds (with optional time filter)
        $sql = 'SELECT COALESCE(SUM(rp.amount), 0) AS total
                FROM pos_refund_payments rp
                JOIN pos_refunds r ON rp.refund_id = r.id
                WHERE r.shift_id = ? AND rp.method = ?';
        $params = [$shiftId, 'cash'];
        if ($cutoffTime) {
            $sql .= ' AND r.created_at <= ?';
            $params[] = $cutoffTime;
        }
        $cashRefunds = (float)($this->findOne($sql, $params)['total'] ?? 0);

        // Standalone cash refunds (with optional time filter)
        $sql = 'SELECT COALESCE(SUM(amount), 0) AS total
                FROM pos_standalone_refunds
                WHERE shift_id = ? AND payment_method = ?';
        $params = [$shiftId, 'cash'];
        if ($cutoffTime) {
            $sql .= ' AND created_at <= ?';
            $params[] = $cutoffTime;
        }
        $standaloneCashRefunds = (float)($this->findOne($sql, $params)['total'] ?? 0);

        // Petty cash (with optional time filter)
        $sql = 'SELECT COALESCE(SUM(amount), 0) AS total
                FROM pos_petty_cash WHERE shift_id = ?';
        $params = [$shiftId];
        if ($cutoffTime) {
            $sql .= ' AND created_at <= ?';
            $params[] = $cutoffTime;
        }
        $pettyCash = (float)($this->findOne($sql, $params)['total'] ?? 0);

        // Gift card cash sales (cash received for gift card purchases)
        $sql = "SELECT COALESCE(SUM(amount), 0) AS total
                FROM pos_gift_card_sales WHERE shift_id = ? AND payment_method = 'cash'";
        $params2 = [$shiftId];
        if ($cutoffTime) {
            $sql .= ' AND created_at <= ?';
            $params2[] = $cutoffTime;
        }
        $gcCash = (float)($this->findOne($sql, $params2)['total'] ?? 0);

        $expected = round($openingFloat + $cashSales - $cashRefunds - $standaloneCashRefunds - $pettyCash + $gcCash, 2);

        return [
            'opening_float'          => $openingFloat,
            'cash_sales'             => $cashSales,
            'cash_refunds'           => $cashRefunds,
            'standalone_cash_refunds'=> $standaloneCashRefunds,
            'petty_cash'             => $pettyCash,
            'gift_card_cash'         => $gcCash,
            'expected_cash'          => $expected,
        ];
    }

    public function getHistory(int $limit = 50, ?int $terminalId = null): array {
        $sql = "SELECT s.*, u.username, tm.name AS terminal_name,
                    cu.username AS closed_by_name,
                    (SELECT COUNT(*) FROM pos_transactions t WHERE t.shift_id = s.id AND t.status IN ('completed','partial_refund')) AS transaction_count,
                    (SELECT COALESCE(SUM(t.total), 0) FROM pos_transactions t WHERE t.shift_id = s.id AND t.status IN ('completed','partial_refund')) AS total_sales
             FROM pos_shifts s
             JOIN pos_users u ON s.user_id = u.id
             LEFT JOIN pos_users cu ON s.closed_by = cu.id
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
        // Cash retained = cash tendered minus change given back
        // Nickel rounding: use ROUND(t.total * 20) / 20 so change matches what was actually given
        $row = $this->findOne(
            "SELECT COALESCE(SUM(
                p_cash.cash_paid - GREATEST(0, p_all.total_paid - ROUND(t.total * 20) / 20)
            ), 0) AS total
             FROM pos_transactions t
             JOIN (SELECT transaction_id, SUM(amount) AS cash_paid FROM pos_payments WHERE method = 'cash' GROUP BY transaction_id) p_cash ON p_cash.transaction_id = t.id
             JOIN (SELECT transaction_id, SUM(amount) AS total_paid FROM pos_payments GROUP BY transaction_id) p_all ON p_all.transaction_id = t.id
             WHERE t.shift_id = ? AND t.status IN ('completed','partial_refund')",
            [$shiftId]
        );
        return (float)($row['total'] ?? 0);
    }

    /** Total card payments for a shift (card + moneris methods). */
    public function getCardPaymentsTotal(int $shiftId): float {
        $row = $this->findOne(
            "SELECT COALESCE(SUM(p.amount), 0) AS total
             FROM pos_payments p
             JOIN pos_transactions t ON p.transaction_id = t.id
             WHERE t.shift_id = ? AND t.status IN ('completed','partial_refund') AND p.method IN ('card','moneris')",
            [$shiftId]
        );
        return (float)($row['total'] ?? 0);
    }

    /** Total card refunds for a shift. */
    public function getCardRefundsTotal(int $shiftId): float {
        $row = $this->findOne(
            'SELECT COALESCE(SUM(rp.amount), 0) AS total
             FROM pos_refund_payments rp
             JOIN pos_refunds r ON rp.refund_id = r.id
             WHERE r.shift_id = ? AND rp.method IN (?,?)',
            [$shiftId, 'card', 'moneris']
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
        // Nickel rounding: use ROUND(t.total * 20) / 20 for cash change calculation
        $payments = $this->findAll(
            "SELECT p.method,
                    SUM(CASE WHEN p.method = 'cash'
                        THEN p.amount - GREATEST(0, p_all.total_paid - ROUND(t.total * 20) / 20)
                        ELSE p.amount END) AS total
             FROM pos_payments p
             JOIN pos_transactions t ON p.transaction_id = t.id
             JOIN (SELECT transaction_id, SUM(amount) AS total_paid FROM pos_payments GROUP BY transaction_id) p_all ON p_all.transaction_id = t.id
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

        // Standalone refund totals
        $srModel = new StandaloneRefund();
        $srTotals   = $srModel->getShiftTotals($shiftId);
        $srPayments = $srModel->getShiftPaymentBreakdown($shiftId);

        // Petty cash totals
        $pcModel   = new PettyCash();
        $pcSummary = $pcModel->getShiftSummary($shiftId);

        // Gift card sales totals
        $gcModel   = new GiftCardSale();
        $gcSummary = $gcModel->getShiftSummary($shiftId);

        return [
            'payments'                    => $payments,
            'transaction_count'           => (int)($totals['count'] ?? 0),
            'subtotal'                    => (float)($totals['subtotal'] ?? 0),
            'gst'                         => (float)($totals['gst'] ?? 0),
            'pst'                         => (float)($totals['pst'] ?? 0),
            'total'                       => (float)($totals['total'] ?? 0),
            'void_count'                  => $voids,
            'refund_count'                => (int)($refundTotals['count'] ?? 0),
            'refund_total'                => (float)($refundTotals['total'] ?? 0),
            'refund_payments'             => $refundPayments,
            'standalone_refund_count'     => $srTotals['count'],
            'standalone_refund_total'     => $srTotals['total'],
            'standalone_refund_payments'  => $srPayments,
            'petty_cash_count'            => $pcSummary['count'],
            'petty_cash_total'            => $pcSummary['total'],
            'petty_cash_entries'          => $pcSummary['entries'],
            'gift_card_sales_count'       => $gcSummary['count'],
            'gift_card_sales_total'       => $gcSummary['total'],
            'gift_card_sales_card_total'  => $gcSummary['card_total'],
            'gift_card_sales_cash_total'  => $gcSummary['cash_total'],
            'gift_card_sales_entries'     => $gcSummary['entries'],
        ];
    }
}
