<?php
declare(strict_types=1);
namespace S2G;

/**
 * Миграции: файлы server/migrations/*.php в порядке имени (001_..., 002_...).
 * Каждый файл возвращает function (PDO $db, string $driver): void.
 * Применённые имена хранятся в таблице schema_migrations. Если новых файлов нет,
 * стоит один быстрый SELECT и работа продолжается. Уже применённый файл не править:
 * изменения схемы добавляй новым файлом.
 * @return string[] имена применённых сейчас миграций
 */
function migrate(?string $dir = null): array {
    $dir ??= env('S2G_MIGRATIONS_DIR', __DIR__ . '/migrations');
    $files = glob(rtrim($dir, '/') . '/*.php') ?: [];
    sort($files, SORT_STRING);
    $names = array_map(fn($f) => basename($f, '.php'), $files);
    if (!$names) return [];

    $applied = appliedMigrations();
    $pending = array_values(array_diff($names, $applied));
    if (!$pending) return [];

    $lock = driver() === 'mysql';
    if ($lock) {
        // один процесс за раз: параллельные запросы подождут и увидят уже применённое
        $got = one("SELECT GET_LOCK('s2g_migrate', 20) g");
        if (!$got || (int)$got['g'] !== 1) throw new \RuntimeException('Не удалось получить блокировку миграций');
    }
    $done = [];
    try {
        $applied = appliedMigrations();
        foreach ($pending as $name) {
            if (in_array($name, $applied, true)) continue;
            $fn = require $dir . '/' . $name . '.php';
            if (!is_callable($fn)) throw new \RuntimeException("Миграция $name не возвращает функцию");
            $fn(db(), driver());
            q('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)', [$name, time()]);
            $done[] = $name;
        }
    } finally {
        if ($lock) { try { one("SELECT RELEASE_LOCK('s2g_migrate') r"); } catch (\Throwable $e) { /* соединение закроется само */ } }
    }
    return $done;
}

function appliedMigrations(): array {
    try {
        $rows = all('SELECT name FROM schema_migrations');
    } catch (\PDOException $e) {
        db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(100) NOT NULL PRIMARY KEY, applied_at INT NOT NULL)');
        $rows = all('SELECT name FROM schema_migrations');
    }
    return array_column($rows, 'name');
}
