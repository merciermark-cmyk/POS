<?php
/**
 * Base model providing PDO-backed query helpers.
 * All query parameters MUST go through prepared statements.
 */
class BaseModel {
    protected PDO $db;

    public function __construct() {
        $this->db = getDB();
    }

    /** Return a single row or null */
    protected function findOne(string $sql, array $params = []): ?array {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** Return all matching rows */
    protected function findAll(string $sql, array $params = []): array {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Execute a non-SELECT query; returns affected row count */
    protected function execute(string $sql, array $params = []): int {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** Execute INSERT and return last insert ID */
    protected function insert(string $sql, array $params = []): int|string {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $this->db->lastInsertId();
    }

    /** Count a result set */
    protected function count(string $sql, array $params = []): int {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function beginTransaction(): void   { $this->db->beginTransaction(); }
    public function commit(): void             { $this->db->commit(); }
    public function rollBack(): void           { $this->db->rollBack(); }
    public function inTransaction(): bool      { return $this->db->inTransaction(); }
    public function lastInsertId(): int|string { return $this->db->lastInsertId(); }
}
