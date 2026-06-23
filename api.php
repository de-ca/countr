<?php
/**
 * Countr Analytics - REST API (SQLite-only backend)
 * 
 * Provides programmatic access to statistics data directly from SQLite.
 * No JSON file dependencies. No FileDB. No config.json for data.
 * 
 * Endpoints:
 *   GET /api.php?action=snapshot     - Full dashboard snapshot
 *   GET /api.php?action=summary      - Today's stats
 *   GET /api.php?action=overall      - All-time totals
 *   GET /api.php?action=top_pages    - Top pages
 *   GET /api.php?action=browsers     - Browser distribution
 *   GET /api.php?action=online       - Online count
 *   GET /api.php?action=last_days    - Last N days chart data
 *   GET /api.php?action=range        - Date range stats
 *   GET /api.php?action=os           - OS distribution
 *   GET /api.php?action=devices      - Device type distribution
 *   GET /api.php?action=hourly       - Hourly distribution
 * 
 * @package    Countr
 * @copyright  2026 Countr Analytics
 * @license    GPL-3.0-or-later
 * @version 1.4.0
 */

declare(strict_types=1);

// ========== BOOTSTRAP ==========
define('COUNTR_DIR', __DIR__);

// DB path – SQLite is the only storage
$dbPath = __DIR__ . '/data/countr.db';
if (!file_exists($dbPath)) {
    sendJsonError('Database not found. Run setup first.', 503);
}

// Load dependencies via autoload + legacy classes
require_once __DIR__ . '/inc/autoload.php';
require_once __DIR__ . '/inc/Visitor.php';
require_once __DIR__ . '/inc/Tracker.php';
require_once __DIR__ . '/inc/Stats.php';

// ========== CONFIG FROM SQLITE VIA UNIFIED FACADE ==========
use Countr\Core\Database\DatabaseFacade;

$db = DatabaseFacade::getInstance($dbPath);
$db->connect();

// Single source of truth: getSettingsAsNestedArray() normalizes both dotted
// and legacy underscore keys, coerces booleans/ints, and returns a consistent
// nested array every component (admin.php, track.php, api.php, index.php) uses.
$rawConfig = $db->getSettingsAsNestedArray();

$siteName  = $rawConfig['site']['name'] ?? 'Meine Webseite';
$timezone  = $rawConfig['tracking']['timezone'] ?? 'Europe/Berlin';
$apiKey    = $rawConfig['security']['api_key'] ?? null;
$enablePublicStats = $rawConfig['security']['enable_public_stats'] ?? true;

date_default_timezone_set($timezone);

// ========== AUTHENTICATION ==========
$providedKey = $_GET['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;

// If an API key is configured, require it
if ($apiKey && $providedKey !== $apiKey) {
    // Also allow session-based admin access
    $sessionAllowed = false;
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!empty($_SESSION['wb_admin_logged_in'])) {
        $sessionAllowed = true;
    }

    if (!$sessionAllowed) {
        sendJsonError('Invalid or missing API key', 401);
    }
}

// ========== INITIALIZE WITH DATABASE (SQLite only, NO FileDB) ==========
$visitor = Visitor::fromCurrentRequest();
$tracker = new Tracker($db, $visitor, $rawConfig);
$stats = new Stats($db, $tracker, $rawConfig);

// Flush buffer so we have fresh data
$tracker->flushBuffer();

// ========== HANDLE ACTIONS ==========
$action = $_GET['action'] ?? 'snapshot';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    switch ($action) {
        case 'snapshot':
            $snapshot = $stats->getDashboardSnapshot();
            sendJson(['success' => true, 'data' => $snapshot]);
            break;

        case 'summary':
        case 'today':
            $summary = $tracker->getTodaySummary();
            sendJson(['success' => true, 'data' => $summary]);
            break;

        case 'overall':
            $overall = $tracker->getOverallStats();
            sendJson(['success' => true, 'data' => $overall]);
            break;

        case 'range':
            $from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
            $to = $_GET['to'] ?? date('Y-m-d');
            $range = $tracker->getRangeStats($from, $to);
            sendJson(['success' => true, 'data' => $range]);
            break;

        case 'top_pages':
            $limit = (int)($_GET['limit'] ?? 10);
            $days = (int)($_GET['days'] ?? 7);
            $pages = $tracker->getTopPages($limit, $days);
            sendJson(['success' => true, 'data' => $pages]);
            break;

        case 'browsers':
            $days = (int)($_GET['days'] ?? 30);
            $browsers = $tracker->getBrowserDistribution($days);
            sendJson(['success' => true, 'data' => $browsers]);
            break;

        case 'os':
            $days = (int)($_GET['days'] ?? 30);
            $os = $tracker->getOSDistribution($days);
            sendJson(['success' => true, 'data' => $os]);
            break;

        case 'devices':
            $days = (int)($_GET['days'] ?? 30);
            $devices = $tracker->getDeviceDistribution($days);
            sendJson(['success' => true, 'data' => $devices]);
            break;

        case 'hourly':
            $hourly = $tracker->getHourlyDistribution();
            sendJson(['success' => true, 'data' => $hourly]);
            break;

        case 'online':
            $online = $tracker->getOnlineCount();
            sendJson(['success' => true, 'data' => ['online' => $online]]);
            break;

        case 'last_days':
            $days = (int)($_GET['days'] ?? 7);
            $data = $tracker->getLastNDays($days);
            sendJson(['success' => true, 'data' => $data]);
            break;

        default:
            sendJson(['success' => false, 'error' => 'Unknown action', 'available' => [
                'snapshot', 'summary', 'overall', 'range', 'top_pages',
                'browsers', 'os', 'devices', 'hourly', 'online', 'last_days'
            ]], 400);
            break;
    }
} catch (Throwable $e) {
    sendJson(['success' => false, 'error' => $e->getMessage()], 500);
}

// ========== HELPERS ==========

/**
 * Send a JSON response and exit.
 *
 * @param array $data
 * @param int   $status HTTP status code
 */
function sendJson(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send a JSON error response and exit.
 *
 * @param string $message
 * @param int    $status HTTP status code
 */
function sendJsonError(string $message, int $status = 400): void
{
    sendJson(['success' => false, 'error' => $message], $status);
}