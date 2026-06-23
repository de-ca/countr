<?php
/**
 * StatisticsTrait - Tracking statistics and reporting methods.
 *
 * Extracted from the monolithic Tracker class. Provides statistics retrieval
 * including today's summary, real-time count, overall stats, N-day charts,
 * hourly distribution, top pages/referrers, browser/OS/device distributions,
 * and getStoredData() for GDPR disclosure.
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
 * Trait StatisticsTrait
 *
 * Expects the host class to provide:
 *   - $db (object) $this->db
 *   - Visitor $this->visitor
 *   - string $this->today
 *   - ?array $this->todayStatsCache
 *   - ?float $this->lastCacheRefresh
 *   - float $this->cacheTTL
 *   - method getRealtimeCount(int $minutes = 5): int
 */
trait StatisticsTrait
{
    /**
     * Get today's statistics summary.
     * Backward-compatible alias for getTodayStats().
     *
     * @return array
     */
    public function getTodaySummary(): array
    {
        return $this->getTodayStats();
    }

    /**
     * Get today's statistics summary.
     *
     * @return array
     */
    public function getTodayStats(): array
    {
        // Use cache if fresh enough
        if (
            $this->todayStatsCache !== null &&
            $this->lastCacheRefresh !== null &&
            (microtime(true) - $this->lastCacheRefresh) < $this->cacheTTL
        ) {
            return $this->todayStatsCache;
        }

        try {
            $stats = $this->db->getTodaySummary();
            $stats['session_duration_avg'] = $this->db->getAverageDuration($this->today, $this->today);
            $stats['realtime_online'] = $this->getRealtimeCount(5);

            $this->todayStatsCache = $stats;
            $this->lastCacheRefresh = microtime(true);

            return $stats;
        } catch (\Throwable $e) {
            return [
                'date' => $this->today,
                'visitors_today' => 0,
                'human_visitors' => 0,
                'pageviews_today' => 0,
                'realtime_online' => 0,
            ];
        }
    }

    /**
     * Get the realtime online visitor count.
     *
     * @param int $minutes Window in minutes (default 5)
     * @return int
     */
    public function getRealtimeCount(int $minutes = 5): int
    {
        try {
            return $this->db->getRealtimeVisitors($minutes);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * BC wrapper: Get number of currently online visitors.
     * Alias for getRealtimeCount(5).
     *
     * @return int
     */
    public function getOnlineCount(): int
    {
        return $this->getRealtimeCount(5);
    }

    /**
     * Get overall (all-time) statistics.
     *
     * @return array
     */
    public function getOverallStats(): array
    {
        try {
            return $this->db->getOverallStats();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get the daily stats for the last N days (for charts).
     *
     * @param int $days Number of days
     * @return array
     */
    public function getLastNDays(int $days = 30): array
    {
        try {
            return $this->db->getLastNDays($days);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get hourly distribution for today.
     *
     * @return array
     */
    public function getHourlyDistribution(): array
    {
        try {
            return $this->db->getHourlyDistribution($this->today);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get top pages.
     *
     * @param int $limit Max pages to return
     * @param int $days  Lookback period in days (0 = all time)
     * @return array
     */
    public function getTopPages(int $limit = 10, int $days = 7): array
    {
        try {
            return $this->db->getTopPages($limit, $days);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get top referrers.
     *
     * @param int $limit
     * @return array
     */
    public function getTopReferrers(int $limit = 10): array
    {
        try {
            return $this->db->getTopReferrers($limit);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get browser distribution.
     *
     * @param int $days Lookback period
     * @return array
     */
    public function getBrowserDistribution(int $days = 30): array
    {
        try {
            return $this->db->getBrowserDistribution($days);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get OS distribution.
     *
     * @param int $days Lookback period
     * @return array
     */
    public function getOSDistribution(int $days = 30): array
    {
        try {
            return $this->db->getOSDistribution($days);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get device type distribution.
     *
     * @param int $days Lookback period
     * @return array
     */
    public function getDeviceDistribution(int $days = 30): array
    {
        try {
            return $this->db->getDeviceDistribution($days);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get country distribution from visitors table.
     *
     * @param int $days Lookback period
     * @return array
     */
    public function getCountryDistribution(int $days = 30): array
    {
        try {
            return $this->db->getCountryDistribution($days);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * BC wrapper: Get stats for a date range.
     *
     * @param string $from Start date (YYYY-MM-DD)
     * @param string $to   End date (YYYY-MM-DD)
     * @return array
     */
    public function getRangeStats(string $from, string $to): array
    {
        try {
            return $this->db->getRangeStats($from, $to);
        } catch (\Throwable $e) {
            return ['days' => [], 'totals' => ['visitors' => 0, 'pageviews' => 0, 'unique' => 0]];
        }
    }

    /**
     * BC wrapper: Get a single day's raw stats.
     *
     * @param string $date Date string (YYYY-MM-DD)
     * @return array|null
     */
    public function getDayStats(string $date): ?array
    {
        try {
            return $this->db->getDayStats($date);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // =========================================================================
    // GDPR & PRIVACY
    // =========================================================================

    /**
     * Get the anonymized visitor data that would be stored.
     * Useful for privacy disclosure pages.
     *
     * @return array
     */
    public function getStoredData(): array
    {
        return [
            'visitor_hash' => $this->visitor->getVisitorId(),
            'ip_anonymized' => $this->visitor->getAnonymizedIp(),
            'ip_hash' => $this->visitor->getIpHash(),
            'session_id' => $this->visitor->getSessionId(),
            'browser' => $this->visitor->getBrowser(),
            'os' => $this->visitor->getOperatingSystem(),
            'device_type' => $this->visitor->getDeviceType(),
            'is_bot' => $this->visitor->isBot(),
        ];
    }
}