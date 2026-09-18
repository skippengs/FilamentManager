<?php
declare(strict_types=1);
if (!defined('FILAMENT')) { http_response_code(403); exit('Forbidden'); }

/**
 * Replaces {filament} with the real table name, prefix included.
 *
 * This keeps the queries full of readable names while still allowing the
 * database to be shared with another site by setting DB_PREFIX.
 */
function sqlTables(string $sql): string
{
    return preg_replace_callback(
        '/\{(\w+)\}/',
        static fn(array $m): string => DB_PREFIX . $m[1],
        $sql
    ) ?? $sql;
}

/**
 * PDO that deals with those braces itself, so no single call has to
 * remember to.
 */
final class Db extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return parent::query(sqlTables($query), $fetchMode, ...$fetchModeArgs);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(sqlTables($query), $options);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(sqlTables($statement));
    }
}

function db(): Db
{
    static $pdo = null;
    if ($pdo instanceof Db) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

    try {
        $pdo = new Db($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);

        // No details on the server, but do show them locally.
        if (defined('DEBUG') && DEBUG) {
            exit('No database connection: ' . $e->getMessage());
        }

        exit('No database connection. Check inc/config.php, or run install.php.');
    }

    return $pdo;
}
