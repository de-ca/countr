/**
 * admin.js - Countr Admin Entry Point (ES6 Module)
 *
 * Orchestrates all admin submodules: UI, settings, charts.
 * Binds the public API to window.WBAdmin for backward compatibility
 * with existing inline scripts.
 *
 * @package Countr
 * @version 1.6.0
 */

import { renderAdminCharts, renderPagesChart, renderReferrersChart } from './admin/charts.js';
import { getActiveTab } from './admin/ui.js';
import { initTabs, initSidebar, initDateFilter, initConfirmDialogs, initAdminRefresh, initAdminTheme } from './admin/ui.js';
import { initSettingsForm, initExportButtons, initBackupTools, initToolActions, triggerExport } from './admin/settings.js';

/**
 * Safely call an init function, catching errors so one failure
 * does not block the rest of the admin panel from loading.
 *
 * @param {string} name   Display name for error logging
 * @param {Function} fn   Initializer to call
 */
function safeInit(name, fn) {
    try {
        fn();
    } catch (e) {
        console.error('[Countr Admin] Init failed for ' + name + ':', e);
    }
}

/**
 * Initialize the admin panel.
 */
function init() {
    safeInit('tabs', initTabs);
    safeInit('sidebar', initSidebar);
    safeInit('dateFilter', initDateFilter);
    safeInit('confirmDialogs', initConfirmDialogs);
    safeInit('settingsForm', initSettingsForm);
    safeInit('exportButtons', initExportButtons);
    safeInit('backupTools', initBackupTools);
    safeInit('toolActions', initToolActions);
    safeInit('adminRefresh', initAdminRefresh);
    safeInit('adminTheme', initAdminTheme);

    // Render charts if the Visitors tab is initially active (from URL hash)
    const activeTab = getActiveTab();
    if (activeTab === 'visitors') {
        setTimeout(renderAdminCharts, 200);
    } else if (activeTab === 'pages') {
        setTimeout(renderPagesChart, 200);
    } else if (activeTab === 'referrers') {
        setTimeout(renderReferrersChart, 200);
    }
}

// =========================================================================
// PUBLIC API – bind to window for backward compatibility with inline scripts
// =========================================================================
window.WBAdmin = {
    init,
    triggerExport,
    renderAdminCharts,
    renderPagesChart,
    renderReferrersChart,
    switchTab: function (tabId) {
        const btn = document.querySelector(`.tab-btn[data-tab="${tabId}"]`);
        if (btn) btn.click();
    },
};

// Auto-init
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}