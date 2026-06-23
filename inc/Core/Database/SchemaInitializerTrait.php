<?php
/**
 * SchemaInitializerTrait - Database schema initialization and migration parsing.
 *
 * Extracted from Connection.php. Handles initial schema creation from SQL
 * files or inline definitions, and parses multi-statement SQL with BEGIN/END.
 *
 * v1.6.0: Extracted from Connection.php as part of modular refactoring.
 *
 * @package Countr\Core\Database
 * @copyright  2026 Countr Analytics
 * @version 1.6.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Core\Database;

use PDOException;
use RuntimeException;

/**
 * Trait SchemaInitializerTrait
 *
 * Expects the host class to provide:
 *   - ?PDO $this->pdo
 *   - method log(string $message): void
 */
trait SchemaInitializerTrait
{
    /**
     * Initialize the database schema for a new database.
     */
    private function initializeSchema(): void
    {
        $schemaFile = __DIR__ . '/../../../statix_schema.sql';

        // First try the bundled schema
        if (file_exists($schemaFile)) {
            $schema = @file_get_contents($schemaFile);
            if ($schema === false) {
                throw new RuntimeException(
                    sprintf('Failed to read schema file: %s', $schemaFile)
                );
            }
            if ($this->pdo !== null) {
                try {
                    $statements = $this->parseSQLStatements($schema);
                    foreach ($statements as $sql) {
                        $sql = trim($sql);
                        if (!empty($sql)) {
                            $this->pdo->exec($sql);
                        }
                    }
                    $this->log('Schema initialized from statix_schema.sql');
                    return;
                } catch (PDOException $e) {
                    throw new RuntimeException(
                        sprintf(
                            'Schema execution failed for file "%s": %s',
                            $schemaFile,
                            $e->getMessage()
                        ),
                        (int) $e->getCode(),
                        $e
                    );
                }
            }
        }

        // Fallback: create minimal schema inline
        $this->executeInlineSchema();
    }

    /**
     * Create a minimal schema inline (fallback if SQL file is missing).
     */
    private function executeInlineSchema(): void
    {
        if ($this->pdo === null) {
            return;
        }

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS visitors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visitor_hash CHAR(32) NOT NULL UNIQUE,
            first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
            visits_count INTEGER DEFAULT 1,
            ip_hash CHAR(32),
            user_agent TEXT,
            browser VARCHAR(50),
            browser_version VARCHAR(20),
            os VARCHAR(50),
            os_version VARCHAR(20),
            device_type VARCHAR(20),
            screen_size VARCHAR(20),
            language VARCHAR(10),
            country_code CHAR(2),
            is_bot BOOLEAN DEFAULT 0
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            visitor_id INTEGER NOT NULL,
            session_id CHAR(32),
            page_url TEXT NOT NULL,
            page_title TEXT,
            referrer TEXT,
            referrer_domain VARCHAR(255),
            load_time INTEGER,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_bounce BOOLEAN DEFAULT 1,
            is_exit BOOLEAN DEFAULT 0,
            scroll_depth INTEGER DEFAULT 0,
            FOREIGN KEY (visitor_id) REFERENCES visitors(id) ON DELETE CASCADE
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS daily_stats (
            date DATE PRIMARY KEY,
            visitors INTEGER DEFAULT 0,
            unique_visitors INTEGER DEFAULT 0,
            pageviews INTEGER DEFAULT 0,
            bounces INTEGER DEFAULT 0,
            avg_duration INTEGER DEFAULT 0,
            total_duration INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS hourly_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date DATE NOT NULL,
            hour INTEGER NOT NULL,
            visitors INTEGER DEFAULT 0,
            pageviews INTEGER DEFAULT 0,
            UNIQUE(date, hour)
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS page_stats (
            page_url TEXT PRIMARY KEY,
            total_views INTEGER DEFAULT 0,
            unique_views INTEGER DEFAULT 0,
            avg_duration INTEGER DEFAULT 0,
            bounce_rate REAL DEFAULT 0,
            last_viewed DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS referrer_stats (
            referrer_domain VARCHAR(255) PRIMARY KEY,
            visits INTEGER DEFAULT 0,
            last_referral DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS settings (
            key VARCHAR(100) PRIMARY KEY,
            value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
            version VARCHAR(20) PRIMARY KEY,
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            description TEXT
        )');

        $this->pdo->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('schema_version', '1.0.0')");
        $this->pdo->exec("INSERT OR IGNORE INTO migrations (version, description) VALUES ('1.0.0', 'Initial schema (inline)')");

        // Create minimal indexes
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_visits_timestamp ON visits(timestamp)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_visits_visitor_id ON visits(visitor_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_visitors_hash ON visitors(visitor_hash)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_daily_stats_date ON daily_stats(date)');

        $this->log('Schema initialized from inline definitions');
    }

    /**
     * Parse a SQL string into individual statements.
     *
     * Handles semicolons inside string literals and inside BEGIN...END blocks
     * (used by CREATE TRIGGER statements), so that triggers are not split apart.
     *
     * @param string $sql
     * @return array<string>
     */
    private function parseSQLStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $beginDepth = 0;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            // --- String literal handling ---
            if (!$inString && ($char === "'" || $char === '"')) {
                $inString = true;
                $stringChar = $char;
                $current .= $char;
                continue;
            }

            if ($inString && $char === $stringChar) {
                // Escaped quote (doubled)
                if ($i + 1 < $length && $sql[$i + 1] === $stringChar) {
                    $current .= $char . $char;
                    $i++;
                    continue;
                }
                $inString = false;
                $current .= $char;
                continue;
            }

            // --- Track BEGIN / END nesting for compound statements ---
            if (!$inString) {
                // Check for BEGIN keyword (not part of another word)
                if ($this->isWordAt($sql, $i, 'BEGIN')) {
                    $beginDepth++;
                } elseif ($this->isWordAt($sql, $i, 'END')) {
                    if ($beginDepth > 0) {
                        $beginDepth--;
                    }
                }
            }

            // --- Statement terminator (only at top level) ---
            if (!$inString && $beginDepth === 0 && $char === ';') {
                $statements[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }

    /**
     * Check whether a word starts at position $i in $sql.
     * A word is bounded by non-word characters or string boundaries.
     *
     * @param string $sql
     * @param int    $i
     * @param string $word
     * @return bool
     */
    private function isWordAt(string $sql, int $i, string $word): bool
    {
        $wordLen = strlen($word);
        // Not enough characters left
        if ($i + $wordLen > strlen($sql)) {
            return false;
        }
        // Check the word matches case-insensitively
        if (strtoupper(substr($sql, $i, $wordLen)) !== strtoupper($word)) {
            return false;
        }
        // Check character before (if any) is a word boundary
        if ($i > 0) {
            $prev = $sql[$i - 1];
            if (ctype_alnum($prev) || $prev === '_') {
                return false;
            }
        }
        // Check character after (if any) is a word boundary
        if ($i + $wordLen < strlen($sql)) {
            $next = $sql[$i + $wordLen];
            if (ctype_alnum($next) || $next === '_') {
                return false;
            }
        }
        return true;
    }
}