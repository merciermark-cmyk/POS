<?php
class AuditLog extends BaseModel {

    public function record(
        int    $userId,
        int    $productId,
        int    $locationId,
        string $action,
        float  $before,
        float  $change,
        float  $after,
        string $reason = '',
        ?int   $referenceId = null
    ): int {
        return (int)$this->insert(
            'INSERT INTO audit_log
             (user_id, product_id, location_id, action,
              quantity_before, quantity_change, quantity_after,
              reason, reference_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $productId, $locationId, $action,
             $before, $change, $after,
             $reason, $referenceId]
        );
    }
}
