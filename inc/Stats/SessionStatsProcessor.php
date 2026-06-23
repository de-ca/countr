<?php
/**
 * SessionStatsProcessor - Bounce rate, session duration, and engagement metrics.
 *
 * v1.6.0: Extracted from Stats.php as part of modular refactoring.
 *
 * @package Countr\Stats
 * @copyright  2026 Countr Analytics
 * @version 1.6.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * SessionStatsProcessor
 *
 * Computes bounce rate, average session duration, and other engagement
 * metrics directly from the SQLite database.
 */
class SessionStatsProcessor
{
    /** @var object Database handler */
    private $db;

    /**
     * @param object $db
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Estimate the bounce rate from SQLite data.
     * Bounce = visitors with only 1 pageview.
     *
     * @return float Percentage (0-100)
     */
    public function estimateBounceRate(): float
    {
        try {
            $total = (int)$this->db->queryScalar(
                "SELECT COUNT(DISTINCT visitor_id) FROM visits WHERE DATE(timestamp) = DATE('now', 'localtime')",
                [], 0
            );
            if ($total === 0) {
                return 0.0;
            }

            $bounces = (int)$this->db->queryScalar(
                "SELECT COUNT(*) FROM (
                    SELECT visitor_id, COUNT(*) as cnt
                    FROM visits
                    WHERE DATE(timestamp) = DATE('now', 'localtime')
                    GROUP BY visitor_id
                    HAVING cnt <= 1
                )",
                [], 0
            );

            return round(($bounces / $total) * 100, 1);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Get the average session duration for today.
     *
     * @return string Formatted as HH:MM:SS
     */
    public function getAverageSessionDuration(): string
    {
        try {
            $avgSeconds = (int)$this->db->queryScalar(
                "SELECT COALESCE(AVG(duration), 0) FROM (
                    SELECT visitor_id, session_id,
                           MAX(strftime('%s', timestamp)) - MIN(strftime('%s', timestamp)) as duration
                    FROM visits
                    WHERE DATE(timestamp) = DATE('now', 'localtime')
                    GROUP BY visitor_id, session_id
                    HAVING COUNT(*) > 1
                )",
                [], 0
            );

            return sprintf('%02d:%02d:%02d',
                floor($avgSeconds / 3600),
                floor(($avgSeconds % 3600) / 60),
                $avgSeconds % 60
            );
        } catch (Throwable $e) {
            return '00:00:00';
        }
    }
}