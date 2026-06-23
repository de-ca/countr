/**
 * admin/core.js - Countr Admin Core Utilities
 *
 * Shared helpers for all admin modules: i18n, theme detection,
 * Chart.js defaults, chart registry, and format utilities.
 *
 * @package Countr
 * @version 1.6.0
 */

// Admin chart instances registry
const adminCharts = {};

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
 * Destroy a chart by canvas ID.
 * @param {string} canvasId
 */
export function destroyAdminChart(canvasId) {
    if (adminCharts[canvasId]) {
        adminCharts[canvasId].destroy();
        delete adminCharts[canvasId];
    }
}

/**
 * Update all admin chart themes when dark/light mode changes.
 */
export function updateAdminChartThemes() {
    Object.keys(adminCharts).forEach((key) => {
        if (adminCharts[key] && adminCharts[key].options && adminCharts[key].options.scales) {
            const style = getComputedStyle(document.documentElement);
            const tickColor = style.getPropertyValue('--text-muted').trim();
            const gridColor = style.getPropertyValue('--border-color').trim();
            const labelColor = style.getPropertyValue('--text-secondary').trim();

            if (adminCharts[key].options.scales.x) {
                adminCharts[key].options.scales.x.ticks.color = tickColor;
                adminCharts[key].options.scales.x.grid.color = gridColor;
            }
            if (adminCharts[key].options.scales.y) {
                adminCharts[key].options.scales.y.ticks.color = tickColor;
                adminCharts[key].options.scales.y.grid.color = gridColor;
            }
            if (adminCharts[key].options.plugins && adminCharts[key].options.plugins.legend) {
                if (adminCharts[key].options.plugins.legend.labels) {
                    adminCharts[key].options.plugins.legend.labels.color = labelColor;
                }
            }
            adminCharts[key].update();
        }
    });
}

/**
 * Get the admin charts registry (read-only reference).
 * @returns {object}
 */
export function getAdminCharts() {
    return adminCharts;
}

/**
 * Doughnut color palette shared across admin charts.
 */
export const doughnutColors = [
    'rgba(102, 126, 234, 0.8)',
    'rgba(239, 68, 68, 0.7)',
    'rgba(16, 185, 129, 0.7)',
    'rgba(245, 158, 11, 0.7)',
    'rgba(59, 130, 246, 0.7)',
    'rgba(236, 72, 153, 0.7)',
    'rgba(168, 85, 247, 0.7)',
    'rgba(20, 184, 166, 0.7)',
    'rgba(251, 146, 60, 0.7)',
    'rgba(148, 163, 184, 0.7)',
];