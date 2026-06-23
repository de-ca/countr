<?php
/**
 * Countr Analytics - Admin Control Panel
 *
 * Password-protected administration area with 7 tabs:
 *   Overview, Visitors, Pages, Referrers, Settings, Export, Tools
 *
 * Features: Login with bcrypt, session management, date filtering,
 *           CSV/JSON/Excel export, settings editor, backup/restore,
 *           data cleanup, IP management, and system info.
 *
 * @package    Countr
 * @copyright  2026 Countr Analytics
 * @license    GPL-3.0-or-later
 * @version    1.6.0
 */

declare(strict_types=1);

// =========================================================================
// BOOTSTRAP & i18n
// =========================================================================
define('COUNTR_DIR', __DIR__);

// i18n (internationalization)
$i18nBase = dirname(__DIR__) . '/i18n.php';
if (file_exists($i18nBase)) {
    require_once $i18nBase;
} elseif (file_exists(__DIR__ . '/../i18n.php')) {
    require_once __DIR__ . '/../i18n.php';
} else {
    // Fallback: minimal translation helper if i18n.php is missing
    function __(string $key): string { return $key; }
}

// Redirect to setup if not installed (check for SQLite database)
if (!file_exists(__DIR__ . '/data/countr.db')) {
    $setupPath = __DIR__ . '/setup.php';
    if (file_exists($setupPath)) {
        header('Location: setup.php');
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(503);
    echo '<!DOCTYPE html><html lang="' . ($lang ?? 'en') . '"><head><meta charset="utf-8"><title>' . __('admin.not_installed') . '</title></head><body><h1>' . __('admin.not_installed') . '</h1><p>' . __('admin.not_installed_desc') . '</p></body></html>';
    exit;
}

// Load dependencies
require_once __DIR__ . '/inc/autoload.php';
require_once __DIR__ . '/inc/Visitor.php';
require_once __DIR__ . '/inc/Tracker.php';
require_once __DIR__ . '/inc/Stats.php';

// Initialize SQLite database via DatabaseFacade
use Countr\Core\Database\DatabaseFacade;
$db = DatabaseFacade::getInstance();

/**
 * Convert ISO 3166-1 alpha-2 country code to flag emoji.
 */
function countryFlag(string $code): string {
    if (strlen($code) !== 2) return '';
    $code = strtoupper($code);
    $a = ord($code[0]);
    $b = ord($code[1]);
    $oA = ord('A');
    if ($a < $oA || $a > ord('Z') || $b < $oA || $b > ord('Z')) return '';
    $low = strtolower($code);
    $file = __DIR__ . "/assets/flags/{$low}.svg";
    if (!file_exists($file)) return '';
    return '<img src="assets/flags/' . $low . '.svg" width="20" height="15" alt="' . $code . '" style="vertical-align: middle; margin-right: 6px;">';
}

/**
 * Get the display name for a country code (simple local map).
 */
function countryName(string $code): string {
    $map = [
        'DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz',
        'FR' => 'Frankreich', 'IT' => 'Italien', 'ES' => 'Spanien',
        'PT' => 'Portugal', 'NL' => 'Niederlande', 'BE' => 'Belgien',
        'PL' => 'Polen', 'CZ' => 'Tschechien', 'SK' => 'Slowakei',
        'HU' => 'Ungarn', 'RO' => 'Rumänien', 'BG' => 'Bulgarien',
        'HR' => 'Kroatien', 'RS' => 'Serbien', 'SI' => 'Slowenien',
        'EE' => 'Estland', 'LV' => 'Lettland', 'LT' => 'Litauen',
        'FI' => 'Finnland', 'SE' => 'Schweden', 'NO' => 'Norwegen',
        'DK' => 'Dänemark', 'IS' => 'Island', 'GR' => 'Griechenland',
        'TR' => 'Türkei', 'RU' => 'Russland', 'UA' => 'Ukraine',
        'SA' => 'Saudi-Arabien', 'IL' => 'Israel', 'JP' => 'Japan',
        'KR' => 'Südkorea', 'CN' => 'China', 'TH' => 'Thailand',
        'VN' => 'Vietnam', 'ID' => 'Indonesien', 'MY' => 'Malaysia',
        'IN' => 'Indien', 'BD' => 'Bangladesch', 'PK' => 'Pakistan',
        'IR' => 'Iran', 'GB' => 'Großbritannien', 'US' => 'USA',
        'CA' => 'Kanada', 'AU' => 'Australien',
    ];
    return $map[strtoupper($code)] ?? strtoupper($code);
}

// Ensure database is connected before loading settings so
// getSettingsAsNestedArray() can read real values from SQLite.
$db->connect();

// Build $rawConfig from SQLite settings table via unified nested array
$rawConfig = $db->getSettingsAsNestedArray();

// =========================================================================
// SESSION & AUTHENTICATION
// =========================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('ADMIN_SESSION_KEY', 'wb_admin_logged_in');
define('ADMIN_SESSION_TIME', 'wb_admin_login_time');
define('SESSION_TIMEOUT', 3600); // 1 hour

// Handle logout
if (isset($_GET['logout'])) {
    unset($_SESSION[ADMIN_SESSION_KEY], $_SESSION[ADMIN_SESSION_TIME]);
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Check session timeout
if (!empty($_SESSION[ADMIN_SESSION_KEY]) && !empty($_SESSION[ADMIN_SESSION_TIME])) {
    if (time() - (int)$_SESSION[ADMIN_SESSION_TIME] > SESSION_TIMEOUT) {
        unset($_SESSION[ADMIN_SESSION_KEY], $_SESSION[ADMIN_SESSION_TIME]);
    }
}

$isLoggedIn = !empty($_SESSION[ADMIN_SESSION_KEY]);

// Generate CSRF token for form protection
$csrfToken = \Countr\Utils\Security::getCsrfToken();

// Auto-initialize admin password if missing from SQLite settings table
if (empty($rawConfig['security']['admin_password'])) {
    $defaultHash = password_hash('admin', PASSWORD_BCRYPT);
    $rawConfig['security']['admin_password'] = $defaultHash;
    $db->setSetting('security.admin_password', $defaultHash);
}

// Handle login POST
$loginError = '';
if (!$isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // Verify CSRF token for login form
    $loginCsrfValid = \Countr\Utils\Security::verifyCsrf($_POST['_csrf'] ?? '');
    if (!$loginCsrfValid) {
        $loginError = __('admin.csrf_error');
    } else {
        $password = $_POST['password'] ?? '';
        $hash = $rawConfig['security']['admin_password'] ?? '';

        if ($hash && password_verify($password, $hash)) {
            session_regenerate_id(true);
            $_SESSION[ADMIN_SESSION_KEY] = true;
            $_SESSION[ADMIN_SESSION_TIME] = time();
            $isLoggedIn = true;
            header('Location: admin.php');
            exit;
        }
        $loginError = __('admin.login_error');
    }
}

// =========================================================================
// INITIALIZE TRACKER & STATS
// =========================================================================
$visitor = Visitor::fromCurrentRequest();
$tracker = new Tracker($db, $visitor, $rawConfig);
$stats = new Stats($db, $tracker, $rawConfig);

date_default_timezone_set($rawConfig['tracking']['timezone'] ?? 'Europe/Berlin');
$tracker->flushBuffer();

// =========================================================================
// HANDLE ADMIN ACTIONS (when logged in)
// =========================================================================
$message = '';
$messageType = 'info';

if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- CSRF Protection for all POST actions ---
    $csrfValid = \Countr\Utils\Security::verifyCsrf($_POST['_csrf'] ?? '');
    if (!$csrfValid) {
        $message = __('admin.csrf_error');
        $messageType = 'error';
    } else {
        // --- Save Settings (to SQLite) ---
        if (isset($_POST['save_settings'])) {
            // Validate DB connection before persisting
            if (!$db->isConnected()) {
                $db->connect();
                if (!$db->isConnected()) {
                    $message = __('admin.settings_error_db');
                    $messageType = 'error';
                }
            }

            if (empty($message)) {
                $rawConfig['site']['name'] = trim($_POST['site_name'] ?? $rawConfig['site']['name']);
                $rawConfig['site']['url'] = trim($_POST['site_url'] ?? $rawConfig['site']['url']);
                $rawConfig['tracking']['timezone'] = $_POST['timezone'] ?? $rawConfig['tracking']['timezone'];
                $rawConfig['tracking']['session_timeout'] = (int)($_POST['session_timeout'] ?? 1800);
                $rawConfig['tracking']['ignore_bots'] = !empty($_POST['ignore_bots']);
                $rawConfig['privacy']['days_to_keep'] = (int)($_POST['days_to_keep'] ?? 90);
                $rawConfig['privacy']['disable_tracking'] = !empty($_POST['disable_tracking']);
                $rawConfig['security']['enable_public_stats'] = !empty($_POST['enable_public_stats']);

                // Persist all settings to SQLite via flat key-value pairs
                $saveResults = [];
                $saveResults['site.name'] = $db->setSetting('site.name', $rawConfig['site']['name']);
                $saveResults['site.url'] = $db->setSetting('site.url', $rawConfig['site']['url']);
                $saveResults['tracking.timezone'] = $db->setSetting('tracking.timezone', $rawConfig['tracking']['timezone']);
                $saveResults['tracking.session_timeout'] = $db->setSetting('tracking.session_timeout', (string)$rawConfig['tracking']['session_timeout']);
                $saveResults['tracking.ignore_bots'] = $db->setSetting('tracking.ignore_bots', $rawConfig['tracking']['ignore_bots'] ? '1' : '0');
                $saveResults['privacy.days_to_keep'] = $db->setSetting('privacy.days_to_keep', (string)$rawConfig['privacy']['days_to_keep']);
                $saveResults['privacy.disable_tracking'] = $db->setSetting('privacy.disable_tracking', $rawConfig['privacy']['disable_tracking'] ? '1' : '0');
                $saveResults['security.enable_public_stats'] = $db->setSetting('security.enable_public_stats', $rawConfig['security']['enable_public_stats'] ? '1' : '0');

                $saveErrors = array_filter($saveResults, fn($v) => $v === false);
                if (!empty($saveErrors)) {
                    $allFailed = count($saveErrors) === count($saveResults);
                    $message = $allFailed ? __('admin.settings_error_save_full') : __('admin.settings_error_save');
                    $messageType = 'error';
                } else {
                    $stats->invalidateCache();
                    header("Location: admin.php?saved=1");
                    exit;
                }
                $stats->invalidateCache();
            }
        }

        // --- Change Password (SQLite) ---
        if (isset($_POST['change_password'])) {
            $currentPw = $_POST['current_password'] ?? '';
            $newPw = $_POST['new_password'] ?? '';
            $confirmPw = $_POST['confirm_password'] ?? '';

            if (!password_verify($currentPw, $rawConfig['security']['admin_password'] ?? '')) {
                $message = __('admin.password_wrong');
                $messageType = 'error';
            } elseif (strlen($newPw) < 6) {
                $message = __('admin.password_too_short');
                $messageType = 'error';
            } elseif ($newPw !== $confirmPw) {
                $message = __('admin.password_mismatch');
                $messageType = 'error';
            } else {
                $hash = password_hash($newPw, PASSWORD_BCRYPT);
                $rawConfig['security']['admin_password'] = $hash;
                $db->setSetting('security.admin_password', $hash);
                $message = __('admin.password_changed');
                $messageType = 'success';
            }
        }

        // --- Cleanup Data ---
        if (isset($_POST['cleanup_data'])) {
            $days = (int)($_POST['cleanup_days'] ?? 90);
            $deleted = $tracker->cleanup($days);
            $stats->invalidateCache();
            $message = "{$deleted} " . __('admin.tools_cleanup_done') . " {$days} " . __('admin.tools_cleanup_done_suffix');
            $messageType = 'success';
        }

        // --- Generate API Token (SQLite) ---
        if (isset($_POST['generate_token'])) {
            $token = 'wc_' . bin2hex(random_bytes(24));
            $rawConfig['security']['api_key'] = $token;
            $db->setSetting('security.api_key', $token);
            $message = __('admin.api_generated');
            $messageType = 'success';
        }

        // --- Generate Export Token (SQLite) ---
        if (isset($_POST['generate_export_token'])) {
            $token = bin2hex(random_bytes(32));
            $rawConfig['security']['export_token'] = $token;
            $db->setSetting('security.export_token', $token);
            $message = __('admin.export_token_generated');
            $messageType = 'success';
        }

        // --- Create Backup (SQLite) ---
        if (isset($_POST['create_backup'])) {
            $backupResult = $db->backup();
            if ($backupResult) {
                $message = __('admin.tools_backup_success') . ' ' . basename($backupResult);
                $messageType = 'success';
            } else {
                $message = __('admin.tools_backup_error');
                $messageType = 'error';
            }
        }
    } // end CSRF check
}

// =========================================================================
// FETCH DATA FOR ADMIN DASHBOARD
// =========================================================================
$todaySummary = $tracker->getTodaySummary();
$overallStats = $tracker->getOverallStats();
$online = $tracker->getOnlineCount();
$last7Days = $tracker->getLastNDays(7);
$last30Days = $tracker->getLastNDays(30);
$topPages = $tracker->getTopPages(10);
$topReferrers = $tracker->getTopReferrers(10);
$browsers = $tracker->getBrowserDistribution();
$osDist = $tracker->getOSDistribution();
$devices = $tracker->getDeviceDistribution();
$bounceRate = $stats->estimateBounceRate();
$peakHour = $stats->getPeakHour();
$growthTrend = $stats->getGrowthTrends();
$siteName = htmlspecialchars($rawConfig['site']['name'] ?? 'My Website', ENT_QUOTES, 'UTF-8');

// Date filter
$filterFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$filterTo = $_GET['to'] ?? date('Y-m-d');
$rangeData = $tracker->getRangeStats($filterFrom, $filterTo);

// Backups list — scan the DB backup directory
$backupDir = __DIR__ . '/data/backups';
$backups = [];
if (is_dir($backupDir)) {
    $files = glob($backupDir . '/countr_*.db*');
    if ($files !== false) {
        foreach ($files as $file) {
            $backups[] = [
                'path' => $file,
                'name' => basename($file),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'size' => filesize($file) ?: 0,
            ];
        }
        usort($backups, function ($a, $b) {
            return strcmp($b['date'], $a['date']);
        });
    }
}

// =========================================================================
// PREPARE CHART DATA (for Visitors tab)
// =========================================================================
// Browser distribution
$browserLabels = [];
$browserValues = [];
$browserRows = [];
foreach ($browsers as $row) {
    $browserRows[$row['browser'] ?? 'Unknown'] = (int)($row['count'] ?? 0);
}
arsort($browserRows);
foreach (array_slice($browserRows, 0, 10) as $b => $c) {
    $browserLabels[] = $b;
    $browserValues[] = $c;
}

// OS distribution
$osLabels = [];
$osValues = [];
$osRows = [];
foreach ($osDist as $row) {
    $osRows[$row['os'] ?? 'Unknown'] = (int)($row['count'] ?? 0);
}
arsort($osRows);
foreach (array_slice($osRows, 0, 8) as $o => $c) {
    $osLabels[] = $o;
    $osValues[] = $c;
}

// Device distribution
$deviceLabels = [];
$deviceValues = [];
$deviceRows = [];
foreach ($devices as $row) {
    $deviceRows[$row['device_type'] ?? 'Unknown'] = (int)($row['count'] ?? 0);
}
arsort($deviceRows);
foreach ($deviceRows as $d => $c) {
    $deviceLabels[] = ucfirst($d);
    $deviceValues[] = $c;
}

// Pages data
$pagesLabels = [];
$pagesValues = [];
$pagesData = [];
$rank = 1;
foreach ($topPages as $idx => $page) {
    if (is_array($page)) {
        $url = $page['page_url'] ?? (array_key_first($page) ?? '');
        $count = (int)($page['total_views'] ?? (array_values($page)[0] ?? 0));
    } else {
        $url = (string)$idx;
        $count = (int)$page;
    }
    if ($url === '') continue;
    $pagesLabels[] = $url;
    $pagesValues[] = $count;
    $pagesData[] = ['url' => $url, 'count' => $count, 'rank' => $rank++];
}

// Referrers data
$refLabels = [];
$refValues = [];
$refData = [];
$refRank = 1;
foreach ($topReferrers as $refEntry) {
    $refLabel = is_array($refEntry)
        ? ($refEntry['referrer_domain'] ?? array_key_first($refEntry) ?? 'Direct')
        : (string)$refEntry;
    $refCount = is_array($refEntry)
        ? (int)($refEntry['visits'] ?? (array_values($refEntry)[0] ?? 0))
        : 0;
    if ($refLabel === '') $refLabel = 'Direct';
    $refLabels[] = $refLabel;
    $refValues[] = $refCount;
    $refData[] = ['label' => $refLabel, 'count' => $refCount, 'rank' => $refRank++];
}

// Build JSON chart data for initial render
$adminChartData = [
    'browsers' => ['labels' => $browserLabels, 'values' => $browserValues],
    'os' => ['labels' => $osLabels, 'values' => $osValues],
    'devices' => ['labels' => $deviceLabels, 'values' => $deviceValues],
    'pages' => ['labels' => $pagesLabels, 'values' => $pagesValues],
    'referrers' => ['labels' => $refLabels, 'values' => $refValues],
];

// =========================================================================
// AJAX ENDPOINT
// =========================================================================
if ($isLoggedIn && isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'online' => $online,
        'today' => $todaySummary,
        'overall' => $overallStats,
    ]);
    exit;
}

// =========================================================================
// HELPERS
// =========================================================================
function fmtNum($val): string {
    return number_format((int)$val, 0, ',', '.');
}
function e($str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
function fmtBytes($bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// =========================================================================
// RENDER: LOGIN PAGE
// =========================================================================
if (!$isLoggedIn) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="<?= ($lang ?? 'en') ?>" data-theme="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= __('admin.login_title') ?></title>
        <link rel="stylesheet" href="assets/css/dashboard.css">
        <link rel="stylesheet" href="assets/css/admin.css">
        <style>
            body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
            .login-box { background: #fff; border-radius: 16px; padding: 2.5rem; box-shadow: 0 20px 60px rgba(0,0,0,0.2); max-width: 420px; width: 100%; text-align: center; }
            .login-box h1 { font-size: 1.5rem; margin-bottom: 0.5rem; color: #1a1a2e; }
            .login-box .sub { color: #6b7280; margin-bottom: 1.5rem; font-size: 0.9rem; }
            .login-box input[type="password"] { width: 100%; padding: 12px 16px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 1rem; margin-bottom: 1rem; text-align: center; transition: border-color 0.2s; }
            .login-box input[type="password"]:focus { outline: none; border-color: #667eea; }
            .login-box button { width: 100%; padding: 12px; background: #667eea; color: #fff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.2s; }
            .login-box button:hover { background: #5a6fd6; }
            .login-box .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; }
            .login-box .back { display: block; margin-top: 1rem; color: #667eea; font-size: 0.85rem; text-decoration: none; }
            .login-lang { position: absolute; top: 1rem; right: 1.5rem; }
            .login-lang a { color: #fff; text-decoration: none; font-weight: 600; font-size: 0.9rem; background: rgba(255,255,255,0.2); padding: 8px 14px; border-radius: 6px; transition: background 0.2s; }
            .login-lang a:hover { background: rgba(255,255,255,0.35); }
        </style>
    </head>
    <body>
        <div class="login-lang">
            <a href="?lang=<?= ($lang ?? 'en') === 'de' ? 'en' : 'de' ?>">
                <?= ($lang ?? 'en') === 'de' ? __('admin.lang_switch_en') : __('admin.lang_switch_de') ?>
            </a>
        </div>
        <div class="login-box">
            <h1><?= __('admin.login_heading') ?></h1>
            <p class="sub"><?= __('admin.login_subtitle') ?></p>
            <?php if ($loginError): ?>
                <div class="error"><?= e($loginError) ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                <input type="password" name="password" placeholder="<?= __('admin.login_password_placeholder') ?>" required autofocus>
                <button type="submit" name="login"><?= __('admin.login_button') ?></button>
            </form>
            <a class="back" href="index.php"><?= __('admin.login_back') ?></a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// =========================================================================
// BUILD JS I18N BRIDGE
// =========================================================================
$adminI18n = [
    'visitors' => __('chart.visitors'),
    'pageviews' => __('chart.pageviews'),
    'no_data' => __('dash.no_data'),
    'dark' => __('admin.theme_dark'),
    'light' => __('admin.theme_light'),
    'browserDistTitle' => __('admin.browser_dist'),
    'osDistTitle' => __('admin.os_dist'),
    'deviceDistTitle' => __('admin.device_dist'),
    'pagesTitle' => __('admin.pages_title'),
    'referrersTitle' => __('admin.referrers_title'),
    'unknown' => 'Unknown',
];

// =========================================================================
// RENDER: ADMIN PANEL
// =========================================================================
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="<?= ($lang ?? 'en') ?>" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – <?= $siteName ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="admin-body">

<!-- Admin Header -->
<header class="admin-header">
    <button class="menu-toggle" id="menu-toggle" aria-label="<?= __('admin.menu_toggle') ?>">☰</button>
    <span class="admin-header-title"><img src="../favicon.svg" width="24" height="24" alt="countr" style="vertical-align: middle; margin-right: 8px;"> Admin – <?= $siteName ?></span>
    <div class="admin-header-actions">
        <button id="admin-refresh-btn" class="btn btn-outline btn-sm">🔄</button>
        <button id="theme-toggle" class="theme-toggle"><?= __('admin.theme_dark') ?></button>
        <a href="?lang=<?= ($lang ?? 'en') === 'de' ? 'en' : 'de' ?>" class="btn btn-outline btn-sm" title="<?= ($lang ?? 'en') === 'de' ? __('admin.lang_switch_title_en') : __('admin.lang_switch_title_de') ?>"><?= ($lang ?? 'en') === 'de' ? 'EN' : 'DE' ?></a>
        <a href="index.php" class="btn btn-outline btn-sm"><?= __('admin.dashboard_btn') ?></a>
        <a href="?logout" class="btn btn-outline btn-sm"><?= __('admin.logout') ?></a>
    </div>
</header>

<div class="admin-main">
    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    <!-- Sidebar -->
    <aside class="admin-sidebar" id="admin-sidebar">
        <nav class="sidebar-nav">
            <button class="sidebar-link tab-btn active" data-tab="overview"><?= __('admin.tab_overview') ?></button>
            <button class="sidebar-link tab-btn" data-tab="visitors"><?= __('admin.tab_visitors') ?></button>
            <button class="sidebar-link tab-btn" data-tab="pages"><?= __('admin.tab_pages') ?></button>
            <button class="sidebar-link tab-btn" data-tab="referrers"><?= __('admin.tab_referrers') ?></button>
            <button class="sidebar-link tab-btn" data-tab="settings"><?= __('admin.tab_settings') ?></button>
            <button class="sidebar-link tab-btn" data-tab="export"><?= __('admin.tab_export') ?></button>
            <button class="sidebar-link tab-btn" data-tab="tools"><?= __('admin.tab_tools') ?></button>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="admin-content">

        <!-- Message Alert -->
        <?php if (isset($_GET['saved']) && $_GET['saved'] == 1): ?>
            <div class="alert alert-success">
                <?= __('admin.settings_saved') ?>
            </div>
        <?php elseif ($message): ?>
            <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'error' : 'info') ?>">
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <!-- Date Filter -->
        <form id="date-filter-form" method="get" class="date-filter">
            <label for="filter-from"><?= __('admin.filter_from') ?></label>
            <input type="date" id="filter-from" name="from" value="<?= e($filterFrom) ?>">
            <label for="filter-to"><?= __('admin.filter_to') ?></label>
            <input type="date" id="filter-to" name="to" value="<?= e($filterTo) ?>">
            <button type="submit" class="btn btn-primary btn-sm"><?= __('admin.filter_apply') ?></button>
            <button type="button" class="btn btn-sm date-preset" data-days="7"><?= __('admin.filter_7days') ?></button>
            <button type="button" class="btn btn-sm date-preset" data-days="30"><?= __('admin.filter_30days') ?></button>
            <button type="button" class="btn btn-sm date-preset" data-days="90"><?= __('admin.filter_90days') ?></button>
        </form>

        <!-- ============================================ -->
        <!-- TAB: OVERVIEW                               -->
        <!-- ============================================ -->
        <div id="tab-overview" class="tab-panel active">
            <section class="admin-section">
                <h2 class="section-title"><?= __('admin.overview_title') ?></h2>

                <!-- Stat Cards -->
                <div class="admin-stats-grid">
                    <div class="admin-stat-card">
                        <div class="stat-label"><?= __('admin.overview_online') ?></div>
                        <div class="stat-value" id="admin-online-count"><?= fmtNum($online) ?></div>
                    </div>
                    <div class="admin-stat-card">
                        <div class="stat-label"><?= __('admin.overview_visitors_today') ?></div>
                        <div class="stat-value"><?= fmtNum($todaySummary['visitors_today'] ?? $todaySummary['visitors'] ?? 0) ?></div>
                    </div>
                    <div class="admin-stat-card">
                        <div class="stat-label"><?= __('admin.overview_pageviews_today') ?></div>
                        <div class="stat-value"><?= fmtNum($todaySummary['pageviews_today'] ?? $todaySummary['pageviews'] ?? 0) ?></div>
                    </div>
                    <div class="admin-stat-card">
                        <div class="stat-label"><?= __('admin.overview_bounce_rate') ?></div>
                        <div class="stat-value"><?= round((float)$bounceRate) ?>%</div>
                    </div>
                    <div class="admin-stat-card">
                        <div class="stat-label"><?= __('admin.overview_total_visitors') ?></div>
                        <div class="stat-value"><?= fmtNum($overallStats['total_visitors'] ?? $overallStats['totals']['visitors'] ?? 0) ?></div>
                    </div>
                    <div class="admin-stat-card">
                        <div class="stat-label"><?= __('admin.overview_total_pageviews') ?></div>
                        <div class="stat-value"><?= fmtNum($overallStats['total_pageviews'] ?? $overallStats['totals']['pageviews'] ?? 0) ?></div>
                    </div>
                </div>

                <!-- Peak Hour / Growth -->
                <div class="grid-2">
                    <div class="chart-card">
                        <h3 class="chart-title"><?= __('admin.peak_hour') ?></h3>
                        <p style="font-size:1.5rem;font-weight:800;color:var(--accent);">
                            <?= !empty($peakHour['hour']) ? sprintf('%02d:00', (int)$peakHour['hour']) : '–' ?>
                        </p>
                    </div>
                    <div class="chart-card">
                        <h3 class="chart-title"><?= __('admin.growth_trend') ?></h3>
                        <?php if (!empty($growthTrend)): ?>
                            <p style="font-size:1.5rem;font-weight:800;color:var(--success);">
                                <?= round((float)($growthTrend['trend_percent'] ?? 0), 1) ?>%
                            </p>
                        <?php else: ?>
                            <p><?= __('admin.growth_not_enough') ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>

        <!-- ============================================ -->
        <!-- TAB: VISITORS (with Chart.js)                -->
        <!-- ============================================ -->
        <div id="tab-visitors" class="tab-panel">
            <section class="admin-section">
                <h2 class="section-title"><?= __('admin.visitors_title') ?></h2>

                <div class="charts-grid">
                    <!-- Browser Distribution Doughnut Chart -->
                    <div class="chart-card">
                        <h3 class="chart-title"><?= __('admin.browser_dist') ?></h3>
                        <div class="chart-container">
                            <canvas id="adminChartBrowsers" role="img"></canvas>
                        </div>
                    </div>
                    <!-- OS Distribution Doughnut Chart -->
                    <div class="chart-card">
                        <h3 class="chart-title"><?= __('admin.os_dist') ?></h3>
                        <div class="chart-container">
                            <canvas id="adminChartOS" role="img"></canvas>
                        </div>
                    </div>
                    <!-- Device Distribution Doughnut Chart -->
                    <div class="chart-card">
                        <h3 class="chart-title"><?= __('admin.device_dist') ?></h3>
                        <div class="chart-container">
                            <canvas id="adminChartDevices" role="img"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Country Distribution List -->
                <div class="charts-grid" style="margin-top:var(--spacing-md);">
                    <div class="chart-card chart-card--full">
                        <h3 class="chart-title"><?= __('admin.country_dist') ?></h3>
                        <div class="top-pages-list" id="admin-country-dist">
                            <?php
                            $adminCountries = $tracker->getCountryDistribution(30);
                            $adminCountryData = [];
                            $adminTotalCountries = 0;
                            foreach ($adminCountries as $row) {
                                $code = $row['country_code'] ?? '';
                                $count = (int)($row['count'] ?? 0);
                                if ($code !== '' && $count > 0) {
                                    $adminTotalCountries += $count;
                                    $adminCountryData[$code] = $count;
                                }
                            }
                            arsort($adminCountryData);
                            $adminCountryData = array_slice($adminCountryData, 0, 10, true);
                            if (!empty($adminCountryData)):
                                $adminRank = 1;
                                foreach ($adminCountryData as $code => $count):
                                    $adminPct = $adminTotalCountries > 0 ? round(($count / $adminTotalCountries) * 100, 1) : 0;
                            ?>
                                <div class="top-page-item">
                                    <span class="top-page-rank">#<?= $adminRank++ ?></span>
                                    <span class="top-page-url"><?= countryFlag($code) ?> <?= htmlspecialchars(countryName($code), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="top-page-count"><?= fmtNum($count) ?> (<?= $adminPct ?>%)</span>
                                </div>
                            <?php endforeach; else: ?>
                                <p class="empty-state"><?= __('dash.no_data') ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- ============================================ -->
        <!-- TAB: PAGES (with horizontal bar chart)       -->
        <!-- ============================================ -->
        <div id="tab-pages" class="tab-panel">
            <section class="admin-section">
                <h2 class="section-title"><?= __('admin.pages_title') ?> (<?= e($filterFrom) ?> – <?= e($filterTo) ?>)</h2>

                <div class="charts-grid">
                    <div class="chart-card chart-card--full">
                        <div class="chart-container" style="max-height:<?= max(250, count($pagesData) * 32) ?>px;">
                            <canvas id="adminChartPages" role="img"></canvas>
                        </div>
                        <!-- Fallback list for no-JS / empty chart -->
                        <div class="top-pages-list" id="admin-pages-list" style="margin-top:1rem;">
                            <?php if (empty($pagesData)): ?>
                                <p class="empty-state"><?= __('admin.pages_no_data') ?></p>
                            <?php else: ?>
                                <?php foreach ($pagesData as $p): ?>
                                    <div class="top-page-item">
                                        <span class="top-page-rank">#<?= $p['rank'] ?></span>
                                        <span class="top-page-url"><?= e($p['url']) ?></span>
                                        <span class="top-page-count"><?= fmtNum($p['count']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- ============================================ -->
        <!-- TAB: REFERRERS (with horizontal bar chart)   -->
        <!-- ============================================ -->
        <div id="tab-referrers" class="tab-panel">
            <section class="admin-section">
                <h2 class="section-title"><?= __('admin.referrers_title') ?> (<?= e($filterFrom) ?> – <?= e($filterTo) ?>)</h2>

                <div class="charts-grid">
                    <div class="chart-card chart-card--full">
                        <div class="chart-container" style="max-height:<?= max(250, count($refData) * 32) ?>px;">
                            <canvas id="adminChartReferrers" role="img"></canvas>
                        </div>
                        <div class="top-pages-list" id="admin-referrers-list" style="margin-top:1rem;">
                            <?php if (empty($refData)): ?>
                                <p class="empty-state"><?= __('admin.referrers_no_data') ?></p>
                            <?php else: ?>
                                <?php foreach ($refData as $r): ?>
                                    <div class="top-page-item">
                                        <span class="top-page-rank">#<?= $r['rank'] ?></span>
                                        <span class="top-page-url"><?= e($r['label']) ?></span>
                                        <span class="top-page-count"><?= fmtNum($r['count']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- ============================================ -->
        <!-- TAB: SETTINGS                               -->
        <!-- ============================================ -->
        <div id="tab-settings" class="tab-panel">
            <section class="admin-section">
                <h2 class="section-title"><?= __('admin.settings_title') ?></h2>
                <form method="post" id="settings-form" class="chart-card">
                    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label" for="site_name"><?= __('admin.settings_site_name') ?></label>
                            <input type="text" id="site_name" name="site_name" class="form-input"
                                   value="<?= e($rawConfig['site']['name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="site_url"><?= __('admin.settings_site_url') ?></label>
                            <input type="text" id="site_url" name="site_url" class="form-input"
                                   value="<?= e($rawConfig['site']['url'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="timezone"><?= __('admin.settings_timezone') ?></label>
                            <select id="timezone" name="timezone" class="form-select">
                                <?php
                                $commonTzs = ['Europe/Berlin', 'Europe/Vienna', 'Europe/Zurich', 'Europe/London', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'Asia/Tokyo', 'UTC'];
                                foreach ($commonTzs as $tz): ?>
                                    <option value="<?= $tz ?>" <?= ($rawConfig['tracking']['timezone'] ?? '') === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="session_timeout"><?= __('admin.settings_session_timeout') ?></label>
                            <input type="number" id="session_timeout" name="session_timeout" class="form-input"
                                   value="<?= (int)($rawConfig['tracking']['session_timeout'] ?? 1800) ?>" min="60">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="days_to_keep"><?= __('admin.settings_days_to_keep') ?></label>
                            <input type="number" id="days_to_keep" name="days_to_keep" class="form-input"
                                   value="<?= (int)($rawConfig['privacy']['days_to_keep'] ?? 90) ?>" min="1" max="3650">
                        </div>
                    </div>

                    <div style="margin-top:var(--spacing-md);">
                        <label class="toggle">
                            <input type="checkbox" name="ignore_bots" <?= !empty($rawConfig['tracking']['ignore_bots']) ? 'checked' : '' ?>>
                            <span class="toggle-switch"></span> <?= __('admin.settings_ignore_bots') ?>
                        </label>
                        <label class="toggle">
                            <input type="checkbox" name="enable_public_stats" <?= !empty($rawConfig['security']['enable_public_stats']) ? 'checked' : '' ?>>
                            <span class="toggle-switch"></span> <?= __('admin.settings_public_stats') ?>
                        </label>
                        <label class="toggle">
                            <input type="checkbox" name="disable_tracking" <?= !empty($rawConfig['privacy']['disable_tracking']) ? 'checked' : '' ?>>
                            <span class="toggle-switch"></span> <?= __('admin.settings_disable_tracking') ?>
                        </label>
                    </div>

                    <button type="submit" name="save_settings" class="btn btn-primary" style="margin-top:var(--spacing-md);">
                        <?= __('admin.settings_save') ?>
                    </button>
                </form>
            </section>

            <!-- Password Change -->
            <section class="admin-section">
                <h2 class="section-title"><?= __('admin.password_title') ?></h2>
                <form method="post" class="chart-card" style="max-width:500px;">
                    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                    <div class="form-group">
                        <label class="form-label" for="current_password"><?= __('admin.password_current') ?></label>
                        <input type="password" id="current_password" name="current_password" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="new_password"><?= __('admin.password_new') ?></label>
                        <input type="password" id="new_password" name="new_password" class="form-input" required minlength="6">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="confirm_password"><?= __('admin.password_confirm') ?></label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-input" required minlength="6">
                    </div>
                    <button type="submit" name="change_password" class="btn btn-primary"><?= __('admin.password_button') ?></button>
                </form>
            </section>
        </div>

        <!-- ============================================ -->
        <!-- TAB: EXPORT                                 -->
        <!-- ============================================ -->
        <div id="tab-export" class="tab-panel">
            <section class="admin-section">
                <h2 class="section-title"><?= __('admin.export_title') ?></h2>
                <div class="chart-card">
                    <p style="margin-bottom:var(--spacing-md);"><?= __('admin.export_desc') ?></p>
                    <div class="export-actions">
                        <button class="btn btn-primary" data-export="csv"><?= __('admin.export_csv') ?></button>
                        <button class="btn btn-primary" data-export="json"><?= __('admin.export_json') ?></button>
                        <button class="btn btn-primary" data-export="excel"><?= __('admin.export_excel') ?></button>
                    </div>
                    <p class="form-help">
                        <?= __('admin.export_range') ?>: <strong><?= e($filterFrom) ?> – <?= e($filterTo) ?></strong>.
                        <?= __('admin.export_adjust') ?>
                    </p>
                </div>
            </section>

            <!-- API Token -->
            <section class="admin-section">
                <h2 class="section-title"><?= __('admin.api_title') ?></h2>
                <div class="grid-2">
                    <div class="chart-card">
                        <h3 class="chart-title"><?= __('admin.api_token') ?></h3>
                        <form method="post">
                            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                            <div class="form-group">
                                <label class="form-label"><?= __('admin.api_current') ?></label>
                                <input type="text" class="form-input" readonly
                                       value="<?= e($rawConfig['security']['api_key'] ?? __('admin.api_no_token')) ?>"
                                       style="font-family:var(--font-mono);font-size:0.8rem;">
                            </div>
                            <button type="submit" name="generate_token" class="btn btn-primary btn-sm"><?= __('admin.api_generate') ?></button>
                        </form>
                    </div>
                    <div class="chart-card">
                        <h3 class="chart-title"><?= __('admin.export_token_title') ?></h3>
                        <form method="post">
                            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                            <div class="form-group">
                                <label class="form-label"><?= __('admin.export_token_current') ?></label>
                                <input type="text" class="form-input" readonly
                                       value="<?= e($rawConfig['security']['export_token'] ?? __('admin.api_no_token')) ?>"
                                       style="font-family:var(--font-mono);font-size:0.8rem;">
                            </div>
                            <button type="submit" name="generate_export_token" class="btn btn-primary btn-sm"><?= __('admin.export_token_generate') ?></button>
                        </form>
                    </div>
                </div>
            </section>
        </div>

        <!-- ============================================ -->
        <!-- TAB: TOOLS                                  -->
        <!-- ============================================ -->
        <div id="tab-tools" class="tab-panel">
            <!-- Data Cleanup -->
            <section class="admin-section">
                <h2 class="section-title"><?= __('admin.tools_cleanup_title') ?></h2>
                <form method="post" class="chart-card" style="max-width:500px;">
                    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                    <div class="form-group">
                        <label class="form-label" for="cleanup_days"><?= __('admin.tools_cleanup_label') ?></label>
                        <input type="number" id="cleanup_days" name="cleanup_days" class="form-input" value="90" min="1" max="3650">
                    </div>
                    <button type="submit" name="cleanup_data" class="btn btn-danger"
                            data-confirm="<?= __('admin.tools_cleanup_confirm') ?>">
                        <?= __('admin.tools_cleanup_button') ?>
                    </button>
                </form>
            </section>

            <!-- Backup / Restore -->
            <section class="admin-section">
                <h2 class="section-title"><?= __('admin.tools_backup_title') ?></h2>
                <div class="grid-2">
                    <div class="chart-card">
                        <h3 class="chart-title"><?= __('admin.tools_backup_create') ?></h3>
                        <form method="post">
                            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                            <button type="submit" name="create_backup" class="btn btn-primary"><?= __('admin.tools_backup_button') ?></button>
                        </form>
                    </div>
                    <div class="chart-card">
                        <h3 class="chart-title"><?= __('admin.tools_backup_existing') ?></h3>
                        <?php if (empty($backups)): ?>
                            <p class="empty-state"><?= __('admin.tools_backup_none') ?></p>
                        <?php else: ?>
                            <div class="backup-list">
                                <?php foreach ($backups as $bkp): ?>
                                    <div class="backup-item">
                                        <span class="backup-name"><?= e($bkp['name']) ?></span>
                                        <span class="backup-date"><?= e($bkp['date']) ?></span>
                                        <span class="backup-size"><?= fmtBytes($bkp['size']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- System Info -->
            <section class="admin-section">
                <h2 class="section-title"><?= __('admin.tools_sysinfo_title') ?></h2>
                <div class="chart-card">
                    <table class="info-table">
                        <tr><td><?= __('admin.tools_sysinfo_php') ?></td><td><?= PHP_VERSION ?></td></tr>
                        <tr><td><?= __('admin.tools_sysinfo_timezone') ?></td><td><?= e($rawConfig['tracking']['timezone'] ?? __('admin.tools_sysinfo_not_set')) ?></td></tr>
                        <tr><td><?= __('admin.tools_sysinfo_first') ?></td><td><?= e($overallStats['first_tracked'] ?? $overallStats['meta']['first_track'] ?? '–') ?></td></tr>
                        <tr><td><?= __('admin.tools_sysinfo_last') ?></td><td><?= e($overallStats['last_tracked'] ?? $overallStats['meta']['last_update'] ?? '–') ?></td></tr>
                        <tr><td><?= __('admin.tools_sysinfo_tracking') ?></td><td><?= empty($rawConfig['privacy']['disable_tracking']) ? __('admin.tools_sysinfo_yes') : __('admin.tools_sysinfo_no') ?></td></tr>
                        <tr><td><?= __('admin.tools_sysinfo_public') ?></td><td><?= !empty($rawConfig['security']['enable_public_stats']) ? __('admin.tools_sysinfo_yes') : __('admin.tools_sysinfo_no') ?></td></tr>
                        <tr><td><?= __('admin.tools_sysinfo_db') ?></td><td><code><?= e($db->getDbPath()) ?></code></td></tr>
                        <tr><td><?= __('admin.tools_sysinfo_db_size') ?></td><td><?= e($db->getDatabaseSize()) ?></td></tr>
                        <tr><td><?= __('admin.tools_sysinfo_license') ?></td><td>GNU General Public License v3.0 <a href="LICENSE" target="_blank"><?= __('admin.tools_sysinfo_license_details') ?></a></td></tr>
                    </table>
                </div>
            </section>
        </div>

    </main>
</div>

<!-- i18n bridge for JavaScript (Chart.js labels) -->
<script>
window.CountrI18n = <?= json_encode($adminI18n, JSON_UNESCAPED_UNICODE) ?>;
</script>

<!-- Initial Chart Data for Admin (from SQLite via PHP) -->
<script>
window.AdminChartData = <?= json_encode($adminChartData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?>;
</script>

<script type="module" src="assets/js/admin.js?v=<?= filemtime(__DIR__ . '/assets/js/admin.js') ?>"></script>
</body>
</html>