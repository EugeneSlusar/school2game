<?php
declare(strict_types=1);
// Миграция 002: анонимные аккаунты с кодом восстановления, ограничитель частоты запросов.
// Таблица users пересоздаётся без max_user_id и с recovery_hash (вход через MAX не реализуется).
// Повторный запуск безопасен: если recovery_hash уже есть, пересборка пропускается.
return function (\PDO $db, string $driver): void {
    $sqlite = $driver === 'sqlite';
    $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $has = true;
    try { $db->query('SELECT recovery_hash FROM users LIMIT 1')->fetchAll(); } catch (\PDOException $e) { $has = false; }
    if (!$has) {
        $db->exec('DROP TABLE IF EXISTS users_new');
        $db->exec("CREATE TABLE users_new (
            id $pk,
            nick VARCHAR(32) NULL,
            nick_key VARCHAR(32) NULL UNIQUE,
            avatar VARCHAR(16) NOT NULL DEFAULT '',
            recovery_hash CHAR(64) NULL UNIQUE,
            created_at INT NOT NULL,
            last_seen INT NOT NULL
        )$tail");
        $db->exec('INSERT INTO users_new (id, nick, nick_key, avatar, created_at, last_seen)
                   SELECT id, nick, nick_key, avatar, created_at, last_seen FROM users');
        $db->exec('DROP TABLE users');
        $db->exec('ALTER TABLE users_new RENAME TO users');
    }
    $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        k VARCHAR(80) NOT NULL PRIMARY KEY,
        hits INT NOT NULL,
        win_start INT NOT NULL
    )$tail");
};
