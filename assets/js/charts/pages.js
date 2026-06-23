/**
 * charts/pages.js - Horizontal Bar Chart (Top Pages)
 *
 * Creates the top pages horizontal bar chart.
 *
 * @package Countr
 * @version 1.6.0
 */

import { t, getChartDefaults, destroyChart, getCharts } from './core.js';

/**
 * Create or update the top pages horizontal bar chart.
 * @param {object} data - { labels: string[], values: number[] }
 */
export function createPagesChart(data) {
    destroyChart('chartPages');

    const canvas = document.getElementById('chartPages');
    if (!canvas) return;

    const defaults = getChartDefaults();
    const ctx = canvas.getContext('2d');
    const charts = getCharts();

    charts.chartPages = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels || [],
            datasets: [{
                label: t('pageviews', 'Pageviews'),
                data: data.values || [],
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
                legend: { display: false },
            },
        },
    });
}