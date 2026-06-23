<?php
/**
 * Tracker - Core Tracking Engine with Batch Processing
 *
 * Provides high-performance page tracking with:
 * - In-memory buffering with configurable batch size (default 10 hits)
 * - Session management (start/end/bounce detection)
 * - Rate limiting to prevent abuse
 * - Event tracking for custom events (clicks, downloads, etc.)
 * - Real-time online visitor counting
 * - Database integration via SQLite DatabaseFacade with transaction safety
 * - Automatic data maintenance (cleanup, optimization, anonymization)
 * - GDPR-compliant data handling
 *
 * Refactored (v1.3.0): Core logic extracted into granular Traits:
 *   - SessionManagementTrait  (session lifecycle, bounce detection, visitor DB)
 *   - StatisticsTrait         (today summary, charts, distributions, getStoredData)
 *   - RateLimitingTrait       (rate limits, cleanup, optimization, anonymization)
 *
 * v1.6.0: Removed FileDB backward-compatibility. SQLite is the sole storage engine.
 *
 * @package Countr
 * @copyright  2026 Countr Analytics
 * @version 1.6.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/Traits/SessionManagementTrait.php';
require_once __DIR__ . '/Traits/StatisticsTrait.php';
require_once __DIR__ . '/Traits/RateLimitingTrait.php';

class Tracker
{
    use SessionManagementTrait;
    use StatisticsTrait;
    use RateLimitingTrait;

    /** @var object Database handler (SQLite via Database or DatabaseFacade) */
    protected $db;
    
    /** @var Visitor Current visitor instance */
    protected Visitor $visitor;
    
    /** @var array Configuration array */
    protected array $config;

    /** @var string Timezone identifier */
    protected string $timezone;

    /** @var string Today's date (YYYY-MM-DD) */
    protected string $today;

    /** @var string Current timestamp for SQLite */
    protected string $now;

    /** @var int Session timeout for online detection (seconds) */
    protected int $sessionTimeout;

    /** @var int Buffer: how many hits to buffer before auto-flush */
    protected int $bufferSize;

    /** @var array<array> In-memory hit buffer */
    protected array $buffer = [];

    /** @var bool Whether to ignore bot traffic */
    protected bool $ignoreBots;

    /** @var bool Whether a batch operation is in progress */
    protected bool $batchActive = false;

    /** @var int Rate limit: max requests per window */
    protected int $rateLimit = 10;

    /** @var int Rate limit window in seconds */
    protected int $rateLimitWindow = 60;

    /** @var bool Whether rate limiting is enabled */
    protected bool $rateLimitEnabled = true;

    /** @var int|null Current visitor's database ID (lazy loaded) */
    protected ?int $visitorDbId = null;

    /** @var bool Whether the visitor DB row is ensured */
    protected bool $visitorEnsured = false;

    /** @var array Statistics cache for getTodayStats() */
    protected ?array $todayStatsCache = null;

    /** @var float|null Timestamp of last cache refresh */
    protected ?float $lastCacheRefresh = null;

    /** @var float Cache TTL for today stats (seconds) */
    protected float $cacheTTL = 10.0;

    // =========================================================================
    // CONSTRUCTOR
    // =========================================================================

    /**
     * Constructor. Accepts a Database or DatabaseFacade instance.
     *
     * @param object|null  $db      Database/DatabaseFacade instance (auto-creates if null)
     * @param Visitor|null $visitor Visitor instance (auto-creates from current request if null)
     * @param array        $config  Configuration array
     */
    public function __construct($db = null, ?Visitor $visitor = null, array $config = [])
    {
        $this->db = $db ?? Database::getInstance();
        
        // Ensure DB is connected
        if ($this->db instanceof Database && !$this->db->isConnected()) {
            $this->db->connect();
        }
        if ($this->db instanceof \Countr\Core\Database\DatabaseFacade && !$this->db->isConnected()) {
            $this->db->connect();
        }
        
        $this->visitor = $visitor ?? new Visitor();
        $this->config = $config;

        // Configure timezone
        $this->timezone = $config['tracking']['timezone'] ?? 'Europe/Berlin';
        date_default_timezone_set($this->timezone);

        $now = new DateTime('now', new DateTimeZone($this->timezone));
        $this->today = $now->format('Y-m-d');
        $this->now = $now->format('Y-m-d H:i:s');

        // Tracking settings (nested dotted-key format only — unified by getSettingsAsNestedArray())
        $this->sessionTimeout = (int)($config['tracking']['session_timeout'] ?? 1800);
        $this->bufferSize     = (int)($config['tracking']['buffer_size'] ?? 10);
        $this->ignoreBots     = (bool)($config['tracking']['ignore_bots'] ?? true);

        // Rate limit settings
        $this->rateLimit        = (int)($config['tracking']['rate_limit'] ?? 10);
        $this->rateLimitWindow  = (int)($config['tracking']['rate_limit_window'] ?? 60);
        $this->rateLimitEnabled = (bool)($config['tracking']['rate_limit_enabled'] ?? true);
    }

    // =========================================================================
    // CORE TRACKING METHODS
    // =========================================================================

    /**
     * Track a page hit.
     * Main entry point for page view tracking.
     *
     * @param string|null $page    Page URL (null = auto-detect from REQUEST_URI)
     * @param array       $options Additional tracking options:
     *                             - 'referrer'    (string) Override referrer
     *                             - 'page_title'  (string) Page title
     *                             - 'load_time'   (int)    Page load time in ms
     *                             - 'scroll_depth'(int)    Scroll depth 0-100
     *                             - 'language'    (string) Override language
     *                             - 'screen_size' (string) Override screen size
     * @return array Tracking result with status and stats
     */
    public function track(?string $page = null, array $options = []): array
    {
        // Rate limiting check
        if ($this->rateLimitEnabled && $this->isRateLimited()) {
            return [
                'tracked' => false,
                'reason'  => 'rate_limited',
                'message' => 'Too many requests. Please slow down.',
            ];
        }

        // Ignore bots if configured
        if ($this->ignoreBots && $this->visitor->isBot()) {
            return [
                'tracked'  => false,
                'reason'   => 'bot_ignored',
                'bot_name' => $this->visitor->getBotName(),
            ];
        }

        // Determine page
        if ($page === null) {
            $page = $this->detectCurrentPage();
        }

        // Determine referrer
        $referrer = $options['referrer'] ?? $this->visitor->getReferrer();
        $referrerDomain = $this->visitor->getReferrerDomain();

        // Build hit data
        $hit = [
            'timestamp'    => $this->now,
            'page'         => $page,
            'page_title'   => $options['page_title'] ?? '',
            'referrer'     => $referrer,
            'referrer_domain' => $referrerDomain,
            'referrer_type'   => $this->visitor->getReferrerType(),
            'browser'      => $this->visitor->getBrowser(),
            'browser_version' => $this->visitor->getBrowserVersion(),
            'os'           => $this->visitor->getOperatingSystem(),
            'device_type'  => $this->visitor->getDeviceType(),
            'language'     => $options['language'] ?? $this->visitor->getLanguage(),
            'screen_size'  => $options['screen_size'] ?? $this->visitor->getScreenSize(),
            'load_time'    => $options['load_time'] ?? 0,
            'scroll_depth' => $options['scroll_depth'] ?? 0,
            'is_bot'       => $this->visitor->isBot(),
        ];

        // Add to buffer
        $this->buffer[] = $hit;

        // Auto-flush if buffer is full
        if (count($this->buffer) >= $this->bufferSize) {
            $this->flushBuffer();
        }

        // Invalidate today stats cache
        $this->todayStatsCache = null;

        // Return current stats
        return [
            'tracked'         => true,
            'buffered'        => true,
            'buffer_size'     => count($this->buffer),
            'visitor_id'      => $this->visitor->getVisitorId(),
            'session_id'      => $this->visitor->getSessionId(),
        ];
    }

    /**
     * Track a custom event.
     *
     * Events are stored in a JSON text field or separate events table.
     * For now, events are appended to the current hit data.
     *
     * @param string      $category Event category (e.g., 'click', 'download', 'video')
     * @param string      $action   Event action (e.g., 'play', 'pause', 'submit')
     * @param string|null $label    Optional label (e.g., button text, file name)
     * @param mixed       $value    Optional numeric value
     * @return array
     */
    public function trackEvent(string $category, string $action, ?string $label = null, $value = null): array
    {
        $event = [
            'category' => substr($category, 0, 50),
            'action'   => substr($action, 0, 50),
            'label'    => $label !== null ? substr($label, 0, 255) : null,
            'value'    => $value !== null ? (float)$value : null,
            'timestamp' => $this->now,
        ];

        // Store events separately - insert into a simple event log
        // For now, we log events to the visits table as a special row
        // with page = '_event:' prefix
        $this->track('_event:' . $category . '/' . $action, [
            'page_title' => $label ?? '',
            'load_time'  => (int)(($value ?? 0) * 1000),
        ]);

        return [
            'tracked' => true,
            'event'   => $event,
        ];
    }

    // =========================================================================
    // BATCH PROCESSING
    // =========================================================================

    /**
     * Begin a manual batch operation.
     * During a batch, hits are buffered but not auto-flushed.
     *
     * @return void
     */
    public function beginBatch(): void
    {
        $this->batchActive = true;
    }

    /**
     * Commit a manual batch – flush all buffered hits.
     *
     * @return int Number of hits committed
     */
    public function commitBatch(): int
    {
        $this->batchActive = false;
        return $this->flushBuffer();
    }

    /**
     * Force flush the write buffer immediately.
     * Writes all buffered hits to database within a transaction.
     *
     * @return int Number of hits flushed
     */
    public function flushBuffer(): int
    {
        if (empty($this->buffer)) {
            return 0;
        }

        $hits = $this->buffer;
        $this->buffer = [];
        $count = count($hits);

        try {
            // Ensure the visitor record exists
            $this->ensureVisitor();

            // Use transaction for atomic batch insert
            $this->db->beginTransaction();

            foreach ($hits as $hit) {
                $this->insertHit($hit);
            }

            $this->db->commit();

            return $count;

        } catch (Throwable $e) {
            $this->db->rollback();
            // Re-add hits back to buffer for retry
            $this->buffer = array_merge($hits, $this->buffer);
            $this->log('Flush failed: ' . $e->getMessage());
            return 0;
        }
    }

    // =========================================================================
    // BACKWARD-COMPATIBILITY WRAPPERS (for frontend integration)
    // =========================================================================

    /**
     * BC wrapper: Track a hit (called by track.php).
     * Delegates to track() with page/referrer parameters.
     *
     * @param string|null $page     Page URL or null for auto-detect
     * @param string|null $referrer Referrer URL or null for auto-detect
     * @return array
     */
    public function trackHit(?string $page = null, ?string $referrer = null): array
    {
        $options = [];
        if ($referrer !== null) {
            $options['referrer'] = $referrer;
        }
        return $this->track($page, $options);
    }

    // =========================================================================
    // INTERNAL: UTILITIES
    // =========================================================================

    /**
     * Detect the current page URL from server variables.
     *
     * @return string Page path (e.g., '/about')
     */
    private function detectCurrentPage(): string
    {
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        return parse_url($path, PHP_URL_PATH) ?: '/';
    }

    /**
     * Log a message to the PHP error log with a consistent prefix.
     *
     * @param string $message
     * @return void
     */
    protected function log(string $message): void
    {
        error_log('[Countr Analytics Tracker] ' . $message);
    }

    // =========================================================================
    // DESTRUCTOR
    // =========================================================================

    /**
     * Destructor – auto-flush remaining buffer on shutdown.
     */
    public function __destruct()
    {
        if (!empty($this->buffer)) {
            $this->flushBuffer();
        }
    }
}