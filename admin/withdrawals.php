<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$user = currentUser();

if (isPost() && verifyCsrf()) {
    $reqId = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        db()->prepare("DELETE FROM withdrawal_requests WHERE id = ?")->execute([$reqId]);
        logAdminAction($user['id'], 'delete_withdrawal', 'withdrawal', $reqId);
        flash('success', 'Withdrawal request record deleted successfully.');
        redirect(APP_URL . '/admin/withdrawals.php');
    }

    $stmt = db()->prepare('SELECT * FROM withdrawal_requests WHERE id = ? AND status = ?');
    $stmt->execute([$reqId, 'pending']);
    $request = $stmt->fetch();

    if ($request) {
        if ($action === 'approve') {
            db()->prepare("UPDATE withdrawal_requests SET status = 'completed', reviewed_by = ? WHERE id = ?")
                ->execute([$user['id'], $reqId]);
            logAdminAction($user['id'], 'approve_withdrawal', 'withdrawal', $reqId);
            flash('success', 'Withdrawal approved and completed.');
        } elseif ($action === 'reject') {
            creditWallet($request['user_id'], $request['amount_paise'], 'withdrawal_refund', $reqId, 'Withdrawal rejected - refunded');
            db()->prepare("UPDATE withdrawal_requests SET status = 'rejected', reviewed_by = ? WHERE id = ?")
                ->execute([$user['id'], $reqId]);
            logAdminAction($user['id'], 'reject_withdrawal', 'withdrawal', $reqId);
            flash('success', 'Withdrawal rejected and amount refunded.');
        }
    }
    redirect(APP_URL . '/admin/withdrawals.php');
}

$requests = db()->query("
    SELECT w.*, u.display_name, u.email
    FROM withdrawal_requests w
    JOIN users u ON u.id = w.user_id
    ORDER BY FIELD(w.status, 'pending', 'completed', 'rejected'), w.created_at DESC
")->fetchAll();

$pageTitle = 'Withdrawals';
$currentPage = 'withdrawals';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin-nav.php';
?>

<div class="page-header">
    <h1>Withdrawal Requests</h1>
    <p>Approve or reject payout requests</p>
</div>

<?php if (empty($requests)): ?>
<div class="empty-state"><p>No withdrawal requests.</p></div>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>User</th>
                <th>Amount</th>
                <th>Bank Details</th>
                <th>Status</th>
                <th>Requested</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($requests as $r): ?>
            <tr>
                <td>
                    <strong><?= e($r['display_name']) ?></strong><br>
                    <span class="text-muted"><?= e($r['email']) ?></span>
                </td>
                <td style="font-weight:600;"><?= paiseToINR((int)$r['amount_paise']) ?></td>
                <td class="text-muted"><?= e($r['bank_details'] ?: '—') ?></td>
                <td><span class="badge badge-<?= $r['status'] === 'pending' ? 'warning' : ($r['status'] === 'completed' ? 'success' : 'danger') ?>"><?= e($r['status']) ?></span></td>
                <td class="text-muted"><?= formatDate($r['created_at']) ?></td>
                <td>
                    <?php if ($r['status'] === 'pending'): ?>
                    <form method="POST" style="display:inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                        <button type="submit" name="action" value="approve" class="btn btn-sm btn-success">Approve</button>
                        <button type="submit" name="action" value="reject" class="btn btn-sm btn-danger">Reject</button>
                    </form>
                    <?php else: ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this withdrawal record?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                        <button type="submit" name="action" value="delete" class="btn btn-sm btn-outline" style="color:#ef4444;border-color:#ef4444;font-weight:600;">🗑️ Delete</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
