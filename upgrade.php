<?php
/**
 * Countr Analytics - Upgrade Script
 * 
 * Automatically migrates data and configuration from older versions.
 * Safe to run multiple times – only applies needed changes.
 * 
 * @package    Countr
 * @copyright  2026 Countr Analytics
 * @license    GPL-3.0-or-later
 * @version    1.4.0
 */

declare(strict_types=1);

define('COUNTR_DIR', __DIR__);

// ========== HEADER ==========
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Countr Analytics - Upgrade</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 20px;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            padding: 2rem;
            max-width: 700px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
        }
        h1 { font-size: 1.5rem; margin-bottom: 1.5rem; }
        h2 { font-size: 1.1rem; margin: 1.5rem 0 0.5rem; color: #555; }
        .log { background: #1a1a2e; color: #cdd6f4; padding: 1rem; border-radius: 8px; font-family: monospace; font-size: 13px; max-height: 400px; overflow-y: auto; margin: 1rem 0; }
        .log .ok { color: #a6e3a1; }
        .log .warn { color: #f9e2af; }
        .log .err { color: #f38ba8; }
        .log .info { color: #89b4fa; }
        .btn {
            display: inline-block; padding: 10px 24px;
            background: #667eea; color: #fff;
            text-decoration: none; border-radius: 6px;
            font-weight: 600; margin-top: 1rem;
        }
        .btn:hover { background: #5a6fd6; }
        .success { background: #f0fff4; border: 1px solid #c6f6d5; padding: 1rem; border-radius: 8px; margin: 1rem 0; }
    </style>
</head>
<body>
<div class="card">
    <h1>🔄 Countr Analytics Upgrade</h1>
    <pre class="log"><?php

// ========== RUN UPGRADE STEPS ==========
$steps = 0;
$errors = 0;
$warnings = 0;

function logMsg(string $msg, string $level = 'info'): void {
    $class = match($level) { 'ok' => 'ok', 'warn' => 'warn', 'err' => 'err', default => 'info' };
    echo "<span class=\"{$class}\">[" . strtoupper($level) . "]</span> {$msg}\n";
    flush();
}

// ========== STEP 1: CHECK DIRECTORY STRUCTURE ==========
logMsg('Prüfe Verzeichnisstruktur...', 'info');
$dirs = [
    'data' => true,
    'data/visitors' => true,
    'data/sessions' => true,
    'data/logs' => true,
    'data/backups' => true,
    'data/exports' => true,
    'cache' => true,
];

foreach ($dirs as $dir => $required) {
    $path = COUNTR_DIR . '/' . $dir;
    if (!is_dir($path)) {
        if (@mkdir($path, 0755, true)) {
            logMsg("Verzeichnis erstellt: {$dir}", 'ok');
            $steps++;
        } else {
            logMsg("FEHLER: Kann Verzeichnis nicht erstellen: {$dir}", 'err');
            $errors++;
        }
    }
}

// ========== STEP 2: CHECK CONFIG ==========
logMsg('Prüfe Konfiguration...', 'info');
$configFile = COUNTR_DIR . '/data/config.json';

if (file_exists($configFile)) {
    $config = json_decode(@file_get_contents($configFile) ?: '{}', true) ?: [];
    
    // Ensure all required config keys exist
    $defaults = [
        'site' => ['name' => 'Meine Webseite', 'url' => ''],
        'security' => [
            'admin_password' => $config['security']['admin_password'] ?? '',
            'allowed_ips' => $config['security']['allowed_ips'] ?? [],
            'enable_public_stats' => $config['security']['enable_public_stats'] ?? true,
            'api_key' => $config['security']['api_key'] ?? null,
            'export_token' => $config['security']['export_token'] ?? null,
        ],
        'tracking' => [
            'track_referrers' => true,
            'track_browsers' => true,
            'track_pages' => true,
            'session_timeout' => 1800,
            'ignore_bots' => true,
            'timezone' => 'Europe/Berlin',
            'buffer_size' => 10,
        ],
        'privacy' => [
            'days_to_keep' => 90,
            'anonymize_ip' => true,
            'disable_tracking' => false,
        ],
        'system' => [
            'version' => '1.3.0',
            'installed_at' => $config['system']['installed_at'] ?? date('Y-m-d H:i:s'),
            'upgraded_at' => date('Y-m-d H:i:s'),
        ],
    ];

    $updated = false;
    foreach ($defaults as $section => $values) {
        if (!isset($config[$section])) {
            $config[$section] = $values;
            $updated = true;
        } else {
            foreach ($values as $key => $default) {
                if (!isset($config[$section][$key])) {
                    $config[$section][$key] = $default;
                    $updated = true;
                }
            }
        }
    }

    // Update version
    $oldVersion = $config['system']['version'] ?? '1.0.0';
    $config['system']['version'] = '1.3.0';
    $config['system']['upgraded_at'] = date('Y-m-d H:i:s');

    if ($updated || $oldVersion !== '1.3.0') {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($configFile, $json, LOCK_EX)) {
            logMsg("Konfiguration aktualisiert (v{$oldVersion} → v1.3.0)", 'ok');
            $steps++;
        } else {
            logMsg("FEHLER: Kann config.json nicht schreiben", 'err');
            $errors++;
        }
    } else {
        logMsg('Konfiguration ist aktuell.', 'ok');
    }
} else {
    logMsg('Keine config.json gefunden – bitte setup.php ausführen.', 'warn');
    $warnings++;
}

// ========== STEP 3: CREATE .htaccess FILES ==========
logMsg('Erstelle .htaccess-Schutzdateien...', 'info');
$dirsToProtect = ['data', 'data/visitors', 'data/sessions', 'data/logs', 'data/backups', 'data/exports', 'cache'];
$htaccessContent = "# Deny direct access\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";

foreach ($dirsToProtect as $dir) {
    $htaccessPath = COUNTR_DIR . '/' . $dir . '/.htaccess';
    if (!file_exists($htaccessPath)) {
        @file_put_contents($htaccessPath, $htaccessContent);
        logMsg(".htaccess erstellt: {$dir}/", 'ok');
        $steps++;
    }
}

// ========== STEP 4: PROTECT setup.php ==========
logMsg('Prüfe setup.php Sicherheit...', 'info');
$setupPath = COUNTR_DIR . '/setup.php';
if (file_exists($setupPath)) {
    logMsg('WARNUNG: setup.php ist noch aktiv! Aus Sicherheitsgründen deaktivieren.', 'warn');
    $warnings++;
}

// ========== STEP 5: CHECK FILE PERMISSIONS ==========
logMsg('Prüfe Dateirechte...', 'info');
$writableDirs = ['data', 'cache'];
foreach ($writableDirs as $dir) {
    $path = COUNTR_DIR . '/' . $dir;
    if (is_dir($path) && !is_writable($path)) {
        @chmod($path, 0755);
        if (is_writable($path)) {
            logMsg("Schreibrechte korrigiert: {$dir}/", 'ok');
            $steps++;
        } else {
            logMsg("WARNUNG: {$dir}/ ist nicht schreibbar!", 'warn');
            $warnings++;
        }
    }
}

// ========== STEP 6: VERIFY MODULAR FILES ==========
logMsg('Prüfe modulare Dateien...', 'info');
$requiredFiles = [
    'inc/Tracking/BotDetector.php',
    'inc/Tracking/Visitor/BrowserDetector.php',
    'inc/Tracking/Visitor/ReferrerAnalyzer.php',
    'inc/Tracking/Session.php',
    'inc/Utils/Logger.php',
    'inc/Utils/Validator.php',
    'inc/Utils/Security.php',
    'inc/Utils/Http.php',
    'inc/Utils/Cache.php',
];

foreach ($requiredFiles as $file) {
    $path = COUNTR_DIR . '/' . $file;
    if (!file_exists($path)) {
        logMsg("FEHLT: {$file}", 'err');
        $errors++;
    }
}
if ($errors === 0) {
    logMsg('Alle modularen Dateien vorhanden.', 'ok');
}

// ========== SUMMARY ==========
echo "</pre>";

echo "<div style=\"margin-top:1rem;\">";
echo "<p><strong>Ergebnis:</strong> {$steps} Änderungen, {$warnings} Warnungen, {$errors} Fehler</p>";

if ($errors === 0) {
    echo "<div class=\"success\">✅ Upgrade erfolgreich abgeschlossen!</div>";
    echo '<a href="index.php" class="btn">📊 Zum Dashboard</a> ';
    echo '<a href="admin.php" class="btn">⚙️ Zum Admin</a>';
} else {
    echo "<div style=\"background:#fff3f3;border:1px solid #ffcaca;padding:1rem;border-radius:8px;margin:1rem 0;\">⚠ Es gab {$errors} Fehler. Bitte prüfen Sie die Logs oben und stellen Sie sicher, dass alle Dateien vorhanden sind.</div>";
}

echo "</div>";
?>
</div>
</body>
</html>