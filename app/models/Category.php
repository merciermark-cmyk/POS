<?php
class Category extends BaseModel {

    public function getAll(): array {
        return $this->findAll('SELECT * FROM categories ORDER BY name');
    }

    public function findById(int $id): ?array {
        return $this->findOne('SELECT * FROM categories WHERE id = ?', [$id]);
    }
}
