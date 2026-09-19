-- CHIT FLOW - MySQL Database Schema for XAMPP
-- Import this file via phpMyAdmin or: mysql -u root chitflow < schema.sql

CREATE DATABASE IF NOT EXISTS chitflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE chitflow;

-- Users table (replaces Supabase auth.users)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100) NOT NULL DEFAULT '',
    phone VARCHAR(20) DEFAULT NULL,
    avatar_url VARCHAR(500) DEFAULT NULL,
    is_frozen TINYINT(1) NOT NULL DEFAULT 0,
    lead_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- User roles
CREATE TABLE user_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role ENUM('member','leader','admin') NOT NULL DEFAULT 'member',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_role (user_id, role),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Wallets
CREATE TABLE wallets (
    user_id INT PRIMARY KEY,
    available_paise BIGINT NOT NULL DEFAULT 0,
    locked_paise BIGINT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CHECK (available_paise >= 0),
    CHECK (locked_paise >= 0),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Wallet transactions (immutable ledger)
CREATE TABLE wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount_paise BIGINT NOT NULL,
    kind VARCHAR(50) NOT NULL,
    reference_id INT DEFAULT NULL,
    note TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_wallet_tx_user (user_id, created_at DESC)
) ENGINE=InnoDB;

-- Chit fund groups
CREATE TABLE groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    leader_id INT NOT NULL,
    name VARCHAR(200) NOT NULL,
    chit_value_paise BIGINT NOT NULL,
    monthly_contribution_paise BIGINT NOT NULL,
    total_members INT NOT NULL,
    duration_months INT NOT NULL,
    commission_pct DECIMAL(5,2) NOT NULL DEFAULT 5.00,
    join_code VARCHAR(20) NOT NULL UNIQUE,
    status ENUM('open','active','closed','frozen') NOT NULL DEFAULT 'open',
    visibility_type ENUM('public','approval_required','private_invite_only') NOT NULL DEFAULT 'public',
    bid_type VARCHAR(50) NOT NULL DEFAULT 'lowest_bid_wins',
    minimum_bid_paise BIGINT NOT NULL DEFAULT 0,
    maximum_bid_paise BIGINT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (chit_value_paise > 0),
    CHECK (monthly_contribution_paise > 0),
    CHECK (total_members BETWEEN 2 AND 50),
    CHECK (duration_months BETWEEN 2 AND 60),
    FOREIGN KEY (leader_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Group members
CREATE TABLE group_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_group_user (group_id, user_id),
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_gm_group (group_id),
    INDEX idx_gm_user (user_id)
) ENGINE=InnoDB;

-- Group join requests
CREATE TABLE group_join_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
    message TEXT DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Auction sessions
CREATE TABLE auction_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    cycle_number INT NOT NULL,
    starts_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ends_at DATETIME NOT NULL,
    state ENUM('pending','scheduled','live','paused','completed','cancelled') NOT NULL DEFAULT 'pending',
    venue VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    winner_user_id INT DEFAULT NULL,
    winning_discount_paise BIGINT DEFAULT NULL,
    spin_status ENUM('none','spinning','completed') NOT NULL DEFAULT 'none',
    spin_duration_seconds INT NOT NULL DEFAULT 8,
    spin_started_at DATETIME DEFAULT NULL,
    spin_winner_user_id INT DEFAULT NULL,
    spin_target_angle INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_group_cycle (group_id, cycle_number),
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
    FOREIGN KEY (winner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_auction_group (group_id, created_at DESC)
) ENGINE=InnoDB;

-- Auction alerts & reminders
CREATE TABLE auction_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    auction_id INT NOT NULL,
    timing_type ENUM('1_day','12_hours','6_hours','3_hours','1_hour','30_mins','15_mins','10_mins','5_mins','1_min','custom') NOT NULL DEFAULT '1_day',
    trigger_minutes_before INT NOT NULL,
    custom_label VARCHAR(100) DEFAULT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    sent_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (auction_id) REFERENCES auction_sessions(id) ON DELETE CASCADE,
    INDEX idx_alert_auction (auction_id, status)
) ENGINE=InnoDB;

-- Auction alert delivery logs
CREATE TABLE auction_alert_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_id INT NOT NULL,
    auction_id INT NOT NULL,
    user_id INT NOT NULL,
    channel ENUM('in_app','sms','email','whatsapp') NOT NULL DEFAULT 'in_app',
    status ENUM('sent','delivered','failed') NOT NULL DEFAULT 'sent',
    message_text TEXT NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (alert_id) REFERENCES auction_alerts(id) ON DELETE CASCADE,
    FOREIGN KEY (auction_id) REFERENCES auction_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Bids
CREATE TABLE bids (
    id INT AUTO_INCREMENT PRIMARY KEY,
    auction_id INT NOT NULL,
    user_id INT NOT NULL,
    discount_paise BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (discount_paise > 0),
    FOREIGN KEY (auction_id) REFERENCES auction_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_bids_auction (auction_id, discount_paise DESC, created_at)
) ENGINE=InnoDB;

-- KYC documents
CREATE TABLE kyc_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    kind ENUM('pan','aadhaar','selfie') NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    rejection_reason TEXT DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Withdrawal requests
CREATE TABLE withdrawal_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount_paise BIGINT NOT NULL,
    status ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
    bank_details TEXT DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Notifications
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    read_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notif_user (user_id, created_at DESC)
) ENGINE=InnoDB;

-- Admin audit logs
CREATE TABLE admin_audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50) DEFAULT NULL,
    target_id INT DEFAULT NULL,
    details TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Lead applications
CREATE TABLE lead_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    business_name VARCHAR(200) NOT NULL,
    experience TEXT DEFAULT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    reason TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed demo users (password for all: "password")
-- Hash: password_verify('password', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi') === true
INSERT INTO users (email, password_hash, display_name) VALUES
('admin@chitflow.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin User');

INSERT INTO user_roles (user_id, role) VALUES (1, 'admin'), (1, 'leader'), (1, 'member');
INSERT INTO wallets (user_id, available_paise) VALUES (1, 10000000);

INSERT INTO users (email, password_hash, display_name, phone) VALUES
('leader@chitflow.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Demo Leader', '9876543210');

INSERT INTO user_roles (user_id, role) VALUES (2, 'leader'), (2, 'member');
INSERT INTO wallets (user_id, available_paise) VALUES (2, 5000000);

INSERT INTO users (email, password_hash, display_name, phone) VALUES
('member@chitflow.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Demo Member', '9876543211');

INSERT INTO user_roles (user_id, role) VALUES (3, 'member');
INSERT INTO wallets (user_id, available_paise) VALUES (3, 2500000);

-- Demo group
INSERT INTO groups (leader_id, name, chit_value_paise, monthly_contribution_paise, total_members, duration_months, commission_pct, join_code, status) VALUES
(2, 'Gold Savings Chit', 10000000, 500000, 20, 20, 5.00, 'CHX-DEMO1', 'open');

INSERT INTO group_members (group_id, user_id) VALUES (1, 2), (1, 3);
