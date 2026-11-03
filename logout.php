<?php
require_once __DIR__ . '/includes/config.php';
clearRememberCookie();   // hapus token "ingat saya" agar tidak auto-login lagi
$_SESSION = [];
session_destroy();
redirect(base_url('login.php'));
