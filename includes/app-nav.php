<?php
require_once __DIR__ . '/auth.php';
$user = currentUser();
$currentPage = $currentPage ?? '';
$unreadNotifCount = 0;
if ($user) {
    $unreadNotifCount = (int)db()->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$user['id']} AND read_at IS NULL")->fetchColumn();
}
?>
<nav class="app-nav">
    <div class="nav-brand">
        <a href="<?= APP_URL ?>/app/index.php">
            <span class="logo-icon">CF</span>
            <span class="logo-text"><?= e(APP_NAME) ?></span>
        </a>
    </div>
    <div class="nav-links">
        <a href="<?= APP_URL ?>/app/index.php" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="<?= APP_URL ?>/app/groups/index.php" class="<?= $currentPage === 'groups' ? 'active' : '' ?>">Groups</a>
        <a href="<?= APP_URL ?>/app/wallet.php" class="<?= $currentPage === 'wallet' ? 'active' : '' ?>">Wallet</a>
        <a href="<?= APP_URL ?>/app/profit.php" class="<?= $currentPage === 'profit' ? 'active' : '' ?>">💰 Profits</a>
        <a href="<?= APP_URL ?>/app/calculator.php" class="<?= $currentPage === 'calculator' ? 'active' : '' ?>">Calculator</a>
        <a href="<?= APP_URL ?>/app/notifications.php" class="<?= $currentPage === 'notifications' ? 'active' : '' ?>" style="display:inline-flex;align-items:center;gap:4px;">
            🔔 Alerts
            <?php if ($unreadNotifCount > 0): ?>
            <span class="badge badge-danger" style="border-radius:10px;padding:2px 6px;font-size:10px;"><?= $unreadNotifCount ?></span>
            <?php endif; ?>
        </a>
        <?php if (isAdmin()): ?>
        <a href="<?= APP_URL ?>/admin/index.php" class="nav-admin">Admin</a>
        <?php endif; ?>
    </div>
    <div class="nav-user">
        <span class="user-name"><?= e($user['display_name'] ?? '') ?></span>
        <a href="<?= APP_URL ?>/app/settings.php" class="btn btn-ghost btn-sm">Settings</a>
        <a href="<?= APP_URL ?>/logout.php" class="btn btn-ghost btn-sm">Logout</a>
    </div>
</nav>
<main class="app-main">
