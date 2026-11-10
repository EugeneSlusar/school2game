<?php
declare(strict_types=1);
namespace S2G;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/validate.php';

const COOKIE = 's2g_sess';
const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const CODE_TTL = 600;
const MAX_BATCH = 200;

/** Вход через MAX включён, только если заданы токен бота, секрет вебхука и ссылка на бота. Без них игра и рейтинг для гостей работают. */
function maxConfigured(): bool {
    return env('MAX_BOT_TOKEN') !== '' && env('MAX_WEBHOOK_SECRET') !== '' && env('MAX_BOT_LINK') !== '';
}

/* ---------- HTTP к MAX (подменяется в тестах) ---------- */
$GLOBALS['s2g_http'] = null;
function httpPost(string $url, array $body, array $headers): array {
    if ($GLOBALS['s2g_http']) return ($GLOBALS['s2g_http'])($url, $body, $headers);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $out === false ? '' : (string)$out];
}
function maxSend(int $maxUserId, string $text): void {
    $token = env('MAX_BOT_TOKEN');
    if ($token === '') return;
    try {
        // токен идёт в заголовке Authorization без Bearer, адресат в query
        httpPost(env('MAX_API_BASE', 'https://platform-api2.max.ru') . '/messages?user_id=' . $maxUserId, ['text' => $text], ['Authorization: ' . $token]);
    } catch (\Throwable $e) { /* ответ пользователю не критичен */ }
}

/* ---------- пользователи и сессии ---------- */
function userView(array $u): array {
    return ['id' => (int)$u['id'], 'nick' => $u['nick'], 'avatar' => $u['avatar'], 'needsNick' => $u['nick'] === null];
}
function sessionUser(array $req): ?array {
    $tok = $req['cookie'] ?? '';
    if (!is_string($tok) || !preg_match('/^[a-f0-9]{64}$/', $tok)) return null;
    $row = one('SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? AND s.expires_at > ?', [hashToken($tok), time()]);
    if ($row) q('UPDATE users SET last_seen = ? WHERE id = ?', [time(), $row['id']]);
    return $row;
}
function requireUser(array $req): array { return sessionUser($req) ?? fail(401, 'not_logged_in'); }

function randomCode(): string {
    $s = '';
    for ($i = 0; $i < 8; $i++) $s .= CODE_ALPHABET[random_int(0, strlen(CODE_ALPHABET) - 1)];
    return $s;
}

/* ---------- обработчики ---------- */
function authStart(array $req): array {
    if (!maxConfigured()) fail(503, 'login_not_configured');
    $now = time();
    q('DELETE FROM login_codes WHERE expires_at < ?', [$now - 3600]);
    q('DELETE FROM sessions WHERE expires_at < ?', [$now]);
    $ip = hash('sha256', ($req['ip'] ?? '') . '|' . env('MAX_WEBHOOK_SECRET'));
    $ip = substr($ip, 0, 16);
    $recent = one('SELECT COUNT(*) c FROM login_codes WHERE ip_hash = ? AND created_at > ?', [$ip, $now - CODE_TTL]);
    if ((int)$recent['c'] >= 20) fail(429, 'too_many_requests');
    $poll = bin2hex(random_bytes(24));
    for ($i = 0; $i < 5; $i++) {
        $code = randomCode();
        $ins = insertIgnore('login_codes', ['code' => $code, 'poll_hash' => hashToken($poll), 'status' => 'pending', 'user_id' => null,
            'ip_hash' => $ip, 'created_at' => $now, 'expires_at' => $now + CODE_TTL]);
        if ($ins) {
            $link = env('MAX_BOT_LINK');
            return [200, ['code' => $code, 'poll' => $poll, 'expiresIn' => CODE_TTL,
                'link' => $link !== '' ? $link . '?start=' . $code : null]];
        }
    }
    fail(500, 'code_generation_failed');
}

function authPoll(array $req): array {
    $poll = $req['body']['poll'] ?? null;
    if (!is_string($poll) || !preg_match('/^[a-f0-9]{48}$/', $poll)) fail(400, 'bad_poll');
    $row = one('SELECT * FROM login_codes WHERE poll_hash = ?', [hashToken($poll)]);
    if (!$row || $row['expires_at'] < time() || $row['status'] === 'used') return [200, ['status' => 'expired']];
    if ($row['status'] !== 'confirmed') return [200, ['status' => 'pending']];
    // атомарно «забираем» код, чтобы сессию получил только один запрос
    $taken = q("UPDATE login_codes SET status = 'used' WHERE code = ? AND status = 'confirmed'", [$row['code']])->rowCount();
    if (!$taken) return [200, ['status' => 'expired']];
    $user = one('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
    $tok = bin2hex(random_bytes(32));
    $days = max(1, (int)env('SESSION_DAYS', '60'));
    q('INSERT INTO sessions (token_hash, user_id, expires_at) VALUES (?,?,?)', [hashToken($tok), $user['id'], time() + $days * 86400]);
    return [200, ['status' => 'ok', 'user' => userView($user)], ['value' => $tok, 'expires' => time() + $days * 86400]];
}

function webhook(array $req): array {
    $secret = env('MAX_WEBHOOK_SECRET');
    if ($secret === '') fail(503, 'webhook_not_configured');
    if (!hash_equals($secret, (string)($req['secretHeader'] ?? ''))) fail(401, 'bad_secret');
    $u = $req['body'] ?? [];
    $type = $u['update_type'] ?? '';
    $maxId = null; $text = '';
    if ($type === 'bot_started') {
        $maxId = $u['user']['user_id'] ?? null;
        $text = (string)($u['payload'] ?? '');
    } elseif ($type === 'message_created') {
        $maxId = $u['message']['sender']['user_id'] ?? null;
        $text = (string)($u['message']['body']['text'] ?? '');
    } else {
        return [200, ['ok' => true]];
    }
    if (!is_int($maxId) || $maxId <= 0) return [200, ['ok' => true]];

    $text = mb_strtoupper(trim($text));
    if (!preg_match('/\b([' . CODE_ALPHABET . ']{8})\b/u', $text, $m)) {
        maxSend($maxId, 'Привет! Это бот школьной игры. Чтобы войти, открой игру, нажми «Войти через MAX» и отправь мне код из игры.');
        return [200, ['ok' => true]];
    }
    $row = one("SELECT * FROM login_codes WHERE code = ? AND status = 'pending' AND expires_at > ?", [$m[1], time()]);
    if (!$row) {
        maxSend($maxId, 'Код не найден или устарел. Вернись в игру и нажми «Войти через MAX» ещё раз.');
        return [200, ['ok' => true]];
    }
    $now = time();
    insertIgnore('users', ['max_user_id' => $maxId, 'avatar' => AVATARS[array_rand(AVATARS)], 'created_at' => $now, 'last_seen' => $now]);
    $user = one('SELECT id FROM users WHERE max_user_id = ?', [$maxId]);
    q("UPDATE login_codes SET status = 'confirmed', user_id = ? WHERE code = ? AND status = 'pending'", [$user['id'], $row['code']]);
    maxSend($maxId, 'Готово, вход подтверждён! Возвращайся в игру.');
    return [200, ['ok' => true]];
}

function health(array $req): array {
    return [200, ['ok' => true, 'db' => true, 'login' => maxConfigured()]];
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
    $nick = cleanNick($b['nick'] ?? null);
    if ($nick === null) fail(400, 'bad_nick');
    $avatar = $b['avatar'] ?? $user['avatar'];
    if (!in_array($avatar, AVATARS, true)) fail(400, 'bad_avatar');
    $taken = one('SELECT id FROM users WHERE nick_key = ? AND id <> ?', [mb_strtolower($nick), $user['id']]);
    if ($taken) fail(409, 'nick_taken');
    try {
        q('UPDATE users SET nick = ?, nick_key = ?, avatar = ? WHERE id = ?', [$nick, mb_strtolower($nick), $avatar, $user['id']]);
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

function leaderboard(array $req): array {
    $period = ($req['query']['period'] ?? 'all') === 'week' ? 'week' : 'all';
    $subject = $req['query']['subject'] ?? 'all';
    if ($subject !== 'all' && !isset(SUBJECT_TOPICS[$subject])) fail(400, 'bad_subject');
    $where = ['u.nick IS NOT NULL']; $args = [];
    if ($period === 'week') { $where[] = 'r.played_at >= ?'; $args[] = weekStart(); }
    if ($subject !== 'all') { $where[] = 'r.subject = ?'; $args[] = $subject; }
    $w = implode(' AND ', $where);
    $rows = all("SELECT u.id, u.nick, u.avatar, SUM(r.score) pts, COUNT(*) n, SUM(CASE WHEN r.outcome = 'win' THEN 1 ELSE 0 END) wins
                 FROM results r JOIN users u ON u.id = r.user_id WHERE $w
                 GROUP BY u.id, u.nick, u.avatar ORDER BY pts DESC, n ASC, u.id ASC LIMIT 50", $args);
    $me = sessionUser($req);
    $list = []; $mine = null;
    foreach ($rows as $i => $r) {
        $e = ['rank' => $i + 1, 'nick' => $r['nick'], 'avatar' => $r['avatar'], 'points' => (int)$r['pts'], 'matches' => (int)$r['n'], 'wins' => (int)$r['wins']];
        if ($me && (int)$r['id'] === (int)$me['id']) { $e['me'] = true; $mine = $e; }
        $list[] = $e;
    }
    if ($me && !$mine && $me['nick'] !== null) {
        $own = one("SELECT SUM(r.score) pts, COUNT(*) n, SUM(CASE WHEN r.outcome = 'win' THEN 1 ELSE 0 END) wins FROM results r WHERE r.user_id = ?" .
            ($period === 'week' ? ' AND r.played_at >= ?' : '') . ($subject !== 'all' ? ' AND r.subject = ?' : ''),
            array_merge([$me['id']], $args));
        if ($own && (int)$own['n'] > 0) {
            $above = one("SELECT COUNT(*) c FROM (SELECT r.user_id FROM results r JOIN users u ON u.id = r.user_id WHERE $w GROUP BY r.user_id HAVING SUM(r.score) > ?) t",
                array_merge($args, [(int)$own['pts']]));
            $mine = ['rank' => (int)$above['c'] + 1, 'nick' => $me['nick'], 'avatar' => $me['avatar'], 'points' => (int)$own['pts'],
                'matches' => (int)$own['n'], 'wins' => (int)$own['wins'], 'me' => true];
        }
    }
    return [200, ['period' => $period, 'subject' => $subject, 'top' => $list, 'me' => $mine]];
}

/* ---------- маршрутизация ---------- */
function routes(): array {
    return [
        'GET /health' => 'S2G\health',
        'GET /config' => fn() => [200, ['login' => maxConfigured(), 'avatars' => AVATARS]],
        'POST /auth/start' => 'S2G\authStart',
        'POST /auth/poll' => 'S2G\authPoll',
        'POST /max/webhook' => 'S2G\webhook',
        'GET /me' => 'S2G\me',
        'POST /logout' => 'S2G\logout',
        'POST /profile' => 'S2G\profile',
        'POST /sync' => 'S2G\sync',
        'GET /leaderboard' => 'S2G\leaderboard',
    ];
}

/** Вход для тестов и для run(): $req = method, route, query, body, cookie, ip, secretHeader, jsonType */
function dispatch(array $req): array {
    $key = $req['method'] . ' ' . $req['route'];
    $h = routes()[$key] ?? null;
    if (!$h) return [404, ['error' => 'not_found']];
    try {
        // JSON-Content-Type у POST заставляет браузер слать preflight на чужих сайтах: простая защита от CSRF
        if ($req['method'] === 'POST' && $req['route'] !== '/max/webhook' && empty($req['jsonType'])) fail(415, 'json_required');
        return $h($req);
    } catch (HttpError $e) {
        return [$e->status, ['error' => $e->getMessage()] + $e->extra];
    } catch (\Throwable $e) {
        error_log('s2g: ' . $e);
        return [500, ['error' => 'server_error']];
    }
}

/** Маршрут API из пути: работает и для /api/..., и для /www/api/..., и при подпапке сайта */
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
        'cookie' => $_COOKIE[COOKIE] ?? '', 'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'secretHeader' => $_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? '',
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
            'expires' => $cookie['expires'], 'path' => '/', 'secure' => str_starts_with(env('APP_URL'), 'https'),
            'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}
/**
 * Подготовка при каждом запуске: подключение к БД и применение новых миграций.
 * Если новых файлов нет, это один быстрый SELECT. Возвращает код ошибки или null.
 * Сбой тут отключает только API: статичная игра в www/ продолжает работать.
 */
function prepare(): ?string {
    try { db(); } catch (\Throwable $e) { error_log('s2g db: ' . $e->getMessage()); return 'db_unavailable'; }
    try { $done = migrate(); if ($done) error_log('s2g: применены миграции: ' . implode(', ', $done)); }
    catch (\Throwable $e) { error_log('s2g migrate: ' . $e->getMessage()); return 'migration_failed'; }
    return null;
}
