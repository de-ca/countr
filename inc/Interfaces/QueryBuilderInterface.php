<?php
/**
 * QueryBuilderInterface - Fluent SQL query builder contract.
 *
 * @package Countr\Interfaces
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Interfaces;

interface QueryBuilderInterface
{
    /**
     * Insert a row into a table.
     *
     * @param string $table Table name
     * @param array  $data  Associative array of column => value
     * @return int|string   Last inserted ID, or 0 on failure
     */
    public function insert(string $table, array $data);

    /**
     * Update rows in a table.
     *
     * @param string $table Table name
     * @param array  $data  Column => value pairs to set
     * @param array  $where Conditions as ['column' => 'value'] (AND only)
     * @return int          Number of affected rows
     */
    public function update(string $table, array $data, array $where): int;

    /**
     * Delete rows from a table.
     *
     * @param string $table Table name
     * @param array  $where Conditions as ['column' => 'value'] (AND only)
     * @return int          Number of deleted rows
     */
    public function delete(string $table, array $where): int;

    /**
     * Select rows from a table with optional conditions and limit.
     *
     * @param string     $table      Table name
     * @param array|null $conditions Conditions as ['column' => 'value'] (AND only)
     * @param int|null   $limit      Maximum rows to return
     * @param string     $orderBy    ORDER BY expression
     * @return array
     */
    public function select(string $table, ?array $conditions = null, ?int $limit = null, string $orderBy = ''): array;

    /**
     * Check if a row exists matching conditions.
     *
     * @param string $table
     * @param array  $conditions
     * @return bool
     */
    public function exists(string $table, array $conditions): bool;

    /**
     * Count rows matching conditions.
     *
     * @param string     $table
     * @param array|null $conditions
     * @return int
     */
    public function count(string $table, ?array $conditions = null): int;
}