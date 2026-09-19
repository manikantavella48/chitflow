<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(APP_URL . '/app/index.php');
}

if (isPost() && verifyCsrf()) {
    $result = registerUser(
        $_POST['email'] ?? '',
        $_POST['password'] ?? '',
        $_POST['display_name'] ?? '',
        $_POST['phone'] ?? null
    );
    if ($result['success']) {
        flash('success', 'Account created successfully!');
        redirect(APP_URL . '/app/index.php');
    } else {
        flash('error', $result['message']);
    }
}

$pageTitle = 'Sign up';
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
        <h1>Create account</h1>
        <p class="subtitle">Join <?= e(APP_NAME) ?> to start saving together.</p>
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-group">
                <label for="display_name">Full Name</label>
                <input type="text" id="display_name" name="display_name" class="form-control" required value="<?= e($_POST['display_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" class="form-control" required autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="phone">Phone (optional)</label>
                <input type="tel" id="phone" name="phone" class="form-control" value="<?= e($_POST['phone'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" required minlength="8" autocomplete="new-password">
                <p class="form-hint">Minimum 8 characters</p>
            </div>
            <button type="submit" class="btn btn-block gradient-btn">Create account</button>
        </form>
        <p class="auth-footer">
            Already have an account? <a href="<?= APP_URL ?>/login.php">Sign in</a>
        </p>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
