<?php
/**
 * AnalyticsQueriesTrait - Analytics-specific database queries and statistics.
 *
 * Extracted from the monolithic Database class. Provides methods for
 * retrieving daily stats, real-time visitors, top pages/referrers,
 * browser/OS/device distributions, hourly data, and overall summaries.
 *
 * @package Countr
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Trait AnalyticsQueriesTrait
 *
 * Expects the host class to provide:
 *   - method query(string $sql, array $params = []): array
 *   - method queryOne(string $sql, array $params = []): ?array
 *   - method queryScalar(string $sql, array $params = [], $default = null)
 *   - method getDatabaseSize(): string
 *   - method getRealtimeVisitors(int $minutes = 5): int
 */
trait AnalyticsQueriesTrait
{
    /**
     * Get daily statistics for a specific date.
     *
     * @param string $date Date in Y-m-d format
     * @return array|null
     */
    public function getDailyStats(string $date): ?array
    {
        $row = $this->queryOne(
            'SELECT * FROM v_daily_summary WHERE date = :date',
            [':date' => $date]
        );

        if ($row === null) {
            // Fall back to raw daily_stats if view row is missing
            $row = $this->queryOne(
                'SELECT * FROM daily_stats WHERE date = :date',
                [':date' => $date]
            );
        }

        return $row;
    }

    /**
     * Get daily stats for a date range.
     *
     * @param string $from Start date Y-m-d
     * @param string $to   End date Y-m-d
     * @return array
     */
    public function getDailyStatsRange(string $from, string $to): array
    {
        return $this->query(
            'SELECT * FROM v_daily_summary WHERE date >= :from AND date <= :to ORDER BY date ASC',
            [':from' => $from, ':to' => $to]
        );
    }

    /**
     * Get the real-time visitor count (last N minutes).
     *
     * @param int $minutes Window in minutes (default 5)
     * @return int
     */
    public function getRealtimeVisitors(int $minutes = 5): int
    {
        return (int)$this->queryScalar(
            'SELECT COUNT(DISTINCT visitor_id) as count FROM visits WHERE timestamp > datetime(\'now\', :offset)',
            [':offset' => "-{$minutes} minutes"],
            0
        );
    }

    /**
     * Get top pages by total views.
     *
     * @param int $limit Maximum pages to return
     * @param int $days  Lookback period in days (0 = all time)
     * @return array
     */
    public function getTopPages(int $limit = 10, int $days = 7): array
    {
        if ($days > 0) {
            return $this->query(
                'SELECT page_url, COUNT(*) as total_views, COUNT(DISTINCT visitor_id) as unique_views
                 FROM visits
                 WHERE timestamp >= datetime(\'now\', :offset)
                 GROUP BY page_url
                 ORDER BY total_views DESC
                 LIMIT :limit',
                [':offset' => "-{$days} days", ':limit' => $limit]
            );
        }

        return $this->query(
            'SELECT page_url, total_views, unique_views, bounce_rate, last_viewed
             FROM v_top_pages
             LIMIT :limit',
            [':limit' => $limit]
        );
    }

    /**
     * Get top referrer domains.
     *
     * @param int $limit
     * @return array
     */
    public function getTopReferrers(int $limit = 10): array
    {
        return $this->query(
            'SELECT referrer_domain, visits, last_referral
             FROM referrer_stats
             ORDER BY visits DESC
             LIMIT :limit',
            [':limit' => $limit]
        );
    }

    /**
     * Get browser distribution (counts by browser type).
     *
     * @param int $days Lookback period
     * @return array
     */
    public function getBrowserDistribution(int $days = 30): array
    {
        if ($days > 0) {
            return $this->query(
                'SELECT browser, COUNT(*) as count
                 FROM visitors v
                 JOIN visits vs ON v.id = vs.visitor_id
                 WHERE vs.timestamp >= datetime(\'now\', :offset) AND v.is_bot = 0
                 GROUP BY browser
                 ORDER BY count DESC',
                [':offset' => "-{$days} days"]
            );
        }

        return $this->query(
            'SELECT browser, COUNT(*) as count
             FROM visitors
             WHERE is_bot = 0 AND browser IS NOT NULL
             GROUP BY browser
             ORDER BY count DESC'
        );
    }

    /**
     * Get operating system distribution.
     *
     * @param int $days Lookback period
     * @return array
     */
    public function getOSDistribution(int $days = 30): array
    {
        if ($days > 0) {
            return $this->query(
                'SELECT os, COUNT(*) as count
                 FROM visitors v
                 JOIN visits vs ON v.id = vs.visitor_id
                 WHERE vs.timestamp >= datetime(\'now\', :offset) AND v.is_bot = 0
                 GROUP BY os
                 ORDER BY count DESC',
                [':offset' => "-{$days} days"]
            );
        }

        return $this->query(
            'SELECT os, COUNT(*) as count
             FROM visitors
             WHERE is_bot = 0 AND os IS NOT NULL
             GROUP BY os
             ORDER BY count DESC'
        );
    }

    /**
     * Get device type distribution (desktop/mobile/tablet).
     *
     * @param int $days Lookback period
     * @return array
     */
    public function getDeviceDistribution(int $days = 30): array
    {
        if ($days > 0) {
            return $this->query(
                'SELECT device_type, COUNT(*) as count
                 FROM visitors v
                 JOIN visits vs ON v.id = vs.visitor_id
                 WHERE vs.timestamp >= datetime(\'now\', :offset) AND v.is_bot = 0
                 GROUP BY device_type
                 ORDER BY count DESC',
                [':offset' => "-{$days} days"]
            );
        }

        return $this->query(
            'SELECT device_type, COUNT(*) as count
             FROM visitors
             WHERE is_bot = 0 AND device_type IS NOT NULL
             GROUP BY device_type
             ORDER BY count DESC'
        );
    }

    /**
     * Get the average session duration for a date range.
     *
     * @param string $from Start date Y-m-d
     * @param string $to   End date Y-m-d
     * @return int         Average duration in seconds
     */
    public function getAverageDuration(string $from, string $to): int
    {
        return (int)$this->queryScalar(
            'SELECT COALESCE(AVG(duration), 0)
             FROM (
                 SELECT visitor_id, session_id,
                        MAX(strftime(\'%s\', timestamp)) - MIN(strftime(\'%s\', timestamp)) as duration
                 FROM visits
                 WHERE DATE(timestamp) >= :from AND DATE(timestamp) <= :to
                 GROUP BY visitor_id, session_id
                 HAVING COUNT(*) > 1
             )',
            [':from' => $from, ':to' => $to],
            0
        );
    }

    /**
     * Get hourly distribution for a given date.
     *
     * @param string $date Date in Y-m-d format
     * @return array
     */
    public function getHourlyDistribution(string $date): array
    {
        return $this->query(
            'SELECT hs.hour, COALESCE(hs.visitors, 0) as visitors,
                    COALESCE(hs.pageviews, 0) as pageviews
             FROM hourly_stats hs
             WHERE hs.date = :date
             ORDER BY hs.hour ASC',
            [':date' => $date]
        );
    }

    /**
     * Get today's summary statistics.
     *
     * @return array
     */
    public function getTodaySummary(): array
    {
        $row = $this->queryOne('SELECT * FROM v_today_stats');
        $realtime = $this->getRealtimeVisitors(5);

        return [
            'date' => date('Y-m-d'),
            'visitors_today' => (int)($row['visitors_today'] ?? 0),
            'human_visitors' => (int)($row['human_visitors'] ?? 0),
            'pageviews_today' => (int)($row['pageviews_today'] ?? 0),
            'avg_load_time' => round((float)($row['avg_load_time'] ?? 0), 2),
            'bounces_today' => (int)($row['bounces_today'] ?? 0),
            'realtime_online' => $realtime,
        ];
    }

    /**
     * Get overall statistics (all-time totals).
     *
     * @return array
     */
    public function getOverallStats(): array
    {
        return [
            'total_visitors' => (int)$this->queryScalar('SELECT COUNT(*) FROM visitors WHERE is_bot = 0', [], 0),
            'total_pageviews' => (int)$this->queryScalar('SELECT COUNT(*) FROM visits', [], 0),
            'total_bots' => (int)$this->queryScalar('SELECT COUNT(*) FROM visitors WHERE is_bot = 1', [], 0),
            'total_pages_tracked' => (int)$this->queryScalar('SELECT COUNT(*) FROM page_stats', [], 0),
            'database_size' => $this->getDatabaseSize(),
            'first_tracked' => $this->queryScalar('SELECT MIN(timestamp) FROM visits', [], null),
            'last_tracked' => $this->queryScalar('SELECT MAX(timestamp) FROM visits', [], null),
        ];
    }

    /**
     * Get the number of pageviews for the last N days (for charts).
     *
     * @param int $days
     * @return array
     */
    public function getLastNDays(int $days = 30): array
    {
        // Generate all dates in range
        $result = [];
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));
        $dateTo = date('Y-m-d');

        // Get actual data
        $stats = $this->getDailyStatsRange($dateFrom, $dateTo);
        $statsByDate = [];
        foreach ($stats as $row) {
            $statsByDate[$row['date']] = $row;
        }

        // Fill in all dates
        $current = new \DateTime($dateFrom);
        $end = new \DateTime($dateTo);
        while ($current <= $end) {
            $date = $current->format('Y-m-d');
            if (isset($statsByDate[$date])) {
                $result[] = [
                    'date' => $date,
                    'visitors' => (int)($statsByDate[$date]['visitors'] ?? 0),
                    'pageviews' => (int)($statsByDate[$date]['pageviews'] ?? 0),
                    'unique_visitors' => (int)($statsByDate[$date]['unique_visitors'] ?? 0),
                ];
            } else {
                $result[] = [
                    'date' => $date,
                    'visitors' => 0,
                    'pageviews' => 0,
                    'unique_visitors' => 0,
                ];
            }
            $current->modify('+1 day');
        }

        return $result;
    }

    /**
     * Get the number of day stats directly from daily table (BC wrapper).
     *
     * @param string $from Start date Y-m-d
     * @param string $to   End date Y-m-d
     * @return array
     */
    public function getRangeStats(string $from, string $to): array
    {
        $days = $this->getDailyStatsRange($from, $to);

        $totals = [
            'visitors' => 0,
            'pageviews' => 0,
            'unique' => 0,
        ];

        foreach ($days as $day) {
            $totals['visitors'] += (int)($day['visitors'] ?? 0);
            $totals['pageviews'] += (int)($day['pageviews'] ?? 0);
            $totals['unique'] += (int)($day['unique_visitors'] ?? 0);
        }

        return ['days' => $days, 'totals' => $totals];
    }

    /**
     * Get a single day's raw stats.
     *
     * @param string $date Date string (YYYY-MM-DD)
     * @return array|null
     */
    public function getDayStats(string $date): ?array
    {
        return $this->getDailyStats($date);
    }
}