/**
 * Countr - Chart Initialization v1.5.0
 * Creates and renders all charts on the dashboard using Chart.js 4.x
 * Hardened: accepts multiple data shapes without crashing.
 */
(function () {
    'use strict';

    /**
     * Safely extract labels/values from CountrData for a given key.
     * Handles old array format [{date, visitors, pageviews}],
     * new {labels, visitors, pageviews} format, and missing data.
     *
     * @param {string} key  – key in window.CountrData (e.g. 'last7Days', 'browsers')
     * @param {string} mode – 'timeseries' or 'distribution'
     * @returns {{ labels: string[], datasets: object[] }}
     */
    function safeExtract(key, mode) {
        var empty = { labels: [], datasets: [] };
        if (!window.CountrData) return empty;

        var raw = window.CountrData[key];
        if (!raw) return empty;

        try {
            if (mode === 'timeseries') {
                // New format: { labels: [...], visitors: [...], pageviews: [...] }
                if (raw.labels && Array.isArray(raw.labels)) {
                    return {
                        labels: raw.labels,
                        datasets: [
                            { label: 'Besucher',  data: raw.visitors || [] },
                            { label: 'Pageviews', data: raw.pageviews || [] },
                        ],
                    };
                }
                // Old format: [{ date: '...', visitors: N, pageviews: N }, ...]
                if (Array.isArray(raw) && raw.length > 0) {
                    var lbls = [];
                    var vis  = [];
                    var pvs  = [];
                    raw.forEach(function (d) {
                        if (d && d.date) {
                            var parts = String(d.date).split('-');
                            lbls.push(parts[2] + '.' + parts[1] + '.');
                        }
                        vis.push(Number(d && d.visitors) || 0);
                        pvs.push(Number(d && d.pageviews) || 0);
                    });
                    return { labels: lbls, datasets: [
                        { label: 'Besucher',  data: vis },
                        { label: 'Pageviews', data: pvs },
                    ]};
                }
                return empty;
            }

            if (mode === 'distribution') {
                // New format: { labels: [...], values: [...] }
                if (raw.labels && Array.isArray(raw.labels) && raw.values && Array.isArray(raw.values)) {
                    return {
                        labels: raw.labels,
                        datasets: [{ label: key, data: raw.values }],
                    };
                }
                // Old format: { 'Chrome': 5, 'Firefox': 3 }
                if (typeof raw === 'object' && !Array.isArray(raw)) {
                    var keys = Object.keys(raw);
                    var vals = keys.map(function (k) { return Number(raw[k]) || 0; });
                    return {
                        labels: keys.length ? keys : ['Keine Daten'],
                        datasets: [{ label: key, data: vals.length ? vals : [1] }],
                    };
                }
                return empty;
            }

            return empty;
        } catch (e) {
            console.warn('[Countr] chart.js error extracting "' + key + '":', e.message);
            return empty;
        }
    }

    function initCharts() {
        if (typeof Chart === 'undefined') {
            setTimeout(initCharts, 100);
            return;
        }

        var colors = {
            primary: '#667eea',
            primaryAlpha: 'rgba(102, 126, 234, 0.3)',
            secondary: '#764ba2',
            success: '#28a745',
            danger: '#dc3545',
            warning: '#ffc107',
            info: '#17a2b8',
        };

        var commonOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        padding: 20,
                        font: { size: 12, family: 'system-ui, sans-serif' },
                    },
                },
                tooltip: {
                    backgroundColor: 'rgba(26, 26, 46, 0.9)',
                    cornerRadius: 6,
                    padding: 10,
                    titleFont: { size: 13 },
                    bodyFont: { size: 12 },
                },
            },
            interaction: { intersect: false, mode: 'index' },
        };

        try {
            // Chart 1: Last 7 Days
            var ctx7days = document.getElementById('chart7days');
            if (ctx7days) {
                var d7 = safeExtract('last7Days', 'timeseries');
                if (d7.labels.length > 0) {
                    new Chart(ctx7days, {
                        type: 'bar',
                        data: {
                            labels: d7.labels,
                            datasets: [
                                {
                                    label: 'Besucher',
                                    data: (d7.datasets[0] && d7.datasets[0].data) ? d7.datasets[0].data : [],
                                    backgroundColor: colors.primary,
                                    borderRadius: 6,
                                    borderSkipped: false,
                                    order: 1,
                                },
                                {
                                    label: 'Pageviews',
                                    data: (d7.datasets[1] && d7.datasets[1].data) ? d7.datasets[1].data : [],
                                    backgroundColor: colors.primaryAlpha,
                                    borderRadius: 6,
                                    borderSkipped: false,
                                    order: 2,
                                },
                            ],
                        },
                        options: Object.assign({}, commonOptions, {
                            scales: {
                                x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.06)' }, ticks: { font: { size: 11 } } },
                            },
                        }),
                    });
                }
            }

            // Chart 2: Hourly Distribution
            var ctxHourly = document.getElementById('chartHourly');
            if (ctxHourly) {
                var dh = safeExtract('hourly', 'distribution');
                var hourLabels = [];
                var hourVisitors = [];
                if (dh.labels.length > 0 && dh.datasets[0] && dh.datasets[0].data.length > 0) {
                    hourLabels = dh.labels;
                    hourVisitors = dh.datasets[0].data;
                } else {
                    // Build empty 24h skeleton
                    for (var h = 0; h < 24; h++) {
                        hourLabels.push(String(h).padStart(2, '0') + ':00');
                        hourVisitors.push(0);
                    }
                }
                new Chart(ctxHourly, {
                    type: 'bar',
                    data: {
                        labels: hourLabels,
                        datasets: [{
                            label: 'Besucher',
                            data: hourVisitors,
                            backgroundColor: colors.secondary,
                            borderRadius: 4,
                            borderSkipped: false,
                        }],
                    },
                    options: Object.assign({}, commonOptions, {
                        scales: {
                            x: { grid: { display: false }, ticks: { font: { size: 10 }, maxTicksLimit: 12 } },
                            y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.06)' }, ticks: { font: { size: 11 } } },
                        },
                        plugins: Object.assign({}, commonOptions.plugins, { legend: { display: false } }),
                    }),
                });
            }

            // Chart 3: Last 30 Days
            var ctx30days = document.getElementById('chart30days');
            if (ctx30days) {
                var d30 = safeExtract('last30Days', 'timeseries');
                if (d30.labels.length > 0) {
                    new Chart(ctx30days, {
                        type: 'line',
                        data: {
                            labels: d30.labels,
                            datasets: [
                                {
                                    label: 'Besucher',
                                    data: (d30.datasets[0] && d30.datasets[0].data) ? d30.datasets[0].data : [],
                                    borderColor: colors.primary,
                                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                    fill: true,
                                    tension: 0.3,
                                    pointRadius: 2,
                                    pointHoverRadius: 5,
                                    pointBackgroundColor: colors.primary,
                                },
                                {
                                    label: 'Pageviews',
                                    data: (d30.datasets[1] && d30.datasets[1].data) ? d30.datasets[1].data : [],
                                    borderColor: colors.secondary,
                                    backgroundColor: 'rgba(118, 75, 162, 0.1)',
                                    fill: true,
                                    tension: 0.3,
                                    pointRadius: 2,
                                    pointHoverRadius: 5,
                                    pointBackgroundColor: colors.secondary,
                                },
                            ],
                        },
                        options: Object.assign({}, commonOptions, {
                            scales: {
                                x: { grid: { display: false }, ticks: { font: { size: 10 }, maxTicksLimit: 15 } },
                                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.06)' }, ticks: { font: { size: 11 } } },
                            },
                        }),
                    });
                }
            }

            // Chart 4: Browser Distribution (Doughnut)
            var ctxBrowsers = document.getElementById('chartBrowsers');
            if (ctxBrowsers) {
                var db = safeExtract('browsers', 'distribution');
                var browserLabels = db.labels.length > 0 ? db.labels : ['Keine Daten'];
                var browserValues = (db.datasets[0] && db.datasets[0].data.length > 0) ? db.datasets[0].data : [1];

                var doughnutColors = [
                    colors.primary, colors.secondary, colors.success,
                    colors.warning, colors.danger, colors.info,
                    '#e83e8c', '#fd7e14',
                ];

                new Chart(ctxBrowsers, {
                    type: 'doughnut',
                    data: {
                        labels: browserLabels,
                        datasets: [{
                            data: browserValues,
                            backgroundColor: (browserValues.length > 1 || browserLabels[0] !== 'Keine Daten')
                                ? doughnutColors.slice(0, Math.max(browserLabels.length, browserValues.length))
                                : ['#e0e0e0'],
                            borderWidth: 2,
                            borderColor: '#fff',
                        }],
                    },
                    options: Object.assign({}, commonOptions, {
                        cutout: '60%',
                        plugins: Object.assign({}, commonOptions.plugins, {
                            legend: {
                                position: 'bottom',
                                labels: { usePointStyle: true, padding: 15, font: { size: 11 } },
                            },
                        }),
                    }),
                });
            }
        } catch (e) {
            console.warn('[Countr] chart.js render failed:', e.message);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCharts);
    } else {
        initCharts();
    }
})();