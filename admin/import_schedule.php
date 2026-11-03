<?php
/**
 * Handler: Import jadwal kuliah massal dari file CSV
 * Alur: isi template xlsx → simpan/ekspor sebagai .csv di Excel → upload di sini
 * Mengembalikan JSON: { ok, inserted, skipped, errors[] }
 */
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('admin_utama','admin_departemen','admin_dmf');

header('Content-Type: application/json; charset=utf-8');

/* ── Konstanta valid ── */
$HARI_MAP = [
    'senin'=>1,'selasa'=>2,'rabu'=>3,'kamis'=>4,
    'jumat'=>5,'sabtu'=>6,'minggu'=>7,
];
/* Tabel jam kuliah — HARUS sama dengan class_schedule.php (form). */
$JAM_REGULER = [
    1=>['07:00','07:50'], 2=>['07:50','08:40'], 3=>['08:45','09:35'],
    4=>['09:35','10:25'], 5=>['10:30','11:20'], 6=>['11:20','12:10'],
    7=>['13:10','14:00'], 8=>['14:00','14:50'], 9=>['14:55','15:45'],
    10=>['15:45','16:35'],11=>['18:15','19:05'],12=>['19:05','19:55'],
];
$JAM_PUASA = [
    1=>['07:30','08:05'], 2=>['08:05','08:40'], 3=>['08:45','09:20'],
    4=>['09:20','09:55'], 5=>['10:00','10:35'], 6=>['10:35','11:10'],
    7=>['11:10','11:45'], 8=>['13:00','13:35'], 9=>['13:35','14:10'],
    10=>['14:10','14:45'],11=>['15:15','15:45'],12=>['15:45','16:15'],
];

/* ── Semester, tahun ajaran, & periode diambil dari SEMESTER AKTIF (sesuai form) ── */
$sa = $_SESSION['sched_semester_aktif'] ?? null;
if (!$sa || empty($sa['tgl_mulai']) || empty($sa['tgl_selesai'])) {
    echo json_encode(['ok'=>false,'msg'=>'Belum ada Semester Aktif. Buka halaman Jadwal Kuliah dan konfirmasi semester dulu sebelum import.']);
    exit;
}
$semStr       = $sa['semester'];
$tahun_ajaran = $sa['tahun_ajaran'];
$tgl_mulai    = $sa['tgl_mulai'];
$tgl_selesai  = $sa['tgl_selesai'];

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

// Deteksi dan buang BOM UTF-8
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($handle);

// ── Auto-deteksi delimiter (koma vs titik koma) ──────────────
$firstLine = fgets($handle);
$cntComma  = substr_count($firstLine, ',');
$cntSemi   = substr_count($firstLine, ';');
$delimiter = ($cntSemi > $cntComma) ? ';' : ',';
rewind($handle);
$bom2 = fread($handle, 3);
if ($bom2 !== "\xEF\xBB\xBF") rewind($handle);

$db       = db();
$inserted = 0;
$skipped  = 0;
$errors   = [];
$rowNum   = 0;

while (($row = fgetcsv($handle, 2000, $delimiter)) !== false) {
    $rowNum++;
    if ($rowNum <= 3) continue; // skip 3 baris header

    $row = array_map('trim', $row);
    while (count($row) < 10) $row[] = '';

    // Kolom CSV (0-indexed) — SESUAI form Tambah Jadwal:
    // 0=Mata Kuliah, 1=Kode MK, 2=Dosen, 3=Prodi, 4=Offering(kelas),
    // 5=Nama Ruangan, 6=Hari, 7=Mode Waktu, 8=Jam Mulai(ke), 9=Jam Selesai(ke)
    // (Departemen otomatis dari ruangan; Semester/Tanggal dari Semester Aktif)
    $nama_matkul  = $row[0];
    $kode_matkul  = $row[1];
    $dosen        = $row[2];
    $prodi        = $row[3];
    $kelas        = $row[4];
    $ruangan_nama = $row[5];
    $hariStr      = strtolower($row[6]);
    $modeStr      = strtolower($row[7]);
    // Jam Mulai/Selesai berupa "Jam ke-N" → ambil angkanya
    preg_match('/\d+/', $row[8], $mM); $keMulai   = (int)($mM[0] ?? 0);
    preg_match('/\d+/', $row[9], $mS); $keSelesai = (int)($mS[0] ?? 0);

    // Skip baris kosong
    if ($nama_matkul === '' && $ruangan_nama === '') { $skipped++; continue; }

    /* ── Departemen ditentukan oleh role / ruangan (bukan dari CSV) ── */
    $dept = '';
    if ($user['role'] === 'admin_departemen') $dept = $user['departemen'];
    elseif ($user['role'] === 'admin_dmf')    $dept = 'Area Outdoor Fakultas Sains';

    /* ── Mode waktu → tabel jam ── */
    $mode = (strpos($modeStr, 'puasa') !== false) ? 'puasa' : 'reguler';
    $tbl  = ($mode === 'puasa') ? $JAM_PUASA : $JAM_REGULER;

    /* ── Validasi ── */
    $rowErrors = [];
    if ($nama_matkul === '')  $rowErrors[] = 'Mata Kuliah kosong';
    if ($ruangan_nama === '') $rowErrors[] = 'Nama Ruangan kosong';
    $hari = $HARI_MAP[$hariStr] ?? 0;
    if (!$hari)                              $rowErrors[] = "Hari \"{$row[6]}\" tidak valid (Senin–Minggu)";
    if ($keMulai < 1 || $keMulai > 12)       $rowErrors[] = "Jam Mulai \"{$row[8]}\" tidak valid (Jam ke-1 s/d 12)";
    if ($keSelesai < 1 || $keSelesai > 12)   $rowErrors[] = "Jam Selesai \"{$row[9]}\" tidak valid (Jam ke-1 s/d 12)";
    if ($keMulai && $keSelesai && $keSelesai < $keMulai) $rowErrors[] = 'Jam Selesai lebih awal dari Jam Mulai';

    // Konversi Jam ke → HH:MM (hanya bila valid)
    $jam_mulai = $jam_selesai = '';
    if (empty($rowErrors)) {
        $jam_mulai   = $tbl[$keMulai][0];
        $jam_selesai = $tbl[$keSelesai][1];
    }

    if (!empty($rowErrors)) {
        $errors[] = "Baris {$rowNum}: " . implode('; ', $rowErrors);
        $skipped++;
        continue;
    }

    /* ── Cari ruangan_id; departemen otomatis mengikuti ruangan ── */
    if ($dept !== '') {
        $rStmt = $db->prepare("SELECT id FROM ruangan WHERE nama=? AND departemen=?");
        $rStmt->bind_param('ss', $ruangan_nama, $dept);
        $rStmt->execute();
        $rRow = $rStmt->get_result()->fetch_assoc();
        if (!$rRow) {
            $errors[] = "Baris {$rowNum}: Ruangan \"{$ruangan_nama}\" tidak ditemukan pada cakupan Anda ({$dept})";
            $skipped++;
            continue;
        }
    } else {
        // admin_utama: cari berdasarkan nama, departemen diambil dari ruangan
        $rStmt = $db->prepare("SELECT id,departemen FROM ruangan WHERE nama=?");
        $rStmt->bind_param('s', $ruangan_nama);
        $rStmt->execute();
        $rRow = $rStmt->get_result()->fetch_assoc();
        if (!$rRow) {
            $errors[] = "Baris {$rowNum}: Ruangan \"{$ruangan_nama}\" tidak ditemukan di sistem";
            $skipped++;
            continue;
        }
        $dept = $rRow['departemen'];
    }
    $ruangan_id = (int)$rRow['id'];

    /* ── Insert ── */
    $s = $db->prepare(
        "INSERT INTO jadwal_kuliah
         (ruangan_id,nama_matkul,kode_matkul,dosen,prodi,kelas,departemen,
          hari,jam_mulai,jam_selesai,tgl_mulai_semester,tgl_selesai_semester,
          tahun_ajaran,semester,dibuat_oleh)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $s->bind_param('issssssissssssi',
        $ruangan_id,$nama_matkul,$kode_matkul,$dosen,$prodi,$kelas,$dept,
        $hari,$jam_mulai,$jam_selesai,$tgl_mulai,$tgl_selesai,
        $tahun_ajaran,$semStr,$user['id']);

    if ($s->execute()) {
        $inserted++;
    } else {
        $errors[] = "Baris {$rowNum}: Gagal simpan — " . $db->error;
        $skipped++;
    }
}
fclose($handle);

echo json_encode([
    'ok'       => true,
    'inserted' => $inserted,
    'skipped'  => $skipped,
    'errors'   => $errors,
]);
