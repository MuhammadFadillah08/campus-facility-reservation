<?php
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('user');
$db   = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$id  = (int)($_POST['id'] ?? 0);
$uid = (int)$user['id'];

if (!$id) {
    $_SESSION['flash_error'] = 'ID peminjaman tidak valid.';
    redirect(base_url('user/my-bookings.php'));
}

// Ambil data peminjaman milik user ini
$stmt = $db->prepare("SELECT p.*, r.nama AS ruangan_nama, r.departemen AS ruangan_departemen, r.tipe AS ruangan_tipe FROM peminjaman p JOIN ruangan r ON p.ruangan_id = r.id WHERE p.id = ? AND p.user_id = ?");
$stmt->bind_param('ii', $id, $uid);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    $_SESSION['flash_error'] = 'Peminjaman tidak ditemukan.';
    redirect(base_url('user/my-bookings.php'));
}

if ($booking['status'] !== 'pending') {
    $_SESSION['flash_error'] = 'Hanya pengajuan dengan status "Menunggu Validasi" yang dapat dibatalkan.';
    redirect(base_url('user/my-bookings.php'));
}

// Update status jadi dibatalkan
$upd = $db->prepare("UPDATE peminjaman SET status = 'dibatalkan', alasan_penolakan = ? WHERE id = ? AND user_id = ? AND status = 'pending'");
$alasan = 'Dibatalkan oleh pemohon';
$upd->bind_param('sii', $alasan, $id, $uid);
$upd->execute();

if ($upd->affected_rows === 0) {
    $_SESSION['flash_error'] = 'Gagal membatalkan pengajuan. Mungkin statusnya sudah berubah.';
    redirect(base_url('user/my-bookings.php'));
}

// Notif ke semua admin yang berwenang
$admin_query = "SELECT id FROM users WHERE role IN ('admin_utama'";
$params      = [];
$types       = '';

if ($booking['ruangan_departemen']) {
    $admin_query .= ", 'admin_departemen'";
    // filter departemen dilakukan di loop bawah
}
if ($booking['ruangan_tipe'] === 'outdoor') {
    $admin_query .= ", 'admin_dmf'";
}
$admin_query .= ')';

$admins = $db->query($admin_query)->fetch_all(MYSQLI_ASSOC);

foreach ($admins as $admin) {
    // admin_departemen hanya perlu notif jika departemen sesuai — cek lewat query terpisah
    $chk = $db->query("SELECT role, departemen FROM users WHERE id={$admin['id']} LIMIT 1")->fetch_assoc();
    if ($chk['role'] === 'admin_departemen' && $chk['departemen'] !== $booking['ruangan_departemen']) {
        continue;
    }
    pushNotif(
        $db,
        (int)$admin['id'],
        '🚫 Peminjaman Dibatalkan',
        'Pengajuan "' . $booking['nama_kegiatan'] . '" untuk ' . $booking['ruangan_nama'] . ' telah dibatalkan oleh pemohon.',
        'warning',
        base_url('admin/bookings.php')
    );
}

$_SESSION['flash_success'] = 'Pengajuan "' . $booking['nama_kegiatan'] . '" berhasil dibatalkan.';
redirect(base_url('user/my-bookings.php'));
