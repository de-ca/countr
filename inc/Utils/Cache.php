<?php
/**
 * Cache - Simple file-based cache system.
 *
 * @package Countr\Utils
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Utils;

class Cache
{
    /** @var string Cache directory */
    private string $cacheDir;

    /** @var int Default TTL in seconds */
    private int $defaultTTL;

    /**
     * @param string $cacheDir   Path to cache directory
     * @param int    $defaultTTL Default TTL in seconds
     */
    public function __construct(string $cacheDir, int $defaultTTL = 300)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->defaultTTL = max(1, $defaultTTL);

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Store a value in the cache.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl Seconds (0 = use default)
     * @return bool
     */
    public function set(string $key, $value, int $ttl = 0): bool
    {
        $file = $this->getFilePath($key);
        $expires = time() + ($ttl > 0 ? $ttl : $this->defaultTTL);

        $data = [
            'expires' => $expires,
            'data'    => $value,
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }

        return @file_put_contents($file, $json, LOCK_EX) !== false;
    }

    /**
     * Retrieve a value from the cache.
     *
     * @param string $key
     * @param mixed  $default Value to return if cache miss
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return $default;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return $default;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $default;
        }

        // Check expiration
        if (($data['expires'] ?? 0) < time()) {
            @unlink($file);
            return $default;
        }

        return $data['data'] ?? $default;
    }

    /**
     * Check if a cache key exists and is not expired.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    /**
     * Delete a cache entry.
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            return @unlink($file);
        }
        return true;
    }

    /**
     * Clear all cached items.
     *
     * @return int Number of deleted files
     */
    public function clear(): int
    {
        $count = 0;
        $files = glob($this->cacheDir . '/*.cache');
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Remove all expired cache entries.
     *
     * @return int Number of removed entries
     */
    public function pruneExpired(): int
    {
        $count = 0;
        $files = glob($this->cacheDir . '/*.cache');
        if ($files === false) {
            return 0;
        }

        $now = time();
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (($data['expires'] ?? 0) < $now) {
                        if (@unlink($file)) {
                            $count++;
                        }
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Get the file path for a cache key.
     *
     * @param string $key
     * @return string
     */
    private function getFilePath(string $key): string
    {
        // Use md5 to create a safe filename
        $safeKey = md5($key);
        return $this->cacheDir . '/' . $safeKey . '.cache';
    }
}