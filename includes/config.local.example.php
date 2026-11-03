<?php
/**
 * Salin file ini menjadi config.local.php lalu isi dengan kredensial Anda.
 * config.local.php sudah masuk .gitignore — jangan pernah di-commit.
 */
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'campus_facility_reservation');
define('DB_PORT', 3306);

// Email OTP (Brevo) — opsional, hanya perlu untuk login mahasiswa
// define('BREVO_API_KEY',  'isi-api-key-brevo');
// define('SMTP_FROM',      'email-terverifikasi@domain-anda.com');
// define('SMTP_FROM_NAME', 'Campus Facility Reservation');

// Tautan Google Spreadsheet sinkronisasi ORMAWA — opsional.
// Bila tidak diisi, tombol "Check Sinkronisasi ORMAWA" disembunyikan.
// define('ORMAWA_SHEET_URL', 'https://docs.google.com/spreadsheets/d/ID-SPREADSHEET/edit');
