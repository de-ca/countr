<?php
/**
 * TimeStatsProcessor - Weekly, monthly, and yearly time-based statistics.
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
 * TimeStatsProcessor
 *
 * Computes weekly, monthly, and yearly summaries from tracker data.
 */
class TimeStatsProcessor
{
    /** @var Tracker Tracker instance */
    private Tracker $tracker;

    /** @var string Cache directory */
    private string $cacheDir;

    /** @var int Cache TTL in seconds */
    private int $cacheTTL = 300;

    /**
     * @param Tracker $tracker
     */
    public function __construct(Tracker $tracker)
    {
        $this->tracker = $tracker;
        $this->cacheDir = __DIR__ . '/../../cache';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get the weekly summary (current week).
     *
     * @return array
     */
    public function getWeeklySummary(): array
    {
        $cacheKey = 'weekly_summary';
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $monday = date('Y-m-d', strtotime('monday this week'));
        $sunday = date('Y-m-d', strtotime('sunday this week'));

        $rangeData = $this->tracker->getRangeStats($monday, $sunday);

        $this->setCache($cacheKey, $rangeData, 600);

        return $rangeData;
    }

    /**
     * Get the monthly summary (current month).
     *
     * @return array
     */
    public function getMonthlySummary(): array
    {
        $cacheKey = 'monthly_summary';
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $firstDay = date('Y-m-01');
        $lastDay = date('Y-m-t');

        $rangeData = $this->tracker->getRangeStats($firstDay, $lastDay);

        $this->setCache($cacheKey, $rangeData, 1800);

        return $rangeData;
    }

    /**
     * Get stats for the last year by month.
     *
     * @return array
     */
    public function getYearlySummary(): array
    {
        $cacheKey = 'yearly_summary';
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-{$i} months"));
            $firstDay = $month . '-01';
            $lastDay = date('Y-m-t', strtotime($firstDay));

            $rangeData = $this->tracker->getRangeStats($firstDay, $lastDay);
            $result[] = [
                'month' => $month,
                'visitors' => $rangeData['totals']['visitors'] ?? 0,
                'pageviews' => $rangeData['totals']['pageviews'] ?? 0,
                'unique' => $rangeData['totals']['unique'] ?? 0,
            ];
        }

        $this->setCache($cacheKey, $result, 3600);

        return $result;
    }

    /**
     * Get visitor growth trends comparing this week vs last week.
     *
     * @return array
     */
    public function getGrowthTrends(): array
    {
        $thisWeek = $this->getWeeklySummary();
        $lastWeekStart = date('Y-m-d', strtotime('monday last week'));
        $lastWeekEnd = date('Y-m-d', strtotime('sunday last week'));
        $lastWeek = $this->tracker->getRangeStats($lastWeekStart, $lastWeekEnd);

        $thisWeekVisitors = $thisWeek['totals']['visitors'] ?? 0;
        $lastWeekVisitors = $lastWeek['totals']['visitors'] ?? 0;

        $trend = 0;
        if ($lastWeekVisitors > 0) {
            $trend = round((($thisWeekVisitors - $lastWeekVisitors) / $lastWeekVisitors) * 100, 1);
        }

        return [
            'this_week' => $thisWeekVisitors,
            'last_week' => $lastWeekVisitors,
            'trend_percent' => $trend,
            'direction' => $trend > 0 ? 'up' : ($trend < 0 ? 'down' : 'flat'),
        ];
    }

    /**
     * Get the peak hour for today.
     *
     * @return array ['hour' => '14', 'visitors' => 42]
     */
    public function getPeakHour(): array
    {
        $hourlyDist = $this->tracker->getHourlyDistribution();
        $peakHour = '00';
        $peakVisitors = 0;

        foreach ($hourlyDist as $data) {
            $visitors = (int)($data['visitors'] ?? 0);
            if ($visitors > $peakVisitors) {
                $peakVisitors = $visitors;
                $peakHour = (string)($data['hour'] ?? '00');
            }
        }

        return [
            'hour' => $peakHour,
            'visitors' => $peakVisitors,
        ];
    }

    // ========== PRIVATE: CACHE ==========

    private function getCache(string $key)
    {
        $cacheFile = $this->cacheDir . '/' . $key . '.json';

        if (!file_exists($cacheFile)) {
            return null;
        }

        $age = time() - filemtime($cacheFile);
        if ($age > $this->cacheTTL) {
            return null;
        }

        $content = @file_get_contents($cacheFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    private function setCache(string $key, $data, int $ttl = 0): void
    {
        $cacheFile = $this->cacheDir . '/' . $key . '.json';

        if (empty($data)) {
            return;
        }

        $json = json_encode($data, JSON_PRETTY_PRINT);
        if ($json === false) {
            return;
        }

        @file_put_contents($cacheFile, $json, LOCK_EX);
        @chmod($cacheFile, 0644);
    }

    public function setCacheTTL(int $seconds): self
    {
        $this->cacheTTL = max(10, $seconds);
        return $this;
    }
}