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
ok($first === ['001_init', '002_accounts', '003_duels'] && $again === [], 'миграции: первый запуск применяет все, повторный ничего');
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

// --- онлайн-дуэли
$T = (int)floor(microtime(true) * 1000); $GLOBALS['S2G_NOW_MS'] = $T;
[$ua, $ca] = guest('2.0.0.1'); [$ub, $cb] = guest('2.0.0.2'); [$uc, $cc] = guest('2.0.0.3');
call('POST', '/profile', ['nick' => 'Альфа'], $ca); call('POST', '/profile', ['nick' => 'Бета'], $cb);
$setup = ['subject' => 'math', 'topic' => 'all', 'level' => 'medium', 'sport' => 'football'];
[$st] = call('POST', '/duel/create', $setup); ok($st === 401, 'дуэль без входа = 401');
[$st] = call('POST', '/duel/create', ['subject' => 'x', 'level' => 'medium'], $ca); ok($st === 400, 'дуэль: плохой предмет');
[$st, $d1] = call('POST', '/duel/create', $setup, $ca);
ok($st === 200 && $d1['status'] === 'waiting' && preg_match('/^\d{4}$/', $d1['code']) && $d1['opp'] === null && $d1['startMs'] === null, 'комната создана, ждёт соперника');
[$st] = call('POST', '/duel/join', ['code' => $d1['code']], $ca); ok($st === 400, 'в свою комнату войти нельзя');
[$st, $r] = call('POST', '/duel/join', ['code' => '0000'], $cb); ok($st === 404 && $r['error'] === 'room_not_found', 'нет такой комнаты = 404');
[$st, $j1] = call('POST', '/duel/join', ['code' => ' ' . $d1['code'] . ' ', 'sport' => 'basketball'], $cb);
ok($st === 200 && $j1['status'] === 'playing' && $j1['id'] === $d1['id'] && $j1['seed'] === $d1['seed'] && $j1['startMs'] === $T + S2G\DUEL_LEAD_MS
    && $j1['opp']['name'] === 'Альфа' && $j1['opp']['sport'] === 'football' && $j1['sport'] === 'basketball', 'вход по коду: матч идёт, seed общий, старт через 5 с');
[$st] = call('POST', '/duel/join', ['code' => $d1['code']], $cc); ok($st === 404, 'занятая комната недоступна третьему');
[$st] = call('GET', '/duel/state', null, $cc, ['id' => (string)$d1['id']]); ok($st === 404, 'чужую дуэль третий не видит');
[, $s1] = call('GET', '/duel/state', null, $ca, ['id' => (string)$d1['id']]);
ok($s1['status'] === 'playing' && $s1['opp']['name'] === 'Бета' && $s1['opp']['sport'] === 'basketball' && $s1['seed'] === $d1['seed'], 'создатель видит соперника и тот же seed');
$limited = false; for ($i = 0; $i < 20; $i++) { [$st] = call('POST', '/duel/join', ['code' => '1'], $cc); if ($st === 429) $limited = true; }
ok($limited, 'подбор кодов комнат ограничен');

// до старта очки не принимаются; потом растут, но не быстрее человека
[$st, $r] = call('POST', '/duel/progress', ['id' => $d1['id'], 'pts' => 40], $ca); ok($st === 200 && $r['opp']['pts'] === 0, 'до старта очки не принимаются');
$GLOBALS['S2G_NOW_MS'] = $T + S2G\DUEL_LEAD_MS + 30000;
[$st, $r] = call('POST', '/duel/progress', ['id' => $d1['id'], 'pts' => 'a'], $ca); ok($st === 400, 'очки не числом отклонены');
call('POST', '/duel/progress', ['id' => $d1['id'], 'pts' => 12], $ca);
[, $r] = call('POST', '/duel/progress', ['id' => $d1['id'], 'pts' => 7], $cb); ok($r['opp']['pts'] === 12, 'соперник видит очки создателя');
[, $r] = call('POST', '/duel/progress', ['id' => $d1['id'], 'pts' => 3], $ca); [, $r2] = call('POST', '/duel/progress', ['id' => $d1['id'], 'pts' => 7], $cb);
ok($r2['opp']['pts'] === 12, 'очки не уменьшаются');
[, $r] = call('POST', '/duel/progress', ['id' => $d1['id'], 'pts' => 12, 'fin' => true], $ca); ok($r['status'] === 'playing' && $r['opp']['fin'] === false, 'досрочное «закончил» игнорируется');

// матч заканчивается по цели: итог считает сервер, дуэль попадает в рейтинг онлайн-игр
call('POST', '/duel/progress', ['id' => $d1['id'], 'pts' => 52, 'fin' => true], $ca);
[, $r] = call('POST', '/duel/progress', ['id' => $d1['id'], 'pts' => 20], $cb); ok($r['opp']['pts'] === 52 && $r['status'] === 'playing', 'соперник видит набранную цель');
[, $r] = call('POST', '/duel/progress', ['id' => $d1['id'], 'pts' => 21, 'fin' => true], $cb);
ok($r['status'] === 'done' && $r['result']['outcome'] === 'lose' && $r['result']['you'] === 21 && $r['result']['foe'] === 52 && $r['result']['rated'] === true, 'итог: у проигравшего 21:52');
[, $r] = call('GET', '/duel/state', null, $ca, ['id' => (string)$d1['id']]); ok($r['status'] === 'done' && $r['result']['outcome'] === 'win', 'победитель видит итог');
[, $lb] = call('GET', '/leaderboard', null, $ca, ['mode' => 'duel']);
ok($lb['mode'] === 'duel' && count($lb['top']) === 2 && $lb['top'][0]['name'] === 'Альфа' && $lb['top'][0]['wins'] === 1 && $lb['top'][1]['wins'] === 0 && $lb['me']['rank'] === 1, 'рейтинг дуэлей: по победам');
[, $lbBot] = call('GET', '/leaderboard', null, $ca); ok(count($lbBot['top']) === 3 && $lbBot['mode'] === 'bot', 'обычный рейтинг дуэли не учитывает');
[, $lbb] = call('GET', '/leaderboard', null, $cb, ['mode' => 'duel']); ok($lbb['me']['rank'] === 2 && $lbb['me']['points'] === 21, 'место проигравшего в рейтинге дуэлей');
[, $lbc] = call('GET', '/leaderboard', null, $cc, ['mode' => 'duel']); ok($lbc['me'] === null, 'у третьего игрока в рейтинге дуэлей пусто');

// выход из матча = поражение, победителю засчитывается
$GLOBALS['S2G_NOW_MS'] = $T + 100000;
[, $d2] = call('POST', '/duel/create', $setup, $cb); call('POST', '/duel/join', ['code' => $d2['code']], $ca);
$GLOBALS['S2G_NOW_MS'] = $T + 100000 + S2G\DUEL_LEAD_MS + 20000;
call('POST', '/duel/progress', ['id' => $d2['id'], 'pts' => 9], $ca); call('POST', '/duel/progress', ['id' => $d2['id'], 'pts' => 14], $cb);
[, $r] = call('POST', '/duel/progress', ['id' => $d2['id'], 'quit' => true], $ca);
ok($r['status'] === 'done' && $r['result']['outcome'] === 'lose' && $r['result']['forfeit'] === 'me', 'вышел из матча: поражение');
[, $r] = call('GET', '/duel/state', null, $cb, ['id' => (string)$d2['id']]); ok($r['result']['outcome'] === 'win' && $r['result']['forfeit'] === 'opp', 'соперник видит победу по выходу');
// нет очков у победителя: дуэль не зачитывается
$GLOBALS['S2G_NOW_MS'] = $T + 200000;
[, $d3] = call('POST', '/duel/create', $setup, $ca); call('POST', '/duel/join', ['code' => $d3['code']], $cb);
$GLOBALS['S2G_NOW_MS'] = $T + 200000 + S2G\DUEL_LEAD_MS + 5000;
call('POST', '/duel/progress', ['id' => $d3['id'], 'quit' => true], $cb);
[, $r] = call('GET', '/duel/state', null, $ca, ['id' => (string)$d3['id']]); ok($r['status'] === 'done' && $r['result']['rated'] === false, 'пустая игра в рейтинг не идёт');
$cnt = one('SELECT COUNT(*) c FROM duel_results'); ok((int)$cnt['c'] === 4, 'в рейтинг попали две зачётные дуэли (по строке на игрока)');
// соперник пропал: после «закончил» и тишины в 10 с итог подводится сам
$GLOBALS['S2G_NOW_MS'] = $T + 300000;
[, $d4] = call('POST', '/duel/create', $setup, $ca); call('POST', '/duel/join', ['code' => $d4['code']], $cb);
$GLOBALS['S2G_NOW_MS'] = $T + 300000 + S2G\DUEL_LEAD_MS + 125000;
call('POST', '/duel/progress', ['id' => $d4['id'], 'pts' => 30], $ca); call('POST', '/duel/progress', ['id' => $d4['id'], 'pts' => 25, 'fin' => true], $cb);
[, $r] = call('GET', '/duel/state', null, $cb, ['id' => (string)$d4['id']]); ok($r['status'] === 'playing', 'пока соперник на связи, ждём его');
$GLOBALS['S2G_NOW_MS'] += 11000;
[, $r] = call('GET', '/duel/state', null, $cb, ['id' => (string)$d4['id']]); ok($r['status'] === 'done' && $r['result']['outcome'] === 'lose', 'соперник пропал: итог подведён по его последним очкам');
// очки сверх возможного обрезаются до допустимого
$GLOBALS['S2G_NOW_MS'] = $T + 400000;
[, $dx] = call('POST', '/duel/create', $setup, $ca); call('POST', '/duel/join', ['code' => $dx['code']], $cb);
$GLOBALS['S2G_NOW_MS'] = $T + 400000 + S2G\DUEL_LEAD_MS + 2000;
[$st] = call('POST', '/duel/progress', ['id' => $dx['id'], 'pts' => 300], $ca); [, $r] = call('POST', '/duel/progress', ['id' => $dx['id'], 'pts' => 1], $cb);
ok($st === 200 && $r['opp']['pts'] === 17, 'очки сверх возможного за 2 секунды обрезаются до 17');
// новая комната закрывает старую
$GLOBALS['S2G_NOW_MS'] = $T + 500000;
[, $d5] = call('POST', '/duel/create', $setup, $ca); [, $d6] = call('POST', '/duel/create', $setup, $ca);
$old = one('SELECT status FROM duels WHERE id = ?', [$d5['id']]); ok($old['status'] === 'cancelled', 'новая комната закрывает прежнюю ждущую');
[$st] = call('POST', '/duel/leave', ['id' => $d6['id']], $ca); [, $r] = call('GET', '/duel/state', null, $ca, ['id' => (string)$d6['id']]);
ok($st === 200 && $r['status'] === 'cancelled', 'отмена ожидания закрывает комнату');
// очередь «случайный соперник»
$q = ['subject' => 'math', 'level' => 'medium', 'sport' => 'football'];
[, $r] = call('POST', '/duel/queue', $q, $ca); ok($r['status'] === 'queued', 'очередь: первый ждёт');
[, $r] = call('POST', '/duel/queue', ['subject' => 'math', 'level' => 'hard', 'sport' => 'football'], $cc); ok($r['status'] === 'queued', 'очередь: другой уровень не подходит');
[, $r] = call('POST', '/duel/queue', ['subject' => 'rus', 'level' => 'medium', 'sport' => 'football'], $cc); ok($r['status'] === 'queued', 'очередь: другой предмет не подходит');
[, $r] = call('POST', '/duel/queue', $q, $cb);
ok($r['status'] === 'playing' && $r['opp']['name'] === 'Альфа' && $r['topic'] === 'all', 'очередь: второй сразу получает дуэль');
[, $r2] = call('POST', '/duel/queue', $q, $ca); ok($r2['id'] === $r['id'] && $r2['opp']['name'] === 'Бета' && $r2['seed'] === $r['seed'], 'очередь: первый при следующем опросе получает ту же дуэль');
[, $r3] = call('POST', '/duel/queue', $q, $ca); ok($r3['status'] === 'queued', 'очередь: после получения дуэли нового поиска нет, пока не позвали снова');
call('POST', '/duel/leave', [], $ca); $qn = one('SELECT COUNT(*) c FROM duel_queue WHERE user_id = ?', [$ua['id']]); ok((int)$qn['c'] === 0, 'выход из очереди');
unset($GLOBALS['S2G_NOW_MS']);

// --- выход
call('POST', '/logout', [], $c1);
[$st] = call('GET', '/me', null, $c1); ok($st === 401, 'после выхода сессии нет');

echo $fail ? "\nПровалено: $fail из $n\n" : "Бэкенд: все $n проверок пройдены\n";
exit($fail ? 1 : 0);
