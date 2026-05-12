<?php
class PosUser extends BaseModel {

    public function findByUsername(string $username): ?array {
        return $this->findOne(
            'SELECT * FROM pos_users WHERE username = ? AND is_active = 1',
            [$username]
        );
    }

    public function findByPin(string $pin): ?array {
        return $this->findOne(
            'SELECT * FROM pos_users WHERE pin = ? AND is_active = 1',
            [$pin]
        );
    }

    public function findById(int $id): ?array {
        return $this->findOne('SELECT * FROM pos_users WHERE id = ?', [$id]);
    }

    public function getAll(): array {
        return $this->findAll('SELECT * FROM pos_users ORDER BY username');
    }

    public function getActive(): array {
        return $this->findAll(
            'SELECT id, username, role, pin FROM pos_users WHERE is_active = 1 ORDER BY username'
        );
    }

    public function create(array $data): int {
        return (int)$this->insert(
            'INSERT INTO pos_users (username, password_hash, pin, role, is_active)
             VALUES (?, ?, ?, ?, ?)',
            [
                $data['username'],
                password_hash($data['password'], PASSWORD_DEFAULT),
                $data['pin'] ?: null,
                $data['role'] ?? ROLE_CASHIER,
                $data['is_active'] ?? 1,
            ]
        );
    }

    public function update(int $id, array $data): void {
        $fields = [];
        $params = [];

        if (isset($data['username'])) {
            $fields[] = 'username = ?';
            $params[] = $data['username'];
        }
        if (!empty($data['password'])) {
            $fields[] = 'password_hash = ?';
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        if (array_key_exists('pin', $data)) {
            $fields[] = 'pin = ?';
            $params[] = $data['pin'] ?: null;
        }
        if (isset($data['role'])) {
            $fields[] = 'role = ?';
            $params[] = $data['role'];
        }
        if (isset($data['is_active'])) {
            $fields[] = 'is_active = ?';
            $params[] = (int)$data['is_active'];
        }
        if (array_key_exists('schedule_user_id', $data)) {
            $fields[] = 'schedule_user_id = ?';
            $params[] = $data['schedule_user_id'] ?: null;
        }

        if ($fields) {
            $params[] = $id;
            $this->execute(
                'UPDATE pos_users SET ' . implode(', ', $fields) . ' WHERE id = ?',
                $params
            );
        }
    }

    public function delete(int $id): void {
        $this->execute('DELETE FROM pos_users WHERE id = ?', [$id]);
    }

    public function verifyPassword(array $user, string $password): bool {
        return password_verify($password, $user['password_hash']);
    }

    public function findManagerByPin(string $pin): ?array {
        return $this->findOne(
            'SELECT id, username, role FROM pos_users WHERE pin = ? AND role = ? AND is_active = 1',
            [$pin, ROLE_MANAGER]
        );
    }

    public function verifyPin(int $userId, string $pin): bool {
        $user = $this->findById($userId);
        if (!$user) return false;
        if (empty($user['pin'])) return true; // no pin set — allow through
        return $user['pin'] === $pin;
    }
}
