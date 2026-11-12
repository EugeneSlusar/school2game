<?php
declare(strict_types=1);
// Миграция 004: статистика каждого игрока дуэли (ответы, ошибки, серия, скорость) для итогового экрана.
// Хранится JSON-текстом, показывается обоим игрокам после матча. Идемпотентна: повторное добавление колонки пропускается.
return function (\PDO $db, string $driver): void {
    foreach (['p1_stat', 'p2_stat'] as $col) {
        try { $db->exec("ALTER TABLE duels ADD COLUMN $col TEXT NULL"); } catch (\PDOException $e) { /* колонка уже есть */ }
    }
};
