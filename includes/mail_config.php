<?php
// ============================================================
//  KONFIGURASI EMAIL — Campus Facility Reservation
//  Menggunakan Brevo Transactional Email API (HTTPS)
//  Daftar gratis di https://brevo.com — 300 email/hari
//
//  Jangan menulis API key di file ini. Definisikan di
//  includes/config.local.php (diabaikan git). Lihat config.local.example.php.
// ============================================================

// Tanpa API key, login OTP mahasiswa gagal dengan pesan wajar.
// Admin tetap bisa masuk memakai email + password.
if (!defined('BREVO_API_KEY'))  define('BREVO_API_KEY',  '');

// Email pengirim HARUS sudah diverifikasi di Brevo:
//   Brevo Dashboard → Settings → Senders & IP → Add a Sender
if (!defined('SMTP_FROM'))      define('SMTP_FROM',      'noreply@example.com');
if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', 'Campus Facility Reservation');
