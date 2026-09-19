<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$user = currentUser();
$auctionId = (int) ($_GET['id'] ?? 0);

if (!$auctionId) {
    flash('error', 'Auction ID required');
    redirect(APP_URL . '/app/groups/index.php');
}

$stmt = db()->prepare('
    SELECT a.*, g.name as group_name, g.chit_value_paise, g.commission_pct, g.leader_id, g.id as gid
    FROM auction_sessions a
    JOIN groups g ON g.id = a.group_id
    WHERE a.id = ?
');
$stmt->execute([$auctionId]);
$auction = $stmt->fetch();

if (!$auction) {
    flash('error', 'Auction session not found');
    redirect(APP_URL . '/app/groups/index.php');
}

$isMember = isGroupMember($auction['group_id'], $user['id']);
$isLeader = ($auction['leader_id'] == $user['id']);

if (!$isMember && !$isLeader && !isAdmin()) {
    flash('error', 'Access denied to this auction');
    redirect(APP_URL . '/app/groups/index.php');
}

// Fetch Group Total Duration
$stmtGroup = db()->prepare('SELECT duration_months, total_members FROM groups WHERE id = ?');
$stmtGroup->execute([$auction['group_id']]);
$groupInfo = $stmtGroup->fetch();
$groupDurationMonths = max(1, (int)($groupInfo['duration_months'] ?? $groupInfo['total_members']));
$isLastCycle = ((int)$auction['cycle_number'] >= $groupDurationMonths);

// Minimum 4% Starting Bid Discount
$commissionPct = (float)($auction['commission_pct'] ?: 4.0);
$minAllowedDiscountPaise = (int)round($auction['chit_value_paise'] * ($commissionPct / 100));
$minAllowedRupees = (int)ceil($minAllowedDiscountPaise / 100);

// API Sync Endpoint
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $stmt = db()->prepare('
        SELECT b.*, u.display_name FROM bids b
        JOIN users u ON u.id = b.user_id
        WHERE b.auction_id = ?
        ORDER BY b.discount_paise DESC, b.created_at ASC
    ');
    $stmt->execute([$auctionId]);
    $bidsList = $stmt->fetchAll();
    $top = $bidsList[0] ?? null;
    $secondsRemaining = max(0, strtotime($auction['ends_at']) - time());

    // Fetch eligible members for spin wheel
    $stmtMembers = db()->prepare('
        SELECT gm.user_id, u.display_name
        FROM group_members gm
        JOIN users u ON u.id = gm.user_id
        WHERE gm.group_id = ? AND gm.user_id NOT IN (
            SELECT winner_user_id FROM auction_sessions
            WHERE group_id = ? AND state = "completed" AND winner_user_id IS NOT NULL
        )
        ORDER BY gm.joined_at ASC
    ');
    $stmtMembers->execute([$auction['group_id'], $auction['group_id']]);
    $eligibleMembers = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);

    $spinWinnerName = '';
    if (!empty($auction['spin_winner_user_id'])) {
        $stmt = db()->prepare('SELECT display_name FROM users WHERE id = ?');
        $stmt->execute([$auction['spin_winner_user_id']]);
        $spinWinnerName = (string)$stmt->fetchColumn();
    }

    echo json_encode([
        'success' => true,
        'state' => $auction['state'],
        'ends_at' => $auction['ends_at'],
        'ends_at_timestamp' => strtotime($auction['ends_at']),
        'seconds_remaining' => $secondsRemaining,
        'top_bid_paise' => $top ? (int)$top['discount_paise'] : 0,
        'top_bid_formatted' => $top ? paiseToINR((int)$top['discount_paise']) : '—',
        'top_bidder' => $top ? $top['display_name'] : 'No bids yet',
        'total_bids' => count($bidsList),
        'spin_status' => $auction['spin_status'] ?? 'none',
        'spin_duration_seconds' => (int)($auction['spin_duration_seconds'] ?? 8),
        'spin_started_at_ts' => !empty($auction['spin_started_at']) ? strtotime($auction['spin_started_at']) : 0,
        'spin_winner_user_id' => (int)($auction['spin_winner_user_id'] ?? 0),
        'spin_winner_name' => $spinWinnerName,
        'spin_target_angle' => (int)($auction['spin_target_angle'] ?? 0),
        'eligible_members' => $eligibleMembers,
        'bids' => array_map(function($b) {
            return [
                'id' => (int)$b['id'],
                'user_id' => (int)$b['user_id'],
                'display_name' => $b['display_name'],
                'discount_paise' => (int)$b['discount_paise'],
                'discount_formatted' => paiseToINR((int)$b['discount_paise']),
                'created_at_formatted' => formatDate($b['created_at'])
            ];
        }, $bidsList)
    ]);
    exit;
}

// Handle bid / close / trigger_spin
if (isPost() && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'bid' && $auction['state'] === 'live') {
        $discountRupees = (float)($_POST['discount'] ?? 0);
        $discountPaise = rupeesToPaise($discountRupees);
        $result = placeBid($auctionId, $user['id'], $discountPaise);
        flash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(APP_URL . '/app/auction.php?id=' . $auctionId);
    }

    if ($action === 'close' && ($isLeader || isAdmin())) {
        $result = closeAuction($auctionId, $user['id']);
        flash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(APP_URL . '/app/auction.php?id=' . $auctionId);
    }

    if ($action === 'trigger_spin' && ($isLeader || isAdmin())) {
        $durationSeconds = (int)($_POST['spin_duration'] ?? 8);
        $targetWinnerId = (int)($_POST['target_winner_user_id'] ?? 0);
        $result = triggerSpinWheel($auctionId, $user['id'], $durationSeconds, $targetWinnerId);
        if (isset($_GET['api']) || isset($_POST['api_json'])) {
            header('Content-Type: application/json');
            echo json_encode($result);
            exit;
        }
        flash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(APP_URL . '/app/auction.php?id=' . $auctionId);
    }
}

// Refresh auction data
$stmt = db()->prepare('SELECT * FROM auction_sessions WHERE id = ?');
$stmt->execute([$auctionId]);
$auction = array_merge($auction, $stmt->fetch());

// Get bids
$stmt = db()->prepare('
    SELECT b.*, u.display_name FROM bids b
    JOIN users u ON u.id = b.user_id
    WHERE b.auction_id = ?
    ORDER BY b.discount_paise DESC, b.created_at ASC
');
$stmt->execute([$auctionId]);
$bids = $stmt->fetchAll();

// Auto-transition scheduled auction to live if start time reached
if ($auction['state'] === 'scheduled' && strtotime($auction['starts_at']) <= time()) {
    db()->prepare("UPDATE auction_sessions SET state = 'live' WHERE id = ?")->execute([$auctionId]);
    $auction['state'] = 'live';
}

$topBid = $bids[0] ?? null;
$isLive = $auction['state'] === 'live' && strtotime($auction['ends_at']) > time();
$endsTimestamp = strtotime($auction['ends_at']);

// Check if current user has won any previous auction in this group
$stmtPreviousWin = db()->prepare('
    SELECT COUNT(*) FROM auction_sessions
    WHERE group_id = ? AND winner_user_id = ? AND state = "completed"
');
$stmtPreviousWin->execute([$auction['group_id'], $user['id']]);
$hasWonPreviousAuction = ($stmtPreviousWin->fetchColumn() > 0);

// Find last remaining member for final cycle
$stmtLastMember = db()->prepare('
    SELECT gm.user_id, u.display_name FROM group_members gm
    JOIN users u ON u.id = gm.user_id
    WHERE gm.group_id = ? AND gm.user_id NOT IN (
        SELECT winner_user_id FROM auction_sessions
        WHERE group_id = ? AND state = "completed" AND winner_user_id IS NOT NULL
    )
    LIMIT 1
');
$stmtLastMember->execute([$auction['group_id'], $auction['group_id']]);
$lastNonWinner = $stmtLastMember->fetch();

// Maximum 45% cap
$maxDiscountPaise = (int)round($auction['chit_value_paise'] * 0.45);
$maxBidRupees = (int)floor($maxDiscountPaise / 100);

if ($topBid) {
    $minBidRupees = (int)(ceil(((int)$topBid['discount_paise'] + 10000) / 10000) * 100);
} else {
    $minBidRupees = $minAllowedRupees;
}

// Fetch eligible non-winning group members for Admin/Leader Spin Wheel Selection
$stmtEligible = db()->prepare('
    SELECT gm.user_id, u.display_name
    FROM group_members gm
    JOIN users u ON u.id = gm.user_id
    WHERE gm.group_id = ? AND gm.user_id NOT IN (
        SELECT winner_user_id FROM auction_sessions
        WHERE group_id = ? AND state = "completed" AND winner_user_id IS NOT NULL
    )
    ORDER BY gm.joined_at ASC
');
$stmtEligible->execute([$auction['group_id'], $auction['group_id']]);
$eligibleMembersForSelect = $stmtEligible->fetchAll();

$pageTitle = 'Live Auction - ' . $auction['group_name'];
$currentPage = 'groups';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/app-nav.php';
?>

<!-- LIVE ALERT NOTIFICATION BANNER -->
<div id="liveAlertBox" class="alert alert-info" style="display:none;margin-bottom:16px;border-left:4px solid var(--primary);animation:fadeIn 0.3s ease-out;">
    <strong id="alertTitle">🔔 Auction Alert</strong>: <span id="alertBody"></span>
</div>

<div class="page-header">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div>
            <h1>Cycle #<?= $auction['cycle_number'] ?> Auction: <?= e($auction['group_name']) ?></h1>
            <p class="text-muted" style="margin-top:4px;">
                Cycle <?= $auction['cycle_number'] ?> of <?= $groupDurationMonths ?> · Total Chit Value: <strong><?= paiseToINR((int)$auction['chit_value_paise']) ?></strong>
            </p>
        </div>
        <div>
            <span class="badge badge-<?= $auction['state'] === 'live' ? 'success' : ($auction['state'] === 'completed' ? 'primary' : 'warning') ?>" style="font-size:14px;padding:8px 16px;">
                State: <?= strtoupper($auction['state']) ?>
            </span>
        </div>
    </div>
</div>

<div class="stats-grid" style="margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-label">
            <?= $auction['state'] === 'completed' ? 'Auction Status' : 'Live Auction Clock' ?>
        </div>
        <div class="stat-value" id="liveTimerClock" style="font-size:22px;color:<?= $auction['state'] === 'completed' ? '#10b981' : 'var(--primary)' ?>;font-family:monospace;">
            <?= $auction['state'] === 'completed' ? '00:00:00 (COMPLETED)' : '00:00:00' ?>
        </div>
        <div id="liveClockSub" style="font-size:11px;color:var(--muted);margin-top:4px;">
            <?= $auction['state'] === 'completed' ? 'Auction Settled' : 'Ends: ' . formatDate($auction['ends_at']) ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Current Highest Bid</div>
        <div class="stat-value" id="statTopBid" style="font-size:24px;color:var(--success);">
            <?= $topBid ? paiseToINR((int)$topBid['discount_paise']) : '—' ?>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:4px;" id="liveBidderName">
            <?= $topBid ? e($topBid['display_name']) : 'No bids placed yet' ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Total Bids Placed</div>
        <div class="stat-value" id="statTotalBids" style="font-size:24px;"><?= count($bids) ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:4px;">Live Bids Submitted</div>
    </div>
</div>

<?php if ($isLastCycle && $auction['state'] !== 'completed'): ?>
<div class="card" style="margin-bottom:24px;background:linear-gradient(135deg, rgba(16,185,129,0.08), rgba(99,102,241,0.08));border:2px solid #10b981;border-radius:14px;padding:24px;">
    <h3 style="margin:0 0 8px 0;color:#10b981;display:flex;align-items:center;gap:8px;">
        👑 Final Auction Cycle (#<?= $auction['cycle_number'] ?> of <?= $groupDurationMonths ?>)
    </h3>
    <p style="margin:0;font-size:14px;line-height:1.6;color:var(--text-color);">
        As per Chit Fund rules, the last remaining member <strong><?= e($lastNonWinner['display_name'] ?? 'Final Member') ?></strong> automatically receives the final chit pot payout of <strong style="color:var(--success);font-size:18px;"><?= paiseToINR((int)$auction['chit_value_paise'] - $minAllowedDiscountPaise) ?></strong> (Chit Value <?= paiseToINR((int)$auction['chit_value_paise']) ?> − 4% Leader Commission <?= paiseToINR($minAllowedDiscountPaise) ?>). No manual bidding required!
    </p>
</div>
<?php elseif ($isLive && $isMember && !$hasWonPreviousAuction): ?>
<div class="card" style="margin-bottom:24px;">
    <h3 class="card-title">Place Your Bid</h3>
    <p class="card-desc">Bids start at or above 4% leader commission (<?= paiseToINR($minAllowedDiscountPaise) ?>) up to max 45% discount (<?= paiseToINR($maxDiscountPaise) ?>) in multiples of ₹100.</p>
    <?php if ($minBidRupees <= $maxBidRupees): ?>
    <form method="POST" class="bid-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="bid">
        <div style="flex:1;">
            <label>Discount Amount (₹)</label>
            <input type="number" id="bidInput" name="discount" class="form-control" required
                min="<?= $minBidRupees ?>" max="<?= $maxBidRupees ?>" step="100"
                value="<?= $minBidRupees ?>" placeholder="e.g. <?= $minBidRupees ?>">
            <div style="font-size:12px;color:var(--muted);margin-top:6px;">
                Min Bid: <strong>₹<?= number_format($minBidRupees) ?></strong> (Min 4%: <?= paiseToINR($minAllowedDiscountPaise) ?>) · Max Bid (45%): <strong>₹<?= number_format($maxBidRupees) ?></strong> · Multiples of <strong>₹100</strong>
            </div>
        </div>
        <button type="submit" class="btn gradient-btn" style="align-self:end;">Place Bid</button>
    </form>
    <?php else: ?>
    <div class="alert alert-info" style="margin-top:12px;">
        ⚠️ Maximum 45% discount cap (<?= paiseToINR($maxDiscountPaise) ?>) reached. No further higher bids can be placed.
    </div>
    <?php endif; ?>
</div>
<?php elseif ($isLive && $isMember && $hasWonPreviousAuction): ?>
<div class="card" style="margin-bottom:24px;background:rgba(59,130,246,0.05);border-color:rgba(59,130,246,0.2);">
    <h4 style="margin:0;color:var(--primary);display:flex;align-items:center;gap:8px;font-size:16px;">
        <span>🏆</span> You have already won an auction in this group
    </h4>
    <p class="text-muted" style="margin-top:6px;font-size:13px;margin-bottom:0;line-height:1.5;">
        As a previous auction winner for <strong><?= e($auction['group_name']) ?></strong>, your chit pot payout has been disbursed and you are not eligible to bid in remaining cycles.
    </p>
</div>
<?php endif; ?>

<!-- LEADER / ADMIN SPIN WHEEL CONTROLS FOR ZERO-BID FALLBACK -->
<?php if (($isLeader || isAdmin()) && $auction['state'] !== 'completed' && !$isLastCycle): ?>
<div class="card" style="margin-bottom:24px;background:linear-gradient(135deg, rgba(99,102,241,0.08), rgba(236,72,153,0.08));border:2px solid #8b5cf6;border-radius:14px;padding:24px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
        <h3 style="margin:0;color:#8b5cf6;display:flex;align-items:center;gap:8px;font-size:18px;">
            🎰 Spin Lucky Wheel Draw (Zero-Bid Fallback)
        </h3>
        <span class="badge badge-primary" style="background:#8b5cf6;color:#fff;"><?= count($bids) ?> Bids Placed</span>
    </div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:16px;line-height:1.5;">
        If no manual bids are placed or you want to pick a lucky winner, launch a live synchronized Spin Wheel draw! Every logged-in user viewing this auction room will see the wheel spin live on their screen simultaneously. The winner receives <strong><?= paiseToINR((int)$auction['chit_value_paise'] - $minAllowedDiscountPaise) ?></strong> (Chit Value − 4% Leader Commission).
    </p>

    <form method="POST" style="display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="trigger_spin">
        
        <div style="flex:1.5;min-width:220px;">
            <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">🎯 Pre-Select Spin Winner (Admin Control)</label>
            <select name="target_winner_user_id" class="form-control" style="font-weight:600;">
                <option value="0">🎲 Random / Automatic Selection</option>
                <?php foreach ($eligibleMembersForSelect as $m): ?>
                <option value="<?= $m['user_id'] ?>">👑 <?= e($m['display_name']) ?> (User #<?= $m['user_id'] ?>)</option>
                <?php endforeach; ?>
            </select>
            <div style="font-size:11px;color:var(--muted);margin-top:4px;">Select winner before wheel stops, or leave on Random</div>
        </div>

        <div style="width:130px;">
            <label style="font-weight:600;font-size:12px;display:block;margin-bottom:4px;">Spin Time (Sec)</label>
            <input type="number" name="spin_duration" class="form-control" value="8" min="3" max="30" required placeholder="e.g. 8">
            <div style="font-size:11px;color:var(--muted);margin-top:4px;">3s to 30s</div>
        </div>

        <button type="submit" class="btn" style="background:linear-gradient(135deg, #8b5cf6, #ec4899);color:#fff;font-weight:700;padding:12px 24px;border:none;border-radius:10px;box-shadow:0 4px 15px rgba(139,92,246,0.4);">
            🎰 Launch Live Multi-User Spin Wheel Draw →
        </button>
    </form>
</div>
<?php endif; ?>

<?php if ($isLeader && $auction['state'] === 'live'): ?>
<div class="card" style="margin-bottom:24px;border-color:rgba(220,38,38,.3);">
    <h3 class="card-title">Leader Manual Settle Controls</h3>
    <form method="POST" onsubmit="return confirm('Close this auction and settle payouts?');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="close">
        <button type="submit" class="btn btn-danger">Close Auction & Settle Top Bid</button>
    </form>
</div>
<?php endif; ?>

<?php if ($auction['state'] === 'completed'): ?>
<div class="card" style="margin-bottom:24px;background:rgba(5,150,105,.05);border-color:rgba(5,150,105,.2);">
    <h3 style="color:var(--success);">✓ Auction Completed</h3>
    <?php if ($auction['winning_discount_paise']): ?>
    <p style="margin-top:8px;">Winning discount: <strong><?= paiseToINR((int)$auction['winning_discount_paise']) ?></strong></p>
    <?php
    $stmt = db()->prepare('SELECT display_name FROM users WHERE id = ?');
    $stmt->execute([$auction['winner_user_id']]);
    $winner = $stmt->fetchColumn();
    if ($winner): ?>
    <p>Winner: <strong><?= e($winner) ?></strong></p>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<section>
    <h2 class="section-title">Bid History</h2>
    <div class="card">
        <?php if (empty($bids)): ?>
        <div class="empty-state" style="padding:24px;">No bids placed yet in this auction cycle.</div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Bidder</th>
                        <th class="text-right">Discount</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody id="bidsTableBody">
                    <?php foreach ($bids as $i => $bid): ?>
                    <tr style="<?= $i === 0 ? 'background:rgba(5,150,105,.05);' : '' ?>">
                        <td><?= $i + 1 ?></td>
                        <td>
                            <?= e($bid['display_name']) ?>
                            <?php if ($i === 0): ?><span class="badge badge-success" style="margin-left:4px;">Top</span><?php endif; ?>
                        </td>
                        <td class="text-right text-success" style="font-weight:600;"><?= paiseToINR((int)$bid['discount_paise']) ?></td>
                        <td class="text-muted"><?= formatDate($bid['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</section>

<p style="margin-top:24px;">
    <a href="<?= APP_URL ?>/app/groups/view.php?id=<?= $auction['group_id'] ?>">← Back to Group</a>
</p>

<!-- REAL-TIME MULTI-USER LIVE SPIN WHEEL MODAL OVERLAY -->
<div id="spinWheelModal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(15,23,42,0.96);backdrop-filter:blur(16px);z-index:99999;overflow-y:auto;overflow-x:hidden;padding:16px;box-sizing:border-box;">
    <div style="max-width:480px;width:100%;margin:auto;display:flex;flex-direction:column;align-items:center;position:relative;padding-top:10px;padding-bottom:30px;">
        <button onclick="document.getElementById('spinWheelModal').style.display='none';" style="position:absolute;top:0;right:0;background:rgba(255,255,255,0.1);border:none;color:#9ca3af;font-size:18px;width:32px;height:32px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;">✕</button>

        <div style="text-align:center;margin-bottom:10px;">
            <span class="badge" style="background:linear-gradient(90deg, #ec4899, #8b5cf6);color:#fff;font-size:11px;padding:4px 12px;letter-spacing:1px;text-transform:uppercase;font-weight:700;">
                🎰 LIVE LUCKY SPIN DRAW
            </span>
            <h2 style="font-size:20px;font-weight:800;color:#fff;margin:6px 0 2px 0;">Selecting Lucky Auction Winner</h2>
            <div id="spinWheelSubTitle" style="font-size:12px;color:#9ca3af;">Synchronized live for all viewing group members...</div>
        </div>

        <div style="position:relative;width:280px;height:280px;margin:6px 0;max-width:100%;">
            <div style="position:absolute;top:-8px;left:-8px;right:-8px;bottom:-8px;border-radius:50%;background:linear-gradient(135deg, #ec4899, #8b5cf6, #3b82f6);box-shadow:0 0 30px rgba(139,92,246,0.6);"></div>
            <canvas id="spinCanvas" width="280" height="280" style="position:relative;z-index:2;border-radius:50%;background:#1e293b;width:280px;height:280px;display:block;"></canvas>
            <div style="position:absolute;top:-14px;left:50%;transform:translateX(-50%);z-index:10;width:0;height:0;border-left:14px solid transparent;border-right:14px solid transparent;border-top:24px solid #ef4444;filter:drop-shadow(0 4px 6px rgba(0,0,0,0.5));"></div>
            <div style="position:absolute;top:50%;left:50%;transform:translate(-50%, -50%);z-index:10;width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg, #1e293b, #0f172a);border:3px solid #8b5cf6;box-shadow:0 0 15px rgba(0,0,0,0.8);display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;font-weight:900;">
                🎰
            </div>
        </div>

        <div id="spinWinnerNotice" style="display:none;margin-top:12px;width:100%;background:#1e293b;border:2px solid #10b981;box-shadow:0 15px 35px rgba(0,0,0,0.8);border-radius:14px;padding:16px;text-align:center;color:#fff;box-sizing:border-box;">
            <div style="font-size:32px;margin-bottom:4px;">🎉 🏆 🎉</div>
            <h3 id="spinWinnerTitle" style="font-size:18px;font-weight:800;color:#10b981;margin:0 0 4px 0;">WINNER ANNOUNCED!</h3>
            <p id="spinWinnerDesc" style="font-size:13px;color:#d1d5db;margin:0 0 14px 0;line-height:1.4;"></p>
            <button onclick="location.reload();" class="btn btn-success btn-block" style="font-size:14px;padding:10px 14px;font-weight:700;border-radius:8px;width:100%;">
                View Settlement & Ledger Payout →
            </button>
        </div>
    </div>
</div>

<!-- REAL-TIME JS TIMER & SPIN WHEEL ENGINE -->
<script>
window.formattedChitPayout = "<?= paiseToINR((int)$auction['chit_value_paise'] - $minAllowedDiscountPaise) ?>";
(function() {
    const endsTimestamp = <?= (int)$endsTimestamp ?>;
    const isLive = <?= $isLive ? 'true' : 'false' ?>;
    const currentUserId = <?= (int)$user['id'] ?>;
    const isCurrentLeader = <?= ($isLeader || isAdmin()) ? 'true' : 'false' ?>;
    let lastTopBidPaise = <?= $topBid ? (int)$topBid['discount_paise'] : 0 ?>;
    let isSpinningActive = false;

    const auctionState = "<?= e($auction['state']) ?>";

    function formatTime(seconds) {
        if (seconds <= 0) return '00:00:00';
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        return [h, m, s].map(v => String(v).padStart(2, '0')).join(':');
    }

    function updateTimer() {
        const clockEl = document.getElementById('liveTimerClock');
        if (!clockEl) return;

        if (auctionState === 'completed') {
            clockEl.textContent = '00:00:00 (COMPLETED)';
            clockEl.style.color = '#10b981';
            return;
        }

        const now = Math.floor(Date.now() / 1000);
        const remaining = endsTimestamp - now;
        if (remaining <= 0 || !isLive) {
            clockEl.textContent = '00:00:00 (EXPIRED)';
            clockEl.style.color = '#ef4444';
        } else {
            clockEl.textContent = formatTime(remaining);
            clockEl.style.color = 'var(--primary)';
        }
    }

    function showAlert(title, body) {
        const box = document.getElementById('liveAlertBox');
        if (box) {
            document.getElementById('alertTitle').textContent = title;
            document.getElementById('alertBody').textContent = body;
            box.style.display = 'block';
            setTimeout(() => { box.style.display = 'none'; }, 6000);
        }
    }

    // Audio synthesizer
    let audioCtx = null;
    function playTickSound() {
        try {
            if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(600, audioCtx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(120, audioCtx.currentTime + 0.04);
            gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
            gain.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 0.04);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.04);
        } catch(e) {}
    }

    function playVictorySound() {
        try {
            if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const notes = [523.25, 659.25, 783.99, 1046.50];
            notes.forEach((freq, i) => {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0, audioCtx.currentTime + i * 0.12);
                gain.gain.linearRampToValueAtTime(0.4, audioCtx.currentTime + i * 0.12 + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + i * 0.12 + 0.4);
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start(audioCtx.currentTime + i * 0.12);
                osc.stop(audioCtx.currentTime + i * 0.12 + 0.4);
            });
        } catch(e) {}
    }

    const colors = ['#10b981', '#6366f1', '#f59e0b', '#ec4899', '#3b82f6', '#8b5cf6', '#14b8a6', '#f43f5e', '#06b6d4', '#84cc16'];

    function drawWheel(members, rotationAngleRad) {
        const canvas = document.getElementById('spinCanvas');
        if (!canvas || !members || members.length === 0) return;
        const ctx = canvas.getContext('2d');
        const cx = canvas.width / 2;
        const cy = canvas.height / 2;
        const radius = cx - 6;
        const sliceAngle = (2 * Math.PI) / members.length;

        ctx.clearRect(0, 0, canvas.width, canvas.height);

        members.forEach((m, idx) => {
            const startAngle = rotationAngleRad + (idx * sliceAngle);
            const endAngle = startAngle + sliceAngle;

            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.arc(cx, cy, radius, startAngle, endAngle);
            ctx.closePath();

            ctx.fillStyle = colors[idx % colors.length];
            ctx.fill();
            ctx.lineWidth = 2;
            ctx.strokeStyle = '#ffffff';
            ctx.stroke();

            // Text Label
            ctx.save();
            ctx.translate(cx, cy);
            ctx.rotate(startAngle + sliceAngle / 2);
            ctx.textAlign = 'right';
            ctx.fillStyle = '#ffffff';
            const fontSize = members.length > 14 ? '10px' : (members.length > 10 ? '11px' : '12px');
            ctx.font = `bold ${fontSize} Inter, sans-serif`;
            ctx.shadowColor = 'rgba(0,0,0,0.6)';
            ctx.shadowBlur = 4;
            ctx.fillText(m.display_name, radius - 16, 4);
            ctx.restore();
        });
    }

    function animateSpin(members, durationSec, targetAngleDeg, startTimeTs, winnerName) {
        const modal = document.getElementById('spinWheelModal');
        if (modal) modal.style.display = 'block';

        let lastSliceIdx = -1;
        const targetRad = (targetAngleDeg * Math.PI) / 180;
        const durationMs = durationSec * 1000;

        function step() {
            const nowMs = Date.now();
            const elapsedMs = Math.max(0, nowMs - (startTimeTs * 1000));
            const progress = Math.min(1, elapsedMs / durationMs);
            const easedProgress = 1 - Math.pow(1 - progress, 3);
            const currentAngleRad = easedProgress * targetRad;

            drawWheel(members, currentAngleRad);

            const currentAngleDeg = (currentAngleRad * 180) / Math.PI;
            const sliceDeg = 360 / members.length;
            const currentSliceIdx = Math.floor((currentAngleDeg % 360) / sliceDeg);
            if (currentSliceIdx !== lastSliceIdx) {
                playTickSound();
                lastSliceIdx = currentSliceIdx;
            }

            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                playVictorySound();
                const notice = document.getElementById('spinWinnerNotice');
                const title = document.getElementById('spinWinnerTitle');
                const desc = document.getElementById('spinWinnerDesc');
                if (notice && title && desc) {
                    title.textContent = `🎉 WINNER: ${winnerName}!`;
                    desc.innerHTML = `The lucky spin wheel landed on <strong>${winnerName}</strong>!<br>Net Chit Pot Payout: <strong style="color:#10b981;font-size:16px;">${window.formattedChitPayout}</strong> (Chit Value − 4% Leader Commission).`;
                    notice.style.display = 'block';
                }

                if (isCurrentLeader && !window.hasAutoSettledSpin) {
                    window.hasAutoSettledSpin = true;
                    setTimeout(() => {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        const csrfEl = document.querySelector('input[name="csrf_token"]');
                        if (csrfEl) {
                            form.innerHTML = `
                                <input type="hidden" name="csrf_token" value="${csrfEl.value}">
                                <input type="hidden" name="action" value="close">
                            `;
                            document.body.appendChild(form);
                            form.submit();
                        }
                    }, 2500);
                }
            }
        }
        requestAnimationFrame(step);
    }

    async function pollLiveSync() {
        try {
            const res = await fetch(window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'api=1');
            const data = await res.json();
            if (data.success) {
                // Check if spin wheel triggered
                if (data.spin_status === 'spinning' && !isSpinningActive) {
                    isSpinningActive = true;
                    animateSpin(data.eligible_members, data.spin_duration_seconds, data.spin_target_angle, data.spin_started_at_ts, data.spin_winner_name);
                }

                if (data.top_bid_paise > lastTopBidPaise) {
                    if (lastTopBidPaise > 0) {
                        showAlert('⚡ New High Bid Alert!', `${data.top_bidder} placed a new top bid of ${data.top_bid_formatted}!`);
                    }
                    lastTopBidPaise = data.top_bid_paise;
                }

                const topBidValEl = document.getElementById('liveTopBidVal');
                if (topBidValEl) topBidValEl.textContent = data.top_bid_formatted;

                const bidderNameEl = document.getElementById('liveBidderName');
                if (bidderNameEl) bidderNameEl.textContent = data.top_bidder;

                const statTopBidEl = document.getElementById('statTopBid');
                if (statTopBidEl) statTopBidEl.textContent = data.top_bid_formatted;

                const statTotalBidsEl = document.getElementById('statTotalBids');
                if (statTotalBidsEl) statTotalBidsEl.textContent = data.total_bids;

                const tbody = document.getElementById('bidsTableBody');
                if (tbody && data.bids && data.bids.length > 0) {
                    tbody.innerHTML = data.bids.map((b, idx) => `
                        <tr style="${idx === 0 ? 'background:rgba(5,150,105,.05);' : ''}">
                            <td>${idx + 1}</td>
                            <td>
                                ${b.display_name}
                                ${idx === 0 ? '<span class="badge badge-success" style="margin-left:4px;">Top</span>' : ''}
                            </td>
                            <td class="text-right text-success" style="font-weight:600;">${b.discount_formatted}</td>
                            <td class="text-muted">${b.created_at_formatted}</td>
                        </tr>
                    `).join('');
                }
            }
        } catch (err) {
            console.warn('Live sync poll error:', err);
        }
    }

    updateTimer();
    setInterval(updateTimer, 1000);
    setInterval(pollLiveSync, 2000);
})();
</script>

<style>
@keyframes pulse {
    0% { transform: scale(0.95); opacity: 0.8; }
    50% { transform: scale(1.1); opacity: 1; }
    100% { transform: scale(0.95); opacity: 0.8; }
}
</style>

</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
