<?php
declare(strict_types=1);
// Миграция 006: еженедельная лига, кубки и рамки аватара.
//  league_members — кто в какой лиге и группе на какой неделе, итог недели (место, опыт, повышение/понижение);
//  league_weeks   — какие недели уже подведены (повышения и кубки выдаются один раз);
//  users.frame    — выбранная рамка аватара (открывается лигами, сериями, уровнями, кубками).
// Опыт за неделю не хранится: он считается из результатов (server/league.php). Миграция идемпотентна.
return function (\PDO $db, string $driver): void {
    $tail = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $db->exec("CREATE TABLE IF NOT EXISTS league_members (
        week INT NOT NULL,
        user_id INT NOT NULL,
        league TINYINT NOT NULL,
        grp INT NOT NULL,
        final_rank INT NULL,
        final_xp INT NULL,
        result VARCHAR(8) NULL,
        PRIMARY KEY (week, user_id)
    )$tail");
    $db->exec("CREATE TABLE IF NOT EXISTS league_weeks (
        week INT NOT NULL PRIMARY KEY,
        settled TINYINT NOT NULL DEFAULT 0
    )$tail");
    $index = function (string $sql) use ($db): void { try { $db->exec($sql); } catch (\PDOException $e) { /* индекс уже есть: миграция идемпотентна */ } };
    $index('CREATE INDEX league_group ON league_members (week, league, grp)');
    $index('CREATE INDEX league_user ON league_members (user_id, week)');
    try { $db->exec("ALTER TABLE users ADD COLUMN frame VARCHAR(16) NOT NULL DEFAULT ''"); } catch (\PDOException $e) { /* колонка уже есть */ }
};
