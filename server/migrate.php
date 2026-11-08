<?php
// Запуск по SSH: php server/migrate.php  (создаёт таблицы, повторный запуск безопасен)
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/routes.php';
S2G\migrate();
echo "Таблицы готовы (" . S2G\driver() . ")\n";
