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

        // Payment breakdown
        $db = getDB();
        $sql = "SELECT p.method, SUM(p.amount) AS total
                FROM pos_payments p
                JOIN pos_transactions t ON p.transaction_id = t.id
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

        // Payment breakdown
        $db = getDB();
        $sql = "SELECT p.method, SUM(p.amount) AS total
                FROM pos_payments p
                JOIN pos_transactions t ON p.transaction_id = t.id
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
