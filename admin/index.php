<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$stats = [
    'users' => (int) db()->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL')->fetchColumn(),
    'leaders' => (int) db()->query("SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE ur.role = 'leader' AND u.deleted_at IS NULL")->fetchColumn(),
    'groups' => (int) db()->query('SELECT COUNT(*) FROM groups WHERE deleted_at IS NULL')->fetchColumn(),
    'live_auctions' => (int) db()->query("SELECT COUNT(*) FROM auction_sessions WHERE state = 'live'")->fetchColumn(),
    'pending_kyc' => (int) db()->query("SELECT COUNT(*) FROM kyc_documents WHERE status = 'pending'")->fetchColumn(),
    'pending_withdrawals' => (int) db()->query("SELECT COUNT(*) FROM withdrawal_requests WHERE status = 'pending'")->fetchColumn(),
    'frozen' => (int) db()->query('SELECT COUNT(*) FROM users WHERE is_frozen = 1 AND deleted_at IS NULL')->fetchColumn(),
];

$walletSum = db()->query('SELECT COALESCE(SUM(w.available_paise + w.locked_paise), 0) FROM wallets w JOIN users u ON u.id = w.user_id WHERE u.deleted_at IS NULL')->fetchColumn();
$pendingPayout = db()->query("SELECT COALESCE(SUM(amount_paise), 0) FROM withdrawal_requests WHERE status = 'pending'")->fetchColumn();

$pageTitle = 'Admin Overview';
$currentPage = 'overview';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin-nav.php';
?>

<div class="page-header">
    <h1>Platform Overview</h1>
    <p>Real-time snapshot of <?= e(APP_NAME) ?>. Click any card below to navigate directly to that section.</p>
</div>

<style>
.stat-card-link {
    text-decoration: none;
    color: inherit;
    display: block;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    cursor: pointer;
}
.stat-card-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
}
</style>

<div class="stats-grid">
    <a href="<?= APP_URL ?>/admin/users.php" class="stat-card stat-card-link">
        <div class="stat-label">Users</div>
        <div class="stat-value"><?= $stats['users'] ?></div>
    </a>
    <a href="<?= APP_URL ?>/admin/users.php?role=leader" class="stat-card stat-card-link">
        <div class="stat-label">Leaders</div>
        <div class="stat-value"><?= $stats['leaders'] ?></div>
    </a>
    <a href="<?= APP_URL ?>/admin/groups.php" class="stat-card stat-card-link">
        <div class="stat-label">Groups</div>
        <div class="stat-value"><?= $stats['groups'] ?></div>
    </a>
    <a href="<?= APP_URL ?>/admin/auctions.php" class="stat-card stat-card-link">
        <div class="stat-label">Live Auctions</div>
        <div class="stat-value"><?= $stats['live_auctions'] ?></div>
    </a>
    <a href="<?= APP_URL ?>/admin/withdrawals.php" class="stat-card stat-card-link">
        <div class="stat-label">Wallet Float</div>
        <div class="stat-value" style="font-size:18px;"><?= paiseToINR((int)$walletSum) ?></div>
    </a>
    <a href="<?= APP_URL ?>/admin/withdrawals.php" class="stat-card stat-card-link <?= $pendingPayout > 0 ? 'stat-warn' : '' ?>">
        <div class="stat-label">Pending Payouts</div>
        <div class="stat-value" style="font-size:18px;"><?= paiseToINR((int)$pendingPayout) ?></div>
    </a>
    <a href="<?= APP_URL ?>/admin/kyc.php" class="stat-card stat-card-link <?= $stats['pending_kyc'] > 0 ? 'stat-warn' : '' ?>">
        <div class="stat-label">Pending KYC</div>
        <div class="stat-value"><?= $stats['pending_kyc'] ?></div>
    </a>
    <a href="<?= APP_URL ?>/admin/users.php?filter=frozen" class="stat-card stat-card-link <?= $stats['frozen'] > 0 ? 'stat-warn' : '' ?>">
        <div class="stat-label">Frozen Accounts</div>
        <div class="stat-value"><?= $stats['frozen'] ?></div>
    </a>
</div>

</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
