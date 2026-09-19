<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$user = currentUser();
$auctionId = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare('
    SELECT a.*, g.name as group_name, g.chit_value_paise, g.commission_pct, g.leader_id, g.id as group_id, u.display_name as leader_name
    FROM auction_sessions a
    JOIN groups g ON g.id = a.group_id
    JOIN users u ON u.id = g.leader_id
    WHERE a.id = ?
');
$stmt->execute([$auctionId]);
$auction = $stmt->fetch();

if (!$auction) {
    if (isset($_GET['api'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Auction not found']);
        exit;
    }
    flash('error', 'Auction not found.');
    redirect(APP_URL . '/admin/auctions.php');
}

// Handle force close / cancel
if (isPost() && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'force_close') {
        $result = closeAuction($auctionId, $auction['leader_id']);
        flash($result['success'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'cancel') {
        db()->prepare("UPDATE auction_sessions SET state = 'cancelled' WHERE id = ?")->execute([$auctionId]);
        logAdminAction($user['id'], 'cancel_auction', 'auction', $auctionId);
        flash('success', 'Auction cancelled.');
    }
    redirect(APP_URL . '/admin/auction-view.php?id=' . $auctionId);
}

// Helper: Fetch bids and members
function getAuctionAdminData(int $auctionId, int $groupId) {
    $pdo = db();
    // Bids
    $stmt = $pdo->prepare('
        SELECT b.*, u.display_name, u.email FROM bids b
        JOIN users u ON u.id = b.user_id
        WHERE b.auction_id = ?
        ORDER BY b.discount_paise DESC, b.created_at ASC
    ');
    $stmt->execute([$auctionId]);
    $bids = $stmt->fetchAll();

    // Group Members & Participation
    $stmt = $pdo->prepare('
        SELECT gm.user_id, u.display_name, u.email,
        (SELECT MAX(discount_paise) FROM bids WHERE auction_id = ? AND user_id = u.id) as max_member_bid,
        (SELECT COUNT(*) FROM bids WHERE auction_id = ? AND user_id = u.id) as member_bid_count
        FROM group_members gm
        JOIN users u ON u.id = gm.user_id
        WHERE gm.group_id = ?
        ORDER BY u.display_name ASC
    ');
    $stmt->execute([$auctionId, $auctionId, $groupId]);
    $members = $stmt->fetchAll();

    return ['bids' => $bids, 'members' => $members];
}

// API endpoint for live polling
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $data = getAuctionAdminData($auctionId, $auction['group_id']);
    $top = $data['bids'][0] ?? null;
    $secondsRemaining = max(0, strtotime($auction['ends_at']) - time());

    echo json_encode([
        'success' => true,
        'state' => $auction['state'],
        'ends_at' => $auction['ends_at'],
        'seconds_remaining' => $secondsRemaining,
        'top_bid_formatted' => $top ? paiseToINR((int)$top['discount_paise']) : '—',
        'top_bidder' => $top ? $top['display_name'] : 'None',
        'total_bids' => count($data['bids']),
        'total_members' => count($data['members']),
        'participated_count' => count(array_filter($data['members'], fn($m) => (int)$m['member_bid_count'] > 0)),
        'bids' => array_map(function($b) {
            return [
                'id' => (int)$b['id'],
                'user_id' => (int)$b['user_id'],
                'display_name' => $b['display_name'],
                'email' => $b['email'],
                'discount_formatted' => paiseToINR((int)$b['discount_paise']),
                'created_at_formatted' => formatDate($b['created_at'])
            ];
        }, $data['bids']),
        'members' => array_map(function($m) {
            return [
                'user_id' => (int)$m['user_id'],
                'display_name' => $m['display_name'],
                'email' => $m['email'],
                'has_bid' => (int)$m['member_bid_count'] > 0,
                'max_bid_formatted' => $m['max_member_bid'] ? paiseToINR((int)$m['max_member_bid']) : '—',
                'bid_count' => (int)$m['member_bid_count']
            ];
        }, $data['members'])
    ]);
    exit;
}

$initialData = getAuctionAdminData($auctionId, $auction['group_id']);
$bids = $initialData['bids'];
$members = $initialData['members'];
$topBid = $bids[0] ?? null;
$isLive = $auction['state'] === 'live' && strtotime($auction['ends_at']) > time();
$endsTimestamp = strtotime($auction['ends_at']);
$participatedCount = count(array_filter($members, fn($m) => (int)$m['member_bid_count'] > 0));

$pageTitle = 'Admin Live Auction Monitor - ' . $auction['group_name'];
$currentPage = 'auctions';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin-nav.php';
?>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
    <div>
        <h1>🔍 Live Auction Monitor: <?= e($auction['group_name']) ?></h1>
        <p>Cycle #<?= $auction['cycle_number'] ?> · Led by <?= e($auction['leader_name']) ?> · Total Chit Pot: <strong><?= paiseToINR((int)$auction['chit_value_paise']) ?></strong></p>
    </div>
    <div>
        <a href="<?= APP_URL ?>/admin/auctions.php" class="btn btn-outline">← Back to Auctions</a>
    </div>
</div>

<?php if ($isLive): ?>
<div class="card" style="margin-bottom:24px;background:linear-gradient(135deg, rgba(16,185,129,0.08), rgba(59,130,246,0.08));border:1px solid rgba(16,185,129,0.3);border-radius:16px;padding:24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:20px;">
        <div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#10b981;box-shadow:0 0 10px #10b981;animation:pulse 1.5s infinite;"></span>
                <span style="font-weight:700;text-transform:uppercase;letter-spacing:1.5px;font-size:12px;color:#10b981;">ADMIN LIVE MONITORING ACTIVE</span>
            </div>
            <h2 id="liveTimerClock" style="font-size:42px;font-weight:900;margin:0;font-family:monospace;letter-spacing:3px;color:var(--foreground);">00:00:00</h2>
            <div style="font-size:12px;color:var(--muted);margin-top:4px;">Ends at: <?= formatDate($auction['ends_at']) ?></div>
        </div>
        <div style="text-align:right;background:rgba(0,0,0,0.2);padding:16px 20px;border-radius:12px;border:1px solid var(--border);">
            <div class="stat-label" style="font-size:12px;text-transform:uppercase;letter-spacing:1px;">Current Top Bidder</div>
            <div id="liveBidderName" style="font-size:20px;font-weight:700;color:var(--primary);margin-top:2px;">
                <?= $topBid ? e($topBid['display_name']) : 'No bids yet' ?>
            </div>
            <div id="liveTopBidVal" style="font-size:26px;font-weight:800;color:var(--success);margin-top:2px;">
                <?= $topBid ? paiseToINR((int)$topBid['discount_paise']) : '—' ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="stats-grid" style="margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-label">Status</div>
        <div class="stat-value">
            <span class="badge badge-<?= $isLive ? 'live' : ($auction['state'] === 'completed' ? 'success' : 'primary') ?>">
                <?= e($auction['state']) ?>
            </span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Participation Rate</div>
        <div id="statParticipation" class="stat-value"><?= $participatedCount ?>/<?= count($members) ?> Members</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Bids Placed</div>
        <div id="statTotalBids" class="stat-value"><?= count($bids) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Highest Discount</div>
        <div id="statTopBid" class="stat-value" style="font-size:20px;"><?= $topBid ? paiseToINR((int)$topBid['discount_paise']) : '—' ?></div>
    </div>
</div>

<?php if (in_array($auction['state'], ['live', 'pending', 'paused'])): ?>
<div class="card" style="margin-bottom:24px;border-color:rgba(220,38,38,.3);">
    <h3 class="card-title">Admin Controls</h3>
    <form method="POST" style="margin-top:12px;display:flex;gap:12px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="force_close">
        <button type="submit" onclick="return confirm('Force close auction and settle payouts?')" class="btn btn-success">Force Close & Settle</button>
        <button type="submit" name="action" value="cancel" onclick="return confirm('Cancel this auction?')" class="btn btn-danger">Cancel Auction</button>
    </form>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">
    <!-- Group Members Participation Status -->
    <section>
        <h2 class="section-title">Group Participants (<span id="memberCountLabel"><?= count($members) ?></span>)</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Status</th>
                        <th class="text-right">Top Bid</th>
                    </tr>
                </thead>
                <tbody id="membersTableBody">
                    <?php foreach ($members as $m): ?>
                    <tr>
                        <td>
                            <strong><?= e($m['display_name']) ?></strong>
                            <div class="text-muted" style="font-size:12px;"><?= e($m['email']) ?></div>
                        </td>
                        <td>
                            <?php if ($m['member_bid_count'] > 0): ?>
                            <span class="badge badge-success">✓ Bid Placed (<?= $m['member_bid_count'] ?>)</span>
                            <?php else: ?>
                            <span class="badge badge-primary">Not Bidden</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right font-mono" style="font-weight:600;">
                            <?= $m['max_member_bid'] ? paiseToINR((int)$m['max_member_bid']) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Live Bid Stream Log -->
    <section>
        <h2 class="section-title">Live Bid Stream (<span id="bidsCountLabel"><?= count($bids) ?></span>)</h2>
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
                    <?php if (empty($bids)): ?>
                    <tr><td colspan="4" class="text-muted text-center" style="padding:24px;">No bids submitted yet</td></tr>
                    <?php else: ?>
                    <?php foreach ($bids as $i => $bid): ?>
                    <tr style="<?= $i === 0 ? 'background:rgba(5,150,105,.05);' : '' ?>">
                        <td><?= $i + 1 ?></td>
                        <td>
                            <strong><?= e($bid['display_name']) ?></strong>
                            <?php if ($i === 0): ?><span class="badge badge-success" style="margin-left:4px;">Leading</span><?php endif; ?>
                            <div class="text-muted" style="font-size:11px;"><?= e($bid['email']) ?></div>
                        </td>
                        <td class="text-right text-success" style="font-weight:700;"><?= paiseToINR((int)$bid['discount_paise']) ?></td>
                        <td class="text-muted" style="font-size:12px;"><?= formatDate($bid['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<!-- LIVE AUTO-POLLING SCRIPT FOR ADMIN MONITORING -->
<script>
(function() {
    const endsTimestamp = <?= (int)$endsTimestamp ?>;
    const isLive = <?= $isLive ? 'true' : 'false' ?>;

    function formatTime(seconds) {
        if (seconds <= 0) return '00:00:00';
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        return [h, m, s].map(v => String(v).padStart(2, '0')).join(':');
    }

    function updateTimer() {
        const now = Math.floor(Date.now() / 1000);
        const remaining = endsTimestamp - now;
        const clockEl = document.getElementById('liveTimerClock');
        if (clockEl) {
            clockEl.textContent = formatTime(remaining);
            if (remaining <= 0 && isLive) {
                clockEl.textContent = '00:00:00 (EXPIRED)';
                clockEl.style.color = '#ef4444';
            }
        }
    }

    async function pollAdminLiveSync() {
        if (!isLive) return;
        try {
            const res = await fetch(window.location.href + '&api=1');
            const data = await res.json();
            if (data.success) {
                // Update stats
                document.getElementById('liveTopBidVal').textContent = data.top_bid_formatted;
                document.getElementById('liveBidderName').textContent = data.top_bidder;
                document.getElementById('statTopBid').textContent = data.top_bid_formatted;
                document.getElementById('statTotalBids').textContent = data.total_bids;
                document.getElementById('statParticipation').textContent = `${data.participated_count}/${data.total_members} Members`;
                document.getElementById('bidsCountLabel').textContent = data.total_bids;

                // Render Bids Table
                const bidsTbody = document.getElementById('bidsTableBody');
                if (bidsTbody) {
                    if (data.bids.length === 0) {
                        bidsTbody.innerHTML = '<tr><td colspan="4" class="text-muted text-center" style="padding:24px;">No bids submitted yet</td></tr>';
                    } else {
                        bidsTbody.innerHTML = data.bids.map((b, idx) => `
                            <tr style="${idx === 0 ? 'background:rgba(5,150,105,.05);' : ''}">
                                <td>${idx + 1}</td>
                                <td>
                                    <strong>${b.display_name}</strong>
                                    ${idx === 0 ? '<span class="badge badge-success" style="margin-left:4px;">Leading</span>' : ''}
                                    <div class="text-muted" style="font-size:11px;">${b.email}</div>
                                </td>
                                <td class="text-right text-success" style="font-weight:700;">${b.discount_formatted}</td>
                                <td class="text-muted" style="font-size:12px;">${b.created_at_formatted}</td>
                            </tr>
                        `).join('');
                    }
                }

                // Render Members Table
                const membersTbody = document.getElementById('membersTableBody');
                if (membersTbody && data.members) {
                    membersTbody.innerHTML = data.members.map(m => `
                        <tr>
                            <td>
                                <strong>${m.display_name}</strong>
                                <div class="text-muted" style="font-size:12px;">${m.email}</div>
                            </td>
                            <td>
                                ${m.has_bid 
                                    ? `<span class="badge badge-success">✓ Bid Placed (${m.bid_count})</span>` 
                                    : `<span class="badge badge-primary">Not Bidden</span>`}
                            </td>
                            <td class="text-right font-mono" style="font-weight:600;">
                                ${m.max_bid_formatted}
                            </td>
                        </tr>
                    `).join('');
                }
            }
        } catch (err) {
            console.warn('Admin live sync error:', err);
        }
    }

    if (isLive) {
        updateTimer();
        setInterval(updateTimer, 1000);
        setInterval(pollAdminLiveSync, 2000);
    }
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
