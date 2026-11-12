<?php
declare(strict_types=1);
namespace S2G;

/* Зеркало констант игры (docs/artifact-version.html). Меняешь там, меняй и здесь. */
const SUBJECT_TOPICS = ['math' => ['mul', 'div'], 'rus' => ['vowel', 'stress', 'tsya']];
const LEVELS = ['easy', 'medium', 'hard'];
const SPORTS = ['football', 'basketball'];
const POINTS_PER_PLAYER = 50;
const MATCH_SECONDS = 120;
const MAX_GAIN = 5;          // ответ 1 + быстрый 1 + бросок 3
const MIN_ANSWER_SEC = 0.7;  // быстрее человек не отвечает
const MIN_MATCH_SEC = 8;
const TEST_SECONDS = 300;    // «Контрольная»
const WINDOW = 30;

function allTopics(): array { return array_merge(...array_values(SUBJECT_TOPICS)); }

function ival(mixed $v, int $min, int $max): ?int {
    if (is_bool($v) || !(is_int($v) || (is_float($v) && floor($v) == $v) || (is_string($v) && ctype_digit($v)))) return null;
    $n = (int)$v;
    return ($n < $min || $n > $max) ? null : $n;
}

/**
 * Проверка правдоподобности матча: клиенту доверять нельзя.
 * Возвращает [чистая запись, null] или [null, причина].
 */
function checkMatch(mixed $m, ?int $now = null): array {
    $now ??= time();
    if (!is_array($m)) return [null, 'bad_format'];
    $id = $m['id'] ?? null;
    if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) return [null, 'bad_id'];
    $subject = $m['subject'] ?? null;
    if (!is_string($subject) || !isset(SUBJECT_TOPICS[$subject])) return [null, 'bad_subject'];
    $topic = $m['topic'] ?? 'all';
    if (!is_string($topic) || !($topic === 'all' || $topic === 'weak' || in_array($topic, SUBJECT_TOPICS[$subject], true))) return [null, 'bad_topic'];
    $level = $m['level'] ?? null;
    if (!in_array($level, LEVELS, true)) return [null, 'bad_level'];
    $sport = $m['sport'] ?? null;
    if (!in_array($sport, SPORTS, true)) return [null, 'bad_sport'];
    $format = $m['format'] ?? null;
    if ($format !== '1x1' && $format !== '2x2') return [null, 'bad_format'];
    $team = $format === '2x2' ? 2 : 1;

    $you = ival($m['you'] ?? null, 0, 1000); $foe = ival($m['foe'] ?? null, 0, 1000);
    $correct = ival($m['correct'] ?? null, 0, 1000); $wrong = ival($m['wrong'] ?? null, 0, 1000);
    $timeouts = ival($m['timeouts'] ?? null, 0, 1000); $sec = ival($m['sec'] ?? null, 0, 1000);
    if (in_array(null, [$you, $foe, $correct, $wrong, $timeouts, $sec], true)) return [null, 'bad_number'];
    $t = ival($m['t'] ?? null, 1, PHP_INT_MAX);
    if ($t === null) return [null, 'bad_time'];
    $playedAt = intdiv($t, 1000);
    if ($playedAt > $now + 3600 || $playedAt < $now - 400 * 86400) return [null, 'bad_time'];

    if ($sec < MIN_MATCH_SEC || $sec > MATCH_SECONDS + 5) return [null, 'bad_duration'];
    $attempts = $correct + $wrong + $timeouts;
    if ($attempts > $sec / MIN_ANSWER_SEC + 3) return [null, 'too_fast'];
    // очки: каждый верный ответ не больше MAX_GAIN; в 2×2 к ним добавляются очки напарника-бота
    $botAnswers = $team === 2 ? intdiv($sec, 1) + 2 : 0;
    if ($you > MAX_GAIN * ($correct + $botAnswers)) return [null, 'too_many_points'];
    if ($you < $correct && $team === 1) return [null, 'too_few_points'];
    $target = POINTS_PER_PLAYER * $team;
    if (max($you, $foe) > $target + MAX_GAIN * 2 * $team) return [null, 'over_target'];
    // матч кончается по цели или по времени
    if (max($you, $foe) < $target && $sec < MATCH_SECONDS - 3) return [null, 'ended_early'];
    $outcome = $you > $foe ? 'win' : ($you < $foe ? 'lose' : 'draw');
    if (($m['outcome'] ?? null) !== $outcome) return [null, 'outcome_mismatch'];

    $by = $m['byTopic'] ?? [];
    if (!is_array($by)) return [null, 'bad_topics'];
    $sumOk = 0; $sumN = 0; $clean = [];
    foreach ($by as $tid => $b) {
        if (!is_string($tid) || !in_array($tid, SUBJECT_TOPICS[$subject], true) || !is_array($b)) return [null, 'bad_topics'];
        $n = ival($b['n'] ?? null, 0, 1000); $ok = ival($b['ok'] ?? null, 0, 1000);
        if ($n === null || $ok === null || $ok > $n) return [null, 'bad_topics'];
        $sumOk += $ok; $sumN += $n; $clean[$tid] = ['n' => $n, 'ok' => $ok];
    }
    // Игра пишет в тему только первый исход вопроса, а повторный верный ответ после ошибки идёт в `correct`:
    // поэтому верных по темам может быть меньше, чем верных ответов, но не больше.
    if ($sumOk > $correct || $sumN > $attempts || $correct > $sumN + $wrong) return [null, 'topics_mismatch'];

    return [[
        'id' => $id, 'played_at' => min($playedAt, $now), 'subject' => $subject, 'topic' => $topic, 'level' => $level,
        'sport' => $sport, 'format' => $format, 'you_pts' => $you, 'foe_pts' => $foe,
        'score' => intdiv($you + $team - 1, $team), // в рейтинг идут очки на игрока
        'outcome' => $outcome, 'correct' => $correct, 'wrong' => $wrong, 'timeouts' => $timeouts, 'sec' => $sec,
        'by_topic' => json_encode($clean, JSON_UNESCAPED_UNICODE),
    ], null];
}

/**
 * Проверка результата «Контрольной» (5 минут, весь материал темы). Идёт только в прогресс игрока, в рейтинг не попадает.
 * Возвращает [чистая запись, null] или [null, причина].
 */
function checkTest(mixed $m, ?int $now = null): array {
    $now ??= time();
    if (!is_array($m)) return [null, 'bad_format'];
    $id = $m['id'] ?? null;
    if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) return [null, 'bad_id'];
    $subject = $m['subject'] ?? null;
    if (!is_string($subject) || !isset(SUBJECT_TOPICS[$subject])) return [null, 'bad_subject'];
    $topic = $m['topic'] ?? 'all';
    if (!is_string($topic) || !($topic === 'all' || $topic === 'weak' || in_array($topic, SUBJECT_TOPICS[$subject], true))) return [null, 'bad_topic'];
    $total = ival($m['total'] ?? null, 1, 400); $correct = ival($m['correct'] ?? null, 0, 400);
    $wrong = ival($m['wrong'] ?? null, 0, 400); $sec = ival($m['sec'] ?? null, 0, 1000);
    if (in_array(null, [$total, $correct, $wrong, $sec], true)) return [null, 'bad_number'];
    $t = ival($m['t'] ?? null, 1, PHP_INT_MAX);
    if ($t === null) return [null, 'bad_time'];
    $playedAt = intdiv($t, 1000);
    if ($playedAt > $now + 3600 || $playedAt < $now - 400 * 86400) return [null, 'bad_time'];
    $answered = $correct + $wrong;
    if ($answered > $total) return [null, 'too_many_answers'];
    if ($sec < MIN_MATCH_SEC || $sec > TEST_SECONDS + 5) return [null, 'bad_duration'];
    if ($answered > $sec / MIN_ANSWER_SEC + 3) return [null, 'too_fast'];
    if ($answered < $total && $sec < TEST_SECONDS - 5) return [null, 'ended_early'];   // кончается, когда пройден весь материал или вышло время
    return [['id' => $id, 'played_at' => min($playedAt, $now), 'subject' => $subject, 'topic' => $topic, 'total' => $total, 'correct' => $correct, 'wrong' => $wrong, 'sec' => $sec], null];
}

/** Статистика темы: recent = [[0|1, секунды], ...] не длиннее WINDOW */
function cleanStat(mixed $s): ?array {
    if (!is_array($s) || !isset($s['recent']) || !is_array($s['recent'])) return null;
    $recent = [];
    foreach ($s['recent'] as $r) {
        if (!is_array($r) || count($r) < 2 || !is_numeric($r[1])) continue;
        $recent[] = [empty($r[0]) ? 0 : 1, round(max(0.0, min(60.0, (float)$r[1])), 1)];
    }
    $recent = array_slice($recent, -WINDOW);
    $total = ival($s['total'] ?? 0, 0, 10000000) ?? 0;
    return ['recent' => $recent, 'total' => max($total, count($recent))];
}

const AVATARS = ['⚽', '🏀', '🏆', '🦊', '🐼', '🐯', '🦁', '🐸', '🐵', '🐧', '🦄', '🐙', '🚀', '⭐', '🔥', '⚡', '🎯', '🎮', '🦉', '🐺', '🐲', '🦖', '🛹', '🎸'];

function cleanNick(mixed $nick): ?string {
    if (!is_string($nick)) return null;
    $nick = trim(preg_replace('/\s+/u', ' ', $nick) ?? '');
    $len = mb_strlen($nick);
    if ($len < 2 || $len > 16) return null;
    if (!preg_match('/^[\p{L}\p{N} _.\-]+$/u', $nick)) return null;
    return $nick;
}
