<?php
/**
 * Countr Analytics - Configuration Store
 *
 * Handles loading, saving, and backup of the JSON configuration.
 * Singleton-based storage with dot-notation access.
 *
 * @package    Countr
 * @copyright  2026 Countr Analytics
 * @license    GPL-3.0-or-later
 * @version    1.4.0
 */

declare(strict_types=1);

class ConfigStore
{
    /** @var ConfigStore|null Singleton instance */
    private static ?ConfigStore $instance = null;

    /** @var array<string, mixed> Configuration data */
    private array $data = [];

    /** @var string Path to the JSON config file */
    private string $filePath;

    /** @var string Backup directory path */
    private string $backupDir;

    /** @var array<string, mixed> Default configuration structure */
    private const DEFAULT_CONFIG = [
        'site' => [
            'name' => 'Meine Webseite',
            'url'  => '',
        ],
        'security' => [
            'admin_password'      => '',
            'allowed_ips'         => [],
            'enable_public_stats' => true,
            'api_key'             => null,
            'export_token'        => null,
            'multi_user'          => false,
            'rate_limit'          => [
                'enabled'        => true,
                'max_requests'   => 100,
                'window_seconds' => 60,
            ],
        ],
        'tracking' => [
            'track_referrers' => true,
            'track_browsers'  => true,
            'track_pages'     => true,
            'track_devices'   => true,
            'track_os'        => true,
            'session_timeout' => 1800,
            'ignore_bots'     => true,
            'store_ip'        => false,
            'timezone'        => 'Europe/Berlin',
            'batch_size'      => 10,
            'write_interval'  => 5,
        ],
        'privacy' => [
            'days_to_keep'     => 90,
            'anonymize_ip'     => true,
            'disable_tracking' => false,
            'cookie_free'      => true,
        ],
        'system' => [
            'version'          => '1.4.0',
            'installed_at'     => null,
            'setup_completed'  => false,
            'health_checks'    => true,
            'email_from'       => 'noreply@localhost',
            'email_from_name'  => 'Countr Analytics',
            'usage_tracking'   => true,
        ],
    ];

    // =========================================================================
    // SINGLETON & CONSTRUCTOR
    // =========================================================================

    private function __construct(string $baseDir = '', string $configFile = 'data/config.json')
    {
        $baseDir = $baseDir ?: (defined('COUNTR_DIR') ? COUNTR_DIR : __DIR__ . '/../..');
        $this->filePath  = rtrim($baseDir, '/') . '/' . ltrim($configFile, '/');
        $this->backupDir = rtrim($baseDir, '/') . '/data/backups';

        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0755, true);
        }

        $this->load();
    }

    public static function getInstance(string $baseDir = '', string $configFile = 'data/config.json'): ConfigStore
    {
        if (self::$instance === null) {
            self::$instance = new self($baseDir, $configFile);
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    // =========================================================================
    // LOAD / SAVE
    // =========================================================================

    public function load(?string $file = null): bool
    {
        if ($file !== null) {
            $this->filePath = $file;
        }

        if (!file_exists($this->filePath)) {
            $this->data = self::DEFAULT_CONFIG;
            return false;
        }

        $content = @file_get_contents($this->filePath);
        if ($content === false) {
            $this->data = self::DEFAULT_CONFIG;
            return false;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            $this->data = self::DEFAULT_CONFIG;
            return false;
        }

        $this->data = self::mergeDefaults($decoded, self::DEFAULT_CONFIG);
        return true;
    }

    public function save(?string $file = null): bool
    {
        if ($file !== null) {
            $this->filePath = $file;
        }

        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $this->createBackup();

        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            error_log('[ConfigStore] JSON encoding failed: ' . json_last_error_msg());
            return false;
        }

        $result = @file_put_contents($this->filePath, $json, LOCK_EX);
        if ($result === false) {
            error_log('[ConfigStore] Failed to write config file: ' . $this->filePath);
            return false;
        }

        @chmod($this->filePath, 0644);
        return true;
    }

    // =========================================================================
    // GET / SET / HAS
    // =========================================================================

    /**
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $segments = explode('.', $key);
        $current  = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    public function set(string $key, $value): self
    {
        $segments = explode('.', $key);
        $current  = &$this->data;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
            } else {
                if (!isset($current[$segment]) || !is_array($current[$segment])) {
                    $current[$segment] = [];
                }
                $current = &$current[$segment];
            }
        }

        return $this;
    }

    public function has(string $key): bool
    {
        $segments = explode('.', $key);
        $current  = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }

        return true;
    }

    public function remove(string $key): self
    {
        $segments = explode('.', $key);
        $current  = &$this->data;

        foreach ($segments as $i => $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $this;
            }
            if ($i === count($segments) - 1) {
                unset($current[$segment]);
            } else {
                $current = &$current[$segment];
            }
        }

        return $this;
    }

    /** @return array<string, mixed> */
    public function getAll(): array
    {
        return $this->data;
    }

    public function setAll(array $data): self
    {
        $this->data = self::mergeDefaults($data, self::DEFAULT_CONFIG);
        return $this;
    }

    // =========================================================================
    // FACTORY: CREATE DEFAULT CONFIG
    // =========================================================================

    public static function createDefaultConfig(string $path, array $overrides = []): bool
    {
        $config = self::DEFAULT_CONFIG;

        foreach ($overrides as $key => $value) {
            self::setByDotNotation($config, $key, $value);
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        $result = @file_put_contents($path, $json, LOCK_EX);
        if ($result !== false) {
            @chmod($path, 0644);
        }

        return $result !== false;
    }

    // =========================================================================
    // BACKUP SYSTEM
    // =========================================================================

    /** @return string|false */
    private function createBackup()
    {
        if (!file_exists($this->filePath)) {
            return false;
        }

        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0755, true);
        }

        $timestamp  = date('Ymd_His');
        $backupPath = $this->backupDir . '/config_' . $timestamp . '.json';

        $content = @file_get_contents($this->filePath);
        if ($content === false) {
            return false;
        }

        $result = @file_put_contents($backupPath, $content, LOCK_EX);
        if ($result !== false) {
            @chmod($backupPath, 0644);
            $this->pruneBackups();
        }

        return $result !== false ? $backupPath : false;
    }

    private function pruneBackups(): void
    {
        $pattern = $this->backupDir . '/config_*.json';
        $files   = glob($pattern);

        if ($files === false || count($files) <= 10) {
            return;
        }

        usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));

        $toDelete = array_slice($files, 0, count($files) - 10);
        foreach ($toDelete as $file) {
            @unlink($file);
        }
    }

    /** @return string|false */
    public function createNamedBackup(string $label = 'manual')
    {
        $safeLabel  = preg_replace('/[^a-zA-Z0-9_-]/', '_', $label);
        $timestamp  = date('Ymd_His');
        $backupPath = $this->backupDir . '/config_' . $safeLabel . '_' . $timestamp . '.json';

        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        $result = @file_put_contents($backupPath, $json, LOCK_EX);
        if ($result !== false) {
            @chmod($backupPath, 0644);
        }

        return $result !== false ? $backupPath : false;
    }

    /** @return array<int, array{path: string, date: string, size: int, name: string}> */
    public function listBackups(): array
    {
        $pattern = $this->backupDir . '/config_*.json';
        $files   = glob($pattern);

        if ($files === false) {
            return [];
        }

        $backups = [];
        foreach ($files as $file) {
            $backups[] = [
                'path' => $file,
                'name' => basename($file),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'size' => filesize($file) ?: 0,
            ];
        }

        usort($backups, fn($a, $b) => strcmp($b['date'], $a['date']));

        return $backups;
    }

    public function restoreFromBackup(string $backupPath): bool
    {
        if (!file_exists($backupPath)) {
            return false;
        }

        $content = @file_get_contents($backupPath);
        if ($content === false) {
            return false;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return false;
        }

        $this->createBackup();

        $this->data = self::mergeDefaults($decoded, self::DEFAULT_CONFIG);

        return $this->save();
    }

    // =========================================================================
    // FILE PATH UTILITIES
    // =========================================================================

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getBackupDir(): string
    {
        return $this->backupDir;
    }

    public function fileExists(): bool
    {
        return file_exists($this->filePath);
    }

    // =========================================================================
    // ARRAY ACCESS (for backward compatibility)
    // =========================================================================

    public function __get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function __set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function __isset(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private static function mergeDefaults(array $user, array $defaults): array
    {
        $result = $defaults;

        foreach ($user as $key => $value) {
            if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                $result[$key] = self::mergeDefaults($value, $result[$key]);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private static function setByDotNotation(array &$array, string $key, $value): void
    {
        $segments = explode('.', $key);
        $current  = &$array;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
            } else {
                if (!isset($current[$segment]) || !is_array($current[$segment])) {
                    $current[$segment] = [];
                }
                $current = &$current[$segment];
            }
        }
    }

    private function __clone() {}
}

// =========================================================================
// BACKWARD COMPATIBILITY: Config alias
// =========================================================================
class_alias('ConfigStore', 'Config');