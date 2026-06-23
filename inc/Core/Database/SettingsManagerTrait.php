<?php
/**
 * SettingsManagerTrait - Cached key-value settings management.
 *
 * Extracted from Connection.php. Provides settings CRUD with in-memory
 * caching and flat-to-nested array conversion with type coercion.
 *
 * v1.6.0: Extracted from Connection.php as part of modular refactoring.
 *
 * @package Countr\Core\Database
 * @copyright  2026 Countr Analytics
 * @version 1.6.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Core\Database;

use PDO;
use PDOException;
use Throwable;

/**
 * Trait SettingsManagerTrait
 *
 * Expects the host class to provide:
 *   - ?PDO $this->pdo
 *   - bool $this->connected
 *   - ?string $this->lastError
 *   - method ensureConnected(): void
 */
trait SettingsManagerTrait
{
    /** @var array<string, mixed> Runtime cache for settings */
    private array $settingsCache = [];

    /** @var bool Whether settings cache is loaded */
    private bool $settingsLoaded = false;

    /**
     * Load settings from the database into cache.
     */
    private function loadSettings(): void
    {
        if (!$this->connected || $this->pdo === null) {
            $this->settingsLoaded = true;
            return;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT key, value FROM settings');
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->settingsCache = [];
            foreach ($rows as $row) {
                $this->settingsCache[$row['key']] = $row['value'];
            }
            $this->settingsLoaded = true;
        } catch (Throwable $e) {
            $this->settingsLoaded = true;
        }
    }

    /**
     * Get a setting value by key.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function getSetting(string $key, $default = null)
    {
        if (!$this->settingsLoaded) {
            $this->loadSettings();
        }
        return $this->settingsCache[$key] ?? $default;
    }

    /**
     * Set a setting value (insert or update).
     *
     * @param string $key
     * @param mixed  $value
     * @return bool
     */
    public function setSetting(string $key, $value): bool
    {
        $this->ensureConnected();

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, CURRENT_TIMESTAMP)
                 ON CONFLICT(key) DO UPDATE SET value = :value2, updated_at = CURRENT_TIMESTAMP'
            );
            $result = $stmt->execute([
                ':key'    => $key,
                ':value'  => (string)$value,
                ':value2' => (string)$value,
            ]);

            if ($result) {
                $this->settingsCache[$key] = (string)$value;
            }

            return $result;
        } catch (PDOException $e) {
            $this->lastError = "Set setting failed: {$e->getMessage()}";
            return false;
        }
    }

    /**
     * Get all settings as an associative array.
     *
     * @return array<string, string>
     */
    public function getAllSettings(): array
    {
        if (!$this->settingsLoaded) {
            $this->loadSettings();
        }
        return $this->settingsCache;
    }

    /**
     * Get ALL settings as a properly nested array from flat dotted keys.
     *
     * Normalizes both dotted keys (site.name) and legacy underscore keys
     * (site_name → site.name) into one consistent nested structure:
     *   ['site' => ['name' => '...'], 'tracking' => ['timezone' => '...'], ...]
     *
     * Type coercion rules:
     *   - '0'/'1' strings for boolean-like keys → bool (for checkboxes)
     *   - Numeric strings for int keys → int
     *   - Everything else stays as string (for flexibility)
     *
     * @return array<string, mixed>
     */
    public function getSettingsAsNestedArray(): array
    {
        if (!$this->settingsLoaded) {
            $this->loadSettings();
        }

        $legacyMap = [
            'site_name'                  => 'site.name',
            'timezone'                   => 'tracking.timezone',
            'tracking_ignore_bots'       => 'tracking.ignore_bots',
            'tracking_session_timeout'   => 'tracking.session_timeout',
            'tracking_buffer_size'       => 'tracking.buffer_size',
            'privacy_anonymize_ip'       => 'privacy.anonymize_ip',
            'privacy_store_ip'           => 'privacy.store_ip',
            'enable_public_stats'        => 'security.enable_public_stats',
            'api_key'                    => 'security.api_key',
            'export_token'               => 'security.export_token',
            'installed_at'               => 'system.installed_at',
            'schema_version'             => 'system.schema_version',
        ];

        $booleanKeys = [
            'tracking.ignore_bots',
            'privacy.anonymize_ip',
            'privacy.store_ip',
            'privacy.disable_tracking',
            'security.enable_public_stats',
        ];

        $integerKeys = [
            'tracking.session_timeout',
            'tracking.buffer_size',
            'tracking.rate_limit',
            'tracking.rate_limit_window',
            'privacy.days_to_keep',
        ];

        $flat = [];
        foreach ($this->settingsCache as $rawKey => $value) {
            $canonicalKey = $legacyMap[$rawKey] ?? $rawKey;
            if (!isset($flat[$canonicalKey])) {
                $flat[$canonicalKey] = $value;
            }
        }

        $result = [];
        foreach ($flat as $dottedKey => $value) {
            $coerced = $value;
            if (in_array($dottedKey, $booleanKeys, true)) {
                $coerced = ($value === '1' || $value === 'true');
            } elseif (in_array($dottedKey, $integerKeys, true)) {
                $coerced = (int) $value;
            }

            $segments = explode('.', $dottedKey);
            $current = &$result;
            foreach ($segments as $i => $segment) {
                if ($i === count($segments) - 1) {
                    $current[$segment] = $coerced;
                } else {
                    if (!isset($current[$segment]) || !is_array($current[$segment])) {
                        $current[$segment] = [];
                    }
                    $current = &$current[$segment];
                }
            }
        }

        $defaults = [
            'site' => ['name' => 'Meine Webseite', 'url' => ''],
            'tracking' => ['timezone' => 'Europe/Berlin', 'session_timeout' => 1800, 'ignore_bots' => true],
            'privacy' => ['anonymize_ip' => true, 'days_to_keep' => 90, 'disable_tracking' => false],
            'security' => ['admin_password' => '', 'enable_public_stats' => true, 'api_key' => null, 'export_token' => null],
            'system' => ['schema_version' => '1.0.0', 'installed_at' => null],
        ];

        return array_replace_recursive($defaults, $result);
    }
}