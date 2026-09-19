<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$user = currentUser();
$isAdmin = isAdmin();
$isLeaderUser = isLeader();

if (!$isAdmin && !$isLeaderUser) {
    flash('error', 'Unauthorized access.');
    redirect(APP_URL . '/app/index.php');
}

$auctionId = (int)($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare('
    SELECT a.*, g.name as group_name, g.join_code, g.leader_id, g.total_members
    FROM auction_sessions a
    JOIN groups g ON g.id = a.group_id
    WHERE a.id = ? AND g.deleted_at IS NULL
');
$stmt->execute([$auctionId]);
$auction = $stmt->fetch();

if (!$auction) {
    flash('error', 'Auction session not found.');
    redirect(APP_URL . '/admin/auctions.php');
}

// Check authorization for leaders
if (!$isAdmin && $auction['leader_id'] != $user['id']) {
    flash('error', 'You can only manage alerts for your own group auctions.');
    redirect(APP_URL . '/app/groups/index.php');
}

// Handle Form Submissions
if (isPost() && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_alert') {
        $timingType = $_POST['timing_type'] ?? '1_day';
        $customMinutes = (int)($_POST['custom_minutes'] ?? 0);
        $customLabel = trim($_POST['custom_label'] ?? '');

        addAuctionAlert($auctionId, $timingType, $customMinutes, $customLabel ?: null);
        flash('success', 'Alert timing added successfully.');
        redirect(APP_URL . '/admin/auction_alerts.php?id=' . $auctionId);
    }

    if ($action === 'toggle_alert') {
        $alertId = (int)($_POST['alert_id'] ?? 0);
        $pdo->prepare('UPDATE auction_alerts SET is_enabled = NOT is_enabled WHERE id = ? AND auction_id = ?')
            ->execute([$alertId, $auctionId]);
        flash('success', 'Alert timing status updated.');
        redirect(APP_URL . '/admin/auction_alerts.php?id=' . $auctionId);
    }

    if ($action === 'delete_alert') {
        $alertId = (int)($_POST['alert_id'] ?? 0);
        $pdo->prepare('DELETE FROM auction_alerts WHERE id = ? AND auction_id = ?')
            ->execute([$alertId, $auctionId]);
        flash('success', 'Alert timing deleted.');
        redirect(APP_URL . '/admin/auction_alerts.php?id=' . $auctionId);
    }

    if ($action === 'send_now') {
        $alertId = (int)($_POST['alert_id'] ?? 0);
        $res = dispatchAuctionAlert($alertId);
        flash($res['success'] ? 'success' : 'error', $res['message']);
        redirect(APP_URL . '/admin/auction_alerts.php?id=' . $auctionId);
    }

    if ($action === 'update_session') {
        $venue = trim($_POST['venue'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $state = $_POST['state'] ?? $auction['state'];

        $pdo->prepare('UPDATE auction_sessions SET venue = ?, description = ?, state = ? WHERE id = ?')
            ->execute([$venue, $description, $state, $auctionId]);
        flash('success', 'Auction session details updated.');
        redirect(APP_URL . '/admin/auction_alerts.php?id=' . $auctionId);
    }
}

// Fetch configured alerts
$stmt = $pdo->prepare('SELECT * FROM auction_alerts WHERE auction_id = ? ORDER BY trigger_minutes_before DESC');
$stmt->execute([$auctionId]);
$alerts = $stmt->fetchAll();

// Fetch delivery logs
$stmt = $pdo->prepare('
    SELECT l.*, u.display_name as member_name, u.email as member_email
    FROM auction_alert_logs l
    JOIN users u ON u.id = l.user_id
    WHERE l.auction_id = ?
    ORDER BY l.sent_at DESC
    LIMIT 100
');
$stmt->execute([$auctionId]);
$deliveryLogs = $stmt->fetchAll();

$pageTitle = 'Alert Management — ' . $auction['group_name'];
$currentPage = $isAdmin ? 'auctions' : 'groups';
require_once __DIR__ . '/../includes/header.php';

if ($isAdmin) {
    require_once __DIR__ . '/../includes/admin-nav.php';
} else {
    require_once __DIR__ . '/../includes/app-nav.php';
}
?>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;">
    <div>
        <h1>🔔 Alert Management</h1>
        <p>Auction Session #<?= $auction['cycle_number'] ?> · <strong><?= e($auction['group_name']) ?></strong> (Code: <code><?= e($auction['join_code']) ?></code>)</p>
    </div>
    <a href="<?= $isAdmin ? APP_URL . '/admin/auctions.php' : APP_URL . '/app/groups/view.php?id=' . $auction['group_id'] ?>" class="btn btn-outline">← Back</a>
</div>

<!-- AUCTION DETAILS & VENUE FORM -->
<div class="card" style="margin-bottom:24px;border:1px solid var(--border);border-radius:12px;padding:24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h3 style="margin:0;font-size:17px;font-weight:700;color:var(--primary);">🗓️ Session Details & Meeting Link / Venue</h3>
        <span class="badge badge-<?= $auction['state'] === 'live' ? 'live' : ($auction['state'] === 'scheduled' ? 'primary' : 'muted') ?>">
            <?= strtoupper(e($auction['state'])) ?>
        </span>
    </div>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_session">
        
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin-bottom:16px;">
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Start Datetime</label>
                <input type="text" class="form-control" value="<?= formatDate($auction['starts_at']) ?>" disabled readonly style="background:var(--bg);">
            </div>
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">End Datetime</label>
                <input type="text" class="form-control" value="<?= formatDate($auction['ends_at']) ?>" disabled readonly style="background:var(--bg);">
            </div>
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Status</label>
                <select name="state" class="form-control">
                    <option value="scheduled" <?= $auction['state'] === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                    <option value="live" <?= $auction['state'] === 'live' ? 'selected' : '' ?>>Active / Live</option>
                    <option value="completed" <?= $auction['state'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $auction['state'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Auction Venue / Online Meeting Link</label>
                <input type="text" name="venue" class="form-control" value="<?= e($auction['venue'] ?? '') ?>" placeholder="e.g. Google Meet Link / Zoom URL / Main Hall Branch B">
            </div>
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Notes / Description</label>
                <input type="text" name="description" class="form-control" value="<?= e($auction['description'] ?? '') ?>" placeholder="Special instructions for members...">
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">Save Session Details</button>
    </form>
</div>

<!-- ADD NEW ALERT TIMING CARD -->
<div class="card" style="margin-bottom:24px;border:1px solid var(--border);border-radius:12px;padding:24px;">
    <h3 style="margin:0 0 16px 0;font-size:17px;font-weight:700;">➕ Configure New Alert Timing</h3>
    <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;align-items:end;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_alert">
        
        <div>
            <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Alert Preset Timing</label>
            <select name="timing_type" id="timingTypeSelect" class="form-control" onchange="document.getElementById('customMinBox').style.display = this.value === 'custom' ? 'block' : 'none';">
                <option value="1_day">1 Day Before (24h)</option>
                <option value="12_hours">12 Hours Before</option>
                <option value="6_hours">6 Hours Before</option>
                <option value="3_hours">3 Hours Before</option>
                <option value="1_hour">1 Hour Before</option>
                <option value="30_mins">30 Minutes Before</option>
                <option value="15_mins">15 Minutes Before</option>
                <option value="10_mins">10 Minutes Before</option>
                <option value="5_mins">5 Minutes Before</option>
                <option value="1_min">1 Minute Before</option>
                <option value="custom">Custom Minutes Before</option>
            </select>
        </div>

        <div id="customMinBox" style="display:none;">
            <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Custom Minutes</label>
            <input type="number" name="custom_minutes" class="form-control" min="1" max="10000" placeholder="e.g. 45">
        </div>

        <div>
            <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Optional Custom Note/Label</label>
            <input type="text" name="custom_label" class="form-control" placeholder="e.g. Final Call">
        </div>

        <div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Add Alert Timing</button>
        </div>
    </form>
</div>

<!-- CONFIGURED ALERTS LIST -->
<div class="card" style="margin-bottom:24px;border:1px solid var(--border);border-radius:12px;padding:24px;">
    <h3 style="margin:0 0 16px 0;font-size:17px;font-weight:700;">⏱️ Configured Alert Reminders</h3>
    
    <?php if (empty($alerts)): ?>
    <div style="text-align:center;padding:20px;color:var(--muted);">
        <p style="margin:0;">No alert timings configured yet. Use the form above to add reminders!</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Alert Timing</th>
                    <th>Minutes Before</th>
                    <th>Status</th>
                    <th>Trigger Time</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alerts as $al): ?>
                <?php 
                    $triggerTimestamp = strtotime($auction['starts_at']) - ($al['trigger_minutes_before'] * 60);
                    $triggerFormatted = date('d M Y, h:i A', $triggerTimestamp);
                    $timingLabel = str_replace('_', ' ', strtoupper($al['timing_type']));
                    if ($al['custom_label']) $timingLabel .= " ({$al['custom_label']})";
                ?>
                <tr>
                    <td><strong><?= e($timingLabel) ?></strong></td>
                    <td><?= $al['trigger_minutes_before'] ?> mins</td>
                    <td>
                        <?php if ($al['status'] === 'sent'): ?>
                        <span class="badge badge-success">Sent (<?= formatDate($al['sent_at']) ?>)</span>
                        <?php elseif (!$al['is_enabled']): ?>
                        <span class="badge badge-muted">Disabled</span>
                        <?php else: ?>
                        <span class="badge badge-primary">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= $triggerFormatted ?></td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <!-- Send Manual Reminder Now -->
                            <form method="POST" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="send_now">
                                <input type="hidden" name="alert_id" value="<?= $al['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Send manual reminder to all members immediately?');">⚡ Send Now</button>
                            </form>

                            <!-- Toggle Enable/Disable -->
                            <form method="POST" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_alert">
                                <input type="hidden" name="alert_id" value="<?= $al['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline"><?= $al['is_enabled'] ? 'Disable' : 'Enable' ?></button>
                            </form>

                            <!-- Delete Alert -->
                            <form method="POST" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_alert">
                                <input type="hidden" name="alert_id" value="<?= $al['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this alert timing?');">🗑️</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ALERT DELIVERY LOGS -->
<div class="card" style="border:1px solid var(--border);border-radius:12px;padding:24px;">
    <h3 style="margin:0 0 16px 0;font-size:17px;font-weight:700;">📊 Alert Delivery Logs & Delivery Status</h3>
    
    <?php if (empty($deliveryLogs)): ?>
    <div style="text-align:center;padding:20px;color:var(--muted);">
        <p style="margin:0;">No delivery logs recorded yet. Reminders will log member delivery statuses here.</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>Member Name</th>
                    <th>Channel</th>
                    <th>Status</th>
                    <th>Message Delivered</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deliveryLogs as $log): ?>
                <tr>
                    <td class="text-muted"><?= formatDate($log['sent_at']) ?></td>
                    <td><strong><?= e($log['member_name']) ?></strong> <span style="font-size:11px;color:var(--muted);">(<?= e($log['member_email']) ?>)</span></td>
                    <td>
                        <span class="badge badge-<?= $log['channel'] === 'in_app' ? 'primary' : ($log['channel'] === 'sms' ? 'success' : 'outline') ?>">
                            <?= strtoupper($log['channel']) ?>
                        </span>
                    </td>
                    <td><span class="badge badge-success"><?= e($log['status']) ?></span></td>
                    <td style="font-size:12px;max-width:350px;"><?= e($log['message_text']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
