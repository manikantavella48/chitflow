<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$user = currentUser();

// Sample template download handler
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="users_sample_template.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Name', 'Email', 'Phone', 'Password']);
    fputcsv($output, ['Ramesh Kumar', 'ramesh@example.com', '9876543210', 'password123']);
    fputcsv($output, ['Suresh Patel', 'suresh@example.com', '9876543211', 'password123']);
    fputcsv($output, ['Anita Sharma', 'anita@example.com', '9876543212', 'password123']);
    fclose($output);
    exit;
}

if (isPost() && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'freeze') {
        db()->prepare('UPDATE users SET is_frozen = 1 WHERE id = ?')->execute([$targetId]);
        logAdminAction($user['id'], 'freeze_user', 'user', $targetId);
        flash('success', 'User frozen.');
    } elseif ($action === 'unfreeze') {
        db()->prepare('UPDATE users SET is_frozen = 0 WHERE id = ?')->execute([$targetId]);
        logAdminAction($user['id'], 'unfreeze_user', 'user', $targetId);
        flash('success', 'User unfrozen.');
    } elseif ($action === 'make_leader') {
        db()->prepare("INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, 'leader')")->execute([$targetId]);
        logAdminAction($user['id'], 'promote_leader', 'user', $targetId);
        flash('success', 'User promoted to leader.');
    } elseif ($action === 'delete') {
        if ($targetId === 1 || $targetId === $user['id']) {
            flash('error', 'Cannot delete super admin or yourself.');
        } else {
            db()->prepare('UPDATE users SET deleted_at = NOW() WHERE id = ?')->execute([$targetId]);
            logAdminAction($user['id'], 'delete_user_recycle_bin', 'user', $targetId);
            flash('success', 'User moved to Recycle Bin. You can restore them anytime.');
        }
    } elseif ($action === 'update_user_details') {
        $displayName = trim($_POST['display_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';

        if (empty($displayName) || empty($email)) {
            flash('error', 'Display Name and Email are required.');
        } else {
            $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id != ? AND deleted_at IS NULL');
            $stmt->execute([$email, $targetId]);
            if ($stmt->fetchColumn() > 0) {
                flash('error', 'Another user is already using this email address.');
            } else {
                db()->prepare('UPDATE users SET display_name = ?, email = ?, phone = ? WHERE id = ?')->execute([$displayName, $email, $phone, $targetId]);
                $msg = 'User details updated successfully.';

                if (!empty($newPassword)) {
                    if (strlen($newPassword) < 6) {
                        flash('error', 'Password must be at least 6 characters. Profile updated without password reset.');
                    } else {
                        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $targetId]);
                        $msg .= ' Password reset successfully.';
                    }
                }
                logAdminAction($user['id'], 'edit_user_details', 'user', $targetId);
                flash('success', $msg);
            }
        }
    } elseif ($action === 'import_users') {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Please upload a valid Excel/CSV file.');
        } else {
            $filePath = $_FILES['import_file']['tmp_name'];
            $makeLeader = !empty($_POST['make_leader']);

            $handle = fopen($filePath, 'r');
            if ($handle === false) {
                flash('error', 'Could not open the uploaded file.');
            } else {
                $importedCount = 0;
                $skippedCount = 0;
                $rowNum = 0;

                while (($row = fgetcsv($handle, 1000, ",")) !== false) {
                    $rowNum++;
                    if ($rowNum === 1) {
                        $firstCol = strtolower(trim($row[0] ?? ''));
                        $secondCol = strtolower(trim($row[1] ?? ''));
                        if (str_contains($firstCol, 'name') || str_contains($secondCol, 'email')) {
                            continue;
                        }
                    }

                    $name = trim($row[0] ?? '');
                    $email = trim($row[1] ?? '');
                    $phone = trim($row[2] ?? '');
                    $pass = trim($row[3] ?? 'password123');
                    if (empty($pass)) $pass = 'password123';

                    if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $skippedCount++;
                        continue;
                    }

                    $stmt = db()->prepare('SELECT id, deleted_at FROM users WHERE email = ?');
                    $stmt->execute([$email]);
                    $existingUser = $stmt->fetch();

                    if ($existingUser) {
                        if ($existingUser['deleted_at'] !== null) {
                            db()->prepare('UPDATE users SET deleted_at = NULL, display_name = ?, phone = ? WHERE id = ?')
                                ->execute([$name, $phone, $existingUser['id']]);
                            $importedCount++;
                        } else {
                            $skippedCount++;
                        }
                    } else {
                        $reg = registerUser($email, $pass, $name, $phone);
                        if ($reg['success']) {
                            $importedCount++;
                            if ($makeLeader) {
                                db()->prepare("INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, 'leader')")
                                    ->execute([$reg['user_id']]);
                            }
                        } else {
                            $skippedCount++;
                        }
                    }
                }
                fclose($handle);

                logAdminAction($user['id'], 'import_users', 'user', 0);
                flash('success', "Import complete! $importedCount users imported successfully ($skippedCount skipped or invalid).");
            }
        }
    }
    redirect(APP_URL . '/admin/users.php');
}

$editId = (int)($_GET['edit_id'] ?? 0);
$editUser = null;
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch() ?: null;
}

$search = trim($_GET['q'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$filter = trim($_GET['filter'] ?? '');

$sql = 'SELECT u.*, GROUP_CONCAT(ur.role) as roles FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id WHERE u.deleted_at IS NULL';
$params = [];

if ($search) {
    $sql .= ' AND (u.email LIKE ? OR u.display_name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($roleFilter === 'leader') {
    $sql .= ' AND u.id IN (SELECT user_id FROM user_roles WHERE role = "leader")';
}
if ($filter === 'frozen') {
    $sql .= ' AND u.is_frozen = 1';
}

$sql .= ' GROUP BY u.id ORDER BY u.created_at DESC LIMIT 100';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$headerTitle = 'Users';
if ($roleFilter === 'leader') {
    $headerTitle = 'Group Leaders 👥';
} elseif ($filter === 'frozen') {
    $headerTitle = 'Frozen Accounts ❄️';
}

$pageTitle = $headerTitle;
$currentPage = 'users';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin-nav.php';
?>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;">
    <div>
        <h1><?= e($headerTitle) ?></h1>
        <p><?= $roleFilter === 'leader' ? 'Showing registered leaders only' : ($filter === 'frozen' ? 'Showing frozen accounts only' : 'Manage platform users, edit details, reset passwords, and bulk import Excel/CSV files') ?></p>
    </div>
    <div style="display:flex;gap:8px;">
        <?php if ($roleFilter || $filter): ?>
        <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-sm btn-outline">Clear Filter</a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/admin/users.php?action=import" class="btn btn-sm btn-primary">📥 Import Users (Excel/CSV)</a>
        <a href="<?= APP_URL ?>/admin/recycle-bin.php" class="btn btn-sm btn-outline">🗑️ Open Recycle Bin</a>
    </div>
</div>

<?php if (isset($_GET['action']) && $_GET['action'] === 'import'): ?>
<div class="card" style="margin-bottom:24px;border:2px solid var(--primary);background:var(--card-bg);padding:24px;border-radius:12px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h3 style="margin:0;font-size:18px;font-weight:700;color:var(--primary);">📥 Import Users from Excel / CSV</h3>
        <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-sm btn-outline" style="text-decoration:none;">✕ Cancel</a>
    </div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:16px;">
        Upload a <strong>CSV</strong> or <strong>Excel (.csv/.xlsx)</strong> file containing user data. Columns expected: <code>Name</code>, <code>Email</code>, <code>Phone</code>, <code>Password (Optional)</code>.
    </p>
    <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="import_users">

        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;margin-bottom:20px;">
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Select Excel / CSV File</label>
                <input type="file" name="import_file" class="form-control" accept=".csv, .txt, .xlsx, .xls" required style="padding:8px;">
            </div>
            <div style="display:flex;align-items:center;gap:8px;padding-top:20px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
                    <input type="checkbox" name="make_leader" value="1">
                    Promote imported users to Leader role
                </label>
            </div>
        </div>

        <div style="display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap;">
            <a href="<?= APP_URL ?>/admin/users.php?download_template=1" class="btn btn-sm btn-outline" style="display:inline-flex;align-items:center;gap:6px;">
                📄 Download Sample CSV Template
            </a>
            <div style="display:flex;gap:8px;">
                <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-outline">Cancel</a>
                <button type="submit" class="btn btn-primary">📤 Upload & Import Users</button>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($editUser): ?>
<div class="card" style="margin-bottom:24px;border:2px solid var(--primary);background:var(--card-bg);padding:24px;border-radius:12px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h3 style="margin:0;font-size:18px;font-weight:700;color:var(--primary);">✏️ Edit User Details & Reset Password (ID: <?= $editUser['id'] ?>)</h3>
        <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-sm btn-outline" style="text-decoration:none;">✕ Cancel</a>
    </div>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_user_details">
        <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">

        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin-bottom:20px;">
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Display Name</label>
                <input type="text" name="display_name" class="form-control" value="<?= e($editUser['display_name']) ?>" required>
            </div>
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Email Address</label>
                <input type="email" name="email" class="form-control" value="<?= e($editUser['email']) ?>" required>
            </div>
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Phone Number</label>
                <input type="text" name="phone" class="form-control" value="<?= e($editUser['phone']) ?>" placeholder="e.g. 9876543210">
            </div>
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Reset Password <span style="font-weight:normal;color:var(--muted);">(Optional)</span></label>
                <input type="password" name="new_password" class="form-control" placeholder="Enter new password to reset">
            </div>
        </div>

        <div style="display:flex;gap:12px;justify-content:flex-end;">
            <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary">💾 Save Changes</button>
        </div>
    </form>
</div>
<?php endif; ?>

<form method="GET" style="margin-bottom:20px;display:flex;gap:8px;">
    <?php if ($roleFilter): ?><input type="hidden" name="role" value="<?= e($roleFilter) ?>"><?php endif; ?>
    <?php if ($filter): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
    <input type="text" name="q" class="form-control" placeholder="Search by email or name..." value="<?= e($search) ?>" style="max-width:300px;">
    <button type="submit" class="btn">Search</button>
</form>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Email / Phone</th>
                <th>Roles</th>
                <th>Status</th>
                <th>Joined</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
            <tr>
                <td colspan="6" class="text-muted" style="text-align:center;padding:24px;">No matching users found.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><strong><?= e($u['display_name']) ?></strong></td>
                <td><?= e($u['email']) ?> <?= $u['phone'] ? '· ' . e($u['phone']) : '' ?></td>
                <td>
                    <?php foreach (explode(',', $u['roles'] ?? '') as $role): ?>
                    <?php if ($role): ?><span class="badge badge-primary"><?= e($role) ?></span><?php endif; ?>
                    <?php endforeach; ?>
                </td>
                <td>
                    <?php if ($u['is_frozen']): ?>
                    <span class="badge badge-danger">Frozen</span>
                    <?php else: ?>
                    <span class="badge badge-success">Active</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted"><?= formatDateShort($u['created_at']) ?></td>
                <td>
                    <form method="POST" style="display:inline-flex;gap:4px;align-items:center;">
                        <?= csrfField() ?>
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">

                        <a href="<?= APP_URL ?>/admin/users.php?edit_id=<?= $u['id'] ?>" class="btn btn-sm btn-outline" style="text-decoration:none;">✏️ Edit</a>

                        <?php if ($u['is_frozen']): ?>
                        <button type="submit" name="action" value="unfreeze" class="btn btn-sm btn-outline">Unfreeze</button>
                        <?php else: ?>
                        <button type="submit" name="action" value="freeze" class="btn btn-sm btn-danger">Freeze</button>
                        <?php endif; ?>

                        <?php if (!str_contains($u['roles'] ?? '', 'leader')): ?>
                        <button type="submit" name="action" value="make_leader" class="btn btn-sm btn-outline">Make Leader</button>
                        <?php endif; ?>

                        <?php if ($u['id'] != 1 && $u['id'] != $user['id']): ?>
                        <button type="submit" name="action" value="delete" onclick="return confirm('Move this user to Recycle Bin?')" class="btn btn-sm btn-outline" style="color:#ef4444;border-color:#ef4444;">🗑️ Delete</button>
                        <?php endif; ?>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
