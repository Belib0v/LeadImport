<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/** All database work: connection, transactions and inserts. */
final class Database
{
    private readonly PDO $pdo;

    public function __construct(string $dsn, string $user, string $password)
    {
        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /** Connection settings from DB_DSN, DB_USER and DB_PASS. */
    public static function fromEnv(): self
    {
        return new self(
            getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=lead_import;charset=utf8mb4',
            getenv('DB_USER') ?: 'lead_import',
            getenv('DB_PASS') ?: '',
        );
    }

    /**
     * Runs $callback in a transaction: commits when it returns, rolls back when it throws.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Inserts many rows with one multi-row INSERT. A row that hits a UNIQUE key is skipped.
     *
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows column => value; a missing column is inserted as NULL
     * @return int number of rows actually inserted
     */
    public function insertSkippingDuplicates(string $table, array $columns, array $rows): int
    {
        if (!$rows) {
            return 0;
        }
        $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        // "UPDATE x = x" changes nothing: a duplicate is skipped and not counted as affected.
        // Unlike INSERT IGNORE, it does not hide other errors such as a value that does not fit.
        $sql = sprintf(
            'INSERT INTO `%1$s` (`%2$s`) VALUES %3$s ON DUPLICATE KEY UPDATE `%4$s` = `%4$s`',
            $table,
            implode('`,`', $columns),
            implode(',', array_fill(0, count($rows), $placeholders)),
            $columns[0],
        );

        $params = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $params[] = $row[$column] ?? null;
            }
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }
}
