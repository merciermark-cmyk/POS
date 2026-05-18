<?php
/**
 * DayClose model — cash counting, float prep, deposit calc, shift integration.
 */
class DayClose extends BaseModel {

    // ── Constants (same as standalone app) ────────────────────────
    const CUP_WEIGHT = 14; // grams
    const COINS = [
        'toonie'  => ['weight' => 6.92, 'value' => 2.00, 'label' => 'Toonie'],
        'loonie'  => ['weight' => 6.27, 'value' => 1.00, 'label' => 'Loonie'],
        'quarter' => ['weight' => 4.40, 'value' => 0.25, 'label' => 'Quarter'],
        'dime'    => ['weight' => 1.75, 'value' => 0.10, 'label' => 'Dime'],
        'nickel'  => ['weight' => 3.95, 'value' => 0.05, 'label' => 'Nickel'],
    ];
    const BILLS = [100, 50, 20, 10, 5];
    const REGISTERS = [
        'r1' => ['name' => 'Loose Tea', 'short' => 'R1'],
        'r2' => ['name' => 'Tea Bar',   'short' => 'R2'],
        'r3' => ['name' => 'Ice Tea',   'short' => 'R3'],
    ];
    const FLOAT_TARGETS = ['r1' => 100, 'r2' => 100, 'r3' => 150];
    const FLOAT_MODE = ['r1' => 'bills_fixed', 'r2' => 'bills_fixed', 'r3' => 'total_fixed'];
    const REGISTER_TERMINAL_MAP = ['r1' => 1, 'r2' => 2, 'r3' => 3];
    const LOCK_TIMEOUT_MINUTES = 30;

    // ── Coin calculation ─────────────────────────────────────────
    public function coinCalc(float $weightG, string $coinKey): array {
        if ($weightG <= self::CUP_WEIGHT) return ['count' => 0, 'value' => 0.00];
        $coin = self::COINS[$coinKey];
        $count = (int)floor(($weightG - self::CUP_WEIGHT) / $coin['weight']);
        return ['count' => $count, 'value' => round($count * $coin['value'], 2)];
    }

    // Sum of counted bills (face-value $) for a register from the details array.
    private function getRegBillsCounted(array $details, string $reg): float {
        $total = 0.0;
        foreach ($details as $d) {
            if (($d['register'] ?? '') === $reg && ($d['denomination_type'] ?? '') === 'bill') {
                $total += (int)$d['value'] * (int)$d['denomination'];
            }
        }
        return $total;
    }

    // FEATURE_SAFE_COIN: write per-register coin-overflow rows to safe_coin_ledger.
    // Idempotent: removes any prior overflow_in rows for this close before inserting.
    private function writeCoinOverflowLedger(int $countId, array $coinOverage, ?int $createdBy): void {
        $this->execute(
            "DELETE FROM safe_coin_ledger WHERE related_count_id = ? AND type = 'overflow_in'",
            [$countId]
        );
        foreach (['r1', 'r2', 'r3'] as $reg) {
            $dollars = $coinOverage[$reg] ?? null;
            if ($dollars === null || $dollars <= 0) continue;
            $this->insert(
                "INSERT INTO safe_coin_ledger
                 (type, denomination, dollars, note, related_count_id, related_register, created_by)
                 VALUES ('overflow_in', 'mixed', ?, ?, ?, ?, ?)",
                [$dollars, "Coin overflow from " . strtoupper($reg) . " close", $countId, $reg, $createdBy]
            );
        }
    }

    // ── Data access ──────────────────────────────────────────────
    public function getCountByDate(string $date): ?array {
        return $this->findOne(
            "SELECT c.*, u.username AS staff_name
             FROM dayclose_counts c
             LEFT JOIN pos_users u ON c.closed_by = u.id
             WHERE c.close_date = ?",
            [$date]
        );
    }

    public function getCountDetails(int $countId): array {
        return $this->findAll(
            "SELECT * FROM dayclose_count_details WHERE count_id = ?
             ORDER BY register, denomination_type, denomination",
            [$countId]
        );
    }

    public function getCountFloats(int $countId): array {
        return $this->findAll(
            "SELECT * FROM dayclose_floats WHERE count_id = ?
             ORDER BY register, denomination",
            [$countId]
        );
    }

    public function getCountsByRange(string $from, string $to): array {
        return $this->findAll(
            "SELECT c.*, u.username AS staff_name
             FROM dayclose_counts c
             LEFT JOIN pos_users u ON c.closed_by = u.id
             WHERE c.close_date BETWEEN ? AND ?
             ORDER BY c.close_date DESC",
            [$from, $to]
        );
    }

    // ── Build prefill data for JS ────────────────────────────────
    public function buildPrefillData(array $count, array $details, array $floats): array {
        $prefill = [
            'count_id'   => $count['id'],
            'close_date' => $count['close_date'],
            'closed_by'  => $count['closed_by'],
            'staff_name' => $count['staff_name'] ?? '',
            'notes'      => $count['notes'] ?? '',
            'status'     => $count['status'],
            'actual_deposit' => $count['actual_deposit'],
            // Structured keys consumed by the rebuilt dayclose.js
            'cardBatch' => [
                'r1' => $count['r1_card'] ?? '',
                'r2' => $count['r2_card'] ?? '',
                'r3' => $count['r3_card_batch'] ?? '',
            ],
            'tips' => [
                'r2' => $count['r2_tips'] ?? '',
                'r3' => $count['r3_tips'] ?? '',
            ],
            'registerTape' => [
                'total_sales' => $count['r3_total_sales'] ?? '',
                'txn_count'   => $count['r3_txn_count']   ?? '',
                'gst'         => $count['r3_gst']         ?? '',
                'cash_sales'  => $count['r3_cash']        ?? '',
                'card_sales'  => $count['r3_card']        ?? '',
            ],
            'count' => [],
            'float' => [],
        ];

        foreach (self::REGISTERS as $regId => $reg) {
            $prefill['count'][$regId] = ['bills' => [], 'coins' => [], 'usd' => 0];
            foreach (self::BILLS as $b) $prefill['count'][$regId]['bills'][$b] = 0;
            foreach (self::COINS as $key => $c) $prefill['count'][$regId]['coins'][$key] = 0;
            $prefill['float'][$regId] = ['bills' => []];
            foreach (self::BILLS as $b) $prefill['float'][$regId]['bills'][$b] = 0;
        }

        foreach ($details as $d) {
            $reg = $d['register'];
            switch ($d['denomination_type']) {
                case 'bill':
                    $prefill['count'][$reg]['bills'][(int)$d['denomination']] = (int)$d['value'];
                    break;
                case 'coin':
                    $prefill['count'][$reg]['coins'][$d['denomination']] = (float)$d['value'];
                    break;
                case 'usd':
                    $prefill['count'][$reg]['usd'] = (float)$d['calculated_amount'];
                    break;
            }
        }

        foreach ($floats as $f) {
            $prefill['float'][$f['register']]['bills'][(int)$f['denomination']] = (int)$f['quantity'];
        }

        return $prefill;
    }

    // ── Save count (transactional) ───────────────────────────────
    public function saveCount(array $data, bool $complete = true): int {
        $this->beginTransaction();
        try {
            $date    = $data['close_date'];
            $staffId = (int)$data['closed_by'];
            $notes   = $data['notes'] ?? '';
            $details = $data['details'] ?? [];
            $floats  = $data['floats'] ?? [];
            $actualDeposit = isset($data['actual_deposit']) && $data['actual_deposit'] !== ''
                ? round((float)$data['actual_deposit'], 2) : null;

            // Per-register Moneris card batch totals
            $r1Card = isset($data['r1_card']) && $data['r1_card'] !== '' && $data['r1_card'] !== null
                ? round((float)$data['r1_card'], 2) : null;
            $r1Tips = isset($data['r1_tips']) && $data['r1_tips'] !== '' && $data['r1_tips'] !== null
                ? round((float)$data['r1_tips'], 2) : null;
            $r2Card = isset($data['r2_card']) && $data['r2_card'] !== '' && $data['r2_card'] !== null
                ? round((float)$data['r2_card'], 2) : null;
            $r2Tips = isset($data['r2_tips']) && $data['r2_tips'] !== '' && $data['r2_tips'] !== null
                ? round((float)$data['r2_tips'], 2) : null;
            // R3 Moneris batch (separate column from r3_card which is the register-tape's card_sales line)
            $r3CardBatch = isset($data['r3_card_batch']) && $data['r3_card_batch'] !== '' && $data['r3_card_batch'] !== null
                ? round((float)$data['r3_card_batch'], 2) : null;

            // R3 register-tape fields (entered from the analog register's tape)
            $r3TotalSales = isset($data['r3_total_sales']) && $data['r3_total_sales'] !== '' && $data['r3_total_sales'] !== null
                ? round((float)$data['r3_total_sales'], 2) : null;
            $r3TxnCount   = isset($data['r3_txn_count']) && $data['r3_txn_count'] !== '' && $data['r3_txn_count'] !== null
                ? (int)$data['r3_txn_count'] : null;
            $r3Gst        = isset($data['r3_gst']) && $data['r3_gst'] !== '' && $data['r3_gst'] !== null
                ? round((float)$data['r3_gst'], 2) : null;
            $r3Cash       = isset($data['r3_cash']) && $data['r3_cash'] !== '' && $data['r3_cash'] !== null
                ? round((float)$data['r3_cash'], 2) : null;
            $r3Card       = isset($data['r3_card']) && $data['r3_card'] !== '' && $data['r3_card'] !== null
                ? round((float)$data['r3_card'], 2) : null;
            $r3Tips       = isset($data['r3_tips']) && $data['r3_tips'] !== '' && $data['r3_tips'] !== null
                ? round((float)$data['r3_tips'], 2) : null;

            // FEATURE_SAFE_COIN: per-till coin overage going to safe.
            // Null when client posts null (flag off) — preserves NULL in DB.
            $safeCoinOn = defined('FEATURE_SAFE_COIN_SYSTEM') && FEATURE_SAFE_COIN_SYSTEM;
            $coinOverage = ['r1' => null, 'r2' => null, 'r3' => null];
            if ($safeCoinOn) {
                foreach (['r1','r2','r3'] as $reg) {
                    $k = $reg . '_coin_overage';
                    if (isset($data[$k]) && $data[$k] !== '' && $data[$k] !== null) {
                        $coinOverage[$reg] = round((float)$data[$k], 2);
                    }
                }
            }

            // Server-side recalculate totals (R1+R2 from details, R3 from manual cash)
            $grandCad = 0.0;
            $grandUsd = 0.0;
            foreach ($details as $d) {
                if ($d['denomination_type'] === 'usd') {
                    $grandUsd += (float)$d['calculated_amount'];
                } else {
                    $grandCad += (float)$d['calculated_amount'];
                }
            }
            // Add R3 cash to grand CAD total
            if ($r3Cash !== null) {
                $grandCad += $r3Cash;
            }

            // Calculate deposit: total bills minus float bills (R1+R2 only)
            $billPool = [];
            foreach (self::BILLS as $b) $billPool[$b] = 0;
            foreach ($details as $d) {
                if ($d['denomination_type'] === 'bill') {
                    $billPool[(int)$d['denomination']] += (int)$d['value'];
                }
            }
            $floatBills = [];
            foreach (self::BILLS as $b) $floatBills[$b] = 0;
            foreach ($floats as $f) {
                $floatBills[(int)$f['denomination']] += (int)$f['quantity'];
            }
            $depositTotal = 0.0;
            foreach (self::BILLS as $b) {
                $depositTotal += ($billPool[$b] - $floatBills[$b]) * $b;
            }

            // Upsert count
            $status = $complete ? 'completed' : 'incomplete';
            $existing = $this->getCountByDate($date);
            if ($existing) {
                $countId = (int)$existing['id'];
                $this->execute(
                    "UPDATE dayclose_counts SET closed_by = ?, status = ?, notes = ?,
                     deposit_total = ?, grand_total_cad = ?, grand_total_usd = ?, actual_deposit = ?,
                     r1_card = ?, r1_tips = ?, r2_card = ?, r2_tips = ?, r3_card_batch = ?,
                     r3_total_sales = ?, r3_txn_count = ?, r3_gst = ?, r3_cash = ?, r3_card = ?, r3_tips = ?,
                     r1_coin_overage = ?, r2_coin_overage = ?, r3_coin_overage = ?,
                     locked_by = NULL, locked_at = NULL, lock_session = NULL,
                     updated_at = NOW() WHERE id = ?",
                    [$staffId, $status, $notes, $depositTotal, $grandCad, $grandUsd, $actualDeposit,
                     $r1Card, $r1Tips, $r2Card, $r2Tips, $r3CardBatch,
                     $r3TotalSales, $r3TxnCount, $r3Gst, $r3Cash, $r3Card, $r3Tips,
                     $coinOverage['r1'], $coinOverage['r2'], $coinOverage['r3'],
                     $countId]
                );
                $this->execute("DELETE FROM dayclose_count_details WHERE count_id = ?", [$countId]);
                $this->execute("DELETE FROM dayclose_floats WHERE count_id = ?", [$countId]);
            } else {
                $countId = (int)$this->insert(
                    "INSERT INTO dayclose_counts
                     (close_date, closed_by, status, notes, deposit_total, grand_total_cad, grand_total_usd,
                      actual_deposit, r1_card, r1_tips, r2_card, r2_tips, r3_card_batch,
                      r3_total_sales, r3_txn_count, r3_gst, r3_cash, r3_card, r3_tips,
                      r1_coin_overage, r2_coin_overage, r3_coin_overage)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$date, $staffId, $status, $notes, $depositTotal, $grandCad, $grandUsd, $actualDeposit,
                     $r1Card, $r1Tips, $r2Card, $r2Tips, $r3CardBatch,
                     $r3TotalSales, $r3TxnCount, $r3Gst, $r3Cash, $r3Card, $r3Tips,
                     $coinOverage['r1'], $coinOverage['r2'], $coinOverage['r3']]
                );
            }

            // Insert details (R1+R2 only — R3 is manual)
            foreach ($details as $d) {
                $this->insert(
                    "INSERT INTO dayclose_count_details (count_id, register, denomination_type, denomination, value, calculated_amount)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [$countId, $d['register'], $d['denomination_type'], $d['denomination'],
                     $d['value'], $d['calculated_amount']]
                );
            }

            // Insert floats (R1+R2 only — R3 float is fixed)
            foreach ($floats as $f) {
                $this->insert(
                    "INSERT INTO dayclose_floats (count_id, register, denomination, quantity)
                     VALUES (?, ?, ?, ?)",
                    [$countId, $f['register'], $f['denomination'], $f['quantity']]
                );
            }

            $this->commit();

            // Close all shifts only on complete (non-fatal)
            if ($complete) {
                $this->closeShifts($date, [
                    'r1_card' => $r1Card, 'r1_tips' => $r1Tips,
                    'r2_card' => $r2Card, 'r2_tips' => $r2Tips,
                    'r3_cash' => $r3Cash, 'r3_card' => $r3Card, 'r3_tips' => $r3Tips,
                    // R3 Z-tape fields — required by upsertR3Transaction (Daily Sales mirror)
                    // and by the R3 pos_shifts auto-insert (closing_card from card batch).
                    'r3_total_sales' => $r3TotalSales,
                    'r3_gst'         => $r3Gst,
                    'r3_txn_count'   => $r3TxnCount,
                    'r3_card_batch'  => $r3CardBatch,
                    'details' => $details, 'floats' => $floats,
                    'deposit_total' => $depositTotal,
                    'closed_by' => $staffId,
                ]);

                // FEATURE_SAFE_COIN: ledger writes for tonight's coin overflow.
                if ($safeCoinOn) {
                    $this->writeCoinOverflowLedger($countId, $coinOverage, $staffId);
                }
            }

            return $countId;

        } catch (\Throwable $e) {
            if ($this->inTransaction()) $this->rollBack();
            throw $e;
        }
    }

    // ── Close all shifts via Shift::close() ────────────────────────
    private function closeShifts(string $date, array $data): void {
        $shiftModel = new Shift();
        $details = $data['details'];
        $floats  = $data['floats'];
        $safeCoinOn = defined('FEATURE_SAFE_COIN_SYSTEM') && FEATURE_SAFE_COIN_SYSTEM;

        // Calculate per-register CAD drawer totals for R1, R2, R3 (counted bills + coins)
        $regTotals = ['r1' => 0.0, 'r2' => 0.0, 'r3' => 0.0];
        foreach ($details as $d) {
            if ($d['denomination_type'] !== 'usd' && isset($regTotals[$d['register']])) {
                $regTotals[$d['register']] += (float)$d['calculated_amount'];
            }
        }

        // Calculate per-register deposit
        $regDeposit = ['r1' => 0.0, 'r2' => 0.0];
        $regBills = ['r1' => [], 'r2' => []];
        $regFloatBills = ['r1' => [], 'r2' => []];
        foreach (self::BILLS as $b) {
            $regBills['r1'][$b] = 0;
            $regBills['r2'][$b] = 0;
            $regFloatBills['r1'][$b] = 0;
            $regFloatBills['r2'][$b] = 0;
        }
        foreach ($details as $d) {
            if ($d['denomination_type'] === 'bill' && isset($regBills[$d['register']])) {
                $regBills[$d['register']][(int)$d['denomination']] += (int)$d['value'];
            }
        }
        foreach ($floats as $f) {
            if (isset($regFloatBills[$f['register']])) {
                $regFloatBills[$f['register']][(int)$f['denomination']] += (int)$f['quantity'];
            }
        }
        foreach (['r1', 'r2'] as $reg) {
            foreach (self::BILLS as $b) {
                $regDeposit[$reg] += ($regBills[$reg][$b] - $regFloatBills[$reg][$b]) * $b;
            }
        }

        // Build per-register config: cash, card, tips, deposit
        $regConfig = [
            'r1' => [
                'cash'    => $regTotals['r1'],
                'card'    => $data['r1_card'],
                'tips'    => $data['r1_tips'],
                'deposit' => $regDeposit['r1'],
            ],
            'r2' => [
                'cash'    => $regTotals['r2'],
                'card'    => $data['r2_card'],
                'tips'    => $data['r2_tips'],
                'deposit' => $regDeposit['r2'],
            ],
            'r3' => [
                'cash'    => $data['r3_cash'],
                'card'    => $data['r3_card'],
                'tips'    => $data['r3_tips'],
                // Old mode: deposit = r3_cash − $150 (rough approx from Z-tape).
                // New mode (FEATURE_SAFE_COIN): deposit = counted bills − $100 (bills only;
                // coin overage goes to safe, not deposit envelope).
                'deposit' => $data['r3_cash'] !== null
                    ? ($safeCoinOn
                        ? max(0, $this->getRegBillsCounted($details, 'r3') - 100.0)
                        : max(0, $data['r3_cash'] - self::FLOAT_TARGETS['r3']))
                    : null,
            ],
        ];

        $closedBy = $data['closed_by'] ?? null;

        foreach ($regConfig as $reg => $vals) {
            try {
                if ($vals['cash'] === null && $reg === 'r3') continue; // R3 not filled in

                $terminalId = self::REGISTER_TERMINAL_MAP[$reg];
                $shift = $shiftModel->getOpenForTerminal($terminalId);

                if (!$shift) {
                    // R3 is an analog register — no shift is ever opened on POS hardware.
                    // To keep Shift History showing all three registers (the design intent),
                    // auto-create a synthetic closed shift row from the dayclose data.
                    // Skip for R1/R2 (those should always have a real shift if staff opened one).
                    if ($reg === 'r3') {
                        // Guard against duplicate auto-creates on a repeated Save & Complete.
                        $existing = $this->findOne(
                            "SELECT id FROM pos_shifts
                             WHERE terminal_id = ? AND DATE(closed_at) = ?
                             ORDER BY id DESC LIMIT 1",
                            [$terminalId, $date]
                        );
                        $r3ShiftId = null;
                        if ($existing) {
                            $r3ShiftId = (int)$existing['id'];
                        } else {
                            // R3 reconciliation mirrors Shift::close() for R1/R2:
                            //   closing_cash    = counted drawer (bills + coins)
                            //   expected_cash   = opening_float + Z-tape cash sales
                            //   over_short      = closing_cash − expected_cash
                            //   closing_card    = Moneris batch (r3_card_batch)
                            //   expected_card   = Z-tape card sales (r3_card; sum of all CHCK keys)
                            //   card_over_short = closing_card − expected_card − closing_tips
                            //                     (pinpad tips inflate batch but not Z-tape)
                            // New mode (FEATURE_SAFE_COIN): R3 float is $100 bills + $100 coin = $200 total.
                            // Old mode: R3 float was $150 total (bills+coins riding together).
                            $floatAmt    = $safeCoinOn ? 200.0 : (float)self::FLOAT_TARGETS['r3'];
                            $countedCash = round((float)$regTotals['r3'], 2);
                            $zCash       = (float)($data['r3_cash'] ?? 0);
                            $expectedCash = round($floatAmt + $zCash, 2);
                            $overShort    = round($countedCash - $expectedCash, 2);

                            $closingCard   = isset($data['r3_card_batch']) ? (float)$data['r3_card_batch'] : null;
                            $expectedCard  = isset($data['r3_card']) ? (float)$data['r3_card'] : null;
                            $closingTips   = isset($data['r3_tips']) ? (float)$data['r3_tips'] : null;
                            $cardOverShort = ($closingCard !== null && $expectedCard !== null)
                                ? round($closingCard - $expectedCard - ($closingTips ?? 0), 2)
                                : null;

                            $r3ShiftId = (int)$this->insert(
                                "INSERT INTO pos_shifts
                                    (user_id, closed_by, terminal_id, opened_at, closed_at,
                                     opening_float, closing_cash, expected_cash, over_short,
                                     closing_card, expected_card, card_over_short,
                                     closing_tips, cash_deposit,
                                     status, notes)
                                 VALUES (?, ?, ?, NOW(), NOW(),
                                     ?, ?, ?, ?,
                                     ?, ?, ?,
                                     ?, ?,
                                     'closed', 'Auto-created from Close Registers (R3 analog register)')",
                                [$closedBy, $closedBy, $terminalId,
                                 $floatAmt, $countedCash, $expectedCash, $overShort,
                                 $closingCard, $expectedCard, $cardOverShort,
                                 $closingTips, $vals['deposit']]
                            );
                        }

                        // Mirror R3 dayclose data into pos_transactions so it shows in
                        // Daily Sales reports (which read only from pos_transactions).
                        if ($r3ShiftId !== null) {
                            $this->upsertR3Transaction($r3ShiftId, $terminalId, $date, $data, $closedBy);
                        }
                    }
                    continue;
                }

                $shiftModel->close(
                    (int)$shift['id'],
                    (float)$vals['cash'],          // closingCash
                    'Closed via Close Registers',   // notes
                    $vals['card'],                  // closingCard
                    $vals['tips'],                  // closingTips
                    $vals['deposit'],               // cashDeposit
                    $closedBy ? (int)$closedBy : null
                );
            } catch (\Throwable $e) {
                error_log("Close Registers closeShifts ($reg) failed: " . $e->getMessage());
            }
        }
    }

    // ── R3 transaction mirror ─────────────────────────────────────
    // Mirror R3 register-tape data into pos_transactions + pos_payments
    // so Daily Sales reports (which only read pos_transactions) include R3.
    // Idempotent: delete any prior synthetic R3 txn for this shift before insert.
    private function upsertR3Transaction(int $shiftId, int $terminalId, string $date, array $data, ?int $closedBy): void {
        $total = isset($data['r3_total_sales']) && $data['r3_total_sales'] !== null
            ? round((float)$data['r3_total_sales'], 2) : null;
        if ($total === null || $total <= 0) return; // nothing to mirror

        $gst      = isset($data['r3_gst']) && $data['r3_gst'] !== null ? round((float)$data['r3_gst'], 2) : 0.0;
        $subtotal = round($total - $gst, 2); // R3 = iced tea, GST-only (no PST)
        $cash     = isset($data['r3_cash']) && $data['r3_cash'] !== null ? round((float)$data['r3_cash'], 2) : 0.0;
        $card     = isset($data['r3_card']) && $data['r3_card'] !== null ? round((float)$data['r3_card'], 2) : 0.0;
        $tips     = isset($data['r3_tips']) && $data['r3_tips'] !== null ? round((float)$data['r3_tips'], 2) : null;
        $txnCount = isset($data['r3_txn_count']) && $data['r3_txn_count'] !== null ? (int)$data['r3_txn_count'] : null;
        $createdAt = $date . ' ' . date('H:i:s');

        // Idempotency: remove any prior synthetic R3 txn on this shift
        $prior = $this->findOne(
            "SELECT id FROM pos_transactions WHERE shift_id = ? AND terminal_id = ? LIMIT 1",
            [$shiftId, $terminalId]
        );
        if ($prior) {
            $this->execute("DELETE FROM pos_payments WHERE transaction_id = ?", [$prior['id']]);
            $this->execute("DELETE FROM pos_transactions WHERE id = ?", [$prior['id']]);
        }

        $txnId = (int)$this->insert(
            "INSERT INTO pos_transactions
             (shift_id, terminal_id, user_id, subtotal, gst_amount, pst_amount, tip_amount, total,
              status, is_manual_entry, transaction_count, notes, created_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, 'completed', 1, ?, ?, ?)",
            [$shiftId, $terminalId, $closedBy, $subtotal, $gst, $tips, $total, $txnCount,
             'Auto-created from Close Registers (R3 register tape)', $createdAt]
        );

        if ($cash > 0) {
            $this->insert(
                "INSERT INTO pos_payments (transaction_id, method, amount) VALUES (?, 'cash', ?)",
                [$txnId, $cash]
            );
        }
        if ($card > 0) {
            $this->insert(
                "INSERT INTO pos_payments (transaction_id, method, amount) VALUES (?, 'card', ?)",
                [$txnId, $card]
            );
        }

        // Set daily/monthly/annual counters (matches Transaction::updateCounters logic
        // but uses the txn's own created_at date, not CURDATE, so it works for back-dated closes).
        $counters = $this->findOne(
            "SELECT
                (SELECT COUNT(*) FROM pos_transactions WHERE status = 'completed' AND DATE(created_at) = DATE(?) AND id < ?) + 1 AS daily_number,
                (SELECT COUNT(*) FROM pos_transactions WHERE status = 'completed' AND YEAR(created_at) = YEAR(?) AND MONTH(created_at) = MONTH(?) AND id < ?) + 1 AS monthly_number,
                (SELECT COUNT(*) FROM pos_transactions WHERE status = 'completed' AND YEAR(created_at) = YEAR(?) AND id < ?) + 1 AS annual_number",
            [$createdAt, $txnId, $createdAt, $createdAt, $txnId, $createdAt, $txnId]
        );
        $this->execute(
            "UPDATE pos_transactions SET daily_number = ?, monthly_number = ?, annual_number = ? WHERE id = ?",
            [(int)$counters['daily_number'], (int)$counters['monthly_number'], (int)$counters['annual_number'], $txnId]
        );
    }

    // ── Shift reconciliation for summary ─────────────────────────
    public function getShiftReconciliation(string $date): array {
        return $this->findAll(
            "SELECT s.*, t.name AS terminal_name
             FROM pos_shifts s
             LEFT JOIN pos_terminals t ON s.terminal_id = t.id
             WHERE s.status = 'closed' AND DATE(s.closed_at) = ?
             ORDER BY s.terminal_id",
            [$date]
        );
    }

    // ── Lock management ──────────────────────────────────────────
    public function acquireLock(string $date, int $userId, string $sessionId): bool {
        // Check for existing active lock
        $lock = $this->getLockInfo($date);
        if ($lock && $lock['lock_session'] !== $sessionId) {
            return false; // locked by someone else
        }

        // Ensure row exists (create placeholder if needed)
        $existing = $this->getCountByDate($date);
        if ($existing) {
            $this->execute(
                "UPDATE dayclose_counts SET locked_by = ?, locked_at = NOW(), lock_session = ? WHERE id = ?",
                [$userId, $sessionId, $existing['id']]
            );
        } else {
            $this->insert(
                "INSERT INTO dayclose_counts (close_date, closed_by, status, locked_by, locked_at, lock_session)
                 VALUES (?, ?, 'open', ?, NOW(), ?)",
                [$date, $userId, $userId, $sessionId]
            );
        }
        return true;
    }

    public function heartbeatLock(string $date, string $sessionId): void {
        $this->execute(
            "UPDATE dayclose_counts SET locked_at = NOW() WHERE close_date = ? AND lock_session = ?",
            [$date, $sessionId]
        );
    }

    public function releaseLock(string $date, string $sessionId): void {
        $this->execute(
            "UPDATE dayclose_counts SET locked_by = NULL, locked_at = NULL, lock_session = NULL
             WHERE close_date = ? AND lock_session = ?",
            [$date, $sessionId]
        );
    }

    public function getLockInfo(string $date): ?array {
        $row = $this->findOne(
            "SELECT c.locked_by, c.locked_at, c.lock_session, u.username AS locker_name
             FROM dayclose_counts c
             LEFT JOIN pos_users u ON c.locked_by = u.id
             WHERE c.close_date = ? AND c.locked_by IS NOT NULL
               AND c.locked_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$date, self::LOCK_TIMEOUT_MINUTES]
        );
        return $row;
    }

    // ── Float carryover ──────────────────────────────────────────
    public function getLastFloatTotals(): ?array {
        $count = $this->findOne(
            "SELECT id, r3_cash FROM dayclose_counts WHERE status = 'completed' ORDER BY close_date DESC LIMIT 1"
        );
        if (!$count) return null;

        $floats = $this->getCountFloats((int)$count['id']);
        $details = $this->getCountDetails((int)$count['id']);

        $result = [];

        // R1 & R2: bills only (coins are extra, stay in register)
        foreach (['r1', 'r2'] as $reg) {
            $billTotal = 0;
            foreach ($floats as $f) {
                if ($f['register'] === $reg) {
                    $billTotal += (int)$f['quantity'] * (int)$f['denomination'];
                }
            }
            // Add coin totals
            $coinTotal = 0;
            foreach ($details as $d) {
                if ($d['register'] === $reg && $d['denomination_type'] === 'coin') {
                    $calc = $this->coinCalc((float)$d['value'], $d['denomination']);
                    $coinTotal += $calc['value'];
                }
            }
            $termId = self::REGISTER_TERMINAL_MAP[$reg];
            $result[$termId] = round($billTotal + $coinTotal, 2);
        }

        // R3: fixed $150
        $result[self::REGISTER_TERMINAL_MAP['r3']] = (float)self::FLOAT_TARGETS['r3'];

        return $result;
    }
}
