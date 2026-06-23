<?php
/**
 * countr – Internationalization (i18n) Module
 *
 * Detects the user's preferred language via a strict priority hierarchy:
 *  1. ?lang= query parameter (explicit manual override) – stored in session if available
 *  2. HTTP_ACCEPT_LANGUAGE browser header (first two chars)
 *  3. Cloudflare HTTP_CF_IPCOUNTRY header (Geo-IP fallback)
 *  4. Default: en (English)
 *
 * @package    Countr
 * @copyright  2026 Countr Analytics
 * @license    GPL-3.0-or-later
 * @version    1.3.0
 */

declare(strict_types=1);

// Supported language codes
define('SUPPORTED_LANGS', ['de', 'en']);

// =========================================================================
// LANGUAGE DETECTION – Strict Priority Hierarchy
// =========================================================================

$lang = 'en'; // Priority 4: System default

// --- Priority 1: Manual override via ?lang= query parameter ---
// Also persists the choice in the session so it survives page reloads.
if (!empty($_GET['lang']) && in_array($_GET['lang'], SUPPORTED_LANGS, true)) {
    $lang = $_GET['lang'];

    // Store in session if sessions are available
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['lang'] = $lang;
}
// --- Priority 2: Standard browser Accept-Language header (the core fix) ---
elseif (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
    $browserLang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    if ($browserLang === 'de') {
        $lang = 'de';
    } elseif ($browserLang === 'en') {
        $lang = 'en';
    }
}
// --- Priority 3: Cloudflare Geo-IP header (optional fallback) ---
elseif (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
    $cc = strtoupper($_SERVER['HTTP_CF_IPCOUNTRY']);
    if (in_array($cc, ['DE', 'AT', 'CH'], true)) {
        $lang = 'de';
    } else {
        $lang = 'en';
    }
}
// Priority 4: $lang already defaults to 'en' above

// Final safety net – ensure only supported languages pass through
if (!in_array($lang, SUPPORTED_LANGS, true)) {
    $lang = 'en';
}

// =========================================================================
// LOAD TRANSLATIONS
// =========================================================================

$translationFile = __DIR__ . '/lang/' . $lang . '.json';

if (file_exists($translationFile)) {
    $json = file_get_contents($translationFile);
    $t = json_decode($json, true);
    if (!is_array($t)) {
        $t = [];
    }
} else {
    $t = [];
}

// Fallback: load default language file if the requested one is broken/empty
if (empty($t) && $lang !== 'en') {
    $fallbackFile = __DIR__ . '/lang/en.json';
    if (file_exists($fallbackFile)) {
        $json = file_get_contents($fallbackFile);
        $fb = json_decode($json, true);
        if (is_array($fb)) {
            $t = $fb;
        }
    }
}

/**
 * Helper: return translation for a given key, or the key itself if missing.
 *
 * @param string $key
 * @return string
 */
function __(string $key): string
{
    global $t;
    return $t[$key] ?? $key;
}

/**
 * Helper: echo translation for a given key.
 *
 * @param string $key
 * @return void
 */
function _e(string $key): void
{
    echo __($key);
}