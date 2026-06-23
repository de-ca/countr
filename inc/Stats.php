<?php
/**
 * Stats - Statistics Facade (SQLite-only)
 * 
 * Slim facade that delegates to modular processors:
 *   - DailyStatsProcessor   (dashboard snapshots, public summaries, chart transforms)
 *   - TimeStatsProcessor   (weekly/monthly/yearly summaries, growth trends, peak hour)
 *   - DeviceStatsProcessor  (device/browser/OS distributions, active day analysis)
 *   - SessionStatsProcessor (bounce rate, average session duration)
 * 
 * v1.6.0: Modularized into inc/Stats/ processors; Stats.php is now a facade.
 * 
 * @package Countr
 * @copyright  2026 Countr Analytics
 * @version 1.6.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/Stats/DailyStatsProcessor.php';
require_once __DIR__ . '/Stats/TimeStatsProcessor.php';
require_once __DIR__ . '/Stats/DeviceStatsProcessor.php';
require_once __DIR__ . '/Stats/SessionStatsProcessor.php';

class Stats
{
    /** @var object SQLite database handler (Database or DatabaseFacade) */
    private $db;

    /** @var Tracker Tracker instance */
    private Tracker $tracker;

    /** @var array Configuration */
    private array $config;

    /** @var DailyStatsProcessor */
    private DailyStatsProcessor $dailyProcessor;

    /** @var TimeStatsProcessor */
    private TimeStatsProcessor $timeProcessor;

    /** @var DeviceStatsProcessor */
    private DeviceStatsProcessor $deviceProcessor;

    /** @var SessionStatsProcessor */
    private SessionStatsProcessor $sessionProcessor;

    /**
     * Constructor
     *
     * @param object  $db      Database or DatabaseFacade instance
     * @param Tracker $tracker
     * @param array   $config
     */
    public function __construct($db, Tracker $tracker, array $config)
    {
        $this->db = $db;
        $this->tracker = $tracker;
        $this->config = $config;

        // Initialize modular processors
        $this->dailyProcessor = new DailyStatsProcessor($db, $tracker, $config);
        $this->timeProcessor = new TimeStatsProcessor($tracker);
        $this->deviceProcessor = new DeviceStatsProcessor($db, $tracker);
        $this->sessionProcessor = new SessionStatsProcessor($db);
    }

    // ========== DAILY STATS (delegated to DailyStatsProcessor) ==========

    /**
     * Get a complete dashboard snapshot with all stats.
     *
     * @return array
     */
    public function getDashboardSnapshot(): array
    {
        $snapshot = $this->dailyProcessor->getDashboardSnapshot();

        // Enrich with time-based and session metrics
        $snapshot['weekly'] = $this->timeProcessor->getWeeklySummary();
        $snapshot['monthly'] = $this->timeProcessor->getMonthlySummary();
        $snapshot['bounce_rate'] = $this->sessionProcessor->estimateBounceRate();
        $snapshot['avg_duration'] = $snapshot['avg_duration'] ?: $this->sessionProcessor->getAverageSessionDuration();

        return $snapshot;
    }

    /**
     * Generate a public stats summary (safe for public display, no private data).
     *
     * @return array
     */
    public function getPublicSummary(): array
    {
        return $this->dailyProcessor->getPublicSummary();
    }

    // ========== TIME-BASED STATS (delegated to TimeStatsProcessor) ==========

    /**
     * Get the weekly summary (current week).
     *
     * @return array
     */
    public function getWeeklySummary(): array
    {
        return $this->timeProcessor->getWeeklySummary();
    }

    /**
     * Get the monthly summary (current month).
     *
     * @return array
     */
    public function getMonthlySummary(): array
    {
        return $this->timeProcessor->getMonthlySummary();
    }

    /**
     * Get stats for the last year by month.
     *
     * @return array
     */
    public function getYearlySummary(): array
    {
        return $this->timeProcessor->getYearlySummary();
    }

    /**
     * Get the peak hour for today.
     *
     * @return array ['hour' => '14', 'visitors' => 42]
     */
    public function getPeakHour(): array
    {
        return $this->timeProcessor->getPeakHour();
    }

    /**
     * Get visitor growth trends comparing this week vs last week.
     *
     * @return array
     */
    public function getGrowthTrends(): array
    {
        return $this->timeProcessor->getGrowthTrends();
    }

    // ========== DEVICE STATS (delegated to DeviceStatsProcessor) ==========

    /**
     * Get the most active day of the week based on historical data.
     *
     * @return array
     */
    public function getActiveDayDistribution(): array
    {
        return $this->deviceProcessor->getActiveDayDistribution();
    }

    // ========== SESSION STATS (delegated to SessionStatsProcessor) ==========

    /**
     * Estimate the bounce rate from SQLite data.
     *
     * @return float Percentage (0-100)
     */
    public function estimateBounceRate(): float
    {
        return $this->sessionProcessor->estimateBounceRate();
    }

    /**
     * Get the average session duration for today.
     *
     * @return string Formatted as HH:MM:SS
     */
    public function getAverageSessionDuration(): string
    {
        return $this->sessionProcessor->getAverageSessionDuration();
    }

    // ========== CHART TRANSFORMERS (delegated to DailyStatsProcessor) ==========

    /**
     * Transform a DB time-series result into a chart-ready format.
     *
     * @param array $rows
     * @return array
     */
    public function transformTimeSeriesChart(array $rows): array
    {
        return $this->dailyProcessor->transformTimeSeriesChart($rows);
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
        return $this->dailyProcessor->transformDistChart($rows, $keyField, $valField);
    }

    // ========== CACHE MANAGEMENT ==========

    /**
     * Set cache TTL for all processors.
     *
     * @param int $seconds
     * @return self
     */
    public function setCacheTTL(int $seconds): self
    {
        $this->dailyProcessor->setCacheTTL($seconds);
        $this->timeProcessor->setCacheTTL($seconds);
        return $this;
    }
}