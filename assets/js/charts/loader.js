/**
 * charts/loader.js - Chart Data Loader & Orchestrator
 *
 * Handles initial data loading from CountrData (PHP render),
 * API polling, auto-init on DOM ready, and theme change listening.
 *
 * @package Countr
 * @version 1.6.0
 */

import { safeChartData, updateChartThemes } from './core.js';
import { create7DayChart } from './bar.js';
import { createHourlyChart, create30DayChart } from './line.js';
import { createBrowserChart } from './doughnut.js';
import { createPagesChart } from './pages.js';

/**
 * Load all charts from the server API.
 * Uses the window.CountrData first (initial PHP render), then polls API.
 */
function loadAllCharts() {
    // Try initial data from PHP render first
    const initialData = window.CountrData;
    if (initialData) {
        try {
            const s7 = safeChartData(initialData.last7Days);
            const s30 = safeChartData(initialData.last30Days);
            const sh = safeChartData(initialData.hourly);
            const sb = safeChartData(initialData.browsers, 'browser', 'count');

            if (s7.labels.length > 0) create7DayChart(s7);
            if (s30.labels.length > 0) create30DayChart(s30);
            if (sh.labels.length > 0) createHourlyChart(sh);
            if (sb.labels.length > 0) createBrowserChart(sb);
        } catch (e) {
            console.warn('[Countr Charts] initial render failed:', e.message);
        }
    }

    // Then poll for fresh data
    fetchChartData();
}

/**
 * Fetch fresh chart data from the API and update all charts.
 */
function fetchChartData() {
    fetch('api.php?action=snapshot')
        .then(function (response) {
            if (!response.ok) throw new Error('API error: ' + response.status);
            return response.json();
        })
        .then(function (result) {
            if (!result.success || !result.data) return;

            const d = result.data;

            try {
                // 7-day chart – prefer pre-transformed key, fallback to raw data
                const s7 = safeChartData(d.last_7_days_chart || d.last_7_days);
                if (s7.labels.length > 0) create7DayChart(s7);

                // 30-day chart
                const s30 = safeChartData(d.last_30_days_chart || d.last_30_days);
                if (s30.labels.length > 0) create30DayChart(s30);

                // Hourly chart
                const sh = safeChartData(d.hourly_chart || d.hourly);
                if (sh.labels.length > 0) createHourlyChart(sh);

                // Browser chart – fallback to raw array-of-objects
                const sb = safeChartData(d.browsers_chart || d.browsers, 'browser', 'count');
                if (sb.labels.length > 0) createBrowserChart(sb);

                // Pages chart – fallback to raw array-of-objects
                const sp = safeChartData(d.top_pages_chart || d.top_pages, 'page_url', 'total_views');
                if (sp.labels.length > 0) createPagesChart(sp);
            } catch (e) {
                console.warn('[Countr Charts] chart creation failed:', e.message);
            }
        })
        .catch(function (err) {
            console.warn('[Countr Charts] Could not fetch chart data:', err.message);
        });
}

/**
 * Initialize all chart modules.
 * Auto-init when DOM is ready.
 */
export function initCharts() {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadAllCharts);
    } else {
        loadAllCharts();
    }

    // Listen for theme changes
    document.addEventListener('wb:themeChanged', updateChartThemes);
}

/**
 * Re-export fetchChartData for manual refresh.
 */
export { fetchChartData };