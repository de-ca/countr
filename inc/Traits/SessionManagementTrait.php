<?php
/**
 * SessionManagementTrait - Session lifecycle, bounce detection, and visitor management.
 *
 * Extracted from the monolithic Tracker class. Provides session start/end,
 * session duration calculation, bounce detection, visitor DB ID resolution,
 * and visitor record ensure/insert logic.
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
 * Trait SessionManagementTrait
 *
 * Expects the host class to provide:
 *   - $db (object) $this->db
 *   - Visitor $this->visitor
 *   - string $this->now
 *   - ?int $this->visitorDbId
 *   - bool $this->visitorEnsured
 *   - method log(string $message): void
 */
trait SessionManagementTrait
{
    /**
     * Start a new session.
     * A session cookie is set by Visitor, this method handles DB tracking.
     *
     * @return string Session ID
     */
    public function startSession(): string
    {
        $sessionId = $this->visitor->getSessionId();
        return $sessionId;
    }

    /**
     * End the current session (mark last visit as potential exit).
     *
     * @return bool
     */
    public function endSession(): bool
    {
        $visitorId = $this->getVisitorDbId();
        if ($visitorId === null) {
            return false;
        }

        // Mark the last visit of this session as potential exit
        $sessionId = $this->visitor->getSessionId();

        try {
            $this->db->execute(
                'UPDATE visits SET is_exit = 1
                 WHERE visitor_id = :vid AND session_id = :sid
                 AND timestamp = (
                     SELECT MAX(timestamp) FROM visits
                     WHERE visitor_id = :vid2 AND session_id = :sid2
                 )',
                [
                    ':vid'  => $visitorId,
                    ':sid'  => $sessionId,
                    ':vid2' => $visitorId,
                    ':sid2' => $sessionId,
                ]
            );
            return true;
        } catch (\Throwable $e) {
            $this->log('End session failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the current session duration in seconds.
     *
     * @return int Duration in seconds (0 if single hit)
     */
    public function getSessionDuration(): int
    {
        $visitorId = $this->getVisitorDbId();
        if ($visitorId === null) {
            return 0;
        }

        $sessionId = $this->visitor->getSessionId();

        $first = $this->db->queryScalar(
            'SELECT MIN(strftime(\'%s\', timestamp)) FROM visits
             WHERE visitor_id = :vid AND session_id = :sid',
            [':vid' => $visitorId, ':sid' => $sessionId],
            0
        );

        $last = $this->db->queryScalar(
            'SELECT MAX(strftime(\'%s\', timestamp)) FROM visits
             WHERE visitor_id = :vid AND session_id = :sid',
            [':vid' => $visitorId, ':sid' => $sessionId],
            0
        );

        if ($first && $last && $last > $first) {
            return (int)($last - $first);
        }

        return 0;
    }

    /**
     * Check if the current session is a bounce (single page visit).
     *
     * @return bool
     */
    public function isBounce(): bool
    {
        $visitorId = $this->getVisitorDbId();
        if ($visitorId === null) {
            return true;
        }

        $sessionId = $this->visitor->getSessionId();

        $count = (int)$this->db->queryScalar(
            'SELECT COUNT(*) FROM visits
             WHERE visitor_id = :vid AND session_id = :sid',
            [':vid' => $visitorId, ':sid' => $sessionId],
            0
        );

        return $count <= 1;
    }

    // =========================================================================
    // INTERNAL: VISITOR MANAGEMENT
    // =========================================================================

    /**
     * Get the database ID for the current visitor.
     * Lazily ensures the visitor row exists.
     *
     * @return int|null Visitor ID or null on failure
     */
    private function getVisitorDbId(): ?int
    {
        if ($this->visitorDbId !== null) {
            return $this->visitorDbId;
        }

        try {
            // Look up existing visitor
            $row = $this->db->queryOne(
                'SELECT id FROM visitors WHERE visitor_hash = :hash',
                [':hash' => $this->visitor->getVisitorId()]
            );

            if ($row !== null) {
                $this->visitorDbId = (int)$row['id'];
                $this->visitorEnsured = true;
                return $this->visitorDbId;
            }

            // Insert new visitor
            $newId = $this->db->insert('visitors', [
                'visitor_hash'    => $this->visitor->getVisitorId(),
                'first_seen'      => $this->now,
                'last_seen'       => $this->now,
                'ip_hash'         => $this->visitor->getIpHash(),
                'user_agent'      => $this->visitor->getUserAgent(),
                'browser'         => $this->visitor->getBrowser(),
                'browser_version' => $this->visitor->getBrowserVersion(),
                'os'              => $this->visitor->getOperatingSystem(),
                'device_type'     => $this->visitor->getDeviceType(),
                'language'        => $this->visitor->getLanguage(),
                'is_bot'          => $this->visitor->isBot() ? 1 : 0,
                'visits_count'    => 1,
            ]);

            if ($newId) {
                $this->visitorDbId = (int)$newId;
                $this->visitorEnsured = true;
            }

            return $newId ? (int)$newId : null;

        } catch (\Throwable $e) {
            $this->log('Visitor DB ID lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Ensure the visitor record exists in the database.
     * Creates or updates the visitor row as needed.
     *
     * @return void
     */
    private function ensureVisitor(): void
    {
        if ($this->visitorEnsured) {
            return;
        }

        try {
            $existing = $this->db->queryOne(
                'SELECT id, browser, os, device_type, language, visits_count
                 FROM visitors WHERE visitor_hash = :hash',
                [':hash' => $this->visitor->getVisitorId()]
            );

            if ($existing !== null) {
                $this->visitorDbId = (int)$existing['id'];

                // Update last_seen and potentially update parsed fields
                $updateData = [
                    'last_seen' => $this->now,
                    'visits_count' => (int)$existing['visits_count'] + 1,
                ];

                // Update browser/OS if it changed (user upgraded)
                if ($existing['browser'] !== $this->visitor->getBrowser()) {
                    $updateData['browser'] = $this->visitor->getBrowser();
                    $updateData['browser_version'] = $this->visitor->getBrowserVersion();
                }
                if ($existing['os'] !== $this->visitor->getOperatingSystem()) {
                    $updateData['os'] = $this->visitor->getOperatingSystem();
                }

                $this->db->update('visitors', $updateData, ['id' => $this->visitorDbId]);

            } else {
                // Insert new visitor
                $newId = $this->db->insert('visitors', [
                    'visitor_hash'    => $this->visitor->getVisitorId(),
                    'first_seen'      => $this->now,
                    'last_seen'       => $this->now,
                    'ip_hash'         => $this->visitor->getIpHash(),
                    'user_agent'      => $this->visitor->getUserAgent(),
                    'browser'         => $this->visitor->getBrowser(),
                    'browser_version' => $this->visitor->getBrowserVersion(),
                    'os'              => $this->visitor->getOperatingSystem(),
                    'device_type'     => $this->visitor->getDeviceType(),
                    'language'        => $this->visitor->getLanguage(),
                    'is_bot'          => $this->visitor->isBot() ? 1 : 0,
                    'visits_count'    => 1,
                ]);

                if ($newId) {
                    $this->visitorDbId = (int)$newId;
                }
            }

            $this->visitorEnsured = true;

        } catch (\Throwable $e) {
            $this->log('Ensure visitor failed: ' . $e->getMessage());
        }
    }

    /**
     * Insert a single hit into the visits table.
     * Assumes this is called within a transaction.
     *
     * @param array $hit Hit data
     * @return void
     */
    private function insertHit(array $hit): void
    {
        // Determine bounce status
        $isBounce = 1; // Default: assume bounce until another hit appears
        $visitorId = $this->getVisitorDbId();

        if ($visitorId !== null) {
            // Check if this is a subsequent hit in the same session (not bounce)
            $previousHits = (int)$this->db->queryScalar(
                'SELECT COUNT(*) FROM visits
                 WHERE visitor_id = :vid AND session_id = :sid',
                [
                    ':vid' => $visitorId,
                    ':sid' => $this->visitor->getSessionId(),
                ],
                0
            );

            if ($previousHits > 0) {
                $isBounce = 0;
                // Update the previous hit to not be a bounce anymore
                $this->db->execute(
                    'UPDATE visits SET is_bounce = 0
                     WHERE visitor_id = :vid AND session_id = :sid
                     AND is_bounce = 1',
                    [
                        ':vid' => $visitorId,
                        ':sid' => $this->visitor->getSessionId(),
                    ]
                );
            }
        }

        // Insert the visit
        $this->db->insert('visits', [
            'visitor_id'      => $visitorId ?? 0,
            'session_id'      => $this->visitor->getSessionId(),
            'page_url'        => $hit['page'],
            'page_title'      => $hit['page_title'],
            'referrer'        => $hit['referrer'],
            'referrer_domain' => $hit['referrer_domain'],
            'load_time'       => $hit['load_time'],
            'timestamp'       => $hit['timestamp'],
            'is_bounce'       => $isBounce,
            'is_exit'         => 1, // Assume exit until next hit appears
            'scroll_depth'    => $hit['scroll_depth'],
        ]);
    }
}