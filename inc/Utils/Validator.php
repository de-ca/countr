<?php
/**
 * Validator - Input validation utility.
 *
 * @package Countr\Utils
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Utils;

class Validator
{
    /**
     * Validate and sanitize a date string.
     *
     * @param string $date
     * @param string $default Default value if invalid
     * @return string YYYY-MM-DD or default
     */
    public static function date(string $date, string $default = ''): string
    {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false) {
            return $date;
        }
        return $default;
    }

    /**
     * Validate an integer within a range.
     *
     * @param mixed $value
     * @param int   $min
     * @param int   $max
     * @param int   $default
     * @return int
     */
    public static function int($value, int $min = 0, int $max = PHP_INT_MAX, int $default = 0): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false || $int < $min || $int > $max) {
            return $default;
        }
        return $int;
    }

    /**
     * Validate and sanitize a URL path (for pages).
     *
     * @param string $path
     * @param string $default
     * @return string
     */
    public static function pagePath(string $path, string $default = '/'): string
    {
        $path = trim($path);
        if ($path === '') {
            return $default;
        }
        // Ensure it starts with /
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        // Remove any null bytes or control characters
        $path = preg_replace('/[\x00-\x1F\x7F]/', '', $path);
        return $path !== null ? $path : $default;
    }

    /**
     * Validate an email address.
     *
     * @param string $email
     * @return bool
     */
    public static function email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate and sanitize a string (strip tags, trim, limit length).
     *
     * @param string $value
     * @param int    $maxLength
     * @return string
     */
    public static function string(string $value, int $maxLength = 255): string
    {
        $value = strip_tags($value);
        $value = trim($value);
        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }
        return $value;
    }

    /**
     * Check if a value is a valid timezone identifier.
     *
     * @param string $tz
     * @return bool
     */
    public static function timezone(string $tz): bool
    {
        try {
            new \DateTimeZone($tz);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}