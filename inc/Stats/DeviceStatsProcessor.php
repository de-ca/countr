<?php
/**
 * DeviceStatsProcessor - Device, browser, and platform distribution analysis.
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
 * DeviceStatsProcessor
 *
 * Provides device type, browser, OS distribution and active day analysis.
 */
class DeviceStatsProcessor
{
    /** @var object Database handler */
    private $db;

    /** @var Tracker Tracker instance */
    private Tracker $tracker;

    /**
     * @param object  $db
     * @param Tracker $tracker
     */
    public function __construct($db, Tracker $tracker)
    {
        $this->db = $db;
        $this->tracker = $tracker;
    }

    /**
     * Get the most active day of the week based on historical data (SQLite).
     *
     * @return array
     */
    public function getActiveDayDistribution(): array
    {
        $days = ['Mon' => 0, 'Tue' => 0, 'Wed' => 0, 'Thu' => 0, 'Fri' => 0, 'Sat' => 0, 'Sun' => 0];

        try {
            $rows = $this->db->query(
                "SELECT strftime('%w', date) as dow, AVG(visitors) as avg_visitors
                 FROM daily_stats
                 WHERE date >= DATE('now', '-90 days')
                 GROUP BY dow"
            );

            $dowMap = ['0' => 'Sun', '1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat'];
            foreach ($rows as $row) {
                $dayName = $dowMap[$row['dow']] ?? null;
                if ($dayName !== null) {
                    $days[$dayName] = round((float)($row['avg_visitors'] ?? 0), 1);
                }
            }
        } catch (Throwable $e) {
            // Return zeros on any error
        }

        return $days;
    }

    /**
     * Get device distribution for a given period.
     *
     * @param int $days Lookback period
     * @return array
     */
    public function getDeviceDistribution(int $days = 30): array
    {
        return $this->tracker->getDeviceDistribution($days);
    }

    /**
     * Get browser distribution for a given period.
     *
     * @param int $days Lookback period
     * @return array
     */
    public function getBrowserDistribution(int $days = 30): array
    {
        return $this->tracker->getBrowserDistribution($days);
    }

    /**
     * Get OS distribution for a given period.
     *
     * @param int $days Lookback period
     * @return array
     */
    public function getOSDistribution(int $days = 30): array
    {
        return $this->tracker->getOSDistribution($days);
    }
}