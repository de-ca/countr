/**
 * charts/line.js - Line Charts (Hourly + 30-Day)
 *
 * Creates hourly distribution and 30-day trend line charts.
 *
 * @package Countr
 * @version 1.6.0
 */

import { t, getChartDefaults, destroyChart, getCharts } from './core.js';

/**
 * Create or update the hourly distribution line chart.
 * @param {object} data - { labels: string[], values: number[] }
 */
export function createHourlyChart(data) {
    destroyChart('chartHourly');

    const canvas = document.getElementById('chartHourly');
    if (!canvas) return;

    const defaults = getChartDefaults();
    const ctx = canvas.getContext('2d');
    const charts = getCharts();

    charts.chartHourly = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels || [],
            datasets: [{
                label: t('visitors', 'Visitors'),
                data: data.values || [],
                borderColor: 'rgba(102, 126, 234, 1)',
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 6,
                pointBackgroundColor: 'rgba(102, 126, 234, 1)',
            }],
        },
        options: {
            ...defaults,
            interaction: { intersect: false, mode: 'index' },
        },
    });
}

/**
 * Create or update the 30-day trend chart.
 * @param {object} data - { labels: string[], visitors: number[] }
 */
export function create30DayChart(data) {
    destroyChart('chart30days');

    const canvas = document.getElementById('chart30days');
    if (!canvas) return;

    const defaults = getChartDefaults();
    const ctx = canvas.getContext('2d');
    const charts = getCharts();

    charts.chart30days = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.labels || [],
            datasets: [{
                label: t('visitors', 'Visitors'),
                data: data.visitors || [],
                borderColor: 'rgba(118, 75, 162, 1)',
                backgroundColor: 'rgba(118, 75, 162, 0.1)',
                fill: true,
                tension: 0.3,
                pointRadius: 2,
                pointHoverRadius: 5,
                pointBackgroundColor: 'rgba(118, 75, 162, 1)',
            }],
        },
        options: {
            ...defaults,
            interaction: { intersect: false, mode: 'index' },
        },
    });
}