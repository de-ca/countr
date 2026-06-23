<?php
/**
 * BrowserDetector - Browser/OS/Device detection with 50+ patterns each.
 *
 * Extracted from the monolithic Visitor class. Parses User-Agent strings
 * to identify browsers, operating systems, device types, and versions.
 *
 * @package Countr\Tracking
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Tracking;

class BrowserDetector
{
    // =========================================================================
    // BROWSER PATTERNS (50+ patterns)
    // =========================================================================

    private const BROWSER_PATTERNS = [
        // Edge (must come before Chrome – Edge UA contains "Chrome/")
        'edg/'          => 'Edge',
        'edge/'         => 'Edge (Legacy)',

        // Opera (various variants – must come before Chrome)
        'opr/'          => 'Opera',
        'opera mini'    => 'Opera Mini',
        'opera mobi'    => 'Opera Mobile',
        'opera'         => 'Opera',

        // Samsung Browser (must come before Chrome)
        'samsungbrowser/' => 'Samsung Browser',

        // UC Browser (must come before Chrome)
        'ucbrowser/'    => 'UC Browser',
        'ucweb/'        => 'UC Browser',

        // Yandex Browser (must come before Chrome)
        'yabrowser/'    => 'Yandex Browser',

        // Brave (must come before Chrome)
        'brave'         => 'Brave',

        // Vivaldi (must come before Chrome)
        'vivaldi/'      => 'Vivaldi',

        // Chrome (iOS) – must come before regular Chrome
        'crios/'        => 'Chrome (iOS)',
        'chrome/'       => 'Chrome',
        'chromium/'     => 'Chromium',

        // In-app browsers (before Firefox and Safari)
        'instagram'     => 'Instagram (in-app)',
        'fbav/'         => 'Facebook (in-app)',
        'fbios/'        => 'Facebook (iOS in-app)',
        'fban/'         => 'Facebook (Android in-app)',
        'tiktok'        => 'TikTok (in-app)',
        'whatsapp'      => 'WhatsApp (in-app)',
        'snapchat'      => 'Snapchat (in-app)',
        'twitter'       => 'Twitter (in-app)',
        'linkedin'      => 'LinkedIn (in-app)',
        'pinterest'     => 'Pinterest (in-app)',
        'wechat'        => 'WeChat (in-app)',
        'line/'         => 'LINE (in-app)',
        'kakaotalk'     => 'KakaoTalk (in-app)',

        // Firefox variants
        'firefox/'      => 'Firefox',
        'fxios/'        => 'Firefox (iOS)',
        'focus/'        => 'Firefox Focus',
        'fennec/'       => 'Firefox (Android)',

        // Safari variants
        'safari/'       => 'Safari',
        'mobile safari' => 'Safari Mobile',

        // Internet Explorer
        'msie '         => 'Internet Explorer',
        'trident/'      => 'Internet Explorer',
        'iemobile/'     => 'IE Mobile',

        // Other notable browsers
        'puffin/'       => 'Puffin',
        'silk/'         => 'Silk',
        'electron/'     => 'Electron',
        'maxthon/'      => 'Maxthon',
        'konqueror/'    => 'Konqueror',
        'seamonkey/'    => 'SeaMonkey',
        'pale moon/'    => 'Pale Moon',
        'waterfox/'     => 'Waterfox',
        'iceweasel/'    => 'Iceweasel',
        'icecat/'       => 'IceCat',
        'midori/'       => 'Midori',
        'epiphany/'     => 'Epiphany (GNOME Web)',
        'falkon/'       => 'Falkon',
        'otter/'        => 'Otter Browser',
        'netscape/'     => 'Netscape',
        'minimo/'       => 'Minimo',
        'dolphin/'      => 'Dolphin Browser',
        'miuibrowser/'  => 'Miui Browser',
        'huaweibrowser/' => 'Huawei Browser',
        'quark/'        => 'Quark Browser',
    ];

    // =========================================================================
    // OS PATTERNS (50+ patterns)
    // =========================================================================

    private const OS_PATTERNS = [
        // Windows
        'windows nt 10.0'   => 'Windows 10/11',
        'windows nt 6.3'    => 'Windows 8.1',
        'windows nt 6.2'    => 'Windows 8',
        'windows nt 6.1'    => 'Windows 7',
        'windows nt 6.0'    => 'Windows Vista',
        'windows nt 5.2'    => 'Windows Server 2003/XP x64',
        'windows nt 5.1'    => 'Windows XP',
        'windows nt 5.0'    => 'Windows 2000',
        'windows nt 4.0'    => 'Windows NT 4.0',
        'windows phone'     => 'Windows Phone',
        'windows'           => 'Windows',

        // iOS (MUST come before macOS – iPhone/iPad UAs contain "Mac OS X")
        'iphone'            => 'iOS',
        'ipad'              => 'iPadOS',
        'ipod'              => 'iOS',
        'ios'               => 'iOS',

        // Android (MUST come before Linux – Android UAs contain "Linux")
        'android'           => 'Android',

        // ChromeOS / Chromium OS (MUST come before Linux)
        'crkey'             => 'ChromeOS',
        'cros'              => 'ChromeOS',

        // macOS
        'mac os x 15'       => 'macOS Sequoia',
        'mac os x 14'       => 'macOS Sonoma',
        'mac os x 13'       => 'macOS Ventura',
        'mac os x 12'       => 'macOS Monterey',
        'mac os x 11'       => 'macOS Big Sur',
        'mac os x 10_15'    => 'macOS Catalina',
        'mac os x 10_14'    => 'macOS Mojave',
        'mac os x 10_13'    => 'macOS High Sierra',
        'mac os x 10_12'    => 'macOS Sierra',
        'mac os x 10_11'    => 'OS X El Capitan',
        'mac os x 10_10'    => 'OS X Yosemite',
        'mac os x 10_9'     => 'OS X Mavericks',
        'mac os x'          => 'macOS',
        'macintosh'         => 'macOS',
        'mac os'            => 'macOS',

        // Linux Distros
        'ubuntu'            => 'Ubuntu',
        'debian'            => 'Debian',
        'fedora'            => 'Fedora',
        'centos'            => 'CentOS',
        'red hat'           => 'Red Hat',
        'suse'              => 'openSUSE',
        'arch linux'        => 'Arch Linux',
        'gentoo'            => 'Gentoo',
        'linux mint'        => 'Linux Mint',
        'manjaro'           => 'Manjaro',
        'elementary os'     => 'Elementary OS',
        'pop!_os'           => 'Pop!_OS',
        'kali linux'        => 'Kali Linux',
        'raspbian'          => 'Raspbian',
        'linux'             => 'Linux',

        // Other operating systems
        'blackberry'        => 'BlackBerry',
        'tizen'             => 'Tizen',
        'kaios'             => 'KaiOS',
        'webos'             => 'WebOS',
        'freebsd'           => 'FreeBSD',
        'openbsd'           => 'OpenBSD',
        'netbsd'            => 'NetBSD',
        'sunos'             => 'Solaris',
        'solaris'           => 'Solaris',
        'haiku'             => 'Haiku',
        'beos'              => 'BeOS',
        'qnx'               => 'QNX',
        'symbian'           => 'Symbian',
        'series60'          => 'Symbian S60',
        'bada'              => 'Samsung Bada',
        'brew'              => 'Brew MP',
        'kindle'            => 'Fire OS',
        'silk'              => 'Fire OS',

        // Game Consoles
        'nintendo switch'   => 'Nintendo Switch',
        'nintendo wiiu'     => 'Nintendo Wii U',
        'nintendo wii'      => 'Nintendo Wii',
        'playstation 5'     => 'PlayStation 5',
        'playstation 4'     => 'PlayStation 4',
        'playstation 3'     => 'PlayStation 3',
        'playstation vita'  => 'PlayStation Vita',
        'playstation portable' => 'PSP',
        'xbox one'          => 'Xbox One',
        'xbox'              => 'Xbox',

        // Smart TV
        'smart-tv'          => 'Smart TV',
        'smarttv'           => 'Smart TV',
        'googletv'          => 'Google TV',
        'appletv'           => 'Apple TV',
        'roku'              => 'Roku',
        'lg netcast'        => 'LG Smart TV',
        'samsung smarttv'   => 'Samsung Smart TV',
        'panasonic viera'   => 'Panasonic Viera',
        'philipstv'         => 'Philips Smart TV',
        'opera tv'          => 'Opera TV',
        'web0s'             => 'LG webOS TV',
    ];

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Parse a User-Agent string to extract browser, OS, version, and device type.
     *
     * @param string $userAgent Raw User-Agent string
     * @param bool   $isBot     Whether the visitor is already identified as a bot
     * @return array{browser: string, browser_version: string, os: string, device_type: string}
     */
    public function parse(string $userAgent, bool $isBot = false): array
    {
        $uaLower = strtolower($userAgent);

        $result = [
            'browser'         => 'Unknown',
            'browser_version'  => '',
            'os'              => 'Unknown',
            'device_type'     => $isBot ? 'bot' : 'desktop',
        ];

        // If bot, skip detailed browser/OS parsing but still detect OS
        if ($isBot) {
            foreach (self::OS_PATTERNS as $pattern => $name) {
                if (strpos($uaLower, $pattern) !== false) {
                    $result['os'] = $name;
                    break;
                }
            }
            return $result;
        }

        // Detect device type
        $result['device_type'] = $this->detectDeviceType($uaLower);

        // Detect browser (ordered so Edge/Opera fires before Chrome)
        foreach (self::BROWSER_PATTERNS as $pattern => $name) {
            if (strpos($uaLower, $pattern) !== false) {
                $result['browser'] = $name;
                $result['browser_version'] = $this->extractVersion($uaLower, $pattern);
                break;
            }
        }

        // Detect OS
        foreach (self::OS_PATTERNS as $pattern => $name) {
            if (strpos($uaLower, $pattern) !== false) {
                $result['os'] = $name;
                break;
            }
        }

        return $result;
    }

    /**
     * Detect the device type from a lowercase User-Agent string.
     *
     * @param string $uaLower Lowercase User-Agent
     * @return string 'desktop', 'mobile', 'tablet', 'tv', 'console'
     */
    public function detectDeviceType(string $uaLower): string
    {
        // Game Consoles (check before tablet/mobile)
        if (
            strpos($uaLower, 'nintendo switch') !== false ||
            strpos($uaLower, 'nintendo wii') !== false ||
            strpos($uaLower, 'playstation') !== false ||
            strpos($uaLower, 'xbox') !== false
        ) {
            return 'console';
        }

        // Smart TVs
        if (
            strpos($uaLower, 'smart-tv') !== false ||
            strpos($uaLower, 'smarttv') !== false ||
            strpos($uaLower, 'googletv') !== false ||
            strpos($uaLower, 'appletv') !== false ||
            strpos($uaLower, 'roku') !== false ||
            strpos($uaLower, 'netcast') !== false ||
            strpos($uaLower, 'viera') !== false ||
            strpos($uaLower, 'opera tv') !== false ||
            strpos($uaLower, 'web0s') !== false
        ) {
            return 'tv';
        }

        // Tablets: iPad or Android without "mobile" keyword
        if (strpos($uaLower, 'ipad') !== false) {
            return 'tablet';
        }

        if (strpos($uaLower, 'android') !== false && strpos($uaLower, 'mobile') === false) {
            return 'tablet';
        }

        if (strpos($uaLower, 'kindle') !== false || strpos($uaLower, 'silk') !== false) {
            return 'tablet';
        }

        // Mobile devices
        $mobileKeywords = [
            'mobile', 'iphone', 'ipod', 'android',
            'blackberry', 'windows phone', 'opera mini',
            'iemobile', 'kaios', 'webos', 'symbian',
            'series60', 'bada', 'brew',
        ];

        foreach ($mobileKeywords as $keyword) {
            if (strpos($uaLower, $keyword) !== false) {
                return 'mobile';
            }
        }

        return 'desktop';
    }

    /**
     * Get the list of available browser patterns (for testing/display).
     *
     * @return array<string, string>
     */
    public static function getBrowserPatterns(): array
    {
        return self::BROWSER_PATTERNS;
    }

    /**
     * Get the list of available OS patterns (for testing/display).
     *
     * @return array<string, string>
     */
    public static function getOSPatterns(): array
    {
        return self::OS_PATTERNS;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Extract a version number following a pattern in the User-Agent.
     *
     * @param string $uaLower Lowercase User-Agent
     * @param string $pattern The pattern just matched (e.g., 'chrome/')
     * @return string Version number or empty string
     */
    private function extractVersion(string $uaLower, string $pattern): string
    {
        $pos = strpos($uaLower, $pattern);
        if ($pos === false) {
            return '';
        }

        $start = $pos + strlen($pattern);
        $version = substr($uaLower, $start);

        if (preg_match('/^([\d.]+)/', $version, $matches)) {
            return $matches[1];
        }

        return '';
    }
}