/**
 * admin/charts.js - Admin Chart Rendering
 *
 * Doughnut charts (browsers, OS, devices) and horizontal bar
 * charts (pages, referrers) for the admin panel tabs.
 *
 * @package Countr
 * @version 1.6.0
 */

import {
    t, getTheme, getChartDefaults, destroyAdminChart,
    getAdminCharts, doughnutColors
} from './core.js';

/**
 * Create a doughnut chart.
 * @param {string} canvasId
 * @param {{ labels: string[], values: number[] }} data
 * @param {string} title
 */
function createDoughnutChart(canvasId, data, title) {
    destroyAdminChart(canvasId);
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    const defaults = getChartDefaults();
    const ctx = canvas.getContext('2d');
    const adminCharts = getAdminCharts();

    if (!data || !data.labels || data.labels.length === 0) {
        // Draw empty state
        ctx.font = '14px sans-serif';
        ctx.fillStyle = getTheme() === 'dark' ? '#94a3b8' : '#9ca3af';
        ctx.textAlign = 'center';
        ctx.fillText(t('no_data', 'No data'), canvas.width / 3, canvas.height / 2);
        return;
    }

    adminCharts[canvasId] = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: data.labels,
            datasets: [{
                data: data.values,
                backgroundColor: doughnutColors.slice(0, data.labels.length),
                borderColor: getTheme() === 'dark' ? '#1e293b' : '#ffffff',
                borderWidth: 2,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                title: {
                    display: true,
                    text: title,
                    color: defaults.plugins.legend.labels.color,
                    font: { size: 13, weight: 'bold' },
                    padding: { bottom: 12 },
                },
                legend: {
                    position: 'bottom',
                    labels: {
                        color: defaults.plugins.legend.labels.color,
                        padding: 12,
                        font: { size: 11 },
                        usePointStyle: true,
                    },
                },
            },
        },
    });
}

/**
 * Create a horizontal bar chart for pages/referrers.
 * @param {string} canvasId
 * @param {{ labels: string[], values: number[] }} data
 * @param {string} label
 */
function createHorizontalBarChart(canvasId, data, label) {
    destroyAdminChart(canvasId);
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    const defaults = getChartDefaults();
    const ctx = canvas.getContext('2d');
    const adminCharts = getAdminCharts();

    if (!data || !data.labels || data.labels.length === 0) return;

    // Reverse for horizontal bar (top item at top)
    const reversedLabels = data.labels.slice().reverse();
    const reversedValues = data.values.slice().reverse();

    adminCharts[canvasId] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: reversedLabels,
            datasets: [{
                label: label,
                data: reversedValues,
                backgroundColor: 'rgba(102, 126, 234, 0.7)',
                borderColor: 'rgba(102, 126, 234, 1)',
                borderWidth: 1,
                borderRadius: 4,
                maxBarThickness: 30,
            }],
        },
        options: {
            indexAxis: 'y',
            ...defaults,
            plugins: {
                ...defaults.plugins,
                legend: { display: false },
            },
        },
    });
}

/**
 * Render all Visitors tab charts (Browsers, OS, Devices).
 */
export function renderAdminCharts() {
    const data = window.AdminChartData;
    if (!data) return;

    if (data.browsers && data.browsers.labels && data.browsers.labels.length > 0) {
        createDoughnutChart('adminChartBrowsers', data.browsers, t('browserDistTitle', 'Browser Distribution'));
    }
    if (data.os && data.os.labels && data.os.labels.length > 0) {
        createDoughnutChart('adminChartOS', data.os, t('osDistTitle', 'Operating Systems'));
    }
    if (data.devices && data.devices.labels && data.devices.labels.length > 0) {
        createDoughnutChart('adminChartDevices', data.devices, t('deviceDistTitle', 'Device Types'));
    }
}

/**
 * Render Pages horizontal bar chart.
 */
export function renderPagesChart() {
    const data = window.AdminChartData;
    if (!data || !data.pages || !data.pages.labels || data.pages.labels.length === 0) return;

    createHorizontalBarChart('adminChartPages', data.pages, t('pageviews', 'Pageviews'));
}

/**
 * Render Referrers horizontal bar chart.
 */
export function renderReferrersChart() {
    const data = window.AdminChartData;
    if (!data || !data.referrers || !data.referrers.labels || data.referrers.labels.length === 0) return;

    createHorizontalBarChart('adminChartReferrers', data.referrers, t('visitors', 'Visitors'));
}