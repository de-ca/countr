<?php
/**
 * Transaction - Nested transaction management with savepoints and automatic rollback.
 *
 * Depends on a Connection instance (injected) for PDO access.
 *
 * @package Countr\Core\Database
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Core\Database;

use PDOException;
use Throwable;

class Transaction
{
    /** @var Connection Underlying connection */
    private Connection $connection;

    /** @var int Transaction nesting level */
    private int $transactionLevel = 0;

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
    // TRANSACTION MANAGEMENT
    // =========================================================================

    /**
     * Begin a database transaction (supports nesting via savepoints).
     *
     * @return bool
     */
    public function beginTransaction(): bool
    {
        $this->connection->ensureConnected();
        $pdo = $this->connection->getPDO();

        if ($pdo === null) {
            return false;
        }

        try {
            if ($this->transactionLevel === 0) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec("SAVEPOINT level_{$this->transactionLevel}");
            }

            $this->transactionLevel++;
            return true;

        } catch (PDOException $e) {
            $this->connection->setLastError("Begin transaction failed: {$e->getMessage()}");
            $this->log($this->connection->getLastError());
            return false;
        }
    }

    /**
     * Commit the current transaction level.
     *
     * @return bool
     */
    public function commit(): bool
    {
        $pdo = $this->connection->getPDO();

        if ($pdo === null || $this->transactionLevel <= 0) {
            return false;
        }

        $this->transactionLevel--;

        try {
            if ($this->transactionLevel === 0) {
                $pdo->commit();
            } else {
                $pdo->exec("RELEASE SAVEPOINT level_{$this->transactionLevel}");
            }
            return true;

        } catch (PDOException $e) {
            $this->connection->setLastError("Commit failed: {$e->getMessage()}");
            $this->log($this->connection->getLastError());
            return false;
        }
    }

    /**
     * Rollback the current transaction level.
     *
     * @return bool
     */
    public function rollback(): bool
    {
        $pdo = $this->connection->getPDO();

        if ($pdo === null || $this->transactionLevel <= 0) {
            return false;
        }

        $this->transactionLevel--;

        try {
            if ($this->transactionLevel === 0) {
                $pdo->rollBack();
            } else {
                $pdo->exec("ROLLBACK TO SAVEPOINT level_{$this->transactionLevel}");
            }
            return true;

        } catch (PDOException $e) {
            $this->connection->setLastError("Rollback failed: {$e->getMessage()}");
            $this->log($this->connection->getLastError());
            return false;
        }
    }

    /**
     * Execute a callback within a transaction.
     * Automatically commits on success, rolls back on exception.
     *
     * @param callable $callback Function receiving the Connection instance
     * @return mixed             Return value from callback, or null on failure
     */
    public function transaction(callable $callback)
    {
        $this->beginTransaction();

        try {
            $result = $callback($this->connection);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollback();
            $this->connection->setLastError("Transaction failed: {$e->getMessage()}");
            $this->log($this->connection->getLastError());
            return null;
        }
    }

    /**
     * Rollback any open transactions (used during disconnect).
     *
     * @return void
     */
    public function rollbackAll(): void
    {
        $pdo = $this->connection->getPDO();

        if ($pdo === null || $this->transactionLevel <= 0) {
            $this->transactionLevel = 0;
            return;
        }

        try {
            // Rollback the outermost transaction
            $pdo->rollBack();
        } catch (Throwable $e) {
            // Silently ignore
        }

        $this->transactionLevel = 0;
    }

    // =========================================================================
    // STATE INQUIRY
    // =========================================================================

    /**
     * Get current transaction nesting level.
     *
     * @return int
     */
    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Check if a transaction is currently active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->transactionLevel > 0;
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
        error_log('[Countr Transaction] ' . date('Y-m-d H:i:s') . ' ' . $message);
    }
}