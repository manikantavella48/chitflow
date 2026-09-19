<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$user = currentUser();

if (isPost() && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['target_id'] ?? 0);

    if ($action === 'restore_user') {
        db()->prepare('UPDATE users SET deleted_at = NULL WHERE id = ?')->execute([$targetId]);
        logAdminAction($user['id'], 'restore_user', 'user', $targetId);
        flash('success', 'User restored successfully.');
    } elseif ($action === 'purge_user') {
        if ($targetId === 1 || $targetId === $user['id']) {
            flash('error', 'Cannot purge super admin or yourself.');
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                // Delete groups led by this user first
                $pdo->prepare('DELETE FROM groups WHERE leader_id = ?')->execute([$targetId]);
                // Delete group membership records
                $pdo->prepare('DELETE FROM group_members WHERE user_id = ?')->execute([$targetId]);
                // Delete user roles
                $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$targetId]);
                // Delete wallet transactions & wallet
                $pdo->prepare('DELETE FROM wallet_transactions WHERE user_id = ?')->execute([$targetId]);
                $pdo->prepare('DELETE FROM wallets WHERE user_id = ?')->execute([$targetId]);
                // Delete bids
                $pdo->prepare('DELETE FROM bids WHERE user_id = ?')->execute([$targetId]);
                // Delete KYC & withdrawal requests & notifications
                $pdo->prepare('DELETE FROM kyc_documents WHERE user_id = ?')->execute([$targetId]);
                $pdo->prepare('DELETE FROM withdrawal_requests WHERE user_id = ?')->execute([$targetId]);
                $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$targetId]);

                // Finally purge user record
                $pdo->prepare('DELETE FROM users WHERE id = ? AND deleted_at IS NOT NULL')->execute([$targetId]);

                $pdo->commit();
                logAdminAction($user['id'], 'purge_user_permanent', 'user', $targetId);
                flash('success', 'User and all associated data permanently purged.');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error', 'Failed to purge user: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'restore_group') {
        db()->prepare('UPDATE groups SET deleted_at = NULL WHERE id = ?')->execute([$targetId]);
        logAdminAction($user['id'], 'restore_group', 'group', $targetId);
        flash('success', 'Group restored successfully.');
    } elseif ($action === 'purge_group') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Delete bids for auction sessions of this group
            $pdo->prepare('DELETE FROM bids WHERE auction_id IN (SELECT id FROM auction_sessions WHERE group_id = ?)')->execute([$targetId]);
            // Delete auction sessions
            $pdo->prepare('DELETE FROM auction_sessions WHERE group_id = ?')->execute([$targetId]);
            // Delete group join requests & group members
            $pdo->prepare('DELETE FROM group_join_requests WHERE group_id = ?')->execute([$targetId]);
            $pdo->prepare('DELETE FROM group_members WHERE group_id = ?')->execute([$targetId]);
            // Purge group
            $pdo->prepare('DELETE FROM groups WHERE id = ? AND deleted_at IS NOT NULL')->execute([$targetId]);

            $pdo->commit();
            logAdminAction($user['id'], 'purge_group_permanent', 'group', $targetId);
            flash('success', 'Group and all associated data permanently purged.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Failed to purge group: ' . $e->getMessage());
        }
    }
    redirect(APP_URL . '/admin/recycle-bin.php');
}

// Fetch deleted users
$deletedUsers = db()->query('
    SELECT u.*, GROUP_CONCAT(ur.role) as roles
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    WHERE u.deleted_at IS NOT NULL
    GROUP BY u.id
    ORDER BY u.deleted_at DESC
')->fetchAll();

// Fetch deleted groups
$deletedGroups = db()->query('
    SELECT g.*, u.display_name as leader_name
    FROM groups g
    LEFT JOIN users u ON u.id = g.leader_id
    WHERE g.deleted_at IS NOT NULL
    ORDER BY g.deleted_at DESC
')->fetchAll();

$pageTitle = 'Recycle Bin';
$currentPage = 'recycle-bin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin-nav.php';
?>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;">
    <div>
        <h1>Recycle Bin 🗑️</h1>
        <p>Manage soft-deleted users and groups. Restore mistakenly deleted items or permanently purge them.</p>
    </div>
    <div style="display:flex;gap:12px;">
        <span class="badge badge-danger" style="font-size:13px;padding:6px 12px;"><?= count($deletedUsers) ?> Deleted Users</span>
        <span class="badge badge-warning" style="font-size:13px;padding:6px 12px;"><?= count($deletedGroups) ?> Deleted Groups</span>
    </div>
</div>

<!-- DELETED USERS SECTION -->
<section style="margin-bottom:32px;">
    <h2 class="section-title" style="display:flex;align-items:center;gap:8px;">
        <span>👤</span> Soft-Deleted Users (<?= count($deletedUsers) ?>)
    </h2>
    <?php if (empty($deletedUsers)): ?>
    <div class="card" style="text-align:center;padding:24px;color:var(--muted);">
        <p style="margin:0;">No soft-deleted users in the recycle bin.</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email / Phone</th>
                    <th>Roles</th>
                    <th>Deleted At</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deletedUsers as $u): ?>
                <tr>
                    <td><strong><?= e($u['display_name']) ?></strong></td>
                    <td><?= e($u['email']) ?> <?= $u['phone'] ? '· ' . e($u['phone']) : '' ?></td>
                    <td>
                        <?php foreach (explode(',', $u['roles'] ?? '') as $role): ?>
                        <?php if ($role): ?><span class="badge badge-primary"><?= e($role) ?></span><?php endif; ?>
                        <?php endforeach; ?>
                    </td>
                    <td class="text-muted"><?= formatDate($u['deleted_at']) ?></td>
                    <td class="text-right">
                        <form method="POST" style="display:inline-flex;gap:6px;">
                            <?= csrfField() ?>
                            <input type="hidden" name="target_id" value="<?= $u['id'] ?>">
                            <button type="submit" name="action" value="restore_user" class="btn btn-sm btn-success">🔄 Restore</button>
                            <button type="submit" name="action" value="purge_user" onclick="return confirm('Permanently delete this user? This CANNOT be undone!');" class="btn btn-sm btn-danger">❌ Purge</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<!-- DELETED GROUPS SECTION -->
<section>
    <h2 class="section-title" style="display:flex;align-items:center;gap:8px;">
        <span>👥</span> Soft-Deleted Groups (<?= count($deletedGroups) ?>)
    </h2>
    <?php if (empty($deletedGroups)): ?>
    <div class="card" style="text-align:center;padding:24px;color:var(--muted);">
        <p style="margin:0;">No soft-deleted groups in the recycle bin.</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Group Name</th>
                    <th>Leader</th>
                    <th>Chit Value</th>
                    <th>Code</th>
                    <th>Deleted At</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deletedGroups as $g): ?>
                <tr>
                    <td><strong><?= e($g['name']) ?></strong></td>
                    <td><?= e($g['leader_name'] ?? 'Unknown') ?></td>
                    <td><?= paiseToINR((int)$g['chit_value_paise']) ?></td>
                    <td><code><?= e($g['join_code']) ?></code></td>
                    <td class="text-muted"><?= formatDate($g['deleted_at']) ?></td>
                    <td class="text-right">
                        <form method="POST" style="display:inline-flex;gap:6px;">
                            <?= csrfField() ?>
                            <input type="hidden" name="target_id" value="<?= $g['id'] ?>">
                            <button type="submit" name="action" value="restore_group" class="btn btn-sm btn-success">🔄 Restore</button>
                            <button type="submit" name="action" value="purge_group" onclick="return confirm('Permanently delete this group? This CANNOT be undone!');" class="btn btn-sm btn-danger">❌ Purge</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
