<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$user = currentUser();

if (isPost() && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    $groupId = (int)($_POST['group_id'] ?? 0);

    if ($action === 'delete') {
        db()->prepare('UPDATE groups SET deleted_at = NOW() WHERE id = ?')->execute([$groupId]);
        logAdminAction($user['id'], 'delete_group_recycle_bin', 'group', $groupId);
        flash('success', 'Group moved to Recycle Bin. You can restore it anytime.');
    } elseif ($action === 'create_group') {
        $name = trim($_POST['name'] ?? '');
        $leaderId = (int)($_POST['leader_id'] ?? $user['id']);
        $chitValue = rupeesToPaise((float)($_POST['chit_value'] ?? 0));
        $monthlyContribution = rupeesToPaise((float)($_POST['monthly_contribution'] ?? 0));
        $totalMembers = (int)($_POST['total_members'] ?? 20);
        $durationMonths = (int)($_POST['duration_months'] ?? 20);
        $commissionPct = (float)($_POST['commission_pct'] ?? 4);
        $visibilityType = $_POST['visibility_type'] ?? 'public';

        if (empty($name) || $chitValue <= 0 || $totalMembers < 2 || $durationMonths < 2) {
            flash('error', 'Please fill all required group fields correctly.');
        } else {
            $joinCode = generateJoinCode();
            $stmt = db()->prepare('
                INSERT INTO groups (leader_id, name, chit_value_paise, monthly_contribution_paise, total_members, duration_months, commission_pct, join_code, visibility_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$leaderId, $name, $chitValue, $monthlyContribution, $totalMembers, $durationMonths, $commissionPct, $joinCode, $visibilityType]);
            $newGroupId = (int) db()->lastInsertId();

            // Add leader as member automatically
            db()->prepare('INSERT IGNORE INTO group_members (group_id, user_id) VALUES (?, ?)')->execute([$newGroupId, $leaderId]);

            logAdminAction($user['id'], 'create_group', 'group', $newGroupId);
            flash('success', "Group created successfully! Join Code: $joinCode");
        }
    }
    redirect(APP_URL . '/admin/groups.php');
}

$leaders = db()->query("
    SELECT u.id, u.display_name, u.email
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    WHERE ur.role IN ('leader', 'admin') AND u.deleted_at IS NULL
    GROUP BY u.id
    ORDER BY u.display_name ASC
")->fetchAll();

$groups = db()->query('
    SELECT g.*, u.display_name as leader_name,
    (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as member_count
    FROM groups g
    LEFT JOIN users u ON u.id = g.leader_id
    WHERE g.deleted_at IS NULL
    ORDER BY g.created_at DESC
')->fetchAll();

$pageTitle = 'Groups';
$currentPage = 'groups';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin-nav.php';
?>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;">
    <div>
        <h1>Groups</h1>
        <p>All active chit fund groups on the platform</p>
    </div>
    <div style="display:flex;gap:8px;">
        <a href="<?= APP_URL ?>/admin/groups.php?action=create" class="btn btn-sm btn-primary">➕ Create New Group</a>
        <a href="<?= APP_URL ?>/admin/recycle-bin.php" class="btn btn-sm btn-outline">🗑️ Open Recycle Bin</a>
    </div>
</div>

<?php if (isset($_GET['action']) && $_GET['action'] === 'create'): ?>
<div class="card" style="margin-bottom:24px;border:2px solid var(--primary);background:var(--card-bg);padding:24px;border-radius:12px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h3 style="margin:0;font-size:18px;font-weight:700;color:var(--primary);">➕ Create New Chit Fund Group</h3>
        <a href="<?= APP_URL ?>/admin/groups.php" class="btn btn-sm btn-outline" style="text-decoration:none;">✕ Cancel</a>
    </div>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_group">

        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin-bottom:16px;">
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Group Name</label>
                <input type="text" name="name" class="form-control" placeholder="e.g. Gold Savings Chit" required>
            </div>
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Group Leader</label>
                <select name="leader_id" class="form-control" required>
                    <?php foreach ($leaders as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= $l['id'] == $user['id'] ? 'selected' : '' ?>>
                        <?= e($l['display_name']) ?> (<?= e($l['email']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Chit Value (₹)</label>
                <input type="number" name="chit_value" class="form-control" required min="1000" step="1000" value="100000">
            </div>
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Monthly Contribution (₹)</label>
                <input type="number" name="monthly_contribution" class="form-control" required min="100" step="100" value="5000">
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:16px;margin-bottom:20px;">
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Total Members</label>
                <input type="number" name="total_members" class="form-control" required min="2" max="50" value="20">
            </div>
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Duration (Months)</label>
                <input type="number" name="duration_months" class="form-control" required min="2" max="60" value="20">
            </div>
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Foreman Commission (%)</label>
                <input type="number" name="commission_pct" class="form-control" min="0" max="20" step="0.5" value="4">
            </div>
            <div>
                <label class="form-label" style="font-weight:600;display:block;margin-bottom:6px;">Visibility</label>
                <select name="visibility_type" class="form-control">
                    <option value="public">Public</option>
                    <option value="approval_required">Approval Required</option>
                    <option value="private_invite_only">Private (Invite Only)</option>
                </select>
            </div>
        </div>

        <div style="display:flex;gap:12px;justify-content:flex-end;">
            <a href="<?= APP_URL ?>/admin/groups.php" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary">🚀 Create Group</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Leader</th>
                <th>Chit Value</th>
                <th>Members</th>
                <th>Code</th>
                <th>Status</th>
                <th>Created</th>
                <th class="text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($groups as $g): ?>
            <tr>
                <td><strong><?= e($g['name']) ?></strong></td>
                <td><?= e($g['leader_name'] ?? 'Unknown') ?></td>
                <td><?= paiseToINR((int)$g['chit_value_paise']) ?></td>
                <td><?= $g['member_count'] ?>/<?= $g['total_members'] ?></td>
                <td><code><?= e($g['join_code']) ?></code></td>
                <td><span class="badge badge-<?= $g['status'] === 'open' ? 'success' : 'primary' ?>"><?= e($g['status']) ?></span></td>
                <td class="text-muted"><?= formatDateShort($g['created_at']) ?></td>
                <td class="text-right">
                    <form method="POST" style="display:inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="group_id" value="<?= $g['id'] ?>">
                        <button type="submit" name="action" value="delete" onclick="return confirm('Move this group to Recycle Bin?')" class="btn btn-sm btn-outline" style="color:#ef4444;border-color:#ef4444;">🗑️ Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
