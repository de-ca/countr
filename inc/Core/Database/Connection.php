<?php
/**
 * Connection - PDO connection management with WAL mode, backup/restore, and maintenance.
 *
 * Extracted from the monolithic Database class. Handles the infrastructure-level
 * connection lifecycle so that other classes (QueryBuilder, Transaction, Migration)
 * focus on business logic.
 *
 * v1.6.0: Further modularized — schema initialization and settings management
 * extracted into SchemaInitializerTrait and SettingsManagerTrait.
 *
 * @package Countr\Core\Database
 * @copyright  2026 Countr Analytics
 * @version 1.6.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Core\Database;

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    throw new \Exception('Fehler: Countr Analytics benötigt mindestens PHP 8.1. Deine Version: ' . PHP_VERSION);
}


use PDO;
use PDOException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/SchemaInitializerTrait.php';
require_once __DIR__ . '/SettingsManagerTrait.php';

class Connection
{
    use SchemaInitializerTrait;
    use SettingsManagerTrait;

    /** @var PDO|null Active PDO handle */
    private ?PDO $pdo = null;

    /** @var string Path to the SQLite database file */
    private string $dbPath;

    /** @var string Directory for database backups */
    private string $backupDir;

    /** @var bool Whether the connection is active */
    private bool $connected = false;

    /** @var string|null Last error message */
    private ?string $lastError = null;

    /** @var int Maximum backup files to retain */
    private int $maxBackups = 5;

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    /**
     * @param string|null $dbPath    Path to SQLite database (defaults to /var/www/html/countr/data/countr.db)
     * @param string|null $backupDir Path to backup directory (defaults to /var/www/html/countr/data/backups)
     */
    public function __construct(?string $dbPath = null, ?string $backupDir = null)
    {
        $this->dbPath = $dbPath ?? '/var/www/html/countr/data/countr.db';
        $this->backupDir = $backupDir ?? '/var/www/html/countr/data/backups';
    }

    // =========================================================================
    // CONNECTION LIFECYCLE
    // =========================================================================

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
                PDO::ATTR_TIMEOUT            => 5,
                PDO::ATTR_PERSISTENT         => false,
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

            // Ensure country_code column exists (migration for v1.7.0)
            $this->ensureCountryCodeColumn();

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
     *
     * @param int $transactionLevel Current nesting level to roll back
     */
    public function disconnect(int $transactionLevel = 0): void
    {
        if ($this->pdo !== null && $transactionLevel > 0) {
            try {
                $this->pdo->rollBack();
            } catch (Throwable $e) {
                // Silently ignore
            }
        }

        $this->pdo = null;
        $this->connected = false;
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
     * Ensure the database is connected before operations.
     *
     * @throws RuntimeException if connection fails
     */
    public function ensureConnected(): void
    {
        if (!$this->connected || $this->pdo === null) {
            if (!$this->connect()) {
                throw new RuntimeException('Database not connected');
            }
        }
    }

    // =========================================================================
    // PDO ACCESS
    // =========================================================================

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

    /**
     * Get the backup directory path.
     *
     * @return string
     */
    public function getBackupDir(): string
    {
        return $this->backupDir;
    }

    // =========================================================================
    // PERFORMANCE PRAGMAS
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
            'journal_mode = WAL',
            'synchronous = NORMAL',
            'cache_size = -10000',
            'temp_store = MEMORY',
            'mmap_size = 268435456',
            'automatic_index = TRUE',
            'journal_size_limit = 67108864',
            'query_only = FALSE',
            'page_size = 4096',
            'auto_vacuum = NONE',
            'foreign_keys = ON',
        ];

        foreach ($pragmas as $pragma) {
            try {
                $this->pdo->exec("PRAGMA {$pragma}");
            } catch (PDOException $e) {
                $this->log("PRAGMA warning: {$pragma} - {$e->getMessage()}");
            }
        }
    }

    // =========================================================================
    // BACKUP & RESTORE
    // =========================================================================

    /**
     * Create a backup of the SQLite database.
     *
     * @param string|null $backupPath Custom backup path (auto-generated if null)
     * @return string|false            Backup file path on success, false on failure
     */
    public function backup(?string $backupPath = null)
    {
        $this->ensureConnected();

        // Ensure backup directory exists
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0755, true);
        }

        // Protect backup directory
        $htaccess = $this->backupDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }

        // Generate backup filename
        if ($backupPath === null) {
            $timestamp = date('Ymd_His');
            $backupPath = $this->backupDir . '/countr_' . $timestamp . '.db.gz';
        }

        try {
            // Force WAL checkpoint before backup
            $this->pdo->exec('PRAGMA wal_checkpoint(FULL)');

            // Read the database file
            $dbContent = @file_get_contents($this->dbPath);
            if ($dbContent === false) {
                $this->lastError = "Failed to read database file for backup";
                return false;
            }

            // Compress and write
            $compressed = @gzencode($dbContent, 9);
            if ($compressed === false) {
                $compressed = $dbContent;
                $backupPath = preg_replace('/\.gz$/', '', $backupPath);
            }

            $written = @file_put_contents($backupPath, $compressed, LOCK_EX);
            if ($written === false) {
                $this->lastError = "Failed to write backup file: {$backupPath}";
                return false;
            }

            // Cleanup old backups
            $this->cleanupOldBackups();

            return $backupPath;

        } catch (Throwable $e) {
            $this->lastError = "Backup failed: {$e->getMessage()}";
            $this->log($this->lastError);
            return false;
        }
    }

    /**
     * Restore the database from a backup file.
     *
     * @param string $backupPath Path to the backup file
     * @return bool
     */
    public function restore(string $backupPath): bool
    {
        if (!file_exists($backupPath)) {
            $this->lastError = "Backup file not found: {$backupPath}";
            return false;
        }

        try {
            // Disconnect first
            $this->disconnect();

            $content = @file_get_contents($backupPath);
            if ($content === false) {
                $this->lastError = "Failed to read backup file";
                return false;
            }

            // Decompress if gzipped
            if (substr($content, 0, 2) === "\x1f\x8b") {
                $content = @gzdecode($content);
                if ($content === false) {
                    $this->lastError = "Failed to decompress backup file";
                    return false;
                }
            }

            // Write to database file
            $written = @file_put_contents($this->dbPath, $content, LOCK_EX);
            if ($written === false) {
                $this->lastError = "Failed to write restored database";
                return false;
            }

            // Reconnect
            $this->connect();

            return true;

        } catch (Throwable $e) {
            $this->lastError = "Restore failed: {$e->getMessage()}";
            $this->log($this->lastError);
            $this->connect();
            return false;
        }
    }

    // =========================================================================
    // MAINTENANCE OPERATIONS
    // =========================================================================

    /**
     * Optimize the database (run ANALYZE for query planner).
     *
     * @return bool
     */
    public function optimize(): bool
    {
        $this->ensureConnected();

        try {
            $this->pdo->exec('PRAGMA optimize');
            $this->pdo->exec('PRAGMA analysis_limit = 1000');
            $this->pdo->exec('ANALYZE');
            return true;
        } catch (PDOException $e) {
            $this->lastError = "Optimize failed: {$e->getMessage()}";
            return false;
        }
    }

    /**
     * Vacuum the database (reclaim disk space).
     *
     * @return bool
     */
    public function vacuum(): bool
    {
        $this->ensureConnected();

        try {
            $this->pdo->exec('VACUUM');
            return true;
        } catch (PDOException $e) {
            $this->lastError = "Vacuum failed: {$e->getMessage()}";
            return false;
        }
    }

    /**
     * Get the database file size in human-readable format.
     *
     * @return string
     */
    public function getDatabaseSize(): string
    {
        if (!file_exists($this->dbPath)) {
            return '0 B';
        }

        $bytes = filesize($this->dbPath);
        if ($bytes === false) {
            return 'Unknown';
        }

        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }

    // =========================================================================
    // ERROR HANDLING
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
     * Set the last error message (used by collaborating classes).
     *
     * @param string $error
     * @return void
     */
    public function setLastError(string $error): void
    {
        $this->lastError = $error;
    }

    // =========================================================================
    // PRIVATE UTILITIES
    // =========================================================================

    /**
     * Clean up old backup files beyond maxBackups.
     */
    private function cleanupOldBackups(): void
    {
        $files = glob($this->backupDir . '/countr_*.db*');
        if ($files === false || count($files) <= $this->maxBackups) {
            return;
        }

        usort($files, function ($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        $toDelete = array_slice($files, 0, count($files) - $this->maxBackups);
        foreach ($toDelete as $file) {
            @unlink($file);
        }
    }

    /**
     * Ensure the country_code column exists in the visitors table.
     *
     * This is a lightweight migration for existing databases that were created
     * before the country_code field was introduced. Uses ALTER TABLE ADD COLUMN
     * which is safe for SQLite — it won't affect existing data.
     */
    private function ensureCountryCodeColumn(): void
    {
        if ($this->pdo === null) {
            return;
        }

        try {
            // Check if country_code column already exists
            $result = $this->pdo->query("PRAGMA table_info('visitors')");
            $columns = [];
            if ($result !== false) {
                foreach ($result->fetchAll(\PDO::FETCH_ASSOC) as $col) {
                    $columns[] = $col['name'];
                }
            }

            if (!in_array('country_code', $columns, true)) {
                $this->pdo->exec('ALTER TABLE visitors ADD COLUMN country_code CHAR(2)');
                $this->log('Migration: Added country_code column to visitors table');
            }
        } catch (\PDOException $e) {
            $this->log('Migration warning: Could not add country_code column: ' . $e->getMessage());
        }
    }

    /**
     * Log a message to the PHP error log.
     *
     * @param string $message
     */
    private function log(string $message): void
    {
        error_log('[Countr Connection] ' . date('Y-m-d H:i:s') . ' ' . $message);
    }
}
