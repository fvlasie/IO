<?php

namespace IO\Storage;

use PDO;

class RoleMapStore
{
    private PDO $db;

    public function __construct(Database $database)
    {
        $this->db = $database->pdo();
        $this->initSchema();
    }

    private function initSchema(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS role_map (
                role_name TEXT PRIMARY KEY,
                schedule_type TEXT NOT NULL,
                gender_override TEXT,
                buffer_before INTEGER DEFAULT 0,
                buffer_after INTEGER DEFAULT 0,
                updated_at TEXT NOT NULL
            );
        ");
    }

    public function set(
        string $roleName,
        string $scheduleType,
        ?string $genderOverride = null,
        int $bufferBefore = 0,
        int $bufferAfter = 0
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO role_map (role_name, schedule_type, gender_override, buffer_before, buffer_after, updated_at)
            VALUES (:role, :type, :override, :before, :after, :updated)
            ON CONFLICT(role_name) DO UPDATE SET
                schedule_type   = excluded.schedule_type,
                gender_override = excluded.gender_override,
                buffer_before   = excluded.buffer_before,
                buffer_after    = excluded.buffer_after,
                updated_at      = excluded.updated_at
        ");

        $stmt->execute([
            ':role'     => $roleName,
            ':type'     => $scheduleType,
            ':override' => $genderOverride,
            ':before'   => $bufferBefore,
            ':after'    => $bufferAfter,
            ':updated'  => date('c')
        ]);
    }

    public function get(string $roleName): ?array
    {
        $stmt = $this->db->prepare("
            SELECT schedule_type, gender_override, buffer_before, buffer_after
            FROM role_map
            WHERE role_name = :role
        ");

        $stmt->execute([':role' => $roleName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function getAll(): array
    {
        $stmt = $this->db->query("
            SELECT role_name, schedule_type, gender_override, buffer_before, buffer_after
            FROM role_map
            ORDER BY role_name
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
