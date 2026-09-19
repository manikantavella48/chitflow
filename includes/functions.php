<?php

function paiseToINR(int $paise): string {
    $rupees = $paise / 100;
    return '₹' . number_format($rupees, 2);
}

function rupeesToPaise(float $rupees): int {
    return (int) round($rupees * 100);
}

function formatDate(string $datetime): string {
    return date('d M Y, h:i A', strtotime($datetime));
}

function formatDateShort(string $datetime): string {
    return date('d M Y', strtotime($datetime));
}

function generateJoinCode(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = 'CHX-';
    for ($i = 0; $i < 5; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function isPost(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function hasRole(int $userId, string $role): bool {
    $stmt = db()->prepare('SELECT 1 FROM user_roles WHERE user_id = ? AND role = ?');
    $stmt->execute([$userId, $role]);
    return (bool) $stmt->fetch();
}

function isGroupMember(int $groupId, int $userId): bool {
    $stmt = db()->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$groupId, $userId]);
    return (bool) $stmt->fetch();
}

function isGroupLeader(int $groupId, int $userId): bool {
    $stmt = db()->prepare('SELECT 1 FROM groups WHERE id = ? AND leader_id = ?');
    $stmt->execute([$groupId, $userId]);
    return (bool) $stmt->fetch();
}

function getMemberCount(int $groupId): int {
    $stmt = db()->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ?');
    $stmt->execute([$groupId]);
    return (int) $stmt->fetchColumn();
}

function syncUserWallet(int $userId): void {
    $pdo = db();
    $pdo->prepare('INSERT IGNORE INTO wallets (user_id, available_paise) VALUES (?, 0)')->execute([$userId]);
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_paise), 0) FROM wallet_transactions WHERE user_id = ?');
    $stmt->execute([$userId]);
    $totalPaise = max(0, (int)$stmt->fetchColumn());
    $pdo->prepare('UPDATE wallets SET available_paise = ?, updated_at = NOW() WHERE user_id = ?')->execute([$totalPaise, $userId]);
}

function getUserWallet(int $userId): array {
    syncUserWallet($userId);
    $stmt = db()->prepare('SELECT * FROM wallets WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: ['user_id' => $userId, 'available_paise' => 0, 'locked_paise' => 0];
}

function creditWallet(int $userId, int $amountPaise, string $kind, ?int $refId = null, ?string $note = null): void {
    $pdo = db();
    $pdo->prepare('INSERT INTO wallet_transactions (user_id, amount_paise, kind, reference_id, note) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $amountPaise, $kind, $refId, $note]);
    syncUserWallet($userId);
}

function debitWallet(int $userId, int $amountPaise, string $kind, ?int $refId = null, ?string $note = null): bool {
    $pdo = db();
    syncUserWallet($userId);
    $stmt = $pdo->prepare('SELECT available_paise FROM wallets WHERE user_id = ? FOR UPDATE');
    $pdo->beginTransaction();
    try {
        $stmt->execute([$userId]);
        $balance = (int) $stmt->fetchColumn();
        if ($balance < $amountPaise) {
            $pdo->rollBack();
            return false;
        }
        $pdo->prepare('INSERT INTO wallet_transactions (user_id, amount_paise, kind, reference_id, note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, -$amountPaise, $kind, $refId, $note]);
        $pdo->commit();
        syncUserWallet($userId);
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

function createNotification(int $userId, string $type, string $title, string $body): void {
    db()->prepare('INSERT INTO notifications (user_id, type, title, body) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $type, $title, $body]);
}

function notifyGroupMembers(int $groupId, string $type, string $title, string $body, ?int $excludeUserId = null): void {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT user_id FROM group_members WHERE group_id = ?');
    $stmt->execute([$groupId]);
    $members = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $insertStmt = $pdo->prepare('INSERT INTO notifications (user_id, type, title, body) VALUES (?, ?, ?, ?)');
    foreach ($members as $uId) {
        if ($excludeUserId && (int)$uId === (int)$excludeUserId) continue;
        $insertStmt->execute([(int)$uId, $type, $title, $body]);
    }
}

function logAdminAction(int $adminId, string $action, ?string $targetType = null, ?int $targetId = null, ?string $details = null): void {
    db()->prepare('INSERT INTO admin_audit_logs (admin_id, action, target_type, target_id, details) VALUES (?, ?, ?, ?, ?)')
        ->execute([$adminId, $action, $targetType, $targetId, $details]);
}

function placeBid(int $auctionId, int $userId, int $discountPaise): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            SELECT a.*, g.name as group_name, g.chit_value_paise, g.commission_pct, g.id as gid
            FROM auction_sessions a
            JOIN groups g ON g.id = a.group_id
            WHERE a.id = ? FOR UPDATE
        ');
        $stmt->execute([$auctionId]);
        $auction = $stmt->fetch();

        if (!$auction) throw new Exception('Auction not found');
        if ($auction['state'] !== 'live') throw new Exception('Auction is not live');
        if (strtotime($auction['ends_at']) < time()) throw new Exception('Auction has ended');
        if (!isGroupMember($auction['gid'], $userId)) throw new Exception('You are not a group member');

        // Check if this is the final cycle
        $stmtDur = $pdo->prepare('SELECT duration_months, total_members FROM groups WHERE id = ?');
        $stmtDur->execute([$auction['gid']]);
        $gInfo = $stmtDur->fetch();
        $totalCycles = max(1, (int)($gInfo['duration_months'] ?? $gInfo['total_members']));

        if ((int)$auction['cycle_number'] >= $totalCycles) {
            throw new Exception('This is the final cycle. The last non-winning member automatically receives the chit payout (Chit Value − 4% Leader Commission). No manual bidding required!');
        }

        // Discount must start at or above 4% leader commission (e.g. ₹4,000 for ₹100,000 chit value)
        $commissionPct = (float)($auction['commission_pct'] ?: 4.0);
        $minAllowedDiscountPaise = (int)round($auction['chit_value_paise'] * ($commissionPct / 100));
        if ($discountPaise < $minAllowedDiscountPaise) {
            throw new Exception('Bid discount must start at or above the 4% leader commission (' . paiseToINR($minAllowedDiscountPaise) . ')');
        }

        // Discount must be a multiple of ₹100 (10,000 paise)
        if ($discountPaise % 10000 !== 0) {
            throw new Exception('Bid discount must be a multiple of ₹100 (e.g. ₹100, ₹200, ₹500, ₹1000)');
        }

        // Discount cannot exceed 45% of chit value
        $maxDiscountPaise = (int)round($auction['chit_value_paise'] * 0.45);
        if ($discountPaise > $maxDiscountPaise) {
            throw new Exception('Discount cannot exceed 45% of chit value (' . paiseToINR($maxDiscountPaise) . ')');
        }

        // Check if user has already won a completed auction in this group
        $stmt = $pdo->prepare('SELECT 1 FROM auction_sessions WHERE group_id = ? AND winner_user_id = ? AND state = "completed"');
        $stmt->execute([$auction['gid'], $userId]);
        if ($stmt->fetch()) {
            throw new Exception('You have already won an auction in this group and are not eligible to bid again.');
        }

        $stmt = $pdo->prepare('SELECT COALESCE(MAX(discount_paise), 0) FROM bids WHERE auction_id = ?');
        $stmt->execute([$auctionId]);
        $currentMax = (int) $stmt->fetchColumn();
        if ($discountPaise <= $currentMax) {
            throw new Exception('Bid must be higher than current top bid (' . paiseToINR($currentMax) . ')');
        }

        $pdo->prepare('INSERT INTO bids (auction_id, user_id, discount_paise) VALUES (?, ?, ?)')
            ->execute([$auctionId, $userId, $discountPaise]);

        // Get bidder name
        $stmt = $pdo->prepare('SELECT display_name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $bidderName = $stmt->fetchColumn() ?: 'A group member';

        // Notify all group members
        notifyGroupMembers(
            (int)$auction['gid'],
            'bid_placed',
            '⚡ New High Bid Placed!',
            "{$bidderName} placed a new top bid of " . paiseToINR($discountPaise) . " in {$auction['group_name']}!",
            $userId
        );

        $pdo->commit();
        return ['success' => true, 'message' => 'Bid placed successfully'];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function triggerSpinWheel(int $auctionId, int $leaderId, int $durationSeconds = 8, int $selectedWinnerUserId = 0): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            SELECT a.*, g.name as group_name, g.leader_id, g.id as gid, g.chit_value_paise, g.commission_pct
            FROM auction_sessions a
            JOIN groups g ON g.id = a.group_id
            WHERE a.id = ? FOR UPDATE
        ');
        $stmt->execute([$auctionId]);
        $auction = $stmt->fetch();

        if (!$auction) throw new Exception('Auction not found');
        if ($auction['leader_id'] != $leaderId && !hasRole($leaderId, 'admin')) {
            throw new Exception('Only group leader or admin can launch spin wheel draw');
        }

        if ($durationSeconds < 3 || $durationSeconds > 30) {
            $durationSeconds = 8;
        }

        // Fetch eligible non-winning group members
        $stmtMembers = $pdo->prepare('
            SELECT gm.user_id, u.display_name
            FROM group_members gm
            JOIN users u ON u.id = gm.user_id
            WHERE gm.group_id = ? AND gm.user_id NOT IN (
                SELECT winner_user_id FROM auction_sessions
                WHERE group_id = ? AND state = "completed" AND winner_user_id IS NOT NULL
            )
            ORDER BY gm.joined_at ASC
        ');
        $stmtMembers->execute([$auction['gid'], $auction['gid']]);
        $members = $stmtMembers->fetchAll();

        if (empty($members)) {
            throw new Exception('No eligible members available for spin wheel draw');
        }

        // Pick winner: either Admin pre-selected participant or random
        $winnerIdx = -1;
        if ($selectedWinnerUserId > 0) {
            foreach ($members as $idx => $m) {
                if ((int)$m['user_id'] === $selectedWinnerUserId) {
                    $winnerIdx = $idx;
                    break;
                }
            }
        }

        if ($winnerIdx === -1) {
            $winnerIdx = random_int(0, count($members) - 1);
        }

        $winnerUser = $members[$winnerIdx];
        $winnerUserId = (int)$winnerUser['user_id'];

        // Calculate target angle (5 full 360deg spins + target slice center at top 270deg)
        $sliceDeg = 360 / count($members);
        $sliceCenterDeg = ($winnerIdx * $sliceDeg) + ($sliceDeg / 2);
        $targetOffset = (360 - (int)round($sliceCenterDeg) + 270) % 360;
        $targetAngle = (360 * 5) + $targetOffset;

        $nowStr = date('Y-m-d H:i:s');
        $pdo->prepare('
            UPDATE auction_sessions
            SET spin_status = "spinning",
                spin_duration_seconds = ?,
                spin_started_at = ?,
                spin_winner_user_id = ?,
                spin_target_angle = ?
            WHERE id = ?
        ')->execute([$durationSeconds, $nowStr, $winnerUserId, $targetAngle, $auctionId]);

        notifyGroupMembers(
            (int)$auction['gid'],
            'spin_started',
            '🎰 Lucky Spin Wheel Draw Started!',
            "Zero bids received in {$auction['group_name']}. The lucky spin wheel is now spinning live to select the winner!"
        );

        $pdo->commit();
        return [
            'success' => true,
            'message' => 'Spin wheel started!',
            'duration_seconds' => $durationSeconds,
            'winner_user_id' => $winnerUserId,
            'winner_name' => $winnerUser['display_name'],
            'target_angle' => $targetAngle
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function closeAuction(int $auctionId, int $leaderId): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            SELECT a.*, g.name as group_name, g.join_code, g.chit_value_paise, g.commission_pct, g.leader_id, g.id as gid
            FROM auction_sessions a
            JOIN groups g ON g.id = a.group_id
            WHERE a.id = ? FOR UPDATE
        ');
        $stmt->execute([$auctionId]);
        $auction = $stmt->fetch();

        if (!$auction) throw new Exception('Auction not found');
        if ($auction['leader_id'] != $leaderId && !hasRole($leaderId, 'admin')) {
            throw new Exception('Only group leader or admin can close auction');
        }

        $chit = (int) $auction['chit_value_paise'];
        $commissionPct = (float) ($auction['commission_pct'] ?: 4.0);
        $commissionAmt = (int) round($chit * ($commissionPct / 100));

        // Determine total cycles
        $stmtDur = $pdo->prepare('SELECT duration_months, total_members FROM groups WHERE id = ?');
        $stmtDur->execute([$auction['gid']]);
        $gInfo = $stmtDur->fetch();
        $totalCycles = max(1, (int)($gInfo['duration_months'] ?? $gInfo['total_members']));
        $isLastCycle = ((int)$auction['cycle_number'] >= $totalCycles);

        // Find last non-winning member
        $stmtLast = $pdo->prepare('
            SELECT gm.user_id FROM group_members gm
            WHERE gm.group_id = ? AND gm.user_id NOT IN (
                SELECT winner_user_id FROM auction_sessions
                WHERE group_id = ? AND state = "completed" AND winner_user_id IS NOT NULL
            )
            LIMIT 1
        ');
        $stmtLast->execute([$auction['gid'], $auction['gid']]);
        $lastNonWinnerId = (int)$stmtLast->fetchColumn();

        $stmt = $pdo->prepare('SELECT user_id, discount_paise FROM bids WHERE auction_id = ? ORDER BY discount_paise DESC, created_at ASC LIMIT 1');
        $stmt->execute([$auctionId]);
        $topBid = $stmt->fetch();

        if (!empty($auction['spin_winner_user_id'])) {
            // Winner selected via Lucky Spin Wheel!
            $winner = (int)$auction['spin_winner_user_id'];
            $top = $topBid ? max((int)$topBid['discount_paise'], $commissionAmt) : $commissionAmt;
            $pdo->prepare("UPDATE auction_sessions SET spin_status = 'completed' WHERE id = ?")->execute([$auctionId]);
        } elseif ($isLastCycle) {
            // For the last auction: the last member automatically gets chit amount minus 4% commission (100000 - 4% = 96000)
            $winner = $topBid ? (int)$topBid['user_id'] : ($lastNonWinnerId ?: (int)$leaderId);
            $top = $topBid ? max((int)$topBid['discount_paise'], $commissionAmt) : $commissionAmt;
        } else {
            if (!$topBid) {
                $pdo->prepare("UPDATE auction_sessions SET state = 'cancelled' WHERE id = ?")->execute([$auctionId]);
                notifyGroupMembers((int)$auction['gid'], 'auction_cancelled', 'Auction Cancelled', "Auction for {$auction['group_name']} was cancelled due to no bids.");
                $pdo->commit();
                return ['success' => true, 'message' => 'Auction cancelled (no bids)'];
            }
            $winner = (int)$topBid['user_id'];
            $top = (int)$topBid['discount_paise'];
        }

        $payout = $chit - $top; // e.g. 100,000 - 4,000 = 96,000
        $remainingDividendPool = max(0, $top - $commissionAmt);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ?');
        $stmt->execute([$auction['gid']]);
        $memberCount = (int) $stmt->fetchColumn();
        $dividendPerMember = $memberCount > 0 ? (int) floor($remainingDividendPool / $memberCount) : 0;

        // Credit winner with winning payout amount (e.g. ₹96,000)
        $pdo->prepare('INSERT INTO wallet_transactions (user_id, amount_paise, kind, reference_id, note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$winner, $payout, 'auction_payout', $auctionId, 'Auction win payout']);
        syncUserWallet($winner);

        // Leader commission (4% of Total Chit Value credited ONLY on Completed Auction)
        $groupCode = $auction['join_code'] ?? '';
        $groupName = $auction['group_name'] ?? 'Chit Group';
        $leaderNote = sprintf('Completed Auction 4%% Commission — %s (%s)', $groupName, $groupCode);
        $pdo->prepare('INSERT INTO wallet_transactions (user_id, amount_paise, kind, reference_id, note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$leaderId, $commissionAmt, 'leader_commission', $auctionId, $leaderNote]);
        syncUserWallet($leaderId);

        // Dividend to each member
        $stmt = $pdo->prepare('SELECT user_id FROM group_members WHERE group_id = ?');
        $stmt->execute([$auction['gid']]);
        while ($member = $stmt->fetch()) {
            $mId = (int)$member['user_id'];
            $pdo->prepare('INSERT INTO wallet_transactions (user_id, amount_paise, kind, reference_id, note) VALUES (?, ?, ?, ?, ?)')
                ->execute([$mId, $dividendPerMember, 'dividend', $auctionId, "Cycle #{$auction['cycle_number']} dividend share"]);
            syncUserWallet($mId);
        }

        $pdo->prepare("UPDATE auction_sessions SET state = 'completed', winner_user_id = ?, winning_discount_paise = ? WHERE id = ?")
            ->execute([$winner, $top, $auctionId]);

        // Get winner name
        $stmt = $pdo->prepare('SELECT display_name FROM users WHERE id = ?');
        $stmt->execute([$winner]);
        $winnerName = $stmt->fetchColumn() ?: 'Group member';

        // Notify all group members
        notifyGroupMembers(
            (int)$auction['gid'],
            'auction_closed',
            '🏆 Auction Completed!',
            "Auction for {$auction['group_name']} is completed! Winner: {$winnerName} with winning discount " . paiseToINR($top) . "."
        );

        $pdo->commit();
        return ['success' => true, 'message' => 'Auction closed. Winner paid ' . paiseToINR($payout)];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function joinGroupByCode(int $userId, string $code): array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, name, total_members FROM groups WHERE join_code = ? AND status = 'open' AND deleted_at IS NULL");
    $stmt->execute([strtoupper(trim($code))]);
    $group = $stmt->fetch();

    if (!$group) return ['success' => false, 'message' => 'Group not found or closed'];

    $count = getMemberCount($group['id']);
    if ($count >= $group['total_members']) return ['success' => false, 'message' => 'Group is full'];

    try {
        $pdo->prepare('INSERT IGNORE INTO group_members (group_id, user_id) VALUES (?, ?)')
            ->execute([$group['id'], $userId]);

        $newCount = getMemberCount($group['id']);
        if ($newCount >= (int)$group['total_members']) {
            $pdo->prepare("UPDATE groups SET status = 'active' WHERE id = ?")->execute([$group['id']]);
            notifyGroupMembers(
                $group['id'],
                'group_activated',
                '🎉 Group Fully Enrolled & Activated!',
                "{$group['name']} has reached 100% capacity ({$newCount}/{$group['total_members']} members)! Auctions are now activated and ready to start."
            );
        }

        return ['success' => true, 'message' => 'Joined group successfully', 'group_id' => $group['id']];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Could not join group'];
    }
}

/**
 * Get trigger offset in minutes for alert timing preset
 */
function getTimingOffsetMinutes(string $timingType, int $customMinutes = 0): int {
    switch ($timingType) {
        case '1_day': return 1440;
        case '12_hours': return 720;
        case '6_hours': return 360;
        case '3_hours': return 180;
        case '1_hour': return 60;
        case '30_mins': return 30;
        case '15_mins': return 15;
        case '10_mins': return 10;
        case '5_mins': return 5;
        case '1_min': return 1;
        case 'custom': return max(1, $customMinutes);
        default: return 1440;
    }
}

/**
 * Add alert timing configuration to an auction session
 */
function addAuctionAlert(int $auctionId, string $timingType, int $customMinutes = 0, ?string $customLabel = null): int {
    $pdo = db();
    $minutes = getTimingOffsetMinutes($timingType, $customMinutes);
    $stmt = $pdo->prepare('
        INSERT INTO auction_alerts (auction_id, timing_type, trigger_minutes_before, custom_label, is_enabled, status)
        VALUES (?, ?, ?, ?, 1, "pending")
    ');
    $stmt->execute([$auctionId, $timingType, $minutes, $customLabel]);
    return (int)$pdo->lastInsertId();
}

/**
 * Dispatch an auction alert to all group members and log delivery status
 */
function dispatchAuctionAlert(int $alertId): array {
    $pdo = db();
    $stmt = $pdo->prepare('
        SELECT aa.*, a.starts_at, a.venue, a.description, g.name as group_name, g.id as group_id
        FROM auction_alerts aa
        JOIN auction_sessions a ON a.id = aa.auction_id
        JOIN groups g ON g.id = a.group_id
        WHERE aa.id = ?
    ');
    $stmt->execute([$alertId]);
    $alert = $stmt->fetch();

    if (!$alert) return ['success' => false, 'message' => 'Alert not found'];

    $groupName = $alert['group_name'];
    $startDate = date('d M Y', strtotime($alert['starts_at']));
    $startTime = date('h:i A', strtotime($alert['starts_at']));
    $timingType = $alert['timing_type'];

    if ($timingType === '1_day') {
        $msgText = "Dear Member, the auction for Group {$groupName} is scheduled on {$startDate} at {$startTime}.";
    } elseif ($timingType === '1_hour' || $alert['trigger_minutes_before'] == 60) {
        $msgText = "Reminder: Auction for {$groupName} starts in 1 hour.";
    } elseif ($timingType === '10_mins' || $alert['trigger_minutes_before'] == 10) {
        $msgText = "Urgent Reminder: Auction for {$groupName} starts in 10 minutes. Please join immediately.";
    } else {
        $mins = $alert['trigger_minutes_before'];
        $timeStr = $mins >= 60 ? round($mins / 60) . ' hour(s)' : "{$mins} minutes";
        $msgText = "Reminder: Auction for Group {$groupName} starts in {$timeStr} at {$startTime}.";
    }

    if (!empty($alert['venue'])) {
        $msgText .= " Venue/Link: " . $alert['venue'];
    }

    $stmt = $pdo->prepare('SELECT user_id FROM group_members WHERE group_id = ?');
    $stmt->execute([$alert['group_id']]);
    $members = $stmt->fetchAll();

    $sentCount = 0;
    foreach ($members as $m) {
        $uid = (int)$m['user_id'];
        
        createNotification($uid, 'auction_alert', "📢 Auction Reminder — {$groupName}", $msgText);
        $sentCount++;

        $pdo->prepare('INSERT INTO auction_alert_logs (alert_id, auction_id, user_id, channel, status, message_text) VALUES (?, ?, ?, "in_app", "delivered", ?)')
            ->execute([$alertId, $alert['auction_id'], $uid, $msgText]);

        $pdo->prepare('INSERT INTO auction_alert_logs (alert_id, auction_id, user_id, channel, status, message_text) VALUES (?, ?, ?, "sms", "sent", ?)')
            ->execute([$alertId, $alert['auction_id'], $uid, $msgText]);

        $pdo->prepare('INSERT INTO auction_alert_logs (alert_id, auction_id, user_id, channel, status, message_text) VALUES (?, ?, ?, "email", "sent", ?)')
            ->execute([$alertId, $alert['auction_id'], $uid, $msgText]);
    }

    $pdo->prepare('UPDATE auction_alerts SET status = "sent", sent_at = NOW() WHERE id = ?')
        ->execute([$alertId]);

    return ['success' => true, 'message' => "Alert dispatched to {$sentCount} members", 'sent_count' => $sentCount];
}

/**
 * Process all pending auction alerts that have reached their trigger time
 */
function processPendingAuctionAlerts(): int {
    $pdo = db();
    $pdo->exec("UPDATE auction_sessions SET state = 'live' WHERE state = 'scheduled' AND starts_at <= NOW()");

    $stmt = $pdo->query('
        SELECT aa.id
        FROM auction_alerts aa
        JOIN auction_sessions a ON a.id = aa.auction_id
        WHERE aa.status = "pending" AND aa.is_enabled = 1
        AND TIMESTAMPADD(MINUTE, -aa.trigger_minutes_before, a.starts_at) <= NOW()
    ');
    $alerts = $stmt->fetchAll();

    $dispatched = 0;
    foreach ($alerts as $al) {
        $res = dispatchAuctionAlert((int)$al['id']);
        if ($res['success']) $dispatched++;
    }
    return $dispatched;
}
