<?php
class Terminal extends BaseModel {

    public function getAll(): array {
        return $this->findAll('SELECT * FROM pos_terminals ORDER BY name');
    }

    public function getActive(): array {
        return $this->findAll('SELECT * FROM pos_terminals WHERE is_active = 1 ORDER BY name');
    }

    public function findById(int $id): ?array {
        return $this->findOne('SELECT * FROM pos_terminals WHERE id = ?', [$id]);
    }

    public function create(array $data): int {
        return (int)$this->insert(
            'INSERT INTO pos_terminals (name, print_service_url, is_active) VALUES (?, ?, ?)',
            [
                $data['name'],
                $data['print_service_url'] ?? 'http://localhost:5000',
                (int)($data['is_active'] ?? 1),
            ]
        );
    }

    public function update(int $id, array $data): void {
        $this->execute(
            'UPDATE pos_terminals SET name = ?, print_service_url = ?, is_active = ?, updated_at = NOW() WHERE id = ?',
            [
                $data['name'],
                $data['print_service_url'] ?? 'http://localhost:5000',
                (int)($data['is_active'] ?? 1),
                $id,
            ]
        );
    }

    public function delete(int $id): bool {
        // Block delete if shifts exist for this terminal
        $count = $this->count(
            'SELECT COUNT(*) FROM pos_shifts WHERE terminal_id = ?',
            [$id]
        );
        if ($count > 0) {
            return false;
        }
        $this->execute('DELETE FROM pos_terminals WHERE id = ?', [$id]);
        return true;
    }

    public function hasOpenShift(int $terminalId): bool {
        return (bool)$this->findOne(
            "SELECT id FROM pos_shifts WHERE terminal_id = ? AND status = 'open' LIMIT 1",
            [$terminalId]
        );
    }
}
