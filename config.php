<?php
/**
 * Countr Analytics - Configuration Backward-Compatibility Shim
 *
 * This file exists for backward compatibility. Since v1.4.0, the
 * configuration management has been split into two specialized classes:
 *   - ConfigStore (inc/Core/ConfigStore.php) – loading, saving, backup
 *   - ConfigValidator (inc/Core/ConfigValidator.php) – validation
 *
 * This file requires the new classes and creates a class_alias so that
 * existing code referencing `Config` continues to work unchanged.
 *
 * @package    Countr
 * @copyright  2026 Countr Analytics
 * @license    GPL-3.0-or-later
 * @version    1.4.0
 */

declare(strict_types=1);

// Require the new specialized classes
require_once __DIR__ . '/inc/Core/ConfigStore.php';
require_once __DIR__ . '/inc/Core/ConfigValidator.php';

// ConfigStore already has `class_alias('ConfigStore', 'Config')` at its end,
// so any `require 'config.php'` will transparently provide the `Config` class.