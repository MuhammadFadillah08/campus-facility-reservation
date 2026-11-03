<?php
require_once __DIR__ . '/../includes/config.php';
requireLogin();
header('Content-Type: application/json');

$ruangan_id  = (int)($_GET['ruangan_id'] ?? 0);
$tgl_mulai   = $_GET['tanggal_mulai']  ?? '';
$tgl_selesai = $_GET['tanggal_selesai'] ?? '';
$jam_mulai   = $_GET['jam_mulai']   ?? '';
$jam_selesai = $_GET['jam_selesai'] ?? '';

if (!$tgl_selesai) $tgl_selesai = $tgl_mulai;

if (!$ruangan_id || !$tgl_mulai || !$jam_mulai || !$jam_selesai) {
    echo json_encode(['available' => null]);
    exit;
}

$db = db();
$conflicts = [];

/* ── 1. Peminjaman mahasiswa ── */
$s1 = $db->prepare(
    "SELECT p.nama_kegiatan, p.tanggal_mulai, p.tanggal_selesai,
            p.jam_mulai, p.jam_selesai, p.status, 'Peminjaman Mahasiswa' AS sumber
     FROM peminjaman p
     WHERE p.ruangan_id = ?
       AND p.status IN ('pending','disetujui')
       AND CONCAT(p.tanggal_mulai,' ',p.jam_mulai)   < CONCAT(?,' ',?)
       AND CONCAT(p.tanggal_selesai,' ',p.jam_selesai) > CONCAT(?,' ',?)
     ORDER BY p.tanggal_mulai, p.jam_mulai"
);
$s1->bind_param('issss', $ruangan_id, $tgl_selesai, $jam_selesai, $tgl_mulai, $jam_mulai);
$s1->execute();
$conflicts = array_merge($conflicts, $s1->get_result()->fetch_all(MYSQLI_ASSOC));

/* ── 2. Peminjaman eksternal (orang luar) ──
   Status menunggu/disetujui dianggap memakai ruangan; yang ditolak tidak
   dihitung. Parameter exclude_ext dipakai saat MENGEDIT data eksternal agar
   tidak terdeteksi bentrok dengan dirinya sendiri. */
$exclude_ext = (int)($_GET['exclude_ext'] ?? 0);
$s2 = $db->prepare(
    "SELECT pe.nama_kegiatan, pe.tanggal_mulai, pe.tanggal_selesai,
            pe.jam_mulai, pe.jam_selesai, pe.status, 'Peminjaman Eksternal' AS sumber
     FROM peminjaman_eksternal pe
     WHERE pe.ruangan_id = ?
       AND pe.status IN ('pending','disetujui')
       AND pe.id <> ?
       AND CONCAT(pe.tanggal_mulai,' ',pe.jam_mulai)   < CONCAT(?,' ',?)
       AND CONCAT(pe.tanggal_selesai,' ',pe.jam_selesai) > CONCAT(?,' ',?)
     ORDER BY pe.tanggal_mulai, pe.jam_mulai"
);
$s2->bind_param('iissss', $ruangan_id, $exclude_ext, $tgl_selesai, $jam_selesai, $tgl_mulai, $jam_mulai);
$s2->execute();
$conflicts = array_merge($conflicts, $s2->get_result()->fetch_all(MYSQLI_ASSOC));

/* ── 3. Jadwal kuliah reguler ── */
// Cek apakah hari tanggal_mulai (weekday 1=Senin…7=Minggu) ada di jadwal kuliah
// dan semesternya mencakup tanggal_mulai, serta waktu overlap.
// DAYOFWEEK MySQL: 1=Minggu, 2=Senin, …, 7=Sabtu
// Konversi ke sistem kita (1=Senin…7=Minggu):
//   CASE DAYOFWEEK(d) WHEN 1 THEN 7 ELSE DAYOFWEEK(d)-1 END
$s3 = $db->prepare(
    "SELECT jk.nama_matkul AS nama_kegiatan,
            jk.tgl_mulai_semester AS tanggal_mulai,
            jk.tgl_selesai_semester AS tanggal_selesai,
            jk.jam_mulai, jk.jam_selesai,
            'aktif' AS status, 'Jadwal Kuliah' AS sumber
     FROM jadwal_kuliah jk
     WHERE jk.ruangan_id = ?
       AND jk.tgl_mulai_semester  <= ?
       AND jk.tgl_selesai_semester >= ?
       AND jk.hari = CASE DAYOFWEEK(?) WHEN 1 THEN 7 ELSE DAYOFWEEK(?)-1 END
       AND jk.jam_mulai   < ?
       AND jk.jam_selesai > ?
     ORDER BY jk.hari, jk.jam_mulai"
);
// 7 params: ruangan_id(i), tgl_mulai(s), tgl_mulai(s), tgl_mulai(s), tgl_mulai(s), jam_selesai(s), jam_mulai(s)
$s3->bind_param('issssss',
    $ruangan_id,
    $tgl_mulai,   // tgl_mulai_semester <= tgl_mulai
    $tgl_mulai,   // tgl_selesai_semester >= tgl_mulai
    $tgl_mulai,   // DAYOFWEEK(?) — first occurrence
    $tgl_mulai,   // DAYOFWEEK(?) — second occurrence in CASE
    $jam_selesai, // jam_mulai < jam_selesai_booking
    $jam_mulai    // jam_selesai > jam_mulai_booking
);
$s3->execute();
$conflicts = array_merge($conflicts, $s3->get_result()->fetch_all(MYSQLI_ASSOC));

echo json_encode(['available' => empty($conflicts), 'conflicts' => $conflicts]);
