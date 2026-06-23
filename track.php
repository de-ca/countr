<?php
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }
/**
 * Countr Analytics - Tracking Endpoint
 * 
 * Ultra-fast tracking endpoint that can serve as:
 * 1. 1x1 Transparent GIF (pixel tracking)
 * 2. JSON Response (API/JS tracking)
 * 3. JavaScript snippet (dynamic injection)
 * 
 * Response time target: < 50ms
 * 
 * Backend: SQLite via DatabaseFacade (v1.3.0)
 * 
 * @package    Countr
 * @copyright  2026 Countr Analytics
 * @license    GPL-3.0-or-later
 * @version    1.6.0
 */

// ========== CONFIGURATION ==========
define('COUNTR_START', microtime(true));
define('COUNTR_DIR', __DIR__);

// ========== BOOTSTRAP (SQLite-based, minimal) ==========
require_once __DIR__ . '/inc/autoload.php';
require_once __DIR__ . '/inc/Visitor.php';

use Countr\Core\Database\DatabaseFacade;

// ========== RATE LIMITING ==========
/**
 * Simple rate limiter: max 1 request per second per IP.
 * Uses a fast file-based check in /tmp to avoid overloading the main data store.
 */
function rateLimitCheck(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $ipHash = md5($ip);
    $rateFile = sys_get_temp_dir() . '/wb_rate_' . $ipHash;

    $now = microtime(true);

    if (file_exists($rateFile)) {
        $lastRequest = (float) @file_get_contents($rateFile);
        if ($now - $lastRequest < 1.0) {
            // Rate limited: max 1 request per second
            return false;
        }
    }

    @file_put_contents($rateFile, (string) $now, LOCK_EX);
    return true;
}

// Rate limit check (skip for localhost + Docker gateway in debug mode)
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocalhost = in_array($remoteAddr, ['127.0.0.1', '::1', 'localhost'], true)
               || preg_match('/^(?:10\.|172\.(?:1[6-9]|2\d|3[01])\.|192\.168\.)/', $remoteAddr);
if (!$isLocalhost && !rateLimitCheck()) {
    http_response_code(429); // Too Many Requests
    header('Content-Type: application/json');
    header('Retry-After: 1');
    echo json_encode(['status' => 'rate_limited', 'error' => 'Too many requests']);
    exit;
}

// ========== INITIALIZATION ==========
$db = DatabaseFacade::getInstance();

// Load configuration from SQLite settings table (with defaults for fresh installs)
// GDPR-Hinweis: Die Settings privacy.anonymize_ip und privacy.store_ip werden aus der
// SQLite-Tabelle `settings` (nicht aus config.json) geladen und als Booleans gecastet.
// config.json dient nur als Initial-Setup-Quelle und wird über Migration.php in die
// DB übertragen. Der maßgebliche Wert lebt danach ausschließlich in der DB.
$trackingDisabled    = (bool) $db->getSetting('privacy.disable_tracking', '0');
$anonymizeIp         = (bool) $db->getSetting('privacy.anonymize_ip', '1');
$storeIp             = (bool) $db->getSetting('privacy.store_ip', '0');
$sessionTimeout      = (int)  $db->getSetting('tracking.session_timeout', '1800');
$ignoreBots          = (bool) $db->getSetting('tracking.ignore_bots', '1');

// Check if tracking is disabled
if ($trackingDisabled) {
    sendResponse('pixel');
    exit;
}

// ========== CREATE VISITOR FROM REQUEST ==========
$visitor = Visitor::fromCurrentRequest([
    'anonymize_ip'    => $anonymizeIp,
    'store_ip'        => $storeIp,
    'session_timeout' => $sessionTimeout,
]);

// ========== TRACK THE HIT ==========
$page = $_GET['page'] ?? null;
if ($page === null) {
    $isJs = !empty($_GET['js']);
    if ($isJs && !empty($_SERVER['HTTP_REFERER'])) {
        // For JS tracking, extract path + query from HTTP referrer
        // (the page that embedded the script). Include query string
        // so sub-pages like /blog/artikel-1?lang=de are captured correctly.
        $parsed = parse_url($_SERVER['HTTP_REFERER']);
        $page   = ($parsed['path'] ?? '/') . (!empty($parsed['query']) ? '?' . $parsed['query'] : '');
    } elseif ($isJs) {
        // JS mode but no referrer (e.g., strict Referrer-Policy).
        // Fallback to root – better than logging the tracker script itself.
        $page = '/';
    } else {
        $page = $_SERVER['REQUEST_URI'] ?? '/';
    }
} else {
    // Sanitize: if a full URL was passed (e.g., from JS fetch), extract only path + query.
    // This prevents accidental domain inclusion and strips fragments.
    if (preg_match('#^https?://#i', $page)) {
        $parsed = parse_url($page);
        $page = ($parsed['path'] ?? '/') . (!empty($parsed['query']) ? '?' . $parsed['query'] : '');
    }
    // Strip null bytes and control characters for security
    $page = str_replace(["\0", "\r", "\n"], '', $page);
    // Ensure page always starts with '/'
    if ($page !== '' && $page[0] !== '/') {
        $page = '/' . $page;
    }
}
$referrer = $_GET['ref'] ?? $_GET['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? '');

// ---- FILTER: Reject self-tracking calls to track.php itself ----
// Direct calls to /countr/track.php, /countr/track.php?js=1 or any
// URL that resolves to the tracker script itself MUST NOT be logged
// as a valid pageview. This prevents garbage data from bots,
// misconfigured script embeds, or direct browser hits.
$trackerPath = '/countr/track.php';
$requestUri = parse_url($page, PHP_URL_PATH) ?? $page;
if ($requestUri !== null && $requestUri !== '') {
    $normalizedRequest = '/' . ltrim($requestUri, '/');
    $normalizedTracker = '/' . ltrim($trackerPath, '/');
    if (
        // Exact match: /countr/track.php
        $normalizedRequest === $normalizedTracker
        // Also catch bare /track.php calls (e.g. when installed at docroot)
        || $normalizedRequest === '/track.php'
        // Case-insensitive safety net
        || strcasecmp($normalizedRequest, $normalizedTracker) === 0
        || strcasecmp($normalizedRequest, '/track.php') === 0
    ) {
        // Self-track: silently serve a 1x1 pixel without logging
        sendResponse('pixel');
        exit;
    }
}

// Track visitors according to the ignore_bots setting.
// When ignoreBots is ON:  bots are blocked, humans are tracked.
// When ignoreBots is OFF: all visitors (including bots) are tracked.
$tracked = false;
$visitorsToday = 0;
$pageviewsToday = 0;
$onlineNow = 0;

if (!$visitor->isBot() || !$ignoreBots) {
    try {
        $db->beginTransaction();

        $visitorHash   = $visitor->getVisitorId();
        $sessionId     = $visitor->getSessionId();
        $ipHash        = $visitor->getIpHash();
        $userAgent     = $visitor->getUserAgent();
        $browser       = $visitor->getBrowser();
        $browserVer    = $visitor->getBrowserVersion();
        $os            = $visitor->getOS();
        $osVersion     = ''; // Not directly available in legacy Visitor, leave empty
        $deviceType    = $visitor->getDeviceType();
        $screenSize    = $visitor->getScreenSize();
        $language      = $visitor->getLanguage();
        $countryCode   = $visitor->getCountryCode();
        $isBot         = $visitor->isBot() ? 1 : 0;

        $referrerDomain = '';
        if (!empty($referrer)) {
            $host = parse_url($referrer, PHP_URL_HOST);
            if ($host) {
                $referrerDomain = preg_replace('/^www\./', '', $host);
            }
        }
        if (empty($referrerDomain) && !empty($referrer)) {
            $referrerDomain = 'direct';
        }

        // --- Upsert visitor ---
        // GDPR: ip_hash ist SHA-256 der anonymisierten IP (nicht der Roh-IP).
        // Die Anonymisierung erfolgt bereits im Visitor-Konstruktor über
        // Visitor::anonymizeIp(), BEVOR der Hash gebildet wird.
        // Siehe Visitor.php: $this->cachedAnonymizedIp = $this->anonymizeIp($this->ipAddress)
        // und generateVisitorHash()/getIpHash() verwenden ausschließlich $this->cachedAnonymizedIp.
        $existingId = $db->queryScalar(
            'SELECT id FROM visitors WHERE visitor_hash = :hash',
            [':hash' => $visitorHash]
        );

        if ($existingId !== null) {
            $visitorId = (int) $existingId;
            // Update last_seen and visits_count (the trigger trg_update_visitor_after_visit handles this,
            // but we also want to refresh the metadata like browser/os)
            $db->execute(
                'UPDATE visitors SET
                    last_seen = CURRENT_TIMESTAMP,
                    ip_hash = :ip_hash,
                    user_agent = :user_agent,
                    browser = :browser,
                    browser_version = :browser_version,
                    os = :os,
                    device_type = :device_type,
                    screen_size = :screen_size,
                    language = :language,
                    country_code = :country_code,
                    is_bot = :is_bot
                WHERE id = :id',
                [
                    ':ip_hash'         => $ipHash,
                    ':user_agent'      => $userAgent,
                    ':browser'         => $browser,
                    ':browser_version' => $browserVer,
                    ':os'              => $os,
                    ':device_type'     => $deviceType,
                    ':screen_size'     => $screenSize,
                    ':language'        => $language,
                    ':country_code'    => $countryCode,
                    ':is_bot'          => $isBot,
                    ':id'              => $visitorId,
                ]
            );
        } else {
            // Insert new visitor
            $visitorId = (int) $db->insertAndGetId(
                'INSERT INTO visitors (visitor_hash, ip_hash, user_agent, browser, browser_version, os, device_type, screen_size, language, country_code, is_bot)
                 VALUES (:visitor_hash, :ip_hash, :user_agent, :browser, :browser_version, :os, :device_type, :screen_size, :language, :country_code, :is_bot)',
                [
                    ':visitor_hash'    => $visitorHash,
                    ':ip_hash'         => $ipHash,
                    ':user_agent'      => $userAgent,
                    ':browser'         => $browser,
                    ':browser_version' => $browserVer,
                    ':os'              => $os,
                    ':device_type'     => $deviceType,
                    ':screen_size'     => $screenSize,
                    ':language'        => $language,
                    ':country_code'    => $countryCode,
                    ':is_bot'          => $isBot,
                ]
            );
        }

        // --- Insert visit ---
        $db->execute(
            'INSERT INTO visits (visitor_id, session_id, page_url, referrer, referrer_domain, timestamp)
             VALUES (:visitor_id, :session_id, :page_url, :referrer, :referrer_domain, CURRENT_TIMESTAMP)',
            [
                ':visitor_id'      => $visitorId,
                ':session_id'      => $sessionId,
                ':page_url'        => $page,
                ':referrer'        => $referrer,
                ':referrer_domain' => $referrerDomain,
            ]
        );

        // The SQLite triggers handle:
        //   - trg_update_visitor_after_visit: updates visitors.last_seen, visits_count
        //   - trg_update_daily_stats: upserts daily_stats, increments pageviews
        //   - trg_update_hourly_stats: upserts hourly_stats, increments pageviews
        //   - trg_update_page_stats: upserts page_stats, increments total_views
        //   - trg_update_referrer_stats: upserts referrer_stats, increments visits

        $db->commit();

        $tracked = true;

        // Fetch lightweight stats for JS/JSON responses
        $todaySummary   = $db->getTodaySummary();
        $visitorsToday  = (int) ($todaySummary['visitors_today'] ?? 0);
        $pageviewsToday = (int) ($todaySummary['pageviews_today'] ?? 0);
        $onlineNow      = $db->getRealtimeVisitors(5);

    } catch (\Throwable $e) {
        // Rollback on any error
        try {
            $db->rollback();
        } catch (\Throwable $rollbackError) {
            // Silently ignore rollback failures
        }
        error_log('[Countr track.php] Tracking error: ' . $e->getMessage());
        // Continue to send response – never leak errors to the browser
    }
}

// ========== DETERMINE RESPONSE TYPE ==========
$format = $_GET['format'] ?? $_GET['f'] ?? 'auto';

// Auto-detect: JS mode if ?js=1, otherwise pixel
if ($format === 'auto') {
    $format = !empty($_GET['js']) ? 'js' : 'pixel';
}

// ========== SEND RESPONSE ==========
switch ($format) {
    case 'json':
        $responseData = [
            'tracked'         => $tracked,
            'visitors_today'  => $visitorsToday,
            'pageviews_today' => $pageviewsToday,
            'online'          => $onlineNow,
        ];
        sendResponse('json', $responseData);
        break;

    case 'js':
        $responseData = [
            'tracked'         => $tracked,
            'visitors_today'  => $visitorsToday,
            'pageviews_today' => $pageviewsToday,
            'online'          => $onlineNow,
        ];
        sendResponse('js', $responseData);
        break;

    case 'pixel':
    default:
        sendResponse('pixel');
        break;
}

// ========== RESPONSE FUNCTIONS ==========

/**
 * Send the tracking response.
 *
 * @param string      $type   'pixel', 'json', or 'js'
 * @param array|null  $data   Response data (for json/js modes)
 */
function sendResponse(string $type, ?array $data = null): void
{
    $elapsed = round((microtime(true) - COUNTR_START) * 1000, 2);

    switch ($type) {
        case 'json':
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Robots-Tag: noindex, nofollow');

            $response = $data ?? ['tracked' => false];
            $response['_meta'] = [
                'response_time_ms' => $elapsed,
                'service'          => 'Countr Analytics',
            ];

            echo json_encode($response, JSON_PRETTY_PRINT);
            break;

        case 'js':
            // Return JavaScript that can set global variables or call a callback
            header('Content-Type: application/javascript; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: max-age=60'); // Cache for 60 seconds
            header('X-Robots-Tag: noindex, nofollow');

            $callback = $_GET['callback'] ?? null;
            $jsonData = json_encode($data ?? ['tracked' => false]);

            if ($callback) {
                // JSONP-style callback
                echo "{$callback}({$jsonData});";
            } else {
                // Set a global variable
                echo "window.Countr = {$jsonData};";
                echo "\nwindow.CountrLoaded = true;";
                echo "\nif (typeof window.CountrReady === 'function') { window.CountrReady(window.Countr); }";
            }
            break;

        case 'pixel':
        default:
            // Send 1x1 transparent GIF
            header('Content-Type: image/gif');
            header('Access-Control-Allow-Origin: *');
            header('Cache-Control: no-store, must-revalidate');
            header('X-Robots-Tag: noindex, nofollow');
            header('Content-Length: 43');

            // 1x1 transparent GIF (43 bytes)
            echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
            break;
    }

    // Log response time if slow
    if ($elapsed > 100) {
        error_log("[Countr Analytics] Slow response: {$elapsed}ms for {$_SERVER['REMOTE_ADDR']}");
    }

    exit;
}