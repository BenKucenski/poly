<?php
declare(strict_types=1);

namespace common\Poly;

use QuickDRY\Connectors\mysql\MySQL_A;
use QuickDRY\Connectors\QueryExecuteResult;
use RuntimeException;

/**
 * Thin wrapper over QuickDRY's MySQL_A connection that throws on SQL errors,
 * so a failed write stops the market instead of silently losing data.
 */
class Store
{
    /**
     * @param string $sql
     * @param array|null $params
     * @return array rows
     */
    public static function Rows(string $sql, ?array $params = null): array
    {
        $res = MySQL_A::Query($sql, $params);
        if (!empty($res['error'])) {
            throw new RuntimeException('SQL error: ' . $res['error'] . PHP_EOL . $res['sql']);
        }
        return $res['data'] ?? [];
    }

    /**
     * @param string $sql
     * @param array|null $params
     * @return array|null first row
     */
    public static function Row(string $sql, ?array $params = null): ?array
    {
        return self::Rows($sql, $params)[0] ?? null;
    }

    /**
     * @param string $sql
     * @param array|null $params
     * @return QueryExecuteResult
     */
    public static function Exec(string $sql, ?array $params = null): QueryExecuteResult
    {
        $res = MySQL_A::Execute($sql, $params);
        if ($res->error) {
            throw new RuntimeException('SQL error: ' . $res->error . PHP_EOL . substr((string)$res->sql, 0, 2000));
        }
        return $res;
    }

    /**
     * Multi-row INSERT, in chunks.
     *
     * @param string $table
     * @param string[] $columns
     * @param array $rows each row is an associative array keyed by column
     * @param string $suffix e.g. an ON DUPLICATE KEY UPDATE clause
     * @param int $chunk rows per statement
     * @param bool $ignore INSERT IGNORE (skip rows that hit a duplicate key)
     * @return void
     */
    public static function InsertMany(string $table, array $columns, array $rows, string $suffix = '', int $chunk = 200, bool $ignore = false): void
    {
        foreach (array_chunk($rows, $chunk) as $batch) {
            $values = [];
            $params = [];
            foreach ($batch as $r => $row) {
                $ph = [];
                foreach ($columns as $c => $column) {
                    $name = 'p' . $r . '_' . $c;
                    $ph[] = ':' . $name;
                    $params[$name] = self::Value($row[$column] ?? null);
                }
                $values[] = '(' . implode(', ', $ph) . ')';
            }

            $sql = 'INSERT ' . ($ignore ? 'IGNORE ' : '') . 'INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES '
                . PHP_EOL . implode(',' . PHP_EOL, $values)
                . ($suffix ? PHP_EOL . $suffix : '');

            self::Exec($sql, $params);
        }
    }

    /**
     * QuickDRY's escaper quotes strings and writes null for nulls; booleans become 1/0 here.
     *
     * @param mixed $v
     * @return mixed
     */
    private static function Value(mixed $v): mixed
    {
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        return $v;
    }

    /**
     * @param int|null $ts
     * @return string UTC 'Y-m-d H:i:s'
     */
    public static function Time(?int $ts = null): string
    {
        return gmdate('Y-m-d H:i:s', $ts ?? time());
    }

    /**
     * @param mixed $value an ISO date string from the API
     * @return string|null UTC 'Y-m-d H:i:s'
     */
    public static function ParseTime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : self::Time($ts);
    }

    /**
     * @param string $name
     * @return string|null
     */
    public static function GetState(string $name): ?string
    {
        $row = self::Row('SELECT value FROM collector_state WHERE name = :name', ['name' => $name]);
        return $row['value'] ?? null;
    }

    /**
     * @param string $name
     * @param string|null $value
     * @return void
     */
    public static function SetState(string $name, ?string $value): void
    {
        self::Exec('
INSERT INTO collector_state (name, value, updated_at) VALUES (:name, :value, :updated_at)
ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)
', ['name' => $name, 'value' => $value, 'updated_at' => self::Time()]);
    }
}
