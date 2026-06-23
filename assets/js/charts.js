/**
 * charts.js - Countr Chart Entry Point (ES6 Module)
 *
 * Modular chart system. Imports from charts/ submodules and
 * binds the public API to window.WBCharts for backward compatibility
 * with existing inline scripts.
 *
 * @package Countr
 * @version 1.6.0
 */

import { initCharts, fetchChartData } from './charts/loader.js';
import { create7DayChart } from './charts/bar.js';
import { createHourlyChart, create30DayChart } from './charts/line.js';
import { createBrowserChart } from './charts/doughnut.js';
import { createPagesChart } from './charts/pages.js';
import { updateChartThemes, destroyAllCharts } from './charts/core.js';

// =========================================================================
// PUBLIC API – bind to window for backward compatibility with inline scripts
// =========================================================================
window.WBCharts = {
    create7DayChart,
    createBrowserChart,
    createHourlyChart,
    createPagesChart,
    create30DayChart,
    loadAllCharts: initCharts,
    fetchChartData,
    updateChartThemes,
    destroyAllCharts,
};

// Auto-init
initCharts();