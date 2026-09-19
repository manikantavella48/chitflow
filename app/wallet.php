<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$user = currentUser();

// Fetch latest user wallet
$wallet = getUserWallet($user['id']);

if (isPost() && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'withdraw') {
        $amount = rupeesToPaise((float)($_POST['amount'] ?? 0));
        $availablePaise = (int)($wallet['available_paise'] ?? 0);
        if ($amount > 0 && $amount <= $availablePaise) {
            if (debitWallet($user['id'], $amount, 'withdrawal_request', null, 'Withdrawal request')) {
                db()->prepare('INSERT INTO withdrawal_requests (user_id, amount_paise, bank_details) VALUES (?, ?, ?)')
                    ->execute([$user['id'], $amount, $_POST['bank_details'] ?? '']);
                flash('success', 'Withdrawal request submitted successfully.');
            } else {
                flash('error', 'Insufficient balance.');
            }
        } else {
            flash('error', 'Invalid amount or exceeds available balance.');
        }
        redirect(APP_URL . '/app/wallet.php');
    }
}

$stmt = db()->prepare('SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([$user['id']]);
$transactions = $stmt->fetchAll();

$availableRupees = number_format(((int)($wallet['available_paise'] ?? 0)) / 100, 2, '.', '');

$pageTitle = 'Wallet';
$currentPage = 'wallet';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/app-nav.php';
?>

<div class="page-header">
    <h1>Wallet</h1>
    <p>Manage your balance and view transaction history</p>
</div>

<div class="balance-card">
    <div class="balance-label">Available Balance</div>
    <div class="balance-amount"><?= paiseToINR((int)($wallet['available_paise'] ?? 0)) ?></div>
    <div class="balance-locked">Locked: <?= paiseToINR((int)($wallet['locked_paise'] ?? 0)) ?></div>
</div>

<div style="margin-bottom:24px;">
    <div class="card">
        <h3 class="card-title">Withdraw Funds</h3>
        <form method="POST" style="margin-top:12px;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="withdraw">
            <div class="form-group" style="position:relative;">
                <input type="number" id="withdrawInput" name="amount" class="form-control" placeholder="Amount in ₹" min="0.01" max="<?= $availableRupees ?>" step="0.01" required style="padding-right:110px;">
                <button type="button" onclick="document.getElementById('withdrawInput').value='<?= $availableRupees ?>'" style="position:absolute;right:8px;top:7px;padding:6px 12px;font-size:11px;font-weight:700;background:var(--primary);color:#fff;border:none;border-radius:6px;cursor:pointer;">Withdraw All</button>
            </div>
            <div class="form-group">
                <input type="text" name="bank_details" class="form-control" placeholder="Bank account details (UPI / Account No / IFSC)" required>
            </div>
            <button type="submit" class="btn btn-outline btn-block">Request Withdrawal</button>
        </form>
    </div>
</div>

<section>
    <h2 class="section-title">Transaction History</h2>
    <?php if (empty($transactions)): ?>
    <div class="empty-state"><p>No transactions yet.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Note</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $tx): ?>
                <tr>
                    <td class="text-muted"><?= formatDate($tx['created_at']) ?></td>
                    <td><span class="badge badge-primary"><?= e($tx['kind']) ?></span></td>
                    <td><?= e($tx['note'] ?? '—') ?></td>
                    <td class="text-right <?= $tx['amount_paise'] >= 0 ? 'text-success' : 'text-danger' ?>" style="font-weight:600;">
                        <?= ($tx['amount_paise'] >= 0 ? '+' : '') . paiseToINR((int)$tx['amount_paise']) ?>
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
