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
ok($first === ['001_init', '002_accounts', '003_duels', '004_duel_stats', '005_progress', '006_league'] && $again === [], 'миграции: первый запуск применяет все, повторный ничего');
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
[$st] = call('POST', '/profile', ['nick' => 'Молния', 'avatar' => '🦁'], $c2); ok($st === 200, 'второй игрок получил ник');
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
$statB = ['correct' => 9, 'wrong' => 3, 'timeouts' => 1, 'run' => 4, 'fast' => 1.46, 'errors' => ["7 × 8 = 56\x07", 'ап_льсин', str_repeat('я', 90), 5, '']];
[, $r] = call('POST', '/duel/progress', ['id' => $d1['id'], 'pts' => 21, 'fin' => true, 'stats' => $statB], $cb);
ok($r['status'] === 'done' && $r['result']['outcome'] === 'lose' && $r['result']['you'] === 21 && $r['result']['foe'] === 52 && $r['result']['rated'] === true, 'итог: у проигравшего 21:52');
[, $r] = call('GET', '/duel/state', null, $ca, ['id' => (string)$d1['id']]); ok($r['status'] === 'done' && $r['result']['outcome'] === 'win', 'победитель видит итог');
$fs = $r['result']['stats']['foe'] ?? null;
ok($fs !== null && $fs['correct'] === 9 && $fs['wrong'] === 3 && $fs['run'] === 4 && $fs['fast'] === 1.5 && count($fs['errors']) === 3 && $fs['errors'][0] === '7 × 8 = 56' && mb_strlen($fs['errors'][2]) === 60, 'статистика соперника доходит до победителя, лишнее вычищено');
ok($r['result']['stats']['you'] === null, 'у того, кто статистику не слал, её нет');
[, $rb] = call('GET', '/duel/state', null, $cb, ['id' => (string)$d1['id']]); ok($rb['result']['stats']['you']['correct'] === 9, 'свою статистику игрок тоже получает');
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

// --- система возвращения: серия, задания дня, опыт, значки, открываемые аватары
use function S2G\{computeProgress, mskDay, questsFor};
function rowMatch(int $uid, string $id, int $playedAt, array $o = []): void {
    $o += ['subject' => 'math', 'outcome' => 'win', 'correct' => 12, 'wrong' => 2, 'timeouts' => 1];
    q('INSERT INTO results (id, user_id, played_at, subject, topic, level, sport, format, you_pts, foe_pts, score, outcome, correct, wrong, timeouts, sec, by_topic, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $uid, $playedAt, $o['subject'], 'all', 'medium', 'football', '1x1', 20, 10, 20, $o['outcome'], $o['correct'], $o['wrong'], $o['timeouts'], 120, '{}', $playedAt]);
}
$NOW = time(); $DAY = 86400;
$free = true; for ($i = 0; $i < 14; $i++) { [$gu] = guest('3.0.1.' . $i); if (array_search($gu['avatar'], S2G\AVATARS, true) >= S2G\FREE_AVATARS) $free = false; }
ok($free, 'новый игрок получает аватар только из открытых сразу');
[$st] = call('GET', '/progress'); ok($st === 401, 'прогресс без входа = 401');
[$up, $cp] = guest('3.0.0.1');
[$st, $pr] = call('GET', '/progress', null, $cp);
ok($st === 200 && $pr['xp'] === 0 && $pr['level'] === 1 && $pr['title'] === 'Новичок' && $pr['streak']['current'] === 0 && count($pr['quests']['items']) === 3 && count($pr['badges']) === 16, 'новый игрок: нулевой прогресс, три задания, значки');
ok(count(array_filter($pr['avatars'], fn($a) => !$a['unlocked'])) === 14 && $pr['nextXp'] === 80, 'закрыто 14 аватаров, до 2 уровня 80 опыта');
[$st, $r] = call('POST', '/profile', ['avatar' => '🐲'], $cp); ok($st === 403 && $r['error'] === 'avatar_locked', 'закрытый аватар выбрать нельзя');
[$st] = call('POST', '/profile', ['avatar' => '🐸'], $cp); ok($st === 200, 'открытый аватар выбирается');
// матч даёт опыт, серию и первый значок
call('POST', '/sync', ['matches' => [match_('p1', ['correct' => 12, 'byTopic' => ['mul' => ['n' => 15, 'ok' => 12]]])]], $cp);
[, $pr] = call('GET', '/progress', null, $cp);
ok($pr['xp'] >= 22 && $pr['streak']['current'] === 1 && $pr['streak']['playedToday'] === true && $pr['totals']['matches'] === 1 && $pr['totals']['wins'] === 1, 'матч: 12 верных + 5 + 5 за победу (не меньше 22 опыта, плюс награда за задание дня), серия 1');
ok(array_values(array_filter($pr['badges'], fn($b) => $b['id'] === 'first'))[0]['unlocked'] === true, 'значок «Первый матч» получен');
// «Контрольная»: принимается, даёт опыт и значки; нереальное отклоняется
$tt = fn(string $id, array $o = []) => $o + ['id' => $id, 't' => ($NOW - 60) * 1000, 'subject' => 'math', 'topic' => 'mul', 'total' => 64, 'correct' => 64, 'wrong' => 0, 'sec' => 70];
[, $r] = call('POST', '/sync', ['tests' => [$tt('t1'), $tt('t2', ['sec' => 20]), $tt('t3', ['correct' => 20, 'wrong' => 10, 'sec' => 100]), $tt('t4', ['correct' => 60, 'wrong' => 20])]], $cp);
ok($r['accepted'] === ['t1'] && count($r['rejected']) === 3 && $r['rejected'][0]['reason'] === 'too_fast' && $r['rejected'][1]['reason'] === 'ended_early' && $r['rejected'][2]['reason'] === 'too_many_answers', 'контрольная: принята верная, нереальные отклонены');
[, $r] = call('POST', '/sync', ['tests' => [$tt('t1')]], $cp); ok($r['duplicate'] === ['t1'], 'контрольная: повтор не удваивает');
[, $pr] = call('GET', '/progress', null, $cp);
$bid = fn(string $id) => array_values(array_filter($pr['badges'], fn($b) => $b['id'] === $id))[0]['unlocked'];
ok($pr['xp'] >= 22 + 64 + 10 + 20 && $bid('test5') && $bid('clean') && $pr['totals']['tests'] === 1 && $pr['totals']['correct'] === 76, 'контрольная на «5» без ошибок: опыт и значки');
[$st, $r] = call('POST', '/sync', ['tests' => 'x'], $cp); ok($st === 400, 'контрольные не списком отклонены');

// серия дней: подряд, пропуск, «заморозка»
[$us, $cs] = guest('3.0.0.2'); $sid = (int)$us['id']; $today = mskDay($NOW);
foreach ([2, 1, 0] as $k) rowMatch($sid, "s$k", $NOW - $k * $DAY);
$p = computeProgress($sid, $NOW);
ok($p['streak']['current'] === 3 && $p['streak']['best'] === 3 && $p['streak']['playedToday'] === true, 'серия 3 дня подряд');
$p = computeProgress($sid, $NOW);
ok(count($p['week']['days']) === 7 && $p['week']['today'] >= 0 && $p['week']['today'] <= 6 && $p['week']['days'][$p['week']['today']] === 'done' && $p['dayLeft'] > 0 && $p['dayLeft'] <= 86400, 'неделя для праздника серии: сегодня отмечено, есть время до конца дня');
$p = computeProgress($sid, $NOW + $DAY); ok($p['streak']['current'] === 3 && $p['streak']['playedToday'] === false, 'вчера играл, сегодня ещё нет: серия жива');
$p = computeProgress($sid, $NOW + 2 * $DAY); ok($p['streak']['current'] === 0 && $p['streak']['best'] === 3, 'два дня без игры: серия сгорела, рекорд остался');
ok(array_values(array_filter($p['avatars'], fn($a) => $a['a'] === '⭐'))[0]['unlocked'] === true && array_values(array_filter($p['avatars'], fn($a) => $a['a'] === '🔥'))[0]['unlocked'] === false, 'аватар «⭐» открыт серией 3, «🔥» (7 дней) ещё закрыт');
[$st] = call('POST', '/profile', ['avatar' => '⭐'], $cs); ok($st === 200, 'открытый наградой аватар выбирается');
[$uf, $cf] = guest('3.0.0.3'); $fid = (int)$uf['id'];
foreach ([8, 7, 6, 5, 4, 3, 2] as $k) rowMatch($fid, "f$k", $NOW - $k * $DAY);   // 7 дней подряд: «заморозка», потом пропуск одного дня
rowMatch($fid, 'f0', $NOW);
$p = computeProgress($fid, $NOW);
ok($p['streak']['current'] === 8 && $p['streak']['best'] === 8 && $p['streak']['freezes'] === 0, '7 дней подряд дали «заморозку», она закрыла пропущенный день: серия 8');
$gap = computeProgress($fid, $NOW + 2 * $DAY); ok($gap['streak']['current'] === 0, 'без заморозки пропуск ломает серию');

// задания дня и бонус за все три
[$uq, $cq] = guest('3.0.0.4'); $qid = (int)$uq['id'];
rowMatch($qid, 'q1', $NOW - 100, ['subject' => 'math', 'correct' => 50, 'wrong' => 2, 'timeouts' => 0]);
rowMatch($qid, 'q2', $NOW - 90, ['subject' => 'rus', 'correct' => 50, 'wrong' => 2, 'timeouts' => 0]);
q('INSERT INTO test_results (id, user_id, played_at, created_at, subject, topic, total, correct, wrong, sec) VALUES (?,?,?,?,?,?,?,?,?,?)', ['qt1', $qid, $NOW - 80, $NOW - 80, 'math', 'mul', 64, 64, 0, 100]);
$p = computeProgress($qid, $NOW);
$picked = questsFor($qid, $today); $expect = 2 * (50 + 5 + 5) + (64 + 10 + 20) + S2G\QUEST_DAY_BONUS; foreach ($picked as $id) $expect += S2G\QUESTS[$id][3];
ok($p['quests']['allDone'] === true && $p['quests']['done'] === 3 && $p['xp'] === $expect && $picked === questsFor($qid, $today), 'три задания дня выполнены, опыт с наградами и бонусом, набор заданий стабилен');
ok(computeProgress($qid, $NOW + $DAY)['quests']['done'] === 0 && count(computeProgress($qid, $NOW + $DAY)['quests']['items']) === 3, 'на следующий день новые задания');
ok(computeProgress($qid, $NOW)['level'] === S2G\levelOf($expect) && S2G\levelOf(79) === 1 && S2G\levelOf(80) === 2 && S2G\levelOf(240) === 3, 'уровни: 80 опыта = 2, 240 = 3');
// офлайн-матч, присланный через неделю, серию не рисует задним числом
[$uo] = guest('3.0.0.5'); $oid = (int)$uo['id'];
q('INSERT INTO results (id, user_id, played_at, subject, topic, level, sport, format, you_pts, foe_pts, score, outcome, correct, wrong, timeouts, sec, by_topic, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    ['o1', $oid, $NOW - 7 * $DAY, 'math', 'all', 'medium', 'football', '1x1', 20, 10, 20, 'win', 12, 2, 1, 120, '{}', $NOW]);
$p = computeProgress($oid, $NOW); ok($p['streak']['current'] === 1 && $p['streak']['best'] === 1 && $p['xp'] >= 22, 'старый матч, присланный позже двух суток, считается днём получения');
[$st, $p2] = call('GET', '/progress', null, $cp); ok(!array_key_exists('recovery_hash', $p2) && !array_key_exists('id', $p2), 'в прогрессе нет служебных полей');

// --- лига недели, кубки, рамки, награда за возвращение
use function S2G\{weekOf, weekStartTs};
$lg = fn(string $cookie, int $now) => S2G\league(['cookie' => $cookie, 'now' => $now])[1];
$wk = weekOf($NOW); $ws0 = (new DateTimeImmutable('@' . weekStartTs($wk)))->setTimezone(new DateTimeZone('Europe/Moscow'));
ok($ws0->format('N H:i') === '1 00:00' && weekOf(weekStartTs($wk)) === $wk && weekOf(weekStartTs($wk) - 1) === $wk - 1 && weekStartTs($wk) === S2G\weekStart($NOW), 'неделя начинается с понедельника, 00:00 по Москве, как в недельном рейтинге');
[$st] = call('GET', '/league'); ok($st === 401, 'лига без входа = 401');
[$ul1, $cl1] = guest('3.0.2.1'); [$ul2, $cl2] = guest('3.0.2.2');
[$st, $L] = call('GET', '/league', null, $cl1);
ok($st === 200 && $L['league'] === 1 && $L['name'] === 'Бронзовая лига' && $L['size'] >= 8 && $L['rank'] !== null && count($L['list']) === $L['size'] && $L['secondsLeft'] > 0 && $L['secondsLeft'] <= 7 * $DAY, 'лига: новичок в бронзовой, группа дополнена ботами');
ok(count(array_filter($L['list'], fn($e) => $e['bot'])) >= 6 && count(array_filter($L['list'], fn($e) => !empty($e['me']))) === 1, 'боты помечены, свой ряд один');
call('GET', '/league', null, $cl2);
$members = one('SELECT COUNT(*) c FROM league_members WHERE week = ? AND league = 1 AND grp = 1', [weekOf($NOW)]); ok((int)$members['c'] >= 2, 'второй игрок попал в ту же группу');
rowMatch((int)$ul1['id'], 'lg1', $NOW - 30, ['correct' => 40]);
$L = $lg($cl1, $NOW); ok($L['xp'] >= 50, 'опыт недели растёт от матчей');
// итоги недели: победитель поднимается, выигрыш недели даёт кубок и рамку
[$uw, $cw] = guest('3.0.2.3'); $wid = (int)$uw['id'];
$prevNow = $NOW - 7 * $DAY;
$lg($cw, $prevNow);
rowMatch($wid, 'lw1', $prevNow + 60, ['correct' => 900]);
$wWeek = weekOf($prevNow);
[$uz, $cz] = guest('3.0.2.4'); $zid = (int)$uz['id'];
q('INSERT INTO league_members (week, user_id, league, grp) VALUES (?, ?, 2, 1)', [$wWeek, $zid]);   // в серебряной лиге без единого очка
$L = $lg($cw, $NOW);
$row = one('SELECT final_rank, result FROM league_members WHERE week = ? AND user_id = ?', [$wWeek, $wid]);
ok((int)$row['final_rank'] === 1 && $row['result'] === 'up' && $L['league'] === 2 && $L['name'] === 'Серебряная лига', 'победитель недели поднялся в серебряную лигу');
ok($L['last'] !== null && $L['last']['rank'] === 1 && $L['last']['result'] === 'up' && $L['last']['league'] === 1 && $L['trophies']['gold'] === 1, 'итог прошлой недели и золотой кубок');
$Lz = $lg($cz, $NOW); $rz = one('SELECT final_rank, result FROM league_members WHERE week = ? AND user_id = ?', [$wWeek, $zid]);
ok($rz['result'] === 'down' && $Lz['league'] === 1, 'без очков в серебряной лиге: спуск в бронзовую');
$again = one('SELECT final_rank FROM league_members WHERE week = ? AND user_id = ?', [$wWeek, $wid]); $lg($cw, $NOW); ok((int)$again['final_rank'] === 1, 'повторный вызов итоги не меняет');
[, $prW] = call('GET', '/progress', null, $cw);
$fr = fn(string $id) => array_values(array_filter($prW['frames'], fn($f) => $f['id'] === $id))[0]['unlocked'];
ok($prW['trophies']['gold'] === 1 && $fr('crown') && $fr('silver') && !$fr('gold') && count($prW['frames']) === 10, 'кубок и рамки: чемпионская и серебряная открыты, золотая нет');
[$st, $r] = call('POST', '/profile', ['frame' => 'gold'], $cw); ok($st === 403 && $r['error'] === 'frame_locked', 'закрытую рамку выбрать нельзя');
[$st, $r] = call('POST', '/profile', ['frame' => 'silver'], $cw); ok($st === 200 && $r['user']['frame'] === 'silver', 'открытую рамку можно выбрать');
[$st, $r] = call('POST', '/profile', ['frame' => 'нет'], $cw); ok($st === 403, 'неизвестная рамка отклонена');
[, $r] = call('GET', '/me', null, $cw); ok($r['user']['frame'] === 'silver', 'рамка видна в профиле');
[$st, $r] = call('POST', '/profile', ['frame' => ''], $cw); ok($st === 200 && $r['user']['frame'] === '', 'рамку можно снять');
call('POST', '/profile', ['frame' => 'silver'], $cw);
$Lx = $lg($cw, $NOW); ok(count(array_filter($Lx['list'], fn($e) => !empty($e['me']) && $e['frame'] === 'silver')) === 1, 'рамка видна в таблице лиги');
// награда за возвращение
[$ub, $cb2] = guest('3.0.2.5'); $bid2 = (int)$ub['id'];
rowMatch($bid2, 'b1', $NOW - 5 * $DAY);
$p = computeProgress($bid2, $NOW);
ok($p['comeback']['ready'] === true && $p['comeback']['today'] === false && $p['comeback']['xp'] === 50 && $p['comeback']['missed'] === 4, 'после перерыва игра предлагает бонус за возвращение');
rowMatch($bid2, 'b2', $NOW - 10);
$p2 = computeProgress($bid2, $NOW);
ok($p2['comeback']['today'] === true && $p2['comeback']['ready'] === false && $p2['comeback']['xp'] === 50 && $p2['xp'] >= $p['xp'] + 22 + 50, 'вернулся и сыграл: бонус +50 к опыту');
[$ul, $cl] = guest('3.0.2.6'); $lid = (int)$ul['id'];
rowMatch($lid, 'c1', $NOW - 20 * $DAY); rowMatch($lid, 'c2', $NOW - 10);
ok(computeProgress($lid, $NOW)['comeback']['xp'] === 100, 'после двух недель перерыва бонус +100');
[$un, $cn] = guest('3.0.2.7'); rowMatch((int)$un['id'], 'n1', $NOW - 2 * $DAY);
ok(computeProgress((int)$un['id'], $NOW)['comeback']['ready'] === false, 'пропуск в один день бонусом не считается');

// --- приложение (Android): токен вместо cookie и CORS
[$st, $r, $ck] = call('POST', '/auth/guest', ['native' => true], '', [], '4.0.0.1') + [2 => null];
ok($st === 200 && isset($r['token']) && $r['token'] === $ck['value'] && strlen($r['token']) === 64, 'приложению токен выдаётся в ответе');
[$st, $r2] = call('POST', '/auth/guest', [], '', [], '4.0.0.2'); ok($st === 200 && !isset($r2['token']), 'сайту токен в ответе не нужен (только cookie)');
$viaToken = dispatch(['method' => 'GET', 'route' => '/me', 'query' => [], 'body' => null, 'cookie' => '', 'token' => $r['token'], 'ip' => '4.0.0.1', 'jsonType' => false]);
ok($viaToken[0] === 200 && $viaToken[1]['user']['id'] === $r['user']['id'], 'запрос с токеном вместо cookie проходит');
$bad = dispatch(['method' => 'GET', 'route' => '/me', 'query' => [], 'body' => null, 'cookie' => '', 'token' => str_repeat('a', 64), 'ip' => '4.0.0.1', 'jsonType' => false]); ok($bad[0] === 401, 'чужой токен не подходит');
[, $rr] = call('POST', '/auth/recover', ['code' => $r['recoveryCode'], 'native' => true], '', [], '4.0.0.3'); ok(isset($rr['token']) && $rr['token'] !== $r['token'], 'вход по коду в приложении тоже даёт токен');
$cors = S2G\corsHeaders('https://localhost');
ok(($cors['Access-Control-Allow-Origin'] ?? '') === 'https://localhost' && str_contains($cors['Access-Control-Allow-Headers'], 'X-S2G-Token') && S2G\corsHeaders('https://evil.example') === [] && S2G\corsHeaders('') === [], 'CORS: свой источник разрешён, чужой и пустой нет');

// --- удаление профиля
[$ud, $cd] = guest('4.0.1.1'); $did = (int)$ud['id'];
call('POST', '/sync', ['matches' => [match_('del1')], 'tests' => []], $cd);
rowMatch($did, 'del2', $NOW - 100); q('INSERT INTO league_members (week, user_id, league, grp) VALUES (?, ?, 1, 1)', [weekOf($NOW), $did]);
[$st, $r] = call('POST', '/account/delete', [], $cd); ok($st === 400 && $r['error'] === 'confirm_required', 'удаление профиля требует подтверждения');
[$st] = call('POST', '/account/delete', ['confirm' => true]); ok($st === 401, 'удаление без входа = 401');
[$st] = call('POST', '/account/delete', ['confirm' => true], $cd); ok($st === 200, 'профиль удалён');
$left = 0; foreach (['users' => 'id', 'sessions' => 'user_id', 'results' => 'user_id', 'topic_stats' => 'user_id', 'league_members' => 'user_id', 'guests' => 'user_id'] as $tb => $col) $left += (int)one("SELECT COUNT(*) c FROM $tb WHERE $col = ?", [$did])['c'];
ok($left === 0, 'после удаления в базе не осталось ни профиля, ни результатов, ни сессий');
[$st] = call('GET', '/me', null, $cd); ok($st === 401, 'после удаления старая сессия не действует');
[, $lbd] = call('GET', '/leaderboard', null, ''); ok(!in_array('Неопознанный №' . $did, array_column($lbd['top'], 'name'), true), 'удалённого игрока нет в рейтинге');

// --- выход
call('POST', '/logout', [], $c1);
[$st] = call('GET', '/me', null, $c1); ok($st === 401, 'после выхода сессии нет');

echo $fail ? "\nПровалено: $fail из $n\n" : "Бэкенд: все $n проверок пройдены\n";
exit($fail ? 1 : 0);
