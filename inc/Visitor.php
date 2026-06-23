<?php
/**
 * Visitor - Visitor Detection & Identification Engine
 *
 * Provides comprehensive visitor identification including:
 * - Unique visitor fingerprinting (IP + User-Agent + Language hashing)
 * - Browser/OS/Device parsing (delegates to Countr\Tracking\BrowserDetector)
 * - Bot detection (100+ patterns including AI/LLM crawlers; delegates to Countr\Tracking\BotDetector)
 * - GDPR-compliant IP anonymization (last octet removal)
 * - Session management with configurable timeout (delegates to Countr\Tracking\Session)
 * - Referrer analysis (organic, social, direct, email, paid classification; delegates to Countr\Tracking\ReferrerAnalyzer)
 * - Performance caching for repeated calls
 *
 * This class serves as the public-facing API and backward-compatibility layer.
 * Detection logic lives in the modular Countr\Tracking namespace.
 *
 * @package Countr
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */
 
declare(strict_types=1);

// Load modular dependencies (autoloader handles namespaced classes)
require_once __DIR__ . '/Tracking/BotDetector.php';
require_once __DIR__ . '/Tracking/Visitor/BrowserDetector.php';
require_once __DIR__ . '/Tracking/Visitor/ReferrerAnalyzer.php';
require_once __DIR__ . '/Tracking/Session.php';

class Visitor
{
    /** @var string Unique visitor fingerprint (SHA-256 of anonymized IP + UA + Language) */
    private string $visitorHash;
    
    /** @var string Session ID */
    private string $sessionId;
    
    /** @var string Raw IP address */
    private string $ipAddress;
    
    /** @var string Full User-Agent string */
    private string $userAgent;
    
    /** @var string Accept-Language header */
    private string $acceptLanguage;
    
    /** @var array Parsed browser/OS/device info (cached) */
    private array $parsedInfo;
    
    /** @var bool Whether this visitor is a bot */
    private bool $isBotFlag = false;
    
    /** @var string|null Bot name if detected */
    private ?string $botName = null;
    
    /** @var bool Whether the bot is a search engine */
    private bool $isSearchEngineFlag = false;
    
    /** @var bool Whether to anonymize IP addresses */
    private bool $anonymizeIp = true;
    
    /** @var bool Whether to store the full IP */
    private bool $storeIp = false;
    
    /** @var int Session timeout in seconds (default 30 minutes) */
    private int $sessionTimeout = 1800;
    
    /** @var string Hash algorithm for visitor ID */
    private string $hashAlgorithm = 'sha256';
    
    /** @var string|null Secret salt for visitor ID hashing */
    private ?string $salt = null;
    
    /** @var string|null Cached anonymized IP */
    private ?string $cachedAnonymizedIp = null;
    
    /** @var bool Whether the visitor ID has been generated */
    private bool $hashGenerated = false;
    
    /** @var array|null Server array (injected for testability) */
    private ?array $server;
    
    /** @var \Countr\Tracking\BotDetector|null Lazy-loaded bot detector */
    private ?\Countr\Tracking\BotDetector $botDetector = null;
    
    /** @var \Countr\Tracking\BrowserDetector|null Lazy-loaded browser/OS detector */
    private ?\Countr\Tracking\BrowserDetector $browserDetector = null;
    
    /** @var \Countr\Tracking\ReferrerAnalyzer|null Lazy-loaded referrer analyzer */
    private ?\Countr\Tracking\ReferrerAnalyzer $referrerAnalyzer = null;
    
    /** @var \Countr\Tracking\Session|null Lazy-loaded session manager */
    private ?\Countr\Tracking\Session $sessionManager = null;

    // =========================================================================
    // BOT PATTERNS (inline, for backward-compatible detectBot method)
    // =========================================================================

    /**
     * Known bot/crawler patterns in User-Agent strings.
     */
    private const BOT_PATTERNS = [
        'googlebot', 'google web preview', 'google-image', 'google-safety',
        'bingbot', 'bingpreview', 'msnbot', 'msnbot-media',
        'yandexbot', 'yandeximages', 'yandexmobilebot',
        'baiduspider', 'baidubot',
        'duckduckbot', 'duckduckgo',
        'slurp', 'yahoo',
        'gptbot', 'chatgpt-user', 'ccbot',
        'anthropic', 'claude-web', 'cohere-ai',
        'perplexitybot', 'youbot', 'bard', 'google-extended',
        'amazonbot', 'applebot', 'bytespider', 'petalbot',
        'ahrefsbot', 'semrushbot', 'mj12bot', 'rogerbot', 'dotbot',
        'screaming frog', 'sitebulb', 'deepcrawl', 'oncrawl',
        'facebookexternalhit', 'facebookcatalog',
        'twitterbot', 'twitter',
        'linkedinbot', 'linkedin',
        'pinterestbot', 'pinterest',
        'whatsapp', 'telegrambot', 'telegram',
        'slackbot', 'discordbot', 'discord',
        'redditbot', 'reddit', 'tumblr',
        'snapchat', 'tiktok', 'instagram',
        'pingdom', 'uptimerobot', 'uptime',
        'nagios', 'zabbix', 'datadog', 'newrelic',
        'lighthouse', 'pagespeed', 'gtmetrix',
        'headlesschrome', 'puppeteer', 'phantomjs', 'phantom',
        'selenium', 'cypress', 'playwright', 'postman',
        'curl', 'wget', 'python-requests', 'python-urllib',
        'go-http', 'okhttp', 'libwww', 'httpclient',
        'node-fetch', 'axios', 'guzzlehttp', 'scrapy',
        'scraper', 'scraping', 'extractor', 'crawler', 'spider',
        'bot',
    ];

    private const SEARCH_ENGINE_PATTERNS = [
        'googlebot', 'google',
        'bingbot', 'msnbot',
        'yandexbot', 'yandex',
        'baiduspider', 'baidu',
        'duckduckbot', 'duckduckgo',
        'slurp', 'yahoo',
        'teoma', 'ask jeeves',
        'ccbot', 'ia_archiver',
    ];

    // =========================================================================
    // BROWSER/OS PATTERNS (inline, for backward-compatible parseUserAgent)
    // =========================================================================

    private const BROWSER_PATTERNS = [
        'edg/' => 'Edge', 'chrome/' => 'Chrome', 'firefox/' => 'Firefox',
        'safari/' => 'Safari', 'opr/' => 'Opera', 'msie ' => 'Internet Explorer',
        'trident/' => 'Internet Explorer',
    ];

    private const OS_PATTERNS = [
        'windows nt 10.0' => 'Windows 10/11',
        'windows nt 6.1' => 'Windows 7',
        'windows' => 'Windows',
        'iphone' => 'iOS', 'ipad' => 'iPadOS',
        'android' => 'Android',
        'mac os x' => 'macOS',
        'linux' => 'Linux',
    ];

    // =========================================================================
    // MODULAR DEPENDENCY ACCESSORS
    // =========================================================================
    
    /**
     * Get (or create) the BotDetector instance.
     *
     * @return \Countr\Tracking\BotDetector
     */
    private function getBotDetector(): \Countr\Tracking\BotDetector
    {
        if ($this->botDetector === null) {
            $this->botDetector = new \Countr\Tracking\BotDetector();
        }
        return $this->botDetector;
    }

    /**
     * Get (or create) the BrowserDetector instance.
     *
     * @return \Countr\Tracking\BrowserDetector
     */
    private function getBrowserDetector(): \Countr\Tracking\BrowserDetector
    {
        if ($this->browserDetector === null) {
            $this->browserDetector = new \Countr\Tracking\BrowserDetector();
        }
        return $this->browserDetector;
    }

    /**
     * Get (or create) the ReferrerAnalyzer instance.
     *
     * @return \Countr\Tracking\ReferrerAnalyzer
     */
    private function getReferrerAnalyzer(): \Countr\Tracking\ReferrerAnalyzer
    {
        if ($this->referrerAnalyzer === null) {
            $this->referrerAnalyzer = new \Countr\Tracking\ReferrerAnalyzer();
        }
        return $this->referrerAnalyzer;
    }

    /**
     * Get (or create) the Session manager instance.
     *
     * @return \Countr\Tracking\Session
     */
    private function getSessionManager(): \Countr\Tracking\Session
    {
        if ($this->sessionManager === null) {
            $this->sessionManager = new \Countr\Tracking\Session($this->sessionTimeout);
        }
        return $this->sessionManager;
    }
    
    // =========================================================================
    // CONSTRUCTOR & INITIALIZATION
    // =========================================================================
    
    /**
     * Constructor – analyzes the current visitor from server variables.
     *
     * @param array|null $server  $_SERVER array (injected for testability)
     * @param string|null $salt   Optional secret salt for ID hashing
     */
    public function __construct(?array $server = null, ?string $salt = null)
    {
        $this->server = $server ?? $_SERVER;
        
        $this->salt = $salt;
        $this->ipAddress = $this->extractIpAddress($this->server);
        $this->userAgent = $this->server['HTTP_USER_AGENT'] ?? 'Unknown';
        $this->acceptLanguage = $this->server['HTTP_ACCEPT_LANGUAGE'] ?? '';
        
        // GDPR: Die rohe IP wird SOFORT bei der Instanziierung anonymisiert und
        // als $cachedAnonymizedIp abgelegt (IPv4: letztes Oktett genullt, IPv6: /48-Präfix).
        // Dies geschieht IMMER – unabhängig vom Schalter privacy.anonymize_ip –,
        // sodass die rohe IP zu keinem Zeitpunkt für Hashing oder Persistenz
        // verwendet werden kann. Der Schalter privacy.anonymize_ip steuert NUR,
        // ob getIpAddress() (auf expliziten Abruf) die rohe IP zurückgibt.
        $this->cachedAnonymizedIp = $this->anonymizeIp($this->ipAddress);
        
        // Detect bot (uses inline patterns for BC; modular BotDetector also available via getBotDetector())
        $this->detectBot();
        
        // Parse browser/OS/device using modular BrowserDetector
        $this->parsedInfo = $this->getBrowserDetector()->parse($this->userAgent, $this->isBotFlag);
        
        // Generate visitor hash
        $this->visitorHash = $this->generateVisitorHash();
        $this->hashGenerated = true;
        
        // Get or create session ID using modular Session manager
        $this->sessionId = $this->getSessionManager()->getOrCreateId();
    }    

    // =========================================================================
    // CORE IDENTIFICATION METHODS
    // =========================================================================

    /**
     * Get the unique visitor hash (fingerprint).
     * Alias for getVisitorHash().
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->visitorHash;
    }

    /**
     * Get the unique visitor hash (fingerprint).
     *
     * @return string
     */
    public function getVisitorId(): string
    {
        return $this->visitorHash;
    }

    /**
     * Get the current session ID.
     *
     * @return string
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Check if this is the first visit from this visitor (not known in DB).
     * Requires database lookup – returns null if not determinable.
     *
     * @param Database|null $db Optional Database instance for lookup
     * @return bool|null True if new, false if returning, null if unknown
     */
    public function isNewVisitor(?Database $db = null): ?bool
    {
        if ($db === null) {
            return null;
        }
        return !$db->exists('visitors', ['visitor_hash' => $this->visitorHash]);
    }

    /**
     * Check if this is a returning visitor.
     * Requires database lookup – returns null if not determinable.
     *
     * @param Database|null $db Optional Database instance for lookup
     * @return bool|null True if returning, false if new, null if unknown
     */
    public function isReturningVisitor(?Database $db = null): ?bool
    {
        $isNew = $this->isNewVisitor($db);
        if ($isNew === null) {
            return null;
        }
        return !$isNew;
    }

    // =========================================================================
    // GDPR & PRIVACY METHODS
    // =========================================================================

    /**
     * Get the anonymized IP address (last octet removed).
     *
     * @return string
     */
    public function getAnonymizedIp(): string
    {
        return $this->cachedAnonymizedIp;
    }

    /**
     * Get a hash of the (anonymized) IP address for privacy-safe storage.
     *
     * @return string
     */
    public function getIpHash(): string
    {
        return hash('sha256', $this->cachedAnonymizedIp);
    }

    /**
     * Get the full IP address.
     *
     * @param bool $anonymized  Whether to return the anonymized version
     * @return string
     */
    public function getIpAddress(bool $anonymized = false): string
    {
        if ($anonymized || $this->anonymizeIp) {
            return $this->cachedAnonymizedIp;
        }

        if ($this->storeIp) {
            return $this->ipAddress;
        }

        return $this->cachedAnonymizedIp;
    }

    /**
     * Get the raw User-Agent string.
     *
     * @return string
     */
    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    // =========================================================================
    // BROWSER/OS/DEVICE DETECTION
    // =========================================================================

    /**
     * Get the detected browser name.
     *
     * @return string
     */
    public function getBrowser(): string
    {
        return $this->parsedInfo['browser'] ?? 'Unknown';
    }

    /**
     * Get the browser version.
     *
     * @return string
     */
    public function getBrowserVersion(): string
    {
        return $this->parsedInfo['browser_version'] ?? '';
    }

    /**
     * Get the detected operating system.
     *
     * @return string
     */
    public function getOperatingSystem(): string
    {
        return $this->parsedInfo['os'] ?? 'Unknown';
    }

    /**
     * Alias for getOperatingSystem().
     *
     * @return string
     */
    public function getOS(): string
    {
        return $this->getOperatingSystem();
    }

    /**
     * Get the device type: 'desktop', 'mobile', 'tablet', 'tv', 'console', 'bot', or 'unknown'.
     *
     * @return string
     */
    public function getDeviceType(): string
    {
        return $this->parsedInfo['device_type'] ?? 'unknown';
    }

    /**
     * Get the detected screen size from JavaScript (or empty if not available).
     * Note: This requires client-side JS to populate a cookie.
     *
     * @return string
     */
    public function getScreenSize(): string
    {
        return $_COOKIE['_wbc_ss'] ?? '';
    }

    /**
     * Get the visitor's preferred language from Accept-Language header.
     *
     * @return string Primary language code (e.g., 'de', 'en', 'fr')
     */
    public function getLanguage(): string
    {
        if (empty($this->acceptLanguage)) {
            return 'unknown';
        }

        // Accept-Language: de-DE,de;q=0.9,en;q=0.8
        $parts = explode(',', $this->acceptLanguage);
        $primary = trim($parts[0]);

        // Extract language code (e.g., 'de' from 'de-DE')
        if (preg_match('/^([a-zA-Z]{2,3})/', $primary, $matches)) {
            return strtolower($matches[1]);
        }

        return 'unknown';
    }

    /**
     * Get the visitor's country code (2-letter ISO 3166-1 alpha-2) based on
     * the Accept-Language header, without any IP lookups.
     *
     * Parses the primary locale from HTTP_ACCEPT_LANGUAGE (e.g., "de-DE" → "DE",
     * "en-GB" → "GB", "fr-CH" → "CH"). If no region subtag is present, applies
     * a language-to-country mapping for common languages (e.g., "de" → "DE",
     * "fr" → "FR", "it" → "IT").
     *
     * Returns empty string if undetermined — never uses the IP address.
     *
     * @return string 2-letter ISO country code (e.g., 'DE', 'AT', 'US') or empty
     */
    public function getCountryCode(): string
    {
        if (empty($this->acceptLanguage)) {
            return '';
        }

        // Parse the primary locale tag from Accept-Language
        // Format: de-DE,de;q=0.9,en;q=0.8  → primary = de-DE
        $parts = explode(',', $this->acceptLanguage);
        $primary = trim($parts[0]);

        // Try to match a region subtag: xx-YY or xx_YY
        if (preg_match('/^[a-zA-Z]{2,3}[_-]([a-zA-Z]{2})$/', $primary, $matches)) {
            $region = strtoupper($matches[1]);
            // Validate it looks like an ISO 3166-1 alpha-2 code
            if (preg_match('/^[A-Z]{2}$/', $region)) {
                return $region;
            }
        }

        // Fallback: map language code to most likely country
        $langToCountry = [
            'de' => 'DE',
            'fr' => 'FR',
            'it' => 'IT',
            'es' => 'ES',
            'pt' => 'PT',
            'nl' => 'NL',
            'pl' => 'PL',
            'cs' => 'CZ',
            'sk' => 'SK',
            'hu' => 'HU',
            'ro' => 'RO',
            'bg' => 'BG',
            'hr' => 'HR',
            'sr' => 'RS',
            'sl' => 'SI',
            'et' => 'EE',
            'lv' => 'LV',
            'lt' => 'LT',
            'fi' => 'FI',
            'sv' => 'SE',
            'no' => 'NO',
            'da' => 'DK',
            'is' => 'IS',
            'el' => 'GR',
            'tr' => 'TR',
            'ru' => 'RU',
            'uk' => 'UA',
            'ar' => 'SA',
            'he' => 'IL',
            'ja' => 'JP',
            'ko' => 'KR',
            'zh' => 'CN',
            'th' => 'TH',
            'vi' => 'VN',
            'id' => 'ID',
            'ms' => 'MY',
            'hi' => 'IN',
            'bn' => 'BD',
            'ur' => 'PK',
            'fa' => 'IR',
            'en' => '', // English is too ambiguous (US/GB/AU/CA/...)
        ];

        $language = $this->getLanguage();
        return $langToCountry[$language] ?? '';
    }

    /**
     * Check if this is a mobile device.
     *
     * @return bool
     */
    public function isMobile(): bool
    {
        return $this->getDeviceType() === 'mobile';
    }

    /**
     * Check if this is a tablet device.
     *
     * @return bool
     */
    public function isTablet(): bool
    {
        return $this->getDeviceType() === 'tablet';
    }

    /**
     * Check if this is a desktop device.
     *
     * @return bool
     */
    public function isDesktop(): bool
    {
        return $this->getDeviceType() === 'desktop';
    }

    // =========================================================================
    // BOT & SPAM PROTECTION
    // =========================================================================

    /**
     * Check if this visitor is a bot.
     *
     * @return bool
     */
    public function isBot(): bool
    {
        return $this->isBotFlag;
    }

    /**
     * Check if this visitor is a known search engine crawler.
     *
     * @return bool
     */
    public function isSearchEngine(): bool
    {
        return $this->isSearchEngineFlag;
    }

    /**
     * Get the bot name if this is a bot, null otherwise.
     *
     * @return string|null
     */
    public function getBotName(): ?string
    {
        return $this->botName;
    }

    // =========================================================================
    // REFERRER ANALYSIS
    // =========================================================================

    /**
     * Get the raw referrer URL from the request.
     *
     * @return string
     */
    public function getReferrer(): string
    {
        return $this->server['HTTP_REFERER'] ?? '';
    }

    /**
     * Get the referrer domain (host) without www prefix.
     *
     * @return string 'direct' if no referrer, domain name otherwise
     */
    public function getReferrerDomain(): string
    {
        $referrer = $this->getReferrer();

        if (empty($referrer)) {
            return 'direct';
        }

        $host = parse_url($referrer, PHP_URL_HOST);
        if (!$host) {
            return 'unknown';
        }

        return preg_replace('/^www\./', '', $host);
    }

    /**
     * Get the referrer traffic type.
     *
     * @return string 'direct', 'organic', 'social', 'email', 'paid', 'referral', or 'unknown'
     */
    public function getReferrerType(): string
    {
        $referrer = $this->getReferrer();

        if (empty($referrer)) {
            return 'direct';
        }

        $domain = $this->getReferrerDomain();

        // Email (check BEFORE organic — mail.google.com should not be "organic")
        $query = parse_url($referrer, PHP_URL_QUERY) ?? '';
        if (
            strpos($query, 'utm_medium=email') !== false ||
            strpos($domain, 'mail.') !== false ||
            strpos($domain, 'webmail.') !== false ||
            strpos($domain, 'outlook.') !== false ||
            strpos($domain, 'mail.google.') !== false
        ) {
            return 'email';
        }

        // Paid (UTM parameters — check BEFORE organic)
        if (
            strpos($query, 'utm_medium=cpc') !== false ||
            strpos($query, 'utm_medium=ppc') !== false ||
            strpos($query, 'utm_medium=paid') !== false ||
            strpos($query, 'gclid=') !== false ||
            strpos($query, 'fbclid=') !== false
        ) {
            return 'paid';
        }

        // Organic search
        $searchEngines = [
            'google.', 'bing.', 'yahoo.', 'yandex.', 'baidu.',
            'duckduckgo.', 'ecosia.', 'qwant.', 'ask.',
            'seznam.', 'naver.', 'sogou.', 'mojeek.',
        ];
        foreach ($searchEngines as $se) {
            if (strpos($domain, $se) !== false) {
                return 'organic';
            }
        }

        // Social media
        $socialDomains = [
            'facebook.', 'fb.', 'instagram.', 'twitter.', 'x.com',
            'linkedin.', 'pinterest.', 'reddit.', 'tumblr.',
            'snapchat.', 'tiktok.', 'youtube.', 'whatsapp.',
            'telegram.', 'discord.', 'signal.', 'mastodon.',
            'bsky.', 'threads.',
        ];
        foreach ($socialDomains as $sd) {
            if (strpos($domain, $sd) !== false) {
                return 'social';
            }
        }

        // Fallback: paid (gclid/fbclid in query)
        if (
            strpos($query, 'utm_medium=cpc') !== false ||
            strpos($query, 'utm_medium=ppc') !== false ||
            strpos($query, 'utm_medium=paid') !== false ||
            strpos($query, 'gclid=') !== false ||
            strpos($query, 'fbclid=') !== false
        ) {
            return 'paid';
        }

        return 'referral';
    }

    // =========================================================================
    // PERFORMANCE & CACHING
    // =========================================================================

    /**
     * Clear any internally cached values (forces re-parsing on next access).
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->parsedInfo = [];
        $this->cachedAnonymizedIp = null;
        $this->hashGenerated = false;
    }

    // =========================================================================
    // SERIALIZATION METHODS
    // =========================================================================

    /**
     * Get all parsed visitor information as an associative array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'visitor_hash' => $this->visitorHash,
            'session_id' => $this->sessionId,
            'ip_hash' => $this->getIpHash(),
            'ip_anonymized' => $this->getAnonymizedIp(),
            'user_agent' => $this->userAgent,
            'browser' => $this->getBrowser(),
            'browser_version' => $this->getBrowserVersion(),
            'os' => $this->getOperatingSystem(),
            'device_type' => $this->getDeviceType(),
            'screen_size' => $this->getScreenSize(),
            'language' => $this->getLanguage(),
            'is_bot' => $this->isBotFlag,
            'bot_name' => $this->botName,
            'is_search_engine' => $this->isSearchEngineFlag,
            'is_mobile' => $this->isMobile(),
            'is_tablet' => $this->isTablet(),
            'is_desktop' => $this->isDesktop(),
            'referrer' => $this->getReferrer(),
            'referrer_domain' => $this->getReferrerDomain(),
            'referrer_type' => $this->getReferrerType(),
        ];
    }

    /**
     * Create a compact visitor snapshot for JSON/daily summaries.
     *
     * @return array
     */
    public function toSnapshot(): array
    {
        return [
            'id' => $this->visitorHash,
            'browser' => $this->getBrowser(),
            'os' => $this->getOperatingSystem(),
            'device' => $this->getDeviceType(),
            'is_bot' => $this->isBotFlag,
        ];
    }

    // =========================================================================
    // CONFIGURATION METHODS
    // =========================================================================

    /**
     * Set whether to anonymize IP addresses.
     *
     * @param bool $anonymize
     * @return self
     */
    public function setAnonymizeIp(bool $anonymize): self
    {
        $this->anonymizeIp = $anonymize;
        return $this;
    }

    /**
     * Set whether to store the full IP (overrides anonymization for storage).
     *
     * @param bool $store
     * @return self
     */
    public function setStoreIp(bool $store): self
    {
        $this->storeIp = $store;
        return $this;
    }

    /**
     * Set the session timeout in seconds.
     *
     * @param int $seconds
     * @return self
     */
    public function setSessionTimeout(int $seconds): self
    {
        $this->sessionTimeout = max(60, $seconds);
        return $this;
    }

    /**
     * Static factory: Create a Visitor from the current request with optional config.
     *
     * @param array|null $config Optional config array with keys: anonymize_ip, store_ip, session_timeout, salt
     * @return self
     */
    public static function fromCurrentRequest(?array $config = null): self
    {
        $salt = $config['salt'] ?? null;
        $visitor = new self(null, $salt);

        if ($config) {
            if (isset($config['anonymize_ip'])) {
                $visitor->setAnonymizeIp((bool)$config['anonymize_ip']);
            }
            if (isset($config['store_ip'])) {
                $visitor->setStoreIp((bool)$config['store_ip']);
            }
            if (isset($config['session_timeout'])) {
                $visitor->setSessionTimeout((int)$config['session_timeout']);
            }
        }

        return $visitor;
    }

    // =========================================================================
    // PRIVATE: IP EXTRACTION & ANONYMIZATION
    // =========================================================================

    /**
     * Extract the real IP address considering reverse proxies and load balancers.
     *
     * Checks multiple headers in order of trustworthiness:
     * 1. HTTP_CLIENT_IP (set by some proxies)
     * 2. HTTP_X_FORWARDED_FOR (standard proxy header)
     * 3. HTTP_X_FORWARDED (older variant)
     * 4. HTTP_X_CLUSTER_CLIENT_IP (AWS ALB etc.)
     * 5. HTTP_FORWARDED_FOR (RFC 7239 variant)
     * 6. HTTP_FORWARDED (RFC 7239)
     * 7. REMOTE_ADDR (fallback – most reliable directly)
     *
     * @param array $server
     * @return string
     */
    private function extractIpAddress(array $server): string
    {
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($server[$header])) {
                // Handle comma-separated IPs (X-Forwarded-For chain)
                $ips = explode(',', $server[$header]);
                $ip = trim($ips[0]);

                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    // If there are multiple IPs and the first is private,
                    // take the first non-private IP
                    if (count($ips) > 1 && $this->isPrivateIP($ip)) {
                        foreach ($ips as $candidate) {
                            $candidate = trim($candidate);
                            if (
                                filter_var($candidate, FILTER_VALIDATE_IP) &&
                                !$this->isPrivateIP($candidate)
                            ) {
                                return $candidate;
                            }
                        }
                    }
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }

    /**
     * Check if an IP address is in a private/reserved range.
     *
     * @param string $ip
     * @return bool True if private/reserved
     */
    private function isPrivateIP(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * Anonymize an IP address for GDPR compliance.
     *
     * IPv4: Replaces the last octet with 0 (e.g., 192.168.1.42 → 192.168.1.0)
     * IPv6: Zeros out the last 80 bits (keeping a /48 prefix)
     *
     * @param string $ip
     * @return string
     */
    public function anonymizeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // For IPv6: find the third colon group to keep /48 prefix
            // Example: 2001:db8:85a3::8a2e:370:7334 → 2001:db8::
            $parts = explode(':', $ip);
            if (count($parts) >= 3) {
                return $parts[0] . ':' . $parts[1] . '::';
            }
            return substr($ip, 0, 10) . '::';
        }

        return '0.0.0.0';
    }

    // =========================================================================
    // PRIVATE: VISITOR HASH GENERATION
    // =========================================================================

    /**
     * Generate a unique visitor fingerprint.
     * Uses: anonymized IP + User-Agent + Accept-Language (+ optional salt)
     *
     * @return string
     */
    private function generateVisitorHash(): string
    {
        $raw = $this->cachedAnonymizedIp . '|' . $this->userAgent . '|' . $this->acceptLanguage;

        if ($this->salt !== null) {
            $raw .= '|' . $this->salt;
        }

        return hash($this->hashAlgorithm, $raw);
    }

    // =========================================================================
    // PRIVATE: SESSION MANAGEMENT
    // =========================================================================

    /**
     * Get or create a session ID for this visitor.
     *
     * Priority:
     * 1. Active PHP session (hashed for security)
     * 2. Cookie-based session ID (_wbc_sid)
     * 3. Generate new crypto-random session ID
     *
     * @return string 32-character hex session ID
     */
    private function getOrCreateSessionId(): string
    {
        // Use PHP session if active
        if (session_status() === PHP_SESSION_ACTIVE && !empty(session_id())) {
            return hash('sha256', session_id());
        }

        // Use a cookie-based session ID
        $cookieName = '_wbc_sid';

        if (isset($_COOKIE[$cookieName]) && strlen($_COOKIE[$cookieName]) === 32) {
            return $_COOKIE[$cookieName];
        }

        // Generate a new crypto-random session ID
        $newId = bin2hex(random_bytes(16)); // 32 hex chars

        // Set cookie if headers not already sent
        if (!headers_sent()) {
            setcookie(
                $cookieName,
                $newId,
                [
                    'expires'  => time() + $this->sessionTimeout,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
        }

        return $newId;
    }

    // =========================================================================
    // PRIVATE: BOT DETECTION
    // =========================================================================

    /**
     * Detect if this visitor is a bot/crawler.
     *
     * Delegates to the modular BotDetector (185+ patterns) for primary detection,
     * with a fallback inline check for edge cases.
     */
    private function detectBot(): void
    {
        // Use the comprehensive modular BotDetector (185+ patterns)
        $detection = $this->getBotDetector()->detect($this->userAgent, $this->server);

        if ($detection['isBot']) {
            $this->isBotFlag = true;
            $this->botName = $detection['botName'];
            $this->isSearchEngineFlag = $detection['isSearchEngine'];
            return;
        }

        $this->isBotFlag = false;
        $this->botName = null;
        $this->isSearchEngineFlag = false;
    }

    // =========================================================================
    // PRIVATE: USER-AGENT PARSING (kept for backward compatibility)
    // =========================================================================

    /**
     * Parse the User-Agent string to extract browser, OS, version, and device type.
     * Results are cached for the lifetime of the Visitor instance.
     *
     * @return array
     */
    private function parseUserAgent(): array
    {
        $ua = $this->userAgent;
        $uaLower = strtolower($ua);

        $result = [
            'browser'         => 'Unknown',
            'browser_version'  => '',
            'os'              => 'Unknown',
            'device_type'     => $this->isBotFlag ? 'bot' : 'desktop',
        ];

        // If bot, skip detailed browser/OS parsing
        if ($this->isBotFlag) {
            // Still try to identify bot OS for completeness
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
     * Detect the device type from User-Agent string.
     *
     * @param string $uaLower Lowercase User-Agent
     * @return string 'desktop', 'mobile', 'tablet', 'tv', 'console'
     */
    private function detectDeviceType(string $uaLower): string
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