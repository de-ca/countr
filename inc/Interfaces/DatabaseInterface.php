<?php
/**
 * DatabaseInterface - Core database abstraction contract.
 *
 * Defines the public API that any database implementation must provide.
 *
 * @package Countr\Interfaces
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Interfaces;

interface DatabaseInterface
{
    /**
     * Connect to the database.
     *
     * @param string|null $dbPath Optional database path override
     * @return bool
     */
    public function connect(?string $dbPath = null): bool;

    /**
     * Disconnect from the database.
     *
     * @return void
     */
    public function disconnect(): void;

    /**
     * Check if the database connection is active.
     *
     * @return bool
     */
    public function isConnected(): bool;

    /**
     * Get the underlying PDO instance.
     *
     * @return \PDO|null
     */
    public function getPDO(): ?\PDO;

    /**
     * Get the database file path.
     *
     * @return string
     */
    public function getDbPath(): string;

    /**
     * Execute a SELECT query and return all matching rows.
     *
     * @param string $sql    SQL query with named or positional placeholders
     * @param array  $params Query parameters
     * @return array         Result rows (empty on failure)
     */
    public function query(string $sql, array $params = []): array;

    /**
     * Execute a query and return a single row.
     *
     * @param string $sql
     * @param array  $params
     * @return array|null
     */
    public function queryOne(string $sql, array $params = []): ?array;

    /**
     * Execute a query and return a single scalar value.
     *
     * @param string $sql
     * @param array  $params
     * @param mixed  $default
     * @return mixed
     */
    public function queryScalar(string $sql, array $params = [], $default = null);

    /**
     * Execute a non-SELECT query (INSERT, UPDATE, DELETE, DDL).
     *
     * @param string $sql
     * @param array  $params
     * @return int    Number of affected rows
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Execute a query and return the last inserted ID.
     *
     * @param string $sql
     * @param array  $params
     * @return int|string
     */
    public function insertAndGetId(string $sql, array $params = []);

    /**
     * Begin a transaction (supports savepoint nesting).
     *
     * @return bool
     */
    public function beginTransaction(): bool;

    /**
     * Commit the current transaction level.
     *
     * @return bool
     */
    public function commit(): bool;

    /**
     * Rollback the current transaction level.
     *
     * @return bool
     */
    public function rollback(): bool;

    /**
     * Execute a callback within a transaction.
     *
     * @param callable $callback
     * @return mixed
     */
    public function transaction(callable $callback);

    /**
     * Get the last error message.
     *
     * @return string|null
     */
    public function getLastError(): ?string;
}