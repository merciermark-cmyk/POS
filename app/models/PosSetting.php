<?php
class PosSetting extends BaseModel {

    public function get(string $key, ?string $default = null): ?string {
        $row = $this->findOne(
            'SELECT setting_value FROM pos_settings WHERE setting_key = ?',
            [$key]
        );
        return $row ? $row['setting_value'] : $default;
    }

    public function set(string $key, ?string $value): void {
        $this->execute(
            'INSERT INTO pos_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()',
            [$key, $value, $value]
        );
    }

    public function getAll(): array {
        $rows = $this->findAll('SELECT * FROM pos_settings ORDER BY setting_key');
        $map = [];
        foreach ($rows as $row) {
            $map[$row['setting_key']] = $row['setting_value'];
        }
        return $map;
    }

    public function getShopLocationId(): int {
        return (int)$this->get('shop_location_id', '0');
    }
}
