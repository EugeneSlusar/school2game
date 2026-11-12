<?php
declare(strict_types=1);
// Миграция 003: онлайн-дуэли двух игроков.
//  duels        — комната/матч (код, настройки, общий seed, очки обоих, итог);
//  duel_queue   — очередь «случайный соперник»;
//  duel_results — итоги для отдельного рейтинга онлайн-игр (по строке на игрока).
return function (\PDO $db, string $driver): void {
    $sqlite = $driver === 'sqlite';
    $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $db->exec("CREATE TABLE IF NOT EXISTS duels (
        id $pk,
        code VARCHAR(8) NOT NULL,
        status VARCHAR(12) NOT NULL,
        source VARCHAR(8) NOT NULL,
        subject VARCHAR(8) NOT NULL,
        topic VARCHAR(16) NOT NULL,
        level VARCHAR(8) NOT NULL,
        seed BIGINT NOT NULL,
        created_at INT NOT NULL,
        start_ms BIGINT NULL,
        p1 INT NOT NULL,
        p2 INT NULL,
        p1_sport VARCHAR(16) NOT NULL,
        p2_sport VARCHAR(16) NULL,
        p1_pts INT NOT NULL DEFAULT 0,
        p2_pts INT NOT NULL DEFAULT 0,
        p1_seen BIGINT NOT NULL DEFAULT 0,
        p2_seen BIGINT NOT NULL DEFAULT 0,
        p1_fin TINYINT NOT NULL DEFAULT 0,
        p2_fin TINYINT NOT NULL DEFAULT 0,
        forfeit TINYINT NOT NULL DEFAULT 0,
        winner TINYINT NULL,
        rated TINYINT NOT NULL DEFAULT 0,
        finished_at INT NULL
    )$tail");
    $index = function (string $sql) use ($db): void { try { $db->exec($sql); } catch (\PDOException $e) { /* индекс уже есть: миграция идемпотентна */ } };
    $index('CREATE INDEX duels_code ON duels (code, status)');
    $db->exec("CREATE TABLE IF NOT EXISTS duel_queue (
        user_id INT NOT NULL PRIMARY KEY,
        subject VARCHAR(8) NOT NULL,
        level VARCHAR(8) NOT NULL,
        sport VARCHAR(16) NOT NULL,
        created_at INT NOT NULL,
        duel_id INT NULL
    )$tail");
    $db->exec("CREATE TABLE IF NOT EXISTS duel_results (
        id $pk,
        duel_id INT NOT NULL,
        user_id INT NOT NULL,
        played_at INT NOT NULL,
        subject VARCHAR(8) NOT NULL,
        score INT NOT NULL,
        opp_score INT NOT NULL,
        outcome VARCHAR(4) NOT NULL,
        UNIQUE (duel_id, user_id)
    )$tail");
    $index('CREATE INDEX duel_results_user ON duel_results (user_id, played_at)');
};
