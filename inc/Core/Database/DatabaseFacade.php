<?php
/**
 * DatabaseFacade - Backward-compatible wrapper that delegates to modular classes.
 *
 * Keeps the exact same public API as the legacy Database class while internally
 * using the new Connection, QueryBuilder, Transaction, Migration, and
 * AnalyticsQueries classes with proper dependency injection.
 *
 * This allows existing code (Tracker, Visitor, admin.php, tests, etc.) to
 * continue working without changes.
 *
 * @package Countr\Core\Database
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Core\Database;

use PDO;
use RuntimeException;
use Throwable;
use Countr\Interfaces\DatabaseInterface;

class DatabaseFacade implements DatabaseInterface
{
    /** @var DatabaseFacade|null Singleton instance */
    private static ?DatabaseFacade $instance = null;

    /** @var Connection Connection management */
    private Connection $connection;

    /** @var QueryBuilder Query execution & CRUD */
    private QueryBuilder $qb;

    /** @var Transaction Transaction management */
    private Transaction $transaction;

    /** @var Migration Schema migration */
    private Migration $migration;

    /** @var AnalyticsQueries Analytics-specific queries */
    private AnalyticsQueries $analytics;

    // =========================================================================
    // SINGLETON & CONSTRUCTOR
    // =========================================================================

    /**
     * Private constructor – use getInstance().
     *
     * @param string|null $dbPath    Path to SQLite database
     * @param string|null $backupDir Path to backup directory
     */
    private function __construct(?string $dbPath = null, ?string $backupDir = null)
    {
        // Build the new modular stack with DI
        $this->connection  = new Connection($dbPath, $backupDir);
        $this->qb          = new QueryBuilder($this->connection);
        $this->transaction = new Transaction($this->connection);
        $this->migration   = new Migration($this->qb, $this->connection);
        $this->analytics   = new AnalyticsQueries($this->qb);
    }

    /**
     * Get the singleton DatabaseFacade instance.
     *
     * @param string|null $dbPath    Optional database path (only used on first call)
     * @param string|null $backupDir Optional backup directory (only used on first call)
     * @return self
     */
    public static function getInstance(?string $dbPath = null, ?string $backupDir = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($dbPath, $backupDir);
        }
        return self::$instance;
    }

    // =========================================================================
    // CONNECTION MANAGEMENT (delegates to Connection)
    // =========================================================================

    /**
     * Connect to the SQLite database.
     *
     * @param string|null $dbPath Optional database path override
     * @return bool
     */
    public function connect(?string $dbPath = null): bool
    {
        return $this->connection->connect($dbPath);
    }

    /**
     * Disconnect from the database.
     *
     * @return void
     */
    public function disconnect(): void
    {
        // Rollback any open transactions first
        $this->transaction->rollbackAll();
        $this->connection->disconnect();
    }

    /**
     * Check if the database connection is active.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connection->isConnected();
    }

    /**
     * Get the PDO instance for direct operations.
     *
     * @return PDO|null
     */
    public function getPDO(): ?PDO
    {
        return $this->connection->getPDO();
    }

    /**
     * Get the database file path.
     *
     * @return string
     */
    public function getDbPath(): string
    {
        return $this->connection->getDbPath();
    }

    // =========================================================================
    // QUERY EXECUTION (delegates to QueryBuilder)
    // =========================================================================

    /**
     * Execute a SELECT query and return all matching rows.
     *
     * @param string $sql    SQL query with placeholders
     * @param array  $params Query parameters
     * @return array         Result rows (empty on failure)
     */
    public function query(string $sql, array $params = []): array
    {
        return $this->qb->query($sql, $params);
    }

    /**
     * Execute a query and return a single row.
     *
     * @param string $sql
     * @param array  $params
     * @return array|null
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        return $this->qb->queryOne($sql, $params);
    }

    /**
     * Execute a query and return a single scalar value.
     *
     * @param string $sql
     * @param array  $params
     * @param mixed  $default
     * @return mixed
     */
    public function queryScalar(string $sql, array $params = [], $default = null)
    {
        return $this->qb->queryScalar($sql, $params, $default);
    }

    /**
     * Execute a non-SELECT query (INSERT, UPDATE, DELETE, DDL).
     *
     * @param string $sql
     * @param array  $params
     * @return int    Number of affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->qb->execute($sql, $params);
    }

    /**
     * Execute a query and return the last inserted ID.
     *
     * @param string $sql
     * @param array  $params
     * @return int|string
     */
    public function insertAndGetId(string $sql, array $params = [])
    {
        return $this->qb->insertAndGetId($sql, $params);
    }

    // =========================================================================
    // TRANSACTION MANAGEMENT (delegates to Transaction)
    // =========================================================================

    /**
     * Begin a transaction (supports nesting).
     *
     * @return bool
     */
    public function beginTransaction(): bool
    {
        return $this->transaction->beginTransaction();
    }

    /**
     * Commit the current transaction level.
     *
     * @return bool
     */
    public function commit(): bool
    {
        return $this->transaction->commit();
    }

    /**
     * Rollback the current transaction level.
     *
     * @return bool
     */
    public function rollback(): bool
    {
        return $this->transaction->rollback();
    }

    /**
     * Execute a callback within a transaction.
     *
     * @param callable $callback
     * @return mixed
     */
    public function transaction(callable $callback)
    {
        return $this->transaction->transaction($callback);
    }

    /**
     * Get current transaction nesting level.
     *
     * @return int
     */
    public function getTransactionLevel(): int
    {
        return $this->transaction->getTransactionLevel();
    }

    // =========================================================================
    // QUERY BUILDER HELPERS - CRUD (delegates to QueryBuilder)
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
        return $this->qb->insert($table, $data);
    }

    /**
     * Update rows in a table.
     *
     * @param string $table Table name
     * @param array  $data  Column => value pairs to set
     * @param array  $where Conditions as ['column' => 'value']
     * @return int          Number of affected rows
     */
    public function update(string $table, array $data, array $where): int
    {
        return $this->qb->update($table, $data, $where);
    }

    /**
     * Delete rows from a table.
     *
     * @param string $table Table name
     * @param array  $where Conditions as ['column' => 'value']
     * @return int          Number of deleted rows
     */
    public function delete(string $table, array $where): int
    {
        return $this->qb->delete($table, $where);
    }

    /**
     * Select rows from a table.
     *
     * @param string     $table      Table name
     * @param array|null $conditions Conditions as ['column' => 'value']
     * @param int|null   $limit      Maximum rows
     * @param string     $orderBy    ORDER BY expression
     * @return array
     */
    public function select(string $table, ?array $conditions = null, ?int $limit = null, string $orderBy = ''): array
    {
        return $this->qb->select($table, $conditions, $limit, $orderBy);
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
        return $this->qb->exists($table, $conditions);
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
        return $this->qb->count($table, $conditions);
    }

    // =========================================================================
    // ANALYTICS-SPECIFIC METHODS (delegates to AnalyticsQueries)
    // =========================================================================

    /**
     * Get daily statistics for a specific date.
     *
     * @param string $date Date in Y-m-d format
     * @return array|null
     */
    public function getDailyStats(string $date): ?array
    {
        return $this->analytics->getDailyStats($date);
    }

    /**
     * Get daily stats for a date range.
     *
     * @param string $from Start date Y-m-d
     * @param string $to   End date Y-m-d
     * @return array
     */
    public function getDailyStatsRange(string $from, string $to): array
    {
        return $this->analytics->getDailyStatsRange($from, $to);
    }

    /**
     * Get the real-time visitor count (last N minutes).
     *
     * @param int $minutes Window in minutes (default 5)
     * @return int
     */
    public function getRealtimeVisitors(int $minutes = 5): int
    {
        return $this->analytics->getRealtimeVisitors($minutes);
    }

    /**
     * Get top pages by total views.
     *
     * @param int $limit Maximum pages to return
     * @param int $days  Lookback period in days (0 = all time)
     * @return array
     */
    public function getTopPages(int $limit = 10, int $days = 7): array
    {
        return $this->analytics->getTopPages($limit, $days);
    }

    /**
     * Get top referrer domains.
     *
     * @param int $limit
     * @return array
     */
    public function getTopReferrers(int $limit = 10): array
    {
        return $this->analytics->getTopReferrers($limit);
    }

    /**
     * Get browser distribution.
     *
     * @param int $days Lookback period
     * @return array
     */
    public function getBrowserDistribution(int $days = 30): array
    {
        return $this->analytics->getBrowserDistribution($days);
    }

    /**
     * Get operating system distribution.
     *
     * @param int $days Lookback period
     * @return array
     */
    public function getOSDistribution(int $days = 30): array
    {
        return $this->analytics->getOSDistribution($days);
    }

    /**
     * Get device type distribution.
     *
     * @param int $days Lookback period
     * @return array
     */
    public function getDeviceDistribution(int $days = 30): array
    {
        return $this->analytics->getDeviceDistribution($days);
    }

    /**
     * Get country distribution based on visitor country_code.
     *
     * @param int $days Lookback period
     * @return array
     */
    public function getCountryDistribution(int $days = 30): array
    {
        return $this->analytics->getCountryDistribution($days);
    }

    /**
     * Get the average session duration for a date range.
     *
     * @param string $from Start date Y-m-d
     * @param string $to   End date Y-m-d
     * @return int         Average duration in seconds
     */
    public function getAverageDuration(string $from, string $to): int
    {
        return $this->analytics->getAverageDuration($from, $to);
    }

    /**
     * Get hourly distribution for a given date.
     *
     * @param string $date Date in Y-m-d format
     * @return array
     */
    public function getHourlyDistribution(string $date): array
    {
        return $this->analytics->getHourlyDistribution($date);
    }

    /**
     * Get today's summary statistics.
     *
     * @return array
     */
    public function getTodaySummary(): array
    {
        return $this->analytics->getTodaySummary();
    }

    /**
     * Get overall statistics (all-time totals).
     *
     * @return array
     */
    public function getOverallStats(): array
    {
        $connection = $this->connection;
        return $this->analytics->getOverallStats(function () use ($connection) {
            return $connection->getDatabaseSize();
        });
    }

    /**
     * Get the number of pageviews for the last N days (for charts).
     *
     * @param int $days
     * @return array
     */
    public function getLastNDays(int $days = 30): array
    {
        return $this->analytics->getLastNDays($days);
    }

    // =========================================================================
    // SETTINGS MANAGEMENT (delegates to Connection)
    // =========================================================================

    /**
     * Get a setting value by key.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function getSetting(string $key, $default = null)
    {
        return $this->connection->getSetting($key, $default);
    }

    /**
     * Set a setting value.
     *
     * @param string $key
     * @param mixed  $value
     * @return bool
     */
    public function setSetting(string $key, $value): bool
    {
        return $this->connection->setSetting($key, $value);
    }

    /**
     * Get all settings.
     *
     * @return array<string, string>
     */
    public function getAllSettings(): array
    {
        return $this->connection->getAllSettings();
    }


    /**
     * Get ALL settings as a properly nested array from flat dotted keys.
     * Normalizes dotted and legacy underscore keys into one consistent nested structure.
     *
     * @return array<string, mixed>
     */
    public function getSettingsAsNestedArray(): array
    {
        return $this->connection->getSettingsAsNestedArray();
    }
    // =========================================================================
    // MAINTENANCE & BACKUP (delegates to Connection)
    // =========================================================================

    /**
     * Create a backup of the SQLite database.
     *
     * @param string|null $backupPath Custom backup path
     * @return string|false            Backup file path or false on failure
     */
    public function backup(?string $backupPath = null)
    {
        return $this->connection->backup($backupPath);
    }

    /**
     * Restore the database from a backup file.
     *
     * @param string $backupPath Path to the backup file
     * @return bool
     */
    public function restore(string $backupPath): bool
    {
        return $this->connection->restore($backupPath);
    }

    /**
     * Optimize the database.
     *
     * @return bool
     */
    public function optimize(): bool
    {
        return $this->connection->optimize();
    }

    /**
     * Vacuum the database.
     *
     * @return bool
     */
    public function vacuum(): bool
    {
        return $this->connection->vacuum();
    }

    /**
     * Get the database file size in human-readable format.
     *
     * @return string
     */
    public function getDatabaseSize(): string
    {
        return $this->connection->getDatabaseSize();
    }

    /**
     * Delete old data beyond the retention period.
     *
     * @param int $daysToKeep Number of days to retain
     * @return int            Number of deleted visit records
     */
    public function cleanup(int $daysToKeep = 90): int
    {
        $this->connection->ensureConnected();

        try {
            $this->transaction->beginTransaction();

            $deletedVisits = $this->qb->execute(
                'DELETE FROM visits WHERE timestamp < datetime(\'now\', :offset)',
                [':offset' => "-{$daysToKeep} days"]
            );

            $this->qb->execute(
                'DELETE FROM hourly_stats WHERE date < DATE(\'now\', :offset)',
                [':offset' => "-{$daysToKeep} days"]
            );

            $this->transaction->commit();

            $this->connection->vacuum();

            return $deletedVisits;

        } catch (Throwable $e) {
            $this->transaction->rollback();
            $this->connection->setLastError("Cleanup failed: {$e->getMessage()}");
            return 0;
        }
    }

    // =========================================================================
    // MIGRATION SUPPORT (delegates to Migration)
    // =========================================================================

    /**
     * Run schema migration from one version to another.
     *
     * @param string $fromVersion
     * @param string $toVersion
     * @return bool
     */
    public function migrate(string $fromVersion, string $toVersion): bool
    {
        return $this->migration->migrate($fromVersion, $toVersion);
    }

    /**
     * Get the current schema version from the database.
     *
     * @return string
     */
    public function getSchemaVersion(): string
    {
        return $this->migration->getSchemaVersion();
    }

    /**
     * Get list of applied migrations.
     *
     * @return array
     */
    public function getAppliedMigrations(): array
    {
        return $this->migration->getAppliedMigrations();
    }

    // =========================================================================
    // ERROR HANDLING & LOGGING (delegates to Connection + QueryBuilder)
    // =========================================================================

    /**
     * Get the last error message.
     *
     * @return string|null
     */
    public function getLastError(): ?string
    {
        return $this->connection->getLastError();
    }

    /**
     * Get the query execution log.
     *
     * @return array
     */
    public function getQueryLog(): array
    {
        return $this->qb->getQueryLog();
    }

    /**
     * Enable or disable query logging.
     *
     * @param bool $enable
     * @return self
     */
    public function setQueryLogEnabled(bool $enable): self
    {
        $this->qb->setQueryLogEnabled($enable);
        return $this;
    }

    /**
     * Get the last query execution time in milliseconds.
     *
     * @return float|null
     */
    public function getLastQueryTime(): ?float
    {
        return $this->qb->getLastQueryTime();
    }

    // =========================================================================
    // SINGLETON ENFORCEMENT
    // =========================================================================

    /**
     * Prevent cloning of the singleton instance.
     */
    private function __clone() {}

    /**
     * Prevent unserialization of the singleton instance.
     */
    public function __wakeup()
    {
        throw new RuntimeException('Cannot unserialize DatabaseFacade singleton');
    }

    /**
     * Destructor – close the connection.
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}