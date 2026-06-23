/**
 * dashboard.js - Countr Dashboard Logic
 *
 * Handles live stats updates, auto-refresh, theme toggling,
 * and real-time counter animations.
 *
 * @package Countr
 * @version 1.6.0
 */

(function () {
    'use strict';

    // Config
    var REFRESH_INTERVAL = 30000; // 30 seconds
    var API_ENDPOINT = 'api.php?action=snapshot';

    /**
     * Get translated string from window.CountrI18n or fallback.
     * @param {string} key
     * @param {string} [fallback]
     * @returns {string}
     */
    function t(key, fallback) {
        if (window.CountrI18n && window.CountrI18n[key]) {
            return window.CountrI18n[key];
        }
        return fallback || key;
    }

    // State
    let refreshTimer = null;
    let isUpdating = false;
    let lastOnlineCount = 0;

    // =========================================================================
    // THEME MANAGEMENT
    // =========================================================================

    /**
     * Get the stored theme preference.
     * @returns {string} 'dark' or 'light'
     */
    function getStoredTheme() {
        try {
            return localStorage.getItem('wb-theme') || 'light';
        } catch (e) {
            return 'light';
        }
    }

    /**
     * Store the theme preference.
     * @param {string} theme
     */
    function setStoredTheme(theme) {
        try {
            localStorage.setItem('wb-theme', theme);
        } catch (e) {
            // Storage unavailable
        }
    }

    /**
     * Apply the given theme to the document.
     * @param {string} theme 'dark' or 'light'
     */
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        setStoredTheme(theme);

        // Update toggle button text
        const toggleBtn = document.getElementById('theme-toggle');
        if (toggleBtn) {
            toggleBtn.innerHTML = theme === 'dark'
                ? '☀️ ' + t('light', 'Hell')
                : '🌙 ' + t('dark', 'Dunkel');
        }

        // Notify charts
        document.dispatchEvent(new CustomEvent('wb:themeChanged', { detail: { theme } }));
    }

    /**
     * Toggle between light and dark mode.
     */
    function toggleTheme() {
        const current = document.documentElement.getAttribute('data-theme') || 'light';
        applyTheme(current === 'dark' ? 'light' : 'dark');
    }

    /**
     * Initialize theme from stored preference.
     */
    function initTheme() {
        const stored = getStoredTheme();
        applyTheme(stored);

        const toggleBtn = document.getElementById('theme-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', toggleTheme);
        }
    }

    // =========================================================================
    // DATA FETCHING
    // =========================================================================

    /**
     * Format seconds into MM:SS string.
     * @param {number} seconds
     * @returns {string}
     */
    function formatTime(seconds) {
        if (!seconds || isNaN(seconds)) return '0:00';
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return mins + ':' + String(secs).padStart(2, '0');
    }

    /**
     * Format a number with thousands separators (German locale style: 1.234).
     * @param {number} num
     * @returns {string}
     */
    function formatNumber(num) {
        if (num === null || num === undefined || isNaN(num)) return '0';
        return new Intl.NumberFormat('de-DE').format(num);
    }

    /**
     * Animate a numeric value change.
     * @param {HTMLElement} el - DOM element to update
     * @param {number} newValue - Target value
     * @param {boolean} animate - Whether to animate
     */
    function updateCounter(el, newValue, animate) {
        if (!el) return;

        const currentText = el.textContent;
        const currentVal = parseInt(currentText.replace(/[^0-9-]/g, ''), 10) || 0;

        if (currentVal === newValue && !animate) {
            return;
        }

        if (!animate) {
            el.textContent = formatNumber(newValue);
            return;
        }

        // Animate count-up
        const duration = 500;
        const startTime = performance.now();
        const diff = newValue - currentVal;

        function step(timestamp) {
            const elapsed = timestamp - startTime;
            const progress = Math.min(elapsed / duration, 1);
            // Ease out cubic
            const eased = 1 - Math.pow(1 - progress, 3);
            const current = Math.round(currentVal + diff * eased);

            el.textContent = formatNumber(current);

            if (progress < 1) {
                requestAnimationFrame(step);
            }
        }

        requestAnimationFrame(step);
    }

    /**
     * Fetch live data from the API and update all widgets.
     */
    async function fetchLiveData() {
        if (isUpdating) return;
        isUpdating = true;

        try {
            const response = await fetch(API_ENDPOINT);
            if (!response.ok) throw new Error('HTTP ' + response.status);

            const result = await response.json();
            if (!result.success || !result.data) return;

            const d = result.data;
            const animate = lastOnlineCount !== 0;

            // Resolve online count (multiple possible keys)
            const onlineVal = d.online ?? d.currently_online ?? (d.today ? d.today.realtime_online : null) ?? 0;

            // Update online count
            const onlineEl = document.getElementById('online-count');
            if (onlineEl && onlineVal !== undefined) {
                updateCounter(onlineEl, onlineVal, false);
                lastOnlineCount = onlineVal;

                // Pulse indicator
                const pulseEl = document.querySelector('.stat-card__pulse');
                if (pulseEl) {
                    pulseEl.style.display = onlineVal > 0 ? 'block' : 'none';
                }
            }

            // Update today visitors (resolve from multiple possible key paths)
            const todayVisitors = d.today_visitors
                ?? (d.today ? (d.today.visitors_today ?? d.today.visitors) : null)
                ?? 0;
            const todayVisEl = document.getElementById('today-visitors');
            if (todayVisEl) {
                updateCounter(todayVisEl, todayVisitors, animate);
            }

            // Update today pageviews (resolve from multiple possible key paths)
            const todayPageviews = d.today_pageviews
                ?? (d.today ? (d.today.pageviews_today ?? d.today.pageviews) : null)
                ?? 0;
            const todayPvEl = document.getElementById('today-pageviews');
            if (todayPvEl) {
                updateCounter(todayPvEl, todayPageviews, animate);
            }

            // Update total visitors
            const totalEl = document.getElementById('total-visitors');
            if (totalEl && d.overall) {
                const totalVisitors = d.overall.total_visitors
                    ?? (d.overall.totals ? d.overall.totals.visitors : null)
                    ?? d.overall.visitors
                    ?? 0;
                updateCounter(totalEl, totalVisitors, animate);
            }

            // Update bounce rate if element exists
            const bounceEl = document.getElementById('bounce-rate');
            if (bounceEl && d.bounce_rate !== undefined) {
                bounceEl.textContent = Math.round(d.bounce_rate) + '%';
            }

            // Update avg time if element exists
            const avgTimeEl = document.getElementById('avg-time');
            if (avgTimeEl) {
                const avgDur = d.avg_duration
                    ?? (d.today ? d.today.session_duration_avg : null)
                    ?? 0;
                avgTimeEl.textContent = formatTime(avgDur);
            }

            // Update last-update timestamp
            const updateEl = document.getElementById('last-update');
            if (updateEl) {
                const now = new Date();
                updateEl.textContent = now.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }

            // Update top pages list if it exists with live data
            updateTopPages(d.top_pages);

            // Hide error banner if visible
            const errorBanner = document.getElementById('error-banner');
            if (errorBanner) {
                errorBanner.classList.remove('visible');
            }

        } catch (err) {
            console.warn('[Countr] Live update failed:', err.message);
            showError(t('error_fetch', 'Live-Update fehlgeschlagen. Verbindung zum Server unterbrochen.'));
        } finally {
            isUpdating = false;
        }
    }

    /**
     * Normalize raw top_pages data into a uniform array of { url, count } entries.
     * Accepts:
     *   - { labels: string[], values: number[] }   (chart-ready format)
     *   - [{ page_url: string, total_views: number, ... }]  (raw API format)
     *   - { url: count }  (simple dictionary)
     *   - anything else → returns []
     *
     * @param {*} topPages
     * @returns {{ url: string, count: number }[]}
     */
    function normalizeTopPages(topPages) {
        if (!topPages) return [];

        // Chart-ready format: { labels: [...], values: [...] }
        if (topPages.labels && topPages.values && Array.isArray(topPages.labels) && Array.isArray(topPages.values)) {
            return topPages.labels.map(function (label, i) {
                return { url: String(label || ''), count: Number(topPages.values[i]) || 0 };
            });
        }

        // Raw API array: [{ page_url: '...', total_views: N, unique_views: N }, ...]
        if (Array.isArray(topPages)) {
            return topPages
                .filter(function (item) { return item && typeof item === 'object'; })
                .map(function (item) {
                    var url  = item.page_url  || item.url  || '';
                    var count = item.total_views || item.count || item.unique_views || 0;
                    return { url: String(url), count: Number(count) || 0 };
                });
        }

        // Plain object / dictionary: { '/page': 5, '/other': 3 }
        if (typeof topPages === 'object' && !Array.isArray(topPages)) {
            try {
                return Object.entries(topPages)
                    .filter(function (entry) { return entry && entry.length === 2; })
                    .map(function (entry) {
                        var url   = String(entry[0] || '');
                        var count = (typeof entry[1] === 'object' && entry[1] !== null)
                            ? (Number(entry[1].total_views) || Number(entry[1].count) || 0)
                            : (Number(entry[1]) || 0);
                        return { url: url, count: count };
                    });
            } catch (e) { /* silently ignore */ }
        }

        return [];
    }

    /**
     * Update the top pages list from live data.
     * Handles any shape the API may return for top_pages.
     * @param {*} topPages
     */
    function updateTopPages(topPages) {
        var container = document.getElementById('top-pages');
        if (!container) return;

        var entries = normalizeTopPages(topPages);

        if (entries.length === 0) {
            container.innerHTML = '<p class="empty-state">' + escapeHtml(t('no_data', 'Noch keine Daten für heute vorhanden.')) + '</p>';
            return;
        }

        var html = '';
        entries.slice(0, 10).forEach(function (entry, index) {
            var count = (entry && typeof entry.count === 'number') ? entry.count : 0;
            var url   = (entry && entry.url) ? String(entry.url) : '';
            html +=
                '<div class="top-page-item">' +
                    '<span class="top-page-rank">#' + (index + 1) + '</span>' +
                    '<span class="top-page-url">' + escapeHtml(url) + '</span>' +
                    '<span class="top-page-count">' + formatNumber(count) + '</span>' +
                '</div>';
        });
        container.innerHTML = html;
    }

    /**
     * Show an error banner.
     * @param {string} message
     */
    function showError(message) {
        const banner = document.getElementById('error-banner');
        if (!banner) return;

        banner.textContent = message;
        banner.classList.add('visible');

        // Auto-hide after 10 seconds
        clearTimeout(banner._hideTimeout);
        banner._hideTimeout = setTimeout(() => {
            banner.classList.remove('visible');
        }, 10000);
    }

    /**
     * Basic HTML escaping.
     * @param {string} str
     * @returns {string}
     */
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // =========================================================================
    // AUTO-REFRESH
    // =========================================================================

    /**
     * Start the auto-refresh timer.
     */
    function startAutoRefresh() {
        stopAutoRefresh();
        refreshTimer = setInterval(fetchLiveData, REFRESH_INTERVAL);
    }

    /**
     * Stop the auto-refresh timer.
     */
    function stopAutoRefresh() {
        if (refreshTimer) {
            clearInterval(refreshTimer);
            refreshTimer = null;
        }
    }

    /**
     * Handle page visibility changes (pause updates when hidden).
     */
    function handleVisibilityChange() {
        if (document.hidden) {
            stopAutoRefresh();
        } else {
            // Fetch immediately when becoming visible again
            fetchLiveData();
            startAutoRefresh();
        }
    }

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    /**
     * Initialize the dashboard.
     */
    function init() {
        initTheme();

        // Initial data fetch
        fetchLiveData();

        // Start auto-refresh
        startAutoRefresh();

        // Handle visibility changes
        document.addEventListener('visibilitychange', handleVisibilityChange);

        // Handle manual refresh button
        const refreshBtn = document.getElementById('refresh-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                fetchLiveData();
                if (window.WBCharts && window.WBCharts.fetchChartData) {
                    window.WBCharts.fetchChartData();
                }
                // Visual feedback
                this.style.transform = 'rotate(360deg)';
                this.style.transition = 'transform 0.6s ease';
                setTimeout(() => {
                    this.style.transform = '';
                }, 600);
            });
        }

        // Handle error banner dismiss
        const errorBanner = document.getElementById('error-banner');
        if (errorBanner) {
            errorBanner.addEventListener('click', function () {
                this.classList.remove('visible');
            });
        }
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================
    window.WBDashboard = {
        fetchLiveData,
        startAutoRefresh,
        stopAutoRefresh,
        toggleTheme,
        applyTheme,
        init,
    };

    // Auto-init when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();