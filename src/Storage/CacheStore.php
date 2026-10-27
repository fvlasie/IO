<?php

namespace IO\Storage;

use PDO;

class CacheStore
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
            CREATE TABLE IF NOT EXISTS cache_users (
                user_id TEXT PRIMARY KEY,
                payload TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cache_timetable (
                user_id TEXT NOT NULL,
                date TEXT NOT NULL,
                payload TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (user_id, date)
            );
        ");
    }

    // -----------------------------
    // USERS CACHE
    // -----------------------------

    public function getUsers(): ?array
    {
        $stmt = $this->db->prepare("SELECT payload FROM cache_users");
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!$rows) {
            return null;
        }

        return array_map(fn($json) => json_decode($json, true), $rows);
    }

    public function setUsers(array $users): void
    {
        $this->db->exec("DELETE FROM cache_users");

        $stmt = $this->db->prepare("
            INSERT INTO cache_users (user_id, payload, updated_at)
            VALUES (:user_id, :payload, :updated_at)
        ");

        $now = date('c');

        foreach ($users as $user) {
            $stmt->execute([
                'user_id'    => $user['gibbonPersonID'], // correct key
                'payload'    => json_encode($user),
                'updated_at' => $now,
            ]);
        }
    }

    // -----------------------------
    // TIMETABLE CACHE
    // -----------------------------

    public function getTimetable(string $userId, string $date): ?array
    {
        $stmt = $this->db->prepare("
            SELECT payload
            FROM cache_timetable
            WHERE user_id = :user_id AND date = :date
        ");

        $stmt->execute([
            'user_id' => $userId,
            'date'    => $date,
        ]);

        $row = $stmt->fetchColumn();

        return $row ? json_decode($row, true) : null;
    }

    public function setTimetable(string $userId, string $date, array $rows): void
    {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO cache_timetable (user_id, date, payload, updated_at)
            VALUES (:user_id, :date, :payload, :updated_at)
        ");

        $stmt->execute([
            'user_id'    => $userId,
            'date'       => $date,
            'payload'    => json_encode($rows),
            'updated_at' => date('c'),
        ]);
    }

    public function getUser(string $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT payload
            FROM cache_users
            WHERE user_id = :user_id
        ");

        $stmt->execute(['user_id' => $userId]);

        $row = $stmt->fetchColumn();

        return $row ? json_decode($row, true) : null;
    }

    public function getAllRoles(): array
    {
        $stmt = $this->db->prepare("SELECT payload FROM cache_users");
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $roles = [];

        foreach ($rows as $json) {
            $user = json_decode($json, true);

            if (!empty($user['roles']) && is_array($user['roles'])) {
                foreach ($user['roles'] as $role) {
                    $roles[$role] = true;
                }
            }
        }

        return array_keys($roles);
    }
}
