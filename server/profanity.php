<?php
declare(strict_types=1);
namespace S2G;

/**
 * Фильтр ников: мат, оскорбления, взрослые темы, служебные имена.
 * Ловит обход: регистр, ё/е, латиница вместо кириллицы, цифры-«леет», разделители, повторы букв, транслит.
 * Это сито, а не гарантия: список лежит ниже и дополняется по жалобам. Тест: tests/profanity.test.php.
 */

const LOOKALIKE = ['a' => 'а', 'b' => 'в', 'c' => 'с', 'e' => 'е', 'h' => 'н', 'k' => 'к', 'm' => 'м', 'o' => 'о', 'p' => 'р', 't' => 'т', 'x' => 'х', 'y' => 'у'];
const LEET_CYR = ['0' => 'о', '1' => 'и', '3' => 'з', '4' => 'а', '6' => 'б'];
const LEET_LAT = ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i'];

/** Образ строки для проверки кириллицей: слова через пробел, повторы букв схлопнуты */
function profanityCyr(string $s): string {
    $s = mb_strtolower($s);
    $s = strtr($s, ['ё' => 'е', 'й' => 'й']);
    $s = strtr($s, LOOKALIKE);
    $s = strtr($s, LEET_CYR);
    $s = preg_replace('/[^а-я]+/u', ' ', $s) ?? '';
    $s = preg_replace('/(.)\1+/u', '$1', $s) ?? '';
    return trim($s);
}
/** Образ строки латиницей (английский мат и транслит русского) */
function profanityLat(string $s): string {
    $s = mb_strtolower($s);
    $s = strtr($s, LEET_LAT);
    $s = preg_replace('/[^a-z]+/', ' ', $s) ?? '';
    $s = preg_replace('/(.)\1+/', '$1', $s) ?? '';
    return trim($s);
}

/* Корни, опасные в любом месте слова (в «склеенной» строке без пробелов) */
const BAD_ANYWHERE_CYR = [
    'ху[йеяию]', 'пизд', 'пезд', 'бляд', 'блят', 'блеат', 'залуп', 'мудак', 'мудил', 'мудозвон', 'гандон', 'гондон', 'шлюх', 'шлюш',
    'пидор', 'пидр', 'педераст', 'долбоеб', 'уебан', 'уебок', 'еблан', 'ебан', 'ебуч', 'заеб', 'выеб', 'съеб',
    'говн', 'дерьм', 'дроч', 'проститут', 'сучар', 'сучк', 'гитлер', 'нацист', 'фашист', 'свастик', 'наркот', 'героин', 'суицид',
    'сиськ', 'письк', 'пенис',
];
/* Слова, которые плохи только как отдельное слово (иначе много ложных срабатываний: «хлеб», «небо», «Сукачёв») */
const BAD_WORD_CYR = [
    'бля', 'бл', 'пидар', 'пидары', 'пидарас', 'педик', 'педики', 'сука', 'суки', 'сучка', 'жопа', 'жопу', 'срака', 'срать', 'сать', 'хер', 'хрен', 'член', 'секс', 'порно', 'трах', 'трахать',
    'ебать', 'ебал', 'ебу', 'ебут', 'ебаный', 'еб', 'ебло', 'жид', 'хач', 'негр', 'чурка', 'чурок', 'чмо', 'мразь', 'мразота', 'тварь',
    'урод', 'дебил', 'идиот', 'тупица', 'лох', 'ублюдок', 'кретин', 'шлюха', 'гей', 'лесбиянка',
];
const BAD_ANYWHERE_LAT = [
    'fuck', 'shit', 'bitch', 'cunt', 'pussy', 'faggot', 'whore', 'asshole', 'dickhead', 'porn', 'hitler',
    'huy', 'xuy', 'hui', 'xui', 'pizd', 'pidor', 'pidar', 'pidr', 'blyad', 'blyat', 'eblan', 'zalup', 'mudak', 'gandon', 'gondon', 'dolboeb',
];
/* «Tsukasa», «Slutsky», «debate», «Nazim» не должны блокироваться, поэтому эти слова проверяются только целиком */
const BAD_WORD_LAT = ['sex', 'dick', 'cock', 'ass', 'tit', 'tits', 'fag', 'gay', 'slut', 'nazi', 'suka', 'sooka', 'blya', 'ebat', 'ebal', 'ebu', 'xer'];
/* Имена, под которые нельзя маскироваться */
const RESERVED = ['админ', 'администратор', 'модератор', 'учитель', 'учительница', 'школа', 'система', 'бот', 'поддержка', 'школа2', 'admin', 'administrator', 'moderator', 'teacher', 'school2game', 'system', 'support', 'bot'];

function nickNotAllowed(string $nick): bool {
    $cyr = profanityCyr($nick);
    $lat = profanityLat($nick);
    $cyrJoined = str_replace(' ', '', $cyr);
    $latJoined = str_replace(' ', '', $lat);
    if (preg_match('/(' . implode('|', BAD_ANYWHERE_CYR) . ')/u', $cyrJoined)) return true;
    if (preg_match('/(' . implode('|', BAD_ANYWHERE_LAT) . ')/', $latJoined)) return true;
    // «nigger» после схлопывания повторов превращается в «niger» (страна), поэтому смотрим исходный вид
    if (str_contains(preg_replace('/[^a-z]+/', '', strtr(mb_strtolower($nick), LEET_LAT)) ?? '', 'nigg')) return true;
    foreach (explode(' ', $cyr) as $w) if ($w !== '' && in_array($w, BAD_WORD_CYR, true)) return true;
    foreach (explode(' ', $lat) as $w) if ($w !== '' && in_array($w, BAD_WORD_LAT, true)) return true;
    // «ебать»-группа: корень «еб» с приставками, только как начало слова
    foreach (explode(' ', $cyr) as $w) if (preg_match('/^(за|вы|съ|у|по|на|от|до|об|раз|рас|пере|при|под|недо|про|с|о)?еб(ат|ал|ну|ут|ыр|ис|ля|ло|уч)/u', $w)) return true;
    $plain = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $nick) ?? ''));
    $plainJoined = str_replace([' ', '_', '.', '-'], '', $plain);
    return in_array($plain, RESERVED, true) || in_array($plainJoined, RESERVED, true);
}
