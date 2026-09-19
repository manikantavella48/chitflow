<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLeader();

$user = currentUser();

if (isPost() && verifyCsrf()) {
    $name = trim($_POST['name'] ?? '');
    $chitValue = rupeesToPaise((float)($_POST['chit_value'] ?? 0));
    $monthlyContribution = rupeesToPaise((float)($_POST['monthly_contribution'] ?? 0));
    $totalMembers = (int)($_POST['total_members'] ?? 0);
    $durationMonths = (int)($_POST['duration_months'] ?? 0);
    $commissionPct = (float)($_POST['commission_pct'] ?? 4);

    if (empty($name) || $chitValue <= 0 || $totalMembers < 2 || $durationMonths < 2) {
        flash('error', 'Please fill all required fields correctly.');
    } else {
        $joinCode = generateJoinCode();
        $stmt = db()->prepare('
            INSERT INTO groups (leader_id, name, chit_value_paise, monthly_contribution_paise, total_members, duration_months, commission_pct, join_code)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$user['id'], $name, $chitValue, $monthlyContribution, $totalMembers, $durationMonths, $commissionPct, $joinCode]);
        $groupId = (int) db()->lastInsertId();

        db()->prepare('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)')->execute([$groupId, $user['id']]);

        flash('success', "Group created! Join code: $joinCode");
        redirect(APP_URL . '/app/groups/view.php?id=' . $groupId);
    }
}

$pageTitle = 'Create Group';
$currentPage = 'groups';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/app-nav.php';
?>

<div class="page-header">
    <h1>Create New Group</h1>
    <p>Set up a new chit fund group</p>
</div>

<div class="card" style="max-width:600px;">
    <form method="POST">
        <?= csrfField() ?>
        <div class="form-group">
            <label for="name">Group Name</label>
            <input type="text" id="name" name="name" class="form-control" required placeholder="e.g. Gold Savings Chit">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="chit_value">Chit Value (₹)</label>
                <input type="number" id="chit_value" name="chit_value" class="form-control" required min="1000" step="1000" value="100000">
            </div>
            <div class="form-group">
                <label for="monthly_contribution">Monthly Contribution (₹)</label>
                <input type="number" id="monthly_contribution" name="monthly_contribution" class="form-control" required min="100" step="100" value="5000">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="total_members">Total Members</label>
                <input type="number" id="total_members" name="total_members" class="form-control" required min="2" max="50" value="20">
            </div>
            <div class="form-group">
                <label for="duration_months">Duration (Months)</label>
                <input type="number" id="duration_months" name="duration_months" class="form-control" required min="2" max="60" value="20">
            </div>
        </div>
        <div class="form-group">
            <label for="commission_pct">Foreman Commission (%)</label>
            <input type="number" id="commission_pct" name="commission_pct" class="form-control" min="0" max="20" step="0.5" value="4">
            <div style="font-size:12px;color:var(--muted);margin-top:4px;">Default Chit Fund Leader Commission: <strong>4.0%</strong></div>
        </div>
        <button type="submit" class="btn gradient-btn">Create Group</button>
        <a href="<?= APP_URL ?>/app/groups/index.php" class="btn btn-outline" style="margin-left:8px;">Cancel</a>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const chitValInput = document.getElementById('chit_value');
    const membersInput = document.getElementById('total_members');
    const monthlyInput = document.getElementById('monthly_contribution');
    const durationInput = document.getElementById('duration_months');

    function autoCalc() {
        const chitVal = parseFloat(chitValInput.value) || 0;
        const members = parseInt(membersInput.value) || 0;
        if (members > 0 && chitVal > 0) {
            monthlyInput.value = Math.round(chitVal / members);
            durationInput.value = members;
        }
    }

    chitValInput.addEventListener('input', autoCalc);
    membersInput.addEventListener('input', autoCalc);
});
</script>

</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
