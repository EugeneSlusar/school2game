<?php
declare(strict_types=1);
namespace S2G;

/*
 * Онлайн-дуэль двух живых игроков. Хостинг общий (PHP, без WebSocket), поэтому обмен идёт опросом:
 * во время матча клиент раз в секунду шлёт свои очки в /duel/progress и в ответ получает очки соперника.
 * Вопросы у обоих одинаковые: общий seed (mulberry32) задаёт сервер. Старт общий: сервер называет время
 * начала в своих миллисекундах (startMs) и своё текущее время (serverMs), клиент считает смещение часов.
 * Итог считает сервер по очкам обоих и пишет в duel_results: это отдельный рейтинг онлайн-игр,
 * в обычный рейтинг (против ботов) дуэли не попадают.
 */

const DUEL_LEAD_MS = 5000;         // от соединения пары до старта матча
const DUEL_ROOM_TTL = 600;         // комната ждёт соперника 10 минут
const DUEL_QUEUE_TTL = 45;         // запись в очереди живёт 45 секунд без обновления
const DUEL_GONE_MS = 10000;        // столько без опроса, и игрок считается отключившимся
const DUEL_MAX_MS = 140000;        // матч не может идти дольше (120 с + запас)
const DUEL_MIN_RATED_PTS = 5;      // в рейтинг идёт дуэль, где каждый набрал хотя бы столько (иначе это «пустая» игра)
const DUEL_PAIR_PER_DAY = 10;      // одна и та же пара: не больше стольких зачётных дуэлей в сутки

/** Текущее время в миллисекундах; в тестах подменяется через $GLOBALS['S2G_NOW_MS'] */
function nowMs(): int { return isset($GLOBALS['S2G_NOW_MS']) ? (int)$GLOBALS['S2G_NOW_MS'] : (int)floor(microtime(true) * 1000); }

/** Настройки дуэли из тела запроса: [предмет, тема, уровень, вид спорта]. «Слабые места» в дуэли не бывают: вопросы у двоих общие. */
function duelSettings(array $b, bool $anyTopic = false): array {
    $subject = $b['subject'] ?? null;
    if (!is_string($subject) || !isset(SUBJECT_TOPICS[$subject])) fail(400, 'bad_subject');
    $topic = $b['topic'] ?? 'all';
    if (!is_string($topic) || !in_array($topic, SUBJECT_TOPICS[$subject], true)) $topic = 'all';
    if ($anyTopic) $topic = 'all';
    $level = $b['level'] ?? null;
    if (!in_array($level, LEVELS, true)) fail(400, 'bad_level');
    $sport = $b['sport'] ?? 'football';
    if (!in_array($sport, SPORTS, true)) $sport = 'football';
    return [$subject, $topic, $level, $sport];
}

function duelRow(int $id): ?array { return one('SELECT * FROM duels WHERE id = ?', [$id]); }
function duelSlot(array $d, int $uid): int { return (int)$d['p1'] === $uid ? 1 : ((int)($d['p2'] ?? 0) === $uid ? 2 : 0); }
/** Дуэль по id, в которой участвует игрок; иначе 404 (чужие дуэли не показываем) */
function duelFor(array $req, int $uid): array {
    $id = ival($req['query']['id'] ?? ($req['body']['id'] ?? null), 1, PHP_INT_MAX);
    $d = $id === null ? null : duelRow($id);
    if (!$d || duelSlot($d, $uid) === 0) fail(404, 'duel_not_found');
    return $d;
}

function duelCleanup(): void {
    $now = time();
    q("UPDATE duels SET status = 'cancelled' WHERE status = 'waiting' AND created_at < ?", [$now - DUEL_ROOM_TTL]);
    q("UPDATE duels SET status = 'cancelled' WHERE status = 'playing' AND start_ms < ?", [nowMs() - 600000]);
    q('DELETE FROM duel_queue WHERE created_at < ?', [$now - DUEL_QUEUE_TTL]);
}

/** Игрок начинает новую дуэль: старые комнаты закрываются, начатый матч засчитывается как выход */
function duelLeaveAll(int $uid): void {
    q('DELETE FROM duel_queue WHERE user_id = ?', [$uid]);
    q("UPDATE duels SET status = 'cancelled' WHERE status = 'waiting' AND p1 = ?", [$uid]);
    foreach (all("SELECT * FROM duels WHERE status = 'playing' AND (p1 = ? OR p2 = ?)", [$uid, $uid]) as $d) duelQuit($d, duelSlot($d, $uid));
}
function duelQuit(array $d, int $slot): void {
    q("UPDATE duels SET forfeit = ? WHERE id = ? AND status = 'playing' AND forfeit = 0", [$slot, $d['id']]);
    $fresh = duelRow((int)$d['id']);
    if ($fresh) duelSettle($fresh);
}

function duelStarted(array $d): bool { return $d['start_ms'] !== null && nowMs() >= (int)$d['start_ms']; }
function duelGone(array $d, int $slot): bool {
    $seen = (int)$d["p{$slot}_seen"];
    return duelStarted($d) && nowMs() - max($seen, (int)$d['start_ms']) > DUEL_GONE_MS;
}

/**
 * Подвести итог, если все условия выполнены: оба закончили, кто-то вышел, соперник пропал, вышло время.
 * Возвращает свежую строку. Итог пишется один раз (UPDATE ... WHERE status = 'playing').
 */
function duelSettle(array $d): array {
    if ($d['status'] !== 'playing') return $d;
    if (!duelStarted($d)) {                                         // вышел ещё до старта: матча не было
        if ((int)$d['forfeit'] > 0) { q("UPDATE duels SET status = 'cancelled' WHERE id = ? AND status = 'playing'", [$d['id']]); return duelRow((int)$d['id']) ?? $d; }
        return $d;
    }
    $f1 = (int)$d['p1_fin'] === 1; $f2 = (int)$d['p2_fin'] === 1;
    $forfeit = (int)$d['forfeit'];
    $late = nowMs() > (int)$d['start_ms'] + DUEL_MAX_MS;
    $ready = $forfeit > 0 || ($f1 && $f2) || ($f1 && duelGone($d, 2)) || ($f2 && duelGone($d, 1)) || $late;
    if (!$ready) return $d;

    $p1 = (int)$d['p1_pts']; $p2 = (int)$d['p2_pts'];
    $winner = $forfeit > 0 ? 3 - $forfeit : ($p1 > $p2 ? 1 : ($p1 < $p2 ? 2 : 0));
    // зачёт: у обоих хотя бы DUEL_MIN_RATED_PTS (при выходе хватает очков у победителя) и пара не накручивает
    $rated = $forfeit > 0 ? (max($p1, $p2) >= DUEL_MIN_RATED_PTS && ($winner === 1 ? $p1 : $p2) >= DUEL_MIN_RATED_PTS)
                          : min($p1, $p2) >= DUEL_MIN_RATED_PTS;
    if ($rated) {
        $cnt = one('SELECT COUNT(*) c FROM duels WHERE rated = 1 AND finished_at >= ? AND ((p1 = ? AND p2 = ?) OR (p1 = ? AND p2 = ?))',
            [time() - 86400, $d['p1'], $d['p2'], $d['p2'], $d['p1']]);
        if ((int)$cnt['c'] >= DUEL_PAIR_PER_DAY) $rated = false;
    }
    $now = time();
    $n = q("UPDATE duels SET status = 'done', winner = ?, rated = ?, finished_at = ? WHERE id = ? AND status = 'playing'",
        [$winner, $rated ? 1 : 0, $now, $d['id']])->rowCount();
    if ($n === 1 && $rated) {
        foreach ([[1, (int)$d['p1'], $p1, $p2], [2, (int)$d['p2'], $p2, $p1]] as [$slot, $uid, $mine, $theirs]) {
            $outcome = $winner === 0 ? 'draw' : ($winner === $slot ? 'win' : 'lose');
            insertIgnore('duel_results', ['duel_id' => (int)$d['id'], 'user_id' => $uid, 'played_at' => $now, 'subject' => $d['subject'],
                'score' => $mine, 'opp_score' => $theirs, 'outcome' => $outcome]);
        }
    }
    return duelRow((int)$d['id']) ?? $d;
}

function duelView(array $d, int $uid): array {
    $me = duelSlot($d, $uid); $o = 3 - $me;
    $opp = null;
    if ($d["p$o"] !== null) {
        $u = one('SELECT id, nick, avatar FROM users WHERE id = ?', [$d["p$o"]]);
        $opp = ['name' => $u ? displayName($u) : 'Соперник', 'avatar' => $u['avatar'] ?? '🙂', 'sport' => $d["p{$o}_sport"],
            'pts' => (int)$d["p{$o}_pts"], 'fin' => (int)$d["p{$o}_fin"] === 1, 'gone' => $d['status'] === 'playing' && duelGone($d, $o)];
    }
    $result = null;
    if ($d['status'] === 'done') {
        $w = (int)$d['winner']; $f = (int)$d['forfeit'];
        $result = ['you' => (int)$d["p{$me}_pts"], 'foe' => (int)$d["p{$o}_pts"],
            'outcome' => $w === 0 ? 'draw' : ($w === $me ? 'win' : 'lose'),
            'forfeit' => $f === 0 ? null : ($f === $me ? 'me' : 'opp'), 'rated' => (int)$d['rated'] === 1];
    }
    return ['id' => (int)$d['id'], 'code' => $d['code'], 'status' => $d['status'], 'subject' => $d['subject'], 'topic' => $d['topic'],
        'level' => $d['level'], 'seed' => (int)$d['seed'], 'startMs' => $d['start_ms'] === null ? null : (int)$d['start_ms'], 'serverMs' => nowMs(),
        'sport' => $d["p{$me}_sport"], 'opp' => $opp, 'result' => $result];
}

/** Новая дуэль между двумя игроками: сразу «идёт», старт через DUEL_LEAD_MS */
function duelCreatePair(string $source, string $code, array $s, int $p1, string $p1Sport, int $p2, string $p2Sport): int {
    [$subject, $topic, $level] = $s;
    q("INSERT INTO duels (code, status, source, subject, topic, level, seed, created_at, start_ms, p1, p2, p1_sport, p2_sport)
       VALUES (?, 'playing', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$code, $source, $subject, $topic, $level, random_int(1, 2147483646), time(), nowMs() + DUEL_LEAD_MS, $p1, $p2, $p1Sport, $p2Sport]);
    return (int)db()->lastInsertId();
}

/* ---------- маршруты ---------- */

/** Создать комнату: вернёт 4-значный код, второй игрок вводит его (или идёт по ссылке) */
function duelCreate(array $req): array {
    $user = requireUser($req); $uid = (int)$user['id'];
    if (!rateLimit('duelc:' . $uid, 40, 3600)) fail(429, 'too_many_requests');
    [$subject, $topic, $level, $sport] = duelSettings($req['body'] ?? []);
    duelCleanup(); duelLeaveAll($uid);
    $code = '';
    for ($i = 0; $i < 30; $i++) {
        $c = (string)random_int(1000, 9999);
        if (!one("SELECT id FROM duels WHERE code = ? AND status = 'waiting'", [$c])) { $code = $c; break; }
    }
    if ($code === '') fail(503, 'no_free_code');
    q("INSERT INTO duels (code, status, source, subject, topic, level, seed, created_at, p1, p1_sport) VALUES (?, 'waiting', 'code', ?, ?, ?, ?, ?, ?, ?)",
        [$code, $subject, $topic, $level, random_int(1, 2147483646), time(), $uid, $sport]);
    return [200, duelView(duelRow((int)db()->lastInsertId()), $uid)];
}

/** Войти в комнату по коду */
function duelJoin(array $req): array {
    $user = requireUser($req); $uid = (int)$user['id'];
    $rk = 'duelj:' . $uid;
    if (rateBlocked($rk, 15, 600)) fail(429, 'too_many_requests');   // подбор кодов: считаем только промахи
    $code = preg_replace('/\D/', '', (string)($req['body']['code'] ?? '')) ?? '';
    $sport = $req['body']['sport'] ?? 'football';
    if (!in_array($sport, SPORTS, true)) $sport = 'football';
    duelCleanup();
    $d = strlen($code) === 4 ? one("SELECT * FROM duels WHERE code = ? AND status = 'waiting' ORDER BY id DESC LIMIT 1", [$code]) : null;
    if (!$d) { rateHit($rk, 600); fail(404, 'room_not_found'); }
    if ((int)$d['p1'] === $uid) fail(400, 'own_room');
    duelLeaveAll($uid);
    $n = q("UPDATE duels SET status = 'playing', p2 = ?, p2_sport = ?, start_ms = ? WHERE id = ? AND status = 'waiting'",
        [$uid, $sport, nowMs() + DUEL_LEAD_MS, $d['id']])->rowCount();
    if ($n !== 1) fail(409, 'room_taken');
    return [200, duelView(duelRow((int)$d['id']), $uid)];
}

/** «Случайный соперник»: идемпотентно, клиент зовёт раз в 1–2 секунды, пока не получит дуэль */
function duelQueue(array $req): array {
    $user = requireUser($req); $uid = (int)$user['id'];
    [$subject, , $level, $sport] = duelSettings($req['body'] ?? [], true);
    duelCleanup();
    $mine = one('SELECT * FROM duel_queue WHERE user_id = ?', [$uid]);
    if ($mine && $mine['duel_id'] !== null && (int)$mine['duel_id'] > 0) {          // соперник уже собрал пару
        q('DELETE FROM duel_queue WHERE user_id = ?', [$uid]);
        $d = duelRow((int)$mine['duel_id']);
        if ($d && duelSlot($d, $uid) > 0) return [200, duelView($d, $uid)];
        $mine = null;
    }
    if (!$mine) {
        if (!rateLimit('duelq:' . $uid, 200, 3600)) fail(429, 'too_many_requests');
        // новый поиск: закрываем свои старые комнаты
        q("UPDATE duels SET status = 'cancelled' WHERE status = 'waiting' AND p1 = ?", [$uid]);
        insertIgnore('duel_queue', ['user_id' => $uid, 'subject' => $subject, 'level' => $level, 'sport' => $sport, 'created_at' => time()]);
    } else {
        q('UPDATE duel_queue SET created_at = ?, subject = ?, level = ?, sport = ? WHERE user_id = ? AND duel_id IS NULL', [time(), $subject, $level, $sport, $uid]);
    }
    $other = one('SELECT * FROM duel_queue WHERE duel_id IS NULL AND user_id <> ? AND subject = ? AND level = ? ORDER BY created_at ASC LIMIT 1', [$uid, $subject, $level]);
    if ($other) {
        // занимаем обоих в порядке номеров, чтобы одновременные попытки не создали две дуэли
        $ids = [$uid, (int)$other['user_id']]; sort($ids);
        $got = [];
        foreach ($ids as $id) {
            if (q('UPDATE duel_queue SET duel_id = -1 WHERE user_id = ? AND duel_id IS NULL', [$id])->rowCount() === 1) $got[] = $id; else break;
        }
        if (count($got) === 2) {
            $duel = duelCreatePair('queue', 'rnd', [$subject, 'all', $level], (int)$other['user_id'], $other['sport'], $uid, $sport);
            q('UPDATE duel_queue SET duel_id = ? WHERE user_id = ?', [$duel, $other['user_id']]);
            q('DELETE FROM duel_queue WHERE user_id = ?', [$uid]);
            return [200, duelView(duelRow($duel), $uid)];
        }
        foreach ($got as $id) q('UPDATE duel_queue SET duel_id = NULL WHERE user_id = ? AND duel_id = -1', [$id]);
    }
    return [200, ['status' => 'queued', 'serverMs' => nowMs()]];
}

/** Отмена ожидания; для начатого матча это выход (проигрыш) */
function duelLeave(array $req): array {
    $user = requireUser($req); $uid = (int)$user['id'];
    q('DELETE FROM duel_queue WHERE user_id = ?', [$uid]);
    $id = ival($req['body']['id'] ?? null, 1, PHP_INT_MAX);
    $d = $id === null ? null : duelRow($id);
    if ($d && duelSlot($d, $uid) > 0) {
        if ($d['status'] === 'waiting' && (int)$d['p1'] === $uid) q("UPDATE duels SET status = 'cancelled' WHERE id = ? AND status = 'waiting'", [$d['id']]);
        elseif ($d['status'] === 'playing') duelQuit($d, duelSlot($d, $uid));
    }
    return [200, ['ok' => true]];
}

/** Состояние дуэли (ожидание соперника, итог). Во время матча клиент пользуется /duel/progress. */
function duelState(array $req): array {
    $user = requireUser($req); $uid = (int)$user['id'];
    $d = duelFor($req, $uid);
    $slot = duelSlot($d, $uid);
    if ($d['status'] === 'playing') {
        q("UPDATE duels SET p{$slot}_seen = ? WHERE id = ?", [nowMs(), $d['id']]);
        $d = duelSettle(duelRow((int)$d['id']) ?? $d);
    }
    return [200, duelView($d, $uid)];
}

/**
 * Свои очки → очки соперника. Тело: id, pts, fin (матч у меня закончился), quit (вышел).
 * Очки только растут; больше, чем можно набрать за прошедшее время, сервер не засчитывает (обрезает до допустимого).
 */
function duelProgress(array $req): array {
    $user = requireUser($req); $uid = (int)$user['id'];
    $d = duelFor($req, $uid);
    $slot = duelSlot($d, $uid); $b = $req['body'] ?? [];
    if ($d['status'] !== 'playing') return [200, duelView($d, $uid)];

    $now = nowMs();
    $sets = ["p{$slot}_seen = ?"]; $args = [$now];
    if (duelStarted($d)) {
        $cur = (int)$d["p{$slot}_pts"];
        $pts = array_key_exists('pts', $b) ? ival($b['pts'], 0, 1000) : $cur;     // при выходе очки можно не слать
        if ($pts === null) fail(400, 'bad_number');
        $elapsed = max(0.0, ($now - (int)$d['start_ms']) / 1000);
        $cap = (int)ceil(3 * ($elapsed / MIN_ANSWER_SEC + 1)) + 5;      // верный ответ в среднем не больше 3 очков, быстрее 0,7 с не отвечают
        $cap = min($cap, POINTS_PER_PLAYER + 2 * MAX_GAIN);
        $pts = min($pts, $cap);                                           // больше возможного не засчитываем, но и не ломаем матч: итог у обоих игроков остаётся одинаковым
        if ($pts > $cur) { $sets[] = "p{$slot}_pts = ?"; $args[] = $pts; $cur = $pts; }
        $oppPts = (int)$d['p' . (3 - $slot) . '_pts'];
        // «матч закончился» принимаем, только если он правда мог закончиться: цель набрана (кем-то) или вышло время
        if (!empty($b['fin']) && ($cur >= POINTS_PER_PLAYER || $oppPts >= POINTS_PER_PLAYER || $elapsed >= MATCH_SECONDS - 3)) $sets[] = "p{$slot}_fin = 1";
        if (!empty($b['quit'])) { $sets[] = 'forfeit = ?'; $args[] = $slot; }
    }
    $args[] = $d['id'];
    q('UPDATE duels SET ' . implode(', ', $sets) . " WHERE id = ? AND status = 'playing'" . (!empty($b['quit']) ? ' AND forfeit = 0' : ''), $args);
    $d = duelSettle(duelRow((int)$d['id']) ?? $d);
    return [200, duelView($d, $uid)];
}
