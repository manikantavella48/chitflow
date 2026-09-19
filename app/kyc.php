<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$user = currentUser();

if (isPost() && verifyCsrf()) {
    $kind = $_POST['kind'] ?? '';
    if (!in_array($kind, ['pan', 'aadhaar', 'selfie'])) {
        flash('error', 'Invalid document type.');
    } elseif (!empty($_FILES['document']['name'])) {
        $uploadDir = UPLOAD_DIR;
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        if (!in_array($ext, $allowed)) {
            flash('error', 'Only JPG, PNG, and PDF files allowed.');
        } else {
            $filename = $user['id'] . '_' . $kind . '_' . time() . '.' . $ext;
            $filepath = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['document']['tmp_name'], $filepath)) {
                db()->prepare('INSERT INTO kyc_documents (user_id, kind, file_path) VALUES (?, ?, ?)')
                    ->execute([$user['id'], $kind, $filename]);
                flash('success', 'Document submitted for review.');
            } else {
                flash('error', 'Upload failed.');
            }
        }
    } else {
        flash('error', 'Please select a file.');
    }
    redirect(APP_URL . '/app/kyc.php');
}

$stmt = db()->prepare('SELECT * FROM kyc_documents WHERE user_id = ? ORDER BY created_at DESC');
$stmt->execute([$user['id']]);
$documents = $stmt->fetchAll();

$pageTitle = 'KYC Verification';
$currentPage = 'settings';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/app-nav.php';
?>

<div class="page-header">
    <h1>KYC Verification</h1>
    <p>Submit identity documents for verification</p>
</div>

<div class="card" style="max-width:500px;margin-bottom:24px;">
    <h3 class="card-title">Upload Document</h3>
    <form method="POST" enctype="multipart/form-data" style="margin-top:16px;">
        <?= csrfField() ?>
        <div class="form-group">
            <label for="kind">Document Type</label>
            <select id="kind" name="kind" class="form-control" required>
                <option value="pan">PAN Card</option>
                <option value="aadhaar">Aadhaar Card</option>
                <option value="selfie">Selfie</option>
            </select>
        </div>
        <div class="form-group">
            <label for="document">File (JPG, PNG, or PDF)</label>
            <input type="file" id="document" name="document" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
        </div>
        <button type="submit" class="btn gradient-btn">Submit for Review</button>
    </form>
</div>

<?php if (!empty($documents)): ?>
<section>
    <h2 class="section-title">Your Submissions</h2>
    <?php foreach ($documents as $d): ?>
    <div class="list-item" style="cursor:default;">
        <div>
            <div class="list-item-title"><?= e(ucfirst($d['kind'])) ?></div>
            <div class="list-item-meta">Submitted <?= formatDate($d['created_at']) ?></div>
        </div>
        <span class="badge badge-<?= $d['status'] === 'pending' ? 'warning' : ($d['status'] === 'approved' ? 'success' : 'danger') ?>">
            <?= e($d['status']) ?>
        </span>
    </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
