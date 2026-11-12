<?php
declare(strict_types=1);
namespace S2G;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/profanity.php';
require_once __DIR__ . '/duel.php';

const COOKIE = 's2g_sess';
const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const CODE_LEN = 12;
const MAX_BATCH = 200;
const GUEST_PER_DAY = 200; // с одного адреса: в школе у многих детей общий IP

/** Ограничитель частоты: true, если запрос разрешён. Окно фиксированное. */
function rateLimit(string $key, int $max, int $window): bool {
    $now = time();
    $row = one('SELECT hits, win_start FROM rate_limits WHERE k = ?', [$key]);
    if (!$row || (int)$row['win_start'] + $window <= $now) {
        q('DELETE FROM rate_limits WHERE k = ?', [$key]);
        insertIgnore('rate_limits', ['k' => $key, 'hits' => 1, 'win_start' => $now]);
        return true;
    }
    if ((int)$row['hits'] >= $max) return false;
    q('UPDATE rate_limits SET hits = hits + 1 WHERE k = ?', [$key]);
    return true;
}
/** Только проверить, не исчерпан ли лимит (ничего не записывает) */
function rateBlocked(string $key, int $max, int $window): bool {
    $row = one('SELECT hits, win_start FROM rate_limits WHERE k = ?', [$key]);
    return $row && (int)$row['win_start'] + $window > time() && (int)$row['hits'] >= $max;
}
/** Записать одну попытку (для неудачных входов) */
function rateHit(string $key, int $window): void { rateLimit($key, PHP_INT_MAX, $window); }
/** Настоящий адрес посетителя: за прокси (nginx) REMOTE_ADDR у всех одинаковый, берём X-Real-IP / X-Forwarded-For */
function clientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $private = $ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($private) {
        foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
            $v = trim(explode(',', $_SERVER[$h] ?? '')[0]);
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP)) return $v;
        }
    }
    return $ip;
}
function isHttps(): bool {
    return (($_SERVER['HTTPS'] ?? '') !== '' && strtolower($_SERVER['HTTPS']) !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || strtolower($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on';
}
function ipKey(array $req): string { return substr(hash('sha256', 'school2game|' . ($req['ip'] ?? '')), 0, 16); }

/* ---------- пользователи и сессии ---------- */
/** Имя в рейтинге: ник или «Неопознанный №N», пока игрок не вписал свои данные */
function displayName(array $u): string { return $u['nick'] !== null ? (string)$u['nick'] : 'Неопознанный №' . (int)$u['id']; }
function userView(array $u): array {
    return ['id' => (int)$u['id'], 'nick' => $u['nick'], 'name' => displayName($u), 'avatar' => $u['avatar'], 'needsNick' => $u['nick'] === null];
}
function sessionUser(array $req): ?array {
    $tok = $req['cookie'] ?? '';
    if (!is_string($tok) || !preg_match('/^[a-f0-9]{64}$/', $tok)) return null;
    $row = one('SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? AND s.expires_at > ?', [hashToken($tok), time()]);
    if ($row) q('UPDATE users SET last_seen = ? WHERE id = ?', [time(), $row['id']]);
    return $row;
}
function requireUser(array $req): array { return sessionUser($req) ?? fail(401, 'not_logged_in'); }

/* ---------- аккаунты: анонимный игрок + код восстановления ---------- */
function newRecoveryCode(): string {
    $c = '';
    for ($i = 0; $i < CODE_LEN; $i++) $c .= CODE_ALPHABET[random_int(0, strlen(CODE_ALPHABET) - 1)];
    return implode('-', str_split($c, 4));
}
function normalizeCode(mixed $code): ?string {
    if (!is_string($code)) return null;
    $c = strtr(strtoupper($code), ['О' => 'O', 'І' => 'I']);
    $c = preg_replace('/[^' . CODE_ALPHABET . ']/', '', $c) ?? '';
    return strlen($c) === CODE_LEN ? $c : null;
}
function startSession(int $uid): array {
    $tok = bin2hex(random_bytes(32));
    $days = max(1, (int)env('SESSION_DAYS', '365'));
    $exp = time() + $days * 86400;
    q('INSERT INTO sessions (token_hash, user_id, expires_at) VALUES (?,?,?)', [hashToken($tok), $uid, $exp]);
    return ['value' => $tok, 'expires' => $exp];
}

/** Новый «неопознанный» игрок: случайный аватар, без ника. Возвращает пользователя, код (виден один раз) и сессию. */
function authGuest(array $req): array {
    if (!rateLimit('guest:' . ipKey($req), GUEST_PER_DAY, 86400)) fail(429, 'too_many_requests');
    q('DELETE FROM rate_limits WHERE win_start < ?', [time() - 2 * 86400]);
    q('DELETE FROM sessions WHERE expires_at < ?', [time()]);
    $now = time();
    $code = newRecoveryCode();
    q('INSERT INTO users (nick, nick_key, avatar, recovery_hash, created_at, last_seen) VALUES (NULL, NULL, ?, ?, ?, ?)',
        [AVATARS[array_rand(AVATARS)], hashToken((string)normalizeCode($code)), $now, $now]);
    $uid = (int)db()->lastInsertId();
    $user = one('SELECT * FROM users WHERE id = ?', [$uid]);
    return [200, ['user' => userView($user), 'recoveryCode' => $code], startSession($uid)];
}

/** Вход по коду восстановления (другое устройство, потеря cookie) */
function authRecover(array $req): array {
    $rk = 'recover:' . ipKey($req);
    if (rateBlocked($rk, 15, 600)) fail(429, 'too_many_requests');   // считаем только неудачные попытки
    $code = normalizeCode($req['body']['code'] ?? null);
    $user = $code === null ? null : one('SELECT * FROM users WHERE recovery_hash = ?', [hashToken($code)]);
    if (!$user) { rateHit($rk, 600); fail($code === null ? 400 : 404, 'bad_code'); }
    return [200, ['user' => userView($user)], startSession((int)$user['id'])];
}

/** Новый код вместо старого (старый перестаёт работать) */
function newCode(array $req): array {
    $user = requireUser($req);
    $code = newRecoveryCode();
    q('UPDATE users SET recovery_hash = ? WHERE id = ?', [hashToken((string)normalizeCode($code)), $user['id']]);
    return [200, ['recoveryCode' => $code]];
}

function health(array $req): array {
    return [200, ['ok' => true, 'db' => true]];
}

function me(array $req): array { return [200, ['user' => userView(requireUser($req))]]; }

function logout(array $req): array {
    $tok = $req['cookie'] ?? '';
    if (is_string($tok) && $tok !== '') q('DELETE FROM sessions WHERE token_hash = ?', [hashToken($tok)]);
    return [200, ['ok' => true], ['value' => '', 'expires' => 1]];
}

function profile(array $req): array {
    $user = requireUser($req);
    $b = $req['body'] ?? [];
    $nick = $user['nick']; $nickKey = $user['nick_key']; $avatar = $user['avatar'];
    if (array_key_exists('nick', $b) && $b['nick'] !== null && $b['nick'] !== '') {
        $n = cleanNick($b['nick']);
        if ($n === null) fail(400, 'bad_nick');
        if (nickNotAllowed($n)) fail(422, 'nick_not_allowed');
        $taken = one('SELECT id FROM users WHERE nick_key = ? AND id <> ?', [mb_strtolower($n), $user['id']]);
        if ($taken) fail(409, 'nick_taken');
        $nick = $n; $nickKey = mb_strtolower($n);
    }
    if (isset($b['avatar'])) {
        if (!in_array($b['avatar'], AVATARS, true)) fail(400, 'bad_avatar');
        $avatar = $b['avatar'];
    }
    try {
        q('UPDATE users SET nick = ?, nick_key = ?, avatar = ? WHERE id = ?', [$nick, $nickKey, $avatar, $user['id']]);
    } catch (\PDOException $e) {
        fail(409, 'nick_taken');
    }
    return [200, ['user' => userView(one('SELECT * FROM users WHERE id = ?', [$user['id']]))]];
}

function loadStats(int $uid): array {
    $out = [];
    foreach (all('SELECT topic, recent, total FROM topic_stats WHERE user_id = ?', [$uid]) as $r) {
        $out[$r['topic']] = ['recent' => json_decode($r['recent'], true) ?: [], 'total' => (int)$r['total']];
    }
    return $out;
}

function sync(array $req): array {
    $user = requireUser($req);
    $uid = (int)$user['id'];
    $b = $req['body'] ?? [];
    $guest = $b['guestId'] ?? null;
    if (is_string($guest) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $guest)) insertIgnore('guests', ['guest_id' => $guest, 'user_id' => $uid]);

    $matches = $b['matches'] ?? [];
    if (!is_array($matches)) fail(400, 'bad_matches');
    if (count($matches) > MAX_BATCH) fail(413, 'too_many_matches');
    $accepted = []; $duplicate = []; $rejected = [];
    $now = time();
    foreach ($matches as $m) {
        [$row, $why] = checkMatch($m, $now);
        $id = is_array($m) && is_string($m['id'] ?? null) ? $m['id'] : '';
        if ($row === null) { $rejected[] = ['id' => $id, 'reason' => $why]; continue; }
        $row['user_id'] = $uid; $row['created_at'] = $now;
        if (insertIgnore('results', $row)) { $accepted[] = $id; continue; }
        $owner = one('SELECT user_id FROM results WHERE id = ?', [$id]);
        if ($owner && (int)$owner['user_id'] === $uid) $duplicate[] = $id;
        else $rejected[] = ['id' => $id, 'reason' => 'id_taken'];
    }

    $mine = loadStats($uid);
    $incoming = is_array($b['stats'] ?? null) ? $b['stats'] : [];
    foreach (allTopics() as $topic) {
        $s = cleanStat($incoming[$topic] ?? null);
        if (!$s) continue;
        if (!isset($mine[$topic]) || $s['total'] > $mine[$topic]['total']) {
            q('DELETE FROM topic_stats WHERE user_id = ? AND topic = ?', [$uid, $topic]);
            q('INSERT INTO topic_stats (user_id, topic, recent, total) VALUES (?,?,?,?)', [$uid, $topic, json_encode($s['recent']), $s['total']]);
            $mine[$topic] = $s;
        }
    }
    return [200, ['accepted' => $accepted, 'duplicate' => $duplicate, 'rejected' => $rejected, 'stats' => (object)$mine, 'user' => userView($user)]];
}

function weekStart(?int $now = null): int {
    $tz = new \DateTimeZone('Europe/Moscow');
    $d = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz)->modify('monday this week')->setTime(0, 0);
    return $d->getTimestamp();
}

/**
 * Рейтинг. mode=bot (по умолчанию): матчи против ботов, таблица results, порядок по очкам.
 * mode=duel: онлайн-дуэли, таблица duel_results, порядок по победам, затем по очкам.
 */
function leaderboard(array $req): array {
    $period = ($req['query']['period'] ?? 'all') === 'week' ? 'week' : 'all';
    $mode = ($req['query']['mode'] ?? 'bot') === 'duel' ? 'duel' : 'bot';
    $subject = $req['query']['subject'] ?? 'all';
    if ($subject !== 'all' && !isset(SUBJECT_TOPICS[$subject])) fail(400, 'bad_subject');
    $tbl = $mode === 'duel' ? 'duel_results' : 'results';
    $wins = "SUM(CASE WHEN r.outcome = 'win' THEN 1 ELSE 0 END)";
    $order = $mode === 'duel' ? "$wins DESC, SUM(r.score) DESC, COUNT(*) ASC, u.id ASC" : 'SUM(r.score) DESC, COUNT(*) ASC, u.id ASC';
    $where = ['1 = 1']; $args = [];
    if ($period === 'week') { $where[] = 'r.played_at >= ?'; $args[] = weekStart(); }
    if ($subject !== 'all') { $where[] = 'r.subject = ?'; $args[] = $subject; }
    $w = implode(' AND ', $where);
    $rows = all("SELECT u.id, u.nick, u.avatar, SUM(r.score) pts, COUNT(*) n, $wins wins
                 FROM $tbl r JOIN users u ON u.id = r.user_id WHERE $w
                 GROUP BY u.id, u.nick, u.avatar ORDER BY $order LIMIT 50", $args);
    $me = sessionUser($req);
    $list = []; $mine = null;
    foreach ($rows as $i => $r) {
        $e = ['rank' => $i + 1, 'name' => displayName($r), 'anon' => $r['nick'] === null, 'avatar' => $r['avatar'], 'points' => (int)$r['pts'], 'matches' => (int)$r['n'], 'wins' => (int)$r['wins']];
        if ($me && (int)$r['id'] === (int)$me['id']) { $e['me'] = true; $mine = $e; }
        $list[] = $e;
    }
    if ($me && !$mine) {
        $own = one("SELECT SUM(r.score) pts, COUNT(*) n, $wins wins FROM $tbl r WHERE r.user_id = ?" .
            ($period === 'week' ? ' AND r.played_at >= ?' : '') . ($subject !== 'all' ? ' AND r.subject = ?' : ''),
            array_merge([$me['id']], $args));
        if ($own && (int)$own['n'] > 0) {
            $cond = $mode === 'duel' ? "$wins > ? OR ($wins = ? AND SUM(r.score) > ?)" : 'SUM(r.score) > ?';
            $cargs = $mode === 'duel' ? [(int)$own['wins'], (int)$own['wins'], (int)$own['pts']] : [(int)$own['pts']];
            $above = one("SELECT COUNT(*) c FROM (SELECT r.user_id FROM $tbl r JOIN users u ON u.id = r.user_id WHERE $w GROUP BY r.user_id HAVING $cond) t",
                array_merge($args, $cargs));
            $mine = ['rank' => (int)$above['c'] + 1, 'name' => displayName($me), 'anon' => $me['nick'] === null, 'avatar' => $me['avatar'], 'points' => (int)$own['pts'],
                'matches' => (int)$own['n'], 'wins' => (int)$own['wins'], 'me' => true];
        }
    }
    return [200, ['period' => $period, 'subject' => $subject, 'mode' => $mode, 'top' => $list, 'me' => $mine]];
}

/* ---------- маршрутизация ---------- */
function routes(): array {
    return [
        'GET /health' => 'S2G\health',
        'GET /config' => fn() => [200, ['accounts' => true, 'duels' => true, 'avatars' => AVATARS]],
        'POST /auth/guest' => 'S2G\authGuest',
        'POST /auth/recover' => 'S2G\authRecover',
        'POST /auth/newcode' => 'S2G\newCode',
        'GET /me' => 'S2G\me',
        'POST /logout' => 'S2G\logout',
        'POST /profile' => 'S2G\profile',
        'POST /sync' => 'S2G\sync',
        'GET /leaderboard' => 'S2G\leaderboard',
        'POST /duel/create' => 'S2G\duelCreate',
        'POST /duel/join' => 'S2G\duelJoin',
        'POST /duel/queue' => 'S2G\duelQueue',
        'POST /duel/leave' => 'S2G\duelLeave',
        'GET /duel/state' => 'S2G\duelState',
        'POST /duel/progress' => 'S2G\duelProgress',
    ];
}

/** Вход для тестов и для run(): $req = method, route, query, body, cookie, ip, secretHeader, jsonType */
function dispatch(array $req): array {
    $key = $req['method'] . ' ' . $req['route'];
    $h = routes()[$key] ?? null;
    if (!$h) return [404, ['error' => 'not_found']];
    try {
        // JSON-Content-Type у POST заставляет браузер слать preflight на чужих сайтах: простая защита от CSRF
        if ($req['method'] === 'POST' && empty($req['jsonType'])) fail(415, 'json_required');
        return $h($req);
    } catch (HttpError $e) {
        return [$e->status, ['error' => $e->getMessage()] + $e->extra];
    } catch (\Throwable $e) {
        error_log('s2g: ' . $e);
        return [500, ['error' => 'server_error']];
    }
}

/** Маршрут API из пути: работает и для /api/..., и для /public_html/api/..., и при подпапке сайта */
function routeFromPath(string $path): string {
    if (preg_match('#/api(/[^?]*)?$#', $path, $m)) $route = $m[1] ?? '/';
    else $route = $path;
    $route = '/' . trim($route, '/');
    return $route === '/index.php' ? '/' : $route;
}

function run(): void {
    $route = routeFromPath(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $body = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $raw = file_get_contents('php://input', false, null, 0, 2000000);
        $body = json_decode($raw ?: '', true);
        if (!is_array($body)) $body = [];
    }
    $req = [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET', 'route' => $route, 'query' => $_GET, 'body' => $body,
        'cookie' => $_COOKIE[COOKIE] ?? '', 'ip' => clientIp(),
        'jsonType' => str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json'),
    ];
    $err = prepare();
    if ($err !== null) { http_response_code(503); header('Content-Type: application/json'); header('Cache-Control: no-store'); echo json_encode(['error' => $err]); return; }
    [$status, $data, $cookie] = dispatch($req) + [2 => null];
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($cookie) {
        setcookie(COOKIE, $cookie['value'], [
            'expires' => $cookie['expires'], 'path' => '/', 'secure' => isHttps(),   // по http cookie с Secure браузер выбросит, профиль не удержится
            'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}
/**
 * Подготовка при каждом запуске: подключение к БД и применение новых миграций.
 * Если новых файлов нет, это один быстрый SELECT. Возвращает код ошибки или null.
 * Сбой тут отключает только API: статичная игра в public_html/ продолжает работать.
 */
function prepare(): ?string {
    try { db(); } catch (\Throwable $e) { error_log('s2g db: ' . $e->getMessage()); return 'db_unavailable'; }
    try { $done = migrate(); if ($done) error_log('s2g: применены миграции: ' . implode(', ', $done)); }
    catch (\Throwable $e) { error_log('s2g migrate: ' . $e->getMessage()); return 'migration_failed'; }
    return null;
}
