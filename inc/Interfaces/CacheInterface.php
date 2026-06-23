<?php
/**
 * CacheInterface - Simple key-value cache abstraction.
 *
 * @package Countr\Interfaces
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Interfaces;

interface CacheInterface
{
    /**
     * Get a cached value by key.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get(string $key, $default = null);

    /**
     * Set a cached value.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl Time-to-live in seconds (0 = forever)
     * @return bool
     */
    public function set(string $key, $value, int $ttl = 0): bool;

    /**
     * Check if a key exists in the cache.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Delete a cached value.
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool;

    /**
     * Clear all cached values.
     *
     * @return bool
     */
    public function clear(): bool;
}