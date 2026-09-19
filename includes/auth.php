<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

session_name(SESSION_NAME);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function currentUser(): ?array {
    if (empty($_SESSION['user_id'])) return null;

    static $user = null;
    if ($user === null) {
        $uid = (int)$_SESSION['user_id'];
        syncUserWallet($uid);
        $stmt = db()->prepare('SELECT u.*, w.available_paise, w.locked_paise FROM users u LEFT JOIN wallets w ON w.user_id = u.id WHERE u.id = ?');
        $stmt->execute([$uid]);
        $user = $stmt->fetch() ?: null;
        if ($user) {
            if (!empty($user['deleted_at'])) {
                // User is in Recycle Bin -> force logout
                unset($_SESSION['user_id']);
                $user = null;
                return null;
            }
            $stmt = db()->prepare('SELECT role FROM user_roles WHERE user_id = ?');
            $stmt->execute([$user['id']]);
            $user['roles'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    }
    return $user;
}

function isLoggedIn(): bool {
    return currentUser() !== null;
}

function isAdmin(): bool {
    $user = currentUser();
    return $user && in_array('admin', $user['roles'] ?? []);
}

function isLeader(): bool {
    $user = currentUser();
    return $user && (in_array('leader', $user['roles'] ?? []) || in_array('admin', $user['roles'] ?? []));
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        flash('error', 'Please log in to continue.');
        redirect(APP_URL . '/login.php');
    }
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        flash('error', 'Admin access required.');
        redirect(APP_URL . '/app/index.php');
    }
}

function requireLeader(): void {
    requireLogin();
    if (!isLeader()) {
        flash('error', 'Leader access required.');
        redirect(APP_URL . '/app/index.php');
    }
}

function loginUser(string $loginInput, string $password): array {
    $input = trim($loginInput);
    $cleanPhone = preg_replace('/[^0-9]/', '', $input);

    $stmt = db()->prepare('
        SELECT * FROM users 
        WHERE email = ? 
           OR (phone IS NOT NULL AND phone != "" AND (phone = ? OR REPLACE(phone, " ", "") = ? OR REPLACE(phone, "-", "") = ?))
    ');
    $stmt->execute([$input, $input, $cleanPhone, $cleanPhone]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Invalid email/phone or password'];
    }

    if (!empty($user['deleted_at'])) {
        return ['success' => false, 'message' => 'Your account has been deleted and is in the Recycle Bin. Contact an admin to restore your account.'];
    }

    if ($user['is_frozen']) {
        return ['success' => false, 'message' => 'Your account has been frozen. Contact support.'];
    }

    $_SESSION['user_id'] = $user['id'];
    return ['success' => true, 'user' => $user];
}

function registerUser(string $email, string $password, string $displayName, ?string $phone = null): array {
    if (strlen($password) < 8) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters'];
    }

    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([trim($email)]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Email already registered'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO users (email, password_hash, display_name, phone) VALUES (?, ?, ?, ?)')
            ->execute([trim($email), $hash, trim($displayName), $phone]);
        $userId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO user_roles (user_id, role) VALUES (?, 'member')")->execute([$userId]);
        $pdo->prepare('INSERT INTO wallets (user_id) VALUES (?)')->execute([$userId]);

        $pdo->commit();
        $_SESSION['user_id'] = $userId;
        return ['success' => true, 'user_id' => $userId];
    } catch (Throwable $e) {
        try {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $rbEx) {
            // Ignore rollback failure if connection was lost
        }
        return ['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()];
    }
}

function logoutUser(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
