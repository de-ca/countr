/**
 * admin/ui.js - Admin UI Logic
 *
 * Tab switching, mobile sidebar toggle, date range filters,
 * confirm dialogs, theme management, and admin auto-refresh.
 *
 * @package Countr
 * @version 1.6.0
 */

import { t, getChartDefaults, updateAdminChartThemes } from './core.js';
import { renderAdminCharts, renderPagesChart, renderReferrersChart } from './charts.js';

// Active tab tracker
let activeTab = 'overview';

/**
 * Get the currently active tab.
 * @returns {string}
 */
export function getActiveTab() {
    return activeTab;
}

/**
 * Initialize tab navigation.
 */
export function initTabs() {
    const tabButtons = document.querySelectorAll('.tab-btn');
    const tabPanels = document.querySelectorAll('.tab-panel');

    tabButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            const tabId = this.getAttribute('data-tab');
            if (!tabId) return;

            // Update buttons
            tabButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            // Update panels
            tabPanels.forEach(panel => panel.classList.remove('active'));
            const panel = document.getElementById('tab-' + tabId);
            if (panel) {
                panel.classList.add('active');
            }

            activeTab = tabId;

            // Update URL hash
            if (history.replaceState) {
                history.replaceState(null, '', '#' + tabId);
            }

            // Render charts when switching to visitors/pages/referrers tabs
            if (tabId === 'visitors') {
                setTimeout(renderAdminCharts, 100);
            } else if (tabId === 'pages') {
                setTimeout(renderPagesChart, 100);
            } else if (tabId === 'referrers') {
                setTimeout(renderReferrersChart, 100);
            }
        });
    });

    // Restore active tab from URL hash only (always default to Overview on reload)
    const hash = window.location.hash.replace('#', '');
    const targetTab = hash || 'overview';

    const targetBtn = document.querySelector(`.tab-btn[data-tab="${targetTab}"]`);
    if (targetBtn) {
        targetBtn.click();
    }
}

/**
 * Initialize mobile sidebar toggle.
 */
export function initSidebar() {
    const menuToggle = document.getElementById('menu-toggle');
    const sidebar = document.getElementById('admin-sidebar');
    const overlay = document.getElementById('sidebar-overlay');

    if (!menuToggle || !sidebar || !overlay) return;

    function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('open');
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
    }

    menuToggle.addEventListener('click', openSidebar);
    overlay.addEventListener('click', closeSidebar);

    // Close sidebar on window resize (if desktop width)
    window.addEventListener('resize', function () {
        if (window.innerWidth > 900) {
            closeSidebar();
        }
    });
}

/**
 * Initialize date range filters.
 */
export function initDateFilter() {
    const filterForm = document.getElementById('date-filter-form');
    if (!filterForm) return;

    // Set default values if empty
    const fromInput = document.getElementById('filter-from');
    const toInput = document.getElementById('filter-to');

    if (fromInput && !fromInput.value) {
        const d = new Date();
        d.setDate(d.getDate() - 7);
        fromInput.value = d.toISOString().split('T')[0];
    }
    if (toInput && !toInput.value) {
        toInput.value = new Date().toISOString().split('T')[0];
    }

    // Quick date presets
    document.querySelectorAll('.date-preset').forEach(btn => {
        btn.addEventListener('click', function () {
            const days = parseInt(this.getAttribute('data-days'), 10);
            if (!days) return;

            const to = new Date();
            const from = new Date();
            from.setDate(from.getDate() - days);

            if (fromInput) fromInput.value = from.toISOString().split('T')[0];
            if (toInput) toInput.value = to.toISOString().split('T')[0];

            filterForm.submit();
        });
    });
}

/**
 * Add confirm dialogs to data-confirm buttons.
 */
export function initConfirmDialogs() {
    document.querySelectorAll('[data-confirm]').forEach(btn => {
        btn.addEventListener('click', function (e) {
            const message = this.getAttribute('data-confirm') || 'Sind Sie sicher?';
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
    });
}

/**
 * Initialize admin dashboard auto-refresh.
 */
export function initAdminRefresh() {
    const refreshBtn = document.getElementById('admin-refresh-btn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            window.location.reload();
        });
    }

    // Auto-refresh every 60 seconds for overview tab
    setInterval(() => {
        if (activeTab === 'overview' && !document.hidden) {
            fetchAdminLiveData();
        }
    }, 60000);
}

/**
 * Fetch live admin data (online count).
 */
async function fetchAdminLiveData() {
    try {
        const resp = await fetch('admin.php?ajax=1');
        if (!resp.ok) return;
        const data = await resp.json();

        // Update online count
        const onlineEl = document.getElementById('admin-online-count');
        if (onlineEl && data.online !== undefined) {
            onlineEl.textContent = data.online;
        }
    } catch (e) {
        // Silently ignore
    }
}

/**
 * Initialize theme toggle in admin.
 */
export function initAdminTheme() {
    const toggleBtn = document.getElementById('theme-toggle');
    if (!toggleBtn) return;

    function getStoredTheme() {
        try { return localStorage.getItem('wb-theme') || 'light'; } catch (e) { return 'light'; }
    }

    function setStoredTheme(theme) {
        try { localStorage.setItem('wb-theme', theme); } catch (e) {}
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        setStoredTheme(theme);
        const darkLabel = t('dark', '🌙 Dunkel');
        const lightLabel = t('light', '☀️ Hell');
        toggleBtn.innerHTML = theme === 'dark' ? lightLabel : darkLabel;

        // Update charts
        setTimeout(updateAdminChartThemes, 100);
    }

    // Initialize
    applyTheme(getStoredTheme());

    toggleBtn.addEventListener('click', function () {
        const current = document.documentElement.getAttribute('data-theme') || 'light';
        applyTheme(current === 'dark' ? 'light' : 'dark');
    });
}