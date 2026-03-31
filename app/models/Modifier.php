<?php
class Modifier extends BaseModel {

    public function getAll(bool $activeOnly = false): array {
        $sql = 'SELECT * FROM pos_modifiers';
        if ($activeOnly) $sql .= ' WHERE is_active = 1';
        $sql .= ' ORDER BY sort_order, name';
        return $this->findAll($sql);
    }

    public function getActiveModifiers(): array {
        return $this->getAll(true);
    }

    public function findById(int $id): ?array {
        return $this->findOne('SELECT * FROM pos_modifiers WHERE id = ?', [$id]);
    }

    public function create(array $data): int {
        return (int)$this->insert(
            'INSERT INTO pos_modifiers (name, price, sort_order, is_active) VALUES (?, ?, ?, ?)',
            [
                $data['name'],
                $data['price'],
                (int)($data['sort_order'] ?? 0),
                (int)($data['is_active'] ?? 1),
            ]
        );
    }

    public function update(int $id, array $data): void {
        $this->execute(
            'UPDATE pos_modifiers SET name = ?, price = ?, sort_order = ?, is_active = ?, updated_at = NOW() WHERE id = ?',
            [
                $data['name'],
                $data['price'],
                (int)($data['sort_order'] ?? 0),
                (int)($data['is_active'] ?? 1),
                $id,
            ]
        );
    }

    public function delete(int $id): void {
        $this->execute('DELETE FROM pos_modifiers WHERE id = ?', [$id]);
    }

    /**
     * Bulk insert modifiers for a transaction item.
     * $mods = [['id' => int, 'name' => string, 'price' => float, 'qty' => int], ...]
     */
    public function saveTransactionItemModifiers(int $txnItemId, array $mods): void {
        foreach ($mods as $mod) {
            $this->insert(
                'INSERT INTO pos_transaction_item_modifiers (transaction_item_id, modifier_id, modifier_name, modifier_price, quantity)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $txnItemId,
                    (int)$mod['id'],
                    $mod['name'],
                    (float)$mod['price'],
                    (int)($mod['qty'] ?? 1),
                ]
            );
        }
    }

    /**
     * Batch-load modifiers for multiple transaction items.
     * Returns [item_id => [rows]].
     */
    public function getModifiersForTransactionItems(array $itemIds): array {
        if (empty($itemIds)) return [];

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $rows = $this->findAll(
            "SELECT * FROM pos_transaction_item_modifiers WHERE transaction_item_id IN ($placeholders) ORDER BY id",
            array_values($itemIds)
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['transaction_item_id']][] = $row;
        }
        return $map;
    }
}
