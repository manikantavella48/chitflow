<?php
require_once __DIR__ . '/auth.php';
$user = currentUser();
$flash = getFlash();
$pageTitle = $pageTitle ?? APP_NAME;
$bodyClass = $bodyClass ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
</head>
<body class="<?= e($bodyClass) ?>">
<?php if ($flash): ?>
<div class="flash flash-<?= e($flash['type']) ?>" id="flash-msg">
    <?= e($flash['message']) ?>
    <button onclick="this.parentElement.remove()" class="flash-close">&times;</button>
</div>
<?php endif; ?>
