<?php
class ManualEntryController {

    public function form(): void {
        requireAuth();
        $terminals = (new Terminal())->getManualEntryOnly();
        $errors = [];
        $data = [
            'terminal_id' => '',
            'entry_date'  => date('Y-m-d'),
            'subtotal'    => '',
            'gst_amount'  => '',
            'pst_amount'  => '',
            'cash_amount' => '',
            'card_amount'       => '',
            'tip_amount'        => '',
            'transaction_count' => '',
            'opening_float'     => '150.00',
            'deposit_amount'    => '',
            'notes'             => '',
        ];
        require APP_PATH . '/views/manual-entry/form.php';
    }

    public function save(): void {
        requireAuth();
        verifyCsrfToken();

        $terminals = (new Terminal())->getManualEntryOnly();
        $errors = [];

        $data = [
            'terminal_id' => trim($_POST['terminal_id'] ?? ''),
            'entry_date'  => trim($_POST['entry_date'] ?? date('Y-m-d')),
            'subtotal'    => trim($_POST['subtotal'] ?? ''),
            'gst_amount'  => trim($_POST['gst_amount'] ?? ''),
            'pst_amount'  => trim($_POST['pst_amount'] ?? ''),
            'cash_amount' => trim($_POST['cash_amount'] ?? ''),
            'card_amount'       => trim($_POST['card_amount'] ?? ''),
            'tip_amount'        => trim($_POST['tip_amount'] ?? ''),
            'transaction_count' => trim($_POST['transaction_count'] ?? ''),
            'opening_float'     => trim($_POST['opening_float'] ?? '150.00'),
            'deposit_amount'    => trim($_POST['deposit_amount'] ?? ''),
            'notes'             => trim($_POST['notes'] ?? ''),
        ];

        // Validate
        if ($data['terminal_id'] === '') {
            $errors[] = 'Terminal is required.';
        }
        if ($data['subtotal'] === '' || !is_numeric($data['subtotal']) || (float)$data['subtotal'] < 0) {
            $errors[] = 'Subtotal must be a valid positive number.';
        }
        if ($data['gst_amount'] !== '' && (!is_numeric($data['gst_amount']) || (float)$data['gst_amount'] < 0)) {
            $errors[] = 'GST must be a valid number.';
        }
        if ($data['pst_amount'] !== '' && (!is_numeric($data['pst_amount']) || (float)$data['pst_amount'] < 0)) {
            $errors[] = 'PST must be a valid number.';
        }

        $subtotal  = round((float)$data['subtotal'], 2);
        $gst       = round((float)$data['gst_amount'], 2);
        $pst       = round((float)$data['pst_amount'], 2);
        $total     = round($subtotal + $gst + $pst, 2);

        $cashAmt      = round((float)($data['cash_amount'] ?: 0), 2);
        $cardAmt      = round((float)($data['card_amount'] ?: 0), 2);
        $tipAmt       = round((float)($data['tip_amount'] ?: 0), 2);
        $openingFloat = round((float)($data['opening_float'] ?: 150), 2);
        // Cash amount is full drawer count; cash sales = drawer - float
        $cashSales = round($cashAmt - $openingFloat, 2);
        $payTotal = round($cashSales + $cardAmt, 2);

        if ($total > 0 && $payTotal < $total) {
            $errors[] = 'Payment total ($' . number_format($payTotal, 2) . ') is less than transaction total ($' . number_format($total, 2) . ').';
        }

        if (!empty($errors)) {
            require APP_PATH . '/views/manual-entry/form.php';
            return;
        }

        $userId     = $_SESSION['pos_user_id'] ?? $_SESSION['pos_operator_id'] ?? 0;
        $terminalId = (int)$data['terminal_id'];
        $createdAt  = $data['entry_date'] . ' ' . date('H:i:s');
        $notes      = $data['notes'] ?: null;
        $txnCount   = $data['transaction_count'] !== '' ? (int)$data['transaction_count'] : null;

        $txn = new Transaction();
        $txn->beginTransaction();
        try {
            // Always create a dedicated closed shift for manual entries
            // cashAmt is the full drawer count (includes float)
            $db = getDB();
            $closingCash = $cashAmt;
            $cashDeposit = $data['deposit_amount'] !== '' ? round((float)$data['deposit_amount'], 2) : null;
            $db->prepare(
                "INSERT INTO pos_shifts (user_id, terminal_id, opening_float, closing_cash, cash_deposit, over_short, status, opened_at, closed_at, closed_by, notes)
                 VALUES (?, ?, ?, ?, ?, 0, 'closed', ?, ?, ?, ?)"
            )->execute([
                $userId, $terminalId, $openingFloat, $closingCash, $cashDeposit,
                $createdAt, $createdAt, $userId,
                'Manual entry' . ($notes ? ': ' . $notes : ''),
            ]);
            $shiftId = (int)$db->lastInsertId();

            // Insert transaction header
            $txnId = (int)$txn->insertManualEntry([
                'shift_id'    => $shiftId,
                'user_id'     => $userId,
                'terminal_id' => $terminalId,
                'subtotal'    => $subtotal,
                'gst_amount'  => $gst,
                'pst_amount'  => $pst,
                'tip_amount'  => $tipAmt > 0 ? $tipAmt : null,
                'total'       => $total,
                'created_at'        => $createdAt,
                'transaction_count' => $txnCount,
                'notes'             => $notes,
            ]);

            // Insert payments (cash sales only, not the float)
            if ($cashSales > 0) {
                $txn->insertPayment($txnId, 'cash', $cashSales);
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
