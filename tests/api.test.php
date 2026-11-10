<?php
// php tests/api.test.php — проверка бэкенда на SQLite в памяти, без сети
declare(strict_types=1);
putenv('DB_DRIVER=sqlite'); putenv('DB_PATH=:memory:'); putenv('S2G_ENV_FILE=/nonexistent');
require __DIR__ . '/../server/routes.php';
use function S2G\{dispatch, migrate, q, one};

$fail = 0; $n = 0;
function ok(bool $c, string $name): void { global $fail, $n; $n++; if (!$c) { $fail++; echo "ПРОВАЛ: $name\n"; } }

// --- миграции
$first = migrate(); $again = migrate();
ok($first === ['001_init', '002_accounts'] && $again === [], 'миграции: первый запуск применяет обе, повторный ничего');
$tmp = sys_get_temp_dir() . '/s2g-mig-' . getmypid(); mkdir($tmp);
foreach (['001_init', '002_accounts'] as $f) copy(__DIR__ . "/../server/migrations/$f.php", "$tmp/$f.php");
file_put_contents("$tmp/003_extra.php", '<?php return function ($db, $d) { $db->exec("CREATE TABLE IF NOT EXISTS extra_t (id INT)"); };');
ok(migrate($tmp) === ['003_extra'], 'новый файл миграции применён сразу');
ok(migrate($tmp) === [], 'без новых файлов миграция ничего не делает');
file_put_contents("$tmp/004_bad.php", '<?php return function ($db, $d) { $db->exec("NOT SQL"); };');
$threw = false; try { migrate($tmp); } catch (\Throwable $e) { $threw = true; }
ok($threw && !in_array('004_bad', S2G\appliedMigrations(), true), 'сбойная миграция не помечается применённой');
array_map('unlink', glob("$tmp/*.php")); rmdir($tmp);
ok(S2G\prepare() === null, 'prepare() проходит');

function call(string $m, string $route, ?array $body = null, string $cookie = '', array $query = [], string $ip = '1.2.3.4', bool $json = true): array {
    return dispatch(['method' => $m, 'route' => $route, 'query' => $query, 'body' => $body, 'cookie' => $cookie, 'ip' => $ip, 'jsonType' => $json]);
}
/** Новый анонимный игрок: [пользователь, cookie, код восстановления] */
function guest(string $ip = '1.2.3.4'): array {
    [$st, $r, $ck] = call('POST', '/auth/guest', [], '', [], $ip) + [2 => null];
    ok($st === 200 && $ck && strlen($ck['value']) === 64, 'гость создан');
    return [$r['user'], $ck['value'], $r['recoveryCode']];
}
function match_(string $id, array $o = []): array {
    return $o + ['id' => $id, 't' => (time() - 60) * 1000, 'subject' => 'math', 'topic' => 'all', 'level' => 'medium', 'sport' => 'football', 'format' => '1x1',
        'you' => 20, 'foe' => 10, 'outcome' => 'win', 'correct' => 12, 'wrong' => 2, 'timeouts' => 1, 'sec' => 120, 'byTopic' => ['mul' => ['n' => 15, 'ok' => 12]]];
}

// --- разбор пути
foreach (['/school2game/api/health' => '/health', '/school2game/public_html/api/auth/guest' => '/auth/guest', '/api/leaderboard' => '/leaderboard',
          '/school2game/public_html/api/' => '/', '/school2game/public_html/api/index.php' => '/', '/api/profile/' => '/profile'] as $path => $route) {
    ok(S2G\routeFromPath($path) === $route, "путь $path → $route (получили " . S2G\routeFromPath($path) . ')');
}

// --- анонимные аккаунты
[$st] = call('POST', '/auth/guest', null, '', [], '1.2.3.4', false); ok($st === 415, 'POST без JSON отклонён (CSRF)');
[$st] = call('GET', '/me'); ok($st === 401, '/me без входа = 401');
[$st, $r] = call('GET', '/config'); ok($st === 200 && $r['accounts'] === true && count($r['avatars']) === 24 && !isset($r['login']), 'config: аккаунты есть, без упоминаний сторонних сервисов');
[$u1, $c1, $code1] = guest();
ok($u1['nick'] === null && $u1['needsNick'] === true && str_starts_with($u1['name'], 'Неопознанный №') && in_array($u1['avatar'], S2G\AVATARS, true), 'новый игрок: неопознанный со случайным аватаром');
ok((bool)preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code1), 'код восстановления нужного вида');
[$st, $r] = call('GET', '/me', null, $c1); ok($st === 200 && $r['user']['id'] === $u1['id'], '/me по cookie');
$h = one('SELECT recovery_hash FROM users WHERE id = ?', [$u1['id']]); ok($h['recovery_hash'] !== $code1 && strlen($h['recovery_hash']) === 64, 'код хранится только хэшем');

[$st, $r, $ck] = call('POST', '/auth/recover', ['code' => strtolower(str_replace('-', ' ', $code1))]) + [2 => null];
ok($st === 200 && $r['user']['id'] === $u1['id'] && $ck && $ck['value'] !== $c1, 'вход по коду (регистр и разделители не важны)');
[$st, $r] = call('POST', '/auth/recover', ['code' => 'AAAA-AAAA-AAAA']); ok($st === 404 && $r['error'] === 'bad_code', 'неверный код = 404');
[$st] = call('POST', '/auth/recover', ['code' => 'коротко']); ok($st === 400, 'код неверного вида = 400');
$limited = false; for ($i = 0; $i < 20; $i++) { [$st] = call('POST', '/auth/recover', ['code' => 'BBBB-BBBB-BBBB'], '', [], '9.9.9.9'); if ($st === 429) $limited = true; }
ok($limited, 'перебор кодов ограничен');
$okAll = true; for ($i = 0; $i < 25; $i++) { [$st] = call('POST', '/auth/recover', ['code' => $code1], '', [], '8.8.8.8'); if ($st !== 200) $okAll = false; }
ok($okAll, 'удачные входы по коду лимит не расходуют');
[$st, $r] = call('POST', '/auth/newcode', [], $c1); $code1b = $r['recoveryCode'] ?? '';
ok($st === 200 && $code1b !== $code1 && $code1b !== '', 'новый код выдан');
[$st] = call('POST', '/auth/recover', ['code' => $code1], '', [], '5.5.5.5'); ok($st === 404, 'старый код больше не работает');
[$st] = call('POST', '/auth/recover', ['code' => $code1b], '', [], '5.5.5.5'); ok($st === 200, 'новый код работает');
[$st] = call('POST', '/auth/newcode', []); ok($st === 401, 'newcode без входа = 401');
$blocked = false; for ($i = 0; $i < S2G\GUEST_PER_DAY + 2; $i++) { [$st] = call('POST', '/auth/guest', [], '', [], '7.7.7.7'); if ($st === 429) { $blocked = true; break; } }
ok($blocked, 'создание аккаунтов с одного адреса ограничено');

// --- профиль: аватар без ника, ник, фильтр, смена
[$st, $r] = call('POST', '/profile', ['avatar' => '🐸'], $c1); ok($st === 200 && $r['user']['avatar'] === '🐸' && $r['user']['nick'] === null, 'аватар меняется без ника');
[$st] = call('POST', '/profile', ['avatar' => 'x'], $c1); ok($st === 400, 'аватар не из списка отклонён');
[$st] = call('POST', '/profile', ['nick' => '<b>x</b>'], $c1); ok($st === 400, 'ник с тегами отклонён');
[$st, $r] = call('POST', '/profile', ['nick' => 'х у й'], $c1); ok($st === 422 && $r['error'] === 'nick_not_allowed', 'мат в нике отклонён');
[$st] = call('POST', '/profile', ['nick' => 'Админ'], $c1); ok($st === 422, 'служебное имя отклонено');
[$st, $r] = call('POST', '/profile', ['nick' => 'Ветер', 'avatar' => '⚽'], $c1); ok($st === 200 && $r['user']['nick'] === 'Ветер' && $r['user']['name'] === 'Ветер' && !$r['user']['needsNick'], 'ник сохранён');
[$u2, $c2] = guest('1.2.3.5');
[$st, $r] = call('POST', '/profile', ['nick' => 'ветер'], $c2); ok($st === 409 && $r['error'] === 'nick_taken', 'ник занят без учёта регистра');
[$st] = call('POST', '/profile', ['nick' => 'Молния', 'avatar' => '🔥'], $c2); ok($st === 200, 'второй игрок получил ник');
[$st, $r] = call('POST', '/profile', ['nick' => 'Ветрище'], $c1); ok($st === 200 && $r['user']['nick'] === 'Ветрище', 'ник можно сменить');
call('POST', '/profile', ['nick' => 'Ветер'], $c1);
[$st, $r] = call('GET', '/me', null, $c1); ok($st === 200 && $r['user']['nick'] === 'Ветер', '/me показывает ник');

// --- проверка матчей
$now = time();
foreach ([
    'нереальные очки' => [match_('a1', ['you' => 49, 'correct' => 3, 'byTopic' => ['mul' => ['n' => 6, 'ok' => 3]], 'wrong' => 2, 'timeouts' => 1]), 'too_many_points'],
    'слишком быстро' => [match_('a2', ['correct' => 300, 'byTopic' => ['mul' => ['n' => 300, 'ok' => 300]], 'you' => 40, 'wrong' => 0, 'timeouts' => 0]), 'too_fast'],
    'исход не сходится' => [match_('a3', ['outcome' => 'lose']), 'outcome_mismatch'],
    'темы не сходятся' => [match_('a4', ['byTopic' => ['mul' => ['n' => 15, 'ok' => 14]]]), 'topics_mismatch'],
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
[$row] = S2G\checkMatch(match_('retry', ['byTopic' => ['mul' => ['n' => 14, 'ok' => 11]]]), $now);
ok($row !== null, 'верный ответ после ошибки на том же вопросе не ломает проверку тем');
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

// --- рейтинг: неопознанные игроки тоже в таблице
call('POST', '/sync', ['matches' => [match_('v1', ['you' => 30, 'foe' => 5, 'correct' => 15, 'byTopic' => ['mul' => ['n' => 18, 'ok' => 15]]])]], $c2);
[$u3, $c3] = guest('1.2.3.6');
call('POST', '/sync', ['matches' => [match_('v2', ['you' => 21, 'correct' => 11, 'byTopic' => ['mul' => ['n' => 14, 'ok' => 11]]])]], $c3);
[, $lb] = call('GET', '/leaderboard', null, $c1);
ok(count($lb['top']) === 3 && $lb['top'][0]['name'] === 'Ветер' && $lb['top'][0]['points'] === 40 && $lb['top'][1]['points'] === 30, 'рейтинг построен');
ok($lb['top'][2]['anon'] === true && $lb['top'][2]['name'] === 'Неопознанный №' . $u3['id'] && $lb['top'][2]['avatar'] === $u3['avatar'], 'неопознанный игрок в рейтинге с аватаром');
ok($lb['me']['name'] === 'Ветер' && $lb['me']['rank'] === 1, 'свой ряд в рейтинге');
[, $lb3] = call('GET', '/leaderboard', null, $c3); ok($lb3['me']['anon'] === true && $lb3['me']['rank'] === 3, 'неопознанный видит своё место');
[, $lb] = call('GET', '/leaderboard', null, '', ['subject' => 'rus']); ok(count($lb['top']) === 1 && $lb['top'][0]['name'] === 'Ветер', 'фильтр по предмету');
[, $lb] = call('GET', '/leaderboard', null, '', ['period' => 'week']); ok(count($lb['top']) === 3, 'неделя');
ok(!array_key_exists('id', $lb['top'][0]) && !array_key_exists('recovery_hash', $lb['top'][0]), 'в рейтинге нет служебных полей');
[$st] = call('GET', '/leaderboard', null, '', ['subject' => 'xx']); ok($st === 400, 'плохой предмет');

// --- выход
call('POST', '/logout', [], $c1);
[$st] = call('GET', '/me', null, $c1); ok($st === 401, 'после выхода сессии нет');

echo $fail ? "\nПровалено: $fail из $n\n" : "Бэкенд: все $n проверок пройдены\n";
exit($fail ? 1 : 0);
