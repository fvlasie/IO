<?php

namespace IO\Storage;

use PDO;

class Database
{
    private PDO $pdo;

    public function __construct(string $file)
    {
        $this->pdo = new PDO('sqlite:' . $file);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode = WAL;');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
