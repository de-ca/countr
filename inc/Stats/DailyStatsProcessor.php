<?php
/**
 * DailyStatsProcessor - Today's statistics and chart data assembly.
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
 * DailyStatsProcessor
 *
 * Assembles the dashboard snapshot from tracker data sources
 * and provides chart-ready transformations.
 */
class DailyStatsProcessor
{
    /** @var object Database handler */
    private $db;

    /** @var Tracker Tracker instance */
    private Tracker $tracker;

    /** @var array Configuration */
    private array $config;

    /** @var string Cache directory */
    private string $cacheDir;

    /** @var int Cache TTL in seconds */
    private int $cacheTTL = 300;

    /**
     * @param object  $db
     * @param Tracker $tracker
     * @param array   $config
     */
    public function __construct($db, Tracker $tracker, array $config)
    {
        $this->db = $db;
        $this->tracker = $tracker;
        $this->config = $config;
        $this->cacheDir = __DIR__ . '/../../cache';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get a complete dashboard snapshot with all stats.
     *
     * @return array
     */
    public function getDashboardSnapshot(): array
    {
        $cacheKey = 'dashboard_snapshot';
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $todaySummary = $this->tracker->getTodaySummary();
        $overallStats = $this->tracker->getOverallStats();
        $hourlyDist = $this->tracker->getHourlyDistribution();
        $topPages = $this->tracker->getTopPages(10);
        $topReferrers = $this->tracker->getTopReferrers(10);
        $browsers = $this->tracker->getBrowserDistribution();
        $osDist = $this->tracker->getOSDistribution();
        $devices = $this->tracker->getDeviceDistribution();
        $last30Days = $this->tracker->getLastNDays(30);

        $todayVisitors = (int)($todaySummary['visitors_today'] ?? $todaySummary['visitors'] ?? 0);
        $todayPageviews = (int)($todaySummary['pageviews_today'] ?? $todaySummary['pageviews'] ?? 0);
        $online = $this->tracker->getOnlineCount();

        $last30Chart = $this->transformTimeSeriesChart($last30Days);
        $last7Raw = $this->tracker->getLastNDays(7);
        $last7Chart = $this->transformTimeSeriesChart($last7Raw);
        $hourlyChart = $this->transformDistChart($hourlyDist, 'hour', 'visitors');
        $browsersChart = $this->transformDistChart($browsers, 'browser', 'count');
        $topPagesChart = $this->transformDistChart($topPages, 'page_url', 'total_views');

        $snapshot = [
            'generated' => date('Y-m-d H:i:s'),
            'timezone' => $this->config['tracking']['timezone'] ?? 'Europe/Berlin',
            'today' => $todaySummary,
            'online' => $online,
            'today_visitors' => $todayVisitors,
            'today_pageviews' => $todayPageviews,
            'overall' => $overallStats,
            'hourly' => $hourlyDist,
            'top_pages' => $topPages,
            'top_referrers' => $topReferrers,
            'browsers' => $browsers,
            'os' => $osDist,
            'devices' => $devices,
            'last_30_days' => $last30Days,
            'last_7_days_chart' => $last7Chart,
            'last_30_days_chart' => $last30Chart,
            'hourly_chart' => $hourlyChart,
            'browsers_chart' => $browsersChart,
            'top_pages_chart' => $topPagesChart,
            'currently_online' => $online,
            'avg_duration' => $todaySummary['session_duration_avg'] ?? $todaySummary['avg_duration'] ?? 0,
        ];

        $this->setCache($cacheKey, $snapshot);

        return $snapshot;
    }

    /**
     * Generate a public stats summary.
     *
     * @return array
     */
    public function getPublicSummary(): array
    {
        $cacheKey = 'public_summary';
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $overallStats = $this->tracker->getOverallStats();
        $todaySummary = $this->tracker->getTodaySummary();
        $last7Days = $this->tracker->getLastNDays(7);
        $topPages = $this->tracker->getTopPages(5);

        $summary = [
            'site' => [
                'name' => $this->config['site']['name'] ?? 'Unknown',
            ],
            'stats' => [
                'today' => [
                    'visitors' => $todaySummary['visitors_today'] ?? $todaySummary['visitors'] ?? 0,
                    'pageviews' => $todaySummary['pageviews_today'] ?? $todaySummary['pageviews'] ?? 0,
                    'online' => $todaySummary['realtime_online'] ?? 0,
                ],
                'total' => [
                    'visitors' => $overallStats['total_visitors'] ?? 0,
                    'pageviews' => $overallStats['total_pageviews'] ?? 0,
                ],
                'last_7_days' => $last7Days,
                'top_pages' => $topPages,
            ],
            'generated' => date('Y-m-d H:i:s T'),
        ];

        $this->setCache($cacheKey, $summary, 120);

        return $summary;
    }

    // ========== CHART DATA TRANSFORMERS ==========

    /**
     * Transform a DB time-series result into a chart-ready format.
     *
     * @param array $rows
     * @return array
     */
    public function transformTimeSeriesChart(array $rows): array
    {
        $labels = [];
        $visitors = [];
        $pageviews = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $labels[] = $row['date'] ?? '';
                $visitors[] = (int)($row['visitors'] ?? $row['visitors_today'] ?? 0);
                $pageviews[] = (int)($row['pageviews'] ?? $row['pageviews_today'] ?? 0);
            }
        }

        return [
            'labels' => $labels,
            'visitors' => $visitors,
            'pageviews' => $pageviews,
        ];
    }

    /**
     * Transform a DB distribution result into a chart-ready format.
     *
     * @param array  $rows
     * @param string $keyField
     * @param string $valField
     * @return array
     */
    public function transformDistChart(array $rows, string $keyField, string $valField): array
    {
        $labels = [];
        $values = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $label = (string)($row[$keyField] ?? (array_key_first($row) ?? ''));
                $value = (int)($row[$valField] ?? (is_array($row) ? reset($row) : 0));
                $labels[] = $label !== '' ? $label : 'Unknown';
                $values[] = $value;
            }
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    // ========== PRIVATE: CACHE ==========

    /**
     * Try to get data from cache.
     *
     * @param string $key
     * @return mixed|null
     */
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

    /**
     * Store data in cache.
     *
     * @param string $key
     * @param mixed  $data
     * @param int    $ttl
     */
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

    /**
     * Set cache TTL.
     *
     * @param int $seconds
     * @return self
     */
    public function setCacheTTL(int $seconds): self
    {
        $this->cacheTTL = max(10, $seconds);
        return $this;
    }
}