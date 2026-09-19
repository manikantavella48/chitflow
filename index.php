<?php
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Home';
$bodyClass = 'landing';
require_once __DIR__ . '/includes/header.php';
?>
<div class="landing">
    <header class="landing-header">
        <a href="<?= APP_URL ?>/" class="logo">
            <span class="logo-icon">CF</span>
            <span><?= e(APP_NAME) ?></span>
        </a>
        <div style="display:flex;gap:8px;">
            <a href="<?= APP_URL ?>/login.php" class="btn btn-ghost btn-sm">Log in</a>
            <a href="<?= APP_URL ?>/signup.php" class="btn btn-sm">Get started</a>
        </div>
    </header>

    <section class="hero">
        <span class="hero-badge"><span class="dot"></span> Live · Digital chit funds</span>
        <h1>Chit funds, reimagined for the digital age.</h1>
        <p>Create groups, run live discount auctions, and settle dividends instantly — backed by transparent accounting and secure wallets.</p>
        <div class="hero-actions">
            <a href="<?= APP_URL ?>/signup.php" class="btn btn-lg gradient-btn">Open your account</a>
            <a href="<?= APP_URL ?>/calculator.php" class="btn btn-lg btn-outline">Try Calculator</a>
        </div>

        <div class="features">
            <div class="feature-card">
                <div class="feature-icon">📊</div>
                <h3>Live Auctions</h3>
                <p>Highest discount wins. Bids are recorded in real time for every member.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🔒</div>
                <h3>Immutable Ledger</h3>
                <p>Every transaction is logged and audit-safe by design.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">💰</div>
                <h3>Instant Dividends</h3>
                <p>Commission and dividend split automatically when an auction closes.</p>
            </div>
        </div>
    </section>

    <footer class="landing-footer">
        <div class="inner">
            <div>
                <a href="<?= APP_URL ?>/" class="logo" style="margin-bottom:12px;">
                    <span class="logo-icon">CF</span>
                    <span><?= e(APP_NAME) ?></span>
                </a>
                <p>Empowering communities with transparent digital chit funds.</p>
            </div>
            <div>
                <p style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);margin-bottom:8px;">Internal Access</p>
                <a href="<?= APP_URL ?>/admin-login.php">Leader & Admin Portal</a>
            </div>
        </div>
        <p style="text-align:center;margin-top:32px;font-size:11px;color:var(--muted);">
            &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> · For demonstration only.
        </p>
    </footer>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
