<?php
class Product extends BaseModel {

    /**
     * Get all active products with category + primary image for POS grid.
     */
    public function getAll(?int $categoryId = null, ?string $search = null): array {
        $sql = 'SELECT p.id, p.name, p.product_code, p.retail_price AS unit_price, p.wholesale_price, p.tax_profile,
                       p.track_inventory, p.wholesale_only,
                       c.name AS category_name, c.id AS category_id,
                       COALESCE(c.parent_id, c.id) AS parent_category_id,
                       pi.filename AS image
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN product_images pi ON pi.product_id = p.id
                    AND pi.sort_order = (SELECT MIN(pi2.sort_order) FROM product_images pi2 WHERE pi2.product_id = p.id)
                WHERE p.deleted_at IS NULL AND p.pos_visible = 1';
        $params = [];

        if ($categoryId) {
            $sql .= ' AND p.category_id = ?';
            $params[] = $categoryId;
        }
        if ($search) {
            $sql .= ' AND (p.name LIKE ? OR p.product_code LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= ' ORDER BY p.name';
        return $this->findAll($sql, $params);
    }

    public function getAllGroupedByCategory(): array {
        $rows = $this->findAll(
            'SELECT p.id, p.name, p.product_code, p.retail_price, p.wholesale_price, p.tax_profile,
                    p.pos_visible, c.name AS category_name, c.id AS category_id
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id
             WHERE p.deleted_at IS NULL
             ORDER BY c.name, p.name'
        );

        $grouped = [];
        foreach ($rows as $row) {
            $cat = $row['category_name'] ?? 'Uncategorized';
            $grouped[$cat][] = $row;
        }
        return $grouped;
    }

    public function toggleVisibility(int $id, bool $visible): void {
        $this->execute(
            'UPDATE products SET pos_visible = ?, updated_at = NOW() WHERE id = ?',
            [$visible ? 1 : 0, $id]
        );
    }

    public function updatePrice(int $id, float $price): void {
        $this->execute(
            'UPDATE products SET retail_price = ?, updated_at = NOW() WHERE id = ?',
            [$price, $id]
        );
    }

    public function updateWholesalePrice(int $id, ?float $price): void {
        $this->execute(
            'UPDATE products SET wholesale_price = ?, updated_at = NOW() WHERE id = ?',
            [$price, $id]
        );
    }

    public function findById(int $id): ?array {
        return $this->findOne(
            'SELECT p.*, p.retail_price AS unit_price, p.track_inventory, c.name AS category_name,
                    pi.filename AS image
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id
             LEFT JOIN product_images pi ON pi.product_id = p.id
                 AND pi.sort_order = (SELECT MIN(pi2.sort_order) FROM product_images pi2 WHERE pi2.product_id = p.id)
             WHERE p.id = ? AND p.deleted_at IS NULL',
            [$id]
        );
    }
}
