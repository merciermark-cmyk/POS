<?php
/**
 * SafeCoinLedger — running ledger for coin held in the safe.
 *
 * Sign convention: `dollars` is signed.
 *   overflow_in, bank_buy:     positive (coin enters safe)
 *   bank_sell:                 negative (coin leaves safe, sold to bank)
 *   adjustment, reconcile:     signed (positive = added to balance, negative = subtracted)
 *
 * So SUM(dollars) gives the running balance directly. Controller is
 * responsible for flipping sign on bank_sell input.
 */
class SafeCoinLedger extends BaseModel {

    const TYPES = ['overflow_in', 'bank_sell', 'bank_buy', 'adjustment', 'reconcile'];
    const DENOMS = ['toonie', 'loonie', 'quarter', 'dime', 'nickel', 'mixed'];

    /** Overall running balance across all denominations. */
    public function getRunningBalance(): float {
        $row = $this->findOne("SELECT COALESCE(SUM(dollars), 0) AS bal FROM safe_coin_ledger");
        return (float)($row['bal'] ?? 0);
    }

    /** Per-denomination balance. Returns ['toonie' => 123.45, ...]. */
    public function getBalanceByDenomination(): array {
        $rows = $this->findAll(
            "SELECT denomination, COALESCE(SUM(dollars), 0) AS bal
             FROM safe_coin_ledger
             GROUP BY denomination"
        );
        $out = [];
        foreach (self::DENOMS as $d) $out[$d] = 0.0;
        foreach ($rows as $r) {
            $out[$r['denomination']] = (float)$r['bal'];
        }
        return $out;
    }

    /** Totals by type (positive amounts; abs() applied). */
    public function getTotalsByType(): array {
        $rows = $this->findAll(
            "SELECT type, COALESCE(SUM(ABS(dollars)), 0) AS total
             FROM safe_coin_ledger
             GROUP BY type"
        );
        $out = [];
        foreach (self::TYPES as $t) $out[$t] = 0.0;
        foreach ($rows as $r) $out[$r['type']] = (float)$r['total'];
        return $out;
    }

    /**
     * Get ledger entries; optional filters: type (string or null), denomination, limit.
     */
    public function getEntries(?string $type = null, ?string $denom = null, int $limit = 100): array {
        $sql = "SELECT l.*, u.username AS created_by_name
                FROM safe_coin_ledger l
                LEFT JOIN pos_users u ON u.id = l.created_by
                WHERE 1=1";
        $params = [];
        if ($type !== null) { $sql .= " AND l.type = ?"; $params[] = $type; }
        if ($denom !== null) { $sql .= " AND l.denomination = ?"; $params[] = $denom; }
        $sql .= " ORDER BY l.ts DESC, l.id DESC LIMIT " . (int)$limit;
        return $this->findAll($sql, $params);
    }

    /**
     * Insert a ledger entry. Returns inserted id.
     * $dollars: signed value (caller flips sign for bank_sell).
     */
    public function addEntry(
        string $type,
        string $denomination,
        float $dollars,
        ?float $grams = null,
        ?string $note = null,
        ?int $createdBy = null
    ): int {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Invalid type: $type");
        }
        if (!in_array($denomination, self::DENOMS, true)) {
            throw new InvalidArgumentException("Invalid denomination: $denomination");
        }
        return (int)$this->insert(
            "INSERT INTO safe_coin_ledger (type, denomination, dollars, grams, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$type, $denomination, $dollars, $grams, $note, $createdBy]
        );
    }
}
