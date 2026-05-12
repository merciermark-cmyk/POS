<?php
class ReportController {

    public function daily(): void {
        requireAuth();
        requireManager();

        $date       = $_GET['date'] ?? date('Y-m-d');
        $terminalId = !empty($_GET['terminal_id']) ? (int)$_GET['terminal_id'] : null;
        $terminals  = (new Terminal())->getAll();

        $txnModel = new Transaction();
        $summary  = $txnModel->getDailySales($date, $terminalId);

        // Payment breakdown (cash = retained after change, card = as-is)
        $db = getDB();
        $sql = "SELECT p.method,
                    SUM(CASE WHEN p.method = 'cash'
                        THEN p.amount - GREATEST(0, p_all.total_paid - t.total)
                        ELSE p.amount END) AS total
                FROM pos_payments p
                JOIN pos_transactions t ON p.transaction_id = t.id
                JOIN (SELECT transaction_id, SUM(amount) AS total_paid FROM pos_payments GROUP BY transaction_id) p_all ON p_all.transaction_id = t.id
                WHERE DATE(t.created_at) = ? AND t.status IN ('completed','partial_refund')";
        $params = [$date];
        if ($terminalId) {
            $sql .= ' AND t.terminal_id = ?';
            $params[] = $terminalId;
        }
        $sql .= ' GROUP BY p.method';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $paymentBreakdown = $stmt->fetchAll();

        // Category breakdown
        $categoryBreakdown = $this->groupByCategory($txnModel->getDailyCategorySales($date, $terminalId));

        // Web sales (shipped orders from PrestaShop)
        $webSales = ['orders' => [], 'count' => 0, 'total' => 0.0];
        if (PS_DB_NAME) {
            $webSales = (new WebOrder())->getSummaryForDate($date);
        }

        // Gift card sales for the day
        $gcSql = "SELECT COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total,
                         COALESCE(SUM(CASE WHEN payment_method = 'card' THEN amount ELSE 0 END), 0) AS card_total,
                         COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END), 0) AS cash_total
                  FROM pos_gift_card_sales WHERE DATE(created_at) = ?";
        $gcParams = [$date];
        if ($terminalId) {
            $gcSql .= ' AND terminal_id = ?';
            $gcParams[] = $terminalId;
        }
        $gcStmt = $db->prepare($gcSql);
        $gcStmt->execute($gcParams);
        $giftCardSales = $gcStmt->fetch();

        require APP_PATH . '/views/reports/daily.php';
    }

    public function monthly(): void {
        requireAuth();
        requireManager();

        $year       = (int)($_GET['year']  ?? date('Y'));
        $month      = (int)($_GET['month'] ?? date('n'));
        $terminalId = !empty($_GET['terminal_id']) ? (int)$_GET['terminal_id'] : null;
        $terminals  = (new Terminal())->getAll();

        $txnModel = new Transaction();
        $summary  = $txnModel->getMonthlySummary($year, $month, $terminalId);

        // Payment breakdown (cash = retained after change, card = as-is)
        $db = getDB();
        $sql = "SELECT p.method,
                    SUM(CASE WHEN p.method = 'cash'
                        THEN p.amount - GREATEST(0, p_all.total_paid - t.total)
                        ELSE p.amount END) AS total
                FROM pos_payments p
                JOIN pos_transactions t ON p.transaction_id = t.id
                JOIN (SELECT transaction_id, SUM(amount) AS total_paid FROM pos_payments GROUP BY transaction_id) p_all ON p_all.transaction_id = t.id
                WHERE YEAR(t.created_at) = ? AND MONTH(t.created_at) = ? AND t.status IN ('completed','partial_refund')";
        $params = [$year, $month];
        if ($terminalId) {
            $sql .= ' AND t.terminal_id = ?';
            $params[] = $terminalId;
        }
        $sql .= ' GROUP BY p.method';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $paymentBreakdown = $stmt->fetchAll();

        // Category breakdown
        $categoryBreakdown = $this->groupByCategory($txnModel->getMonthlyCategorySales($year, $month, $terminalId));

        require APP_PATH . '/views/reports/monthly.php';
    }

    public function productSales(): void {
        requireAuth();
        requireManager();

        $dateFrom   = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo     = $_GET['date_to']   ?? date('Y-m-d');
        $terminalId = !empty($_GET['terminal_id']) ? (int)$_GET['terminal_id'] : null;
        $terminals  = (new Terminal())->getAll();

        $sales = (new Transaction())->getProductSales($dateFrom, $dateTo, $terminalId);

        require APP_PATH . '/views/reports/product_sales.php';
    }

    public function transactionSearch(): void {
        requireAuth();
        requireManager();

        $amount     = isset($_GET['amount']) ? (float)$_GET['amount'] : 0;
        $tolerance  = isset($_GET['tolerance']) ? (float)$_GET['tolerance'] : SEARCH_DEFAULT_TOLERANCE;
        $dateFrom   = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
        $dateTo     = $_GET['date_to']   ?? date('Y-m-d');
        $terminalId = !empty($_GET['terminal_id']) ? (int)$_GET['terminal_id'] : null;
        $terminals  = (new Terminal())->getAll();

        $results = null;
        $combos  = null;
        if ($amount > 0) {
            $txnModel = new Transaction();
            $results = $txnModel->searchByAmount($amount, $tolerance, $dateFrom, $dateTo, $terminalId);
            if (!empty($_GET['deep'])) {
                $combos = $txnModel->searchCombinations($amount, $tolerance, $dateFrom, $dateTo, $terminalId);
            }
        }

        require APP_PATH . '/views/reports/transaction_search.php';
    }

    public function hourlySales(): void {
        requireAuth();
        requireManager();

        $dateFrom   = $_GET['date_from'] ?? date('Y-m-d');
        $dateTo     = $_GET['date_to']   ?? date('Y-m-d');
        $terminalId = !empty($_GET['terminal_id']) ? (int)$_GET['terminal_id'] : null;
        $terminals  = (new Terminal())->getAll();

        $hourlyData = (new Transaction())->getHourlySales($dateFrom, $dateTo, $terminalId);

        require APP_PATH . '/views/reports/hourly_sales.php';
    }

    public function cashSpotCheck(): void {
        requireAuth();
        requireManager();

        $terminals  = (new Terminal())->getAll();
        $terminalId = !empty($_GET['terminal_id']) ? (int)$_GET['terminal_id'] : null;
        $cutoffTime = $_GET['cutoff_time'] ?? date('H:i');

        $shift     = null;
        $breakdown = null;
        $message   = null;

        if ($terminalId) {
            $shiftModel = new Shift();
            $shift = $shiftModel->getOpenForTerminal($terminalId);

            if (!$shift) {
                $message = 'No open shift on this terminal.';
            } else {
                $cutoffDatetime = date('Y-m-d') . ' ' . $cutoffTime . ':59';
                $breakdown = $shiftModel->getExpectedCash((int)$shift['id'], $cutoffDatetime);
            }
        }

        require APP_PATH . '/views/reports/cash_spot_check.php';
    }

    /**
     * Map raw category rows into report groups using REPORT_CATEGORY_GROUPS.
     */
    private function groupByCategory(array $rows): array {
        $groups = REPORT_CATEGORY_GROUPS;

        // Build reverse lookup: category_name => group_name
        $lookup = [];
        foreach ($groups as $groupName => $cats) {
            foreach ($cats as $cat) {
                $lookup[$cat] = $groupName;
            }
        }

        // Initialize all groups to zero
        $result = [];
        foreach (array_keys($groups) as $groupName) {
            $result[$groupName] = ['qty' => 0, 'subtotal' => 0, 'gst' => 0, 'pst' => 0, 'total' => 0];
        }
        $result['Other'] = ['qty' => 0, 'subtotal' => 0, 'gst' => 0, 'pst' => 0, 'total' => 0];

        // Accumulate
        foreach ($rows as $row) {
            $catName   = $row['category_name'] ?? '';
            $groupName = $lookup[$catName] ?? 'Other';
            $result[$groupName]['qty']      += (float)$row['qty'];
            $result[$groupName]['subtotal'] += (float)$row['subtotal'];
            $result[$groupName]['gst']      += (float)$row['gst'];
            $result[$groupName]['pst']      += (float)$row['pst'];
            $result[$groupName]['total']    += (float)$row['line_total'];
        }

        return $result;
    }
}
