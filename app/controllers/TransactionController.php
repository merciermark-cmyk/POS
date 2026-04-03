<?php
class TransactionController {

    public function index(): void {
        requireAuth();

        $dateFrom     = $_GET['date_from'] ?? date('Y-m-d');
        $dateTo       = $_GET['date_to']   ?? date('Y-m-d');
        $customerName = trim($_GET['customer_name'] ?? '');

        $transactions = (new Transaction())->getRecent(200, $dateFrom, $dateTo, null, $customerName);

        require APP_PATH . '/views/transactions/list.php';
    }

    public function view(): void {
        requireAuth();

        $id = (int)($_GET['id'] ?? 0);
        $txnModel    = new Transaction();
        $transaction = $txnModel->findById($id);

        if (!$transaction) {
            setFlash('error', 'Transaction not found.');
            redirect('/transactions');
            return;
        }

        $items    = $txnModel->getItemsWithModifiers($id);
        $payments = $txnModel->getPayments($id);
        $settings = (new PosSetting())->getAll();

        require APP_PATH . '/views/transactions/view.php';
    }

    public function void(): void {
        requireAuth();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/transactions');
            return;
        }

        verifyCsrfToken();

        $id           = (int)($_POST['transaction_id'] ?? 0);
        $reason       = trim($_POST['void_reason'] ?? '');
        $customerName = trim($_POST['customer_name'] ?? '');

        if (!$reason) {
            setFlash('error', 'Void reason is required.');
            redirect('/transactions/view/' . $id);
            return;
        }

        $locationId = (new PosSetting())->getShopLocationId();
        $user = currentUser();

        try {
            (new Transaction())->void($id, $user['id'], $reason, $locationId, $customerName);
            setFlash('success', 'Transaction #' . $id . ' voided.');
        } catch (Exception $e) {
            setFlash('error', 'Void failed: ' . $e->getMessage());
        }

        redirect('/transactions/view/' . $id);
    }

    public function refund(): void {
        requireAuth();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/transactions');
            return;
        }

        verifyCsrfToken();

        $txnId        = (int)($_POST['transaction_id'] ?? 0);
        $reason       = trim($_POST['refund_reason'] ?? '');
        $customerName = trim($_POST['customer_name'] ?? '');
        $authCode     = trim($_POST['auth_code'] ?? '');

        // If not a manager, require a valid temp auth code for refunds over $50
        $authorizedBy = null;
        $tempAuthId   = null;
        if (!isManager() && $authCode) {
            $tempAuth = (new TempAuth())->verify($authCode);
            if (!$tempAuth) {
                setFlash('error', 'Invalid or expired authorization code.');
                redirect('/transactions/view/' . $txnId);
                return;
            }

            $authorizedBy = (int)$tempAuth['generated_by'];
            $tempAuthId   = (int)$tempAuth['id'];
        }

        if (!$reason) {
            setFlash('error', 'Refund reason is required.');
            redirect('/transactions/view/' . $txnId);
            return;
        }

        // Build refund items from form
        $refundItems = [];
        $refundQtys = $_POST['refund_qty'] ?? [];
        foreach ($refundQtys as $itemId => $qty) {
            $qty = (int)$qty;
            if ($qty > 0) {
                $refundItems[] = ['item_id' => (int)$itemId, 'quantity' => $qty];
            }
        }

        if (empty($refundItems)) {
            setFlash('error', 'No items selected for refund.');
            redirect('/transactions/view/' . $txnId);
            return;
        }

        // Build payments
        $payMethods = $_POST['refund_pay_method'] ?? [];
        $payAmounts = $_POST['refund_pay_amount'] ?? [];
        $payments = [];
        for ($i = 0; $i < count($payMethods); $i++) {
            $amt = (float)($payAmounts[$i] ?? 0);
            if ($amt > 0 && !empty($payMethods[$i])) {
                $payments[] = [
                    'method'    => $payMethods[$i],
                    'amount'    => $amt,
                    'reference' => null,
                ];
            }
        }

        // Cashiers need authorization for refunds over $50
        if (!isManager() && !$authorizedBy) {
            $refundTotal = array_sum(array_column($payments, 'amount'));
            if ($refundTotal > 50.00) {
                setFlash('error', 'Manager authorization code required for refunds over $50.');
                redirect('/transactions/view/' . $txnId);
                return;
            }
        }

        $locationId = (new PosSetting())->getShopLocationId();
        $user       = currentUser();
        $shiftId    = $_SESSION['pos_shift_id'] ?? 0;

        try {
            $refundId = (new Transaction())->refund(
                $txnId, $user['id'], $shiftId, $reason,
                $refundItems, $payments, $locationId, $customerName, $authorizedBy
            );

            // Mark temp auth code as used after successful refund
            if ($tempAuthId) {
                (new TempAuth())->markUsed($tempAuthId, $user['id'], $refundId);
            }

            setFlash('success', 'Refund #' . $refundId . ' processed.');
            redirect('/transactions/refund-receipt/' . $refundId);
        } catch (Exception $e) {
            setFlash('error', 'Refund failed: ' . $e->getMessage());
            redirect('/transactions/view/' . $txnId);
        }
    }

    public function refundReceipt(): void {
        requireAuth();

        $refundId = (int)($_GET['id'] ?? 0);
        $txnModel = new Transaction();
        $refund   = $txnModel->findRefundById($refundId);

        if (!$refund) {
            setFlash('error', 'Refund not found.');
            redirect('/transactions');
            return;
        }

        $refundItems    = $txnModel->getRefundItems($refundId);
        $refundPayments = $txnModel->getRefundPayments($refundId);
        $transaction    = $txnModel->findById((int)$refund['original_transaction_id']);
        $settings       = (new PosSetting())->getAll();

        require APP_PATH . '/views/transactions/refund_receipt.php';
    }
}
