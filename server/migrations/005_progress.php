<?php
declare(strict_types=1);
// Миграция 005: система возвращения (серия дней, задания дня, опыт, значки, открываемые аватары).
// Сам прогресс не хранится: он считается из результатов (results, duel_results, test_results) в server/progress.php,
// поэтому нечему расходиться. Здесь только таблица результатов «Контрольной» и индексы для быстрого подсчёта.
return function (\PDO $db, string $driver): void {
    $tail = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $db->exec("CREATE TABLE IF NOT EXISTS test_results (
        id VARCHAR(64) NOT NULL PRIMARY KEY,
        user_id INT NOT NULL,
        played_at INT NOT NULL,
        created_at INT NOT NULL,
        subject VARCHAR(8) NOT NULL,
        topic VARCHAR(16) NOT NULL,
        total INT NOT NULL,
        correct INT NOT NULL,
        wrong INT NOT NULL,
        sec INT NOT NULL
    )$tail");
    $index = function (string $sql) use ($db): void { try { $db->exec($sql); } catch (\PDOException $e) { /* индекс уже есть: миграция идемпотентна */ } };
    $index('CREATE INDEX test_results_user ON test_results (user_id, played_at)');
    $index('CREATE INDEX results_user ON results (user_id, played_at)');
};
