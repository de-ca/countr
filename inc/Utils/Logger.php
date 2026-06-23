<?php
/**
 * Logger - Simple logging system for Countr Analytics.
 *
 * @package Countr\Utils
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Utils;

class Logger
{
    /** @var string Log directory */
    private string $logDir;

    /** @var string Minimum log level */
    private string $minLevel;

    /** @var bool Whether to also log to PHP error_log */
    private bool $logToPhp;

    private const LEVELS = ['DEBUG', 'INFO', 'WARNING', 'ERROR'];
    private const LEVEL_WEIGHTS = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];

    /**
     * @param string $logDir    Directory for log files
     * @param string $minLevel  Minimum level: DEBUG, INFO, WARNING, ERROR
     * @param bool   $logToPhp  Also write to PHP error_log
     */
    public function __construct(string $logDir, string $minLevel = 'INFO', bool $logToPhp = true)
    {
        $this->logDir = rtrim($logDir, '/');
        $this->minLevel = strtoupper($minLevel);
        $this->logToPhp = $logToPhp;

        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * Log a debug message.
     */
    public function debug(string $message, array $context = []): void
    {
        $this->write('DEBUG', $message, $context);
    }

    /**
     * Log an info message.
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /**
     * Log a warning message.
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /**
     * Log an error message.
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /**
     * Write a log entry.
     */
    private function write(string $level, string $message, array $context = []): void
    {
        $levelWeight = self::LEVEL_WEIGHTS[$level] ?? 0;
        $minWeight = self::LEVEL_WEIGHTS[$this->minLevel] ?? 0;

        if ($levelWeight < $minWeight) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $entry = "[{$timestamp}] [{$level}] {$message}{$contextStr}";

        // Write to log file
        $logFile = $this->logDir . '/countr_' . date('Y-m-d') . '.log';
        @file_put_contents($logFile, $entry . "\n", FILE_APPEND | LOCK_EX);

        // Also write to PHP error_log if enabled
        if ($this->logToPhp && in_array($level, ['WARNING', 'ERROR'], true)) {
            error_log('[Countr Analytics] ' . $entry);
        }
    }

    /**
     * Get all log entries for today.
     *
     * @return array<string>
     */
    public function getTodayEntries(): array
    {
        $logFile = $this->logDir . '/countr_' . date('Y-m-d') . '.log';
        if (!file_exists($logFile)) {
            return [];
        }

        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return $lines !== false ? $lines : [];
    }

    /**
     * Clean up old log files.
     *
     * @param int $maxAgeDays Max age in days
     * @return int Number of deleted files
     */
    public function cleanupOldLogs(int $maxAgeDays = 30): int
    {
        $cutoff = time() - ($maxAgeDays * 86400);
        $deleted = 0;

        $files = glob($this->logDir . '/countr_*.log');
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}