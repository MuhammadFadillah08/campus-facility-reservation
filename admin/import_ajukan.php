<?php
/**
 * Handler: Import pengajuan peminjaman massal dari file CSV.
 * Semua baris tercatat atas nama admin yang login (user_id) dengan status 'pending'.
 * Ruangan dicocokkan berdasarkan NAMA saja, dibatasi cakupan peran admin.
 * Mengembalikan JSON: { ok, inserted, skipped, errors[] }
 */
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('admin_utama', 'admin_departemen', 'admin_dmf');

header('Content-Type: application/json; charset=utf-8');

/* ── Validasi file upload ── */
if (empty($_FILES['file_import']) || $_FILES['file_import']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'msg'=>'Tidak ada file yang diupload atau terjadi error upload.']);
    exit;
}
$file = $_FILES['file_import'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($ext !== 'csv') {
    echo json_encode(['ok'=>false,'msg'=>'Format file harus .csv']);
    exit;
}
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['ok'=>false,'msg'=>'Ukuran file maks 5MB.']);
    exit;
}

/* ── Baca CSV ── */
$handle = fopen($file['tmp_name'], 'r');
if (!$handle) {
    echo json_encode(['ok'=>false,'msg'=>'Gagal membuka file CSV.']);
    exit;
}

// Deteksi & buang BOM UTF-8
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($handle);

// Auto-deteksi delimiter (koma vs titik koma)
$firstLine = fgets($handle);
$cntComma  = substr_count($firstLine, ',');
$cntSemi   = substr_count($firstLine, ';');
$delimiter = ($cntSemi > $cntComma) ? ';' : ',';
rewind($handle);
$bom2 = fread($handle, 3);
if ($bom2 !== "\xEF\xBB\xBF") rewind($handle);

$db        = db();
$uid       = (int)$user['id'];
$is_dmf    = ($user['role'] === 'admin_dmf');
$inserted  = 0;
$skipped   = 0;
$errors    = [];
$rowNum    = 0;
$notifySet = [];   // himpunan id admin validator yang perlu diberi notifikasi

/* Helper validasi format */
$isDate = fn($s) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false;
$isTime = fn($s) => (bool)preg_match('/^\d{1,2}:\d{2}$/', $s);

while (($row = fgetcsv($handle, 2000, $delimiter)) !== false) {
    $rowNum++;
    if ($rowNum <= 3) continue; // lewati 3 baris header

    $row = array_map('trim', $row);

    if ($is_dmf) {
        // Kolom CSV DMF (0-indexed, 9 kolom):
        // 0=Nama Kegiatan, 1=Asal Ormawa, 2=Nama Ruangan, 3=Tgl Mulai, 4=Tgl Selesai,
        // 5=Jam Mulai, 6=Jam Selesai, 7=Jumlah Peserta, 8=Keterangan
        while (count($row) < 9) $row[] = '';
        $nama_keg     = $row[0];
        $asal_org     = ($row[1] !== '') ? $row[1] : '-';
        $ruangan_nama = $row[2];
        $tgl_mulai    = $row[3];
        $tgl_selesai  = $row[4];
        $jam_mulai    = $row[5];
        $jam_selesai  = $row[6];
        $peserta      = (int)$row[7];
        $keterangan   = $row[8];
    } else {
        // Kolom CSV non-DMF (0-indexed, 8 kolom):
        // 0=Nama Kegiatan, 1=Nama Ruangan, 2=Tgl Mulai, 3=Tgl Selesai,
        // 4=Jam Mulai, 5=Jam Selesai, 6=Jumlah Peserta, 7=Keterangan
        while (count($row) < 8) $row[] = '';
        $nama_keg     = $row[0];
        $asal_org     = '-';
        $ruangan_nama = $row[1];
        $tgl_mulai    = $row[2];
        $tgl_selesai  = $row[3];
        $jam_mulai    = $row[4];
        $jam_selesai  = $row[5];
        $peserta      = (int)$row[6];
        $keterangan   = $row[7];
    }

    // Lewati baris kosong
    if ($nama_keg === '' && $ruangan_nama === '') { $skipped++; continue; }

    // Normalisasi jam → HH:MM (mis. "8:00" → "08:00")
    $jam_mulai   = $isTime($jam_mulai)   ? str_pad($jam_mulai, 5, '0', STR_PAD_LEFT)   : $jam_mulai;
    $jam_selesai = $isTime($jam_selesai) ? str_pad($jam_selesai, 5, '0', STR_PAD_LEFT) : $jam_selesai;

    /* ── Validasi ── */
    $rowErrors = [];
    if ($nama_keg === '')               $rowErrors[] = 'Nama Kegiatan kosong';
    if ($ruangan_nama === '')           $rowErrors[] = 'Nama Ruangan kosong';
    if (!$isDate($tgl_mulai))           $rowErrors[] = "Tanggal Mulai \"{$tgl_mulai}\" tidak valid (YYYY-MM-DD)";
    if (!$isDate($tgl_selesai))         $rowErrors[] = "Tanggal Selesai \"{$tgl_selesai}\" tidak valid (YYYY-MM-DD)";
    if ($isDate($tgl_mulai) && $isDate($tgl_selesai) && $tgl_selesai < $tgl_mulai)
        $rowErrors[] = 'Tanggal Selesai sebelum Tanggal Mulai';
    if (!$isTime($jam_mulai))           $rowErrors[] = "Jam Mulai \"{$row[4]}\" tidak valid (HH:MM)";
    if (!$isTime($jam_selesai))         $rowErrors[] = "Jam Selesai \"{$row[5]}\" tidak valid (HH:MM)";
    if ($isDate($tgl_mulai) && $isDate($tgl_selesai) && $isTime($jam_mulai) && $isTime($jam_selesai)
        && $tgl_mulai === $tgl_selesai && $jam_selesai <= $jam_mulai)
        $rowErrors[] = 'Jam Selesai harus lebih dari Jam Mulai pada hari yang sama';
    if ($peserta < 1)                   $rowErrors[] = 'Jumlah Peserta harus minimal 1';

    if (!empty($rowErrors)) {
        $errors[] = "Baris {$rowNum}: " . implode('; ', $rowErrors);
        $skipped++;
        continue;
    }

    /* ── Cari ruangan berdasarkan NAMA, dibatasi cakupan peran ── */
    if ($user['role'] === 'admin_departemen') {
        $rStmt = $db->prepare("SELECT id,departemen,tipe FROM ruangan WHERE nama=? AND departemen=? LIMIT 1");
        $rStmt->bind_param('ss', $ruangan_nama, $user['departemen']);
    } elseif ($user['role'] === 'admin_dmf') {
        $rStmt = $db->prepare("SELECT id,departemen,tipe FROM ruangan WHERE nama=? AND tipe='outdoor' LIMIT 1");
        $rStmt->bind_param('s', $ruangan_nama);
    } else { // admin_utama
        $rStmt = $db->prepare("SELECT id,departemen,tipe FROM ruangan WHERE nama=? LIMIT 1");
        $rStmt->bind_param('s', $ruangan_nama);
    }
    $rStmt->execute();
    $rRow = $rStmt->get_result()->fetch_assoc();

    if (!$rRow) {
        $errors[] = "Baris {$rowNum}: Ruangan \"{$ruangan_nama}\" tidak ditemukan dalam cakupan Anda";
        $skipped++;
        continue;
    }
    $ruangan_id   = (int)$rRow['id'];
    $ruangan_tipe = $rRow['tipe'];
    $ruangan_dept = $rRow['departemen'];

    /* ── Insert ke peminjaman ── */
    $file_surat = '';
    $s = $db->prepare(
        "INSERT INTO peminjaman
           (user_id,ruangan_id,nama_kegiatan,asal_organisasi,tanggal_mulai,tanggal_selesai,
            jam_mulai,jam_selesai,jumlah_peserta,keterangan,file_surat,status)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,'pending')"
    );
    $s->bind_param('iisssssssss',
        $uid, $ruangan_id, $nama_keg, $asal_org, $tgl_mulai, $tgl_selesai,
        $jam_mulai, $jam_selesai, $peserta, $keterangan, $file_surat);

    if ($s->execute()) {
        $inserted++;
        // Kumpulkan admin validator relevan (dedupe via set)
        if ($ruangan_tipe === 'outdoor') {
            $rs = $db->query("SELECT id FROM users WHERE role IN ('admin_utama','admin_dmf') AND id<>$uid");
        } else {
            $dept_e = $db->real_escape_string($ruangan_dept);
            $rs = $db->query("SELECT id FROM users WHERE (role='admin_utama' OR (role='admin_departemen' AND departemen='$dept_e')) AND id<>$uid");
        }
        if ($rs) foreach ($rs->fetch_all(MYSQLI_ASSOC) as $a) $notifySet[(int)$a['id']] = true;
    } else {
        $errors[] = "Baris {$rowNum}: Gagal simpan — " . $db->error;
        $skipped++;
    }
}
fclose($handle);

/* ── Notifikasi ringkas ke tiap admin validator ── */
if ($inserted > 0 && $notifySet) {
    $notif_link = base_url('admin/bookings.php');
    $pesan = "{$inserted} pengajuan peminjaman diimpor oleh {$user['nama']} (admin). Mohon divalidasi.";
    foreach (array_keys($notifySet) as $aid) {
        pushNotif($db, $aid, 'Pengajuan Peminjaman Massal', $pesan, 'info', $notif_link);
    }
}

echo json_encode([
    'ok'       => true,
    'inserted' => $inserted,
    'skipped'  => $skipped,
    'errors'   => $errors,
]);
