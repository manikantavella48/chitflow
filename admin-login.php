<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(isAdmin() ? APP_URL . '/admin/index.php' : APP_URL . '/app/index.php');
}

if (isPost() && verifyCsrf()) {
    $loginInput = $_POST['login_input'] ?? $_POST['email'] ?? '';
    $result = loginUser($loginInput, $_POST['password'] ?? '');
    if ($result['success']) {
        if (!isAdmin() && !isLeader()) {
            logoutUser();
            flash('error', 'Admin or Leader access required.');
            redirect(APP_URL . '/admin-login.php');
        }
        flash('success', 'Welcome to the management portal.');
        redirect(isAdmin() ? APP_URL . '/admin/index.php' : APP_URL . '/app/index.php');
    } else {
        flash('error', $result['message']);
    }
}

$pageTitle = 'Admin Login';
$bodyClass = 'auth-page';
require_once __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
    <div style="text-align:center;margin-bottom:24px;">
        <a href="<?= APP_URL ?>/" class="logo" style="justify-content:center;">
            <span class="logo-icon">CF</span>
            <span>Management Portal</span>
        </a>
    </div>
    <div class="card">
        <h1>Admin / Leader Login</h1>
        <p class="subtitle">Access the management dashboard.</p>
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-group">
                <label for="login_input">Email or Phone Number</label>
                <input type="text" id="login_input" name="login_input" class="form-control" required placeholder="Enter email or phone number" value="<?= e($_POST['login_input'] ?? $_POST['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-block gradient-btn">Sign in</button>
        </form>
        <p class="auth-footer">
            <a href="<?= APP_URL ?>/login.php">← Back to member login</a>
        </p>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
