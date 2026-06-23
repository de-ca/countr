<?php
/**
 * Session - Session management extracted from Visitor/Tracker.
 *
 * Handles session ID generation, cookie management, and session lifecycle.
 * Provides a standalone session management layer decoupled from Visitor.
 *
 * @package Countr\Tracking
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Tracking;

class Session
{
    /** @var int Session timeout in seconds (default 30 minutes) */
    private int $timeout;

    /** @var string Cookie name for the session ID */
    private string $cookieName;

    /** @var string|null Generated or retrieved session ID */
    private ?string $sessionId = null;

    /**
     * Constructor.
     *
     * @param int    $timeout    Session timeout in seconds (default 1800 = 30 min)
     * @param string $cookieName Cookie name (default '_wbc_sid')
     */
    public function __construct(int $timeout = 1800, string $cookieName = '_wbc_sid')
    {
        $this->timeout = max(60, $timeout);
        $this->cookieName = $cookieName;
    }

    /**
     * Get or create a session ID.
     *
     * Priority:
     * 1. Active PHP session (hashed for security)
     * 2. Cookie-based session ID (_wbc_sid)
     * 3. Generate new crypto-random session ID
     *
     * @return string 32-character hex session ID
     */
    public function getOrCreateId(): string
    {
        if ($this->sessionId !== null) {
            return $this->sessionId;
        }

        // Use PHP session if active
        if (session_status() === \PHP_SESSION_ACTIVE && !empty(session_id())) {
            $this->sessionId = hash('sha256', session_id());
            return $this->sessionId;
        }

        // Use a cookie-based session ID
        if (isset($_COOKIE[$this->cookieName]) && strlen($_COOKIE[$this->cookieName]) === 32) {
            $this->sessionId = $_COOKIE[$this->cookieName];
            return $this->sessionId;
        }

        // Generate a new crypto-random session ID
        $this->sessionId = bin2hex(random_bytes(16)); // 32 hex chars

        // Set cookie if headers not already sent
        if (!headers_sent()) {
            setcookie(
                $this->cookieName,
                $this->sessionId,
                [
                    'expires'  => time() + $this->timeout,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }

        return $this->sessionId;
    }

    /**
     * Get the current session ID without creating a new one.
     *
     * @return string|null The session ID or null if not set
     */
    public function getId(): ?string
    {
        if ($this->sessionId !== null) {
            return $this->sessionId;
        }

        if (session_status() === \PHP_SESSION_ACTIVE && !empty(session_id())) {
            return hash('sha256', session_id());
        }

        if (isset($_COOKIE[$this->cookieName]) && strlen($_COOKIE[$this->cookieName]) === 32) {
            return $_COOKIE[$this->cookieName];
        }

        return null;
    }

    /**
     * Check if a session already exists (cookie or PHP session).
     *
     * @return bool
     */
    public function exists(): bool
    {
        return $this->getId() !== null;
    }

    /**
     * Set the session timeout.
     *
     * @param int $seconds
     * @return self
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = max(60, $seconds);
        return $this;
    }

    /**
     * Get the session timeout in seconds.
     *
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Clear the session ID (reset internal state).
     *
     * @return void
     */
    public function clear(): void
    {
        $this->sessionId = null;
    }
}