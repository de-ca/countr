<?php
/**
 * Countr Analytics - Public Dashboard (SQLite-only)
 *
 * Main entry point for the Countr Analytics application.
 * Displays live statistics, charts, and a responsive dashboard.
 * Uses ONLY SQLite database — no JSON file backend.
 *
 * @package    Countr
 * @copyright  2026 Countr Analytics
 * @license    GPL-3.0-or-later
 * @version    1.6.0
 */

declare(strict_types=1);

// =========================================================================
// BOOTSTRAP
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

// Database path — SQLite is the only storage
$dbPath = __DIR__ . '/data/countr.db';

// Setup detection: redirect to setup if database is missing
if (!file_exists($dbPath)) {
    $setupPath = __DIR__ . '/setup.php';
    $setupDisabledPath = __DIR__ . '/setup.php.disabled';

    if (file_exists($setupPath)) {
        header('Location: setup.php');
        exit;
    }

    // Render error page
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(503);
    $title = __('dash.install_required');
    $message = file_exists($setupDisabledPath)
        ? 'Die Konfigurationsdatei <code>data/config.json</code> wurde nicht gefunden. Benennen Sie <code>setup.php.disabled</code> zurück zu <code>setup.php</code>.'
        : 'Countr Analytics wurde noch nicht eingerichtet und die <code>setup.php</code> fehlt. Bitte laden Sie <code>setup.php</code> aus dem Original-Paket hoch.';
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>' . $title . '</title>';
    echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#333}.box{background:#fff;padding:2.5rem;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.15);text-align:center;max-width:500px}h1{color:#d00;margin-bottom:1rem}.btn{display:inline-block;padding:12px 24px;background:#667eea;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;margin-top:1rem}code{background:#f5f5f5;padding:2px 8px;border-radius:4px;font-size:14px}</style>';
    echo '</head><body><div class="box"><h1>⚠ Countr Analytics nicht installiert</h1><p>' . $message . '</p></div></body></html>';
    exit;
}

// Load required files (NO FileDB, NO Config class from JSON)
require_once __DIR__ . '/inc/autoload.php';
require_once __DIR__ . '/inc/Visitor.php';
require_once __DIR__ . '/inc/Tracker.php';
require_once __DIR__ . '/inc/Stats.php';

// =========================================================================
// INITIALIZE SQLITE DATABASE
// =========================================================================
use Countr\Core\Database\DatabaseFacade;

$db = DatabaseFacade::getInstance($dbPath);
$db->connect();

// Single source of truth: getSettingsAsNestedArray() provides a consistent
// nested array for every component (admin.php, track.php, api.php, index.php).
$rawConfig = $db->getSettingsAsNestedArray();

$siteName  = $rawConfig['site']['name'] ?? 'Meine Webseite';
$timezone  = $rawConfig['tracking']['timezone'] ?? 'Europe/Berlin';
$enablePublicStats = $rawConfig['security']['enable_public_stats'] ?? true;

// Set timezone
date_default_timezone_set($timezone);

// =========================================================================
// PUBLIC STATS CHECK
// =========================================================================
if (!$enablePublicStats) {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="' . ($lang ?? 'de') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>' . __('dash.not_available') . '</title>';
    echo '<style>body{font-family:sans-serif;text-align:center;padding:3rem;color:#666;background:#f5f5f5}</style>';
    echo '</head><body><h1>📊 ' . __('dash.not_available') . '</h1><p>' . __('dash.not_available_desc') . '</p></body></html>';
    exit;
}

// =========================================================================
// INITIALIZE TRACKER AND STATS (SQLite only)
// =========================================================================
$visitor = Visitor::fromCurrentRequest();
$tracker = new Tracker($db, $visitor, $rawConfig);
$stats = new Stats($db, $tracker, $rawConfig);

// Flush buffer for fresh data
$tracker->flushBuffer();

// =========================================================================
// FETCH DATA FROM SQLITE
// =========================================================================
$todaySummary = $tracker->getTodaySummary();
$overallStats = $tracker->getOverallStats();
$last7Days = $tracker->getLastNDays(7);
$last30Days = $tracker->getLastNDays(30);
$topPages = $tracker->getTopPages(5);
$browsers = $tracker->getBrowserDistribution();
$countries = $tracker->getCountryDistribution(30);
$hourlyDist = $tracker->getHourlyDistribution();
$online = $tracker->getOnlineCount();
$bounceRate = $stats->estimateBounceRate();
$avgDuration = $todaySummary['session_duration_avg'] ?? $todaySummary['avg_duration'] ?? 0;
$siteName = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');

// =========================================================================
// FORMAT HELPERS
// =========================================================================
function fmtNum($val): string {
    return number_format((int)$val, 0, ',', '.');
}
function fmtTime($seconds): string {
    $s = (int)$seconds;
    if ($s <= 0) return '0:00';
    return floor($s / 60) . ':' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT);
}

// =========================================================================
// CHART DATA TRANSFORMERS (PHP side for initial render)
// =========================================================================

/**
 * Transform a DB time-series result (list of {date, visitors, pageviews})
 * into chart-ready format: {labels: string[], visitors: number[], pageviews: number[]}
 */
function transformChartTimeSeries(array $rows): array {
    $labels = []; $visitors = []; $pageviews = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $labels[] = $row['date'] ?? '';
            $visitors[] = (int)($row['visitors'] ?? $row['visitors_today'] ?? 0);
            $pageviews[] = (int)($row['pageviews'] ?? $row['pageviews_today'] ?? 0);
        }
    }
    return ['labels' => $labels, 'visitors' => $visitors, 'pageviews' => $pageviews];
}

/**
 * Transform a DB distribution result (list of {key, value})
 * into chart-ready format: {labels: string[], values: number[]}
 */
function transformChartDist(array $rows, string $keyField, string $valField): array {
    $labels = []; $values = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $key = (string)($row[$keyField] ?? (array_key_first($row) ?? ''));
            $val = (int)($row[$valField] ?? (is_array($row) ? reset($row) : 0));
            $labels[] = $key !== '' ? $key : 'Unknown';
            $values[] = $val;
        }
    }
    return ['labels' => $labels, 'values' => $values];
}

// Format hourly labels: integer hours to "HH:00"
$hourlyDist = $hourlyDist ?? [];
foreach ($hourlyDist as &$hRow) {
    if (is_array($hRow) && isset($hRow['hour'])) {
        $hRow['hour'] = sprintf('%02d:00', (int)$hRow['hour']);
    }
}
unset($hRow);

// Prepare country distribution data for top-list (with flag emoji)
$countryData = [];
$totalCountries = 0;
foreach ($countries as $row) {
    $code = $row['country_code'] ?? '';
    $count = (int)($row['count'] ?? 0);
    if ($code !== '' && $count > 0) {
        $totalCountries += $count;
        $countryData[$code] = $count;
    }
}
arsort($countryData);
if ($totalCountries > 0) {
    // Keep only top 10
    $countryData = array_slice($countryData, 0, 10, true);
    // Calculate percentages
    foreach ($countryData as $code => $count) {
        $pct = round(($count / $totalCountries) * 100, 1);
        $countryData[$code] = ['count' => $count, 'pct' => $pct];
    }
}

/**
 * Convert ISO 3166-1 alpha-2 country code to SVG flag image.
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

// Prepare chart data for JS (initial render)
$chartData = [
    'last7Days' => transformChartTimeSeries($last7Days),
    'last30Days' => transformChartTimeSeries($last30Days),
    'hourly' => transformChartDist($hourlyDist, 'hour', 'visitors'),
    'browsers' => transformChartDist($browsers, 'browser', 'count'),
    'topPages' => transformChartDist($topPages, 'page_url', 'total_views'),
];

// Normalize top pages for HTML fallback list
$normalizedPages = [];
foreach ($topPages as $page) {
    if (is_array($page)) {
        $url = (string)($page['page_url'] ?? '');
        $count = (int)($page['total_views'] ?? $page['count'] ?? 0);
        if ($url !== '') {
            $normalizedPages[$url] = $count;
        }
    }
}

// Build JS i18n bridge for Chart.js labels
$countrI18n = [
    'visitors' => __('chart.visitors'),
    'pageviews' => __('chart.pageviews'),
    'no_data' => __('dash.no_data'),
    'dark' => __('dash.dark'),
    'light' => __('dash.light'),
    'error_fetch' => __('dash.error_fetch'),
];

// =========================================================================
// RENDER HTML
// =========================================================================
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="<?= ($lang ?? 'de') ?>" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $siteName ?> – <?= __('dash.title') ?></title>
    <meta name="description" content="<?= __('dash.title') . ' ' . __('chart.visitors') . ' - ' . $siteName ?>">
    <meta name="robots" content="noindex, nofollow">
    <!-- Social Media Meta Tags -->
    <meta property="og:title" content="<?= $siteName ?> – <?= __('dash.title') ?> | Countr Analytics">
    <meta property="og:description" content="<?= __('dash.title') ?> <?= __('chart.visitors') ?> <?= __('chart.pageviews') ?>. Powered by Countr Analytics.">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Countr Analytics">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= $siteName ?> – <?= __('dash.title') ?> | Countr Analytics">
    <meta name="twitter:description" content="<?= __('dash.title') ?>. Powered by Countr Analytics.">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>

<!-- Header -->
<header class="header">
    <div class="container">
        <div class="header-content">
            <div>
                <h1 class="header-title"><img src="../favicon.svg" width="24" height="24" alt="countr" style="vertical-align: middle; margin-right: 8px;"><?= $siteName ?></h1>
                <p class="header-subtitle"><?= __('dash.title') ?></p>
            </div>
            <div class="header-links">
                <button id="refresh-btn" class="btn btn-outline btn-sm" title="<?= __('dash.refresh') ?>">🔄</button>
                <button id="theme-toggle" class="theme-toggle">🌙 <?= __('dash.dark') ?></button>
                <a href="?lang=<?= ($lang ?? 'de') === 'de' ? 'en' : 'de' ?>" class="btn btn-outline btn-sm" title="<?= ($lang ?? 'de') === 'de' ? 'Switch to English' : 'Zu Deutsch wechseln' ?>"><?= ($lang ?? 'de') === 'de' ? 'EN' : 'DE' ?></a>
                <a href="admin.php" class="btn btn-outline btn-sm">⚙️ <?= __('dash.admin') ?></a>
            </div>
        </div>
    </div>
</header>

<main class="container">
    <!-- Error Banner -->
    <div id="error-banner" class="error-banner" role="alert" aria-live="polite"></div>

    <!-- Stats Cards -->
    <section class="stats-grid" id="live-counters">
        <div class="stat-card stat-card--online">
            <div class="stat-card__icon">🟢</div>
            <div class="stat-card__value" id="online-count"><?= fmtNum($online) ?></div>
            <div class="stat-card__label"><?= __('dash.now_online') ?></div>
            <div class="stat-card__pulse" style="display:<?= $online > 0 ? 'block' : 'none' ?>"></div>
        </div>

        <div class="stat-card">
            <div class="stat-card__icon">👥</div>
            <div class="stat-card__value" id="today-visitors"><?= fmtNum($todaySummary['visitors_today'] ?? $todaySummary['visitors'] ?? 0) ?></div>
            <div class="stat-card__label"><?= __('dash.visitors_today') ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-card__icon">👁</div>
            <div class="stat-card__value" id="today-pageviews"><?= fmtNum($todaySummary['pageviews_today'] ?? $todaySummary['pageviews'] ?? 0) ?></div>
            <div class="stat-card__label"><?= __('dash.pageviews_today') ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-card__icon">📈</div>
            <div class="stat-card__value" id="total-visitors"><?= fmtNum($overallStats['total_visitors'] ?? $overallStats['totals']['visitors'] ?? 0) ?></div>
            <div class="stat-card__label"><?= __('dash.total_visitors') ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-card__icon">🔄</div>
            <div class="stat-card__value" id="bounce-rate"><?= round((float)$bounceRate) ?>%</div>
            <div class="stat-card__label"><?= __('dash.bounce_rate') ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-card__icon">⏱️</div>
            <div class="stat-card__value" id="avg-time"><?= fmtTime($avgDuration) ?></div>
            <div class="stat-card__label"><?= __('dash.avg_time') ?></div>
        </div>
    </section>

    <!-- Charts Row 1: 7-Day Trend + Hourly Distribution -->
    <section class="charts-grid">
        <div class="chart-card chart-card--wide">
            <h2 class="chart-title">📅 <?= __('dash.trend_7_days') ?></h2>
            <div class="chart-container">
                <canvas id="chart7days" role="img" aria-label="7-Tage Besucher-Trend Balkendiagramm"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h2 class="chart-title">⏰ <?= __('dash.hourly_dist') ?></h2>
            <div class="chart-container">
                <canvas id="chartHourly" role="img" aria-label="Stündliche Besucherverteilung"></canvas>
            </div>
        </div>
    </section>

    <!-- Charts Row 2: 30-Day Trend + Browsers -->
    <section class="charts-grid">
        <div class="chart-card chart-card--wide">
            <h2 class="chart-title">📊 <?= __('dash.longterm_trend') ?></h2>
            <div class="chart-container">
                <canvas id="chart30days" role="img" aria-label="30-Tage Besucher-Trend Liniendiagramm"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <h2 class="chart-title">🌐 <?= __('dash.browser_dist') ?></h2>
            <div class="chart-container">
                <canvas id="chartBrowsers" role="img" aria-label="Browser-Verteilung Donut-Diagramm"></canvas>
            </div>
        </div>
    </section>

    <!-- Country Distribution Row -->
    <section class="charts-grid">
        <div class="chart-card chart-card--full">
            <h2 class="chart-title"><?= __('dash.country_dist') ?></h2>
            <?php if (empty($countryData)): ?>
                <p class="empty-state"><?= __('dash.no_data') ?></p>
            <?php else: ?>
                <div class="top-pages-list" id="country-dist">
                    <?php $rank = 1; foreach ($countryData as $code => $info): ?>
                        <div class="top-page-item">
                            <span class="top-page-rank">#<?= $rank++ ?></span>
                            <span class="top-page-url"><?= countryFlag($code) ?> <?= htmlspecialchars(countryName($code), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="top-page-count"><?= number_format((int)$info['count'], 0, ',', '.') ?> (<?= $info['pct'] ?>%)</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Charts Row 3: Top Pages -->
    <section class="charts-grid">
        <div class="chart-card chart-card--full">
            <h2 class="chart-title">🔝 <?= __('dash.top_pages') ?></h2>
            <div class="chart-container" id="topPagesChart">
                <canvas id="chartPages" role="img" aria-label="Meistbesuchte Seiten Balkendiagramm"></canvas>
            </div>
        </div>
    </section>

    <!-- Top Pages List (fallback for no-JS) -->
    <section class="charts-grid" id="top-pages-section">
        <div class="chart-card chart-card--full">
            <h2 class="chart-title">📄 <?= __('dash.page_overview') ?></h2>
            <div class="top-pages-list" id="top-pages">
                <?php if (empty($normalizedPages)): ?>
                    <p class="empty-state"><?= __('dash.no_data') ?></p>
                <?php else: ?>
                    <?php $rank = 1; foreach ($normalizedPages as $url => $count): ?>
                        <div class="top-page-item">
                            <span class="top-page-rank">#<?= $rank++ ?></span>
                            <span class="top-page-url"><?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="top-page-count"><?= fmtNum($count) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<!-- Footer -->
<footer class="footer">
    <div class="container">
        <p>
            <?= __('dash.footer') ?> &middot;
            <?= __('dash.updated') ?>: <span id="last-update"><?= date('H:i:s') ?></span> &middot;
            <?= __('dash.auto_refresh') ?>
        </p>
    </div>
</footer>

<!-- i18n bridge for JavaScript (Chart.js labels, dashboard messages) -->
<script>
window.CountrI18n = <?= json_encode($countrI18n, JSON_UNESCAPED_UNICODE) ?>;
</script>

<!-- Initial Chart Data (from SQLite via PHP) -->
<script>
window.CountrData = <?= json_encode($chartData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?>;
</script>

<script type="module" src="assets/js/charts.js?v=<?= filemtime(__DIR__ . '/assets/js/charts.js') ?>"></script>
<script type="module" src="assets/js/dashboard.js?v=<?= filemtime(__DIR__ . '/assets/js/dashboard.js') ?>"></script>


</body>
</html>