<?php
// Запуск по SSH: php server/migrate.php. Применяет новые миграции (повтор безопасен).
// То же самое происходит автоматически при любом запросе к API.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/routes.php';
$done = S2G\migrate();
echo $done ? 'Применено: ' . implode(', ', $done) . "\n" : "Новых миграций нет\n";
