<?php
declare(strict_types=1);
// Миграция 001: начальная схема. Миграции должны быть идемпотентными (IF NOT EXISTS),
// потому что DDL в MySQL не откатывается при сбое посреди файла.
return function (\PDO $db, string $driver): void {
    $sqlite = $driver === 'sqlite';
    $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $stmts = [
        "CREATE TABLE IF NOT EXISTS users (
            id $pk,
            max_user_id BIGINT NOT NULL UNIQUE,
            nick VARCHAR(32) NULL,
            nick_key VARCHAR(32) NULL UNIQUE,
            avatar VARCHAR(16) NOT NULL DEFAULT '',
            created_at INT NOT NULL,
            last_seen INT NOT NULL
        )$tail",
        "CREATE TABLE IF NOT EXISTS sessions (
            token_hash CHAR(64) NOT NULL PRIMARY KEY,
            user_id INT NOT NULL,
            expires_at INT NOT NULL
        )$tail",
        "CREATE TABLE IF NOT EXISTS login_codes (
            code VARCHAR(16) NOT NULL PRIMARY KEY,
            poll_hash CHAR(64) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            user_id INT NULL,
            ip_hash CHAR(16) NOT NULL,
            created_at INT NOT NULL,
            expires_at INT NOT NULL
        )$tail",
        "CREATE TABLE IF NOT EXISTS guests (
            guest_id VARCHAR(64) NOT NULL PRIMARY KEY,
            user_id INT NOT NULL
        )$tail",
        "CREATE TABLE IF NOT EXISTS results (
            id VARCHAR(64) NOT NULL PRIMARY KEY,
            user_id INT NOT NULL,
            played_at INT NOT NULL,
            subject VARCHAR(8) NOT NULL,
            topic VARCHAR(16) NOT NULL,
            level VARCHAR(8) NOT NULL,
            sport VARCHAR(16) NOT NULL,
            format VARCHAR(4) NOT NULL,
            you_pts INT NOT NULL,
            foe_pts INT NOT NULL,
            score INT NOT NULL,
            outcome VARCHAR(4) NOT NULL,
            correct INT NOT NULL,
            wrong INT NOT NULL,
            timeouts INT NOT NULL,
            sec INT NOT NULL,
            by_topic TEXT NOT NULL,
            created_at INT NOT NULL
        )$tail",
        "CREATE TABLE IF NOT EXISTS topic_stats (
            user_id INT NOT NULL,
            topic VARCHAR(16) NOT NULL,
            recent TEXT NOT NULL,
            total INT NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id, topic)
        )$tail",
    ];
    foreach ($stmts as $sql) $db->exec($sql);
    // индексы: «IF NOT EXISTS» есть не везде, поэтому повторное создание просто игнорируем
    foreach (['CREATE INDEX idx_results_user ON results (user_id, played_at)', 'CREATE INDEX idx_results_time ON results (played_at)'] as $sql) {
        try { $db->exec($sql); } catch (\PDOException $e) { /* уже есть */ }
    }
};
