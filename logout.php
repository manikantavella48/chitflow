<?php
require_once __DIR__ . '/includes/auth.php';
logoutUser();
redirect(APP_URL . '/login.php');
