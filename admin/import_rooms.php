<?php
/**
 * Handler: Import ruangan massal dari file CSV
 * Alur: isi template xlsx → simpan/ekspor sebagai .csv di Excel → upload di sini
 * Mengembalikan JSON: { ok, inserted, skipped, errors[] }
 */
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('admin_utama','admin_departemen','admin_dmf');

header('Content-Type: application/json; charset=utf-8');

/* ── Konstanta valid ── */
$VALID_DEPTS = ['Matematika','Fisika','Kimia','Biologi','Pendidikan IPA','Sains Terapan','Area Outdoor Fakultas Sains','Admin Fakultas Sains'];
$VALID_TYPES = ['indoor','outdoor'];

/* ── Validasi file upload ── */
if (empty($_FILES['file_import']) || $_FILES['file_import']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'msg'=>'Tidak ada file yang diupload atau terjadi error upload.']);
    exit;
}
$file = $_FILES['file_import'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($ext !== 'csv') {
    echo json_encode(['ok'=>false,'msg'=>'Format file harus .csv — buka template xlsx di Excel lalu simpan sebagai CSV (Comma Delimited).']);
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

// Deteksi dan buang BOM (Byte Order Mark) UTF-8 jika ada
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($handle);

// ── Auto-deteksi delimiter (koma vs titik koma) ──────────────
// Excel Indonesia/Eropa sering menyimpan CSV dengan `;` bukan `,`
$firstLine = fgets($handle);
$cntComma  = substr_count($firstLine, ',');
$cntSemi   = substr_count($firstLine, ';');
$delimiter = ($cntSemi > $cntComma) ? ';' : ',';
// Kembali ke posisi awal (setelah BOM yang sudah dibaca)
rewind($handle);
$bom2 = fread($handle, 3);
if ($bom2 !== "\xEF\xBB\xBF") rewind($handle);

$db       = db();
$inserted = 0;
$skipped  = 0;
$errors   = [];
$rowNum   = 0;
$namaImported = []; // lacak nama yang sudah diinsert di batch ini

while (($row = fgetcsv($handle, 2000, $delimiter)) !== false) {
    $rowNum++;

    // Skip 3 baris header (judul, subtitle, header kolom)
    if ($rowNum <= 3) continue;

    // Normalise: trim semua nilai
    $row = array_map('trim', $row);

    // Pastikan array punya minimal 5 kolom
    while (count($row) < 8) $row[] = '';

    $nama    = $row[0];
    $gedung  = $row[1];
    $dept    = $row[2];
    $tipe    = strtolower($row[3]);
    $kapRaw  = trim($row[4]);
    $kapDash = ($kapRaw === '-' || $kapRaw === '');
    $kap     = $kapDash ? 0 : (int)$kapRaw;
    $fas     = $row[5];
    $desk    = $row[6];
    $tersStr = strtolower($row[7]);

    // Skip baris kosong
    if ($nama === '' && $gedung === '' && $dept === '') { $skipped++; continue; }

    /* ── Validasi ── */
    $rowErrors = [];
    if ($nama   === '') $rowErrors[] = 'Nama Ruangan kosong';
    if ($gedung === '') $rowErrors[] = 'Gedung/Lokasi kosong';
    if (!in_array($dept, $VALID_DEPTS)) $rowErrors[] = "Departemen \"{$dept}\" tidak valid";
    if (!in_array($tipe, $VALID_TYPES)) $rowErrors[] = "Tipe \"{$tipe}\" tidak valid (harus indoor/outdoor)";
    if (!$kapDash && !is_numeric($kapRaw))  $rowErrors[] = "Kapasitas harus berupa angka atau '-'";
    elseif (!$kapDash && $kap < 1)          $rowErrors[] = 'Kapasitas harus >= 1';

    /* ── Override berdasarkan role ── */
    if ($user['role'] === 'admin_departemen') {
        $dept = $user['departemen'];
        $tipe = 'indoor';
        $rowErrors = array_values(array_filter($rowErrors,
            fn($e) => !str_contains($e, 'Departemen') && !str_contains($e, 'Tipe')));
    }
    if ($user['role'] === 'admin_dmf') {
        $dept = 'Area Outdoor Fakultas Sains';
        $tipe = 'outdoor';
        $rowErrors = array_values(array_filter($rowErrors,
            fn($e) => !str_contains($e, 'Departemen') && !str_contains($e, 'Tipe')));
    }

    if (!empty($rowErrors)) {
        $errors[] = "Baris {$rowNum}: " . implode('; ', $rowErrors);
        $skipped++;
        continue;
    }

    $tersedia = ($tersStr === 'tidak' || $tersStr === '0' || $tersStr === 'false') ? 0 : 1;
    $foto     = '';

    // Cek duplikat: nama + departemen sudah ada di DB
    $cek = $db->prepare("SELECT id FROM ruangan WHERE nama=? AND departemen=? LIMIT 1");
    $cek->bind_param('ss', $nama, $dept); $cek->execute();
    if ($cek->get_result()->num_rows > 0) {
        $errors[] = "Baris {$rowNum}: Gagal — Ruangan \"{$nama}\" sudah ada di departemen/admin yang sama (duplikat)";
        $skipped++;
        continue;
    }

    // Cek duplikat: kombinasi nama+departemen muncul lebih dari sekali dalam CSV ini
    $keyBatch = $nama . '||' . $dept;
    if (in_array($keyBatch, $namaImported)) {
        $errors[] = "Baris {$rowNum}: Gagal — Ruangan \"{$nama}\" duplikat dalam file CSV ini (departemen sama)";
        $skipped++;
        continue;
    }

    $s = $db->prepare(
        "INSERT INTO ruangan (nama,gedung,departemen,tipe,kapasitas,fasilitas,deskripsi,foto,tersedia)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $s->bind_param('ssssisssi', $nama, $gedung, $dept, $tipe, $kap, $fas, $desk, $foto, $tersedia);

    if ($s->execute()) {
        $namaImported[] = $keyBatch;
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
