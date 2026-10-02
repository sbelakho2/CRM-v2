/**
 * Smart Notification System - Frontend JavaScript
 * Polls the Command Center alert endpoints for the sidebar badge and
 * renders recent alerts in the bell dropdown.
 *
 * Endpoints (wired via the notification_bell template):
 *   count  -> command_center_alert_count  (GET /command-center/alert-count)
 *   alerts -> command_center_alerts       (GET /command-center/alerts)
 */

class NotificationSystem {
    constructor() {
        this.modal = document.getElementById('notification-modal');
        this.bell = document.getElementById('notification-bell');
        this.badge = document.getElementById('notification-badge');
        this.list = document.getElementById('notification-list');
        this.countSpan = document.getElementById('notification-count');
        this.closeBtn = document.getElementById('notification-close');

        this.endpoints = window.NOTIFICATION_ENDPOINTS || {};
        this.pollingInterval = null;
        this.alertCache = [];

        this.init();
    }

    /**
     * Initialize notification system
     */
    init() {
        if (!this.bell) return;
        this.emptyText = (this.endpoints && this.endpoints.emptyText) || 'No notifications';
        this.attachEventListeners();
        this.startPolling();
    }

    /**
     * Attach event listeners
     */
    attachEventListeners() {
        // Bell click - toggle modal
        this.bell.addEventListener('click', (e) => {
            e.stopPropagation();
            this.toggleModal();
        });

        // Close modal
        if (this.closeBtn) {
            this.closeBtn.addEventListener('click', () => this.closeModal());
        }

        // Close modal when clicking outside
        document.addEventListener('click', (e) => {
            if (this.modal && !this.modal.contains(e.target) && !this.bell.contains(e.target)) {
                this.closeModal();
            }
        });

        // Keyboard: ESC to close modal
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeModal();
            }
        });
    }

    /**
     * Start polling for notifications
     */
    startPolling() {
        this.fetchCount();
        this.fetchAlerts();

        this.pollingInterval = setInterval(() => {
            this.fetchCount();
        }, 30000);
    }

    /**
     * Stop polling
     */
    stopPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }
    }

    /**
     * Fetch the unread badge count from the Command Center alert endpoint
     */
    async fetchCount() {
        if (!this.endpoints.count) return;
        try {
            const response = await fetch(this.endpoints.count, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                if (response.status === 401) {
                    this.stopPolling();
                    return;
                }
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            this.updateBadge(parseInt(data.count || data.unread_count || 0, 10));
        } catch (error) {
            console.error('[Notifications] Count fetch error:', error);
        }
    }

    /**
     * Fetch recent alerts for the dropdown
     */
    async fetchAlerts() {
        if (!this.endpoints.alerts) return;
        try {
            const response = await fetch(this.endpoints.alerts, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            this.alertCache = Array.isArray(data.alerts) ? data.alerts : [];

            if (this.modal && !this.modal.classList.contains('rams-hidden')) {
                this.renderAlertList(this.alertCache);
            }
        } catch (error) {
            console.error('[Notifications] Alerts fetch error:', error);
        }
    }

    /**
     * Update badge count
     */
    updateBadge(count) {
        if (!this.countSpan || !this.badge) return;
        this.countSpan.textContent = count;

        if (count > 0) {
            this.badge.classList.remove('rams-hidden');
        } else {
            this.badge.classList.add('rams-hidden');
        }
    }

    /**
     * Render alert list
     */
    renderAlertList(alerts) {
        if (!this.list) return;

        if (alerts.length === 0) {
            this.list.innerHTML = `
                <div class="rams-notification__empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                    </svg>
                    <p>${this.escapeHTML(this.emptyText || 'No notifications')}</p>
                </div>
            `;
            return;
        }

        // Real anchors: keyboard-focusable, middle-clickable, no JS navigation
        this.list.innerHTML = alerts
            .map(alert => this.createAlertHTML(alert))
            .join('');
    }

    /**
     * Create HTML for an alert item
     */
    createAlertHTML(alert) {
        const severity = alert.severity === 'critical' ? 'error' : (alert.severity === 'warning' ? 'warning' : 'info');
        const light = severity === 'error' ? 'red' : (severity === 'warning' ? 'yellow' : 'off');
        const message = alert.message || alert.title || '';
        const tag = alert.link ? 'a' : 'div';
        const href = alert.link ? ` href="${this.escapeHTML(alert.link)}"` : '';

        return `
            <${tag} class="rams-notification__item rams-notification__item--${severity}"${href}>
                <span class="rams-andon__light rams-andon__light--${light}" aria-hidden="true"></span>
                <span class="rams-notification__content">
                    <span class="rams-notification__message">${this.escapeHTML(message)}</span>
                    <span class="rams-notification__timestamp">${this.formatTimestamp(alert.timestamp)}</span>
                </span>
            </${tag}>
        `;
    }

    /**
     * Toggle modal visibility
     */
    toggleModal() {
        if (!this.modal) return;
        if (this.modal.classList.contains('rams-hidden')) {
            this.openModal();
        } else {
            this.closeModal();
        }
    }

    /**
     * Open modal
     */
    openModal() {
        if (!this.modal) return;
        this.modal.classList.remove('rams-hidden');
        this.renderAlertList(this.alertCache);
        this.fetchAlerts();
        if (this.closeBtn) this.closeBtn.focus();
    }

    /**
     * Close modal
     */
    closeModal() {
        if (!this.modal) return;
        this.modal.classList.add('rams-hidden');
        if (this.bell) this.bell.focus();
    }

    /**
     * Format timestamp relative to now, in the page locale (en/fr/ar)
     */
    formatTimestamp(isoString) {
        if (!isoString) return '';
        const date = new Date(isoString);
        if (isNaN(date.getTime())) return '';
        const diffMs = Date.now() - date.getTime();
        const rtf = (typeof Intl !== 'undefined' && Intl.RelativeTimeFormat)
            ? new Intl.RelativeTimeFormat(document.documentElement.lang || 'en', { numeric: 'auto' })
            : null;

        if (rtf) {
            const minutes = Math.round(diffMs / 60000);
            if (Math.abs(minutes) < 60) return rtf.format(-minutes, 'minute');
            const hours = Math.round(diffMs / 3600000);
            if (Math.abs(hours) < 24) return rtf.format(-hours, 'hour');
            const days = Math.round(diffMs / 86400000);
            if (Math.abs(days) < 7) return rtf.format(-days, 'day');
        } else {
            const diffMins = Math.floor(diffMs / 60000);
            if (diffMins < 1) return 'just now';
            if (diffMins < 60) return `${diffMins}m ago`;
            const diffHours = Math.floor(diffMs / 3600000);
            if (diffHours < 24) return `${diffHours}h ago`;
        }

        return date.toLocaleDateString(document.documentElement.lang || undefined);
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHTML(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    /**
     * Destroy notification system
     */
    destroy() {
        this.stopPolling();
        this.closeModal();
    }
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    window.notificationSystem = new NotificationSystem();
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (window.notificationSystem) {
        window.notificationSystem.destroy();
    }
});
