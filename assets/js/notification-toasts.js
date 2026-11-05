/**
 * Bottom-right notification toasts.
 *
 * The stack element supplies the feed URL through data-feed. Staff and the
 * wholesale portal share this script so notifications behave the same way.
 */
(function () {
    'use strict';

    var stack = document.getElementById('appToastStack');
    if (!stack || typeof window.fetch !== 'function') return;

    var feedUrl = stack.getAttribute('data-feed') || 'notifications-feed.php';
    var badge = document.getElementById('topbarNotifyBadge');
    var badgeParent = document.getElementById('wsNotifyNav');
    var seen = {};
    var polling = false;

    if (!badge && badgeParent) {
        badge = badgeParent.querySelector('.ws-nav-badge');
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'ws-nav-badge';
            badge.id = 'wsNavNotifyBadge';
            badge.hidden = true;
            badgeParent.appendChild(badge);
        }
    }

    function updateBadge(count) {
        count = parseInt(count, 10) || 0;
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.hidden = false;
        } else {
            badge.textContent = '';
            badge.hidden = true;
        }
    }

    function safeIcon(icon) {
        icon = String(icon || 'fa-bell');
        return /^fa-[a-z0-9-]+$/i.test(icon) ? icon : 'fa-bell';
    }

    function dismiss(toast) {
        if (!toast || toast.classList.contains('is-leaving')) return;
        toast.classList.add('is-leaving');
        window.setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 260);
    }

    function showToast(item) {
        if (!item || seen[item.id]) return;
        seen[item.id] = true;

        var toast = document.createElement('div');
        toast.className = 'app-toast';
        toast.setAttribute('role', 'status');

        var icon = document.createElement('span');
        icon.className = 'app-toast-icon';
        var iconGlyph = document.createElement('i');
        iconGlyph.className = 'fa ' + safeIcon(item.icon);
        iconGlyph.setAttribute('aria-hidden', 'true');
        icon.appendChild(iconGlyph);

        var content = document.createElement('div');
        content.className = 'app-toast-content';

        var title = document.createElement('div');
        title.className = 'app-toast-title';
        title.textContent = String(item.title || 'Notification');
        content.appendChild(title);

        if (item.message) {
            var message = document.createElement('div');
            message.className = 'app-toast-message';
            message.textContent = String(item.message);
            content.appendChild(message);
        }

        var time = document.createElement('div');
        time.className = 'app-toast-time';
        time.textContent = String(item.time || 'Just now');
        content.appendChild(time);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'app-toast-close';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.innerHTML = '&times;';
        close.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            dismiss(toast);
        });

        toast.appendChild(icon);
        toast.appendChild(content);
        toast.appendChild(close);

        var link = String(item.link || '');
        if (link) {
            toast.classList.add('is-clickable');
            toast.setAttribute('tabindex', '0');
            var openUrl = String(item.open || ('notification-open.php?id=' + encodeURIComponent(item.id)));
            var open = function () {
                window.location.href = openUrl;
            };
            toast.addEventListener('click', open);
            toast.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    open();
                }
            });
        }

        stack.appendChild(toast);
        window.requestAnimationFrame(function () {
            toast.classList.add('is-visible');
        });

        var timer = window.setTimeout(function () { dismiss(toast); }, 7000);
        toast.addEventListener('mouseenter', function () {
            window.clearTimeout(timer);
        });
        toast.addEventListener('mouseleave', function () {
            timer = window.setTimeout(function () { dismiss(toast); }, 2500);
        });
    }

    function poll() {
        if (polling || document.hidden) return;
        polling = true;
        fetch(feedUrl, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Notification feed unavailable');
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.success) return;
                updateBadge(data.unread);
                (data.notifications || []).forEach(showToast);
            })
            .catch(function () {
                // Silent: a temporary network issue should never disturb the page.
            })
            .finally(function () {
                polling = false;
                window.setTimeout(poll, 30000);
            });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) poll();
    });

    poll();
})();
