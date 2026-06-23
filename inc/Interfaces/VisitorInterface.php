<?php
/**
 * VisitorInterface - Contract for visitor identification and analysis.
 *
 * @package Countr\Interfaces
 * @copyright  2026 Countr Analytics
 * @version 1.3.0
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Countr\Interfaces;

interface VisitorInterface
{
    /**
     * Get the unique visitor hash (fingerprint).
     *
     * @return string
     */
    public function getVisitorId(): string;

    /**
     * Get the current session ID.
     *
     * @return string
     */
    public function getSessionId(): string;

    /**
     * Get the anonymized IP address.
     *
     * @return string
     */
    public function getAnonymizedIp(): string;

    /**
     * Get a hash of the (anonymized) IP address.
     *
     * @return string
     */
    public function getIpHash(): string;

    /**
     * Get the raw User-Agent string.
     *
     * @return string
     */
    public function getUserAgent(): string;

    /**
     * Get the detected browser name.
     *
     * @return string
     */
    public function getBrowser(): string;

    /**
     * Get the browser version.
     *
     * @return string
     */
    public function getBrowserVersion(): string;

    /**
     * Get the detected operating system.
     *
     * @return string
     */
    public function getOperatingSystem(): string;

    /**
     * Get the device type.
     *
     * @return string 'desktop', 'mobile', 'tablet', 'tv', 'console', 'bot', or 'unknown'
     */
    public function getDeviceType(): string;

    /**
     * Check if this visitor is a bot.
     *
     * @return bool
     */
    public function isBot(): bool;

    /**
     * Check if this is a known search engine crawler.
     *
     * @return bool
     */
    public function isSearchEngine(): bool;

    /**
     * Get the bot name if this is a bot, null otherwise.
     *
     * @return string|null
     */
    public function getBotName(): ?string;

    /**
     * Get the referrer domain.
     *
     * @return string
     */
    public function getReferrerDomain(): string;

    /**
     * Get the referrer traffic type.
     *
     * @return string 'direct', 'organic', 'social', 'email', 'paid', 'referral', or 'unknown'
     */
    public function getReferrerType(): string;

    /**
     * Get the visitor's preferred language.
     *
     * @return string
     */
    public function getLanguage(): string;

    /**
     * Get all parsed visitor information as an associative array.
     *
     * @return array
     */
    public function toArray(): array;
}