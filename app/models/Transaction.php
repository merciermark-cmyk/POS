<?php
class Transaction extends BaseModel {

    /**
     * Create a complete transaction atomically:
     * inserts transaction + items + payments, deducts inventory, logs audit.
     */
    public function create(
        int   $shiftId,
        int   $userId,
        array $items,
        array $payments,
        int   $locationId,
        bool  $wholesale = false
    ): int {
        $this->beginTransaction();
        try {
            // Calculate totals
            $subtotal = 0;
            $gstTotal = 0;
            $pstTotal = 0;
            foreach ($items as $item) {
                $subtotal += $item['subtotal'];
                $gstTotal += $item['gst'];
                $pstTotal += $item['pst'];
            }
            $total = round($subtotal + $gstTotal + $pstTotal, 2);

            // Insert transaction
            $txnId = (int)$this->insert(
                'INSERT INTO pos_transactions (shift_id, user_id, subtotal, gst_amount, pst_amount, total, status, is_wholesale)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$shiftId, $userId, round($subtotal, 2), round($gstTotal, 2), round($pstTotal, 2), $total, 'completed', $wholesale ? 1 : 0]
            );

            // Insert items + deduct inventory
            $inventory = new Inventory();
            $audit     = new AuditLog();

            foreach ($items as $item) {
                $lineTotal = round($item['subtotal'] + $item['gst'] + $item['pst'], 2);
                $this->insert(
                    'INSERT INTO pos_transaction_items
                     (transaction_id, product_id, product_name, product_code, quantity,
                      unit_price, tax_profile, gst, pst, line_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $txnId,
                        $item['product_id'],
                        $item['product_name'],
                        $item['product_code'] ?? '',
                        $item['quantity'],
                        $item['unit_price'],
                        $item['tax_profile'],
                        $item['gst'],
                        $item['pst'],
                        $lineTotal,
                    ]
                );

                // Deduct inventory (allows negative)
                [$before, $after] = $inventory->adjustStock(
                    $item['product_id'],
                    $locationId,
                    -$item['quantity']
                );

                $audit->record(
                    $userId,
                    $item['product_id'],
                    $locationId,
                    'pos_sale',
                    $before,
                    -$item['quantity'],
                    $after,
                    'POS sale',
                    $txnId
                );
            }

            // Insert payments
            foreach ($payments as $pay) {
                $this->insert(
                    'INSERT INTO pos_payments (transaction_id, method, amount, reference)
                     VALUES (?, ?, ?, ?)',
                    [$txnId, $pay['method'], $pay['amount'], $pay['reference'] ?? null]
                );
            }

            $this->commit();
            return $txnId;

        } catch (Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * Void a transaction: reverses inventory deductions.
     */
    /**
     * Refund specific items from a completed/partial_refund transaction.
     * Returns the new refund ID.
     */
    public function refund(
        int    $txnId,
        int    $refundedBy,
        int    $shiftId,
        string $reason,
        array  $refundItems,   // [['item_id' => int, 'quantity' => int], ...]
        array  $payments,      // [['method' => string, 'amount' => float, 'reference' => ?string], ...]
        int    $locationId
    ): int {
        $txn = $this->findById($txnId);
        if (!$txn) throw new RuntimeException('Transaction not found.');
        if (!in_array($txn['status'], ['completed', 'partial_refund'], true)) {
            throw new RuntimeException('Only completed or partially-refunded transactions can be refunded.');
        }

        $items = $this->getItems($txnId);
        $itemsById = [];
        foreach ($items as $item) {
            $itemsById[(int)$item['id']] = $item;
        }

        $alreadyRefunded = $this->getRefundedQuantities($txnId);

        // Validate quantities and calculate totals
        $subtotal = 0;
        $gstTotal = 0;
        $pstTotal = 0;
        $refundRows = [];

        foreach ($refundItems as $ri) {
            $itemId = (int)$ri['item_id'];
            $qty    = (int)$ri['quantity'];
            if ($qty <= 0) continue;

            if (!isset($itemsById[$itemId])) {
                throw new RuntimeException("Item #$itemId not found in transaction.");
            }

            $origItem      = $itemsById[$itemId];
            $alreadyQty    = $alreadyRefunded[$itemId] ?? 0;
            $maxRefundable = (int)$origItem['quantity'] - $alreadyQty;

            if ($qty > $maxRefundable) {
                throw new RuntimeException("Cannot refund $qty of \"{$origItem['product_name']}\" — only $maxRefundable remaining.");
            }

            $tax = calculateLineTax((float)$origItem['unit_price'], $qty, $origItem['tax_profile']);

            $refundRows[] = [
                'original_item_id' => $itemId,
                'product_id'       => (int)$origItem['product_id'],
                'product_name'     => $origItem['product_name'],
                'product_code'     => $origItem['product_code'],
                'quantity'         => $qty,
                'unit_price'       => (float)$origItem['unit_price'],
                'tax_profile'      => $origItem['tax_profile'],
                'gst'              => $tax['gst'],
                'pst'              => $tax['pst'],
                'line_total'       => $tax['line_total'],
            ];

            $subtotal += $tax['subtotal'];
            $gstTotal += $tax['gst'];
            $pstTotal += $tax['pst'];
        }

        if (empty($refundRows)) {
            throw new RuntimeException('No items selected for refund.');
        }

        $total = round($subtotal + $gstTotal + $pstTotal, 2);

        // Validate payment total matches refund total
        $payTotal = 0;
        foreach ($payments as $pay) {
            $payTotal += (float)$pay['amount'];
        }
        if (abs(round($payTotal, 2) - $total) > 0.01) {
            throw new RuntimeException('Refund payment total ($' . number_format($payTotal, 2) . ') does not match refund amount ($' . number_format($total, 2) . ').');
        }

        $this->beginTransaction();
        try {
            // Insert refund header
            $refundId = (int)$this->insert(
                'INSERT INTO pos_refunds (original_transaction_id, shift_id, refunded_by, subtotal, gst_amount, pst_amount, total, reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$txnId, $shiftId, $refundedBy, round($subtotal, 2), round($gstTotal, 2), round($pstTotal, 2), $total, $reason]
            );

            // Insert refund items + return inventory
            $inventory = new Inventory();
            $audit     = new AuditLog();

            foreach ($refundRows as $row) {
                $this->insert(
                    'INSERT INTO pos_refund_items
                     (refund_id, original_item_id, product_id, product_name, product_code, quantity, unit_price, tax_profile, gst, pst, line_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $refundId,
                        $row['original_item_id'],
                        $row['product_id'],
                        $row['product_name'],
                        $row['product_code'] ?? '',
                        $row['quantity'],
                        $row['unit_price'],
                        $row['tax_profile'],
                        $row['gst'],
                        $row['pst'],
                        $row['line_total'],
                    ]
                );

                // Return stock
                [$before, $after] = $inventory->adjustStock(
                    $row['product_id'],
                    $locationId,
                    $row['quantity'] // positive = return to stock
                );

                $audit->record(
                    $refundedBy,
                    $row['product_id'],
                    $locationId,
                    'pos_refund',
                    $before,
                    $row['quantity'],
                    $after,
                    "Refund: $reason",
                    $txnId
                );
            }

            // Insert refund payments
            foreach ($payments as $pay) {
                $this->insert(
                    'INSERT INTO pos_refund_payments (refund_id, method, amount, reference)
                     VALUES (?, ?, ?, ?)',
                    [$refundId, $pay['method'], $pay['amount'], $pay['reference'] ?? null]
                );
            }

            // Determine new transaction status: fully refunded or partial
            $allRefunded = $this->getRefundedQuantities($txnId);
            $fullyRefunded = true;
            foreach ($items as $item) {
                $refQty = $allRefunded[(int)$item['id']] ?? 0;
                if ($refQty < (int)$item['quantity']) {
                    $fullyRefunded = false;
                    break;
                }
            }

            $newStatus = $fullyRefunded ? 'refunded' : 'partial_refund';
            $this->execute(
                'UPDATE pos_transactions SET status = ? WHERE id = ?',
                [$newStatus, $txnId]
            );

            $this->commit();
            return $refundId;

        } catch (Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * Get total already-refunded quantities per item for a transaction.
     * Returns [item_id => total_qty_refunded].
     */
    public function getRefundedQuantities(int $txnId): array {
        $rows = $this->findAll(
            'SELECT ri.original_item_id, SUM(ri.quantity) AS refunded_qty
             FROM pos_refund_items ri
             JOIN pos_refunds r ON ri.refund_id = r.id
             WHERE r.original_transaction_id = ?
             GROUP BY ri.original_item_id',
            [$txnId]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['original_item_id']] = (int)$row['refunded_qty'];
        }
        return $map;
    }

    /** Get all refunds for a transaction. */
    public function getRefunds(int $txnId): array {
        return $this->findAll(
            'SELECT r.*, u.username AS refunded_by_name
             FROM pos_refunds r
             JOIN pos_users u ON r.refunded_by = u.id
             WHERE r.original_transaction_id = ?
             ORDER BY r.created_at DESC',
            [$txnId]
        );
    }

    public function getRefundItems(int $refundId): array {
        return $this->findAll(
            'SELECT * FROM pos_refund_items WHERE refund_id = ? ORDER BY id',
            [$refundId]
        );
    }

    public function getRefundPayments(int $refundId): array {
        return $this->findAll(
            'SELECT * FROM pos_refund_payments WHERE refund_id = ? ORDER BY id',
            [$refundId]
        );
    }

    public function findRefundById(int $refundId): ?array {
        return $this->findOne(
            'SELECT r.*, u.username AS refunded_by_name
             FROM pos_refunds r
             JOIN pos_users u ON r.refunded_by = u.id
             WHERE r.id = ?',
            [$refundId]
        );
    }

    public function void(int $txnId, int $voidedBy, string $reason, int $locationId): void {
        $txn = $this->findById($txnId);
        if (!$txn) throw new RuntimeException('Transaction not found.');
        if ($txn['status'] === 'voided') throw new RuntimeException('Transaction already voided.');
        if ($txn['status'] === 'refunded') throw new RuntimeException('Cannot void a fully refunded transaction.');

        $this->beginTransaction();
        try {
            // Mark voided
            $this->execute(
                'UPDATE pos_transactions SET status = ?, voided_by = ?, voided_at = NOW(), void_reason = ?
                 WHERE id = ?',
                ['voided', $voidedBy, $reason, $txnId]
            );

            // Reverse inventory
            $items     = $this->getItems($txnId);
            $inventory = new Inventory();
            $audit     = new AuditLog();

            foreach ($items as $item) {
                [$before, $after] = $inventory->adjustStock(
                    $item['product_id'],
                    $locationId,
                    $item['quantity'] // positive = return to stock
                );

                $audit->record(
                    $voidedBy,
                    $item['product_id'],
                    $locationId,
                    'pos_void',
                    $before,
                    $item['quantity'],
                    $after,
                    "Void: $reason",
                    $txnId
                );
            }

            $this->commit();
        } catch (Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function findById(int $id): ?array {
        return $this->findOne(
            'SELECT t.*, u.username
             FROM pos_transactions t
             JOIN pos_users u ON t.user_id = u.id
             WHERE t.id = ?',
            [$id]
        );
    }

    public function getItems(int $txnId): array {
        return $this->findAll(
            'SELECT * FROM pos_transaction_items WHERE transaction_id = ? ORDER BY id',
            [$txnId]
        );
    }

    public function getPayments(int $txnId): array {
        return $this->findAll(
            'SELECT * FROM pos_payments WHERE transaction_id = ? ORDER BY id',
            [$txnId]
        );
    }

    public function getForShift(int $shiftId): array {
        return $this->findAll(
            'SELECT t.*, u.username
             FROM pos_transactions t
             JOIN pos_users u ON t.user_id = u.id
             WHERE t.shift_id = ?
             ORDER BY t.created_at DESC',
            [$shiftId]
        );
    }

    public function getRecent(int $limit = 50, ?string $dateFrom = null, ?string $dateTo = null): array {
        $sql = 'SELECT t.*, u.username, s.id AS shift_number
                FROM pos_transactions t
                JOIN pos_users u ON t.user_id = u.id
                JOIN pos_shifts s ON t.shift_id = s.id
                WHERE 1=1';
        $params = [];

        if ($dateFrom) {
            $sql .= ' AND DATE(t.created_at) >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= ' AND DATE(t.created_at) <= ?';
            $params[] = $dateTo;
        }

        $sql .= ' ORDER BY t.created_at DESC LIMIT ?';
        $params[] = $limit;

        return $this->findAll($sql, $params);
    }

    public function getDailySales(?string $date = null): array {
        $date = $date ?: date('Y-m-d');
        return $this->findOne(
            'SELECT COUNT(*) AS count,
                    COALESCE(SUM(subtotal), 0) AS subtotal,
                    COALESCE(SUM(gst_amount), 0) AS gst,
                    COALESCE(SUM(pst_amount), 0) AS pst,
                    COALESCE(SUM(total), 0) AS total
             FROM pos_transactions
             WHERE DATE(created_at) = ? AND status IN ('completed','partial_refund')',
            [$date]
        ) ?: ['count' => 0, 'subtotal' => 0, 'gst' => 0, 'pst' => 0, 'total' => 0];
    }

    /**
     * Category-level sales breakdown for a WHERE clause.
     */
    private function getCategorySales(string $whereClause, array $params): array {
        return $this->findAll(
            "SELECT CASE WHEN t.is_wholesale = 1 THEN 'Wholesale' ELSE c.name END AS category_name,
                    SUM(ti.quantity) AS qty,
                    COALESCE(SUM(ti.line_total - ti.gst - ti.pst), 0) AS subtotal,
                    COALESCE(SUM(ti.gst), 0) AS gst,
                    COALESCE(SUM(ti.pst), 0) AS pst,
                    COALESCE(SUM(ti.line_total), 0) AS line_total
             FROM pos_transaction_items ti
             JOIN pos_transactions t ON ti.transaction_id = t.id
             LEFT JOIN products p ON ti.product_id = p.id
             LEFT JOIN categories c ON p.category_id = c.id
             WHERE t.status IN ('completed','partial_refund') $whereClause
             GROUP BY CASE WHEN t.is_wholesale = 1 THEN 'Wholesale' ELSE c.name END
             ORDER BY category_name",
            $params
        );
    }

    public function getDailyCategorySales(string $date): array {
        return $this->getCategorySales('AND DATE(t.created_at) = ?', [$date]);
    }

    public function getMonthlyCategorySales(int $year, int $month): array {
        return $this->getCategorySales(
            'AND YEAR(t.created_at) = ? AND MONTH(t.created_at) = ?',
            [$year, $month]
        );
    }

    public function getMonthlySummary(int $year, int $month): array {
        return $this->findOne(
            'SELECT COUNT(*) AS count,
                    COALESCE(SUM(subtotal), 0) AS subtotal,
                    COALESCE(SUM(gst_amount), 0) AS gst,
                    COALESCE(SUM(pst_amount), 0) AS pst,
                    COALESCE(SUM(total), 0) AS total
             FROM pos_transactions
             WHERE YEAR(created_at) = ? AND MONTH(created_at) = ? AND status IN ('completed','partial_refund')',
            [$year, $month]
        ) ?: ['count' => 0, 'subtotal' => 0, 'gst' => 0, 'pst' => 0, 'total' => 0];
    }

    public function getProductSales(?string $dateFrom = null, ?string $dateTo = null): array {
        $sql = 'SELECT ti.product_id, ti.product_name, ti.product_code,
                       SUM(ti.quantity) AS total_qty,
                       SUM(ti.line_total) AS total_revenue
                FROM pos_transaction_items ti
                JOIN pos_transactions t ON ti.transaction_id = t.id
                WHERE t.status IN ('completed','partial_refund')';
        $params = [];

        if ($dateFrom) {
            $sql .= ' AND DATE(t.created_at) >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= ' AND DATE(t.created_at) <= ?';
            $params[] = $dateTo;
        }

        $sql .= ' GROUP BY ti.product_id, ti.product_name, ti.product_code
                   ORDER BY total_revenue DESC';

        return $this->findAll($sql, $params);
    }
}
