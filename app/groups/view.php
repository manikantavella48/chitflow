<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$user = currentUser();
$groupId = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT g.*, u.display_name as leader_name FROM groups g JOIN users u ON u.id = g.leader_id WHERE g.id = ?');
$stmt->execute([$groupId]);
$group = $stmt->fetch();

if (!$group) {
    flash('error', 'Group not found.');
    redirect(APP_URL . '/app/groups/index.php');
}

$isLeader = isGroupLeader($groupId, $user['id']);
$isMember = isGroupMember($groupId, $user['id']);
$memberCount = getMemberCount($groupId);
$isGroupFull = ($memberCount >= (int)$group['total_members']);

// Handle actions
if (isPost() && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'start_auction' && $isLeader) {
        if (!$isGroupFull) {
            flash('error', "Cannot start auction! Group must be 100% full with all {$group['total_members']} members before starting an auction (Currently {$memberCount}/{$group['total_members']} members).");
            redirect(APP_URL . '/app/groups/view.php?id=' . $groupId);
        }

        $cycleNumber = (int)($_POST['cycle_number'] ?? 1);

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

        if (strtotime($endsAt) <= strtotime($startsAt)) {
            flash('error', 'Auction end date/time must be after start date/time.');
            redirect(APP_URL . '/app/groups/view.php?id=' . $groupId);
        }

        $submitMode = $_POST['submit_mode'] ?? 'schedule';
        $targetAuctionId = 0;

        $initialState = (strtotime($startsAt) > time() + 60) ? 'scheduled' : 'live';

        // Check if cycle session already exists
        $stmt = db()->prepare('SELECT id, state FROM auction_sessions WHERE group_id = ? AND cycle_number = ?');
        $stmt->execute([$groupId, $cycleNumber]);
        $existing = $stmt->fetch();

        if ($existing) {
            $targetAuctionId = (int)$existing['id'];
            if ($existing['state'] === 'cancelled' || $submitMode === 'enter_room') {
                if ($existing['state'] === 'cancelled') {
                    db()->prepare('DELETE FROM bids WHERE auction_id = ?')->execute([$existing['id']]);
                    db()->prepare('UPDATE auction_sessions SET starts_at = ?, ends_at = ?, state = ?, winner_user_id = NULL, winning_discount_paise = NULL WHERE id = ?')
                        ->execute([$startsAt, $endsAt, $initialState, $existing['id']]);
                }
                notifyGroupMembers(
                    $groupId,
                    'auction_started',
                    $initialState === 'scheduled' ? '📅 Auction Scheduled!' : '🚨 Cycle Restarted!',
                    "Cycle #{$cycleNumber} auction for {$group['name']} is " . ($initialState === 'scheduled' ? 'scheduled for ' . formatDate($startsAt) : 'now LIVE!')
                );
                flash('success', "Cycle #{$cycleNumber} " . ($initialState === 'scheduled' ? 'scheduled!' : 'restarted & members alerted!'));
            } else {
                flash('info', "Cycle #{$cycleNumber} is already {$existing['state']}. Entering auction room.");
            }
        } else {
            try {
                db()->prepare('INSERT INTO auction_sessions (group_id, cycle_number, starts_at, ends_at, state) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$groupId, $cycleNumber, $startsAt, $endsAt, $initialState]);
                $targetAuctionId = (int)db()->lastInsertId();
                notifyGroupMembers(
                    $groupId,
                    'auction_started',
                    $initialState === 'scheduled' ? '📅 Auction Scheduled!' : '🚨 Live Auction Started!',
                    "Cycle #{$cycleNumber} auction for {$group['name']} is " . ($initialState === 'scheduled' ? 'scheduled for ' . formatDate($startsAt) : 'now LIVE!')
                );
                flash('success', "Cycle #{$cycleNumber} " . ($initialState === 'scheduled' ? 'scheduled successfully!' : 'started & group members alerted!'));
            } catch (Exception $e) {
                flash('error', 'Could not start auction for this cycle.');
            }
        }

        if ($submitMode === 'enter_room' && $targetAuctionId > 0) {
            redirect(APP_URL . '/app/auction.php?id=' . $targetAuctionId);
        } else {
            redirect(APP_URL . '/app/groups/view.php?id=' . $groupId);
        }
    }

    if ($action === 'join' && !$isMember) {
        $result = joinGroupByCode($user['id'], $group['join_code']);
        flash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(APP_URL . '/app/groups/view.php?id=' . $groupId);
    }

    if ($action === 'import_group_members' && $isLeader) {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Please upload a valid Excel/CSV file.');
        } else {
            $filePath = $_FILES['import_file']['tmp_name'];
            $handle = fopen($filePath, 'r');
            if ($handle === false) {
                flash('error', 'Could not open the uploaded file.');
            } else {
                $addedCount = 0;
                $skippedCount = 0;
                $rowNum = 0;

                while (($row = fgetcsv($handle, 1000, ",")) !== false) {
                    $rowNum++;
                    if ($rowNum === 1) {
                        $firstCol = strtolower(trim($row[0] ?? ''));
                        $secondCol = strtolower(trim($row[1] ?? ''));
                        if (str_contains($firstCol, 'name') || str_contains($secondCol, 'email')) {
                            continue;
                        }
                    }

                    $name = trim($row[0] ?? '');
                    $email = trim($row[1] ?? '');
                    $phone = trim($row[2] ?? '');
                    $pass = trim($row[3] ?? 'password123');
                    if (empty($pass)) $pass = 'password123';

                    if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $skippedCount++;
                        continue;
                    }

                    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
                    $stmt->execute([$email]);
                    $targetUserId = $stmt->fetchColumn();

                    if (!$targetUserId) {
                        $reg = registerUser($email, $pass, $name, $phone);
                        if ($reg['success']) {
                            $targetUserId = $reg['user_id'];
                        }
                    }

                    if ($targetUserId) {
                        $stmt = db()->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?');
                        $stmt->execute([$groupId, $targetUserId]);
                        if ($stmt->fetchColumn() == 0) {
                            db()->prepare('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)')->execute([$groupId, $targetUserId]);
                            $addedCount++;
                        } else {
                            $skippedCount++;
                        }
                    } else {
                        $skippedCount++;
                    }
                }
                fclose($handle);

                // Check capacity update
                $newCount = getMemberCount($groupId);
                if ($newCount >= (int)$group['total_members']) {
                    db()->prepare("UPDATE groups SET status = 'active' WHERE id = ?")->execute([$groupId]);
                }

                flash('success', "Import complete! Added $addedCount members to {$group['name']} ($skippedCount skipped or already in group).");
            }
        }
        redirect(APP_URL . '/app/groups/view.php?id=' . $groupId);
    }

    if ($action === 'auto_fill_members' && ($isLeader || isAdmin())) {
        $needed = (int)$group['total_members'] - $memberCount;
        if ($needed <= 0) {
            flash('info', 'Group is already 100% full!');
        } else {
            $addedCount = 0;
            for ($i = 1; $i <= (int)$group['total_members']; $i++) {
                $mEmail = "member{$i}@example.com";
                $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
                $stmt->execute([$mEmail]);
                $targetUserId = $stmt->fetchColumn();

                if (!$targetUserId) {
                    $reg = registerUser($mEmail, 'password123', "Member {$i}", "98765432" . str_pad((string)$i, 2, '0', STR_PAD_LEFT));
                    if ($reg['success']) {
                        $targetUserId = $reg['user_id'];
                    }
                }

                if ($targetUserId) {
                    $stmt = db()->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?');
                    $stmt->execute([$groupId, $targetUserId]);
                    if ($stmt->fetchColumn() == 0) {
                        db()->prepare('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)')->execute([$groupId, $targetUserId]);
                        $addedCount++;
                    }
                }

                $currentCount = getMemberCount($groupId);
                if ($currentCount >= (int)$group['total_members']) {
                    break;
                }
            }

            $newCount = getMemberCount($groupId);
            if ($newCount >= (int)$group['total_members']) {
                db()->prepare("UPDATE groups SET status = 'active' WHERE id = ?")->execute([$groupId]);
            }
            flash('success', "Auto-fill complete! Added {$addedCount} members to fill group to 100% capacity ({$newCount}/{$group['total_members']}). Auction controls are now unlocked!");
        }
        redirect(APP_URL . '/app/groups/view.php?id=' . $groupId);
    }

    if ($action === 'add_single_member' && ($isLeader || isAdmin())) {
        $loginInput = trim($_POST['member_input'] ?? '');
        if (empty($loginInput)) {
            flash('error', 'Please enter a valid email or phone number.');
        } else {
            $cleanPhone = preg_replace('/[^0-9]/', '', $loginInput);
            $stmt = db()->prepare('SELECT id, display_name FROM users WHERE email = ? OR (phone IS NOT NULL AND phone != "" AND (phone = ? OR REPLACE(phone, " ", "") = ? OR REPLACE(phone, "-", "") = ?))');
            $stmt->execute([$loginInput, $loginInput, $cleanPhone, $cleanPhone]);
            $targetUser = $stmt->fetch();

            $targetUserId = 0;
            if (!$targetUser) {
                if (filter_var($loginInput, FILTER_VALIDATE_EMAIL)) {
                    $reg = registerUser($loginInput, 'password123', 'Member', $cleanPhone ?: null);
                    if ($reg['success']) {
                        $targetUserId = $reg['user_id'];
                    }
                }
            } else {
                $targetUserId = $targetUser['id'];
            }

            if (!empty($targetUserId)) {
                $stmt = db()->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?');
                $stmt->execute([$groupId, $targetUserId]);
                if ($stmt->fetchColumn() == 0) {
                    db()->prepare('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)')->execute([$groupId, $targetUserId]);
                    $newCount = getMemberCount($groupId);
                    if ($newCount >= (int)$group['total_members']) {
                        db()->prepare("UPDATE groups SET status = 'active' WHERE id = ?")->execute([$groupId]);
                    }
                    flash('success', 'Member added to group successfully!');
                } else {
                    flash('error', 'Member is already in this group.');
                }
            } else {
                flash('error', 'User not found. Please check email or phone.');
            }
        }
        redirect(APP_URL . '/app/groups/view.php?id=' . $groupId);
    }

    if ($action === 'remove_member' && ($isLeader || isAdmin())) {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if ($targetUserId <= 0) {
            flash('error', 'Invalid member specified.');
        } elseif ($targetUserId === (int)$group['leader_id']) {
            flash('error', 'Group leader cannot be removed from the group.');
        } else {
            $stmt = db()->prepare('SELECT COUNT(*) FROM auction_sessions WHERE group_id = ? AND winner_user_id = ? AND state = "completed"');
            $stmt->execute([$groupId, $targetUserId]);
            if ((int)$stmt->fetchColumn() > 0) {
                flash('error', 'Cannot remove member because they have already won an auction cycle in this group.');
            } else {
                db()->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')->execute([$groupId, $targetUserId]);
                
                $newCount = getMemberCount($groupId);
                if ($newCount < (int)$group['total_members']) {
                    db()->prepare("UPDATE groups SET status = 'open' WHERE id = ?")->execute([$groupId]);
                }

                flash('success', 'Member removed from group successfully.');
            }
        }
        redirect(APP_URL . '/app/groups/view.php?id=' . $groupId);
    }
}

// Get members
$stmt = db()->prepare('
    SELECT u.*, gm.joined_at FROM group_members gm
    JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ?
');
$stmt->execute([$groupId]);
$members = $stmt->fetchAll();

// Get auctions
$stmt = db()->prepare('SELECT * FROM auction_sessions WHERE group_id = ? ORDER BY cycle_number DESC');
$stmt->execute([$groupId]);
$auctions = $stmt->fetchAll();

$stmtCount = db()->prepare('SELECT COUNT(*) FROM auction_sessions WHERE group_id = ? AND state = "completed"');
$stmtCount->execute([$groupId]);
$completedAuctionsCount = (int)$stmtCount->fetchColumn();

$totalDurationMonths = max(1, (int)($group['duration_months'] ?: $group['total_members']));
$remainingAuctionsCount = max(0, $totalDurationMonths - $completedAuctionsCount);

$pageTitle = $group['name'];
$currentPage = 'groups';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/app-nav.php';
?>

<div class="page-header">
    <h1><?= e($group['name']) ?></h1>
    <p>Led by <?= e($group['leader_name']) ?> · Code: <strong style="color:var(--primary);"><?= e($group['join_code']) ?></strong></p>
</div>

<div class="stats-grid" style="margin-bottom:24px;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));">
    <div class="stat-card">
        <div class="stat-label">Chit Value</div>
        <div class="stat-value" style="font-size:20px;"><?= paiseToINR((int)$group['chit_value_paise']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Members</div>
        <div class="stat-value"><?= $memberCount ?>/<?= $group['total_members'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Auctions Progress</div>
        <div class="stat-value" style="font-size:15px;font-weight:700;">
            <?= $completedAuctionsCount ?>/<?= $totalDurationMonths ?> Completed
            <div style="font-size:11px;color:var(--primary);margin-top:2px;">⏳ <?= $remainingAuctionsCount ?> Remaining</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Monthly</div>
        <div class="stat-value" style="font-size:20px;"><?= paiseToINR((int)$group['monthly_contribution_paise']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Status</div>
        <div class="stat-value"><span class="badge badge-<?= $isGroupFull ? 'success' : 'warning' ?>"><?= $isGroupFull ? 'ACTIVE' : 'OPEN' ?></span></div>
    </div>
</div>

<?php if (!$isMember && $group['status'] === 'open' && !$isGroupFull): ?>
<div class="card" style="margin-bottom:24px;text-align:center;">
    <p style="margin-bottom:16px;">Join this group to participate in auctions</p>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="join">
        <button type="submit" class="btn gradient-btn">Join Group</button>
    </form>
</div>
<?php endif; ?>

<?php if ($isLeader): ?>
    <?php if ($isGroupFull): ?>
    <div class="card" style="margin-bottom:24px;border:2px solid #10b981;background:var(--card-bg);padding:24px;border-radius:12px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <h3 class="card-title" style="margin:0;color:#10b981;display:flex;align-items:center;gap:8px;">
                ⚡ Start / Schedule Auction Session
            </h3>
            <span class="badge badge-success">✓ Group 100% Full (<?= $memberCount ?>/<?= $group['total_members'] ?>)</span>
        </div>
        <p style="font-size:13px;color:var(--muted);margin-bottom:16px;">
            All <?= $group['total_members'] ?> members have joined! Launch an immediate live auction or schedule for a future date & time.
        </p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="start_auction">
            
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:12px;margin-bottom:16px;">
                <div class="form-group" style="margin:0;">
                    <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">Cycle #</label>
                    <input type="number" name="cycle_number" class="form-control" value="<?= count($auctions) + 1 ?>" min="1">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">Start Timing</label>
                    <select name="start_mode" class="form-control" onchange="document.getElementById('leaderStartBox').style.display = this.value === 'scheduled' ? 'block' : 'none';">
                        <option value="now" selected>⚡ Start Immediately (Now)</option>
                        <option value="scheduled">🗓️ Schedule Future Date/Time</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">End Timing</label>
                    <select name="end_mode" class="form-control" onchange="document.getElementById('leaderDurationBox').style.display = this.value === 'duration' ? 'block' : 'none'; document.getElementById('leaderEndBox').style.display = this.value === 'custom' ? 'block' : 'none';">
                        <option value="duration" selected>⏱️ Select Duration</option>
                        <option value="custom">📅 Custom End Date/Time</option>
                    </select>
                </div>
            </div>

            <!-- Advanced Custom Inputs -->
            <div id="leaderStartBox" style="display:none;margin-bottom:16px;">
                <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">Custom Start Date & Time</label>
                <input type="datetime-local" name="starts_at_custom" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime('+10 minutes')) ?>">
            </div>

            <div id="leaderDurationBox" style="margin-bottom:16px;">
                <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">Duration (Hours)</label>
                <select name="duration_hours" class="form-control">
                    <option value="0.25">15 Minutes</option>
                    <option value="0.5" selected>0.5 Hours (30 Mins)</option>
                    <option value="1">1 Hour</option>
                    <option value="2">2 Hours</option>
                    <option value="6">6 Hours</option>
                    <option value="12">12 Hours</option>
                    <option value="24">24 Hours (1 Day)</option>
                </select>
            </div>

            <div id="leaderEndBox" style="display:none;margin-bottom:16px;">
                <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">Custom End Date & Time</label>
                <input type="datetime-local" name="ends_at_custom" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>">
            </div>

            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:16px;">
                <button type="submit" name="submit_mode" value="schedule" class="btn btn-success" style="flex:1;min-width:200px;font-weight:700;padding:12px 18px;">
                    🚀 Launch / Schedule Auction Now
                </button>
                <button type="submit" name="submit_mode" value="enter_room" class="btn" style="flex:1;min-width:220px;background:linear-gradient(135deg, #8b5cf6, #ec4899);color:#fff;font-weight:700;padding:12px 18px;border:none;border-radius:8px;box-shadow:0 4px 15px rgba(139,92,246,0.4);">
                    🎰 Enter Auction & Spin Wheel Room →
                </button>
            </div>
        </form>
    </div>
    <?php else: ?>
    <div class="card" style="margin-bottom:24px;border:1px dashed var(--border);background:var(--card-bg);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <h3 class="card-title" style="margin:0;color:var(--muted);display:flex;align-items:center;gap:8px;">
                🔒 Auction Controls Locked
            </h3>
            <span class="badge badge-warning">Waiting for Members (<?= $memberCount ?>/<?= $group['total_members'] ?>)</span>
        </div>
        <p style="font-size:13px;color:var(--muted);margin:0;">
            Auctions will automatically activate as soon as all <strong><?= $group['total_members'] ?></strong> members join this group! (<strong><?= max(0, (int)$group['total_members'] - $memberCount) ?> spots remaining</strong>). Share join code <code style="font-weight:700;color:var(--primary);"><?= e($group['join_code']) ?></code> with members to fill the group.
        </p>
    </div>
    <?php endif; ?>
<?php endif; ?>

<section style="margin-bottom:24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:12px;">
        <h2 class="section-title" style="margin:0;">Auctions History</h2>
        <div style="display:flex;gap:8px;align-items:center;">
            <span class="badge badge-success">✓ Completed: <?= $completedAuctionsCount ?> / <?= $totalDurationMonths ?></span>
            <span class="badge badge-primary">⏳ Remaining Auctions: <?= $remainingAuctionsCount ?></span>
        </div>
    </div>
    <?php if (empty($auctions)): ?>
    <div class="empty-state"><p>No auctions yet.</p></div>
    <?php else: ?>
    <?php foreach ($auctions as $a): ?>
    <div class="list-item" style="display:flex;justify-content:space-between;align-items:center;padding:14px;margin-bottom:8px;background:var(--card-bg);border:1px solid var(--border);border-radius:10px;">
        <div>
            <a href="<?= APP_URL ?>/app/auction.php?id=<?= $a['id'] ?>" style="text-decoration:none;color:inherit;">
                <strong>Cycle #<?= $a['cycle_number'] ?></strong>
                <span class="badge badge-<?= $a['state'] === 'live' ? 'live' : ($a['state'] === 'completed' ? 'success' : 'primary') ?>">
                    <?= e($a['state']) ?>
                </span>
                <?php if (!empty($a['venue'])): ?>
                <div style="font-size:12px;color:var(--primary);margin-top:4px;">📍 <?= e($a['venue']) ?></div>
                <?php endif; ?>
            </a>
        </div>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <div class="text-muted" style="font-size:12px;">
                <?php if ($a['state'] === 'scheduled'): ?>
                Starts: <?= formatDateShort($a['starts_at']) ?>
                <?php elseif ($a['state'] === 'live'): ?>
                Ends: <?= formatDateShort($a['ends_at']) ?>
                <?php elseif ($a['state'] === 'completed' && $a['winning_discount_paise']): ?>
                Discount: <?= paiseToINR((int)$a['winning_discount_paise']) ?>
                <?php endif; ?>
            </div>
            <a href="<?= APP_URL ?>/app/auction.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-primary" style="text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg, #8b5cf6, #ec4899);color:#fff;border:none;">
                🎰 Enter Auction & Spin Wheel Room →
            </a>
            <?php if ($isLeader): ?>
            <a href="<?= APP_URL ?>/admin/auction_alerts.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline" style="text-decoration:none;">
                🔔 Alerts
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</section>

<section>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:10px;">
        <h2 class="section-title" style="margin:0;">Members (<?= count($members) ?> / <?= $group['total_members'] ?>)</h2>
        <?php if ($isLeader || isAdmin()): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <?php if (!$isGroupFull): ?>
            <form method="POST" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="auto_fill_members">
                <button type="submit" class="btn btn-sm btn-success" style="font-weight:700;">
                    ⚡ Auto-Fill All <?= $group['total_members'] ?> Members
                </button>
            </form>
            <button onclick="document.getElementById('addSingleMemberBox').style.display = document.getElementById('addSingleMemberBox').style.display === 'none' ? 'block' : 'none';" class="btn btn-sm btn-primary">
                ➕ Add Member
            </button>
            <?php endif; ?>
            <button onclick="document.getElementById('importMembersBox').style.display = document.getElementById('importMembersBox').style.display === 'none' ? 'block' : 'none';" class="btn btn-sm btn-outline">
                📥 Import Members (CSV)
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($isLeader || isAdmin()): ?>
    <!-- Single Member Add Box -->
    <div id="addSingleMemberBox" style="display:none;margin-bottom:16px;padding:16px;background:var(--card-bg);border:1px dashed var(--primary);border-radius:10px;">
        <h4 style="font-size:14px;font-weight:700;margin-bottom:6px;color:var(--primary);">➕ Add Member by Email or Phone</h4>
        <form method="POST" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_single_member">
            <input type="text" name="member_input" class="form-control" placeholder="Enter Member Email or Phone" required style="max-width:300px;">
            <button type="submit" class="btn btn-sm btn-primary">Add Member</button>
        </form>
    </div>

    <!-- Bulk Import CSV Box -->
    <div id="importMembersBox" style="display:none;margin-bottom:16px;padding:16px;background:var(--card-bg);border:1px dashed var(--primary);border-radius:10px;">
        <h4 style="font-size:14px;font-weight:700;margin-bottom:6px;color:var(--primary);">📥 Bulk Import Members from Excel / CSV</h4>
        <p style="font-size:12px;color:var(--muted);margin-bottom:12px;">Upload a CSV or Excel file (Columns: <code>Name</code>, <code>Email</code>, <code>Phone</code>, <code>Password</code>). New users will be automatically registered and added to <strong><?= e($group['name']) ?></strong>.</p>
        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="import_group_members">
            <input type="file" name="import_file" class="form-control" accept=".csv, .txt, .xlsx, .xls" required style="max-width:280px;padding:6px;">
            <button type="submit" class="btn btn-sm btn-primary">📤 Upload & Add Members</button>
            <a href="<?= APP_URL ?>/admin/users.php?download_template=1" class="text-muted" style="font-size:12px;text-decoration:underline;">Download Sample CSV</a>
        </form>
    </div>
    <?php endif; ?>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Joined</th>
                    <?php if ($isLeader || isAdmin()): ?>
                    <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($members as $m): ?>
                <tr>
                    <td>
                        <strong><?= e($m['display_name']) ?></strong>
                        <?php if ((int)$m['id'] === (int)$group['leader_id']): ?>
                        <span class="badge badge-primary" style="font-size:10px;margin-left:4px;">Leader</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($m['email']) ?></td>
                    <td class="text-muted"><?= formatDateShort($m['joined_at']) ?></td>
                    <?php if ($isLeader || isAdmin()): ?>
                    <td>
                        <?php if ((int)$m['id'] !== (int)$group['leader_id']): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to remove <?= e($m['display_name']) ?> from this group?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="remove_member">
                            <input type="hidden" name="user_id" value="<?= $m['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline" style="color:#ef4444;border-color:#ef4444;font-size:11px;padding:3px 8px;font-weight:600;">
                                ❌ Remove
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
