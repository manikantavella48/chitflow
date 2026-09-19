<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$user = currentUser();

// Fetch all completed auction cycles & dividends for all chit groups the user belongs to
$stmt = db()->prepare("
    SELECT 
        a.id as auction_id,
        a.cycle_number,
        a.winning_discount_paise,
        a.created_at as auction_closed_at,
        g.id as group_id,
        g.name as group_name,
        g.join_code,
        g.chit_value_paise,
        g.monthly_contribution_paise,
        g.total_members,
        g.commission_pct,
        COALESCE(wt.amount_paise, 
            CASE 
                WHEN a.winning_discount_paise IS NOT NULL THEN 
                    FLOOR(GREATEST(0, a.winning_discount_paise - ROUND(g.chit_value_paise * (COALESCE(g.commission_pct, 4.0) / 100))) / g.total_members)
                ELSE 0 
            END
        ) as dividend_paise,
        COALESCE(wt.created_at, a.created_at) as dividend_date
    FROM group_members gm
    JOIN groups g ON g.id = gm.group_id
    JOIN auction_sessions a ON a.group_id = g.id AND a.state = 'completed'
    LEFT JOIN wallet_transactions wt ON wt.reference_id = a.id AND wt.user_id = gm.user_id AND wt.kind = 'dividend'
    WHERE gm.user_id = ?
    ORDER BY a.created_at DESC, a.cycle_number DESC
");
$stmt->execute([$user['id']]);
$dividends = $stmt->fetchAll();

// Calculate aggregate metrics
$totalDividendPaise = array_sum(array_column($dividends, 'dividend_paise'));
$totalCount = count($dividends);
$avgDividendPaise = $totalCount > 0 ? (int)round($totalDividendPaise / $totalCount) : 0;

$stmtGroups = db()->prepare('SELECT COUNT(*) FROM group_members WHERE user_id = ?');
$stmtGroups->execute([$user['id']]);
$groupsCount = (int)$stmtGroups->fetchColumn();

// Group dividends by Month (Year-Month)
$monthlyBreakdown = [];
foreach ($dividends as $d) {
    $monthKey = date('F Y', strtotime($d['dividend_date']));
    if (!isset($monthlyBreakdown[$monthKey])) {
        $monthlyBreakdown[$monthKey] = [
            'month_label' => $monthKey,
            'total_paise' => 0,
            'count' => 0,
            'items' => []
        ];
    }
    $monthlyBreakdown[$monthKey]['total_paise'] += (int)$d['dividend_paise'];
    $monthlyBreakdown[$monthKey]['count']++;
    $monthlyBreakdown[$monthKey]['items'][] = $d;
}

$pageTitle = 'Dividend Profits & Share';
$currentPage = 'profit';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/app-nav.php';
?>

<div class="page-header">
    <h1>💰 Dividend Profits & Earnings</h1>
    <p>Track your monthly auction dividend shares, earnings, and net installment savings across all chit groups.</p>
</div>

<!-- TOP STATS METRICS GRID -->
<div class="stats-grid" style="margin-bottom:24px;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));">
    <div class="stat-card" style="background:linear-gradient(135deg, rgba(16,185,129,0.1), rgba(16,185,129,0.02));border:1px solid rgba(16,185,129,0.3);">
        <div class="stat-label" style="color:#10b981;font-weight:700;">Total Dividend Earned</div>
        <div class="stat-value" style="font-size:24px;color:#10b981;font-weight:800;"><?= paiseToINR($totalDividendPaise) ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:4px;">Credited to Wallet Ledger</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Dividends Received</div>
        <div class="stat-value" style="font-size:22px;"><?= $totalCount ?> <span style="font-size:13px;color:var(--muted);">Cycles</span></div>
        <div style="font-size:11px;color:var(--muted);margin-top:4px;">Auction Sessions Settled</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Avg Dividend / Cycle</div>
        <div class="stat-value" style="font-size:22px;color:var(--primary);"><?= paiseToINR($avgDividendPaise) ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:4px;">Average Return per Cycle</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Active Groups</div>
        <div class="stat-value" style="font-size:22px;"><?= $groupsCount ?> <span style="font-size:13px;color:var(--muted);">Chits</span></div>
        <div style="font-size:11px;color:var(--muted);margin-top:4px;">Participating Groups</div>
    </div>
</div>

<!-- HOW DIVIDEND PROFIT WORKS BANNER -->
<div class="card" style="margin-bottom:24px;background:linear-gradient(135deg, rgba(99,102,241,0.06), rgba(16,185,129,0.06));border:1px solid rgba(99,102,241,0.2);padding:20px;border-radius:12px;">
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <div style="font-size:32px;">💡</div>
        <div style="flex:1;">
            <h4 style="margin:0;font-size:15px;font-weight:700;">How Monthly Dividend Profit Reduces Your Installment</h4>
            <p style="margin:4px 0 0 0;font-size:13px;color:var(--muted);line-height:1.5;">
                In every auction, when a winner bids a discount (e.g. ₹26,000), 4% leader commission (₹4,000) is deducted and the remaining ₹22,000 pool is equally distributed to all members as your <strong>Dividend Share (₹1,100.00)</strong>. Your monthly installment is reduced to: <strong>Monthly Contribution − Dividend Share = Net Installment Paid</strong>.
            </p>
        </div>
    </div>
</div>

<!-- MONTH-BY-MONTH DIVIDEND PROFIT BREAKDOWN -->
<section style="margin-bottom:32px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
        <h2 class="section-title" style="margin:0;">Monthly Dividend History</h2>
        <span class="badge badge-success" style="font-size:12px;padding:6px 12px;">
            Total Earnings: <?= paiseToINR($totalDividendPaise) ?>
        </span>
    </div>

    <?php if (empty($dividends)): ?>
    <div class="empty-state card" style="text-align:center;padding:32px;">
        <div style="font-size:40px;margin-bottom:12px;">📈</div>
        <h3 style="margin:0 0 6px 0;">No Dividend Earnings Yet</h3>
        <p style="color:var(--muted);font-size:13px;margin-bottom:16px;">
            Once auction cycles in your enrolled chit groups complete, your monthly dividend profit shares will appear here.
        </p>
        <a href="<?= APP_URL ?>/app/groups/index.php" class="btn btn-primary btn-sm">Explore Active Chit Groups →</a>
    </div>
    <?php else: ?>

    <?php foreach ($monthlyBreakdown as $monthKey => $mGroup): ?>
    <div class="card" style="margin-bottom:20px;padding:20px;border-radius:12px;border:1px solid var(--border);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:12px;">
            <div>
                <h3 style="margin:0;font-size:17px;font-weight:700;display:flex;align-items:center;gap:8px;">
                    🗓️ <?= e($mGroup['month_label']) ?>
                </h3>
                <div style="font-size:12px;color:var(--muted);margin-top:2px;">
                    <?= $mGroup['count'] ?> dividend credit<?= $mGroup['count'] > 1 ? 's' : '' ?> received
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;">Month Total Profit</div>
                <div style="font-size:20px;font-weight:800;color:#10b981;"><?= paiseToINR($mGroup['total_paise']) ?></div>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Chit Group</th>
                        <th>Cycle #</th>
                        <th class="text-right">Winning Discount</th>
                        <th class="text-right">4% Leader Comm.</th>
                        <th class="text-right">Dividend Pool</th>
                        <th class="text-right">My Dividend Share</th>
                        <th class="text-right">Net Installment Paid</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mGroup['items'] as $item): ?>
                    <?php
                        $chitPaise = (int)($item['chit_value_paise'] ?? 0);
                        $totalMembers = max(1, (int)($item['total_members'] ?? 1));
                        $monthlyContribPaise = (int)($item['monthly_contribution_paise'] ?: round($chitPaise / $totalMembers));
                        $discountPaise = (int)($item['winning_discount_paise'] ?? 0);
                        $commPct = (float)($item['commission_pct'] ?? 4.0);
                        $commAmtPaise = (int)round($chitPaise * ($commPct / 100));
                        $netPoolPaise = max(0, $discountPaise - $commAmtPaise);
                        $mySharePaise = (int)$item['dividend_paise'];
                        $netInstallmentPaise = max(0, $monthlyContribPaise - $mySharePaise);
                    ?>
                    <tr>
                        <td style="font-size:12px;white-space:nowrap;">
                            <?= formatDateShort($item['dividend_date']) ?>
                        </td>
                        <td>
                            <strong><?= e($item['group_name'] ?? 'Chit Group') ?></strong>
                            <?php if (!empty($item['join_code'])): ?>
                            <code style="font-size:11px;margin-left:4px;"><?= e($item['join_code']) ?></code>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-success">Cycle #<?= (int)($item['cycle_number'] ?? 1) ?></span>
                        </td>
                        <td class="text-right" style="font-size:13px;">
                            <?= $discountPaise > 0 ? paiseToINR($discountPaise) : '—' ?>
                        </td>
                        <td class="text-right text-muted" style="font-size:13px;">
                            <?= $commAmtPaise > 0 ? paiseToINR($commAmtPaise) : '—' ?>
                        </td>
                        <td class="text-right" style="font-size:13px;font-weight:600;">
                            <?= $netPoolPaise > 0 ? paiseToINR($netPoolPaise) : '—' ?>
                        </td>
                        <td class="text-right" style="font-size:14px;font-weight:800;color:#10b981;">
                            +<?= paiseToINR($mySharePaise) ?>
                        </td>
                        <td class="text-right" style="font-size:14px;font-weight:700;color:var(--primary);">
                            <?= paiseToINR($netInstallmentPaise) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
</section>

<!-- QUICK NAVIGATION ACTIONS -->
<div style="display:flex;gap:12px;justify-content:center;margin-top:16px;">
    <a href="<?= APP_URL ?>/app/wallet.php" class="btn btn-outline">View Full Wallet Ledger →</a>
    <a href="<?= APP_URL ?>/app/calculator.php" class="btn btn-primary">Open Chit Calculator →</a>
</div>

</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
