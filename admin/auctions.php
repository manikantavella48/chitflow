<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$user = currentUser();

// Automatically process pending auction alerts
processPendingAuctionAlerts();

// API endpoint for live overview updates
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $auctionsList = db()->query('
        SELECT a.id, a.state, a.starts_at, a.ends_at,
        (SELECT COUNT(*) FROM bids WHERE auction_id = a.id) as bid_count,
        (SELECT MAX(discount_paise) FROM bids WHERE auction_id = a.id) as top_bid
        FROM auction_sessions a
        ORDER BY a.created_at DESC
        LIMIT 50
    ')->fetchAll();

    echo json_encode([
        'success' => true,
        'auctions' => array_map(function($a) {
            return [
                'id' => (int)$a['id'],
                'state' => $a['state'],
                'bid_count' => (int)$a['bid_count'],
                'top_bid_formatted' => $a['top_bid'] ? paiseToINR((int)$a['top_bid']) : '—'
            ];
        }, $auctionsList)
    ]);
    exit;
}

if (isPost() && verifyCsrf()) {
    $auctionId = (int)($_POST['auction_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'force_close') {
        $stmt = db()->prepare('SELECT g.leader_id FROM auction_sessions a JOIN groups g ON g.id = a.group_id WHERE a.id = ?');
        $stmt->execute([$auctionId]);
        $leaderId = $stmt->fetchColumn();
        if ($leaderId) {
            $result = closeAuction($auctionId, $leaderId);
            flash($result['success'] ? 'success' : 'error', $result['message']);
        }
    } elseif ($action === 'cancel') {
        db()->prepare("UPDATE auction_sessions SET state = 'cancelled' WHERE id = ?")->execute([$auctionId]);
        logAdminAction($user['id'], 'cancel_auction', 'auction', $auctionId);
        flash('success', 'Auction cancelled.');
    } elseif ($action === 'start_auction') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $cycleNumber = (int)($_POST['cycle_number'] ?? 1);
        $venue = trim($_POST['venue'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $startMode = $_POST['start_mode'] ?? 'now';
        if ($startMode === 'scheduled' && !empty($_POST['starts_at_custom'])) {
            $startsAt = date('Y-m-d H:i:s', strtotime($_POST['starts_at_custom']));
        } else {
            $startsAt = date('Y-m-d H:i:s');
        }

        $endMode = $_POST['end_mode'] ?? 'duration';
        if ($endMode === 'custom' && !empty($_POST['ends_at_custom'])) {
            $endsAt = date('Y-m-d H:i:s', strtotime($_POST['ends_at_custom']));
        } else {
            $durationHours = (float)($_POST['duration_hours'] ?? 0.5);
            if ($durationHours <= 0) $durationHours = 0.5;
            $durationMinutes = (int)round($durationHours * 60);
            $endsAt = date('Y-m-d H:i:s', strtotime($startsAt . " +$durationMinutes minutes"));
        }

        if ($groupId <= 0) {
            flash('error', 'Please select a valid group.');
        } elseif (strtotime($endsAt) <= strtotime($startsAt)) {
            flash('error', 'Auction end date/time must be after start date/time.');
        } else {
            $stmt = db()->prepare('SELECT * FROM groups WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$groupId]);
            $targetGroup = $stmt->fetch();

            if (!$targetGroup) {
                flash('error', 'Selected group does not exist.');
            } else {
                $currentMemberCount = getMemberCount($groupId);
                if ($currentMemberCount < (int)$targetGroup['total_members']) {
                    flash('error', "Cannot start auction for {$targetGroup['name']}. Group must be 100% full before starting (Currently {$currentMemberCount}/{$targetGroup['total_members']} members).");
                } else {
                    $initialState = (strtotime($startsAt) > time() + 60) ? 'scheduled' : 'live';

                    $stmt = db()->prepare('SELECT id, state FROM auction_sessions WHERE group_id = ? AND cycle_number = ?');
                    $stmt->execute([$groupId, $cycleNumber]);
                    $existingSession = $stmt->fetch();

                    if ($existingSession) {
                        if ($existingSession['state'] === 'cancelled') {
                            db()->prepare('DELETE FROM bids WHERE auction_id = ?')->execute([$existingSession['id']]);
                            db()->prepare('UPDATE auction_sessions SET starts_at = ?, ends_at = ?, state = ?, venue = ?, description = ?, winner_user_id = NULL, winning_discount_paise = NULL WHERE id = ?')
                                ->execute([$startsAt, $endsAt, $initialState, $venue, $description, $existingSession['id']]);
                            $newAuctionId = $existingSession['id'];
                        } else {
                            flash('error', "Cycle #{$cycleNumber} for {$targetGroup['name']} is already {$existingSession['state']}.");
                            $newAuctionId = 0;
                        }
                    } else {
                        db()->prepare('INSERT INTO auction_sessions (group_id, cycle_number, starts_at, ends_at, state, venue, description) VALUES (?, ?, ?, ?, ?, ?, ?)')
                            ->execute([$groupId, $cycleNumber, $startsAt, $endsAt, $initialState, $venue, $description]);
                        $newAuctionId = (int) db()->lastInsertId();
                    }

                    if ($newAuctionId > 0) {
                        // Add Selected Alert Preset Timings
                        $selectedAlerts = $_POST['alerts'] ?? ['1_day', '1_hour', '10_mins'];
                        foreach ($selectedAlerts as $timing) {
                            addAuctionAlert($newAuctionId, $timing);
                        }

                        notifyGroupMembers(
                            $groupId,
                            'auction_started',
                            $initialState === 'scheduled' ? '📅 Auction Scheduled!' : '🚨 Live Auction Started!',
                            "Cycle #{$cycleNumber} auction for {$targetGroup['name']} is " . ($initialState === 'scheduled' ? 'scheduled for ' . formatDate($startsAt) : 'now LIVE!') . (!empty($venue) ? " Venue/Link: $venue" : "")
                        );
                        logAdminAction($user['id'], 'start_auction', 'auction', $newAuctionId);
                        flash('success', "Cycle #{$cycleNumber} auction for {$targetGroup['name']} " . ($initialState === 'scheduled' ? 'scheduled successfully with alert reminders!' : 'started & members notified!'));
                    }
                }
            }
        }
    }
    redirect(APP_URL . '/admin/auctions.php');
}

$allGroups = db()->query('SELECT id, name, join_code, chit_value_paise FROM groups WHERE deleted_at IS NULL ORDER BY name ASC')->fetchAll();

$auctions = db()->query('
    SELECT a.*, g.name as group_name, g.join_code, g.chit_value_paise,
    (SELECT COUNT(*) FROM bids WHERE auction_id = a.id) as bid_count,
    (SELECT MAX(discount_paise) FROM bids WHERE auction_id = a.id) as top_bid,
    (SELECT COUNT(*) FROM auction_alerts WHERE auction_id = a.id) as alert_count
    FROM auction_sessions a
    JOIN groups g ON g.id = a.group_id
    WHERE g.deleted_at IS NULL
    ORDER BY a.created_at DESC
    LIMIT 50
')->fetchAll();

$pageTitle = 'Auctions';
$currentPage = 'auctions';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin-nav.php';
?>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;">
    <div>
        <h1>Auctions Overview</h1>
        <p>Monitor real-time live bidding activity, schedule sessions, and configure alert reminders</p>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
        <a href="<?= APP_URL ?>/admin/auctions.php?action=start" class="btn btn-sm btn-primary">⚡ Schedule / Start Auction</a>
        <div style="display:flex;align-items:center;gap:6px;background:rgba(16,185,129,0.1);padding:6px 12px;border-radius:20px;">
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#10b981;box-shadow:0 0 8px #10b981;"></span>
            <span style="font-size:12px;font-weight:700;color:#10b981;text-transform:uppercase;letter-spacing:1px;">Live Admin Feed</span>
        </div>
    </div>
</div>

<?php if (isset($_GET['action']) && $_GET['action'] === 'start'): ?>
<div class="card" style="margin-bottom:24px;border:2px solid var(--primary);background:var(--card-bg);padding:24px;border-radius:12px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h3 style="margin:0;font-size:18px;font-weight:700;color:var(--primary);">🗓️ Schedule / Start Auction Session</h3>
        <a href="<?= APP_URL ?>/admin/auctions.php" class="btn btn-sm btn-outline" style="text-decoration:none;">✕ Cancel</a>
    </div>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="start_auction">

        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin-bottom:16px;">
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Select Group</label>
                <select name="group_id" class="form-control" required>
                    <option value="">-- Choose Chit Group --</option>
                    <?php foreach ($allGroups as $g): ?>
                    <option value="<?= $g['id'] ?>">
                        <?= e($g['name']) ?> (Code: <?= e($g['join_code']) ?>, Chit: <?= paiseToINR((int)$g['chit_value_paise']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Cycle Number</label>
                <input type="number" name="cycle_number" class="form-control" required min="1" max="60" value="1">
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Auction Venue or Online Meeting Link</label>
                <input type="text" name="venue" class="form-control" placeholder="e.g. Google Meet Link / Zoom Link / Main Hall Branch B">
            </div>
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Description / Notes</label>
                <input type="text" name="description" class="form-control" placeholder="Special instructions for members...">
            </div>
        </div>

        <!-- ADVANCED SCHEDULE SELECTION PANEL -->
        <div style="background:rgba(99,102,241,0.05);border:1px solid rgba(99,102,241,0.2);padding:16px;border-radius:10px;margin-bottom:20px;">
            <h4 style="font-size:14px;font-weight:700;margin-bottom:12px;color:var(--primary);display:flex;align-items:center;gap:6px;">
                <span>🗓️</span> Advanced Date & Time Settings
            </h4>
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:16px;margin-bottom:16px;">
                <!-- Start Mode -->
                <div>
                    <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Start Timing</label>
                    <select name="start_mode" id="adminStartMode" class="form-control" onchange="document.getElementById('adminCustomStartBox').style.display = this.value === 'scheduled' ? 'block' : 'none';">
                        <option value="now">⚡ Start Immediately (Now)</option>
                        <option value="scheduled" selected>📅 Schedule for Future Date & Time</option>
                    </select>
                    <div id="adminCustomStartBox" style="margin-top:8px;">
                        <label class="form-label" style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px;">Start Date & Time</label>
                        <input type="datetime-local" name="starts_at_custom" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime('+1 day')) ?>">
                    </div>
                </div>

                <!-- End Mode -->
                <div>
                    <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">End Timing / Duration</label>
                    <select name="end_mode" id="adminEndMode" class="form-control" onchange="document.getElementById('adminDurationBox').style.display = this.value === 'duration' ? 'block' : 'none'; document.getElementById('adminCustomEndBox').style.display = this.value === 'custom' ? 'block' : 'none';">
                        <option value="duration" selected>⏱️ Select Duration (Hours)</option>
                        <option value="custom">📅 Select Custom End Date & Time</option>
                    </select>
                    <div id="adminDurationBox" style="margin-top:8px;">
                        <label class="form-label" style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px;">Duration (Hours)</label>
                        <select name="duration_hours" class="form-control">
                            <option value="0.25">15 Minutes</option>
                            <option value="0.5">0.5 Hours (30 Mins)</option>
                            <option value="1" selected>1 Hour</option>
                            <option value="2">2 Hours</option>
                            <option value="6">6 Hours</option>
                            <option value="12">12 Hours</option>
                            <option value="24">24 Hours (1 Day)</option>
                        </select>
                    </div>
                    <div id="adminCustomEndBox" style="display:none;margin-top:8px;">
                        <label class="form-label" style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px;">Custom End Date & Time</label>
                        <input type="datetime-local" name="ends_at_custom" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime('+1 day +1 hour')) ?>">
                    </div>
                </div>
            </div>

            <!-- ALERT TIMINGS CONFIGURATION CHECKBOXES -->
            <div>
                <label class="form-label" style="font-weight:700;display:block;margin-bottom:8px;color:var(--text-color);">🔔 Configure Alert Reminders to Send to Members:</label>
                <div style="display:flex;flex-wrap:wrap;gap:16px;font-size:13px;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="alerts[]" value="1_day" checked> 1 Day Before (24h)
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="alerts[]" value="12_hours"> 12 Hours Before
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="alerts[]" value="1_hour" checked> 1 Hour Before
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="alerts[]" value="30_mins"> 30 Minutes Before
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="alerts[]" value="10_mins" checked> 10 Minutes Before
                    </label>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:12px;justify-content:flex-end;">
            <a href="<?= APP_URL ?>/admin/auctions.php" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary">🚀 Launch / Schedule Auction Session</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Group & Chit Code</th>
                <th>Cycle</th>
                <th>State</th>
                <th>Bids</th>
                <th>Top Bid</th>
                <th>Starts / Ends</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="adminAuctionsTbody">
            <?php foreach ($auctions as $a): ?>
            <tr id="auctionRow-<?= $a['id'] ?>">
                <td>
                    <strong><?= e($a['group_name']) ?></strong>
                    <br><span style="font-size:11px;color:var(--muted);">Code: <code style="font-weight:700;color:var(--primary);"><?= e($a['join_code']) ?></code></span>
                    <?php if (!empty($a['venue'])): ?>
                    <br><span style="font-size:11px;color:var(--primary);">📍 <?= e($a['venue']) ?></span>
                    <?php endif; ?>
                </td>
                <td>#<?= $a['cycle_number'] ?></td>
                <td>
                    <span class="badge badge-<?= $a['state'] === 'live' ? 'live' : ($a['state'] === 'completed' ? 'success' : 'primary') ?>">
                        <?= e($a['state']) ?>
                    </span>
                </td>
                <td id="bidCount-<?= $a['id'] ?>"><?= $a['bid_count'] ?></td>
                <td id="topBid-<?= $a['id'] ?>">
                    <?= $a['top_bid'] ? paiseToINR((int)$a['top_bid']) : '—' ?>
                </td>
                <td style="font-size:12px;">
                    <?php if ($a['state'] === 'scheduled'): ?>
                    Starts: <strong><?= formatDateShort($a['starts_at']) ?></strong>
                    <?php else: ?>
                    Ends: <?= formatDateShort($a['ends_at']) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <a href="<?= APP_URL ?>/admin/auction_alerts.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline" style="text-decoration:none;">
                            🔔 Alerts (<?= $a['alert_count'] ?>)
                        </a>

                        <?php if ($a['state'] === 'live'): ?>
                        <form method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="force_close">
                            <input type="hidden" name="auction_id" value="<?= $a['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Close auction session and calculate payouts?');">Close</button>
                        </form>
                        <?php elseif ($a['state'] === 'pending' || $a['state'] === 'scheduled'): ?>
                        <form method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="auction_id" value="<?= $a['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline" onclick="return confirm('Cancel this session?');">Cancel</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</main>
<script>
// Real-time Live Admin Feed Polling every 4 seconds
setInterval(async () => {
    try {
        const res = await fetch('<?= APP_URL ?>/admin/auctions.php?api=1');
        const data = await res.json();
        if (data.success && data.auctions) {
            data.auctions.forEach(a => {
                const countEl = document.getElementById('bidCount-' + a.id);
                const topEl = document.getElementById('topBid-' + a.id);
                if (countEl) countEl.textContent = a.bid_count;
                if (topEl) topEl.textContent = a.top_bid_formatted;
            });
        }
    } catch (e) {}
}, 4000);
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
