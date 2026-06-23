<?php
/**
 * QueryBuilder - Fluent SQL query builder with prepared statements and simple CRUD.
 *
 * Depends on a Connection instance (injected) for PDO access.
 * Implements QueryBuilderInterface.
 *
 * @package Countr\Core\Database
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Core\Database;

use PDO;
use PDOException;
use Countr\Interfaces\QueryBuilderInterface;

class QueryBuilder implements QueryBuilderInterface
{
    /** @var Connection Underlying connection */
    private Connection $connection;

    /** @var array<int, array> Query log for debugging */
    private array $queryLog = [];

    /** @var bool Enable query logging */
    private bool $enableQueryLog = false;

    /** @var float|null Last query execution time in seconds (raw) */
    private ?float $lastQueryTime = null;

    // =========================================================================
    // CONSTRUCTOR (Dependency Injection)
    // =========================================================================

    /**
     * @param Connection $connection Database connection (injected)
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    // =========================================================================
    // QUERY EXECUTION
    // =========================================================================

    /**
     * Execute a SELECT query and return all matching rows.
     *
     * @param string $sql    SQL query with optional named (:param) or positional (?) placeholders
     * @param array  $params Query parameters
     * @return array         Result rows (empty array on failure)
     */
    public function query(string $sql, array $params = []): array
    {
        $this->connection->ensureConnected();
        $pdo = $this->connection->getPDO();

        if ($pdo === null) {
            return [];
        }

        try {
            $startTime = microtime(true);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->lastQueryTime = microtime(true) - $startTime;

            if ($this->enableQueryLog) {
                $this->queryLog[] = [
                    'sql'       => $sql,
                    'params'    => $params,
                    'time'      => round($this->lastQueryTime * 1000, 2),
                    'rows'      => count($result),
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
            }

            return $result !== false ? $result : [];

        } catch (PDOException $e) {
            $this->connection->setLastError("Query failed: {$e->getMessage()} [SQL: {$sql}]");
            $this->log($this->connection->getLastError());
            return [];
        }
    }

    /**
     * Execute a query and return a single row.
     *
     * @param string $sql
     * @param array  $params
     * @return array|null Null if no row found or error
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        $rows = $this->query($sql, $params);
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Execute a query and return a single scalar value (first column of first row).
     *
     * @param string $sql
     * @param array  $params
     * @param mixed  $default Default value if no result
     * @return mixed
     */
    public function queryScalar(string $sql, array $params = [], $default = null)
    {
        $row = $this->queryOne($sql, $params);
        if ($row === null || empty($row)) {
            return $default;
        }
        return reset($row);
    }

    /**
     * Execute a non-SELECT query (INSERT, UPDATE, DELETE, DDL).
     *
     * @param string $sql    SQL statement
     * @param array  $params Query parameters
     * @return int           Number of affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        $this->connection->ensureConnected();
        $pdo = $this->connection->getPDO();

        if ($pdo === null) {
            return 0;
        }

        try {
            $startTime = microtime(true);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $affected = $stmt->rowCount();

            $this->lastQueryTime = microtime(true) - $startTime;

            if ($this->enableQueryLog) {
                $this->queryLog[] = [
                    'sql'       => $sql,
                    'params'    => $params,
                    'time'      => round($this->lastQueryTime * 1000, 2),
                    'affected'  => $affected,
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
            }

            return $affected;

        } catch (PDOException $e) {
            $this->connection->setLastError("Execute failed: {$e->getMessage()} [SQL: {$sql}]");
            $this->log($this->connection->getLastError());
            return 0;
        }
    }

    /**
     * Execute a query and return the last inserted ID.
     *
     * @param string $sql
     * @param array  $params
     * @return int|string Last insert ID, or 0 on failure
     */
    public function insertAndGetId(string $sql, array $params = [])
    {
        $this->connection->ensureConnected();
        $pdo = $this->connection->getPDO();

        if ($pdo === null) {
            return 0;
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $pdo->lastInsertId();
        } catch (PDOException $e) {
            $this->connection->setLastError("Insert failed: {$e->getMessage()} [SQL: {$sql}]");
            $this->log($this->connection->getLastError());
            return 0;
        }
    }

    // =========================================================================
    // QUERY BUILDER HELPERS (Simple CRUD)
    // =========================================================================

    /**
     * Insert a row into a table.
     *
     * @param string $table Table name
     * @param array  $data  Associative array of column => value
     * @return int|string   Last inserted ID, or 0 on failure
     */
    public function insert(string $table, array $data)
    {
        if (empty($data)) {
            return 0;
        }

        $columns = array_keys($data);
        $placeholders = array_map(function ($col) {
            return ':' . $col;
        }, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders)
        );

        $params = [];
        foreach ($data as $col => $val) {
            $params[':' . $col] = $val;
        }

        return $this->insertAndGetId($sql, $params);
    }

    /**
     * Update rows in a table.
     *
     * @param string $table Table name
     * @param array  $data  Column => value pairs to set
     * @param array  $where Conditions as ['column' => 'value'] (AND only)
     * @return int          Number of affected rows
     */
    public function update(string $table, array $data, array $where): int
    {
        if (empty($data) || empty($where)) {
            return 0;
        }

        $setClauses = [];
        $params = [];

        foreach ($data as $col => $val) {
            $placeholder = ':set_' . $col;
            $setClauses[] = $this->quoteIdentifier($col) . ' = ' . $placeholder;
            $params[$placeholder] = $val;
        }

        $whereClauses = [];
        foreach ($where as $col => $val) {
            $placeholder = ':where_' . $col;
            $whereClauses[] = $this->quoteIdentifier($col) . ' = ' . $placeholder;
            $params[$placeholder] = $val;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(', ', $setClauses),
            implode(' AND ', $whereClauses)
        );

        return $this->execute($sql, $params);
    }

    /**
     * Delete rows from a table.
     *
     * @param string $table Table name
     * @param array  $where Conditions as ['column' => 'value'] (AND only)
     * @return int          Number of deleted rows
     */
    public function delete(string $table, array $where): int
    {
        if (empty($where)) {
            return 0;
        }

        $whereClauses = [];
        $params = [];

        foreach ($where as $col => $val) {
            $placeholder = ':where_' . $col;
            $whereClauses[] = $this->quoteIdentifier($col) . ' = ' . $placeholder;
            $params[$placeholder] = $val;
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(' AND ', $whereClauses)
        );

        return $this->execute($sql, $params);
    }

    /**
     * Select rows from a table with optional conditions and limit.
     *
     * @param string     $table      Table name
     * @param array|null $conditions Conditions as ['column' => 'value'] (AND only)
     * @param int|null   $limit      Maximum rows to return
     * @param string     $orderBy    ORDER BY expression
     * @return array
     */
    public function select(string $table, ?array $conditions = null, ?int $limit = null, string $orderBy = ''): array
    {
        $sql = 'SELECT * FROM ' . $this->quoteIdentifier($table);
        $params = [];

        if (!empty($conditions)) {
            $whereClauses = [];
            foreach ($conditions as $col => $val) {
                $placeholder = ':cond_' . $col;
                $whereClauses[] = $this->quoteIdentifier($col) . ' = ' . $placeholder;
                $params[$placeholder] = $val;
            }
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        if (!empty($orderBy)) {
            $sql .= ' ORDER BY ' . $orderBy;
        }

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
        }

        return $this->query($sql, $params);
    }

    /**
     * Check if a row exists matching conditions.
     *
     * @param string $table
     * @param array  $conditions
     * @return bool
     */
    public function exists(string $table, array $conditions): bool
    {
        $sql = 'SELECT COUNT(*) as cnt FROM ' . $this->quoteIdentifier($table);
        $params = [];

        if (!empty($conditions)) {
            $whereClauses = [];
            foreach ($conditions as $col => $val) {
                $placeholder = ':cond_' . $col;
                $whereClauses[] = $this->quoteIdentifier($col) . ' = ' . $placeholder;
                $params[$placeholder] = $val;
            }
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        $count = (int)$this->queryScalar($sql, $params, 0);
        return $count > 0;
    }

    /**
     * Count rows matching conditions.
     *
     * @param string     $table
     * @param array|null $conditions
     * @return int
     */
    public function count(string $table, ?array $conditions = null): int
    {
        $sql = 'SELECT COUNT(*) as cnt FROM ' . $this->quoteIdentifier($table);
        $params = [];

        if (!empty($conditions)) {
            $whereClauses = [];
            foreach ($conditions as $col => $val) {
                $placeholder = ':cond_' . $col;
                $whereClauses[] = $this->quoteIdentifier($col) . ' = ' . $placeholder;
                $params[$placeholder] = $val;
            }
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }

        return (int)$this->queryScalar($sql, $params, 0);
    }

    // =========================================================================
    // QUERY LOGGING & DIAGNOSTICS
    // =========================================================================

    /**
     * Enable or disable query logging.
     *
     * @param bool $enable
     * @return self
     */
    public function setQueryLogEnabled(bool $enable): self
    {
        $this->enableQueryLog = $enable;
        return $this;
    }

    /**
     * Get the query execution log.
     *
     * @return array
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    /**
     * Get the last query execution time in milliseconds.
     *
     * @return float|null
     */
    public function getLastQueryTime(): ?float
    {
        return $this->lastQueryTime !== null ? round($this->lastQueryTime * 1000, 2) : null;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Quote an identifier (table name, column name) for safe SQL usage.
     *
     * @param string $identifier
     * @return string
     */
    private function quoteIdentifier(string $identifier): string
    {
        $identifier = str_replace(['"', "'", ';', '`'], '', $identifier);
        return '"' . $identifier . '"';
    }

    /**
     * Log a message to the PHP error log.
     *
     * @param string $message
     */
    private function log(string $message): void
    {
        error_log('[Countr QueryBuilder] ' . date('Y-m-d H:i:s') . ' ' . $message);
    }
}