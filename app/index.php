<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$user = currentUser();

if (isPost() && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'join_group_direct') {
        $targetGroupId = (int)($_POST['group_id'] ?? 0);
        $stmt = db()->prepare('SELECT join_code FROM groups WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$targetGroupId]);
        $code = $stmt->fetchColumn();

        if ($code) {
            $result = joinGroupByCode($user['id'], $code);
            flash($result['success'] ? 'success' : 'error', $result['message']);
        }
        redirect(APP_URL . '/app/index.php');
    }
}

// Get user's groups
if (isLeader()) {
    $stmt = db()->prepare('SELECT g.*, g.id as group_id FROM groups g WHERE g.leader_id = ? AND g.deleted_at IS NULL ORDER BY g.created_at DESC');
    $stmt->execute([$user['id']]);
} else {
    $stmt = db()->prepare('
        SELECT gm.group_id, g.* FROM group_members gm
        JOIN groups g ON g.id = gm.group_id
        WHERE gm.user_id = ? AND g.deleted_at IS NULL ORDER BY gm.joined_at DESC
    ');
    $stmt->execute([$user['id']]);
}
$myGroups = $stmt->fetchAll();

// Get live auctions for user's groups
$groupIds = array_column($myGroups, 'group_id');
if (empty($groupIds)) {
    $groupIds = array_column($myGroups, 'id');
}

$liveAuctions = [];
if (!empty($groupIds)) {
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $stmt = db()->prepare("
        SELECT a.*, g.name as group_name, g.chit_value_paise
        FROM auction_sessions a
        JOIN groups g ON g.id = a.group_id
        WHERE a.group_id IN ($placeholders) AND a.state IN ('pending','scheduled','live','paused') AND g.deleted_at IS NULL
        ORDER BY a.starts_at ASC
    ");
    $stmt->execute($groupIds);
    $liveAuctions = $stmt->fetchAll();
}

// Get Net Installment Due Notices from completed auction cycles
$dueNotices = [];
if (!empty($groupIds)) {
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $stmt = db()->prepare("
        SELECT a.*, g.name as group_name, g.chit_value_paise, g.monthly_contribution_paise, g.total_members, g.commission_pct
        FROM auction_sessions a
        JOIN groups g ON g.id = a.group_id
        WHERE a.group_id IN ($placeholders) AND a.state = 'completed' AND g.deleted_at IS NULL
        ORDER BY a.created_at DESC
    ");
    $stmt->execute($groupIds);
    $completedAuctions = $stmt->fetchAll();

    $seenGroups = [];
    foreach ($completedAuctions as $ca) {
        $gid = (int)$ca['group_id'];
        if (isset($seenGroups[$gid])) continue;
        $seenGroups[$gid] = true;

        $chitPaise = (int)$ca['chit_value_paise'];
        $discountPaise = (int)$ca['winning_discount_paise'];
        $commissionPct = (float)$ca['commission_pct'];
        $monthlyPaise = (int)$ca['monthly_contribution_paise'];
        $totalMembers = max(1, (int)$ca['total_members']);

        $commissionAmtPaise = (int)round($chitPaise * ($commissionPct / 100));
        $dividendPoolPaise = max(0, $discountPaise - $commissionAmtPaise);
        $dividendPerMemberPaise = (int)floor($dividendPoolPaise / $totalMembers);
        $netPayablePaise = max(0, $monthlyPaise - $dividendPerMemberPaise);

        $stmtCount = db()->prepare('SELECT COUNT(*) FROM auction_sessions WHERE group_id = ? AND state = "completed"');
        $stmtCount->execute([$gid]);
        $completedCount = (int)$stmtCount->fetchColumn();

        $totalDuration = max(1, (int)($ca['duration_months'] ?? $totalMembers));
        $remainingAuctions = max(0, $totalDuration - $completedCount);

        $dueNotices[] = [
            'group_name' => $ca['group_name'],
            'group_id' => $gid,
            'cycle_number' => $ca['cycle_number'],
            'total_duration' => $totalDuration,
            'completed_count' => $completedCount,
            'remaining_auctions' => $remainingAuctions,
            'monthly_contribution_paise' => $monthlyPaise,
            'dividend_share_paise' => $dividendPerMemberPaise,
            'net_payable_paise' => $netPayablePaise,
            'winning_discount_paise' => $discountPaise,
            'commission_amt_paise' => $commissionAmtPaise
        ];
    }
}

// Get Available Chit Plans/Groups to Join
$availablePlansQuery = '
    SELECT g.*, u.display_name as leader_name,
    (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as current_member_count
    FROM groups g
    LEFT JOIN users u ON u.id = g.leader_id
    WHERE g.deleted_at IS NULL AND g.status = "open" AND g.visibility_type != "private_invite_only"
';
$queryParams = [];
if (!empty($groupIds)) {
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $availablePlansQuery .= " AND g.id NOT IN ($placeholders)";
    $queryParams = $groupIds;
}
$availablePlansQuery .= ' ORDER BY g.created_at DESC LIMIT 6';

$stmt = db()->prepare($availablePlansQuery);
$stmt->execute($queryParams);
$availablePlans = $stmt->fetchAll();

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/app-nav.php';
?>

<div class="balance-card">
    <div class="balance-label">Available Balance</div>
    <div class="balance-amount"><?= paiseToINR((int)$user['available_paise']) ?></div>
    <div class="balance-locked">Locked: <?= paiseToINR((int)$user['locked_paise']) ?></div>
    <div class="balance-actions">
        <a href="<?= APP_URL ?>/app/wallet.php" class="btn">View Ledger</a>
        <a href="<?= APP_URL ?>/app/groups/index.php" class="btn">Browse Groups</a>
    </div>
</div>

<?php if (!empty($dueNotices)): ?>
<section style="margin-bottom:24px;">
    <?php foreach ($dueNotices as $notice): ?>
    <div class="card" style="margin-bottom:12px;border:2px solid #6366f1;background:linear-gradient(135deg, rgba(99,102,241,0.06), rgba(16,185,129,0.06));padding:20px;border-radius:12px;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
            <div style="display:flex;align-items:center;gap:14px;flex:1;min-width:280px;">
                <div style="font-size:32px;">📢</div>
                <div>
                    <h4 style="margin:0;font-size:15px;font-weight:700;color:var(--text-color);">
                        Monthly Installment Due Notice
                    </h4>
                    <p style="margin:4px 0 0 0;font-size:13px;color:var(--muted);line-height:1.5;">
                        For <strong><?= e($notice['group_name']) ?></strong> (Cycle #<?= $notice['cycle_number'] ?>): Winning bid discount: <strong><?= paiseToINR($notice['winning_discount_paise']) ?></strong>. After 4% leader commission (<?= paiseToINR($notice['commission_amt_paise']) ?>), the remaining pool is equally shared among members as <strong><?= paiseToINR($notice['dividend_share_paise']) ?></strong> dividend share. Your remaining balance to pay is <strong style="color:var(--primary);font-size:16px;"><?= paiseToINR($notice['net_payable_paise']) ?></strong> (<?= paiseToINR($notice['monthly_contribution_paise']) ?> − <?= paiseToINR($notice['dividend_share_paise']) ?>).
                    </p>
                    <div style="display:flex;align-items:center;gap:8px;margin-top:8px;font-size:12px;">
                        <span class="badge badge-success">✓ Completed: <?= $notice['completed_count'] ?> / <?= $notice['total_duration'] ?> Cycles</span>
                        <span class="badge badge-primary">⏳ Remaining Auctions: <?= $notice['remaining_auctions'] ?> Auctions Left</span>
                    </div>
                </div>
            </div>
            <a href="<?= APP_URL ?>/app/wallet.php" class="btn btn-sm btn-primary" style="white-space:nowrap;">Pay / Wallet Balance →</a>
        </div>
    </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<div class="card" style="margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;gap:16px;">
    <div>
        <h3 style="font-size:15px;font-weight:600;">Chit Calculator & Planner</h3>
        <p style="font-size:12px;color:var(--muted);margin-top:4px;">Plan bids, simulate auctions, and calculate returns.</p>
    </div>
    <a href="<?= APP_URL ?>/app/calculator.php" class="btn btn-outline btn-sm">Open →</a>
</div>

<?php if (!empty($liveAuctions)): ?>
<section style="margin-bottom:24px;">
    <h2 class="section-title">🔴 Live Auctions</h2>
    <?php foreach ($liveAuctions as $auction): ?>
    <a href="<?= APP_URL ?>/app/auction.php?id=<?= $auction['id'] ?>" class="auction-live">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <div>
                <span class="badge badge-live"><?= e($auction['state']) ?> · Cycle <?= $auction['cycle_number'] ?></span>
                <div style="font-family:var(--font-display);font-size:20px;margin-top:8px;"><?= e($auction['group_name']) ?></div>
                <div style="font-size:12px;color:var(--muted);margin-top:4px;">
                    Pot: <?= paiseToINR((int)$auction['chit_value_paise']) ?>
                    <?php if ($auction['state'] === 'live'): ?>
                    · Ends: <?= formatDate($auction['ends_at']) ?>
                    <?php else: ?>
                    · Starts: <?= formatDate($auction['starts_at']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <span style="font-size:24px;">→</span>
        </div>
    </a>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<!-- AVAILABLE CHIT PLANS SECTION -->
<section style="margin-bottom:32px;">
    <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <div>
            <h2 class="section-title" style="margin:0;">💎 Available Chit Plans</h2>
            <p style="font-size:12px;color:var(--muted);margin-top:2px;">Discover open chit plans and click <strong>+ Add to Group</strong> to join instantly.</p>
        </div>
        <a href="<?= APP_URL ?>/app/groups/index.php" class="btn btn-ghost btn-sm">Explore All →</a>
    </div>

    <?php if (empty($availablePlans)): ?>
    <div class="card" style="text-align:center;padding:20px;color:var(--muted);">
        <p style="margin:0;">You are currently enrolled in all available public chit plans!</p>
    </div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;">
        <?php foreach ($availablePlans as $plan): ?>
        <?php $spotsLeft = max(0, (int)$plan['total_members'] - (int)$plan['current_member_count']); ?>
        <div class="card" style="display:flex;flex-direction:column;justify-content:space-between;border:1px solid var(--border);border-radius:12px;padding:20px;background:var(--card-bg);">
            <div>
                <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:8px;">
                    <h3 style="font-size:17px;font-weight:700;margin:0;"><?= e($plan['name']) ?></h3>
                    <span class="badge badge-success"><?= $spotsLeft ?> spots left</span>
                </div>
                <div style="font-size:12px;color:var(--muted);margin-bottom:12px;">
                    Led by <?= e($plan['leader_name'] ?? 'ChitFlow Leader') ?> · Code: <code><?= e($plan['join_code']) ?></code>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:12px;background:rgba(0,0,0,0.02);border-radius:8px;margin-bottom:16px;">
                    <div>
                        <div style="font-size:11px;color:var(--muted);text-transform:uppercase;font-weight:600;">Chit Value</div>
                        <div style="font-size:15px;font-weight:700;color:var(--primary);"><?= paiseToINR((int)$plan['chit_value_paise']) ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--muted);text-transform:uppercase;font-weight:600;">Monthly</div>
                        <div style="font-size:15px;font-weight:700;"><?= paiseToINR((int)$plan['monthly_contribution_paise']) ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--muted);text-transform:uppercase;font-weight:600;">Duration</div>
                        <div style="font-size:13px;font-weight:600;"><?= $plan['duration_months'] ?> Months</div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--muted);text-transform:uppercase;font-weight:600;">Members</div>
                        <div style="font-size:13px;font-weight:600;"><?= $plan['current_member_count'] ?>/<?= $plan['total_members'] ?></div>
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:8px;align-items:center;">
                <form method="POST" style="flex:1;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="join_group_direct">
                    <input type="hidden" name="group_id" value="<?= $plan['id'] ?>">
                    <button type="submit" class="btn btn-primary" style="width:100%;">+ Add to Group</button>
                </form>
                <a href="<?= APP_URL ?>/app/groups/view.php?id=<?= $plan['id'] ?>" class="btn btn-outline" style="text-decoration:none;">Details</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<!-- MY GROUPS SECTION -->
<section>
    <div class="section-header">
        <h2 class="section-title">Your Groups</h2>
        <a href="<?= APP_URL ?>/app/groups/index.php" class="btn btn-ghost btn-sm">All →</a>
    </div>
    <?php if (empty($myGroups)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">👥</div>
        <p>You haven't joined any group yet.</p>
        <a href="<?= APP_URL ?>/app/groups/index.php" class="btn" style="margin-top:16px;">Find a Group</a>
    </div>
    <?php else: ?>
    <?php foreach ($myGroups as $g): ?>
    <a href="<?= APP_URL ?>/app/groups/view.php?id=<?= $g['group_id'] ?? $g['id'] ?>" class="list-item">
        <div>
            <div class="list-item-title"><?= e($g['name']) ?></div>
            <div class="list-item-meta">Chit value <?= paiseToINR((int)$g['chit_value_paise']) ?></div>
        </div>
        <span class="badge badge-<?= $g['status'] === 'open' ? 'success' : 'primary' ?>"><?= e($g['status']) ?></span>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
</section>

</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
