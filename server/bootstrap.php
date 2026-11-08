<?php
declare(strict_types=1);
namespace S2G;

const ROOT = __DIR__ . '/..';

function env(string $key, string $default = ''): string {
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $file = getenv('S2G_ENV_FILE') ?: ROOT . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $vars[trim($k)] = trim($v, " \t\"'");
            }
        }
    }
    return $vars[$key] ?? (getenv($key) !== false ? (string)getenv($key) : $default);
}

function db(): \PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (driver() === 'sqlite') {
        $path = env('DB_PATH', ROOT . '/server/data/s2g.sqlite');
        if ($path !== ':memory:' && !is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = 'mysql:host=' . env('DB_HOST', 'localhost') . ';dbname=' . env('DB_NAME') . ';charset=utf8mb4';
        $pdo = new \PDO($dsn, env('DB_USER'), env('DB_PASS'));
        $pdo->exec("SET time_zone = '+00:00'");
    }
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    return $pdo;
}
function driver(): string { return env('DB_DRIVER', 'mysql') === 'sqlite' ? 'sqlite' : 'mysql'; }

function q(string $sql, array $args = []): \PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}
function one(string $sql, array $args = []): ?array { $r = q($sql, $args)->fetch(); return $r ?: null; }
function all(string $sql, array $args = []): array { return q($sql, $args)->fetchAll(); }
/** INSERT, который молча пропускает дубликат; возвращает число вставленных строк */
function insertIgnore(string $table, array $row): int {
    $cols = implode(',', array_keys($row));
    $ph = implode(',', array_fill(0, count($row), '?'));
    $verb = driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    return q("$verb INTO $table ($cols) VALUES ($ph)", array_values($row))->rowCount();
}

function hashToken(string $t): string { return hash('sha256', $t); }

/** Бросается из обработчиков, превращается в JSON-ошибку */
final class HttpError extends \RuntimeException {
    public function __construct(public int $status, string $msg, public array $extra = []) { parent::__construct($msg); }
}
function fail(int $status, string $msg, array $extra = []): never { throw new HttpError($status, $msg, $extra); }
