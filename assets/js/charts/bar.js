/**
 * charts/bar.js - Bar Chart (7-Day Visitor Trend)
 *
 * Creates the 7-day visitor + pageviews bar chart.
 *
 * @package Countr
 * @version 1.6.0
 */

import { t, getChartDefaults, destroyChart, getCharts } from './core.js';

/**
 * Create or update the 7-day visitor trend chart.
 * @param {object} data - { labels: string[], visitors: number[], pageviews: number[] }
 */
export function create7DayChart(data) {
    destroyChart('chart7days');

    const canvas = document.getElementById('chart7days');
    if (!canvas) return;

    const defaults = getChartDefaults();
    const ctx = canvas.getContext('2d');
    const charts = getCharts();

    charts.chart7days = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.labels || [],
            datasets: [
                {
                    label: t('visitors', 'Visitors'),
                    data: data.visitors || [],
                    backgroundColor: 'rgba(102, 126, 234, 0.7)',
                    borderColor: 'rgba(102, 126, 234, 1)',
                    borderWidth: 1,
                    borderRadius: 4,
                },
                {
                    label: t('pageviews', 'Pageviews'),
                    data: data.pageviews || [],
                    backgroundColor: 'rgba(118, 75, 162, 0.5)',
                    borderColor: 'rgba(118, 75, 162, 1)',
                    borderWidth: 1,
                    borderRadius: 4,
                },
            ],
        },
        options: {
            ...defaults,
            interaction: { intersect: false, mode: 'index' },
        },
    });
}