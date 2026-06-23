<?php
/**
 * Http - HTTP utility helpers.
 *
 * @package Countr\Utils
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Utils;

class Http
{
    /**
     * Get the current request URL (scheme + host + path, without query string).
     *
     * @return string
     */
    public static function getCurrentUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return "{$scheme}://{$host}{$path}";
    }

    /**
     * Get the base URL (scheme + host + script directory).
     *
     * @return string
     */
    public static function getBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
        return "{$scheme}://{$host}{$dir}";
    }

    /**
     * Get a specific header from the request.
     *
     * @param string $name    Header name (case-insensitive)
     * @param string $default Default value
     * @return string
     */
    public static function getHeader(string $name, string $default = ''): string
    {
        // Convert to the $_SERVER key format
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? $default;
    }

    /**
     * Get the client's IP address (considering proxies).
     *
     * @return string
     */
    public static function getClientIp(): string
    {
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }

    /**
     * Send a JSON response.
     *
     * @param mixed $data
     * @param int   $statusCode HTTP status code
     */
    public static function json($data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Redirect to another URL.
     *
     * @param string $url
     * @param int    $statusCode
     */
    public static function redirect(string $url, int $statusCode = 302): void
    {
        header('Location: ' . $url, true, $statusCode);
        exit;
    }

    /**
     * Set security-related HTTP headers.
     */
    public static function setSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: no-referrer-when-downgrade');
    }

    /**
     * Check if the request is an AJAX request.
     *
     * @return bool
     */
    public static function isAjax(): bool
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    /**
     * Get the request method (GET, POST, etc.).
     *
     * @return string
     */
    public static function getMethod(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }
}