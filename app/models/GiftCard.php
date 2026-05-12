<?php
class GiftCard extends BaseModel {

    /**
     * Check balance of a PrestaShop web gift card by code.
     * Cross-DB query against PS database.
     */
    public function checkBalance(string $code): ?array {
        if (!PS_DB_NAME) return null;

        $prefix = PS_DB_PREFIX;
        $psDb   = PS_DB_NAME;

        return $this->findOne(
            "SELECT c.code, c.reduction_amount AS balance, c.active, c.date_add, c.date_to
             FROM `$psDb`.`{$prefix}cart_rule` c
             WHERE c.code COLLATE utf8mb4_unicode_ci = ?
               AND c.active = 1
               AND c.quantity > 0
               AND (c.date_to = '0000-00-00 00:00:00' OR c.date_to > NOW())",
            [$code]
        );
    }

    /**
     * Deduct amount from a PrestaShop gift card.
     * Returns new balance.
     */
    public function deduct(string $code, float $amount): float {
        if (!PS_DB_NAME) throw new RuntimeException('PrestaShop DB not configured.');

        $card = $this->checkBalance($code);
        if (!$card) throw new RuntimeException('Gift card not found or expired.');

        $balance = (float)$card['balance'];
        if ($amount > $balance) throw new RuntimeException('Insufficient gift card balance.');

        $newBalance = round($balance - $amount, 2);
        $prefix = PS_DB_PREFIX;
        $psDb   = PS_DB_NAME;

        $this->execute(
            "UPDATE `$psDb`.`{$prefix}cart_rule`
             SET reduction_amount = ?
             WHERE code COLLATE utf8mb4_unicode_ci = ?",
            [$newBalance, $code]
        );

        // Deactivate if zero balance
        if ($newBalance <= 0) {
            $this->execute(
                "UPDATE `$psDb`.`{$prefix}cart_rule`
                 SET active = 0, quantity = 0
                 WHERE code COLLATE utf8mb4_unicode_ci = ?",
                [$code]
            );
        }

        return $newBalance;
    }
}
