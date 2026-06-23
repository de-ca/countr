/**
 * charts/core.js - Countr Chart Core Utilities
 *
 * Shared helpers for all chart modules: i18n, theme detection,
 * Chart.js defaults, chart registry, and data normalization.
 *
 * @package Countr
 * @version 1.6.0
 */

// Chart instances registry for cleanup
const charts = {};

/**
 * Get translated string from window.CountrI18n or fallback to the key itself.
 * @param {string} key
 * @param {string} [fallback]
 * @returns {string}
 */
export function t(key, fallback) {
    if (window.CountrI18n && window.CountrI18n[key]) {
        return window.CountrI18n[key];
    }
    return fallback || key;
}

/**
 * Get the current theme from the HTML element.
 * @returns {string} 'dark' or 'light'
 */
export function getTheme() {
    return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
}

/**
 * Get Chart.js global options based on current theme.
 * @returns {object}
 */
export function getChartDefaults() {
    const isDark = getTheme() === 'dark';
    const style = getComputedStyle(document.documentElement);

    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: {
                    color: style.getPropertyValue('--text-secondary').trim(),
                    padding: 16,
                    font: { size: 12 },
                    usePointStyle: true,
                },
            },
            tooltip: {
                backgroundColor: isDark ? '#334155' : '#1e293b',
                titleColor: '#f1f5f9',
                bodyColor: '#e2e8f0',
                borderColor: isDark ? '#475569' : '#cbd5e1',
                borderWidth: 1,
                padding: 10,
                cornerRadius: 6,
            },
        },
        scales: {
            x: {
                ticks: { color: style.getPropertyValue('--text-muted').trim() },
                grid: { color: style.getPropertyValue('--border-color').trim() },
            },
            y: {
                ticks: { color: style.getPropertyValue('--text-muted').trim() },
                grid: { color: style.getPropertyValue('--border-color').trim() },
                beginAtZero: true,
            },
        },
    };
}

/**
 * Destroy an existing chart by ID.
 * @param {string} id Canvas element ID
 */
export function destroyChart(id) {
    if (charts[id]) {
        charts[id].destroy();
        delete charts[id];
    }
}

/**
 * Convert a raw array of objects (e.g. [{ browser: 'Chrome', count: 5 }, ...])
 * into a { labels: [...], values: [...] } chart-ready object.
 * Also handles arrays of { page_url, total_views } etc.
 *
 * @param {Array} rawArray
 * @param {string} [labelKey]  – property name used for labels (first key with string value)
 * @param {string} [valueKey]  – property name used for values (first key with number value)
 * @returns {{ labels: string[], values: number[] }}
 */
export function arrayToChart(rawArray, labelKey, valueKey) {
    if (!Array.isArray(rawArray)) return { labels: [], values: [] };

    const labels = [];
    const values = [];

    rawArray.forEach((item) => {
        if (!item || typeof item !== 'object') return;

        let label = '';
        let val = 0;

        if (labelKey && valueKey) {
            label = String(item[labelKey] || '');
            val = Number(item[valueKey]) || 0;
        } else {
            // Auto-detect: first string-ish is label, first number-ish is value
            const keys = Object.keys(item);
            for (let i = 0; i < keys.length; i++) {
                const k = keys[i];
                const v = item[k];
                if (label === '' && typeof v === 'string') { label = v; }
                if (val === 0 && typeof v === 'number') { val = v; }
            }
        }

        labels.push(label);
        values.push(val);
    });

    return { labels, values };
}

/**
 * Ensure we always return a valid { labels: [], values: [] } object.
 * Handles:
 *   - null / undefined / empty         → { labels: [], values: [] }
 *   - already chart-ready object        → passed through
 *   - raw array-of-objects              → converted via arrayToChart
 *   - plain object (dictionary)         → converted via Object.entries
 *
 * @param {*}    raw
 * @param {string} [labelKey] – optional key for labels in array-of-objects
 * @param {string} [valueKey] – optional key for values in array-of-objects
 * @returns {{ labels: string[], values: number[] }}
 */
export function safeChartData(raw, labelKey, valueKey) {
    if (!raw) return { labels: [], values: [] };

    // Already the expected { labels, values } shape?
    if (raw.labels && raw.values && Array.isArray(raw.labels) && Array.isArray(raw.values)) {
        return raw;
    }

    // Time-series shape: { labels: string[], visitors: number[], pageviews: number[] }
    if (raw.labels && Array.isArray(raw.labels) && (Array.isArray(raw.visitors) || Array.isArray(raw.pageviews))) {
        return raw;
    }

    // Array of objects e.g. [{ browser: 'Chrome', count: 5 }, ...]
    if (Array.isArray(raw)) {
        return arrayToChart(raw, labelKey, valueKey);
    }

    // Plain object dictionary e.g. { '/page1': 5, '/page2': 3 }
    // Only treat as dictionary if the keys look like data (not 'labels','values','visitors','pageviews')
    if (typeof raw === 'object') {
        const allKeys = Object.keys(raw);
        const metaKeys = ['labels', 'values', 'visitors', 'pageviews'];
        const isMetaObject = allKeys.length > 0 && allKeys.every(k => metaKeys.indexOf(k) !== -1);
        if (isMetaObject) {
            // It's a chart data object without a recognized shape — fall back to empty
            return { labels: [], values: [] };
        }
        try {
            const labels = allKeys;
            const values = labels.map(k => Number(raw[k]) || 0);
            return { labels, values };
        } catch (e) { /* silently ignore */ }
    }

    return { labels: [], values: [] };
}

/**
 * Update all charts for theme change.
 */
export function updateChartThemes() {
    Object.keys(charts).forEach((key) => {
        if (charts[key] && charts[key].options && charts[key].options.scales) {
            const style = getComputedStyle(document.documentElement);
            const tickColor = style.getPropertyValue('--text-muted').trim();
            const gridColor = style.getPropertyValue('--border-color').trim();
            const labelColor = style.getPropertyValue('--text-secondary').trim();

            if (charts[key].options.scales.x) {
                charts[key].options.scales.x.ticks.color = tickColor;
                charts[key].options.scales.x.grid.color = gridColor;
            }
            if (charts[key].options.scales.y) {
                charts[key].options.scales.y.ticks.color = tickColor;
                charts[key].options.scales.y.grid.color = gridColor;
            }
            if (charts[key].options.plugins && charts[key].options.plugins.legend) {
                if (charts[key].options.plugins.legend.labels) {
                    charts[key].options.plugins.legend.labels.color = labelColor;
                }
            }
            charts[key].update();
        }
    });
}

/**
 * Destroy all charts (for cleanup).
 */
export function destroyAllCharts() {
    Object.keys(charts).forEach((id) => {
        if (charts[id]) {
            charts[id].destroy();
            delete charts[id];
        }
    });
}

/**
 * Get the charts registry (read-only reference).
 * @returns {object}
 */
export function getCharts() {
    return charts;
}