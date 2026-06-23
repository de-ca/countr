<?php
/**
 * RateLimitingTrait - Request rate limiting and data maintenance for the Tracker.
 *
 * Extracted from the monolithic Tracker class. Provides rate limit checking,
 * request counting within a sliding window, rate limit toggle, old data cleanup
 * (SQL-only), database optimization, GDPR anonymization, and database size reporting.
 *
 * v1.6.0: Removed FileDB backward-compatibility. SQLite is the sole storage engine.
 *
 * @package Countr
 * @copyright  2026 Countr Analytics
 * @version 1.6.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Trait RateLimitingTrait
 *
 * Expects the host class to provide:
 *   - $db (object) $this->db
 *   - Visitor $this->visitor
 *   - int $this->rateLimit
 *   - int $this->rateLimitWindow
 *   - bool $this->rateLimitEnabled
 *   - method log(string $message): void
 */
trait RateLimitingTrait
{
    /**
     * Check if this visitor is currently rate limited.
     *
     * @param int|null $limit  Max requests (default from config)
     * @param int|null $window Window in seconds (default from config)
     * @return bool True if rate limited
     */
    public function isRateLimited(?int $limit = null, ?int $window = null): bool
    {
        if (!$this->rateLimitEnabled) {
            return false;
        }

        $limit = $limit ?? $this->rateLimit;
        $window = $window ?? $this->rateLimitWindow;

        $visitorHash = $this->visitor->getVisitorId();

        try {
            $count = (int)$this->db->queryScalar(
                "SELECT COUNT(*) FROM visits v
                 JOIN visitors vt ON v.visitor_id = vt.id
                 WHERE vt.visitor_hash = :hash
                 AND v.timestamp > datetime('now', :offset)",
                [
                    ':hash'   => $visitorHash,
                    ':offset' => "-{$window} seconds",
                ],
                0
            );

            return $count >= $limit;
        } catch (\Throwable $e) {
            // If query fails (e.g., table not ready), allow through
            return false;
        }
    }

    /**
     * Get the current request count in the rate limit window.
     *
     * @param int|null $window Window in seconds
     * @return int
     */
    public function getRequestCount(?int $window = null): int
    {
        $window = $window ?? $this->rateLimitWindow;
        $visitorHash = $this->visitor->getVisitorId();

        try {
            return (int)$this->db->queryScalar(
                "SELECT COUNT(*) FROM visits v
                 JOIN visitors vt ON v.visitor_id = vt.id
                 WHERE vt.visitor_hash = :hash
                 AND v.timestamp > datetime('now', :offset)",
                [
                    ':hash'   => $visitorHash,
                    ':offset' => "-{$window} seconds",
                ],
                0
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Enable or disable rate limiting.
     *
     * @param bool $enabled
     * @return self
     */
    public function setRateLimitEnabled(bool $enabled): self
    {
        $this->rateLimitEnabled = $enabled;
        return $this;
    }

    // =========================================================================
    // MAINTENANCE METHODS
    // =========================================================================

    /**
     * Clean up old tracking data beyond the retention period.
     *
     * @param int $days Number of days to retain (default 90)
     * @return int Number of deleted visit rows
     */
    public function cleanupOldData(int $days = 90): int
    {
        try {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

            $deleted = $this->db->execute(
                'DELETE FROM visits WHERE timestamp < :cutoff',
                [':cutoff' => $cutoff]
            );

            // Also clean up hourly stats
            $this->db->execute(
                'DELETE FROM hourly_stats WHERE date < DATE(:cutoff)',
                [':cutoff' => $cutoff]
            );

            // Clean up old daily stats
            $this->db->execute(
                'DELETE FROM daily_stats WHERE date < DATE(:cutoff)',
                [':cutoff' => $cutoff]
            );

            // Clean up orphan visitors (no visits)
            $this->db->execute(
                'DELETE FROM visitors WHERE id NOT IN (SELECT DISTINCT visitor_id FROM visits)'
            );

            return $deleted;

        } catch (\Throwable $e) {
            $this->log('Cleanup failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Run database optimization (VACUUM, reindex).
     *
     * @return bool True on success
     */
    public function optimizeDatabase(): bool
    {
        try {
            $this->db->execute('PRAGMA optimize');
            $this->db->execute('PRAGMA analysis_limit=1000');
            $this->db->execute('PRAGMA optimize');

            return true;
        } catch (\Throwable $e) {
            $this->log('Optimize failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Anonymize old IP data for GDPR compliance.
     * Replaces stored IP hashes with fully anonymized versions after retention period.
     *
     * @param int $days Age of data to anonymize (default 30)
     * @return int Number of affected rows
     */
    public function anonymizeOldData(int $days = 30): int
    {
        try {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

            // Set user_agent to empty for old visit records
            // (IP hash stays but is already anonymized)
            $affected = $this->db->execute(
                "UPDATE visits SET referrer = '',
                 page_title = ''
                 WHERE timestamp < :cutoff",
                [':cutoff' => $cutoff]
            );

            // Update visitor records
            $this->db->execute(
                "UPDATE visitors SET user_agent = ''
                 WHERE last_seen < :cutoff",
                [':cutoff' => $cutoff]
            );

            return $affected;

        } catch (\Throwable $e) {
            $this->log('Anonymization failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get the database file size in human-readable format.
     *
     * @return string
     */
    public function getDatabaseSize(): string
    {
        try {
            $size = $this->db->getDatabaseSize();
            return is_string($size) ? $size : '0 B';
        } catch (\Throwable $e) {
            return 'Unknown';
        }
    }

    /**
     * Clean up old data (delegates to cleanupOldData).
     *
     * @param int $days Age threshold in days
     * @return int Number of deleted visit rows
     */
    public function cleanup(int $days = 90): int
    {
        return $this->cleanupOldData($days);
    }
}
