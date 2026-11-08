<?php
// Запуск по SSH после заполнения .env: php server/max-subscribe.php
// Подписывает бота на вебхук APP_URL/api/max/webhook (только HTTPS, порт 443).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/routes.php';
$token = S2G\env('MAX_BOT_TOKEN'); $secret = S2G\env('MAX_WEBHOOK_SECRET'); $app = rtrim(S2G\env('APP_URL'), '/');
if ($token === '' || $secret === '' || $app === '') { fwrite(STDERR, "Заполни MAX_BOT_TOKEN, MAX_WEBHOOK_SECRET и APP_URL в .env\n"); exit(1); }
[$code, $out] = S2G\httpPost(S2G\env('MAX_API_BASE', 'https://platform-api2.max.ru') . '/subscriptions',
    ['url' => $app . '/api/max/webhook', 'update_types' => ['message_created', 'bot_started'], 'secret' => $secret], ['Authorization: ' . $token]);
echo "HTTP $code\n$out\n";
exit($code === 200 ? 0 : 1);
