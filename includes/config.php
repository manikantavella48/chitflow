<?php
/**
 * CHIT FLOW - Application Configuration
 * Copy this file and adjust settings for your XAMPP environment.
 */

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'chitflow');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'CHIT FLOW');
define('APP_URL', getenv('APP_URL') ?: 'http://localhost/chitflow');
define('APP_ROOT', dirname(__DIR__));

define('SESSION_NAME', 'chitflow_session');
define('UPLOAD_DIR', APP_ROOT . '/uploads/kyc/');
define('UPLOAD_URL', APP_URL . '/uploads/kyc/');

// Timezone
date_default_timezone_set('Asia/Kolkata');

// Error reporting
if (getenv('APP_ENV') === 'production') {
    error_reporting(0);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}
