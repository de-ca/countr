<?php
/**
 * Countr Analytics - Configuration Validator
 *
 * Validates the configuration data structure, types, constraints,
 * and business rules. Does NOT load/save – that's ConfigStore's job.
 *
 * @package    Countr
 * @copyright  2026 Countr Analytics
 * @license    GPL-3.0-or-later
 * @version    1.4.0
 */

declare(strict_types=1);

class ConfigValidator
{
    /** @var array<string, mixed> Reference to the config data to validate */
    private array $data;

    /**
     * @param array<string, mixed> $data The configuration data to validate
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /**
     * Validate the full configuration structure.
     * Returns an array of validation errors (empty = valid).
     *
     * @return array<string, string> Error messages keyed by path
     */
    public function validate(): array
    {
        $errors = [];

        // Required top-level keys
        $requiredKeys = ['site', 'security', 'tracking', 'privacy', 'system'];
        foreach ($requiredKeys as $key) {
            if (!isset($this->data[$key]) || !is_array($this->data[$key])) {
                $errors[$key] = "Missing required configuration section: '{$key}'";
            }
        }

        $errors = array_merge($errors, $this->validateSite());
        $errors = array_merge($errors, $this->validateSecurity());
        $errors = array_merge($errors, $this->validateTracking());
        $errors = array_merge($errors, $this->validatePrivacy());

        return $errors;
    }

    /**
     * Check if the configuration is valid.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return count($this->validate()) === 0;
    }

    // =========================================================================
    // SECTION VALIDATORS
    // =========================================================================

    /** @return array<string, string> */
    private function validateSite(): array
    {
        $errors = [];

        if (isset($this->data['site'])) {
            if (empty($this->data['site']['name'])) {
                $errors['site.name'] = 'Site name is required';
            }
            if (!empty($this->data['site']['url']) && !filter_var($this->data['site']['url'], FILTER_VALIDATE_URL)) {
                $errors['site.url'] = 'Site URL must be a valid URL';
            }
        }

        return $errors;
    }

    /** @return array<string, string> */
    private function validateSecurity(): array
    {
        $errors = [];

        if (!isset($this->data['security'])) {
            return $errors;
        }

        $adminPw = $this->data['security']['admin_password'] ?? '';
        if (!empty($adminPw)) {
            if (substr($adminPw, 0, 4) !== '$2y$' && substr($adminPw, 0, 4) !== '$2b$') {
                $errors['security.admin_password'] = 'Admin password is not a valid bcrypt hash';
            }
        }

        if (isset($this->data['security']['allowed_ips']) && !is_array($this->data['security']['allowed_ips'])) {
            $errors['security.allowed_ips'] = 'allowed_ips must be an array';
        }

        if (isset($this->data['security']['rate_limit'])) {
            $rl = $this->data['security']['rate_limit'];
            if (isset($rl['max_requests']) && (!is_int($rl['max_requests']) || $rl['max_requests'] < 0)) {
                $errors['security.rate_limit.max_requests'] = 'max_requests must be a positive integer';
            }
            if (isset($rl['window_seconds']) && (!is_int($rl['window_seconds']) || $rl['window_seconds'] < 1)) {
                $errors['security.rate_limit.window_seconds'] = 'window_seconds must be at least 1';
            }
        }

        return $errors;
    }

    /** @return array<string, string> */
    private function validateTracking(): array
    {
        $errors = [];

        if (!isset($this->data['tracking'])) {
            return $errors;
        }

        $timeout = $this->data['tracking']['session_timeout'] ?? 0;
        if ($timeout < 60) {
            $errors['tracking.session_timeout'] = 'session_timeout must be at least 60 seconds';
        }

        $batchSize = $this->data['tracking']['batch_size'] ?? 0;
        if ($batchSize < 1 || $batchSize > 1000) {
            $errors['tracking.batch_size'] = 'batch_size must be between 1 and 1000';
        }

        $writeInterval = $this->data['tracking']['write_interval'] ?? 0;
        if ($writeInterval < 1 || $writeInterval > 300) {
            $errors['tracking.write_interval'] = 'write_interval must be between 1 and 300';
        }

        // Validate timezone
        if (!empty($this->data['tracking']['timezone'])) {
            try {
                new \DateTimeZone($this->data['tracking']['timezone']);
            } catch (\Exception $e) {
                $errors['tracking.timezone'] = "Invalid timezone: {$this->data['tracking']['timezone']}";
            }
        }

        return $errors;
    }

    /** @return array<string, string> */
    private function validatePrivacy(): array
    {
        $errors = [];

        if (!isset($this->data['privacy'])) {
            return $errors;
        }

        $daysToKeep = $this->data['privacy']['days_to_keep'] ?? 0;
        if ($daysToKeep < 1 || $daysToKeep > 3650) {
            $errors['privacy.days_to_keep'] = 'days_to_keep must be between 1 and 3650';
        }

        return $errors;
    }

    // =========================================================================
    // STATIC VALIDATION (for pre-save checks)
    // =========================================================================

    /**
     * Validate a single config value by key and value.
     *
     * @param string $key   Dot-notation key
     * @param mixed  $value The proposed new value
     * @return string|null  Error message or null if valid
     */
    public static function validateValue(string $key, $value): ?string
    {
        switch ($key) {
            case 'site.name':
                if (!is_string($value) || trim($value) === '') {
                    return 'Site name must be a non-empty string';
                }
                break;

            case 'site.url':
                if (!is_string($value) || (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL))) {
                    return 'Site URL must be a valid URL or empty';
                }
                break;

            case 'security.admin_password':
                if (is_string($value) && !empty($value)) {
                    if (substr($value, 0, 4) !== '$2y$' && substr($value, 0, 4) !== '$2b$') {
                        return 'Password must be a valid bcrypt hash';
                    }
                }
                break;

            case 'security.rate_limit.max_requests':
                if (!is_int($value) || $value < 0) {
                    return 'max_requests must be a positive integer';
                }
                break;

            case 'security.rate_limit.window_seconds':
                if (!is_int($value) || $value < 1) {
                    return 'window_seconds must be at least 1';
                }
                break;

            case 'tracking.session_timeout':
                if (!is_int($value) || $value < 60) {
                    return 'session_timeout must be at least 60 seconds';
                }
                break;

            case 'tracking.batch_size':
                if (!is_int($value) || $value < 1 || $value > 1000) {
                    return 'batch_size must be between 1 and 1000';
                }
                break;

            case 'tracking.write_interval':
                if (!is_int($value) || $value < 1 || $value > 300) {
                    return 'write_interval must be between 1 and 300';
                }
                break;

            case 'privacy.days_to_keep':
                if (!is_int($value) || $value < 1 || $value > 3650) {
                    return 'days_to_keep must be between 1 and 3650';
                }
                break;

            case 'security.multi_user':
                if (!is_bool($value)) {
                    return 'multi_user must be a boolean';
                }
                break;

            case 'security.enable_public_stats':
                if (!is_bool($value)) {
                    return 'enable_public_stats must be a boolean';
                }
                break;

            default:
                // Unknown keys pass through
                break;
        }

        return null;
    }

    /**
     * Validate a batch of key-value pairs.
     *
     * @param array<string, mixed> $pairs
     * @return array<string, string> Errors keyed by path
     */
    public static function validateBatch(array $pairs): array
    {
        $errors = [];

        foreach ($pairs as $key => $value) {
            $error = self::validateValue($key, $value);
            if ($error !== null) {
                $errors[$key] = $error;
            }
        }

        return $errors;
    }
}