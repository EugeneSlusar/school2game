<?php
declare(strict_types=1);
namespace S2G;

/*
 * Еженедельная лига. Неделя с понедельника по воскресенье (Москва). Игроки одной лиги делятся на группы до 20 человек,
 * внутри группы борются за опыт недели. В конце недели лучшие переходят в лигу выше, худшие вниз, трое лучших получают кубки.
 * Пока игроков мало, группу дополняют боты-соперники (помечены 🤖): их опыт считается детерминированно от недели и группы,
 * ничего не хранится, поэтому итог недели всегда воспроизводится. Опыт недели берётся из результатов игрока.
 */

const LEAGUES = [1 => 'Бронзовая', 2 => 'Серебряная', 3 => 'Золотая', 4 => 'Сапфировая', 5 => 'Рубиновая', 6 => 'Изумрудная', 7 => 'Алмазная'];
const LEAGUE_ICONS = [1 => '🥉', 2 => '🥈', 3 => '🥇', 4 => '🔷', 5 => '🔴', 6 => '💚', 7 => '💎'];
const LEAGUE_GROUP = 20;       // игроков в группе
const LEAGUE_UP = 5;           // первые пять переходят выше
const LEAGUE_DOWN = 3;         // последние трое спускаются ниже (кроме бронзовой)
const LEAGUE_MIN_SHOWN = 8;    // меньше стольких в группе не показываем: добавляем ботов
const BOT_POOL = ['Макс', 'Соня', 'Лёва', 'Алиса', 'Тимур', 'Мира', 'Даня', 'Ева', 'Кирилл', 'Полина', 'Артём', 'Злата', 'Матвей', 'Ника', 'Руслан', 'Вера', 'Глеб', 'Яна', 'Ильдар', 'Лиза', 'Богдан', 'Алёна', 'Платон', 'Дана'];

/** Номер недели (понедельник-воскресенье, Москва) */
function weekOf(int $ts): int { return intdiv(mskDay($ts) + 3, 7); }
function weekStartTs(int $week): int { return ($week * 7 - 3) * 86400 - MSK_OFFSET; }

/** Опыт за неделю у группы игроков: [uid => опыт]. Формулы те же, что в progress.php (без наград за задания). */
function weeklyPoints(array $uids, int $week): array {
    $out = array_fill_keys($uids, 0);
    if (!$uids) return [];
    $ws = weekStartTs($week); $we = $ws + 7 * 86400;
    $in = implode(',', array_fill(0, count($uids), '?'));
    $inWeek = fn(int $played, int $created) => ($t = $created - $played < 172800 ? min($played, $created) : $created) >= $ws && $t < $we;
    foreach (all("SELECT user_id, played_at, created_at, correct, outcome FROM results WHERE user_id IN ($in) AND created_at >= ? AND created_at < ?", array_merge($uids, [$ws, $we + 172800])) as $r) {
        if ($inWeek((int)$r['played_at'], (int)$r['created_at'])) $out[(int)$r['user_id']] += xpBot((int)$r['correct'], $r['outcome'] === 'win');
    }
    foreach (all("SELECT user_id, score, outcome FROM duel_results WHERE user_id IN ($in) AND played_at >= ? AND played_at < ?", array_merge($uids, [$ws, $we])) as $r) {
        $out[(int)$r['user_id']] += xpDuel((int)$r['score'], $r['outcome'] === 'win');
    }
    foreach (all("SELECT user_id, played_at, created_at, total, correct FROM test_results WHERE user_id IN ($in) AND created_at >= ? AND created_at < ?", array_merge($uids, [$ws, $we + 172800])) as $r) {
        if ($inWeek((int)$r['played_at'], (int)$r['created_at'])) $out[(int)$r['user_id']] += xpTest((int)$r['correct'], (int)$r['total']);
    }
    return $out;
}

/** Боты группы: опыт растёт в течение недели, к воскресенью доходит до «итога», который зависит от лиги */
function leagueBots(int $week, int $league, int $grp, int $count, int $now): array {
    $ws = weekStartTs($week);
    $frac = max(0.0, min(1.0, ($now - $ws) / (7 * 86400)));
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $seed = crc32("$week:$league:$grp:$i");
        $final = (int)((60 + ($seed >> 5) % 520) * (1 + 0.45 * ($league - 1)));
        $out[] = ['name' => BOT_POOL[($seed + $i * 5) % count(BOT_POOL)], 'avatar' => AVATARS[($seed >> 3) % FREE_AVATARS], 'xp' => (int)round($final * ($frac ** 0.85)), 'bot' => true, 'uid' => 0, 'frame' => ''];
    }
    return $out;
}

/** Таблица группы: игроки и боты по убыванию опыта, у каждого место */
function leagueStandings(int $week, int $league, int $grp, int $now): array {
    $members = all('SELECT u.id, u.nick, u.avatar, u.frame FROM league_members m JOIN users u ON u.id = m.user_id WHERE m.week = ? AND m.league = ? AND m.grp = ?', [$week, $league, $grp]);
    $pts = weeklyPoints(array_map(fn($m) => (int)$m['id'], $members), $week);
    $rows = [];
    foreach ($members as $m) $rows[] = ['uid' => (int)$m['id'], 'name' => displayName($m), 'avatar' => $m['avatar'], 'frame' => $m['frame'] ?? '', 'xp' => $pts[(int)$m['id']] ?? 0, 'bot' => false];
    foreach (leagueBots($week, $league, $grp, max(0, LEAGUE_MIN_SHOWN - count($rows)), $now) as $b) $rows[] = $b;
    usort($rows, fn($a, $b) => [$b['xp'], $a['bot'], $a['uid']] <=> [$a['xp'], $b['bot'], $b['uid']]);
    foreach ($rows as $i => &$r) $r['rank'] = $i + 1;
    return $rows;
}

/** Подвести итоги прошлых недель: повышения, понижения, места (один раз; повторный вызов безопасен) */
function leagueSettle(int $currentWeek): void {
    foreach (all('SELECT DISTINCT week FROM league_members WHERE week < ? AND final_rank IS NULL', [$currentWeek]) as $w) {
        $week = (int)$w['week'];
        $endNow = weekStartTs($week + 1);
        foreach (all('SELECT DISTINCT league, grp FROM league_members WHERE week = ? AND final_rank IS NULL', [$week]) as $g) {
            $league = (int)$g['league']; $table = leagueStandings($week, $league, (int)$g['grp'], $endNow);
            $size = count($table);
            foreach ($table as $r) {
                if ($r['bot']) continue;
                $res = ($r['rank'] <= LEAGUE_UP && $r['xp'] > 0 && $league < count(LEAGUES)) ? 'up' : (($r['rank'] > $size - LEAGUE_DOWN && $league > 1) ? 'down' : 'stay');
                q('UPDATE league_members SET final_rank = ?, final_xp = ?, result = ? WHERE week = ? AND user_id = ? AND final_rank IS NULL', [$r['rank'], $r['xp'], $res, $week, $r['uid']]);
            }
        }
    }
}

/** В какой лиге игрок на этой неделе: по итогам последней подведённой недели */
function leagueFor(int $uid, int $week): int {
    $prev = one('SELECT league, result FROM league_members WHERE user_id = ? AND week < ? ORDER BY week DESC LIMIT 1', [$uid, $week]);
    if (!$prev) return 1;
    $l = (int)$prev['league'];
    if ($prev['result'] === 'up') $l = min(count(LEAGUES), $l + 1);
    elseif ($prev['result'] === 'down') $l = max(1, $l - 1);
    return $l;
}

function leagueGroupFor(int $week, int $league): int {
    $row = one('SELECT grp FROM league_members WHERE week = ? AND league = ? GROUP BY grp HAVING COUNT(*) < ? ORDER BY grp ASC LIMIT 1', [$week, $league, LEAGUE_GROUP]);
    if ($row) return (int)$row['grp'];
    $m = one('SELECT MAX(grp) m FROM league_members WHERE week = ? AND league = ?', [$week, $league]);
    return (int)($m['m'] ?? 0) + 1;
}

/** Кубки: места 1-3 в подведённых неделях */
function leagueTrophies(int $uid): array {
    $t = ['gold' => 0, 'silver' => 0, 'bronze' => 0, 'list' => []];
    foreach (all('SELECT week, league, final_rank FROM league_members WHERE user_id = ? AND final_rank IS NOT NULL AND final_rank <= 3 ORDER BY week DESC LIMIT 200', [$uid]) as $r) {
        $rank = (int)$r['final_rank'];
        $t[$rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : 'bronze')]++;
        if (count($t['list']) < 12) $t['list'][] = ['week' => (int)$r['week'], 'league' => (int)$r['league'], 'name' => LEAGUES[(int)$r['league']], 'icon' => LEAGUE_ICONS[(int)$r['league']], 'rank' => $rank];
    }
    return $t;
}

function league(array $req): array {
    $user = requireUser($req); $uid = (int)$user['id'];
    $now = $req['now'] ?? time();
    $week = weekOf($now);
    leagueSettle($week);
    $mine = one('SELECT league, grp FROM league_members WHERE week = ? AND user_id = ?', [$week, $uid]);
    if (!$mine) {
        $l = leagueFor($uid, $week);
        insertIgnore('league_members', ['week' => $week, 'user_id' => $uid, 'league' => $l, 'grp' => leagueGroupFor($week, $l)]);
        $mine = one('SELECT league, grp FROM league_members WHERE week = ? AND user_id = ?', [$week, $uid]);
    }
    $league = (int)$mine['league'];
    $table = leagueStandings($week, $league, (int)$mine['grp'], $now);
    $me = null; $list = [];
    foreach ($table as $r) {
        $e = ['rank' => $r['rank'], 'name' => $r['name'], 'avatar' => $r['avatar'], 'frame' => $r['frame'], 'xp' => $r['xp'], 'bot' => $r['bot']];
        if (!$r['bot'] && $r['uid'] === $uid) { $e['me'] = true; $me = $e; }
        $list[] = $e;
    }
    $last = one('SELECT week, league, final_rank, final_xp, result FROM league_members WHERE user_id = ? AND week = ? AND final_rank IS NOT NULL', [$uid, $week - 1]);
    return [200, [
        'week' => $week, 'league' => $league, 'name' => LEAGUES[$league] . ' лига', 'icon' => LEAGUE_ICONS[$league], 'leagues' => count(LEAGUES),
        'secondsLeft' => weekStartTs($week + 1) - $now, 'size' => count($table), 'up' => LEAGUE_UP, 'down' => LEAGUE_DOWN,
        'rank' => $me['rank'] ?? null, 'xp' => $me['xp'] ?? 0, 'list' => $list,
        'last' => $last ? ['league' => (int)$last['league'], 'name' => LEAGUES[(int)$last['league']] . ' лига', 'icon' => LEAGUE_ICONS[(int)$last['league']], 'rank' => (int)$last['final_rank'], 'xp' => (int)$last['final_xp'], 'result' => $last['result']] : null,
        'trophies' => leagueTrophies($uid),
    ]];
}
