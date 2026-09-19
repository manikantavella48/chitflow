<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$user = currentUser();

if (isPost() && verifyCsrf()) {
    $displayName = trim($_POST['display_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    if ($displayName) {
        db()->prepare('UPDATE users SET display_name = ?, phone = ? WHERE id = ?')
            ->execute([$displayName, $phone, $user['id']]);
        flash('success', 'Profile updated.');
    }
    redirect(APP_URL . '/app/settings.php');
}

$pageTitle = 'Settings';
$currentPage = 'settings';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/app-nav.php';
?>

<div class="page-header">
    <h1>Settings</h1>
    <p>Manage your account</p>
</div>

<div class="card" style="max-width:500px;">
    <h3 class="card-title">Profile</h3>
    <form method="POST" style="margin-top:16px;">
        <?= csrfField() ?>
        <div class="form-group">
            <label>Email</label>
            <input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled>
        </div>
        <div class="form-group">
            <label for="display_name">Display Name</label>
            <input type="text" id="display_name" name="display_name" class="form-control" value="<?= e($user['display_name']) ?>" required>
        </div>
        <div class="form-group">
            <label for="phone">Phone</label>
            <input type="tel" id="phone" name="phone" class="form-control" value="<?= e($user['phone'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Roles</label>
            <div style="display:flex;gap:8px;margin-top:4px;">
                <?php foreach ($user['roles'] ?? [] as $role): ?>
                <span class="badge badge-primary"><?= e($role) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <button type="submit" class="btn gradient-btn">Save Changes</button>
    </form>
</div>

</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
