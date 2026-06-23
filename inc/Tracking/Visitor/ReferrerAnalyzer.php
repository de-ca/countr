<?php
/**
 * ReferrerAnalyzer - Referrer domain parsing and traffic source classification.
 *
 * Extracted from the monolithic Visitor class. Analyzes HTTP referrer headers
 * to determine traffic sources: direct, organic search, social media, email,
 * paid (CPC/PPC), and referral.
 *
 * @package Countr\Tracking
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Tracking;

class ReferrerAnalyzer
{
    // =========================================================================
    // KNOWN SEARCH ENGINES
    // =========================================================================

    private const SEARCH_ENGINES = [
        'google.',
        'bing.',
        'yahoo.',
        'yandex.',
        'baidu.',
        'duckduckgo.',
        'ecosia.',
        'qwant.',
        'ask.',
        'seznam.',
        'naver.',
        'sogou.',
        'mojeek.',
    ];

    // =========================================================================
    // KNOWN SOCIAL MEDIA DOMAINS
    // =========================================================================

    private const SOCIAL_DOMAINS = [
        'facebook.',
        'fb.',
        'instagram.',
        'twitter.',
        'x.com',
        'linkedin.',
        'pinterest.',
        'reddit.',
        'tumblr.',
        'snapchat.',
        'tiktok.',
        'youtube.',
        'whatsapp.',
        'telegram.',
        'discord.',
        'signal.',
        'mastodon.',
        'bsky.',
        'threads.',
    ];

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Analyze a referrer URL and return comprehensive traffic source data.
     *
     * @param string $referrer Raw HTTP_REFERER value (empty string if none)
     * @return array{referrer: string, domain: string, type: string}
     */
    public function analyze(string $referrer): array
    {
        if (empty($referrer)) {
            return [
                'referrer' => '',
                'domain'   => 'direct',
                'type'     => 'direct',
            ];
        }

        $host = parse_url($referrer, PHP_URL_HOST);
        if (!$host || $host === false) {
            return [
                'referrer' => $referrer,
                'domain'   => 'unknown',
                'type'     => 'direct',
            ];
        }

        // Normalize domain: remove www. prefix
        $domain = preg_replace('/^www\./', '', $host);

        $query = parse_url($referrer, PHP_URL_QUERY) ?? '';

        // Determine traffic type
        $type = $this->classifyType($domain, $query);

        return [
            'referrer' => $referrer,
            'domain'   => $domain,
            'type'     => $type,
        ];
    }

    /**
     * Get the referrer domain (host) without www prefix.
     *
     * @param string $referrer Raw HTTP_REFERER
     * @return string 'direct' if no referrer, domain name otherwise
     */
    public function getDomain(string $referrer): string
    {
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
     * Classify the traffic source type.
     *
     * @param string $referrer Raw HTTP_REFERER
     * @return string 'direct', 'organic', 'social', 'email', 'paid', 'referral', or 'unknown'
     */
    public function getType(string $referrer): string
    {
        if (empty($referrer)) {
            return 'direct';
        }

        $host = parse_url($referrer, PHP_URL_HOST);
        if (!$host) {
            return 'direct';
        }

        $domain = preg_replace('/^www\./', '', $host);
        $query = parse_url($referrer, PHP_URL_QUERY) ?? '';

        return $this->classifyType($domain, $query);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Classify the traffic source type based on domain and query parameters.
     *
     * @param string $domain Normalized domain (no www.)
     * @param string $query  URL query string
     * @return string
     */
    private function classifyType(string $domain, string $query): string
    {
        // Email (check BEFORE organic — mail.google.com should not be "organic")
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
        foreach (self::SEARCH_ENGINES as $se) {
            if (strpos($domain, $se) !== false) {
                return 'organic';
            }
        }

        // Social media
        foreach (self::SOCIAL_DOMAINS as $sd) {
            if (strpos($domain, $sd) !== false) {
                return 'social';
            }
        }

        return 'referral';
    }
}