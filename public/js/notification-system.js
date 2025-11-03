/**
 * Smart Notification System - Frontend JavaScript
 * Handles polling, modal interactions, and toast notifications
 */

class NotificationSystem {
    constructor() {
        this.modal = document.getElementById('notification-modal');
        this.bell = document.getElementById('notification-bell');
        this.badge = document.getElementById('notification-badge');
        this.list = document.getElementById('notification-list');
        this.countSpan = document.getElementById('notification-count');
        this.markAllBtn = document.getElementById('notification-mark-all');
        this.closeBtn = document.getElementById('notification-close');
        
        this.pollingInterval = null;
        this.toastStack = [];
        this.notificationCache = [];
        
        this.init();
    }

    /**
     * Initialize notification system
     */
    init() {
        this.attachEventListeners();
        this.startPolling();
        this.restoreFromCache();
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

        // Mark all as read
        if (this.markAllBtn) {
            this.markAllBtn.addEventListener('click', () => this.markAllAsRead());
        }

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
        // Initial fetch
        this.fetchNotifications();

        // Poll every 30 seconds
        this.pollingInterval = setInterval(() => {
            this.fetchNotifications();
        }, 30000);

        console.log('[Notifications] Polling started (every 30s)');
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
     * Fetch notifications from API
     */
    async fetchNotifications() {
        try {
            const response = await fetch('/api/notifications', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                if (response.status === 401) {
                    // User not authenticated - stop polling
                    this.stopPolling();
                    return;
                }
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            this.handleNotifications(data);
        } catch (error) {
            console.error('[Notifications] Fetch error:', error);
        }
    }

    /**
     * Handle fetched notifications
     */
    handleNotifications(data) {
        const unreadCount = data.unread_count || 0;
        const notifications = data.notifications || [];

        // Update badge
        this.updateBadge(unreadCount);

        // Cache notifications
        this.notificationCache = notifications;

        // Update list if modal is open
        if (!this.modal.classList.contains('hidden')) {
            this.renderNotificationList(notifications);
        }
    }

    /**
     * Update badge count
     */
    updateBadge(count) {
        this.countSpan.textContent = count;

        if (count > 0) {
            this.badge.classList.remove('hidden');
        } else {
            this.badge.classList.add('hidden');
        }
    }

    /**
     * Render notification list
     */
    renderNotificationList(notifications) {
        if (notifications.length === 0) {
            this.list.innerHTML = `
                <div class="px-4 py-8 text-center text-neutral-500">
                    <p class="text-sm">No notifications</p>
                </div>
            `;
            return;
        }

        this.list.innerHTML = notifications
            .map(notif => this.createNotificationHTML(notif))
            .join('');

        // Attach click listeners to notification items
        this.list.querySelectorAll('.notification-item').forEach((item, index) => {
            item.addEventListener('click', () => this.handleNotificationClick(notifications[index]));
        });
    }

    /**
     * Create HTML for notification item
     */
    createNotificationHTML(notif) {
        const isRead = notif.readAt !== null;
        const timestamp = this.formatTimestamp(notif.createdAt);

        return `
            <div class="notification-item ${isRead ? 'read' : ''}" data-notification-id="${notif.id}">
                <div class="notification-item-header">
                    <span class="notification-icon">${notif.icon || '📬'}</span>
                    <div class="notification-content">
                        <p class="notification-message">${this.escapeHTML(notif.message)}</p>
                        <p class="notification-timestamp">${timestamp}</p>
                    </div>
                    ${!isRead ? '<div class="notification-unread-indicator"></div>' : ''}
                </div>
            </div>
        `;
    }

    /**
     * Handle notification item click
     */
    async handleNotificationClick(notif) {
        if (notif.readAt === null) {
            await this.markAsRead(notif.id);
        }

        // Navigate to related entity if available
        if (notif.entityType && notif.entityId) {
            this.navigateToEntity(notif.entityType, notif.entityId);
        }
    }

    /**
     * Mark notification as read
     */
    async markAsRead(notificationId) {
        try {
            const response = await fetch(`/api/notifications/${notificationId}/read`, {
                method: 'PUT',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            // Re-fetch to update UI
            this.fetchNotifications();
        } catch (error) {
            console.error('[Notifications] Mark as read error:', error);
        }
    }

    /**
     * Mark all as read
     */
    async markAllAsRead() {
        try {
            const response = await fetch('/api/notifications/mark-all-read', {
                method: 'PUT',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            // Re-fetch to update UI
            this.fetchNotifications();
            console.log('[Notifications] All marked as read');
        } catch (error) {
            console.error('[Notifications] Mark all as read error:', error);
        }
    }

    /**
     * Toggle modal visibility
     */
    toggleModal() {
        if (this.modal.classList.contains('hidden')) {
            this.openModal();
        } else {
            this.closeModal();
        }
    }

    /**
     * Open modal
     */
    openModal() {
        this.modal.classList.remove('hidden');
        this.renderNotificationList(this.notificationCache);
        console.log('[Notifications] Modal opened');
    }

    /**
     * Close modal
     */
    closeModal() {
        this.modal.classList.add('hidden');
        console.log('[Notifications] Modal closed');
    }

    /**
     * Show toast notification
     */
    showToast(icon, title, message, duration = 5000) {
        const toastId = `toast-${Date.now()}`;
        
        const toast = document.createElement('div');
        toast.id = toastId;
        toast.className = 'toast-notification';
        toast.innerHTML = `
            <div class="toast-header">
                <span class="toast-icon">${icon}</span>
                <span class="toast-title">${this.escapeHTML(title)}</span>
            </div>
            <p class="toast-message">${this.escapeHTML(message)}</p>
        `;

        document.body.appendChild(toast);
        this.toastStack.push(toastId);

        // Auto-dismiss
        setTimeout(() => {
            this.dismissToast(toastId);
        }, duration);

        console.log(`[Notifications] Toast shown: ${title}`);

        return toastId;
    }

    /**
     * Dismiss toast
     */
    dismissToast(toastId) {
        const toast = document.getElementById(toastId);
        if (toast) {
            toast.classList.add('fade-out');
            setTimeout(() => {
                toast.remove();
                this.toastStack = this.toastStack.filter(id => id !== toastId);
            }, 300);
        }
    }

    /**
     * Navigate to entity
     */
    navigateToEntity(entityType, entityId) {
        const routes = {
            'RFQ': `/rfq/${entityId}`,
            'Email': `/email/${entityId}`,
            'Lead': `/lead/${entityId}`,
            'Quote': `/quote/${entityId}`,
            'Company': `/company/${entityId}`
        };

        const route = routes[entityType];
        if (route) {
            window.location.href = route;
        }
    }

    /**
     * Format timestamp (e.g., "2 minutes ago")
     */
    formatTimestamp(isoString) {
        const date = new Date(isoString);
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
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Save to localStorage cache
     */
    saveToCache() {
        try {
            localStorage.setItem(
                'notifications_cache',
                JSON.stringify({
                    timestamp: Date.now(),
                    notifications: this.notificationCache
                })
            );
        } catch (error) {
            console.warn('[Notifications] Cache save error:', error);
        }
    }

    /**
     * Restore from localStorage cache
     */
    restoreFromCache() {
        try {
            const cached = localStorage.getItem('notifications_cache');
            if (cached) {
                const data = JSON.parse(cached);
                // Only use cache if less than 1 minute old
                if (Date.now() - data.timestamp < 60000) {
                    this.notificationCache = data.notifications;
                    this.updateBadge(data.notifications.length);
                }
            }
        } catch (error) {
            console.warn('[Notifications] Cache restore error:', error);
        }
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
    console.log('[Notifications] System initialized');
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (window.notificationSystem) {
        window.notificationSystem.destroy();
    }
});
