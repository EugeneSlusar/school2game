<?php
declare(strict_types=1);
namespace S2G;

/*
 * Система возвращения: серия дней, задания дня, опыт (XP) и уровни, значки, открываемые аватары.
 * Ничего не хранится отдельно: прогресс целиком считается из результатов игрока (матчи против ботов, дуэли, контрольные),
 * поэтому он переносится на другой телефон вместе с профилем и не может разойтись с рейтингом.
 * Сутки считаются по Москве. Всё без покупок и штрафов: серию можно «заморозить», опыт не отнимается.
 */

const MSK_OFFSET = 10800;                // Москва, UTC+3
const FREE_AVATARS = 10;                 // первые аватары открыты сразу, остальные открываются
const MAX_LEVEL = 30;
const QUEST_DAY_BONUS = 30;              // за все три задания дня
const TITLES = [1 => 'Новичок', 2 => 'Ученик', 3 => 'Знаток', 4 => 'Спортсмен', 5 => 'Умник', 6 => 'Мастер', 7 => 'Чемпион класса', 8 => 'Чемпион школы', 9 => 'Звезда', 10 => 'Легенда'];

/** [текст, показатель за день, сколько нужно, награда XP] */
const QUESTS = [
    'c20' => ['Реши 20 примеров верно', 'correct', 20, 25],
    'c40' => ['Реши 40 примеров верно', 'correct', 40, 45],
    'm2' => ['Сыграй 2 матча', 'matches', 2, 25],
    'w1' => ['Выиграй матч', 'wins', 1, 30],
    'math15' => ['15 верных ответов по математике', 'c_math', 15, 25],
    'rus15' => ['15 верных ответов по русскому языку', 'c_rus', 15, 25],
    't1' => ['Пройди контрольную', 'tests', 1, 40],
    'sharp' => ['Сыграй матч с точностью от 80%', 'sharp', 1, 30],
];

/** [иконка, название, как получить, показатель, нужно] */
const BADGES = [
    'first' => ['🏁', 'Первый матч', 'Сыграй первый матч', 'matches', 1],
    'c100' => ['🎯', '100 верных', '100 верных ответов', 'correct', 100],
    'c500' => ['🧠', 'Умник', '500 верных ответов', 'correct', 500],
    'c2000' => ['🌟', 'Профи', '2000 верных ответов', 'correct', 2000],
    'w10' => ['🏅', 'Победитель', '10 побед в матчах', 'wins', 10],
    'both' => ['📚', 'Универсал', 'Сыграй и математику, и русский язык', 'subjects', 2],
    'test5' => ['📝', 'Отличник', 'Контрольная на «5»', 'tests5', 1],
    'clean' => ['💯', 'Без единой ошибки', 'Контрольная без ошибок', 'clean', 1],
    'duel1' => ['⚔️', 'Дуэлянт', 'Сыграй дуэль с живым игроком', 'duels', 1],
    'dw5' => ['🥇', 'Король дуэлей', '5 побед в дуэлях', 'duelWins', 5],
    's3' => ['🔥', 'Огонёк', '3 дня подряд', 'streakBest', 3],
    's7' => ['🗓️', 'Неделя подряд', '7 дней подряд', 'streakBest', 7],
    's30' => ['🌋', 'Месяц подряд', '30 дней подряд', 'streakBest', 30],
    'q3' => ['✅', 'Любитель заданий', 'Все задания дня выполнены 3 раза', 'allDays', 3],
    'l5' => ['🎓', 'Пятый уровень', 'Достигни 5 уровня', 'level', 5],
    'l10' => ['👑', 'Десятый уровень', 'Достигни 10 уровня', 'level', 10],
];

/** Закрытые аватары: аватар => [показатель, нужно, подсказка]. Остальные из первых FREE_AVATARS открыты всегда. */
const AVATAR_LOCKS = [
    '🦄' => ['level', 3, 'Уровень 3'], '🐙' => ['level', 4, 'Уровень 4'], '🚀' => ['level', 5, 'Уровень 5'],
    '⭐' => ['streakBest', 3, 'Серия 3 дня'], '🔥' => ['streakBest', 7, 'Серия 7 дней'], '⚡' => ['correct', 100, '100 верных ответов'],
    '🎯' => ['wins', 5, '5 побед в матчах'], '🎮' => ['duels', 1, 'Сыграй дуэль'], '🦉' => ['level', 6, 'Уровень 6'],
    '🐺' => ['duelWins', 3, '3 победы в дуэлях'], '🐲' => ['level', 8, 'Уровень 8'], '🦖' => ['streakBest', 14, 'Серия 14 дней'],
    '🛹' => ['level', 7, 'Уровень 7'], '🎸' => ['allDays', 3, 'Все задания дня 3 раза'],
];

function mskDay(int $ts): int { return intdiv($ts + MSK_OFFSET, 86400); }
/** День активности: когда играл. Офлайн-матч, пришедший позже двух суток, засчитывается днём получения (прошлое задним числом не нарисовать). */
function activityDay(int $played, int $created): int { return mskDay($created - $played < 172800 ? min($played, $created) : $created); }

function xpForLevel(int $l): int { return 40 * $l * ($l - 1); }
function levelOf(int $xp): int { $l = 1; while ($l < MAX_LEVEL && xpForLevel($l + 1) <= $xp) $l++; return $l; }
function titleOf(int $l): string { return TITLES[min($l, 10)]; }
function testGrade(int $correct, int $total): int { $p = $total > 0 ? $correct / $total : 0; return $p >= 0.9 ? 5 : ($p >= 0.75 ? 4 : ($p >= 0.5 ? 3 : 2)); }

/** Три задания дня: у каждого игрока свои, на каждый день одни и те же (считаются заново без хранения) */
function questsFor(int $uid, int $day): array {
    $ids = array_keys(QUESTS);
    usort($ids, fn($a, $b) => crc32("$uid:$day:$a") <=> crc32("$uid:$day:$b"));
    $pick = [];
    foreach ($ids as $id) {
        if (($id === 'c20' && in_array('c40', $pick, true)) || ($id === 'c40' && in_array('c20', $pick, true))) continue;
        $pick[] = $id;
        if (count($pick) === 3) break;
    }
    return $pick;
}

function emptyDay(): array { return ['matches' => 0, 'correct' => 0, 'wins' => 0, 'c_math' => 0, 'c_rus' => 0, 'tests' => 0, 'sharp' => 0]; }

/** Весь прогресс игрока. $now подменяется в тестах. */
function computeProgress(int $uid, ?int $now = null): array {
    $now ??= time();
    $today = mskDay($now);
    $days = [];                // день => показатели за день
    $xp = 0; $t = ['matches' => 0, 'wins' => 0, 'correct' => 0, 'duels' => 0, 'duelWins' => 0, 'tests' => 0, 'tests5' => 0, 'clean' => 0];
    $subjects = [];

    foreach (all('SELECT played_at, created_at, subject, correct, wrong, timeouts, outcome FROM results WHERE user_id = ? LIMIT 20000', [$uid]) as $r) {
        $d = activityDay((int)$r['played_at'], (int)$r['created_at']);
        $days[$d] ??= emptyDay();
        $c = (int)$r['correct']; $win = $r['outcome'] === 'win';
        $att = $c + (int)$r['wrong'] + (int)$r['timeouts'];
        $xp += $c + 5 + ($win ? 5 : 0);
        $days[$d]['matches']++; $days[$d]['correct'] += $c; $days[$d]['c_' . $r['subject']] += $c;
        if ($win) $days[$d]['wins']++;
        if ($att > 0 && $c >= 10 && $c / $att >= 0.8) $days[$d]['sharp']++;
        $t['matches']++; $t['correct'] += $c; if ($win) $t['wins']++;
        $subjects[$r['subject']] = true;
    }
    foreach (all('SELECT played_at, subject, score, outcome FROM duel_results WHERE user_id = ? LIMIT 20000', [$uid]) as $r) {
        $d = mskDay((int)$r['played_at']);
        $days[$d] ??= emptyDay();
        $win = $r['outcome'] === 'win';
        $xp += 10 + ($win ? 15 : 0) + intdiv((int)$r['score'], 5);
        $days[$d]['matches']++; if ($win) $days[$d]['wins']++;
        $t['duels']++; if ($win) $t['duelWins']++;
        $subjects[$r['subject']] = true;
    }
    foreach (all('SELECT played_at, created_at, subject, total, correct, wrong FROM test_results WHERE user_id = ? LIMIT 20000', [$uid]) as $r) {
        $d = activityDay((int)$r['played_at'], (int)$r['created_at']);
        $days[$d] ??= emptyDay();
        $c = (int)$r['correct']; $tot = (int)$r['total']; $g = testGrade($c, $tot);
        $xp += $c + 10 + ($g === 5 ? 20 : ($g === 4 ? 10 : ($g === 3 ? 5 : 0)));
        $days[$d]['tests']++; $days[$d]['correct'] += $c; $days[$d]['c_' . $r['subject']] += $c;
        $t['tests']++; $t['correct'] += $c; if ($g === 5) $t['tests5']++;
        if ((int)$r['wrong'] === 0 && $c === $tot && $tot >= 20) $t['clean']++;
        $subjects[$r['subject']] = true;
    }

    // серия: подряд идущие дни; каждые 7 дней серии дают «заморозку» (до двух), она закрывает один пропущенный день
    $active = array_keys($days); sort($active);
    $streak = 0; $best = 0; $freezes = 0; $prev = null;
    foreach ($active as $d) {
        if ($prev === null) $streak = 1;
        else {
            $gap = $d - $prev;
            if ($gap === 1) $streak++;
            elseif ($gap === 2 && $freezes > 0) { $freezes--; $streak++; }
            else $streak = 1;
        }
        if ($streak % 7 === 0) $freezes = min(2, $freezes + 1);
        $best = max($best, $streak);
        $prev = $d;
    }
    $alive = $prev !== null && ($today - $prev <= 1 || ($today - $prev === 2 && $freezes > 0));

    // задания дня: награда за выполненные в любой день, плюс бонус за все три
    $allDays = 0;
    foreach ($days as $d => $st) {
        $done = 0;
        foreach (questsFor($uid, $d) as $qid) {
            [, $key, $need, $reward] = QUESTS[$qid];
            if ($st[$key] >= $need) { $xp += $reward; $done++; }
        }
        if ($done === 3) { $xp += QUEST_DAY_BONUS; $allDays++; }
    }
    $level = levelOf($xp);

    $stats = $t + ['streakBest' => $best, 'allDays' => $allDays, 'level' => $level, 'subjects' => count($subjects)];

    $dayNow = $days[$today] ?? emptyDay();
    $quests = []; $doneNow = 0;
    foreach (questsFor($uid, $today) as $qid) {
        [$text, $key, $need, $reward] = QUESTS[$qid];
        $have = min($dayNow[$key], $need);
        $done = $have >= $need; if ($done) $doneNow++;
        $quests[] = ['id' => $qid, 'text' => $text, 'have' => $have, 'need' => $need, 'xp' => $reward, 'done' => $done];
    }

    $badges = [];
    foreach (BADGES as $id => [$icon, $name, $how, $key, $need]) {
        $have = min((int)$stats[$key], $need);
        $badges[] = ['id' => $id, 'icon' => $icon, 'name' => $name, 'how' => $how, 'have' => $have, 'need' => $need, 'unlocked' => $have >= $need];
    }
    $avatars = [];
    foreach (AVATARS as $i => $a) {
        if ($i < FREE_AVATARS || !isset(AVATAR_LOCKS[$a])) { $avatars[] = ['a' => $a, 'unlocked' => true]; continue; }
        [$key, $need, $hint] = AVATAR_LOCKS[$a];
        $avatars[] = ['a' => $a, 'unlocked' => (int)$stats[$key] >= $need, 'need' => $hint];
    }

    return [
        'day' => $today, 'xp' => $xp, 'level' => $level, 'title' => titleOf($level), 'levelXp' => xpForLevel($level), 'nextXp' => $level >= MAX_LEVEL ? null : xpForLevel($level + 1),
        'streak' => ['current' => $alive ? $streak : 0, 'best' => $best, 'playedToday' => $prev === $today, 'freezes' => $freezes],
        'quests' => ['items' => $quests, 'done' => $doneNow, 'allDone' => $doneNow === 3, 'bonus' => QUEST_DAY_BONUS],
        'badges' => $badges, 'avatars' => $avatars,
        'totals' => ['matches' => $t['matches'], 'wins' => $t['wins'], 'correct' => $t['correct'], 'duels' => $t['duels'], 'duelWins' => $t['duelWins'], 'tests' => $t['tests']],
    ];
}

/** Открыт ли аватар игроку (первые открыты всегда) */
function avatarUnlocked(int $uid, string $avatar): bool {
    $i = array_search($avatar, AVATARS, true);
    if ($i === false) return false;
    if ($i < FREE_AVATARS || !isset(AVATAR_LOCKS[$avatar])) return true;
    foreach (computeProgress($uid)['avatars'] as $a) if ($a['a'] === $avatar) return $a['unlocked'];
    return false;
}

function progress(array $req): array { return [200, computeProgress((int)requireUser($req)['id'])]; }
