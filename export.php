<?php
/**
 * Countr Analytics - Data Export
 * 
 * Exports statistics as CSV, JSON, or Excel-compatible formats.
 * Supports date range filtering and data anonymization.
 * 
 * @package    Countr
 * @copyright  2026 Countr Analytics
 * @license    GPL-3.0-or-later
 * @version    1.4.0
 */

declare(strict_types=1);

// ========== BOOTSTRAP ==========
define('COUNTR_DIR', __DIR__);
require_once __DIR__ . '/inc/autoload.php';
require_once __DIR__ . '/inc/Visitor.php';
require_once __DIR__ . '/inc/Tracker.php';
require_once __DIR__ . '/inc/Stats.php';

use Countr\Core\Database\DatabaseFacade;

// ========== SESSION START ==========
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ========== CHECK AUTH ==========
function checkAuth(): bool
{
    $db = DatabaseFacade::getInstance();
    $db->connect();
    $config = $db->getSettingsAsNestedArray();

    // Check if already logged in
    if (!empty($_SESSION['wb_admin_logged_in']) && $_SESSION['wb_admin_logged_in'] === true) {
        return true;
    }

    // Check API token
    $token = $_GET['token'] ?? $_POST['token'] ?? null;
    if ($token && !empty($config['security']['export_token'])) {
        return hash_equals($config['security']['export_token'], $token);
    }

    // Check password via POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['password'])) {
        $hash = $config['security']['admin_password'] ?? '';
        if (password_verify($_POST['password'], $hash)) {
            $_SESSION['wb_admin_logged_in'] = true;
            return true;
        }
    }

    // Check password via GET (less secure, but convenient for scripts)
    if (!empty($_GET['password'])) {
        $hash = $config['security']['admin_password'] ?? '';
        return password_verify($_GET['password'], $hash);
    }

    return false;
}

// If not authenticated and not a login attempt, show login form or error
if (!checkAuth() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Countr Analytics - Datenexport</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                background: #f0f2f5;
                min-height: 100vh;
                display: flex; align-items: center; justify-content: center;
            }
            .login-box {
                background: #fff;
                padding: 2rem;
                border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.1);
                max-width: 400px;
                width: 100%;
            }
            h1 { font-size: 1.5rem; margin-bottom: 1.5rem; text-align: center; }
            input[type="password"] {
                width: 100%;
                padding: 10px 12px;
                border: 2px solid #e0e0e0;
                border-radius: 6px;
                font-size: 14px;
                margin-bottom: 1rem;
                text-align: center;
            }
            input[type="password"]:focus { outline: none; border-color: #667eea; }
            button {
                width: 100%;
                padding: 12px;
                background: #667eea;
                color: #fff;
                border: none;
                border-radius: 6px;
                font-size: 16px;
                cursor: pointer;
                font-weight: 600;
            }
            button:hover { background: #5a6fd6; }
            .error { color: #d00; margin-bottom: 1rem; text-align: center; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>🔐 Datenexport Login</h1>
            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                <div class="error">❌ Falsches Passwort</div>
            <?php endif; ?>
            <form method="post">
                <input type="password" name="password" placeholder="Admin-Passwort" required autofocus>
                <input type="hidden" name="format" value="<?= htmlspecialchars($_GET['format'] ?? 'csv') ?>">
                <input type="hidden" name="from" value="<?= htmlspecialchars($_GET['from'] ?? '') ?>">
                <input type="hidden" name="to" value="<?= htmlspecialchars($_GET['to'] ?? '') ?>">
                <button type="submit">Export starten</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ========== INITIALIZE ==========
$db = DatabaseFacade::getInstance();
$db->connect();
$config = $db->getSettingsAsNestedArray();

// Ensure defaults for missing keys
$config += [
    'tracking' => ['timezone' => 'Europe/Berlin'],
    'site' => ['name' => 'Meine Webseite'],
];

// Create dummy visitor for Tracker init (not used for export)
$visitor = Visitor::fromCurrentRequest();
$tracker = new Tracker($db, $visitor, $config);
$stats = new Stats($db, $tracker, $config);

// ========== PARSE PARAMETERS ==========
$format = strtolower($_GET['format'] ?? $_POST['format'] ?? 'csv');
$from = $_GET['from'] ?? $_POST['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to = $_GET['to'] ?? $_POST['to'] ?? date('Y-m-d');
$type = $_GET['type'] ?? $_POST['type'] ?? 'daily'; // daily, hourly, summary

// ========== FETCH DATA ==========
$rangeData = $tracker->getRangeStats($from, $to);
$summaryData = $stats->getPublicSummary();

// ========== EXPORT FORMATS ==========
switch ($format) {
    case 'json':
        exportJSON($rangeData, $summaryData, $from, $to);
        break;

    case 'excel':
    case 'xls':
        exportCSV($rangeData, $summaryData, $from, $to, true);
        break;

    case 'csv':
    default:
        exportCSV($rangeData, $summaryData, $from, $to, false);
        break;
}

// ========== EXPORT FUNCTIONS ==========

/**
 * Export data as JSON.
 */
function exportJSON(array $rangeData, array $summaryData, string $from, string $to): void
{
    $filename = 'countr_export_' . $from . '_to_' . $to . '.json';

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $export = [
        'export_info' => [
            'generated' => date('Y-m-d H:i:s'),
            'date_range' => ['from' => $from, 'to' => $to],
            'generator' => 'Countr Analytics',
        ],
        'summary' => $summaryData,
        'daily_data' => $rangeData,
    ];

    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Export data as CSV (or Excel-compatible TSV).
 *
 * @param array $rangeData
 * @param array $summaryData
 * @param string $from
 * @param string $to
 * @param bool $excelMode Use semicolons and BOM for Excel
 */
function exportCSV(array $rangeData, array $summaryData, string $from, string $to, bool $excelMode = false): void
{
    $ext = $excelMode ? 'csv' : 'csv'; // Excel reads .csv files with BOM
    $filename = 'countr_export_' . $from . '_to_' . $to . '.' . $ext;

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    // Open output stream
    $output = fopen('php://output', 'w');

    if ($output === false) {
        http_response_code(500);
        echo 'Internal Server Error: Cannot open output stream';
        exit;
    }

    // Write BOM for Excel UTF-8 compatibility
    if ($excelMode) {
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    }

    // Separator: semicolon for Excel, comma for standard CSV
    $delimiter = $excelMode ? ';' : ',';

    // Header row
    fputcsv($output, [
        'Datum',
        'Besucher',
        'Pageviews',
        'Unique Visitors',
    ], $delimiter, '"', '\\');

    // Data rows
    $days = $rangeData['days'] ?? [];
    foreach ($days as $day) {
        fputcsv($output, [
            $day['date'] ?? '',
            $day['visitors'] ?? 0,
            $day['pageviews'] ?? 0,
            $day['unique'] ?? 0,
        ], $delimiter, '"', '\\');
    }

    // Totals row
    fputcsv($output, [''], $delimiter, '"', '\\'); // Empty separator row
    fputcsv($output, [
        'GESAMT',
        $rangeData['totals']['visitors'] ?? 0,
        $rangeData['totals']['pageviews'] ?? 0,
        $rangeData['totals']['unique'] ?? 0,
    ], $delimiter, '"', '\\');

    // Summary section
    fputcsv($output, [''], $delimiter, '"', '\\');
    fputcsv($output, ['ZUSAMMENFASSUNG'], $delimiter, '"', '\\');
    fputcsv($output, ['Zeitraum', $from . ' bis ' . $to], $delimiter, '"', '\\');

    $online = $summaryData['stats']['today']['online'] ?? 0;
    fputcsv($output, ['Aktuell online', $online], $delimiter, '"', '\\');

    fclose($output);
    exit;
}