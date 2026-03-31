<?php
class ManualEntryController {

    public function form(): void {
        requireManager();
        $terminals = (new Terminal())->getActive();
        $errors = [];
        $data = [
            'terminal_id' => '',
            'entry_date'  => date('Y-m-d'),
            'subtotal'    => '',
            'gst_amount'  => '',
            'pst_amount'  => '',
            'cash_amount' => '',
            'card_amount'       => '',
            'transaction_count' => '',
            'notes'             => '',
        ];
        require APP_PATH . '/views/manual-entry/form.php';
    }

    public function save(): void {
        requireManager();
        verifyCsrfToken();

        $terminals = (new Terminal())->getActive();
        $errors = [];

        $data = [
            'terminal_id' => trim($_POST['terminal_id'] ?? ''),
            'entry_date'  => trim($_POST['entry_date'] ?? date('Y-m-d')),
            'subtotal'    => trim($_POST['subtotal'] ?? ''),
            'gst_amount'  => trim($_POST['gst_amount'] ?? ''),
            'pst_amount'  => trim($_POST['pst_amount'] ?? ''),
            'cash_amount' => trim($_POST['cash_amount'] ?? ''),
            'card_amount'       => trim($_POST['card_amount'] ?? ''),
            'transaction_count' => trim($_POST['transaction_count'] ?? ''),
            'notes'             => trim($_POST['notes'] ?? ''),
        ];

        // Validate
        if ($data['terminal_id'] === '') {
            $errors[] = 'Terminal is required.';
        }
        if ($data['subtotal'] === '' || !is_numeric($data['subtotal']) || (float)$data['subtotal'] < 0) {
            $errors[] = 'Subtotal must be a valid positive number.';
        }
        if ($data['gst_amount'] === '' || !is_numeric($data['gst_amount']) || (float)$data['gst_amount'] < 0) {
            $errors[] = 'GST must be a valid positive number.';
        }
        if ($data['pst_amount'] === '' || !is_numeric($data['pst_amount']) || (float)$data['pst_amount'] < 0) {
            $errors[] = 'PST must be a valid positive number.';
        }

        $subtotal  = round((float)$data['subtotal'], 2);
        $gst       = round((float)$data['gst_amount'], 2);
        $pst       = round((float)$data['pst_amount'], 2);
        $total     = round($subtotal + $gst + $pst, 2);

        $cashAmt = round((float)($data['cash_amount'] ?: 0), 2);
        $cardAmt = round((float)($data['card_amount'] ?: 0), 2);
        $payTotal = round($cashAmt + $cardAmt, 2);

        if ($total > 0 && $payTotal < $total) {
            $errors[] = 'Payment total ($' . number_format($payTotal, 2) . ') is less than transaction total ($' . number_format($total, 2) . ').';
        }

        if (!empty($errors)) {
            require APP_PATH . '/views/manual-entry/form.php';
            return;
        }

        // Need an open shift — use current session shift
        $shiftId = $_SESSION['pos_shift_id'] ?? null;
        if (!$shiftId) {
            $errors[] = 'You must have an open shift to create a manual entry.';
            require APP_PATH . '/views/manual-entry/form.php';
            return;
        }

        $userId     = $_SESSION['pos_user_id'];
        $terminalId = (int)$data['terminal_id'];
        $createdAt  = $data['entry_date'] . ' ' . date('H:i:s');
        $notes      = $data['notes'] ?: null;
        $txnCount   = $data['transaction_count'] !== '' ? (int)$data['transaction_count'] : null;

        $txn = new Transaction();
        $txn->beginTransaction();
        try {
            // Insert transaction header
            $txnId = (int)$txn->insertManualEntry([
                'shift_id'    => $shiftId,
                'user_id'     => $userId,
                'terminal_id' => $terminalId,
                'subtotal'    => $subtotal,
                'gst_amount'  => $gst,
                'pst_amount'  => $pst,
                'total'       => $total,
                'created_at'        => $createdAt,
                'transaction_count' => $txnCount,
                'notes'             => $notes,
            ]);

            // Insert payments
            if ($cashAmt > 0) {
                $txn->insertPayment($txnId, 'cash', $cashAmt);
            }
            if ($cardAmt > 0) {
                $txn->insertPayment($txnId, 'card', $cardAmt);
            }

            // Set daily/monthly/annual counters
            $txn->updateCounters($txnId);

            $txn->commit();

            setFlash('success', 'Manual entry #' . $txnId . ' created — $' . number_format($total, 2));
            redirect('/manual-entry');

        } catch (Exception $e) {
            $txn->rollBack();
            $errors[] = 'Error: ' . $e->getMessage();
            require APP_PATH . '/views/manual-entry/form.php';
        }
    }
}
