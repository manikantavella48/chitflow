<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$user = currentUser();

if (isPost() && verifyCsrf()) {
    $docId = (int)($_POST['doc_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        db()->prepare("DELETE FROM kyc_documents WHERE id = ?")->execute([$docId]);
        logAdminAction($user['id'], 'delete_kyc', 'kyc', $docId);
        flash('success', 'KYC document record deleted successfully.');
        redirect(APP_URL . '/admin/kyc.php');
    }

    if ($action === 'approve') {
        db()->prepare("UPDATE kyc_documents SET status = 'approved', reviewed_by = ? WHERE id = ?")
            ->execute([$user['id'], $docId]);
        logAdminAction($user['id'], 'approve_kyc', 'kyc', $docId);
        flash('success', 'KYC approved.');
    } elseif ($action === 'reject') {
        db()->prepare("UPDATE kyc_documents SET status = 'rejected', reviewed_by = ?, rejection_reason = ? WHERE id = ?")
            ->execute([$user['id'], $_POST['reason'] ?? 'Rejected', $docId]);
        logAdminAction($user['id'], 'reject_kyc', 'kyc', $docId);
        flash('success', 'KYC rejected.');
    }
    redirect(APP_URL . '/admin/kyc.php');
}

$docs = db()->query("
    SELECT k.*, u.display_name, u.email
    FROM kyc_documents k
    JOIN users u ON u.id = k.user_id
    ORDER BY FIELD(k.status, 'pending', 'approved', 'rejected'), k.created_at DESC
")->fetchAll();

$pageTitle = 'KYC Review';
$currentPage = 'kyc';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/admin-nav.php';
?>

<div class="page-header">
    <h1>KYC Review</h1>
    <p>Review and approve identity documents</p>
</div>

<?php if (empty($docs)): ?>
<div class="empty-state"><p>No KYC documents submitted yet.</p></div>
<?php else: ?>
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>User</th>
                <th>Document Type</th>
                <th>Status</th>
                <th>Submitted</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($docs as $d): ?>
            <tr>
                <td>
                    <strong><?= e($d['display_name']) ?></strong><br>
                    <span class="text-muted"><?= e($d['email']) ?></span>
                </td>
                <td><?= e($d['kind']) ?></td>
                <td><span class="badge badge-<?= $d['status'] === 'pending' ? 'warning' : ($d['status'] === 'approved' ? 'success' : 'danger') ?>"><?= e($d['status']) ?></span></td>
                <td class="text-muted"><?= formatDate($d['created_at']) ?></td>
                <td>
                    <?php if ($d['status'] === 'pending'): ?>
                    <form method="POST" style="display:inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
                        <button type="submit" name="action" value="approve" class="btn btn-sm btn-success">Approve</button>
                        <button type="submit" name="action" value="reject" class="btn btn-sm btn-danger">Reject</button>
                    </form>
                    <?php else: ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this KYC document record?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
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
