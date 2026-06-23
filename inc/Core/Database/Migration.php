<?php
/**
 * Migration - Schema migration management with version tracking.
 *
 * Depends on a QueryBuilder instance (injected) for database operations.
 *
 * @package Countr\Core\Database
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Core\Database;

use Throwable;

class Migration
{
    /** @var QueryBuilder Query builder for database operations */
    private QueryBuilder $qb;

    /** @var Connection Connection for settings access */
    private Connection $connection;

    /** @var string Current schema version */
    private string $schemaVersion = '1.0.0';

    // =========================================================================
    // CONSTRUCTOR (Dependency Injection)
    // =========================================================================

    /**
     * @param QueryBuilder $qb         Query builder instance (injected)
     * @param Connection   $connection Connection instance (injected)
     */
    public function __construct(QueryBuilder $qb, Connection $connection)
    {
        $this->qb = $qb;
        $this->connection = $connection;
    }

    // =========================================================================
    // MIGRATION OPERATIONS
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
        if (version_compare($fromVersion, $toVersion, '>=')) {
            return true; // Already up to date
        }

        $this->connection->ensureConnected();

        try {
            // Check if migrations table exists
            $tableExists = $this->qb->queryScalar(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='migrations'",
                [],
                0
            );

            if ($tableExists === 0) {
                // Create migrations table
                $this->qb->execute("CREATE TABLE IF NOT EXISTS migrations (
                    version VARCHAR(20) PRIMARY KEY,
                    applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    description TEXT
                )");

                // Mark initial schema as applied
                $this->qb->execute(
                    "INSERT OR IGNORE INTO migrations (version, description) VALUES (:v, :d)",
                    [':v' => '1.0.0', ':d' => 'Initial Countr schema']
                );
            }

            // Update schema version in settings
            $this->connection->setSetting('schema_version', $toVersion);

            $this->log("Migration complete: {$fromVersion} -> {$toVersion}");
            return true;

        } catch (Throwable $e) {
            $this->connection->setLastError("Migration failed: {$e->getMessage()}");
            $this->log($this->connection->getLastError());
            return false;
        }
    }

    /**
     * Get the current schema version from the database.
     *
     * @return string
     */
    public function getSchemaVersion(): string
    {
        return $this->connection->getSetting('schema_version', '0.0.0');
    }

    /**
     * Get list of applied migrations.
     *
     * @return array
     */
    public function getAppliedMigrations(): array
    {
        return $this->qb->query(
            'SELECT version, applied_at, description FROM migrations ORDER BY version ASC'
        );
    }

    /**
     * Check if a specific migration has been applied.
     *
     * @param string $version
     * @return bool
     */
    public function hasMigration(string $version): bool
    {
        $row = $this->qb->queryOne(
            'SELECT version FROM migrations WHERE version = :v',
            [':v' => $version]
        );
        return $row !== null;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Log a message to the PHP error log.
     *
     * @param string $message
     */
    private function log(string $message): void
    {
        error_log('[Countr Migration] ' . date('Y-m-d H:i:s') . ' ' . $message);
    }
}