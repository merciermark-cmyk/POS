<?php
class Product extends BaseModel {

    /**
     * Get all active products with category + primary image for POS grid.
     */
    public function getAll(?int $categoryId = null, ?string $search = null): array {
        $sql = 'SELECT p.id, p.name, p.product_code, p.retail_price AS unit_price, p.tax_profile,
                       p.track_inventory,
                       c.name AS category_name, c.id AS category_id,
                       pi.filename AS image
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.sort_order = 0
                WHERE p.deleted_at IS NULL';
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

    public function findById(int $id): ?array {
        return $this->findOne(
            'SELECT p.*, p.retail_price AS unit_price, p.track_inventory, c.name AS category_name,
                    pi.filename AS image
             FROM products p
             LEFT JOIN categories c ON p.category_id = c.id
             LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.sort_order = 0
             WHERE p.id = ? AND p.deleted_at IS NULL',
            [$id]
        );
    }
}
