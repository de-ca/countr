<?php
/**
 * Countr Analytics - Directory Permissions Check & Auto-Fix
 *
 * Shows current permissions for all required directories and files,
 * identifies problems, and offers one-click auto-fix via setup.php.
 *
 * @package    Countr
 * @copyright  2026 Countr Analytics
 * @license    GPL-3.0-or-later
 * @version    1.4.0
 */

declare(strict_types=1);

define('COUNTR_DIR', __DIR__);

/**
 * Check a single directory: exists, permissions, writable
 */
function checkDir(string $path, int $requiredPerm = 0755): array
{
    if (!is_dir($path)) {
        return [
            'status' => 'missing',
            'permissions' => null,
            'writable' => false,
            'message' => "Verzeichnis existiert nicht",
        ];
    }

    $perms = fileperms($path);
    $octal = substr(sprintf('%o', $perms), -4);
    $writable = is_writable($path);
    $meetsRequirement = ($perms & 0777) >= $requiredPerm;

    if ($writable && $meetsRequirement) {
        return [
            'status' => 'ok',
            'permissions' => $octal,
            'writable' => true,
            'message' => "Schreibbar ✓ (Rechte: $octal)",
        ];
    } elseif ($writable && !$meetsRequirement) {
        return [
            'status' => 'warning',
            'permissions' => $octal,
            'writable' => true,
            'message' => "Schreibbar, aber Rechte ($octal) unter $requiredPerm – kein Problem",
        ];
    } else {
        return [
            'status' => 'problem',
            'permissions' => $octal,
            'writable' => false,
            'message' => "NICHT schreibbar – Benötigt mind. $requiredPerm",
        ];
    }
}

/**
 * Recursively check all items in a directory
 */
function checkRecursive(string $dir): array
{
    $result = [
        'total_dirs' => 0,
        'total_files' => 0,
        'bad_dirs' => [],
        'bad_files' => [],
    ];

    if (!is_dir($dir)) {
        return $result;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $relPath = str_replace(COUNTR_DIR . '/', '', $path);

            if ($item->isDir()) {
                $result['total_dirs']++;
                if (!is_writable($path)) {
                    $result['bad_dirs'][] = [
                        'path' => $relPath,
                        'perms' => substr(sprintf('%o', fileperms($path)), -4),
                    ];
                }
            } else {
                $result['total_files']++;
                if (!is_writable($path)) {
                    $result['bad_files'][] = [
                        'path' => $relPath,
                        'perms' => substr(sprintf('%o', fileperms($path)), -4),
                    ];
                }
            }
        }
    } catch (\Exception $e) {
        $result['error'] = $e->getMessage();
    }

    return $result;
}

// ========== CHECK ALL DIRECTORIES ==========
$requiredDirs = [
    '.' => 0755,
    'data' => 0755,
    'data/visitors' => 0755,
    'data/sessions' => 0755,
    'data/logs' => 0755,
    'data/backups' => 0755,
    'data/exports' => 0755,
    'cache' => 0755,
];

$dirResults = [];
$hasProblems = false;
$hasMissing = false;

foreach ($requiredDirs as $dir => $required) {
    $path = COUNTR_DIR . '/' . $dir;
    $result = checkDir($path, $required);
    $dirResults[$dir] = $result;

    if ($result['status'] === 'problem') {
        $hasProblems = true;
    }
    if ($result['status'] === 'missing') {
        $hasMissing = true;
        $hasProblems = true;
    }
}

// ========== RECURSIVE CHECK ==========
$dataRecursive = checkRecursive(COUNTR_DIR . '/data');
$cacheRecursive = checkRecursive(COUNTR_DIR . '/cache');

$totalBad = count($dataRecursive['bad_dirs']) + count($dataRecursive['bad_files'])
          + count($cacheRecursive['bad_dirs']) + count($cacheRecursive['bad_files']);

// ========== HTTP OR CLI OUTPUT ==========
if (php_sapi_name() === 'cli') {
    // CLI output
    echo "=== Countr Analytics Permissions Check ===\n\n";

    echo str_pad('Directory', 22) . " | Perms  | Writable | Status\n";
    echo str_repeat('-', 70) . "\n";

    foreach ($dirResults as $dir => $r) {
        echo str_pad($dir, 22) . " | ";
        echo str_pad($r['permissions'] ?? 'N/A', 6) . " | ";
        echo str_pad($r['writable'] ? '✓' : '✗', 8) . " | ";
        echo $r['message'] . "\n";
    }

    echo "\n=== Recursive Check ===\n";
    echo "data/ : {$dataRecursive['total_dirs']} dirs, {$dataRecursive['total_files']} files";
    if (count($dataRecursive['bad_dirs']) > 0 || count($dataRecursive['bad_files']) > 0) {
        echo " – " . (count($dataRecursive['bad_dirs']) + count($dataRecursive['bad_files'])) . " problems";
    }
    echo "\n";
    echo "cache/: {$cacheRecursive['total_dirs']} dirs, {$cacheRecursive['total_files']} files";
    if (count($cacheRecursive['bad_dirs']) > 0 || count($cacheRecursive['bad_files']) > 0) {
        echo " – " . (count($cacheRecursive['bad_dirs']) + count($cacheRecursive['bad_files'])) . " problems";
    }
    echo "\n";

    if ($totalBad > 0) {
        echo "\n=== Problems Found ===\n";
        echo "Fix manually:\n";
        echo "  chmod -R 755 data cache\n";
        echo "  find data cache -type d -exec chmod 755 {} \\;\n";
        echo "  find data cache -type f -exec chmod 644 {} \\;\n";
        echo "\nOr fix via setup.php?action=fix_permissions_all\n";
    }

    echo "\n=== Summary ===\n";
    echo $hasProblems ? "⚠️  PROBLEMS FOUND – Permissions need fixing\n" : "✅ All permissions OK\n";

    exit($hasProblems ? 1 : 0);
}

// ========== HTML OUTPUT ==========
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permissions Check – Countr Analytics</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 2rem;
            color: #333;
        }
        .container { max-width: 900px; margin: 0 auto; }
        h1 { margin-bottom: 1.5rem; }
        h2 { margin: 1.5rem 0 1rem; font-size: 1.2rem; }
        .card {
            background: #fff;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; }
        th { font-weight: 600; color: #555; font-size: 13px; }
        .status-ok { color: #28a745; font-weight: bold; }
        .status-warning { color: #e6a817; font-weight: bold; }
        .status-problem { color: #dc3545; font-weight: bold; }
        .status-missing { color: #dc3545; font-weight: bold; }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-fix {
            background: #ff8c00;
            color: #fff;
        }
        .btn-fix:hover { background: #e07800; }
        .btn-manual { background: #667eea; color: #fff; margin-left: 10px; }
        .btn-manual:hover { background: #5a6fd6; }
        .btn-success { background: #28a745; color: #fff; }
        .summary-ok { background: #f0fff4; border: 1px solid #c6f6d5; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; color: #2f855a; }
        .summary-bad { background: #fff3f3; border: 1px solid #ffcaca; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; color: #c00; }
        pre { background: #1e1e2e; color: #cdd6f4; padding: 1rem; border-radius: 6px; font-size: 12px; overflow-x: auto; margin-top: 8px; }
        .spinner {
            display: inline-block; width: 14px; height: 14px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            vertical-align: middle;
            margin-right: 6px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .bad-list { max-height: 200px; overflow-y: auto; font-size: 12px; color: #c00; margin-top: 8px; }
        .bad-list div { padding: 2px 0; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 Countr Analytics – Permission Check</h1>

    <?php if (!$hasProblems && $totalBad === 0): ?>
        <div class="summary-ok">
            <strong>✅ Alle Berechtigungen sind korrekt.</strong> Keine Probleme gefunden.
        </div>
    <?php else: ?>
        <div class="summary-bad">
            <strong>⚠️ Berechtigungsprobleme gefunden!</strong><br>
            <?= $hasMissing ? 'Einige Verzeichnisse fehlen.<br>' : '' ?>
            <?= $totalBad > 0 ? "$totalBad Element(e) sind nicht schreibbar.<br>" : '' ?>
            <button class="btn btn-fix" id="btnAutoFix" onclick="autoFixPermissions()">
                🔧 Rekursiv beheben (via setup.php)
            </button>
            <button class="btn btn-manual" onclick="document.getElementById('manualFix').style.display='block'">
                📋 Manuelle Befehle anzeigen
            </button>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>📁 Verzeichnis-Berechtigungen</h2>
        <table>
            <tr><th>Verzeichnis</th><th>Rechte</th><th>Schreibbar</th><th>Status</th></tr>
            <?php foreach ($dirResults as $dir => $r): ?>
                <tr>
                    <td><code><?= htmlspecialchars($dir) ?></code></td>
                    <td><?= $r['permissions'] ?? 'N/A' ?></td>
                    <td><?= $r['writable'] ? '✓' : '✗' ?></td>
                    <td><span class="status-<?= $r['status'] ?>"><?= htmlspecialchars($r['message']) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php if ($totalBad > 0): ?>
        <div class="card">
            <h2>📋 Detaillierte Probleme</h2>
            <?php if (count($dataRecursive['bad_dirs']) > 0 || count($dataRecursive['bad_files']) > 0): ?>
                <h3>data/ (<?= count($dataRecursive['bad_dirs']) + count($dataRecursive['bad_files']) ?> Probleme)</h3>
                <div class="bad-list">
                    <?php foreach ($dataRecursive['bad_dirs'] as $item): ?>
                        <div>📁 <?= htmlspecialchars($item['path']) ?> (<?= $item['perms'] ?>)</div>
                    <?php endforeach; ?>
                    <?php foreach ($dataRecursive['bad_files'] as $item): ?>
                        <div>📄 <?= htmlspecialchars($item['path']) ?> (<?= $item['perms'] ?>)</div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (count($cacheRecursive['bad_dirs']) > 0 || count($cacheRecursive['bad_files']) > 0): ?>
                <h3>cache/ (<?= count($cacheRecursive['bad_dirs']) + count($cacheRecursive['bad_files']) ?> Probleme)</h3>
                <div class="bad-list">
                    <?php foreach ($cacheRecursive['bad_dirs'] as $item): ?>
                        <div>📁 <?= htmlspecialchars($item['path']) ?> (<?= $item['perms'] ?>)</div>
                    <?php endforeach; ?>
                    <?php foreach ($cacheRecursive['bad_files'] as $item): ?>
                        <div>📄 <?= htmlspecialchars($item['path']) ?> (<?= $item['perms'] ?>)</div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card" id="manualFix" style="display:none;">
        <h2>📋 Manuelle Korrektur-Befehle</h2>
        <p style="margin-bottom:8px;">Führen Sie folgende Befehle auf Ihrem Server aus:</p>
        <pre><code># In das Countr Analytics-Verzeichnis wechseln
cd <?= htmlspecialchars(COUNTR_DIR) ?>

# Rekursive Rechte setzen:
# Verzeichnisse: 755 (rwxr-xr-x)
find data cache -type d -exec chmod 755 {} \;

# Dateien: 644 (rw-r--r--)
find data cache -type f -exec chmod 644 {} \;

# Hauptverzeichnisse
chmod 755 data cache

# Optional: Besitzer korrigieren
chown -R www-data:www-data data cache</code></pre>
        <p style="font-size:12px;color:#888;margin-top:8px;">
            Nach Ausführung dieser Befehle laden Sie diese Seite neu.
        </p>
    </div>

    <div style="text-align:center;margin-top:1rem;">
        <a href="setup.php" class="btn btn-success">🔧 Zum Setup-Assistenten</a>
        <a href="index.php" class="btn btn-manual">📊 Zum Dashboard</a>
    </div>
</div>

<script>
async function autoFixPermissions() {
    const btn = document.getElementById('btnAutoFix');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Repariere rekursiv...';

    try {
        const response = await fetch('setup.php?action=fix_permissions_all');
        const data = await response.json();

        if (data.success) {
            btn.classList.add('btn-success');
            btn.innerHTML = '✅ Erledigt! ' + data.fixed_dirs + ' Verz., ' + data.fixed_files + ' Dateien';
            setTimeout(() => { location.reload(); }, 1500);
        } else {
            btn.innerHTML = '❌ Fehlgeschlagen';
            btn.disabled = false;
            let msg = 'Auto-Fix fehlgeschlagen.\n\n';
            if (data.results) {
                for (const [key, info] of Object.entries(data.results)) {
                    if (info.failed_items && info.failed_items.length > 0) {
                        msg += key + ':\n';
                        for (const item of info.failed_items) {
                            msg += '  - ' + item.path + ': ' + item.error + '\n';
                        }
                    }
                }
            }
            alert(msg);
            document.getElementById('manualFix').style.display = 'block';
        }
    } catch (err) {
        btn.innerHTML = '❌ Netzwerkfehler';
        btn.disabled = false;
        alert('Netzwerkfehler: ' + err.message);
        document.getElementById('manualFix').style.display = 'block';
    }
}
</script>
</body>
</html>