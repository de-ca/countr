<?php
/**
 * MaintenanceTrait - Backup, restore, optimization, cleanup, and settings management.
 *
 * Extracted from the monolithic Database class. Provides backup/restore
 * with compression, database optimization/vacuum, data cleanup with
 * retention policies, database size reporting, and settings get/set.
 *
 * @package Countr
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Trait MaintenanceTrait
 *
 * Expects the host class to provide:
 *   - ?PDO $this->pdo
 *   - string $this->dbPath
 *   - string $this->backupDir
 *   - bool $this->connected
 *   - ?string $this->lastError
 *   - array $this->settingsCache
 *   - bool $this->settingsLoaded
 *   - int $this->maxBackups
 *   - method log(string $message): void
 *   - method ensureConnected(): void
 *   - method connect(): bool
 *   - method disconnect(): void
 *   - method query(string $sql, array $params = []): array
 *   - method execute(string $sql, array $params = []): int
 */
trait MaintenanceTrait
{
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
                // Fall back to uncompressed copy
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

        } catch (\Throwable $e) {
            $this->lastError = "Backup failed: {$e->getMessage()}";
            $this->log($this->lastError);
            return false;
        }
    }

    /**
     * Restore the database from a backup file.
     *
     * @param string $backupPath  Path to the backup file
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

            // Decompress if gzipped (gzip magic bytes: 0x1F 0x8B)
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

        } catch (\Throwable $e) {
            $this->lastError = "Restore failed: {$e->getMessage()}";
            $this->log($this->lastError);
            // Try to reconnect
            $this->connect();
            return false;
        }
    }

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
        } catch (\PDOException $e) {
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
        } catch (\PDOException $e) {
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

    /**
     * Delete old data beyond the retention period.
     *
     * @param int $daysToKeep Number of days to retain
     * @return int            Number of deleted visit records
     */
    public function cleanup(int $daysToKeep = 90): int
    {
        $this->ensureConnected();

        try {
            $this->beginTransaction();

            // Delete old visits
            $deletedVisits = $this->execute(
                'DELETE FROM visits WHERE timestamp < datetime(\'now\', :offset)',
                [':offset' => "-{$daysToKeep} days"]
            );

            // Delete old hourly stats
            $this->execute(
                'DELETE FROM hourly_stats WHERE date < DATE(\'now\', :offset)',
                [':offset' => "-{$daysToKeep} days"]
            );

            $this->commit();

            // Compact the database
            $this->vacuum();

            return $deletedVisits;

        } catch (\Throwable $e) {
            $this->rollback();
            $this->lastError = "Cleanup failed: {$e->getMessage()}";
            return 0;
        }
    }

    // =========================================================================
    // SETTINGS MANAGEMENT
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
        if (!$this->settingsLoaded) {
            $this->loadSettings();
        }

        return $this->settingsCache[$key] ?? $default;
    }

    /**
     * Set a setting value (insert or update).
     *
     * @param string $key
     * @param mixed  $value
     * @return bool
     */
    public function setSetting(string $key, $value): bool
    {
        $result = $this->execute(
            'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, CURRENT_TIMESTAMP)
             ON CONFLICT(key) DO UPDATE SET value = :value2, updated_at = CURRENT_TIMESTAMP',
            [':key' => $key, ':value' => (string)$value, ':value2' => (string)$value]
        );

        if ($result > 0) {
            $this->settingsCache[$key] = (string)$value;
        }

        return $result > 0;
    }

    /**
     * Get all settings as an associative array.
     *
     * @return array<string, string>
     */
    public function getAllSettings(): array
    {
        if (!$this->settingsLoaded) {
            $this->loadSettings();
        }

        return $this->settingsCache;
    }

    // =========================================================================
    // INTERNAL HELPERS
    // =========================================================================

    /**
     * Load settings from the database into cache.
     */
    protected function loadSettings(): void
    {
        if (!$this->connected || $this->pdo === null) {
            $this->settingsLoaded = true; // Mark as loaded to avoid repeated attempts
            return;
        }

        try {
            $rows = $this->query('SELECT key, value FROM settings');
            $this->settingsCache = [];
            foreach ($rows as $row) {
                $this->settingsCache[$row['key']] = $row['value'];
            }
            $this->settingsLoaded = true;
        } catch (\Throwable $e) {
            // Settings table might not exist yet
            $this->settingsLoaded = true;
        }
    }

    /**
     * Clean up old backup files beyond maxBackups.
     */
    protected function cleanupOldBackups(): void
    {
        $files = glob($this->backupDir . '/countr_*.db*');
        if ($files === false || count($files) <= $this->maxBackups) {
            return;
        }

        // Sort by modification time (oldest first)
        usort($files, function ($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        // Delete oldest files
        $toDelete = array_slice($files, 0, count($files) - $this->maxBackups);
        foreach ($toDelete as $file) {
            @unlink($file);
        }
    }
}