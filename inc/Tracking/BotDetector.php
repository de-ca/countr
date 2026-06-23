<?php
/**
 * BotDetector - Bot/crawler detection with 100+ patterns.
 *
 * Extracted from the monolithic Visitor class. Handles all bot detection
 * logic independently so it can be tested and reused separately.
 *
 * @package Countr\Tracking
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Tracking;

class BotDetector
{
    /**
     * Known bot/crawler patterns in User-Agent strings.
     */
    private const BOT_PATTERNS = [
        // === Search Engines ===
        'googlebot', 'google web preview', 'google-image', 'google-safety',
        'google-structured-data-testing-tool', 'google-read-aloud',
        'google-partner-monitoring', 'adsbot-google', 'apis-google',
        'mediapartners-google', 'feedfetcher-google',
        'bingbot', 'bingpreview', 'msnbot', 'msnbot-media',
        'yandexbot', 'yandeximages', 'yandexmobilebot', 'yandexaccessibilitybot',
        'yandexmetrika', 'yandexcatalog', 'yandexnews', 'yandexvideo',
        'baiduspider', 'baidubot',
        'duckduckbot', 'duckduckgo',
        'yahoo', 'slurp', 'yahoo-ad-monitoring', 'yahoo-mmcrawler',
        'ask jeeves', 'teoma',
        'ia_archiver', 'alexa',
        'naverbot', 'yeti',
        'seznambot',
        'sogou',
        'exalead',
        'mojeekbot',
        'qwantify',

        // === AI / LLM Crawlers ===
        'gptbot',
        'chatgpt-user',
        'ccbot',
        'anthropic',
        'claude-web',
        'cohere-ai',
        'perplexitybot',
        'youbot',
        'bard',
        'google-extended',
        'amazonbot',
        'applebot',
        'applebot-extended',
        'bytespider',
        'petalbot',
        'meta-externalagent',
        'meta-externalfetcher',
        'facebookbot',

        // === SEO & Marketing Tools ===
        'ahrefsbot', 'ahrefssiteaudit',
        'semrushbot', 'semrush',
        'mj12bot',
        'rogerbot',
        'dotbot',
        'screaming frog', 'screaming frog seo spider',
        'sitebulb',
        'deepcrawl',
        'oncrawl',
        'rytebot',
        'woorank',
        'sistrix',
        'botify',
        'lumar',
        'siteimprove',
        'botify-crawler',
        'contentking',

        // === Social Media ===
        'facebookexternalhit', 'facebookcatalog',
        'twitterbot', 'twitter',
        'linkedinbot', 'linkedin',
        'pinterestbot', 'pinterest',
        'whatsapp',
        'telegrambot', 'telegram',
        'slackbot', 'slack-linkexpanding',
        'discordbot', 'discord',
        'redditbot', 'reddit',
        'tumblr',
        'snapchat',
        'skypeuripreview',
        'viber',
        'line',
        'instagram',
        'tiktok',
        'wechat',
        'zalo',

        // === Monitoring & Uptime ===
        'pingdom', 'pingdombot',
        'uptime', 'uptimerobot', 'uptime-kuma',
        'monitoring', 'monitor',
        'check_http', 'check_https',
        'nagios', 'zabbix',
        'datadog', 'newrelic',
        'statuscake',
        'solarwinds',
        'alertbot',
        'betteruptime',
        'hetrix',
        'site24x7',
        'ohDear',

        // === Developer Tools & Testing ===
        'chrome-lighthouse', 'lighthouse',
        'pagespeed', 'page speed',
        'gtmetrix',
        'webpagetest',
        'headlesschrome',
        'puppeteer',
        'phantomjs', 'phantom',
        'selenium',
        'seleniumide',
        'cypress',
        'playwright',
        'postman',
        'insomnia',

        // === HTTP Libraries & Generic Bots ===
        'curl', 'curl/',
        'wget', 'wget/',
        'python-requests', 'python-urllib',
        'go-http-client', 'go-http',
        'java/', 'apache-httpclient', 'okhttp',
        'libwww', 'libwww-perl',
        'httpclient', 'http client',
        'node-fetch', 'node-',
        'axios', 'axios/',
        'cfnetwork',
        'guzzlehttp',
        'scrapy',
        'mechanize',
        'aiohttp',
        'httpx',
        'nutch',
        'heritrix',

        // === Content Scrapers & Aggregators ===
        'scraper', 'scraping',
        'extractor', 'crawler',
        'spider',
        'gigabot',
        'archive.org', 'wayback',
        'turnitinbot',
        'copyrss',
        'feedly',
        'feedbin',
        'newsblur',
        'flipboard',
        'outbrain',
        'taboola',
        'revue',

        // === Other / Miscellaneous ===
        'phpcrawl',
        'proximic',
        'coccocbot',
        'daum',
        '360spider',
        'haosouspider',
        'yisouspider',
        'smtbot',
        'brandwatch',
        'meltwater',
        'talkwalker',
        'criteo',
        'zoominfobot',
        'barkrowler',
        'blexbot',
        'magestic12',
    ];

    /**
     * Search engine patterns (subset of BOT_PATTERNS).
     */
    private const SEARCH_ENGINE_PATTERNS = [
        'googlebot', 'google',
        'bingbot', 'msnbot',
        'yandexbot', 'yandex',
        'baiduspider', 'baidu',
        'duckduckbot', 'duckduckgo',
        'slurp', 'yahoo',
        'teoma', 'ask jeeves',
        'naverbot', 'yeti',
        'seznambot',
        'sogou',
        'exalead',
        'mojeekbot',
        'qwantify',
        'ia_archiver',
        'ccbot',
    ];

    /**
     * Detect if the given User-Agent belongs to a bot/crawler.
     *
     * @param string      $userAgent Raw User-Agent string
     * @param array|null  $server    Optional $_SERVER-like array for header checks
     * @return array{isBot: bool, botName: string|null, isSearchEngine: bool}
     */
    public function detect(string $userAgent, ?array $server = null): array
    {
        $uaLower = strtolower($userAgent);

        // Check against all known bot patterns
        foreach (self::BOT_PATTERNS as $pattern) {
            if (strpos($uaLower, $pattern) !== false) {
                // Do not classify in-app browsers as bots
                if ($this->isInAppBrowser($uaLower, $pattern)) {
                    break;
                }
                return [
                    'isBot'          => true,
                    'botName'        => $this->identifyBotName($uaLower),
                    'isSearchEngine' => $this->detectSearchEngine($uaLower),
                ];
            }
        }

        // Empty or very short User-Agents are likely bots
        if (empty(trim($userAgent)) || strlen(trim($userAgent)) < 10) {
            return [
                'isBot'          => true,
                'botName'        => 'Unknown Bot (short/missing UA)',
                'isSearchEngine' => false,
            ];
        }

        // Check for bot-specific headers
        if ($server !== null) {
            if (
                !empty($server['HTTP_X_ROBOTS_TAG']) ||
                (!empty($server['HTTP_FROM']) && strpos(strtolower($server['HTTP_FROM']), 'crawler') !== false)
            ) {
                return [
                    'isBot'          => true,
                    'botName'        => 'Bot (via HTTP headers)',
                    'isSearchEngine' => false,
                ];
            }
        }

        return [
            'isBot'          => false,
            'botName'        => null,
            'isSearchEngine' => false,
        ];
    }

    /**
     * Check if a matched pattern is actually an in-app browser (human), not a crawler bot.
     *
     * @param string $uaLower        Lowercase User-Agent
     * @param string $matchedPattern The bot pattern that matched
     * @return bool True if this is an in-app browser from a human user
     */
    private function isInAppBrowser(string $uaLower, string $matchedPattern): bool
    {
        $hasWebKit = strpos($uaLower, 'applewebkit') !== false
                  || strpos($uaLower, 'safari') !== false
                  || strpos($uaLower, 'chrome') !== false
                  || strpos($uaLower, 'gecko') !== false;

        if (!$hasWebKit) {
            return false;
        }

        $inAppPatterns = ['instagram', 'tiktok', 'snapchat', 'tumblr', 'pinterest', 'line/'];
        if (in_array($matchedPattern, $inAppPatterns, true)) {
            return true;
        }

        if ($matchedPattern === 'whatsapp' && $hasWebKit) {
            return true;
        }

        return false;
    }

    /**
     * Identify the specific bot name from its User-Agent.
     *
     * @param string $uaLower Lowercase User-Agent
     * @return string Human-readable bot name
     */
    private function identifyBotName(string $uaLower): string
    {
        // Search Engines
        if (strpos($uaLower, 'googlebot') !== false)           return 'Googlebot';
        if (strpos($uaLower, 'adsbot-google') !== false)       return 'Google AdsBot';
        if (strpos($uaLower, 'google') !== false)              return 'Google Bot';
        if (strpos($uaLower, 'bingbot') !== false)             return 'Bingbot';
        if (strpos($uaLower, 'msnbot') !== false)              return 'MSN Bot';
        if (strpos($uaLower, 'yandex') !== false)              return 'Yandex Bot';
        if (strpos($uaLower, 'baidu') !== false)               return 'Baidu Spider';
        if (strpos($uaLower, 'duckduck') !== false)            return 'DuckDuckBot';
        if (strpos($uaLower, 'slurp') !== false)               return 'Yahoo Slurp';
        if (strpos($uaLower, 'yahoo') !== false)               return 'Yahoo Bot';
        if (strpos($uaLower, 'seznambot') !== false)           return 'Seznam Bot';
        if (strpos($uaLower, 'sogou') !== false)               return 'Sogou Spider';
        if (strpos($uaLower, 'yeti') !== false)                return 'Naver Yeti';
        if (strpos($uaLower, 'exalead') !== false)             return 'Exalead';
        if (strpos($uaLower, 'qwantify') !== false)            return 'Qwantify';
        if (strpos($uaLower, 'mojeekbot') !== false)           return 'MojeekBot';
        if (strpos($uaLower, 'ccbot') !== false)               return 'CommonCrawl';
        if (strpos($uaLower, 'ia_archiver') !== false)         return 'Internet Archive';

        // AI / LLM Crawlers
        if (strpos($uaLower, 'gptbot') !== false)              return 'OpenAI GPTBot';
        if (strpos($uaLower, 'chatgpt') !== false)             return 'ChatGPT User';
        if (strpos($uaLower, 'anthropic') !== false)           return 'Anthropic AI';
        if (strpos($uaLower, 'claude-web') !== false)          return 'Claude Web';
        if (strpos($uaLower, 'cohere') !== false)              return 'Cohere AI';
        if (strpos($uaLower, 'perplexitybot') !== false)       return 'Perplexity AI';
        if (strpos($uaLower, 'youbot') !== false)              return 'You.com Bot';
        if (strpos($uaLower, 'bard') !== false)                return 'Google Bard';
        if (strpos($uaLower, 'google-extended') !== false)     return 'Google Extended';
        if (strpos($uaLower, 'amazonbot') !== false)           return 'Amazon Bot';
        if (strpos($uaLower, 'applebot') !== false)            return 'Apple Bot';
        if (strpos($uaLower, 'bytespider') !== false)          return 'ByteDance Spider';
        if (strpos($uaLower, 'petalbot') !== false)            return 'Huawei PetalBot';
        if (strpos($uaLower, 'meta-external') !== false)       return 'Meta AI Crawler';

        // SEO Tools
        if (strpos($uaLower, 'ahrefs') !== false)              return 'Ahrefs Bot';
        if (strpos($uaLower, 'semrush') !== false)             return 'SEMrush Bot';
        if (strpos($uaLower, 'mj12bot') !== false)             return 'Majestic Bot';
        if (strpos($uaLower, 'rogerbot') !== false)            return 'Moz RogerBot';
        if (strpos($uaLower, 'dotbot') !== false)              return 'Moz DotBot';
        if (strpos($uaLower, 'screaming frog') !== false)      return 'Screaming Frog';
        if (strpos($uaLower, 'sitebulb') !== false)            return 'Sitebulb';
        if (strpos($uaLower, 'deepcrawl') !== false)           return 'DeepCrawl';
        if (strpos($uaLower, 'oncrawl') !== false)             return 'OnCrawl';
        if (strpos($uaLower, 'botify') !== false)              return 'Botify';
        if (strpos($uaLower, 'sistrix') !== false)             return 'Sistrix';

        // Social Media
        if (strpos($uaLower, 'facebook') !== false)            return 'Facebook Bot';
        if (strpos($uaLower, 'twitterbot') !== false)          return 'Twitter Bot';
        if (strpos($uaLower, 'linkedin') !== false)            return 'LinkedIn Bot';
        if (strpos($uaLower, 'pinterest') !== false)           return 'Pinterest Bot';
        if (strpos($uaLower, 'whatsapp') !== false)            return 'WhatsApp Bot';
        if (strpos($uaLower, 'telegram') !== false)            return 'Telegram Bot';
        if (strpos($uaLower, 'slack') !== false)               return 'Slack Bot';
        if (strpos($uaLower, 'discord') !== false)             return 'Discord Bot';
        if (strpos($uaLower, 'reddit') !== false)              return 'Reddit Bot';
        if (strpos($uaLower, 'tumblr') !== false)              return 'Tumblr Bot';
        if (strpos($uaLower, 'snapchat') !== false)            return 'Snapchat Bot';
        if (strpos($uaLower, 'tiktok') !== false)              return 'TikTok Bot';
        if (strpos($uaLower, 'instagram') !== false)           return 'Instagram Bot';

        // Monitoring
        if (strpos($uaLower, 'pingdom') !== false)             return 'Pingdom';
        if (strpos($uaLower, 'uptimerobot') !== false)         return 'UptimeRobot';
        if (strpos($uaLower, 'uptime') !== false)              return 'Uptime Monitor';
        if (strpos($uaLower, 'nagios') !== false)              return 'Nagios';
        if (strpos($uaLower, 'zabbix') !== false)              return 'Zabbix';
        if (strpos($uaLower, 'datadog') !== false)             return 'Datadog';
        if (strpos($uaLower, 'newrelic') !== false)            return 'New Relic';
        if (strpos($uaLower, 'statuscake') !== false)          return 'StatusCake';
        if (strpos($uaLower, 'monitor') !== false)             return 'Monitoring Service';

        // Developer Tools
        if (strpos($uaLower, 'lighthouse') !== false)          return 'Lighthouse';
        if (strpos($uaLower, 'pagespeed') !== false)           return 'PageSpeed Insights';
        if (strpos($uaLower, 'gtmetrix') !== false)            return 'GTmetrix';
        if (strpos($uaLower, 'headlesschrome') !== false)      return 'Headless Chrome';
        if (strpos($uaLower, 'phantomjs') !== false)           return 'PhantomJS';
        if (strpos($uaLower, 'selenium') !== false)            return 'Selenium';
        if (strpos($uaLower, 'puppeteer') !== false)           return 'Puppeteer';
        if (strpos($uaLower, 'playwright') !== false)          return 'Playwright';
        if (strpos($uaLower, 'cypress') !== false)             return 'Cypress';
        if (strpos($uaLower, 'postman') !== false)             return 'Postman';

        // HTTP Libraries
        if (strpos($uaLower, 'curl') !== false)                return 'cURL';
        if (strpos($uaLower, 'wget') !== false)                return 'Wget';
        if (strpos($uaLower, 'python-requests') !== false)     return 'Python Requests';
        if (strpos($uaLower, 'python-urllib') !== false)       return 'Python Urllib';
        if (strpos($uaLower, 'go-http') !== false)             return 'Go HTTP Client';
        if (strpos($uaLower, 'okhttp') !== false)              return 'OkHttp';
        if (strpos($uaLower, 'axios') !== false)               return 'Axios';
        if (strpos($uaLower, 'node-fetch') !== false)          return 'Node Fetch';
        if (strpos($uaLower, 'guzzle') !== false)              return 'Guzzle';
        if (strpos($uaLower, 'scrapy') !== false)              return 'Scrapy';

        // Generic fallbacks
        if (strpos($uaLower, 'spider') !== false)              return 'Web Spider';
        if (strpos($uaLower, 'crawler') !== false)             return 'Web Crawler';
        if (strpos($uaLower, 'scraper') !== false)             return 'Web Scraper';
        if (strpos($uaLower, 'bot') !== false)                 return 'Generic Bot';
        if (strpos($uaLower, 'scraping') !== false)            return 'Scraping Tool';

        return 'Unknown Bot';
    }

    /**
     * Check if the matched bot is a known search engine.
     *
     * @param string $uaLower Lowercase User-Agent
     * @return bool
     */
    private function detectSearchEngine(string $uaLower): bool
    {
        foreach (self::SEARCH_ENGINE_PATTERNS as $pattern) {
            if (strpos($uaLower, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}