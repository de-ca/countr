<?php
/**
 * Countr Analytics - Setup Wizard Controller
 *
 * Processes POST requests, performs AJAX actions, handles system checks,
 * database initialization, and delegates rendering to the view.
 *
 * Automatically deactivates itself after successful setup for security.
 *
 * @package    Countr
 * @copyright  2026 Countr Analytics
 * @license    GPL-3.0-or-later
 * @version    1.4.0
 */

declare(strict_types=1);

// ========== SECURITY LOCK: HARD ABORT IF ALREADY SET UP ==========
// This runs BEFORE anything else to protect the installation.
// If the database, config, or setup-lock file exist, the system is
// already configured. The setup script MUST NOT be accessible on a
// public webspace – it would allow an attacker to reset the admin
// password and take over the analytics installation.
defined('COUNTR_DIR') or define('COUNTR_DIR', __DIR__);
$setupLockFile = COUNTR_DIR . '/data/.setup_done';
$configFile    = COUNTR_DIR . '/data/config.json';
$databaseFile  = COUNTR_DIR . '/data/countr.db';

if (file_exists($databaseFile) || file_exists($setupLockFile) || file_exists($configFile)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow">';
    echo '<title>Setup bereits abgeschlossen – Zugriff verweigert</title>';
    echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#1a1a2e;color:#eee}';
    echo '.box{background:#16213e;padding:3rem;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.4);max-width:600px;text-align:center;border:2px solid #e94560}';
    echo 'h1{color:#e94560;font-size:1.6rem;margin-bottom:1rem}';
    echo 'p{color:#ccc;font-size:1.05rem;line-height:1.7;margin-bottom:1.5rem}';
    echo '.warning{background:#2d1f1f;border:1px solid #e94560;color:#ff6b6b;padding:1rem;border-radius:6px;font-size:0.95rem;margin:1rem 0;text-align:left}';
    echo '.warning code{background:#1a1a2e;padding:2px 8px;border-radius:3px;color:#ff6b6b}';
    echo 'strong{color:#e94560}</style>';
    echo '</head><body><div class="box">';
    echo '<h1>⛔ Setup bereits abgeschlossen</h1>';
    echo '<p>Countr Analytics wurde bereits eingerichtet. Der Setup-Assistent ist gesperrt, um die Sicherheit Ihrer Installation zu schützen.</p>';
    echo '<div class="warning"><strong>⚠️ Sicherheitswarnung:</strong><br><br>';
    echo 'Die Datei <code>setup.php</code> ist noch auf Ihrem Server vorhanden und über den Browser erreichbar. ';
    echo 'Solange diese Datei existiert, kann ein Angreifer potenziell die Konfiguration überschreiben oder das Admin-Passwort zurücksetzen.<br><br>';
    echo '<strong>Bitte löschen Sie die <code>setup.php</code> aus Sicherheitsgründen vom Server.</strong><br><br>';
    echo 'Oder benennen Sie sie um: <code>mv setup.php setup.php.deleted</code></div>';
    echo '</div></body></html>';
    exit;
}

// ========== ZERO-CONFIG: AUTO FIX PERMISSIONS & .HTACCESS ==========
autoFixOnFirstLoad();

/**
 * Auto-fix permissions and .htaccess on first load.
 *
 * Called automatically every time setup.php is accessed (before any
 * configuration exists). Ensures data/ and cache/ are writable and
 * that .htaccess protection files exist. If automatic fixes fail,
 * a clear, actionable error message is presented to the user.
 */
function autoFixOnFirstLoad(): void
{
    $errors = [];

    // Directories that MUST be writable
    $criticalDirs = ['data', 'cache'];

    // Sub-directories of data/ that also need .htaccess protection
    $protectedDirs = ['data', 'data/backups', 'data/logs', 'cache'];

    // --- STEP 1: Ensure data/ and cache/ exist ---
    foreach ($criticalDirs as $dir) {
        $path = COUNTR_DIR . '/' . $dir;
        if (!is_dir($path)) {
            if (!@mkdir($path, 0775, true)) {
                $errors[] = "Konnte Berechtigungen nicht automatisch setzen. Bitte stellen Sie sicher, dass das Verzeichnis $dir für den Webserver schreibbar ist.";
                continue;
            }
            @chmod($path, 0775);
        }
    }

    // If we couldn't create the directories, stop here with error
    if (count($errors) > 0) {
        renderAutoFixError($errors);
    }

    // --- STEP 2: Check writability and try to fix ---
    foreach ($criticalDirs as $dir) {
        $path = COUNTR_DIR . '/' . $dir;

        if (!is_writable($path)) {
            // Try chmod 0775 first
            if (!@chmod($path, 0775)) {
                // Try chmod 0777 as fallback
                if (!@chmod($path, 0777)) {
                    $errors[] = "Konnte Berechtigungen nicht automatisch setzen. Bitte stellen Sie sicher, dass das Verzeichnis $dir für den Webserver schreibbar ist.";
                }
            }

            // Verify fix worked
            if (!is_writable($path)) {
                $errors[] = "Konnte Berechtigungen nicht automatisch setzen. Bitte stellen Sie sicher, dass das Verzeichnis $dir für den Webserver schreibbar ist.";
            }
        }
    }

    // --- STEP 3: Ensure .htaccess files exist with "Deny from all" ---
    $htaccessContent = "# Zero-Config Auto-Protection – Countr Analytics\n"
        . "# Deny direct access to all files in this directory\n"
        . "Deny from all\n"
        . "# Apache 2.4+\n"
        . "<IfModule mod_authz_core.c>\n"
        . "    Require all denied\n"
        . "</IfModule>\n";

    foreach ($protectedDirs as $dir) {
        $htaccessPath = COUNTR_DIR . '/' . $dir . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            if (is_writable(COUNTR_DIR . '/' . $dir)) {
                @file_put_contents($htaccessPath, $htaccessContent);
            }
            // If dir itself isn't writable, we already reported that above
        }
    }

    if (count($errors) > 0) {
        renderAutoFixError($errors);
    }
}

/**
 * Render a friendly error page when automatic permission fixes fail.
 *
 * @param string[] $errors List of error messages
 */
function renderAutoFixError(array $errors): void
{
    // Only render if we're not in an AJAX context
    $isAjax = ($_GET['action'] ?? $_POST['action'] ?? '') !== '';
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => implode(' ', $errors),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow">';
    echo '<title>Berechtigungsproblem – Countr Setup</title>';
    echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#1a1a2e;color:#eee}';
    echo '.box{background:#16213e;padding:3rem;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.4);max-width:650px;text-align:center;border:2px solid #e94560}';
    echo 'h1{color:#e94560;font-size:1.6rem;margin-bottom:1rem}';
    echo 'p{color:#ccc;font-size:1.05rem;line-height:1.7;margin-bottom:1rem}';
    echo 'code{background:#1a1a2e;padding:2px 8px;border-radius:3px;color:#ff6b6b;font-size:14px}';
    echo '.cmd{background:#0d1117;border:1px solid #30363d;color:#c9d1d9;padding:12px 16px;border-radius:6px;font-family:monospace;font-size:13px;text-align:left;margin:12px 0;overflow-x:auto;white-space:pre-wrap;word-break:break-all}';
    echo 'strong{color:#e94560}</style>';
    echo '</head><body><div class="box">';
    echo '<h1>🔒 Berechtigungsproblem</h1>';
    echo '<p>Countr konnte die benötigten Schreibrechte nicht automatisch einrichten.</p>';

    foreach ($errors as $error) {
        echo '<div class="cmd">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    echo '<p><strong>Manuelle Lösung:</strong> Führen Sie folgende Befehle auf Ihrem Server aus:</p>';
    echo '<div class="cmd">cd ' . htmlspecialchars(COUNTR_DIR, ENT_QUOTES, 'UTF-8') . "\nchmod -R 775 data cache\nfind data cache -type d -exec chmod 775 {} \\;\nfind data cache -type f -exec chmod 664 {} \\;</div>";
    echo '<p style="font-size:0.9rem;color:#999;">Laden Sie diese Seite anschließend neu.</p>';
    echo '</div></body></html>';
    exit;
}

// ========== AJAX ACTION HANDLING ==========
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    switch ($action) {
        case 'check_system':
            $checks    = getSystemStatus();
            $allPassed = array_reduce($checks, fn($carry, $check) => $carry && $check['status'], true);
            echo json_encode(['success' => true, 'checks' => $checks, 'all_passed' => $allPassed], JSON_UNESCAPED_UNICODE);
            exit;

        case 'check_directories':
            echo json_encode(checkDirectoryPermissions(), JSON_UNESCAPED_UNICODE);
            exit;

        case 'create_directories':
            echo json_encode(createDirectories(), JSON_UNESCAPED_UNICODE);
            exit;

        case 'fix_permissions':
            echo json_encode(fixPermissions(), JSON_UNESCAPED_UNICODE);
            exit;

        case 'fix_permissions_all':
            echo json_encode(fixAllPermissions(), JSON_UNESCAPED_UNICODE);
            exit;
    }
}

// ========== PROCESS FORM SUBMISSION ==========
$step         = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error        = '';
$setupDetails = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = isset($_POST['step']) ? (int)$_POST['step'] : 1;

    switch ($step) {
        case 1:
            // System check – just proceed
            $step = 2;
            break;

        case 2:
            // Configuration
            $siteName       = trim($_POST['site_name'] ?? 'Meine Webseite');
            $siteUrl        = trim($_POST['site_url'] ?? 'https://example.com');
            $password       = $_POST['password'] ?? '';
            $passwordConfirm = $_POST['password_confirm'] ?? '';
            $timezone       = $_POST['timezone'] ?? 'Europe/Berlin';
            $anonymizeIp    = !empty($_POST['anonymize_ip']);
            $ignoreBots     = !empty($_POST['ignore_bots']);
            $enablePublic   = !empty($_POST['enable_public']);
            $generateApiKey = !empty($_POST['generate_api_key']);
            $generateDemoData = !empty($_POST['generate_demo_data']);
            $enableMultiUser = !empty($_POST['enable_multi_user']);

            if (strlen($password) < 6) {
                $error = 'Das Passwort muss mindestens 6 Zeichen lang sein.';
                break;
            }
            if ($password !== $passwordConfirm) {
                $error = 'Die Passwörter stimmen nicht überein.';
                break;
            }

            // Attempt setup
            $setupResult = performSetup([
                'site_name'        => $siteName,
                'site_url'         => $siteUrl,
                'password'         => $password,
                'timezone'         => $timezone,
                'anonymize_ip'     => $anonymizeIp,
                'ignore_bots'      => $ignoreBots,
                'enable_public'    => $enablePublic,
                'generate_api_key' => $generateApiKey,
                'generate_demo_data' => $generateDemoData,
                'enable_multi_user' => $enableMultiUser,
            ]);

            if ($setupResult['success']) {
                $step         = 3;
                $setupDetails = $setupResult;
            } else {
                $error = $setupResult['error'] ?? 'Fehler bei der Einrichtung. Bitte prüfen Sie die Schreibrechte.';
            }
            break;
    }
}

// ========== PERFORM SETUP ==========
function performSetup(array $settings): array
{
    $result = ['success' => false, 'error' => '', 'api_key' => null, 'demo_days' => 0];

    // 1. ENSURE DIRECTORY STRUCTURE EXISTS
    $dirResult = createDirectories();
    if (!$dirResult['success']) {
        $failed = [];
        foreach ($dirResult['results'] as $dir => $info) {
            if ($info['status'] === 'failed') {
                $failed[] = $dir;
            }
        }
        if (count($failed) > 0) {
            $result['error'] = "Kann folgende Verzeichnisse nicht erstellen: " . implode(', ', $failed)
                . ". Bitte führen Sie aus:\n"
                . "mkdir -p " . implode(' ', $failed) . "\n"
                . "chmod 755 " . implode(' ', $failed);
            return $result;
        }
    }

    // 1b. FIX ALL PERMISSIONS RECURSIVELY
    fixAllPermissions();

    // 2. CREATE .htaccess FILES
    $dataHtaccess = "# Deny direct access to all JSON and data files\n"
        . "<IfModule mod_authz_core.c>\n"
        . "    Require all denied\n"
        . "</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "    Order allow,deny\n"
        . "    Deny from all\n"
        . "</IfModule>\n";

    $htaccessDirs = ['data', 'cache', 'data/backups', 'data/logs'];
    foreach ($htaccessDirs as $dir) {
        @file_put_contents(COUNTR_DIR . '/' . $dir . '/.htaccess', $dataHtaccess);
    }

    // 3. CREATE index.html FILES (prevent directory listing)
    $indexHtml = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>403</title></head><body><h1>403 Forbidden</h1><p>Direct access to this directory is not allowed.</p></body></html>';
    $indexDirs = ['data', 'cache', 'data/visitors', 'data/sessions', 'data/logs', 'data/backups', 'data/exports'];
    foreach ($indexDirs as $dir) {
        @file_put_contents(COUNTR_DIR . '/' . $dir . '/index.html', $indexHtml);
    }

    // 4. CREATE config.json
    $apiKey = null;
    if ($settings['generate_api_key']) {
        $apiKey = 'countr_' . bin2hex(random_bytes(24));
    }

    $config = [
        'site' => [
            'name' => $settings['site_name'],
            'url'  => $settings['site_url'],
        ],
        'security' => [
            'admin_password'       => password_hash($settings['password'], PASSWORD_BCRYPT),
            'allowed_ips'          => [],
            'enable_public_stats'  => $settings['enable_public'],
            'api_key'              => $apiKey,
            'multi_user'           => $settings['enable_multi_user'],
            'rate_limit' => [
                'enabled'        => true,
                'max_requests'   => 100,
                'window_seconds' => 60,
            ],
        ],
        'tracking' => [
            'track_referrers'  => true,
            'track_browsers'   => true,
            'track_pages'      => true,
            'track_devices'    => true,
            'track_os'         => true,
            'session_timeout'  => 1800,
            'ignore_bots'      => $settings['ignore_bots'],
            'store_ip'         => false,
            'timezone'         => $settings['timezone'],
            'batch_size'       => 10,
            'write_interval'   => 5,
        ],
        'privacy' => [
            'days_to_keep'      => 90,
            'anonymize_ip'      => $settings['anonymize_ip'],
            'disable_tracking'  => false,
            'cookie_free'       => true,
        ],
        'system' => [
            'version'          => '1.4.0',
            'installed_at'     => date('Y-m-d H:i:s'),
            'setup_completed'  => true,
            'health_checks'    => true,
        ],
    ];

    $configJson = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($configJson === false) {
        $result['error'] = 'JSON-Kodierung der Konfiguration fehlgeschlagen.';
        return $result;
    }

    if (@file_put_contents(COUNTR_DIR . '/data/config.json', $configJson, LOCK_EX) === false) {
        $result['error'] = 'Kann data/config.json nicht schreiben. Bitte prüfen Sie die Schreibrechte.';
        return $result;
    }
    @chmod(COUNTR_DIR . '/data/config.json', 0644);

    $result['api_key'] = $apiKey;

    // 4b. WRITE inc/config.php FOR THE LANDING PAGE
    $rootConfigFile = dirname(COUNTR_DIR) . '/inc/config.php';
    $rootConfigContent = "<?php\nreturn [\n    'base_url' => " . var_export($settings['site_url'], true) . ",\n    'api_key'  => " . var_export($apiKey, true) . ",\n];\n";
    @file_put_contents($rootConfigFile, $rootConfigContent, LOCK_EX);
    @chmod($rootConfigFile, 0644);

    // 5. CREATE stats.json
    $stats = [
        'meta' => [
            'first_track' => date('Y-m-d'),
            'last_update' => date('Y-m-d H:i:s'),
            'version'     => '1.4.0',
        ],
        'totals' => [
            'visitors'       => 0,
            'pageviews'      => 0,
            'unique_visitors' => 0,
        ],
        'current' => [
            'online'          => 0,
            'today_visitors'  => 0,
            'today_pageviews' => 0,
            'today_unique'    => 0,
        ],
    ];

    @file_put_contents(COUNTR_DIR . '/data/stats.json', json_encode($stats, JSON_PRETTY_PRINT), LOCK_EX);
    @chmod(COUNTR_DIR . '/data/stats.json', 0644);

    // 6. CREATE TODAY'S VISITOR FILE
    $today   = date('Y-m-d');
    $dayData = [
        'date' => $today,
        'summary' => [
            'visitors'   => 0,
            'pageviews'  => 0,
            'unique'     => 0,
            'avg_time'   => '00:00:00',
            'last_update' => date('H:i:s'),
        ],
        'hours'           => [],
        'top_pages'       => [],
        'referrers'       => [],
        'browsers'        => [],
        'os_distribution' => [],
        'devices'         => [],
        'visitors'        => [],
        '_unique_visitors' => [],
        '_pages'           => [],
    ];
    @file_put_contents(
        COUNTR_DIR . '/data/visitors/' . $today . '.json',
        json_encode($dayData, JSON_PRETTY_PRINT),
        LOCK_EX
    );
    @chmod(COUNTR_DIR . '/data/visitors/' . $today . '.json', 0644);

    // 7. CREATE .gitkeep IN EMPTY DIRS
    $gitkeepDirs = ['data/logs', 'data/backups', 'data/exports', 'data/sessions', 'cache'];
    foreach ($gitkeepDirs as $dir) {
        @file_put_contents(COUNTR_DIR . '/' . $dir . '/.gitkeep', '');
    }

    // 8. GENERATE DEMO DATA (if requested)
    if ($settings['generate_demo_data']) {
        generateDemoData();
        $result['demo_days'] = 30;
    }

    // 9. CREATE BACKUP OF INITIAL STATE
    $backupName = 'data/backups/setup_' . date('Ymd_His') . '.json';
    @file_put_contents(COUNTR_DIR . '/' . $backupName, $configJson, LOCK_EX);

    // 10. MARK SETUP AS DONE
    @file_put_contents(COUNTR_DIR . '/data/.setup_done', date('Y-m-d H:i:s'));
    @chmod(COUNTR_DIR . '/data/.setup_done', 0644);

    // 11. DEACTIVATE setup.php
    @rename(COUNTR_DIR . '/setup.php', COUNTR_DIR . '/setup.php.disabled');

    $result['success'] = true;
    return $result;
}

// ========== GENERATE DEMO DATA ==========
function generateDemoData(): void
{
    $pages     = ['/', '/about', '/contact', '/blog', '/products', '/services', '/faq', '/pricing'];
    $referrers = [
        'https://www.google.com/search?q=example', 'https://www.bing.com/search?q=example',
        'https://duckduckgo.com/', 'https://t.co/shortlink', 'https://www.facebook.com/',
        'https://www.reddit.com/', 'https://news.ycombinator.com/', 'https://github.com/',
        '', '', '',
    ];
    $browsers  = ['Chrome', 'Chrome', 'Chrome', 'Chrome', 'Firefox', 'Firefox', 'Firefox',
        'Safari', 'Safari', 'Edge', 'Opera', 'Samsung Internet'];
    $osList    = ['Windows', 'Windows', 'Windows', 'Windows', 'macOS', 'macOS', 'macOS',
        'Linux', 'Linux', 'Android', 'Android', 'iOS', 'iOS'];
    $devices   = ['Desktop', 'Desktop', 'Desktop', 'Desktop', 'Desktop', 'Desktop', 'Desktop',
        'Desktop', 'Mobile', 'Mobile', 'Mobile', 'Tablet'];

    for ($daysAgo = 30; $daysAgo >= 0; $daysAgo--) {
        $date      = date('Y-m-d', strtotime("-{$daysAgo} days"));
        $visitors  = (int)(30 + (70 * (sin($daysAgo / 3) * 0.5 + 0.5))) + rand(0, 20);
        $pageviews = $visitors + rand(0, (int)($visitors * 0.6));
        $unique    = (int)($visitors * 0.85);

        $dayData = [
            'date' => $date,
            'summary' => [
                'visitors'    => $visitors,
                'pageviews'   => $pageviews,
                'unique'      => $unique,
                'avg_time'    => sprintf('%02d:%02d:%02d', 0, rand(1, 9), rand(0, 59)),
                'last_update' => date('H:i:s', strtotime('-' . $daysAgo . ' days 23:59:59')),
            ],
            'hours'           => [],
            'top_pages'       => [],
            'referrers'       => [],
            'browsers'        => [],
            'os_distribution' => [],
            'devices'         => [],
            'visitors'        => [],
            '_unique_visitors' => [],
            '_pages'           => [],
        ];

        // Hourly distribution (bell curve centered at 14:00)
        for ($hour = 0; $hour < 24; $hour++) {
            $hourMultiplier           = max(0.05, 1 - abs($hour - 14) / 10);
            $dayData['hours'][$hour] = max(0, (int)($visitors * $hourMultiplier / 4));
        }

        // Top pages
        shuffle($pages);
        for ($i = 0; $i < min(5, count($pages)); $i++) {
            $page                           = $pages[$i];
            $dayData['top_pages'][$page]    = max(1, (int)($pageviews * (0.5 - $i * 0.1)));
        }

        // Referrers
        foreach ($referrers as $ref) {
            $label = $ref ?: 'Direct';
            if (!isset($dayData['referrers'][$label])) {
                $dayData['referrers'][$label] = 0;
            }
            $dayData['referrers'][$label] += rand(0, 5);
        }

        // Browsers
        foreach ($browsers as $br) {
            $dayData['browsers'][$br] = ($dayData['browsers'][$br] ?? 0) + rand(0, 3);
        }

        // OS distribution
        foreach ($osList as $os) {
            $dayData['os_distribution'][$os] = ($dayData['os_distribution'][$os] ?? 0) + rand(0, 3);
        }

        // Devices
        foreach ($devices as $dev) {
            $dayData['devices'][$dev] = ($dayData['devices'][$dev] ?? 0) + rand(0, 3);
        }

        @file_put_contents(
            COUNTR_DIR . '/data/visitors/' . $date . '.json',
            json_encode($dayData, JSON_PRETTY_PRINT),
            LOCK_EX
        );
        @chmod(COUNTR_DIR . '/data/visitors/' . $date . '.json', 0644);
    }

    // Update stats.json with demo totals
    $stats  = json_decode(@file_get_contents(COUNTR_DIR . '/data/stats.json') ?: '{}', true) ?: [];
    $totals = ['visitors' => 0, 'pageviews' => 0, 'unique_visitors' => 0];

    for ($daysAgo = 30; $daysAgo >= 0; $daysAgo--) {
        $date    = date('Y-m-d', strtotime("-{$daysAgo} days"));
        $dayFile = COUNTR_DIR . '/data/visitors/' . $date . '.json';
        if (file_exists($dayFile)) {
            $dayJson                    = json_decode(@file_get_contents($dayFile) ?: '{}', true) ?: [];
            $totals['visitors']        += $dayJson['summary']['visitors'] ?? 0;
            $totals['pageviews']       += $dayJson['summary']['pageviews'] ?? 0;
            $totals['unique_visitors'] += $dayJson['summary']['unique'] ?? 0;
        }
    }

    $stats['totals'] = $totals;
    @file_put_contents(COUNTR_DIR . '/data/stats.json', json_encode($stats, JSON_PRETTY_PRINT), LOCK_EX);
}

// ========== CREATE DIRECTORIES (AJAX-capable) ==========
function createDirectories(): array
{
    $directories = [
        'data',
        'data/visitors',
        'data/sessions',
        'data/logs',
        'data/backups',
        'data/exports',
        'cache',
    ];

    $results    = [];
    $allSuccess = true;

    foreach ($directories as $dir) {
        $path = COUNTR_DIR . '/' . $dir;

        if (!is_dir($path)) {
            if (@mkdir($path, 0755, true)) {
                @file_put_contents($path . '/.htaccess', "Deny from all\n");
                @file_put_contents($path . '/index.html', '');
                $results[$dir] = [
                    'status'      => 'created',
                    'permissions' => '0755',
                    'message'     => 'Verzeichnis erfolgreich erstellt',
                ];
            } else {
                $lastError      = error_get_last();
                $results[$dir]  = [
                    'status'  => 'failed',
                    'error'   => $lastError['message'] ?? 'Unbekannter Fehler',
                    'message' => 'Konnte Verzeichnis nicht erstellen',
                    'fix'     => 'Bitte manuell ausführen: mkdir -p ' . $dir . ' && chmod 755 ' . $dir,
                ];
                $allSuccess = false;
            }
        } else {
            @chmod($path, 0755);
            $results[$dir] = [
                'status'      => 'exists',
                'permissions' => substr(sprintf('%o', fileperms($path)), -4),
                'writable'    => is_writable($path),
                'message'     => is_writable($path) ? 'Verzeichnis existiert und ist schreibbar' : 'Verzeichnis existiert, ist aber NICHT schreibbar',
            ];
        }
    }

    return [
        'success' => $allSuccess,
        'results' => $results,
    ];
}

// ========== CHECK DIRECTORY PERMISSIONS ==========
function checkDirectoryPermissions(): array
{
    $dirs    = ['data', 'cache'];
    $results = [];
    $allOk   = true;

    foreach ($dirs as $dir) {
        $path = COUNTR_DIR . '/' . $dir;

        if (!is_dir($path)) {
            $results[$dir] = [
                'exists'      => false,
                'writable'    => false,
                'permissions' => null,
                'status'      => 'missing',
                'message'     => 'Verzeichnis existiert nicht',
                'fix'         => "Klicken Sie auf 'Verzeichnisse automatisch erstellen' oder führen Sie manuell aus:\n"
                               . "mkdir -p " . $dir . " && chmod 755 " . $dir,
            ];
            $allOk = false;
        } else {
            $writable      = is_writable($path);
            $perms         = substr(sprintf('%o', fileperms($path)), -4);
            $results[$dir] = [
                'exists'      => true,
                'writable'    => $writable,
                'permissions' => $perms,
                'status'      => $writable ? 'ok' : 'not_writable',
                'message'     => $writable
                    ? 'Schreibbar (Rechte: ' . $perms . ')'
                    : 'Nicht schreibbar (Rechte: ' . $perms . ') – Benötigt 755 oder 775',
                'fix'         => $writable ? null : "Führen Sie aus: chmod 755 " . $dir . "\nOder: chmod 775 " . $dir . " (je nach Server-Konfiguration)",
            ];
            if (!$writable) {
                $allOk = false;
            }
        }
    }

    return [
        'success' => true,
        'all_ok'  => $allOk,
        'results' => $results,
    ];
}

// ========== FIX PERMISSIONS (basic – individual dirs only) ==========
function fixPermissions(): array
{
    $dirs    = ['data', 'cache', 'data/visitors', 'data/sessions', 'data/logs', 'data/backups', 'data/exports'];
    $results = [];
    $allOk   = true;

    foreach ($dirs as $dir) {
        $path = COUNTR_DIR . '/' . $dir;
        if (is_dir($path)) {
            if (@chmod($path, 0755)) {
                $results[$dir] = [
                    'status'      => 'fixed',
                    'permissions' => '0755',
                    'message'     => 'Rechte auf 755 gesetzt',
                ];
            } else {
                $results[$dir] = [
                    'status'      => 'failed',
                    'permissions' => substr(sprintf('%o', fileperms($path)), -4),
                    'message'     => 'Konnte Rechte nicht ändern',
                    'fix'         => 'Bitte manuell ausführen: chmod 755 ' . $dir,
                ];
                $allOk = false;
            }
        }
    }

    return [
        'success' => $allOk,
        'results' => $results,
    ];
}

// ========== FIX ALL PERMISSIONS RECURSIVELY ==========
function fixAllPermissions(): array
{
    $directories = ['data', 'cache'];
    $summary     = [
        'success'      => true,
        'total_dirs'   => 0,
        'total_files'  => 0,
        'fixed_dirs'   => 0,
        'fixed_files'  => 0,
        'failed_dirs'  => 0,
        'failed_files' => 0,
        'results'      => [],
    ];

    foreach ($directories as $dir) {
        $path = COUNTR_DIR . '/' . $dir;
        if (!is_dir($path)) {
            $summary['results'][$dir] = [
                'status'  => 'missing',
                'message' => "Verzeichnis existiert nicht: $dir",
            ];
            $summary['success'] = false;
            continue;
        }

        $dirResult                = setRecursivePermissions($path);
        $summary['results'][$dir] = $dirResult;

        $summary['total_dirs']   += $dirResult['total_dirs'];
        $summary['total_files']  += $dirResult['total_files'];
        $summary['fixed_dirs']   += $dirResult['fixed_dirs'];
        $summary['fixed_files']  += $dirResult['fixed_files'];
        $summary['failed_dirs']  += $dirResult['failed_dirs'];
        $summary['failed_files'] += $dirResult['failed_files'];

        if (!$dirResult['success']) {
            $summary['success'] = false;
        }
    }

    // Log the operation
    $logDir = COUNTR_DIR . '/data/logs';
    if (is_dir($logDir) && is_writable($logDir)) {
        $logEntry = date('Y-m-d H:i:s') . ' | fix_permissions_all | '
            . 'dirs: ' . $summary['fixed_dirs'] . '/' . $summary['total_dirs']
            . ' files: ' . $summary['fixed_files'] . '/' . $summary['total_files']
            . ' failed_dirs: ' . $summary['failed_dirs']
            . ' failed_files: ' . $summary['failed_files']
            . ' | success: ' . ($summary['success'] ? 'yes' : 'no') . "\n";
        @file_put_contents($logDir . '/permissions.log', $logEntry, FILE_APPEND | LOCK_EX);
    }

    return $summary;
}

/**
 * Setzt rekursive Rechte für ein Verzeichnis und alle Unterelemente.
 * Verzeichnisse erhalten 0755, Dateien 0644.
 */
function setRecursivePermissions(string $dir, int $dirPerm = 0755, int $filePerm = 0644): array
{
    $result = [
        'success'      => true,
        'total_dirs'   => 0,
        'total_files'  => 0,
        'fixed_dirs'   => 0,
        'fixed_files'  => 0,
        'failed_dirs'  => 0,
        'failed_files' => 0,
        'failed_items' => [],
    ];

    if (!is_dir($dir)) {
        $result['success']        = false;
        $result['failed_items'][] = [
            'path'  => $dir,
            'type'  => 'dir',
            'error' => 'Verzeichnis existiert nicht',
        ];
        return $result;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();

            if ($item->isDir()) {
                $result['total_dirs']++;
                if (@chmod($path, $dirPerm)) {
                    $result['fixed_dirs']++;
                } else {
                    $result['failed_dirs']++;
                    $result['success']        = false;
                    $error                     = error_get_last();
                    $result['failed_items'][] = [
                        'path'  => str_replace(COUNTR_DIR . '/', '', $path),
                        'type'  => 'dir',
                        'error' => $error['message'] ?? 'chmod fehlgeschlagen',
                    ];
                }
            } else {
                $result['total_files']++;
                if (@chmod($path, $filePerm)) {
                    $result['fixed_files']++;
                } else {
                    $result['failed_files']++;
                    $result['success']        = false;
                    $error                     = error_get_last();
                    $result['failed_items'][] = [
                        'path'  => str_replace(COUNTR_DIR . '/', '', $path),
                        'type'  => 'file',
                        'error' => $error['message'] ?? 'chmod fehlgeschlagen',
                    ];
                }
            }
        }

        @chmod($dir, $dirPerm);
    } catch (\Exception $e) {
        $result['success']        = false;
        $result['failed_items'][] = [
            'path'  => $dir,
            'type'  => 'error',
            'error' => $e->getMessage(),
        ];
    }

    return $result;
}

// ========== ENHANCED SYSTEM CHECK ==========
function getSystemStatus(): array
{
    $checks = [];

    // PHP Version
    $phpOk                     = version_compare(PHP_VERSION, '7.0.0', '>=');
    $checks['php_version']     = [
        'name'        => 'PHP Version',
        'required'    => '7.0+',
        'current'     => PHP_VERSION,
        'status'      => $phpOk,
        'status_text' => $phpOk ? '✅ OK' : '❌ Zu alt',
        'fix'         => $phpOk ? null : 'PHP 7.0 oder höher installieren. Aktuelle Version: https://www.php.net/downloads',
        'category'    => 'server',
    ];

    // JSON Extension
    $jsonOk                    = extension_loaded('json');
    $checks['json_extension']  = [
        'name'        => 'JSON Extension',
        'required'    => 'Erforderlich',
        'current'     => $jsonOk ? 'Installiert' : 'Fehlt',
        'status'      => $jsonOk,
        'status_text' => $jsonOk ? '✅ OK' : '❌ Fehlt',
        'fix'         => $jsonOk ? null : 'PHP JSON-Extension aktivieren: extension=json in php.ini oder Paket php-json installieren',
        'category'    => 'php_extensions',
    ];

    // Data directory writable
    $dataDir                   = COUNTR_DIR . '/data';
    $dataWritable              = is_dir($dataDir) ? is_writable($dataDir) : is_writable(COUNTR_DIR);
    $checks['data_writable']   = [
        'name'        => 'Schreibrechte (data/)',
        'required'    => 'Schreibbar',
        'current'     => $dataWritable ? 'Schreibbar' : 'Nicht schreibbar',
        'status'      => $dataWritable,
        'status_text' => $dataWritable ? '✅ OK' : '⚠️ Problem',
        'fix'         => $dataWritable ? null : 'Klicken Sie auf "Verzeichnisse automatisch erstellen" oder führen Sie manuell aus: mkdir -p data data/visitors data/sessions data/logs data/backups data/exports cache && chmod 755 data cache',
        'category'    => 'filesystem',
        'auto_fix'    => 'create_directories',
    ];

    // flock support
    $flockOk                   = function_exists('flock');
    $checks['flock_support']   = [
        'name'        => 'Datei-Locking (flock)',
        'required'    => 'Empfohlen',
        'current'     => $flockOk ? 'Verfügbar' : 'Nicht verfügbar',
        'status'      => $flockOk,
        'status_text' => $flockOk ? '✅ OK' : '⚠️ Nicht verfügbar',
        'fix'         => $flockOk ? null : 'flock() ist nicht verfügbar. Die Anwendung funktioniert trotzdem, aber parallele Schreibzugriffe sind weniger sicher. Keine Aktion nötig.',
        'category'    => 'php_functions',
    ];

    // Bcrypt support
    $bcryptOk                  = defined('PASSWORD_BCRYPT');
    $checks['bcrypt_support']  = [
        'name'        => 'Bcrypt (Passwort-Hash)',
        'required'    => 'Erforderlich',
        'current'     => $bcryptOk ? 'Verfügbar' : 'Nicht verfügbar',
        'status'      => $bcryptOk,
        'status_text' => $bcryptOk ? '✅ OK' : '❌ Nicht verfügbar',
        'fix'         => $bcryptOk ? null : 'PHP muss mit Bcrypt-Unterstützung kompiliert sein (PHP 5.5+ standardmäßig enthalten). Aktualisieren Sie PHP.',
        'category'    => 'php_functions',
    ];

    // Memory Limit
    $memoryLimit               = ini_get('memory_limit');
    $memoryBytes               = return_bytes($memoryLimit);
    $memoryOk                  = $memoryBytes >= 128 * 1024 * 1024 || $memoryBytes === -1;
    $checks['memory_limit']    = [
        'name'        => 'Memory Limit',
        'required'    => '≥ 128 MB',
        'current'     => $memoryLimit,
        'status'      => $memoryOk,
        'status_text' => $memoryOk ? '✅ OK' : '⚠️ Niedrig',
        'fix'         => $memoryOk ? null : 'Erhöhen Sie das Memory Limit in php.ini: memory_limit = 256M',
        'category'    => 'server',
    ];

    // Max Execution Time
    $execTime                  = ini_get('max_execution_time');
    $execOk                    = (int)$execTime >= 30 || (int)$execTime === 0;
    $checks['execution_time']  = [
        'name'        => 'Max. Ausführungszeit',
        'required'    => '≥ 30s',
        'current'     => $execTime . 's',
        'status'      => $execOk,
        'status_text' => $execOk ? '✅ OK' : '⚠️ Niedrig',
        'fix'         => $execOk ? null : 'Erhöhen Sie die Ausführungszeit in php.ini: max_execution_time = 60',
        'category'    => 'server',
    ];

    // Cache directory
    $cacheDir                  = COUNTR_DIR . '/cache';
    $cacheOk                   = is_dir($cacheDir) ? is_writable($cacheDir) : is_writable(COUNTR_DIR);
    $checks['cache_writable']  = [
        'name'        => 'Schreibrechte (cache/)',
        'required'    => 'Schreibbar',
        'current'     => $cacheOk ? 'Schreibbar' : 'Nicht schreibbar',
        'status'      => $cacheOk,
        'status_text' => $cacheOk ? '✅ OK' : '⚠️ Problem',
        'fix'         => $cacheOk ? null : 'Klicken Sie auf "Verzeichnisse automatisch erstellen" oder führen Sie manuell aus: mkdir -p cache && chmod 755 cache',
        'category'    => 'filesystem',
        'auto_fix'    => 'create_directories',
    ];

    // GD / Image Processing
    $gdOk                      = extension_loaded('gd');
    $checks['gd_extension']    = [
        'name'        => 'GD Extension (Grafik)',
        'required'    => 'Optional',
        'current'     => $gdOk ? 'Installiert' : 'Nicht installiert',
        'status'      => true,
        'status_text' => $gdOk ? '✅ OK' : 'ℹ️ Nicht installiert',
        'fix'         => $gdOk ? null : 'Optional: Installieren Sie php-gd für erweiterte Grafik-Features',
        'category'    => 'php_extensions',
    ];

    return $checks;
}

function return_bytes(string $val): int
{
    $val  = trim($val);
    $last = strtolower($val[strlen($val) - 1] ?? '');
    $val  = (int)$val;
    switch ($last) {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}

// ========== PREPARE DATA FOR VIEW ==========
$systemChecks     = getSystemStatus();
$allPassed        = array_reduce($systemChecks, fn($carry, $check) => $carry && $check['status'], true);

// Group checks by category for display
$checksByCategory = [];
foreach ($systemChecks as $key => $check) {
    $category = $check['category'] ?? 'other';
    if (!isset($checksByCategory[$category])) {
        $checksByCategory[$category] = [];
    }
    $checksByCategory[$category][$key] = $check;
}

// ========== DETECT BASE URL FOR TRACKING CODE ==========
$detectedUrl  = 'http' . (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 's' : '') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$detectedPath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');

// ========== OUTPUT – DELEGATE TO VIEW ==========
header('Content-Type: text/html; charset=utf-8');
require __DIR__ . '/views/setup_view.php';