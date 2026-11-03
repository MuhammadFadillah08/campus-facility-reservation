<?php
/* ============================================================
   cleanup_pemohon.php — Hapus SELURUH data pengajuan pemohon
   ------------------------------------------------------------
   • Menghapus seluruh baris tabel  peminjaman  &  peminjaman_eksternal
   • Menghapus seluruh file surat di folder  uploads/surat/
   • Mengosongkan tabel  notifikasi
   • TIDAK menyentuh: ruangan, jadwal_kuliah, akun users (admin & mahasiswa)
   ------------------------------------------------------------
   Hanya bisa diakses oleh admin_utama. Butuh konfirmasi ketik "HAPUS".
   Skrip ini boleh dihapus setelah selesai digunakan.
   ============================================================ */
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('admin_utama');
$db   = db();
$page_title = 'Bersihkan Data Pemohon';

/* ── Hitung data saat ini ── */
function tblCount(mysqli $db, string $t): int {
    $res = $db->query("SELECT COUNT(*) c FROM `$t`");
    return $res ? (int)$res->fetch_assoc()['c'] : 0;
}
$cnt_peminjaman = tblCount($db, 'peminjaman');
$cnt_eksternal  = tblCount($db, 'peminjaman_eksternal');
$cnt_notif      = tblCount($db, 'notifikasi');

/* Hitung file surat fisik (selain file penjaga folder) */
$keepFiles = ['.gitkeep', '.htaccess', 'index.html', 'index.php'];
$suratFiles = [];
if (is_dir(UPLOAD_SURAT)) {
    foreach (scandir(UPLOAD_SURAT) as $f) {
        if ($f === '.' || $f === '..') continue;
        if (in_array(strtolower($f), $keepFiles, true)) continue;
        if (is_file(UPLOAD_SURAT . $f)) $suratFiles[] = $f;
    }
}
$cnt_surat = count($suratFiles);

/* ── Eksekusi penghapusan ── */
$done = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirm = trim($_POST['confirm'] ?? '');
    if ($confirm !== 'HAPUS') {
        flash('error', 'Konfirmasi salah. Ketik HAPUS (huruf kapital) untuk melanjutkan.');
        redirect(base_url('admin/cleanup_pemohon.php'));
    }

    $deletedFiles = 0; $failedFiles = [];

    /* 1) Hapus file surat fisik di folder */
    foreach ($suratFiles as $f) {
        $path = UPLOAD_SURAT . $f;
        if (@unlink($path)) $deletedFiles++;
        else $failedFiles[] = $f;
    }

    /* 2) Hapus seluruh baris tabel pengajuan + notifikasi */
    $db->query("DELETE FROM peminjaman");
    $db->query("DELETE FROM peminjaman_eksternal");
    $db->query("DELETE FROM notifikasi");

    /* 3) Reset AUTO_INCREMENT agar penomoran mulai dari 1 lagi */
    $db->query("ALTER TABLE peminjaman AUTO_INCREMENT = 1");
    $db->query("ALTER TABLE peminjaman_eksternal AUTO_INCREMENT = 1");
    $db->query("ALTER TABLE notifikasi AUTO_INCREMENT = 1");

    $msg = "Data pemohon berhasil dibersihkan — "
         . "$cnt_peminjaman pengajuan mahasiswa, "
         . "$cnt_eksternal pengajuan eksternal, "
         . "$cnt_notif notifikasi, dan "
         . "$deletedFiles file surat dihapus.";
    if ($failedFiles) $msg .= ' (Gagal hapus ' . count($failedFiles) . ' file: ' . e(implode(', ', $failedFiles)) . ')';

    flash('success', $msg);
    redirect(base_url('admin/cleanup_pemohon.php'));
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="section-title">🧹 Bersihkan Data Pemohon</div>
<div class="section-sub">Menghapus seluruh data pengajuan peminjaman beserta file suratnya. Data ruangan, jadwal kuliah, dan akun pengguna TIDAK terpengaruh.</div>

<?php if ($cnt_peminjaman + $cnt_eksternal + $cnt_notif + $cnt_surat === 0): ?>
  <div class="alert alert-info">✅ Tidak ada data pemohon yang tersisa. Semua sudah bersih.</div>
  <a href="<?= base_url('dashboard.php') ?>" class="btn btn-outline">← Kembali ke Dashboard</a>
<?php else: ?>

<div class="card mb-4">
  <div class="card-header"><span class="card-title">📋 Data yang akan dihapus</span></div>
  <div class="card-body">
    <div class="stats-grid" style="margin-bottom:0">
      <div class="stat-card"><div class="stat-icon" style="background:#dbeafe">📋</div>
        <div class="stat-info"><div class="value" style="color:#2563eb"><?= $cnt_peminjaman ?></div><div class="label">Pengajuan Mahasiswa</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#ede9fe">🏛️</div>
        <div class="stat-info"><div class="value" style="color:#7c3aed"><?= $cnt_eksternal ?></div><div class="label">Pengajuan Eksternal</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fef3c7">🔔</div>
        <div class="stat-info"><div class="value" style="color:#d97706"><?= $cnt_notif ?></div><div class="label">Notifikasi</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fee2e2">📎</div>
        <div class="stat-info"><div class="value" style="color:#dc2626"><?= $cnt_surat ?></div><div class="label">File Surat</div></div></div>
    </div>
  </div>
</div>

<div class="card" style="border:2px solid #fca5a5">
  <div class="card-header" style="background:#fee2e2"><span class="card-title" style="color:#991b1b">⚠️ Tindakan Permanen</span></div>
  <div class="card-body">
    <div class="alert alert-danger" style="margin-bottom:18px">
      <div>
        <strong>Peringatan:</strong> Tindakan ini <strong>tidak dapat dibatalkan</strong>. Seluruh data pengajuan
        (mahasiswa &amp; eksternal), notifikasi, dan file surat akan dihapus selamanya.
        <br>Yang <strong>aman / tetap ada</strong>: data ruangan &amp; gedung, jadwal kuliah, serta akun pengguna (admin &amp; mahasiswa).
      </div>
    </div>
    <form method="post" onsubmit="return confirm('Yakin menghapus SELURUH data pemohon? Tindakan ini tidak bisa dibatalkan.');">
      <div class="form-group">
        <label>Ketik <strong>HAPUS</strong> untuk mengonfirmasi</label>
        <input type="text" name="confirm" class="form-control" placeholder="HAPUS" autocomplete="off" required style="max-width:260px;font-weight:700;letter-spacing:.1em">
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button type="submit" class="btn btn-danger">🗑️ Hapus Seluruh Data Pemohon</button>
        <a href="<?= base_url('dashboard.php') ?>" class="btn btn-secondary">Batal</a>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
