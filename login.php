<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(isAdmin() ? APP_URL . '/admin/index.php' : APP_URL . '/app/index.php');
}

if (isPost() && verifyCsrf()) {
    $loginInput = $_POST['login_input'] ?? $_POST['email'] ?? '';
    $result = loginUser($loginInput, $_POST['password'] ?? '');
    if ($result['success']) {
        flash('success', 'Welcome back!');
        redirect(isAdmin() ? APP_URL . '/admin/index.php' : APP_URL . '/app/index.php');
    } else {
        flash('error', $result['message']);
    }
}

$pageTitle = 'Log in';
$bodyClass = 'auth-page';
require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <div style="text-align:center;margin-bottom:24px;">
        <a href="<?= APP_URL ?>/" class="logo" style="justify-content:center;">
            <span class="logo-icon">CF</span>
            <span><?= e(APP_NAME) ?></span>
        </a>
    </div>
    <div class="card">
        <h1>Welcome back</h1>
        <p class="subtitle">Sign in to your <?= e(APP_NAME) ?> account.</p>
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-group">
                <label for="login_input">Email or Phone Number</label>
                <input type="text" id="login_input" name="login_input" class="form-control" required placeholder="Enter email or phone number" autocomplete="username" value="<?= e($_POST['login_input'] ?? $_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-block gradient-btn">Sign in</button>
        </form>
        <p class="auth-footer">
            New here? <a href="<?= APP_URL ?>/signup.php">Create an account</a>
        </p>
        <p class="auth-footer" style="margin-top:12px;border-top:1px solid var(--border);padding-top:12px;">
            <a href="<?= APP_URL ?>/admin-login.php" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);">Management Portal</a>
        </p>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
