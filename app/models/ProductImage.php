<?php
class ProductImage extends BaseModel {

    public function getForProduct(int $productId): array {
        return $this->findAll(
            'SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order',
            [$productId]
        );
    }

    public function findById(int $id): ?array {
        return $this->findOne('SELECT * FROM product_images WHERE id = ?', [$id]);
    }

    public function create(int $productId, string $filename, int $sortOrder = 0): int {
        return (int)$this->insert(
            'INSERT INTO product_images (product_id, filename, sort_order)
             VALUES (?, ?, ?)',
            [$productId, $filename, $sortOrder]
        );
    }

    public function delete(int $id): void {
        $this->execute('DELETE FROM product_images WHERE id = ?', [$id]);
    }

    public function getNextSortOrder(int $productId): int {
        $row = $this->findOne(
            'SELECT MAX(sort_order) AS max_sort FROM product_images WHERE product_id = ?',
            [$productId]
        );
        return ($row['max_sort'] ?? -1) + 1;
    }
}
