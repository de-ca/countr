<?php
/**
 * Security - Security helpers for Countr Analytics.
 *
 * @package Countr\Utils
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Utils;

class Security
{
    /**
     * Generate a cryptographically secure random token.
     *
     * @param int $length Byte length (resulting string will be 2x this)
     * @return string Hex-encoded token
     */
    public static function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Hash a password using bcrypt.
     *
     * @param string $password
     * @return string
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * Verify a password against a bcrypt hash.
     *
     * @param string $password
     * @param string $hash
     * @return bool
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Generate a secure random string suitable for API keys.
     *
     * @param int $length
     * @return string
     */
    public static function generateApiKey(int $length = 48): string
    {
        return 'wc_' . bin2hex(random_bytes($length));
    }

    /**
     * Perform a timing-safe string comparison.
     *
     * @param string $known
     * @param string $provided
     * @return bool
     */
    public static function constantEquals(string $known, string $provided): bool
    {
        return hash_equals($known, $provided);
    }

    /**
     * Sanitize a filename to prevent directory traversal.
     *
     * @param string $filename
     * @return string
     */
    public static function sanitizeFilename(string $filename): string
    {
        // Remove any path components
        $filename = basename($filename);
        // Remove null bytes
        $filename = str_replace("\0", '', $filename);
        // Limit to safe characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        return $filename ?? 'untitled';
    }

    /**
     * Check if a request is from localhost.
     *
     * @return bool
     */
    public static function isLocalhost(): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return in_array($ip, ['127.0.0.1', '::1', 'localhost'], true);
    }

    /**
     * Get a secure CSRF token (stored in session).
     *
     * @return string
     */
    public static function getCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = self::generateToken(16);
        }
        return $_SESSION['_csrf'];
    }

    /**
     * Verify a CSRF token.
     *
     * @param string $token
     * @return bool
     */
    public static function verifyCsrf(string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $stored = $_SESSION['_csrf'] ?? '';
        return $stored !== '' && hash_equals($stored, $token);
    }
}