/**
 * charts/doughnut.js - Doughnut Chart (Browser Distribution)
 *
 * Creates the browser distribution doughnut chart.
 *
 * @package Countr
 * @version 1.6.0
 */

import { t, getTheme, getChartDefaults, destroyChart, getCharts } from './core.js';

const colors = [
    'rgba(102, 126, 234, 0.8)',
    'rgba(239, 68, 68, 0.7)',
    'rgba(16, 185, 129, 0.7)',
    'rgba(245, 158, 11, 0.7)',
    'rgba(59, 130, 246, 0.7)',
    'rgba(236, 72, 153, 0.7)',
    'rgba(168, 85, 247, 0.7)',
];

/**
 * Create or update the browser distribution doughnut chart.
 * @param {object} data - { labels: string[], values: number[] }
 */
export function createBrowserChart(data) {
    destroyChart('chartBrowsers');

    const canvas = document.getElementById('chartBrowsers');
    if (!canvas) return;

    const defaults = getChartDefaults();
    const ctx = canvas.getContext('2d');
    const charts = getCharts();

    charts.chartBrowsers = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: data.labels || [],
            datasets: [{
                data: data.values || [],
                backgroundColor: colors,
                borderColor: getTheme() === 'dark' ? '#1e293b' : '#ffffff',
                borderWidth: 2,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
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