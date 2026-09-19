# CHIT FLOW - XAMPP Version

A complete HTML, CSS, PHP, and MySQL chit fund management application designed to run on XAMPP.

## Requirements

- [XAMPP](https://www.apachefriends.org/) (Apache + MySQL + PHP 8.0+)
- Web browser

## Installation

### Step 1: Copy to XAMPP

Copy the entire `xampp` folder to your XAMPP `htdocs` directory:

```
C:\xampp\htdocs\chitflow\
```

Or keep it in your project folder and access via:
```
http://localhost/chit-fund-live-main/xampp/
```

### Step 2: Start XAMPP Services

1. Open **XAMPP Control Panel**
2. Start **Apache**
3. Start **MySQL**

### Step 3: Create Database

**Option A — phpMyAdmin (recommended):**
1. Open http://localhost/phpmyadmin
2. Click **Import**
3. Choose `database/schema.sql`
4. Click **Go**

**Option B — Command line:**
```bash
cd C:\xampp\mysql\bin
mysql -u root < "D:\My Project Applications\chit-fund-live-main\xampp\database\schema.sql"
```

### Step 4: Configure (if needed)

Edit `includes/config.php` if your MySQL credentials differ:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'chitflow');
define('DB_USER', 'root');
define('DB_PASS', '');  // Default XAMPP has no password
define('APP_URL', 'http://localhost/chitflow');  // Update to match your path
```

### Step 5: Open the App

Visit: **http://localhost/chitflow/** (or your configured URL)

## Demo Accounts

| Role   | Email                | Password   |
|--------|----------------------|------------|
| Admin  | admin@chitflow.com   | password   |
| Leader | leader@chitflow.com  | password   |
| Member | member@chitflow.com  | password   |

> **Note:** All demo accounts use the password `password` for easy testing.

## Features

- **User Authentication** — Register, login, session management
- **Dashboard** — Wallet balance, live auctions, group overview
- **Groups** — Create groups (leaders), join by code, view members
- **Live Auctions** — Place bids, leader closes & settles payouts
- **Wallet** — Top-up (demo), withdraw, transaction ledger
- **Chit Calculator** — Simulate auctions, calculate dividends
- **Admin Panel** — Users, groups, auctions, KYC, withdrawals
- **KYC Review** — Admin approval workflow
- **Withdrawal Management** — Request and approve payouts

## Project Structure

```
xampp/
├── index.php              # Landing page
├── login.php              # Member login
├── signup.php             # Registration
├── calculator.php         # Public calculator
├── admin-login.php        # Admin/Leader portal login
├── logout.php
├── app/                   # Member area
│   ├── index.php          # Dashboard
│   ├── wallet.php
│   ├── calculator.php
│   ├── settings.php
│   ├── auction.php
│   └── groups/
│       ├── index.php
│       ├── new.php
│       └── view.php
├── admin/                 # Admin panel
│   ├── index.php
│   ├── users.php
│   ├── groups.php
│   ├── auctions.php
│   ├── kyc.php
│   └── withdrawals.php
├── includes/              # PHP core
│   ├── config.php
│   ├── db.php
│   ├── auth.php
│   ├── functions.php
│   ├── header.php
│   ├── footer.php
│   ├── app-nav.php
│   └── admin-nav.php
├── assets/
│   ├── css/style.css
│   └── js/calculator.js
├── database/
│   └── schema.sql
└── uploads/kyc/           # KYC document uploads
```

## Business Logic

### Auction Flow
1. Leader creates a group and starts an auction
2. Members place bids (highest discount wins)
3. Leader closes the auction
4. System automatically:
   - Pays winner (chit value − discount)
   - Pays leader commission (% of discount)
   - Distributes dividend to all members

### Join Group
Members can join open groups using the join code (e.g., `CHX-DEMO1`).

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Database connection error | Check MySQL is running; verify credentials in `config.php` |
| Page not found (404) | Ensure Apache is running; check folder path in `htdocs` |
| Blank page | Enable PHP errors in `config.php`; check Apache error log |
| CSS not loading | Update `APP_URL` in `config.php` to match your URL |

## Security Notes

This is a **demonstration** application. For production use:
- Change all default passwords
- Set a MySQL root password
- Disable `display_errors` in `config.php`
- Use HTTPS
- Add rate limiting and input validation
- Implement proper file upload security for KYC

## License

For demonstration purposes only.
