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
                <div class="rams-p-4 rams-text--center">
                    <p class="rams-dymo">${this.escapeHTML(this.emptyText || 'No notifications')}</p>
                </div>
            `;
            return;
        }

        this.list.innerHTML = alerts
            .map(alert => this.createAlertHTML(alert))
            .join('');

        // Attach click listeners to alert items
        this.list.querySelectorAll('.rams-notification__item').forEach(item => {
            item.addEventListener('click', () => this.handleAlertClick(item));
        });
    }

    /**
     * Create HTML for an alert item
     */
    createAlertHTML(alert) {
        const severity = alert.severity === 'critical' ? 'rams-alert--error' : (alert.severity === 'warning' ? 'rams-alert--warning' : 'rams-alert--info');
        const message = alert.message || alert.title || '';

        return `
            <div class="rams-notification__item ${severity}" data-alert-link="${this.escapeHTML(alert.link || '')}">
                <div class="rams-notification__item-header">
                    <div class="rams-notification__icon">
                        <span class="rams-andon__light rams-andon__light--${severity === 'rams-alert--error' ? 'red' : (severity === 'rams-alert--warning' ? 'yellow' : 'green')}"></span>
                    </div>
                    <div class="rams-notification__content">
                        <p class="rams-notification__message">${this.escapeHTML(message)}</p>
                        <p class="rams-notification__timestamp">${this.formatTimestamp(alert.timestamp)}</p>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Handle alert item click - navigate to the alert link when available
     */
    handleAlertClick(item) {
        const link = item.getAttribute('data-alert-link');
        if (link) {
            window.location.href = link;
        }
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
    }

    /**
     * Close modal
     */
    closeModal() {
        if (!this.modal) return;
        this.modal.classList.add('rams-hidden');
    }

    /**
     * Format timestamp (e.g., "2 minutes ago")
     */
    formatTimestamp(isoString) {
        if (!isoString) return '';
        const date = new Date(isoString);
        if (isNaN(date.getTime())) return '';
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return 'just now';
        if (diffMins < 60) return `${diffMins}m ago`;
        if (diffHours < 24) return `${diffHours}h ago`;
        if (diffDays < 7) return `${diffDays}d ago`;

        return date.toLocaleDateString();
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
