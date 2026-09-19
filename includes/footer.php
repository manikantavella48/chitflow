<?php
$isUserLoggedIn = isset($_SESSION['user_id']);
?>
    <!-- Real-Time Toast Notifications Container -->
    <div id="toastNotificationContainer" style="position:fixed;top:20px;right:20px;z-index:999999;display:flex;flex-direction:column;gap:12px;max-width:380px;width:calc(100% - 40px);pointer-events:none;"></div>

    <script>
        // Dismiss flash messages automatically
        setTimeout(() => {
            const f = document.getElementById('flash-msg');
            if (f) f.remove();
        }, 5000);

        <?php if ($isUserLoggedIn): ?>
        // Request Desktop Notification Permission on user interaction
        if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
            document.addEventListener('click', function reqPerm() {
                Notification.requestPermission();
                document.removeEventListener('click', reqPerm);
            }, { once: true });
        }

        // Web Audio Chime Generator
        function playChime() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const now = ctx.currentTime;
                
                const osc1 = ctx.createOscillator();
                const gain1 = ctx.createGain();
                osc1.type = 'sine';
                osc1.frequency.setValueAtTime(523.25, now); // C5
                gain1.gain.setValueAtTime(0.15, now);
                gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
                osc1.connect(gain1);
                gain1.connect(ctx.destination);
                osc1.start(now);
                osc1.stop(now + 0.5);

                const osc2 = ctx.createOscillator();
                const gain2 = ctx.createGain();
                osc2.type = 'sine';
                osc2.frequency.setValueAtTime(659.25, now + 0.15); // E5
                gain2.gain.setValueAtTime(0.2, now + 0.15);
                gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.7);
                osc2.connect(gain2);
                gain2.connect(ctx.destination);
                osc2.start(now + 0.15);
                osc2.stop(now + 0.7);
            } catch(e) {}
        }

        const seenToastIds = new Set();

        async function checkLiveAlerts() {
            try {
                const res = await fetch('<?= APP_URL ?>/app/notifications.php?api=check_unread');
                const data = await res.json();

                if (data.success && data.notifications && data.notifications.length > 0) {
                    let hasNewAlert = false;
                    data.notifications.forEach(n => {
                        if (!seenToastIds.has(n.id)) {
                            seenToastIds.add(n.id);
                            hasNewAlert = true;
                            showToastAlert(n);
                        }
                    });

                    if (hasNewAlert) {
                        playChime();
                    }
                }
            } catch (e) {}
        }

        function showToastAlert(n) {
            const container = document.getElementById('toastNotificationContainer');
            if (!container) return;

            const toast = document.createElement('div');
            toast.id = 'toast-' + n.id;
            toast.style.cssText = `
                pointer-events: auto;
                background: var(--card-bg, #1e293b);
                color: var(--text-color, #f8fafc);
                border-left: 5px solid #6366f1;
                border-radius: 12px;
                padding: 16px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
                display: flex;
                flex-direction: column;
                gap: 8px;
                cursor: pointer;
                transition: transform 0.2s ease, opacity 0.3s ease;
                animation: slideInRight 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            `;

            let icon = '🔔';
            if (n.title.includes('Reminder')) icon = '📢';
            else if (n.title.includes('Started') || n.title.includes('LIVE')) icon = '🚨';
            else if (n.title.includes('Completed') || n.title.includes('Winner')) icon = '🏆';
            else if (n.title.includes('Spin')) icon = '🎰';

            let actionUrl = '<?= APP_URL ?>/app/notifications.php';
            if (n.title.toLowerCase().includes('auction') || n.body.toLowerCase().includes('auction')) {
                actionUrl = '<?= APP_URL ?>/app/groups/index.php';
            }

            toast.onclick = function(e) {
                if (e.target.tagName === 'BUTTON' || e.target.classList.contains('close-toast-btn')) {
                    e.stopPropagation();
                    dismissToast(n.id);
                } else {
                    dismissToast(n.id, actionUrl);
                }
            };

            toast.innerHTML = `
                <div style="display:flex;align-items:start;justify-content:space-between;gap:8px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:20px;animation:bellRing 1s ease infinite alternate;">${icon}</span>
                        <strong style="font-size:14px;color:#818cf8;">${escapeHtml(n.title)}</strong>
                    </div>
                    <button class="close-toast-btn" onclick="dismissToast(${n.id}); event.stopPropagation();" style="background:none;border:none;color:var(--muted,#94a3b8);cursor:pointer;font-size:18px;line-height:1;padding:2px 6px;">✕</button>
                </div>
                <p style="margin:0;font-size:13px;color:var(--muted, #cbd5e1);line-height:1.4;">${escapeHtml(n.body)}</p>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;">
                    <span style="font-size:11px;color:var(--muted, #64748b);">${n.created_at}</span>
                    <span style="background:linear-gradient(135deg, #6366f1, #8b5cf6);color:#fff;font-size:11px;font-weight:600;padding:4px 10px;border-radius:6px;">🚀 View Alert →</span>
                </div>
            `;

            container.appendChild(toast);

            // Auto-dismiss toast after 10 seconds and mark as read in database
            setTimeout(() => {
                const el = document.getElementById('toast-' + n.id);
                if (el) {
                    dismissToast(n.id);
                }
            }, 10000);

            // Native OS Desktop Notification
            if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
                try {
                    new Notification(n.title, { body: n.body, icon: '<?= APP_URL ?>/assets/favicon.ico' });
                } catch(e) {}
            }
        }

        async function dismissToast(id, redirectUrl = null) {
            const toast = document.getElementById('toast-' + id);
            if (toast) {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100%)';
                setTimeout(() => { toast.remove(); }, 300);
            }

            try {
                const formData = new FormData();
                formData.append('action', 'dismiss_notif');
                formData.append('notif_id', id);
                await fetch('<?= APP_URL ?>/app/notifications.php', { method: 'POST', body: formData });
            } catch(e) {}

            if (redirectUrl) {
                window.location.href = redirectUrl;
            }
        }

        function escapeHtml(str) {
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // Poll for new alerts every 5 seconds
        checkLiveAlerts();
        setInterval(checkLiveAlerts, 5000);
        <?php endif; ?>
    </script>
    <style>
    @keyframes slideInRight {
        from { transform: translateX(120%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes bellRing {
        0% { transform: rotate(-15deg); }
        100% { transform: rotate(15deg); }
    }
    </style>
</body>
</html>
