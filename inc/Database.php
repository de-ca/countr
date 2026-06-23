<?php
/**
 * Database - SQLite PDO Wrapper with Connection Pooling & Performance Optimization
 * 
 * Provides a robust interface for SQLite operations including:
 * - Singleton pattern with connection management
 * - WAL mode and performance PRAGMAs
 * - Transaction management with automatic rollback
 * - Query builder interface with prepared statements
 * - Analytics-specific helper methods
 * - Automatic backup and maintenance operations
 * - Schema migration support
 *
 * Refactored (v1.3.0): Core logic extracted into granular Traits:
 *   - SchemaBuilderTrait    (schema init, migrations, SQL parsing)
 *   - CRUDOperationsTrait   (query execution, transactions, CRUD helpers)
 *   - AnalyticsQueriesTrait (analytics-specific statistical queries)
 *   - MaintenanceTrait      (backup, restore, optimization, settings, cleanup)
 * 
 * @package Countr
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/Traits/CRUDOperationsTrait.php';
require_once __DIR__ . '/Traits/SchemaBuilderTrait.php';
require_once __DIR__ . '/Traits/AnalyticsQueriesTrait.php';
require_once __DIR__ . '/Traits/MaintenanceTrait.php';

class Database
{
    use CRUDOperationsTrait;
    use SchemaBuilderTrait;
    use AnalyticsQueriesTrait;
    use MaintenanceTrait;

    /** @var Database|null Singleton instance */
    private static ?Database $instance = null;

    /** @var PDO|null PDO connection handle */
    protected ?PDO $pdo = null;

    /** @var string Path to the SQLite database file */
    protected string $dbPath;

    /** @var string Directory for database backups */
    protected string $backupDir;

    /** @var bool Whether the connection is active */
    protected bool $connected = false;

    /** @var int Transaction nesting level */
    protected int $transactionLevel = 0;

    /** @var array<string, mixed> Runtime cache for frequently accessed settings */
    protected array $settingsCache = [];

    /** @var bool Whether settings cache is loaded */
    protected bool $settingsLoaded = false;

    /** @var string|null Last error message */
    protected ?string $lastError = null;

    /** @var array<int, array> Query log for debugging */
    protected array $queryLog = [];

    /** @var bool Enable query logging */
    protected bool $enableQueryLog = false;

    /** @var float|null Track last query execution time */
    protected ?float $lastQueryTime = null;

    /** @var int Maximum backup files to retain */
    protected int $maxBackups = 5;

    /** @var string Current schema version */
    protected string $schemaVersion = '1.0.0';

    // =========================================================================
    // SINGLETON & CONNECTION MANAGEMENT
    // =========================================================================

    /**
     * Private constructor – use getInstance().
     *
     * @param string|null $dbPath    Path to SQLite database (defaults to /var/www/html/countr/data/countr.db)
     * @param string|null $backupDir Path to backup directory (defaults to /var/www/html/countr/data/backups)
     */
    private function __construct(?string $dbPath = null, ?string $backupDir = null)
    {
        $this->dbPath = $dbPath ?? '/var/www/html/countr/data/countr.db';
        $this->backupDir = $backupDir ?? '/var/www/html/countr/data/backups';
    }

    /**
     * Get the singleton Database instance.
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

    /**
     * Connect to the SQLite database with performance-optimized PRAGMAs.
     * Automatically creates the database file and runs schema if needed.
     *
     * @param string|null $dbPath Optional database path override
     * @return bool
     */
    public function connect(?string $dbPath = null): bool
    {
        if ($dbPath !== null) {
            $this->dbPath = $dbPath;
        }

        if ($this->connected && $this->pdo !== null) {
            return true;
        }

        try {
            // Ensure the directory exists
            $dbDir = dirname($this->dbPath);
            if (!is_dir($dbDir)) {
                if (!@mkdir($dbDir, 0755, true)) {
                    $this->lastError = 'Das Datenverzeichnis "' . $dbDir . '" konnte nicht erstellt werden. '
                        . 'Bitte stellen Sie sicher, dass das übergeordnete Verzeichnis für den Webserver schreibbar ist. '
                        . 'Führen Sie manuell aus: mkdir -p ' . $dbDir . ' && chmod 775 ' . $dbDir;
                    $this->log($this->lastError);
                    return false;
                }
                @chmod($dbDir, 0755);
            }

            // ========== RUNTIME WRITABILITY CHECK ==========
            // Before attempting to open the SQLite database (which would fail
            // with a cryptic "read-only" or "unable to open database" error),
            // check whether the data/ directory is actually writable by the
            // webserver user. If not, present a clear, actionable message
            // instead of a cryptic SQLite PDOException.
            if (!is_writable($dbDir)) {
                // Try to fix permissions automatically
                $fixed = @chmod($dbDir, 0775);
                if (!$fixed) {
                    $fixed = @chmod($dbDir, 0777);
                }

                // Verify the fix
                if (!$fixed || !is_writable($dbDir)) {
                    $this->lastError = 'Kein Schreibzugriff auf das Datenverzeichnis "'
                        . $dbDir . '". '
                        . 'Die SQLite-Datenbank benötigt Schreibrechte in diesem Ordner. '
                        . 'Konnte Berechtigungen nicht automatisch setzen. '
                        . 'Bitte stellen Sie sicher, dass das Verzeichnis "'
                        . $dbDir . '" für den Webserver schreibbar ist. '
                        . 'Führen Sie manuell aus: chmod 775 ' . $dbDir
                        . ' oder chmod 755 ' . $dbDir;
                    $this->log($this->lastError);
                    return false;
                }
            }

            // Also check that the dbPath directory is readable (for SQLite
            // journal/WAL files in the same directory)
            if (!is_readable($dbDir)) {
                @chmod($dbDir, 0755);
                if (!is_readable($dbDir)) {
                    $this->lastError = 'Kein Lesezugriff auf das Datenverzeichnis "'
                        . $dbDir . '". '
                        . 'Bitte stellen Sie sicher, dass das Verzeichnis "'
                        . $dbDir . '" für den Webserver lesbar ist.';
                    $this->log($this->lastError);
                    return false;
                }
            }

            // Check if this is a fresh database
            $isNewDatabase = !file_exists($this->dbPath);

            // Create PDO connection
            $dsn = 'sqlite:' . $this->dbPath;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5, // 5 seconds lock timeout
                PDO::ATTR_PERSISTENT         => false, // SQLite works better without persistence
            ];

            $this->pdo = new PDO($dsn, null, null, $options);

            // Apply performance PRAGMAs
            $this->applyPerformancePragmas();

            // Enable WAL mode
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA foreign_keys = ON');

            $this->connected = true;

            // If new database, run schema setup
            if ($isNewDatabase) {
                $this->initializeSchema();
            }

            // Load settings into cache
            $this->loadSettings();

            return true;

        } catch (PDOException $e) {
            $this->lastError = 'Database connection failed: ' . $e->getMessage();
            $this->log($this->lastError);
            $this->connected = false;
            $this->pdo = null;
            return false;
        } catch (Throwable $e) {
            $this->lastError = 'Database connection error: ' . $e->getMessage();
            $this->log($this->lastError);
            $this->connected = false;
            $this->pdo = null;
            return false;
        }
    }

    /**
     * Disconnect from the database.
     */
    public function disconnect(): void
    {
        if ($this->pdo !== null && $this->transactionLevel > 0) {
            // Rollback any open transactions
            try {
                $this->pdo->rollBack();
            } catch (Throwable $e) {
                // Silently ignore
            }
        }

        $this->pdo = null;
        $this->connected = false;
        $this->transactionLevel = 0;
        $this->settingsLoaded = false;
        $this->settingsCache = [];
    }

    /**
     * Check if the database connection is active.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->pdo !== null;
    }

    /**
     * Get the PDO instance for direct operations.
     *
     * @return PDO|null
     */
    public function getPDO(): ?PDO
    {
        return $this->pdo;
    }

    /**
     * Get the database file path.
     *
     * @return string
     */
    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    // =========================================================================
    // ERROR HANDLING & LOGGING
    // =========================================================================

    /**
     * Get the last error message.
     *
     * @return string|null
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
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
     * Apply performance-optimized SQLite PRAGMAs.
     */
    private function applyPerformancePragmas(): void
    {
        if ($this->pdo === null) {
            return;
        }

        $pragmas = [
            // WAL mode for better concurrency
            'journal_mode = WAL',

            // Balance between speed and safety (NORMAL is safe for WAL)
            'synchronous = NORMAL',

            // 10MB page cache (negative = KB)
            'cache_size = -10000',

            // Store temp tables/indexes in memory
            'temp_store = MEMORY',

            // 256MB memory-mapped I/O
            'mmap_size = 268435456',

            // Auto-index for queries missing indexes
            'automatic_index = TRUE',

            // Store the rollback journal in memory
            'journal_size_limit = 67108864', // 64MB max journal

            // Use the full features of the query planner
            'query_only = FALSE',

            // Page size: 4096 bytes (good balance)
            'page_size = 4096',

            // Disable auto-vacuum for speed
            'auto_vacuum = NONE',

            // Enable foreign key enforcement
            'foreign_keys = ON',
        ];

        foreach ($pragmas as $pragma) {
            try {
                $this->pdo->exec("PRAGMA {$pragma}");
            } catch (PDOException $e) {
                // Some PRAGMAs might not be supported in older SQLite versions
                $this->log("PRAGMA warning: {$pragma} - {$e->getMessage()}");
            }
        }
    }

    /**
     * Log a message to the PHP error log.
     *
     * @param string $message
     */
    protected function log(string $message): void
    {
        error_log('[Countr Database] ' . date('Y-m-d H:i:s') . ' ' . $message);
    }

    /**
     * Prevent cloning of the singleton instance.
     */
    private function __clone() {}

    /**
     * Prevent unserialization of the singleton instance.
     */
    public function __wakeup()
    {
        throw new RuntimeException('Cannot unserialize Database singleton');
    }

    /**
     * Destructor – close the connection.
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}