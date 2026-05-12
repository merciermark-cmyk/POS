<?php
class Category extends BaseModel {

    public function getAll(): array {
        return $this->findAll('SELECT * FROM categories ORDER BY sort_order, name');
    }

    public function getTopLevel(): array {
        return $this->findAll(
            'SELECT * FROM categories WHERE parent_id IS NULL ORDER BY sort_order, name'
        );
    }

    public function getAllWithHierarchy(): array {
        $all = $this->findAll('SELECT * FROM categories ORDER BY sort_order, name');

        $parents = [];
        $children = [];
        foreach ($all as $cat) {
            if ($cat['parent_id']) {
                $children[(int)$cat['parent_id']][] = $cat;
            } else {
                $cat['children'] = [];
                $parents[(int)$cat['id']] = $cat;
            }
        }

        foreach ($children as $parentId => $kids) {
            if (isset($parents[$parentId])) {
                $parents[$parentId]['children'] = $kids;
            }
        }

        return array_values($parents);
    }

    public function findById(int $id): ?array {
        return $this->findOne('SELECT * FROM categories WHERE id = ?', [$id]);
    }
}
