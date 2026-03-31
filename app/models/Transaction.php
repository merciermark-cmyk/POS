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
        bool  $wholesale = false,
        bool  $cartDiscount = false
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
            $terminalId = $_SESSION['pos_terminal_id'] ?? null;
            $txnDiscountPct = $cartDiscount ? 10 : 0;
            $txnId = (int)$this->insert(
                'INSERT INTO pos_transactions (shift_id, user_id, terminal_id, subtotal, gst_amount, pst_amount, total, status, is_wholesale, discount_percent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$shiftId, $userId, $terminalId, round($subtotal, 2), round($gstTotal, 2), round($pstTotal, 2), $total, 'completed', $wholesale ? 1 : 0, $txnDiscountPct]
            );

            // Insert items + deduct inventory
            $inventory = new Inventory();
            $audit     = new AuditLog();
            $modifierModel = new Modifier();

            // Batch-fetch track_inventory flags for all products in this transaction
            $productIds = array_unique(array_column($items, 'product_id'));
            $trackMap = [];
            if ($productIds) {
                $placeholders = implode(',', array_fill(0, count($productIds), '?'));
                $rows = $this->findAll(
                    "SELECT id, track_inventory FROM products WHERE id IN ($placeholders)",
                    array_values($productIds)
                );
                foreach ($rows as $r) {
                    $trackMap[(int)$r['id']] = (int)$r['track_inventory'];
                }
            }

            foreach ($items as $item) {
                // Use effective_unit_price (base + modifiers) as the stored unit_price
                $storedUnitPrice = $item['effective_unit_price'] ?? $item['unit_price'];
                $lineTotal = round($item['subtotal'] + $item['gst'] + $item['pst'], 2);
                $itemDiscountPct = (float)($item['discount_percent'] ?? 0);
                $itemId = (int)$this->insert(
                    'INSERT INTO pos_transaction_items
                     (transaction_id, product_id, product_name, product_code, quantity,
                      unit_price, tax_profile, gst, pst, line_total, discount_percent)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $txnId,
                        $item['product_id'],
                        $item['product_name'],
                        $item['product_code'] ?? '',
                        $item['quantity'],
                        $storedUnitPrice,
                        $item['tax_profile'],
                        $item['gst'],
                        $item['pst'],
                        $lineTotal,
                        $itemDiscountPct,
                    ]
                );

                // Save modifiers for this transaction item
                if (!empty($item['modifiers'])) {
                    $modifierModel->saveTransactionItemModifiers($itemId, $item['modifiers']);
                }

                // Deduct inventory only for tracked products
                if (($trackMap[(int)$item['product_id']] ?? 1) === 1) {
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
            }

            // Insert payments
            foreach ($payments as $pay) {
                $this->insert(
                    'INSERT INTO pos_payments (transaction_id, method, amount, reference)
                     VALUES (?, ?, ?, ?)',
                    [$txnId, $pay['method'], $pay['amount'], $pay['reference'] ?? null]
                );
            }

            // Compute daily / monthly / annual sale numbers
            $counters = $this->findOne(
                "SELECT
                    (SELECT COUNT(*) FROM pos_transactions WHERE status = 'completed' AND DATE(created_at) = CURDATE() AND id < ?) + 1 AS daily_number,
                    (SELECT COUNT(*) FROM pos_transactions WHERE status = 'completed' AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) AND id < ?) + 1 AS monthly_number,
                    (SELECT COUNT(*) FROM pos_transactions WHERE status = 'completed' AND YEAR(created_at) = YEAR(CURDATE()) AND id < ?) + 1 AS annual_number",
                [$txnId, $txnId, $txnId]
            );
            $this->execute(
                'UPDATE pos_transactions SET daily_number = ?, monthly_number = ?, annual_number = ? WHERE id = ?',
                [(int)$counters['daily_number'], (int)$counters['monthly_number'], (int)$counters['annual_number'], $txnId]
            );

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
            $qty    = (float)$ri['quantity'];
            if ($qty <= 0) continue;

            if (!isset($itemsById[$itemId])) {
                throw new RuntimeException("Item #$itemId not found in transaction.");
            }

            $origItem      = $itemsById[$itemId];
            $alreadyQty    = $alreadyRefunded[$itemId] ?? 0;
            $maxRefundable = (float)$origItem['quantity'] - $alreadyQty;

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

            // Batch-fetch track_inventory flags
            $refundProductIds = array_unique(array_column($refundRows, 'product_id'));
            $trackMap = [];
            if ($refundProductIds) {
                $placeholders = implode(',', array_fill(0, count($refundProductIds), '?'));
                $rows = $this->findAll(
                    "SELECT id, track_inventory FROM products WHERE id IN ($placeholders)",
                    array_values($refundProductIds)
                );
                foreach ($rows as $r) {
                    $trackMap[(int)$r['id']] = (int)$r['track_inventory'];
                }
            }

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

                // Return stock only for tracked products
                if (($trackMap[(int)$row['product_id']] ?? 1) === 1) {
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
                if ($refQty < (float)$item['quantity']) {
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
            $map[(int)$row['original_item_id']] = (float)$row['refunded_qty'];
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

            // Batch-fetch track_inventory flags
            $productIds = array_unique(array_column($items, 'product_id'));
            $trackMap = [];
            if ($productIds) {
                $placeholders = implode(',', array_fill(0, count($productIds), '?'));
                $rows = $this->findAll(
                    "SELECT id, track_inventory FROM products WHERE id IN ($placeholders)",
                    array_values($productIds)
                );
                foreach ($rows as $r) {
                    $trackMap[(int)$r['id']] = (int)$r['track_inventory'];
                }
            }

            foreach ($items as $item) {
                if (($trackMap[(int)$item['product_id']] ?? 1) === 0) continue;

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

    /**
     * Get transaction items with their modifiers attached.
     * Each item gets a 'modifiers' key with an array of modifier rows.
     */
    public function getItemsWithModifiers(int $txnId): array {
        $items = $this->getItems($txnId);
        if (empty($items)) return $items;

        $itemIds = array_map(fn($i) => (int)$i['id'], $items);
        $modMap = (new Modifier())->getModifiersForTransactionItems($itemIds);

        foreach ($items as &$item) {
            $item['modifiers'] = $modMap[(int)$item['id']] ?? [];
        }
        unset($item);

        return $items;
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

    public function getDailySales(?string $date = null, ?int $terminalId = null): array {
        $date = $date ?: date('Y-m-d');
        $sql = "SELECT COUNT(*) AS count,
                    COALESCE(SUM(subtotal), 0) AS subtotal,
                    COALESCE(SUM(gst_amount), 0) AS gst,
                    COALESCE(SUM(pst_amount), 0) AS pst,
                    COALESCE(SUM(total), 0) AS total
             FROM pos_transactions
             WHERE DATE(created_at) = ? AND status IN ('completed','partial_refund')";
        $params = [$date];
        if ($terminalId) {
            $sql .= ' AND terminal_id = ?';
            $params[] = $terminalId;
        }
        return $this->findOne($sql, $params) ?: ['count' => 0, 'subtotal' => 0, 'gst' => 0, 'pst' => 0, 'total' => 0];
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

    public function getDailyCategorySales(string $date, ?int $terminalId = null): array {
        $where = 'AND DATE(t.created_at) = ?';
        $params = [$date];
        if ($terminalId) {
            $where .= ' AND t.terminal_id = ?';
            $params[] = $terminalId;
        }
        return $this->getCategorySales($where, $params);
    }

    public function getMonthlyCategorySales(int $year, int $month, ?int $terminalId = null): array {
        $where = 'AND YEAR(t.created_at) = ? AND MONTH(t.created_at) = ?';
        $params = [$year, $month];
        if ($terminalId) {
            $where .= ' AND t.terminal_id = ?';
            $params[] = $terminalId;
        }
        return $this->getCategorySales($where, $params);
    }

    public function getMonthlySummary(int $year, int $month, ?int $terminalId = null): array {
        $sql = "SELECT COUNT(*) AS count,
                    COALESCE(SUM(subtotal), 0) AS subtotal,
                    COALESCE(SUM(gst_amount), 0) AS gst,
                    COALESCE(SUM(pst_amount), 0) AS pst,
                    COALESCE(SUM(total), 0) AS total
             FROM pos_transactions
             WHERE YEAR(created_at) = ? AND MONTH(created_at) = ? AND status IN ('completed','partial_refund')";
        $params = [$year, $month];
        if ($terminalId) {
            $sql .= ' AND terminal_id = ?';
            $params[] = $terminalId;
        }
        return $this->findOne($sql, $params) ?: ['count' => 0, 'subtotal' => 0, 'gst' => 0, 'pst' => 0, 'total' => 0];
    }

    /**
     * Insert a manual entry transaction (no items, no inventory).
     */
    public function insertManualEntry(array $data): int {
        return (int)$this->insert(
            'INSERT INTO pos_transactions (shift_id, user_id, terminal_id, subtotal, gst_amount, pst_amount, total, status, is_manual_entry, transaction_count, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
            [
                $data['shift_id'],
                $data['user_id'],
                $data['terminal_id'],
                $data['subtotal'],
                $data['gst_amount'],
                $data['pst_amount'],
                $data['total'],
                'completed',
                $data['transaction_count'] ?? null,
                $data['notes'] ?? null,
                $data['created_at'],
            ]
        );
    }

    /** Insert a single payment row. */
    public function insertPayment(int $txnId, string $method, float $amount, ?string $reference = null): void {
        $this->insert(
            'INSERT INTO pos_payments (transaction_id, method, amount, reference) VALUES (?, ?, ?, ?)',
            [$txnId, $method, $amount, $reference]
        );
    }

    /** Set daily/monthly/annual counters for a transaction. */
    public function updateCounters(int $txnId): void {
        $counters = $this->findOne(
            "SELECT
                (SELECT COUNT(*) FROM pos_transactions WHERE status = 'completed' AND DATE(created_at) = CURDATE() AND id < ?) + 1 AS daily_number,
                (SELECT COUNT(*) FROM pos_transactions WHERE status = 'completed' AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) AND id < ?) + 1 AS monthly_number,
                (SELECT COUNT(*) FROM pos_transactions WHERE status = 'completed' AND YEAR(created_at) = YEAR(CURDATE()) AND id < ?) + 1 AS annual_number",
            [$txnId, $txnId, $txnId]
        );
        $this->execute(
            'UPDATE pos_transactions SET daily_number = ?, monthly_number = ?, annual_number = ? WHERE id = ?',
            [(int)$counters['daily_number'], (int)$counters['monthly_number'], (int)$counters['annual_number'], $txnId]
        );
    }

    public function getProductSales(?string $dateFrom = null, ?string $dateTo = null, ?int $terminalId = null): array {
        $sql = "SELECT ti.product_id, ti.product_name, ti.product_code,
                       SUM(ti.quantity) AS total_qty,
                       SUM(ti.line_total) AS total_revenue
                FROM pos_transaction_items ti
                JOIN pos_transactions t ON ti.transaction_id = t.id
                WHERE t.status IN ('completed','partial_refund')";
        $params = [];

        if ($dateFrom) {
            $sql .= ' AND DATE(t.created_at) >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $sql .= ' AND DATE(t.created_at) <= ?';
            $params[] = $dateTo;
        }
        if ($terminalId) {
            $sql .= ' AND t.terminal_id = ?';
            $params[] = $terminalId;
        }

        $sql .= ' GROUP BY ti.product_id, ti.product_name, ti.product_code
                   ORDER BY total_revenue DESC';

        return $this->findAll($sql, $params);
    }
}
