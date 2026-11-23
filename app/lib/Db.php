<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function connect(array $cfg): PDO
    {
        if (self::$pdo === null) {
            $pdo = new PDO($cfg['dsn'], $cfg['user'] ?? null, $cfg['password'] ?? null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            if (self::driver($pdo) === 'sqlite') {
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            throw new \RuntimeException('Base de données non connectée');
        }
        return self::$pdo;
    }

    public static function driver(?PDO $pdo = null): string
    {
        return ($pdo ?? self::pdo())->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function run(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** Insère une ligne et retourne son id. */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', $cols),
            implode(',', array_map(fn($c) => ':' . $c, $cols))
        );
        self::run($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(',', array_map(fn($c) => "$c = :$c", array_keys($data)));
        return self::run("UPDATE $table SET $set WHERE $where", $data + $whereParams);
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Exécute un fichier SQL en adaptant la syntaxe au pilote. */
    public static function runSchema(string $file): void
    {
        $sql = file_get_contents($file);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        if (self::driver() === 'sqlite') {
            $sql = str_replace('INTEGER PRIMARY KEY AUTO_INCREMENT', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        } else {
            $sql = str_replace('INTEGER PRIMARY KEY AUTO_INCREMENT', 'INT UNSIGNED PRIMARY KEY AUTO_INCREMENT', $sql);
            $sql = preg_replace('/\)\s*;/', ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;', $sql);
            // Les clés étrangères/colonnes INTEGER vers INT UNSIGNED pour cohérence
            $sql = preg_replace('/\b(user_id|group_id|folder_id|page_id|parent_id|created_by|updated_by|uploaded_by) INTEGER/', '$1 INT UNSIGNED', $sql);
        }
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            self::pdo()->exec($stmt);
        }
    }
}
