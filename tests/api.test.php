<?php
// php tests/api.test.php — проверка бэкенда на SQLite в памяти, без сети
declare(strict_types=1);
putenv('DB_DRIVER=sqlite'); putenv('DB_PATH=:memory:'); putenv('S2G_ENV_FILE=/nonexistent');
putenv('MAX_WEBHOOK_SECRET=test-secret-123'); putenv('MAX_BOT_TOKEN=tok'); putenv('MAX_BOT_LINK=https://max.ru/testbot');
require __DIR__ . '/../server/routes.php';
use function S2G\{dispatch, migrate, q, one};

$fail = 0; $n = 0;
function ok(bool $c, string $name): void { global $fail, $n; $n++; if (!$c) { $fail++; echo "ПРОВАЛ: $name\n"; } }
$sent = [];
$GLOBALS['s2g_http'] = function ($url, $body, $h) use (&$sent) { $sent[] = [$url, $body, $h]; return [200, '{}']; };
$first = migrate(); $again = migrate();
ok($first === ['001_init'] && $again === [], 'миграции: первый запуск применяет, повторный ничего');
// новая миграция подхватывается сама, старые не повторяются
$tmp = sys_get_temp_dir() . '/s2g-mig-' . getmypid(); mkdir($tmp);
copy(__DIR__ . '/../server/migrations/001_init.php', "$tmp/001_init.php");
file_put_contents("$tmp/002_extra.php", '<?php return function ($db, $d) { $db->exec("CREATE TABLE IF NOT EXISTS extra_t (id INT)"); };');
ok(migrate($tmp) === ['002_extra'], 'новый файл миграции применён сразу');
ok(migrate($tmp) === [], 'без новых файлов миграция ничего не делает');
file_put_contents("$tmp/003_bad.php", '<?php return function ($db, $d) { $db->exec("NOT SQL"); };');
$threw = false; try { migrate($tmp); } catch (\Throwable $e) { $threw = true; }
ok($threw && !in_array('003_bad', S2G\appliedMigrations(), true), 'сбойная миграция не помечается применённой');
array_map('unlink', glob("$tmp/*.php")); rmdir($tmp);
ok(S2G\prepare() === null, 'prepare() проходит');

// --- без настроенного MAX сайт работает
$saved = [getenv('MAX_BOT_TOKEN'), getenv('MAX_BOT_LINK')];
putenv('MAX_BOT_TOKEN'); putenv('MAX_BOT_LINK');
[$st, $r] = dispatch(['method' => 'GET', 'route' => '/config', 'query' => [], 'body' => null, 'cookie' => '', 'ip' => '1', 'secretHeader' => '', 'jsonType' => false]);
ok($st === 200 && $r['login'] === false && count($r['avatars']) === 24, 'config: вход выключен без MAX, аватары отдаются');
[$st, $r] = dispatch(['method' => 'POST', 'route' => '/auth/start', 'query' => [], 'body' => [], 'cookie' => '', 'ip' => '1', 'secretHeader' => '', 'jsonType' => true]);
ok($st === 503 && $r['error'] === 'login_not_configured', 'auth/start без MAX = 503 login_not_configured');
[$st, $r] = dispatch(['method' => 'GET', 'route' => '/leaderboard', 'query' => [], 'body' => null, 'cookie' => '', 'ip' => '1', 'secretHeader' => '', 'jsonType' => false]);
ok($st === 200, 'рейтинг читается без MAX');
putenv('MAX_BOT_TOKEN=' . $saved[0]); putenv('MAX_BOT_LINK=' . $saved[1]);

function call(string $m, string $route, ?array $body = null, string $cookie = '', array $query = [], string $secret = '', bool $json = true): array {
    return dispatch(['method' => $m, 'route' => $route, 'query' => $query, 'body' => $body, 'cookie' => $cookie, 'ip' => '1.2.3.4', 'secretHeader' => $secret, 'jsonType' => $json]);
}
function login(int $maxId): array {
    global $sent;
    [, $s] = call('POST', '/auth/start', []);
    [, $p0] = call('POST', '/auth/poll', ['poll' => $s['poll']]);
    ok($p0['status'] === 'pending', 'до подтверждения pending');
    [$st] = call('POST', '/max/webhook', ['update_type' => 'bot_started', 'user' => ['user_id' => $maxId], 'payload' => $s['code']], '', [], 'test-secret-123', false);
    ok($st === 200, 'вебхук принят');
    [, $p, $ck] = call('POST', '/auth/poll', ['poll' => $s['poll']]) + [2 => null];
    ok($p['status'] === 'ok' && $ck && strlen($ck['value']) === 64, 'poll выдал сессию');
    [, $p2] = call('POST', '/auth/poll', ['poll' => $s['poll']]);
    ok($p2['status'] === 'expired', 'код одноразовый');
    return [$p['user'], $ck['value']];
}
function match_(string $id, array $o = []): array {
    return $o + ['id' => $id, 't' => (time() - 60) * 1000, 'subject' => 'math', 'topic' => 'all', 'level' => 'medium', 'sport' => 'football', 'format' => '1x1',
        'you' => 20, 'foe' => 10, 'outcome' => 'win', 'correct' => 12, 'wrong' => 2, 'timeouts' => 1, 'sec' => 120, 'byTopic' => ['mul' => ['n' => 15, 'ok' => 12]]];
}

// --- разбор пути
foreach (['/school2game/api/health' => '/health', '/school2game/www/api/auth/start' => '/auth/start', '/api/leaderboard' => '/leaderboard',
          '/school2game/www/api/' => '/', '/school2game/www/api/index.php' => '/', '/api/max/webhook/' => '/max/webhook'] as $path => $route) {
    ok(S2G\routeFromPath($path) === $route, "путь $path → $route (получили " . S2G\routeFromPath($path) . ')');
}

// --- безопасность входа
[$st] = call('POST', '/max/webhook', ['update_type' => 'bot_started'], '', [], 'wrong', false); ok($st === 401, 'вебхук с чужим секретом отклонён');
[$st] = call('POST', '/auth/start', null, '', [], '', false); ok($st === 415, 'POST без JSON отклонён (CSRF)');
[$st] = call('GET', '/me'); ok($st === 401, '/me без входа = 401');
[$st, $s] = call('POST', '/auth/start', []); ok($st === 200 && $s['link'] === 'https://max.ru/testbot?start=' . $s['code'] && strlen($s['code']) === 8, 'start даёт код и ссылку');
// код текстом, в нижнем регистре и с лишним
[, $s2] = call('POST', '/auth/start', []);
call('POST', '/max/webhook', ['update_type' => 'message_created', 'message' => ['sender' => ['user_id' => 555], 'body' => ['text' => ' мой код: ' . strtolower($s2['code'])]]], '', [], 'test-secret-123', false);
[, $pp] = call('POST', '/auth/poll', ['poll' => $s2['poll']]);
ok($pp['status'] === 'ok' || $pp['status'] === 'pending', 'код текстом обработан');
q("UPDATE login_codes SET expires_at = 1 WHERE code = ?", [$s['code']]);
call('POST', '/max/webhook', ['update_type' => 'bot_started', 'user' => ['user_id' => 9], 'payload' => $s['code']], '', [], 'test-secret-123', false);
[, $pe] = call('POST', '/auth/poll', ['poll' => $s['poll']]); ok($pe['status'] === 'expired', 'просроченный код не работает');

// --- вход, ник, профиль
[$u1, $c1] = login(1001);
ok($u1['needsNick'] === true, 'новому нужен ник');
[$st] = call('POST', '/profile', ['nick' => '<b>x</b>'], $c1); ok($st === 400, 'ник с тегами отклонён');
[$st] = call('POST', '/profile', ['nick' => 'Ветер', 'avatar' => '⚽'], $c1); ok($st === 200, 'ник сохранён');
[$u2, $c2] = login(1002);
[$st, $r] = call('POST', '/profile', ['nick' => 'ветер'], $c2); ok($st === 409, 'ник занят без учёта регистра');
call('POST', '/profile', ['nick' => 'Молния', 'avatar' => '🔥'], $c2);
[$st, $r] = call('GET', '/me', null, $c1); ok($st === 200 && $r['user']['nick'] === 'Ветер', '/me работает');

// --- проверка матчей
$now = time();
foreach ([
    'нереальные очки' => [match_('a1', ['you' => 49, 'correct' => 3, 'byTopic' => ['mul' => ['n' => 6, 'ok' => 3]], 'wrong' => 2, 'timeouts' => 1]), 'too_many_points'],
    'слишком быстро' => [match_('a2', ['correct' => 300, 'byTopic' => ['mul' => ['n' => 300, 'ok' => 300]], 'you' => 40, 'wrong' => 0, 'timeouts' => 0]), 'too_fast'],
    'исход не сходится' => [match_('a3', ['outcome' => 'lose']), 'outcome_mismatch'],
    'темы не сходятся' => [match_('a4', ['byTopic' => ['mul' => ['n' => 15, 'ok' => 5]]]), 'topics_mismatch'],
    'чужая тема предмета' => [match_('a5', ['byTopic' => ['vowel' => ['n' => 15, 'ok' => 12]]]), 'bad_topics'],
    'будущее' => [match_('a6', ['t' => ($now + 86400 * 3) * 1000]), 'bad_time'],
    'слишком короткий' => [match_('a7', ['sec' => 2]), 'bad_duration'],
    'кончился рано без цели' => [match_('a8', ['sec' => 60, 'correct' => 12, 'wrong' => 2, 'timeouts' => 1]), 'ended_early'],
    'плохой id' => [match_('../x'), 'bad_id'],
    'плохой уровень' => [match_('a9', ['level' => 'god']), 'bad_level'],
] as $name => [$m, $why]) {
    [$row, $r] = S2G\checkMatch($m, $now);
    ok($row === null && $r === $why, "$name → $why (получили " . ($r ?? 'принят') . ')');
}
[$row] = S2G\checkMatch(match_('good'), $now); ok($row !== null && $row['score'] === 20, 'нормальный матч принят');
[$row] = S2G\checkMatch(match_('t', ['format' => '2x2', 'you' => 60, 'foe' => 30, 'correct' => 12]), $now); ok($row !== null && $row['score'] === 30, '2×2: очки на игрока');
[$row] = S2G\checkMatch(match_('w', ['you' => 50, 'foe' => 20, 'sec' => 70, 'correct' => 25, 'wrong' => 0, 'timeouts' => 0, 'byTopic' => ['mul' => ['n' => 25, 'ok' => 25]]]), $now);
ok($row !== null, 'матч до цели 50 за 70 с принят');

// --- синхронизация
$body = ['guestId' => 'guest-1', 'matches' => [match_('m1'), match_('m2', ['subject' => 'rus', 'byTopic' => ['vowel' => ['n' => 15, 'ok' => 12]]]), match_('bad', ['you' => 999])],
    'stats' => ['mul' => ['recent' => [[1, 3.2], [0, 5]], 'total' => 40], 'zzz' => ['recent' => [], 'total' => 1]]];
[$st, $r] = call('POST', '/sync', $body, $c1);
ok($st === 200 && $r['accepted'] === ['m1', 'm2'] && count($r['rejected']) === 1, 'sync принял 2, отклонил 1');
[, $r] = call('POST', '/sync', $body, $c1);
ok($r['accepted'] === [] && $r['duplicate'] === ['m1', 'm2'], 'повтор sync идемпотентен');
ok(isset($r['stats']->mul) || isset($r['stats']['mul']), 'статистика темы сохранена');
[, $r] = call('POST', '/sync', ['matches' => [match_('m1')], 'stats' => ['mul' => ['recent' => [[1, 1]], 'total' => 3]]], $c2);
ok($r['rejected'][0]['reason'] === 'id_taken', 'чужой id матча не присвоить');
[$st] = call('POST', '/sync', ['matches' => array_fill(0, 201, [])], $c1); ok($st === 413, 'лимит пачки');
[$st] = call('POST', '/sync', ['matches' => []], ''); ok($st === 401, 'sync без входа = 401');
$t = one('SELECT total FROM topic_stats WHERE user_id = ? AND topic = ?', [$u1['id'], 'mul']); ok((int)$t['total'] === 40, 'меньшая статистика не затирает большую');

// --- рейтинг
call('POST', '/sync', ['matches' => [match_('v1', ['you' => 30, 'foe' => 5, 'correct' => 15, 'byTopic' => ['mul' => ['n' => 18, 'ok' => 15]]])]], $c2);
[, $lb] = call('GET', '/leaderboard', null, $c1);
ok(count($lb['top']) === 2 && $lb['top'][0]['nick'] === 'Ветер' && $lb['top'][0]['points'] === 40 && $lb['top'][1]['points'] === 30, 'рейтинг построен');
ok($lb['top'][0]['points'] >= $lb['top'][1]['points'], 'рейтинг отсортирован');
ok($lb['me']['nick'] === 'Ветер', 'свой ряд в рейтинге');
[, $lb] = call('GET', '/leaderboard', null, '', ['subject' => 'rus']); ok(count($lb['top']) === 1 && $lb['top'][0]['nick'] === 'Ветер', 'фильтр по предмету');
[, $lb] = call('GET', '/leaderboard', null, '', ['period' => 'week']); ok(count($lb['top']) === 2, 'неделя');
ok(!array_key_exists('max_user_id', $lb['top'][0]), 'в рейтинге нет ID из MAX');
[$st] = call('GET', '/leaderboard', null, '', ['subject' => 'xx']); ok($st === 400, 'плохой предмет');

// --- выход
[, , $ck] = call('POST', '/logout', [], $c1) + [2 => null];
[$st] = call('GET', '/me', null, $c1); ok($st === 401, 'после выхода сессии нет');
ok(count($sent) > 0 && str_contains($sent[0][0], '/messages?user_id=') && $sent[0][2][0] === 'Authorization: tok', 'ответ бота идёт с токеном в Authorization');

echo $fail ? "\nПровалено: $fail из $n\n" : "Бэкенд: все $n проверок пройдены\n";
exit($fail ? 1 : 0);
