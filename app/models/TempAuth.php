<?php
class TempAuth extends BaseModel {

    private const EXPIRY_MINUTES = 15;

    /**
     * Generate a random 6-digit authorization code for a manager.
     * Returns the plain code string.
     */
    public function generate(int $managerId): array {
        // Generate cryptographically random 6-digit code
        $code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + self::EXPIRY_MINUTES * 60);

        $this->insert(
            'INSERT INTO pos_temp_auth (code, action_type, generated_by, expires_at)
             VALUES (?, ?, ?, ?)',
            [$code, 'refund', $managerId, $expiresAt]
        );

        return ['code' => $code, 'expires_at' => $expiresAt];
    }

    /**
     * Verify a code: must be unused and not expired.
     * Returns the row with manager info, or null if invalid.
     */
    public function verify(string $code): ?array {
        return $this->findOne(
            'SELECT ta.*, u.username AS manager_username
             FROM pos_temp_auth ta
             JOIN pos_users u ON ta.generated_by = u.id
             WHERE ta.code = ?
               AND ta.used_at IS NULL
               AND ta.expires_at > NOW()',
            [$code]
        );
    }

    /**
     * Mark a code as used after a successful refund.
     */
    public function markUsed(int $id, int $usedBy, int $txnId): void {
        $this->execute(
            'UPDATE pos_temp_auth SET used_at = NOW(), used_by = ?, used_for_txn = ? WHERE id = ?',
            [$usedBy, $txnId, $id]
        );
    }

    /**
     * Get recent codes for the audit dashboard.
     */
    public function getRecent(int $limit = 20): array {
        return $this->findAll(
            'SELECT ta.*,
                    g.username AS generated_by_name,
                    u.username AS used_by_name
             FROM pos_temp_auth ta
             JOIN pos_users g ON ta.generated_by = g.id
             LEFT JOIN pos_users u ON ta.used_by = u.id
             ORDER BY ta.created_at DESC
             LIMIT ?',
            [$limit]
        );
    }
}
