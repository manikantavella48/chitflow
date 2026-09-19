<?php
require_once __DIR__ . '/auth.php';
$user = currentUser();
$currentPage = $currentPage ?? '';
?>
<nav class="admin-nav">
    <div class="nav-brand">
        <a href="<?= APP_URL ?>/admin/index.php">
            <span class="logo-icon">CF</span>
            <span class="logo-text">Admin Panel</span>
        </a>
    </div>
    <div class="nav-links">
        <a href="<?= APP_URL ?>/admin/index.php" class="<?= $currentPage === 'overview' ? 'active' : '' ?>">Overview</a>
        <a href="<?= APP_URL ?>/admin/users.php" class="<?= $currentPage === 'users' ? 'active' : '' ?>">Users</a>
        <a href="<?= APP_URL ?>/admin/groups.php" class="<?= $currentPage === 'groups' ? 'active' : '' ?>">Groups</a>
        <a href="<?= APP_URL ?>/admin/auctions.php" class="<?= $currentPage === 'auctions' ? 'active' : '' ?>">Auctions</a>
        <a href="<?= APP_URL ?>/admin/kyc.php" class="<?= $currentPage === 'kyc' ? 'active' : '' ?>">KYC</a>
        <a href="<?= APP_URL ?>/admin/withdrawals.php" class="<?= $currentPage === 'withdrawals' ? 'active' : '' ?>">Withdrawals</a>
        <a href="<?= APP_URL ?>/admin/recycle-bin.php" class="<?= $currentPage === 'recycle-bin' ? 'active' : '' ?>" style="color:#ef4444;font-weight:700;">🗑️ Recycle Bin</a>
        <a href="<?= APP_URL ?>/app/index.php" class="nav-back">← App</a>
    </div>
</nav>
<main class="app-main admin-main">
