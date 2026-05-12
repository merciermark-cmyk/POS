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
            'r1_card' => $count['r1_card'],
            'r1_tips' => $count['r1_tips'],
            'r2_card' => $count['r2_card'],
            'r2_tips' => $count['r2_tips'],
            'r3' => [
                'total_sales' => $count['r3_total_sales'],
                'txn_count'   => $count['r3_txn_count'],
                'gst'         => $count['r3_gst'],
                'cash'        => $count['r3_cash'],
                'card'        => $count['r3_card'],
                'tips'        => $count['r3_tips'],
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

            // R1/R2 card batch & tips
            $r1Card = isset($data['r1_card']) && $data['r1_card'] !== '' && $data['r1_card'] !== null
                ? round((float)$data['r1_card'], 2) : null;
            $r1Tips = isset($data['r1_tips']) && $data['r1_tips'] !== '' && $data['r1_tips'] !== null
                ? round((float)$data['r1_tips'], 2) : null;
            $r2Card = isset($data['r2_card']) && $data['r2_card'] !== '' && $data['r2_card'] !== null
                ? round((float)$data['r2_card'], 2) : null;
            $r2Tips = isset($data['r2_tips']) && $data['r2_tips'] !== '' && $data['r2_tips'] !== null
                ? round((float)$data['r2_tips'], 2) : null;

            // R3 manual fields
            $r3TotalSales = isset($data['r3_total_sales']) ? round((float)$data['r3_total_sales'], 2) : null;
            $r3TxnCount   = isset($data['r3_txn_count'])   ? (int)$data['r3_txn_count'] : null;
            $r3Gst        = isset($data['r3_gst'])         ? round((float)$data['r3_gst'], 2) : null;
            $r3Cash       = isset($data['r3_cash'])        ? round((float)$data['r3_cash'], 2) : null;
            $r3Card       = isset($data['r3_card'])        ? round((float)$data['r3_card'], 2) : null;
            $r3Tips       = isset($data['r3_tips'])        ? round((float)$data['r3_tips'], 2) : null;

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
                     r1_card = ?, r1_tips = ?, r2_card = ?, r2_tips = ?,
                     r3_total_sales = ?, r3_txn_count = ?, r3_gst = ?, r3_cash = ?, r3_card = ?, r3_tips = ?,
                     locked_by = NULL, locked_at = NULL, lock_session = NULL,
                     updated_at = NOW() WHERE id = ?",
                    [$staffId, $status, $notes, $depositTotal, $grandCad, $grandUsd, $actualDeposit,
                     $r1Card, $r1Tips, $r2Card, $r2Tips,
                     $r3TotalSales, $r3TxnCount, $r3Gst, $r3Cash, $r3Card, $r3Tips, $countId]
                );
                $this->execute("DELETE FROM dayclose_count_details WHERE count_id = ?", [$countId]);
                $this->execute("DELETE FROM dayclose_floats WHERE count_id = ?", [$countId]);
            } else {
                $countId = (int)$this->insert(
                    "INSERT INTO dayclose_counts
                     (close_date, closed_by, status, notes, deposit_total, grand_total_cad, grand_total_usd,
                      actual_deposit, r1_card, r1_tips, r2_card, r2_tips,
                      r3_total_sales, r3_txn_count, r3_gst, r3_cash, r3_card, r3_tips)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$date, $staffId, $status, $notes, $depositTotal, $grandCad, $grandUsd, $actualDeposit,
                     $r1Card, $r1Tips, $r2Card, $r2Tips,
                     $r3TotalSales, $r3TxnCount, $r3Gst, $r3Cash, $r3Card, $r3Tips]
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
                    'details' => $details, 'floats' => $floats,
                    'deposit_total' => $depositTotal,
                ]);
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

        // Calculate per-register CAD totals for R1 and R2
        $regTotals = ['r1' => 0.0, 'r2' => 0.0];
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
                'deposit' => $data['r3_cash'] !== null
                    ? max(0, $data['r3_cash'] - self::FLOAT_TARGETS['r3']) : null,
            ],
        ];

        foreach ($regConfig as $reg => $vals) {
            try {
                if ($vals['cash'] === null && $reg === 'r3') continue; // R3 not filled in

                $terminalId = self::REGISTER_TERMINAL_MAP[$reg];
                $shift = $shiftModel->getOpenForTerminal($terminalId);
                if (!$shift) continue; // no open shift — skip gracefully

                $shiftModel->close(
                    (int)$shift['id'],
                    (float)$vals['cash'],          // closingCash
                    'Closed via DayClose',          // notes
                    $vals['card'],                  // closingCard
                    $vals['tips'],                  // closingTips
                    $vals['deposit']                // cashDeposit
                );
            } catch (\Throwable $e) {
                error_log("DayClose closeShifts ($reg) failed: " . $e->getMessage());
            }
        }
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
