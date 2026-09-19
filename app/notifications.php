<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$user = currentUser();
$pdo = db();

// Process pending auction alerts automatically
processPendingAuctionAlerts();

// API endpoint for real-time live alert checking
if (isset($_GET['api']) && $_GET['api'] === 'check_unread') {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? AND read_at IS NULL ORDER BY created_at DESC LIMIT 5');
    $stmt->execute([$user['id']]);
    $unreadList = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'unread_count' => count($unreadList),
        'notifications' => array_map(function($n) {
            return [
                'id' => (int)$n['id'],
                'title' => $n['title'],
                'body' => $n['body'],
                'type' => $n['type'],
                'created_at' => formatDate($n['created_at'])
            ];
        }, $unreadList)
    ]);
    exit;
}

// Handle dismiss notification / mark all as read / clear history
if (isPost() && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'dismiss_notif') {
        $notifId = (int)($_POST['notif_id'] ?? 0);
        if ($notifId > 0) {
            $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?')
                ->execute([$notifId, $user['id']]);
        }
        if (isset($_GET['api']) || isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        flash('success', 'Alert dismissed.');
        redirect(APP_URL . '/app/notifications.php');
    }

    if ($action === 'mark_all_read') {
        $pdo->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')
            ->execute([$user['id']]);
        flash('success', 'All notifications marked as read.');
        redirect(APP_URL . '/app/notifications.php');
    }

    if ($action === 'clear_read_history') {
        $pdo->prepare('DELETE FROM notifications WHERE user_id = ? AND read_at IS NOT NULL')
            ->execute([$user['id']]);
        flash('success', 'Read notification history cleared.');
        redirect(APP_URL . '/app/notifications.php');
    }
}

// Fetch all user notifications for history log view
$stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();

$totalCount = count($notifications);
$unreadCount = 0;
foreach ($notifications as $n) {
    if (empty($n['read_at'])) $unreadCount++;
}

$pageTitle = 'Notifications & Alerts History';
$currentPage = 'notifications';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/app-nav.php';
?>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
    <div>
        <h1>🔔 Notifications & Alerts History</h1>
        <p>View your complete history of auction alerts, schedule reminders, and group updates.</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
        <?php if ($unreadCount > 0): ?>
        <form method="POST" style="margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="btn btn-sm btn-primary">✓ Mark All as Read (<?= $unreadCount ?>)</button>
        </form>
        <?php endif; ?>
        <?php if ($totalCount > 0): ?>
        <form method="POST" style="margin:0;" onsubmit="return confirm('Clear read notification history?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="clear_read_history">
            <button type="submit" class="btn btn-sm btn-ghost">🗑️ Clear Read History</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <?php if (empty($notifications)): ?>
    <div class="empty-state card" style="text-align:center;padding:32px;">
        <div style="font-size:40px;margin-bottom:12px;">🔔</div>
        <h3 style="margin:0 0 6px 0;">No Notifications in History</h3>
        <p style="color:var(--muted);font-size:13px;margin:0;">
            All your group auction alerts, reminders, and updates will be stored here in your history log.
        </p>
    </div>
    <?php else: ?>

    <div style="display:flex;flex-direction:column;gap:12px;">
        <?php foreach ($notifications as $n): ?>
        <?php
            $isUnread = empty($n['read_at']);
            $icon = '🔔';
            if (str_contains(strtolower($n['title']), 'reminder')) $icon = '📢';
            else if (str_contains(strtolower($n['title']), 'started') || str_contains(strtolower($n['title']), 'live')) $icon = '🚨';
            else if (str_contains(strtolower($n['title']), 'completed') || str_contains(strtolower($n['title']), 'winner')) $icon = '🏆';
            else if (str_contains(strtolower($n['title']), 'spin')) $icon = '🎰';
        ?>
        <div style="padding:16px;border:1px solid <?= $isUnread ? '#6366f1' : 'var(--border)' ?>;border-radius:10px;background:<?= $isUnread ? 'rgba(99,102,241,0.05)' : 'var(--card-bg)' ?>;display:flex;align-items:start;justify-content:space-between;gap:16px;flex-wrap:wrap;">
            <div style="display:flex;gap:14px;align-items:start;flex:1;">
                <div style="font-size:26px;"><?= $icon ?></div>
                <div style="flex:1;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
                        <h4 style="margin:0;font-size:15px;font-weight:700;color:var(--text-color);"><?= e($n['title']) ?></h4>
                        <?php if ($isUnread): ?>
                        <span class="badge badge-primary" style="font-size:10px;padding:2px 8px;">🟢 NEW UNREAD</span>
                        <?php else: ?>
                        <span class="badge badge-success" style="font-size:10px;padding:2px 8px;background:rgba(16,185,129,0.15);color:#10b981;">✓ Saved in History</span>
                        <?php endif; ?>
                    </div>
                    <p style="margin:0;font-size:13px;color:var(--muted);line-height:1.5;"><?= e($n['body']) ?></p>
                    <div style="font-size:11px;color:var(--muted);margin-top:6px;">Received: <?= formatDate($n['created_at']) ?></div>
                </div>
            </div>

            <div style="display:flex;align-items:center;gap:10px;">
                <?php if (str_contains(strtolower($n['title']), 'auction') || str_contains(strtolower($n['body']), 'auction')): ?>
                <a href="<?= APP_URL ?>/app/groups/index.php" class="btn btn-sm btn-primary" style="white-space:nowrap;">🚀 Open Group / Auction →</a>
                <?php endif; ?>

                <?php if ($isUnread): ?>
                <form method="POST" style="margin:0;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="dismiss_notif">
                    <input type="hidden" name="notif_id" value="<?= $n['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-ghost" title="Mark as Read">✓ Read</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
