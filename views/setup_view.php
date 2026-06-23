<?php
/**
 * Countr Analytics - Setup Wizard View
 *
 * Renders the HTML UI for the setup wizard. All business logic resides
 * in setup.php (the controller). This view uses variables injected by
 * the controller.
 *
 * Expected variables:
 *   $step             - Current wizard step (1, 2, or 3)
 *   $error            - Error message string (empty if none)
 *   $setupDetails     - Array with setup result details (api_key, demo_days)
 *   $checksByCategory - System checks grouped by category
 *   $allPassed        - Whether all system checks passed
 *   $detectedUrl      - Auto-detected base URL
 *   $detectedPath     - Auto-detected base path
 *
 * @package    Countr
 * @copyright  2026 Countr Analytics
 * @license    GPL-3.0-or-later
 * @version    1.6.0
 */

declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Countr Analytics - Installation</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container { max-width: 750px; width: 100%; }
        .card {
            background: #fff;
            border-radius: 12px;
            padding: 2.5rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
        }
        h1 { font-size: 1.8rem; color: #333; margin-bottom: 1.5rem; text-align: center; }
        h2 { font-size: 1.2rem; color: #555; margin-bottom: 1rem; }
        h3 { font-size: 1rem; color: #667; margin-bottom: 0.5rem; margin-top: 1.2rem; }
        .progress {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
            gap: 8px;
        }
        .progress-step {
            width: 32px; height: 32px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: bold;
            background: #e0e0e0;
            color: #888;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        .progress-step.active { background: #667eea; color: #fff; }
        .progress-step.done { background: #28a745; color: #fff; }
        .progress-connector {
            width: 40px; height: 2px;
            background: #e0e0e0;
            align-self: center;
            transition: background 0.3s ease;
        }
        .progress-connector.done { background: #28a745; }

        .alert {
            padding: 1rem 1.2rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 14px;
            line-height: 1.6;
        }
        .alert-error {
            background: #fff3f3;
            border: 1px solid #ffcaca;
            color: #c00;
        }
        .alert-success {
            background: #f0fff4;
            border: 1px solid #c6f6d5;
            color: #2f855a;
            text-align: center;
        }
        .alert-warning {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
        }
        .alert-info {
            background: #f0f4ff;
            border: 1px solid #c6d5f6;
            color: #2f4a85;
            font-size: 13px;
        }
        .alert code {
            background: rgba(0,0,0,0.07);
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 13px;
            white-space: nowrap;
        }
        .alert pre {
            background: #1e1e2e;
            color: #cdd6f4;
            padding: 0.8rem;
            border-radius: 6px;
            font-size: 12px;
            overflow-x: auto;
            margin: 8px 0 0;
        }

        table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; vertical-align: top; }
        th { font-weight: 600; color: #555; font-size: 13px; }
        .status-pass { color: #28a745; font-weight: bold; }
        .status-fail { color: #dc3545; font-weight: bold; }
        .status-warn { color: #e6a817; font-weight: bold; }
        .fix-cell { font-size: 12px; color: #888; max-width: 250px; }
        .fix-cell code { background: #f5f5f5; padding: 1px 5px; border-radius: 3px; }

        .btn {
            display: inline-block; padding: 10px 22px;
            border: none; border-radius: 6px;
            font-size: 15px; font-weight: 600;
            cursor: pointer; text-decoration: none;
            transition: all 0.2s;
            font-family: inherit;
        }
        .btn-primary {
            background: #667eea; color: #fff; width: 100%;
        }
        .btn-primary:hover { background: #5a6fd6; }
        .btn-primary:disabled { background: #ccc; cursor: not-allowed; }
        .btn-success { background: #28a745; color: #fff; }
        .btn-success:hover { background: #218838; }
        .btn-outline { background: #fff; color: #667eea; border: 2px solid #667eea; }
        .btn-sm { padding: 6px 14px; font-size: 13px; border-radius: 4px; }
        .btn-auto-fix {
            background: #ff8c00;
            color: #fff;
            border: none;
            padding: 6px 14px;
            font-size: 12px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-auto-fix:hover { background: #e07800; }
        .btn-auto-fix:disabled { background: #ccc; cursor: not-allowed; }
        .btn-auto-fix.success { background: #28a745; }
        .btn-auto-fix.failed { background: #dc3545; }

        label { display: block; margin-bottom: 5px; font-weight: 600; color: #444; font-size: 14px; }
        .label-hint { font-weight: normal; color: #888; font-size: 12px; }
        input[type="text"], input[type="password"], select {
            width: 100%; padding: 10px 12px;
            border: 2px solid #e0e0e0; border-radius: 6px;
            font-size: 14px; margin-bottom: 1rem;
            transition: border-color 0.2s;
            font-family: inherit;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
        }
        .checkbox-group { margin-bottom: 1rem; border-top: 1px solid #eee; padding-top: 0.8rem; }
        .checkbox-group label {
            display: flex; align-items: center; gap: 8px;
            font-weight: normal; cursor: pointer; padding: 6px 0; font-size: 13px;
        }
        .checkbox-group input[type="checkbox"] {
            width: 18px; height: 18px; cursor: pointer; flex-shrink: 0;
        }

        .btn-group { display: flex; gap: 10px; margin-top: 1.2rem; justify-content: center; flex-wrap: wrap; }
        .btn-group .btn { width: auto; padding: 10px 24px; font-size: 14px; }

        .restart { display: block; text-align: center; margin-top: 1rem; color: #667eea; font-size: 14px; }

        pre {
            background: #1e1e2e; color: #cdd6f4;
            padding: 1rem; border-radius: 8px;
            font-size: 12px; overflow-x: auto; margin-top: 8px;
            position: relative;
        }
        pre .copy-btn {
            position: absolute; top: 8px; right: 8px;
            background: #313244; color: #cdd6f4;
            border: none; padding: 4px 10px; border-radius: 4px;
            cursor: pointer; font-size: 11px;
        }
        .api-key-display {
            background: #f0f4ff;
            border: 2px dashed #667eea;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            text-align: center;
            font-family: monospace;
            font-size: 16px;
            word-break: break-all;
        }

        .step-section { animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .spinner {
            display: inline-block; width: 16px; height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            vertical-align: middle;
            margin-right: 6px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .check-category { margin-bottom: 1.5rem; }
        .check-category h3 {
            font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;
            color: #888; margin-bottom: 0.5rem;
            border-bottom: 1px solid #eee; padding-bottom: 6px;
        }

        @media (max-width: 600px) {
            .card { padding: 1.5rem; }
            h1 { font-size: 1.4rem; }
            th, td { font-size: 12px; padding: 8px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>🚀 Countr Analytics Installation</h1>

        <!-- Progress bar -->
        <div class="progress">
            <div class="progress-step <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>">1</div>
            <div class="progress-connector <?= $step > 1 ? 'done' : '' ?>"></div>
            <div class="progress-step <?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>">2</div>
            <div class="progress-connector <?= $step > 2 ? 'done' : '' ?>"></div>
            <div class="progress-step <?= $step >= 3 ? 'done' : '' ?>">✓</div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <strong>⚠️ Fehler:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <!-- STEP 1: System Check -->
            <div class="step-section">
                <h2>📋 System-Prüfung</h2>
                <p style="color:#888;font-size:13px;margin-bottom:1.2rem;">
                    Ihr Server wird auf Kompatibilität mit Countr Analytics geprüft.
                    Kritische Probleme (<span style="color:#dc3545;">❌</span>) müssen behoben werden,
                    Warnungen (<span style="color:#e6a817;">⚠️</span>) sind optional.
                </p>

                <?php foreach ($checksByCategory as $category => $checks): ?>
                    <?php
                    $categoryLabels = [
                        'server' => '🖥️ Server-Konfiguration',
                        'php_extensions' => '🔌 PHP-Erweiterungen',
                        'php_functions' => '⚙️ PHP-Funktionen',
                        'filesystem' => '📁 Dateisystem & Rechte',
                    ];
                    $categoryLabel = $categoryLabels[$category] ?? '📦 ' . ucfirst($category);
                    ?>
                    <div class="check-category">
                        <h3><?= $categoryLabel ?></h3>
                        <table>
                            <tr><th>Komponente</th><th>Benötigt</th><th>Status</th><th>Aktion</th></tr>
                            <?php foreach ($checks as $key => $check): ?>
                                <tr>
                                    <td><?= htmlspecialchars($check['name']) ?></td>
                                    <td style="font-size:12px;color:#888;"><?= htmlspecialchars($check['required']) ?></td>
                                    <td>
                                        <span class="<?= $check['status'] ? 'status-pass' : 'status-fail' ?>">
                                            <?= $check['status_text'] ?>
                                        </span>
                                        <br><small style="color:#888;"><?= htmlspecialchars($check['current']) ?></small>
                                    </td>
                                    <td class="fix-cell">
                                        <?php if (!$check['status'] && isset($check['fix'])): ?>
                                            <div style="margin-bottom:4px;"><?= nl2br(htmlspecialchars($check['fix'])) ?></div>
                                            <?php if (isset($check['auto_fix'])): ?>
                                                <button class="btn-auto-fix"
                                                        onclick="InstallWizard.autoFix('<?= $check['auto_fix'] ?>', this)"
                                                        data-fix-type="<?= $check['auto_fix'] ?>">
                                                    🔧 Automatisch beheben
                                                </button>
                                            <?php endif; ?>
                                        <?php elseif ($check['status']): ?>
                                            <span style="color:#28a745;">✓</span>
                                        <?php else: ?>
                                            <span style="color:#888;">–</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endforeach; ?>

                <?php if ($allPassed): ?>
                    <div class="alert alert-success" style="padding:1rem;font-size:14px;">
                        ✅ Alle System-Prüfungen bestanden! Ihr Server ist bereit für Countr Analytics.
                    </div>
                    <form method="post">
                        <input type="hidden" name="step" value="1">
                        <button type="submit" class="btn btn-primary">Weiter zur Konfiguration →</button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <strong>⚠️ Einige Prüfungen schlugen fehl</strong><br>
                        Beheben Sie die markierten Probleme und laden Sie die Seite neu,
                        oder nutzen Sie die <strong>🔧 Automatisch beheben</strong>-Buttons wo verfügbar.
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-outline" onclick="location.reload()">🔄 Erneut prüfen</button>
                        <?php if ($allPassed): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="step" value="1">
                                <button type="submit" class="btn btn-primary">Trotzdem fortfahren →</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- Manual setup instructions if all else fails -->
                    <div class="alert alert-info" style="margin-top:1.5rem;">
                        <strong>📝 Manuelle Installation (Fallback):</strong><br>
                        Falls die automatische Behebung nicht funktioniert, führen Sie folgende Befehle auf Ihrem Server aus:
                        <pre><code>cd <?= htmlspecialchars(COUNTR_DIR) ?>

                        # Verzeichnisse erstellen
                        mkdir -p data data/visitors data/sessions data/logs data/backups data/exports cache

                        # Rechte rekursiv setzen (Verzeichnisse 755, Dateien 644)
                        find data cache -type d -exec chmod 755 {} \;
                        find data cache -type f -exec chmod 644 {} \;
                        chmod 755 data cache

                        # Besitzer setzen (falls nötig)
                        chown -R www-data:www-data data cache</code></pre>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($step === 2): ?>
            <!-- STEP 2: Configuration -->
            <div class="step-section">
                <h2>⚙️ Konfiguration</h2>
                <form method="post" autocomplete="off">
                    <input type="hidden" name="step" value="2">

                    <label for="site_name">Name der Webseite <span class="label-hint">– Wird im Dashboard angezeigt</span></label>
                    <input type="text" id="site_name" name="site_name"
                           value="Meine Webseite" required autofocus>

                    <label for="site_url">URL der Webseite</label>
                    <input type="text" id="site_url" name="site_url"
                           value="<?= htmlspecialchars($detectedUrl) ?>" required>

                    <label for="password">Admin-Passwort <span class="label-hint">– Mindestens 6 Zeichen</span></label>
                    <input type="password" id="password" name="password"
                           placeholder="Sicheres Passwort" required minlength="6">

                    <label for="password_confirm">Passwort bestätigen</label>
                    <input type="password" id="password_confirm" name="password_confirm"
                           placeholder="Passwort wiederholen" required minlength="6">

                    <label for="timezone">Zeitzone</label>
                    <select id="timezone" name="timezone">
                        <option value="Europe/Berlin">Europe/Berlin (Deutschland)</option>
                        <option value="Europe/Vienna">Europe/Vienna (Österreich)</option>
                        <option value="Europe/Zurich">Europe/Zurich (Schweiz)</option>
                        <option value="Europe/London">Europe/London (UK)</option>
                        <option value="America/New_York">America/New_York (USA Ostküste)</option>
                        <option value="America/Chicago">America/Chicago (USA Zentral)</option>
                        <option value="America/Los_Angeles">America/Los_Angeles (USA Westküste)</option>
                        <option value="Asia/Tokyo">Asia/Tokyo (Japan)</option>
                        <option value="UTC">UTC</option>
                    </select>

                    <h3>🔒 Datenschutz & Sicherheit</h3>
                    <div class="checkbox-group">
                        <label>
                            <input type="checkbox" name="anonymize_ip" checked>
                            <span>IP-Adressen anonymisieren <span class="label-hint">(empfohlen für DSGVO)</span></span>
                        </label>
                        <label>
                            <input type="checkbox" name="ignore_bots" checked>
                            <span>Bots/Crawler ignorieren <span class="label-hint">(nur echte Besucher zählen)</span></span>
                        </label>
                        <label>
                            <input type="checkbox" name="enable_public" checked>
                            <span>Öffentliche Statistiken aktivieren <span class="label-hint">(Dashboard ohne Login)</span></span>
                        </label>
                    </div>

                    <h3>🔧 Erweiterte Optionen</h3>
                    <div class="checkbox-group">
                        <label>
                            <input type="checkbox" name="generate_api_key">
                            <span>API-Schlüssel generieren <span class="label-hint">(für externe Integration)</span></span>
                        </label>
                        <label>
                            <input type="checkbox" name="generate_demo_data">
                            <span>Demo-Daten generieren <span class="label-hint">(30 Tage Testdaten für sofortige Ansicht)</span></span>
                        </label>
                        <label>
                            <input type="checkbox" name="enable_multi_user">
                            <span>Multi-User Support aktivieren <span class="label-hint">(für Teams, später konfigurierbar)</span></span>
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary">🚀 Installation starten →</button>
                </form>
            </div>

        <?php elseif ($step === 3): ?>
            <!-- STEP 3: Complete -->
            <div class="step-section">
                <div class="alert alert-success">
                    <h2>✅ Installation erfolgreich!</h2>
                    <p>Countr Analytics wurde erfolgreich eingerichtet und ist jetzt einsatzbereit.</p>
                </div>

                <?php if (!empty($setupDetails['api_key'])): ?>
                    <div class="alert alert-info">
                        <h3>🔑 Ihr API-Schlüssel</h3>
                        <div class="api-key-display"><?= htmlspecialchars($setupDetails['api_key']) ?></div>
                        <p style="font-size:12px;color:#888;">Bewahren Sie diesen Schlüssel sicher auf. Er wird für externe API-Zugriffe benötigt.</p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($setupDetails['demo_days'])): ?>
                    <div class="alert alert-info">
                        <h3>📊 Demo-Daten</h3>
                        <p><?= $setupDetails['demo_days'] ?> Tage mit Testdaten wurden generiert. Sie können sofort alle Funktionen testen!</p>
                    </div>
                <?php endif; ?>

                <div class="btn-group">
                    <a href="index.php" class="btn btn-primary">📊 Zum Dashboard</a>
                    <a href="admin.php" class="btn btn-success">⚙️ Zum Admin-Bereich</a>
                </div>

                <p style="text-align:center;margin-top:1.5rem;color:#888;font-size:13px;">
                    📝 Tracking-Code in Ihre Webseite einbauen:
                </p>
                <pre><code><!-- Countr Analytics Tracking (empfohlen) -->
                <!-- Erfasst automatisch die aktuelle Seite inkl. Unterseiten -->
                <script>
                (function(e,n){e.src=n+"/track.php?js=1&page="+encodeURIComponent(location.pathname+location.search),e.async=!0,document.head.appendChild(e)})
                (document.createElement("script"),"<?= htmlspecialchars($detectedUrl . $detectedPath) ?>");
                </script>

                <!-- Fallback: Image-Pixel für No-JS (nur Seiten, auf denen der Tag direkt platziert ist) -->
                <noscript><img src="<?= htmlspecialchars($detectedUrl . $detectedPath) ?>/track.php" width="1" height="1" style="display:none" alt=""></noscript></code></pre>

                <div class="alert alert-info" style="margin-top:1rem;">
                    <strong>🔒 Sicherheitshinweis:</strong> Die <code>setup.php</code> wurde automatisch deaktiviert (umbenannt zu <code>setup.php.disabled</code>).
                    Sie können diese Datei bei Bedarf löschen.
                </div>

                <div class="alert alert-info" style="margin-top:0.5rem;">
                    <strong>📄 Lizenz:</strong> Countr Analytics is licensed under GNU General Public License v3.0.
                    <a href="LICENSE" target="_blank">View License</a> &middot;
                    <small>Copyright (C) 2026 Countr Analytics</small>
                </div>

                <div class="alert alert-info" style="margin-top:0.5rem;">
                    <strong>📁 Verzeichnis-Struktur:</strong> Folgende Verzeichnisse wurden erstellt und mit rekursiven Rechten (755/644) geschützt:
                    <ul style="margin:8px 0 0 20px;font-size:12px;">
                        <li><code>data/visitors/</code> – Tägliche Besucherdaten</li>
                        <li><code>data/sessions/</code> – Session-Dateien</li>
                        <li><code>data/logs/</code> – Fehler- und Access-Logs</li>
                        <li><code>data/backups/</code> – Automatische Backups</li>
                        <li><code>data/exports/</code> – CSV/JSON/Excel-Exports</li>
                        <li><code>cache/</code> – Performance-Cache</li>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($step < 3): ?>
            <a href="?step=1" class="restart">↻ Von vorne beginnen</a>
        <?php endif; ?>
    </div>
</div>

<script>
/**
 * InstallWizard – AJAX-basierter Installations-Assistent
 *
 * Bietet Auto-Fix-Funktionen für:
 * - Verzeichnis-Erstellung (create_directories)
 * - Rechte-Korrektur (fix_permissions)
 * - Rekursive Rechte-Korrektur (fix_permissions_all)
 * - System-Prüfung neu laden
 *
 * @version    1.6.0
 */
class InstallWizard {
    /**
     * Führt einen Auto-Fix für ein bestimmtes Problem aus
     * @param {string} fixType - Der Fix-Typ (create_directories, fix_permissions, fix_permissions_all)
     * @param {HTMLElement} button - Der Button, der den Fix ausgelöst hat
     */
    static async autoFix(fixType, button) {
        if (!button) return;

        const originalText = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner"></span> Arbeite...';
        button.classList.remove('success', 'failed');

        try {
            const response = await fetch('setup.php?action=' + encodeURIComponent(fixType));
            const data = await response.json();

            if (data.success) {
                button.classList.add('success');
                button.innerHTML = '✅ Erledigt!';

                // Show summary if available (fix_permissions_all)
                if (data.total_dirs !== undefined) {
                    let msg = '✅ Rekursive Rechte gesetzt!\n\n'
                        + 'Verzeichnisse: ' + data.fixed_dirs + '/' + data.total_dirs + '\n'
                        + 'Dateien: ' + data.fixed_files + '/' + data.total_files;
                    if (data.failed_dirs > 0 || data.failed_files > 0) {
                        msg += '\n\n⚠️ Fehler bei:\n'
                            + 'Verzeichnisse: ' + data.failed_dirs + '\n'
                            + 'Dateien: ' + data.failed_files;
                    }
                    alert(msg);
                }

                // Reload after short delay so user can see the success
                setTimeout(() => { location.reload(); }, 1200);
            } else {
                button.classList.add('failed');
                button.innerHTML = '❌ Fehlgeschlagen';
                button.disabled = false;

                // Show detailed error
                let errorMsg = 'Automatische Behebung fehlgeschlagen.\n\n';
                if (data.results) {
                    for (const [key, info] of Object.entries(data.results)) {
                        if (info.status === 'failed' || info.status === 'missing') {
                            errorMsg += key + ': ' + (info.message || info.error || 'Unbekannter Fehler') + '\n';
                            if (info.fix) {
                                errorMsg += '  Lösung: ' + info.fix + '\n';
                            }
                        }
                        // Show failed_items for recursive fix
                        if (info.failed_items && info.failed_items.length > 0) {
                            errorMsg += '  Fehlgeschlagene Elemente:\n';
                            for (const item of info.failed_items) {
                                errorMsg += '    - ' + item.path + ' (' + item.type + '): ' + item.error + '\n';
                            }
                        }
                    }
                } else if (data.message) {
                    errorMsg += data.message;
                }
                alert(errorMsg);
            }
        } catch (err) {
            button.classList.add('failed');
            button.innerHTML = '❌ Netzwerkfehler';
            button.disabled = false;
            console.error('Auto-fix error:', err);
            alert('Ein Netzwerkfehler ist aufgetreten. Bitte versuchen Sie die manuelle Installation.\n\n'
                + 'Fehler: ' + err.message);
        }
    }

    /**
     * Prüft den System-Status per AJAX und aktualisiert die Anzeige
     */
    static async refreshSystemCheck() {
        try {
            const response = await fetch('setup.php?action=check_system');
            const data = await response.json();
            if (data.success && data.all_passed) {
                location.reload();
            } else {
                location.reload();
            }
        } catch (err) {
            console.error('System check error:', err);
            location.reload();
        }
    }
}

// ========== COPY TRACKING CODE TO CLIPBOARD ==========
document.addEventListener('DOMContentLoaded', function() {
    const copyBtn = document.querySelector('.copy-btn');
    if (copyBtn) {
        copyBtn.addEventListener('click', function() {
            const pre = this.closest('pre');
            const code = pre.querySelector('code');
            if (code) {
                const text = code.textContent || code.innerText;
                navigator.clipboard.writeText(text).then(() => {
                    this.textContent = '✅ Kopiert!';
                    setTimeout(() => { this.textContent = '📋 Kopieren'; }, 2000);
                }).catch(() => {
                    // Fallback for older browsers
                    const textarea = document.createElement('textarea');
                    textarea.value = text;
                    textarea.style.position = 'fixed';
                    textarea.style.opacity = '0';
                    document.body.appendChild(textarea);
                    textarea.select();
                    try { document.execCommand('copy'); } catch(e) {}
                    document.body.removeChild(textarea);
                    this.textContent = '✅ Kopiert!';
                    setTimeout(() => { this.textContent = '📋 Kopieren'; }, 2000);
                });
            }
        });
    }
});
</script>
</body>
</html>