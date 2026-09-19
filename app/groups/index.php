<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$user = currentUser();

if (isPost() && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'join') {
        $result = joinGroupByCode($user['id'], $_POST['code'] ?? '');
        flash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(APP_URL . '/app/groups/index.php');
    } elseif ($action === 'join_direct') {
        $targetGroupId = (int)($_POST['group_id'] ?? 0);
        $stmt = db()->prepare('SELECT join_code FROM groups WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$targetGroupId]);
        $code = $stmt->fetchColumn();

        if ($code) {
            $result = joinGroupByCode($user['id'], $code);
            flash($result['success'] ? 'success' : 'error', $result['message']);
        }
        redirect(APP_URL . '/app/groups/index.php');
    }
}

// 1. Get user's joined/led groups
$stmt = db()->prepare('
    SELECT g.*, u.display_name as leader_name,
    (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as current_member_count,
    IF(g.leader_id = ?, "Leader", "Member") as user_role_in_group
    FROM group_members gm
    JOIN groups g ON g.id = gm.group_id
    LEFT JOIN users u ON u.id = g.leader_id
    WHERE gm.user_id = ? AND g.deleted_at IS NULL
    ORDER BY g.created_at DESC
');
$stmt->execute([$user['id'], $user['id']]);
$myGroups = $stmt->fetchAll();

// 2. Get remaining available public groups
$myGroupIds = array_column($myGroups, 'id');
$sqlOther = '
    SELECT g.*, u.display_name as leader_name,
    (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as current_member_count
    FROM groups g
    LEFT JOIN users u ON u.id = g.leader_id
    WHERE g.deleted_at IS NULL AND g.status = "open" AND g.visibility_type != "private_invite_only"
';
$otherParams = [];
if (!empty($myGroupIds)) {
    $placeholders = implode(',', array_fill(0, count($myGroupIds), '?'));
    $sqlOther .= " AND g.id NOT IN ($placeholders)";
    $otherParams = $myGroupIds;
}
$sqlOther .= ' ORDER BY g.created_at DESC';

$stmt = db()->prepare($sqlOther);
$stmt->execute($otherParams);
$otherGroups = $stmt->fetchAll();

$pageTitle = 'Groups';
$currentPage = 'groups';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/app-nav.php';
?>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;">
    <div>
        <h1>Groups</h1>
        <p>Manage your enrolled groups and explore available chit plans</p>
    </div>
    <?php if (isLeader()): ?>
    <a href="<?= APP_URL ?>/app/groups/new.php" class="btn btn-sm btn-primary">+ New Group</a>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom:28px;">
    <h3 style="font-size:14px;font-weight:600;margin-bottom:12px;">Join Group with Code</h3>
    <form method="POST" style="display:flex;gap:8px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="join">
        <input type="text" name="code" class="form-control" placeholder="e.g. CHX-DEMO1" maxlength="20" style="text-transform:uppercase;" required>
        <button type="submit" class="btn btn-primary">Join</button>
    </form>
</div>

<!-- SECTION 1: YOUR GROUPS -->
<section style="margin-bottom:36px;">
    <h2 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--primary);margin-bottom:16px;display:flex;align-items:center;gap:6px;">
        <span>👥</span> Your Groups (<?= count($myGroups) ?>)
    </h2>
    <?php if (empty($myGroups)): ?>
    <div class="card" style="text-align:center;padding:24px;color:var(--muted);">
        <p style="margin:0;">You haven't joined any groups yet. Browse available groups below or join with a code!</p>
    </div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:12px;">
        <?php foreach ($myGroups as $g): ?>
        <a href="<?= APP_URL ?>/app/groups/view.php?id=<?= $g['id'] ?>" class="list-item" style="border-left:4px solid var(--primary);text-decoration:none;">
            <div>
                <div class="list-item-title" style="display:flex;align-items:center;gap:8px;">
                    <strong><?= e($g['name']) ?></strong>
                    <span class="badge badge-<?= $g['user_role_in_group'] === 'Leader' ? 'primary' : 'outline' ?>" style="font-size:11px;">
                        <?= e($g['user_role_in_group']) ?>
                    </span>
                </div>
                <div class="list-item-meta" style="margin-top:4px;">
                    Chit value <?= paiseToINR((int)$g['chit_value_paise']) ?> · <?= $g['current_member_count'] ?>/<?= $g['total_members'] ?> members · Code: <code><?= e($g['join_code']) ?></code>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="badge badge-<?= $g['status'] === 'open' ? 'success' : 'primary' ?>"><?= e($g['status']) ?></span>
                <span style="font-size:18px;">→</span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<!-- SECTION 2: OTHER AVAILABLE GROUPS -->
<section>
    <h2 style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);margin-bottom:16px;display:flex;align-items:center;gap:6px;">
        <span>🌐</span> Other Available Groups (<?= count($otherGroups) ?>)
    </h2>
    <?php if (empty($otherGroups)): ?>
    <div class="card" style="text-align:center;padding:24px;color:var(--muted);">
        <p style="margin:0;">No other public groups available right now.</p>
    </div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:12px;">
        <?php foreach ($otherGroups as $g): ?>
        <?php $spotsLeft = max(0, (int)$g['total_members'] - (int)$g['current_member_count']); ?>
        <div class="list-item" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <div>
                <div class="list-item-title" style="display:flex;align-items:center;gap:8px;">
                    <strong><?= e($g['name']) ?></strong>
                    <span class="badge badge-success" style="font-size:11px;"><?= $spotsLeft ?> spots left</span>
                </div>
                <div class="list-item-meta" style="margin-top:4px;">
                    Led by <?= e($g['leader_name'] ?? 'ChitFlow Leader') ?> · Chit value <?= paiseToINR((int)$g['chit_value_paise']) ?> · Monthly <?= paiseToINR((int)$g['monthly_contribution_paise']) ?> · Code: <code><?= e($g['join_code']) ?></code>
                </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <form method="POST" style="display:inline;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="join_direct">
                    <input type="hidden" name="group_id" value="<?= $g['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-primary">+ Join Group</button>
                </form>
                <a href="<?= APP_URL ?>/app/groups/view.php?id=<?= $g['id'] ?>" class="btn btn-sm btn-outline">Details</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
