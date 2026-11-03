<?php
/**
 * Generate & download template Excel (.xlsx) untuk pengajuan peminjaman massal.
 *
 * PENTING: dibuat TANPA ZipArchive & TANPA file temporer (ZIP ditulis manual di
 * memori) agar tetap jalan di hosting gratis (InfinityFree) yang sering
 * menonaktifkan ZipArchive / memblokir sys_get_temp_dir() via open_basedir.
 *
 * Kolom mengikuti form ajukan.php. Ruangan dipilih lewat dropdown (daftar
 * ruangan nyata sesuai cakupan peran); pencocokan saat import berdasarkan NAMA.
 *
 * Dipakai bersama admin/import_ajukan.php
 */
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('admin_utama', 'admin_departemen', 'admin_dmf');
$db = db();

$ex_tgl = date('Y-m-d', strtotime('+7 days'));
$is_dmf = ($user['role'] === 'admin_dmf');

/* ── Daftar ruangan sesuai cakupan peran (untuk dropdown & contoh) ── */
$rWhere = ['tersedia=1'];
if ($user['role'] === 'admin_departemen')
    $rWhere[] = "departemen='{$db->real_escape_string($user['departemen'])}'";
if ($user['role'] === 'admin_dmf')
    $rWhere[] = "tipe='outdoor'";
$rooms = $db->query(
    "SELECT nama,kapasitas FROM ruangan WHERE " . implode(' AND ', $rWhere) . " ORDER BY nama"
)->fetch_all(MYSQLI_ASSOC);

/* ================================================================
   Helpers
================================================================ */
function colLetterA(int $n): string {
    $r = '';
    while ($n > 0) { $r = chr(65 + (($n - 1) % 26)) . $r; $n = intdiv($n - 1, 26); }
    return $r;
}

$ss_a = [];
$ssList_a = [];
function ssAddA(string $val): int {
    global $ss_a, $ssList_a;
    if (!isset($ss_a[$val])) { $ss_a[$val] = count($ssList_a); $ssList_a[] = $val; }
    return $ss_a[$val];
}

/** cell XML. $forceText: paksa simpan sebagai string (untuk tanggal/jam). */
function cellXmlA(string $ref, $val, int $style = 0, bool $forceText = false): string {
    if (!$forceText && is_numeric($val) && $val !== '') {
        return "<c r=\"{$ref}\" s=\"{$style}\" t=\"n\"><v>" . htmlspecialchars((string)$val, ENT_XML1) . "</v></c>";
    }
    $si = ssAddA((string)$val);
    return "<c r=\"{$ref}\" s=\"{$style}\" t=\"s\"><v>{$si}</v></c>";
}

/**
 * Tulis arsip ZIP (metode store / tanpa kompresi) secara manual.
 * @param array<string,string> $files  path => isi
 */
function zipStore(array $files): string {
    $local = '';
    $central = '';
    $offset = 0;
    foreach ($files as $name => $content) {
        $crc     = crc32($content);
        $len     = strlen($content);
        $nameLen = strlen($name);
        $lh = "PK\x03\x04"
            . pack('v', 20) . pack('v', 0) . pack('v', 0)   // ver, flag, method(store)
            . pack('v', 0)  . pack('v', 0)                  // mod time, mod date
            . pack('V', $crc) . pack('V', $len) . pack('V', $len)
            . pack('v', $nameLen) . pack('v', 0)
            . $name;
        $local .= $lh . $content;
        $central .= "PK\x01\x02"
            . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', 0)
            . pack('v', 0)  . pack('v', 0)
            . pack('V', $crc) . pack('V', $len) . pack('V', $len)
            . pack('v', $nameLen) . pack('v', 0) . pack('v', 0)
            . pack('v', 0)  . pack('v', 0) . pack('V', 0)
            . pack('V', $offset)
            . $name;
        $offset += strlen($lh) + $len;
    }
    $count = count($files);
    $eocd = "PK\x05\x06"
        . pack('v', 0) . pack('v', 0)
        . pack('v', $count) . pack('v', $count)
        . pack('V', strlen($central)) . pack('V', strlen($local))
        . pack('v', 0);
    return $local . $central . $eocd;
}

/* ================================================================
   Definisi kolom template (8 kolom — sesuai form ajukan.php)
================================================================ */
$headers = $is_dmf
    ? ['Nama Kegiatan *', 'Asal Ormawa', 'Nama Ruangan *',
       'Tanggal Mulai * (YYYY-MM-DD)', 'Tanggal Selesai * (YYYY-MM-DD)',
       'Jam Mulai * (HH:MM)', 'Jam Selesai * (HH:MM)',
       'Jumlah Peserta *', 'Keterangan']
    : ['Nama Kegiatan *', 'Nama Ruangan *',
       'Tanggal Mulai * (YYYY-MM-DD)', 'Tanggal Selesai * (YYYY-MM-DD)',
       'Jam Mulai * (HH:MM)', 'Jam Selesai * (HH:MM)',
       'Jumlah Peserta *', 'Keterangan'];

/* Contoh baris dibangun dari ruangan nyata yang ada → pasti valid saat import */
$exActs  = ['Rapat Koordinasi DMF', 'Workshop/Pelatihan UKM SENI', 'Kegiatan HMD Matematika'];
$exOrm   = ['DMF', 'UKM SENI', 'HMD MATEMATIKA'];
$exActsStd = ['Rapat Koordinasi Departemen', 'Workshop/Pelatihan', 'Seminar Nasional'];
$exTimes = [['08:00','10:00'], ['13:00','16:00'], ['09:00','11:30']];
$exKet   = ['Rapat rutin DMF', 'Pelatihan internal', 'Kegiatan akademik'];
$exKetStd= ['Rapat rutin', 'Pelatihan internal', 'Kegiatan akademik'];
$examples = [];
foreach (array_slice($rooms, 0, 3) as $i => $rm) {
    $kap = max(1, (int)$rm['kapasitas']);
    if ($is_dmf) {
        $examples[] = [
            $exActs[$i], $exOrm[$i], $rm['nama'],
            $ex_tgl, $ex_tgl,
            $exTimes[$i][0], $exTimes[$i][1],
            min(($i + 1) * 20, $kap),
            $exKet[$i],
        ];
    } else {
        $examples[] = [
            $exActsStd[$i], $rm['nama'],
            $ex_tgl, $ex_tgl,
            $exTimes[$i][0], $exTimes[$i][1],
            min(($i + 1) * 20, $kap),
            $exKetStd[$i],
        ];
    }
}
if (empty($examples)) {
    if ($is_dmf) {
        $examples[] = ['Rapat Koordinasi', 'DMF', '(isi nama ruangan)', $ex_tgl, $ex_tgl, '08:00', '10:00', 30, 'Contoh keterangan'];
    } else {
        $examples[] = ['Rapat Koordinasi', '(isi nama ruangan)', $ex_tgl, $ex_tgl, '08:00', '10:00', 30, 'Contoh keterangan'];
    }
}

$panduan_rows_base = [
    ['Nama Kegiatan *',                'Wajib. Nama kegiatan. Contoh: Rapat Koordinasi'],
    ['Nama Ruangan *',                 'Wajib. Pilih dari dropdown (daftar ruangan sesuai cakupan Anda).'],
    ['Tanggal Mulai * (YYYY-MM-DD)',   'Wajib. Format tahun-bulan-tanggal. Contoh: ' . $ex_tgl],
    ['Tanggal Selesai * (YYYY-MM-DD)', 'Wajib. Tidak boleh sebelum Tanggal Mulai.'],
    ['Jam Mulai * (HH:MM)',            'Wajib. Format jam:menit. Contoh: 08:00'],
    ['Jam Selesai * (HH:MM)',          'Wajib. Harus lebih dari Jam Mulai bila tanggal sama. Contoh: 10:00'],
    ['Jumlah Peserta *',               'Wajib. Angka. Contoh: 30'],
    ['Keterangan',                     'Opsional. Deskripsi/kebutuhan khusus.'],
];
if ($is_dmf) {
    array_splice($panduan_rows_base, 1, 0, [
        ['Asal Ormawa', 'Opsional. Nama ormawa pengaju. Isi tanda (-) jika bukan dari organisasi atau tidak terdaftar. Contoh: UKM SENI, HMD MATEMATIKA, DMF'],
    ]);
}
$panduan = array_merge(
    [['Kolom', 'Keterangan']],
    $panduan_rows_base,
    [
        ['', ''],
        ['ℹ️ Catatan',  'Semua pengajuan tercatat atas nama Anda (admin) dengan status "Menunggu" untuk divalidasi.'],
        ['💾 Cara simpan', 'Setelah diisi: File → Simpan Sebagai → CSV (koma/titik koma), lalu upload di menu Import CSV.'],
    ]
);

$numCols = count($headers); // 8 atau 9 (DMF)
$nRooms  = count($rooms);

/* ================================================================
   XML statis
================================================================ */
$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>';

$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

$wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>
<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>';

$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets>
<sheet name="Template Peminjaman" sheetId="1" r:id="rId1"/>
<sheet name="Panduan" sheetId="2" r:id="rId2"/>
<sheet name="Daftar" sheetId="3" r:id="rId3"/>
</sheets>
</workbook>';

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="5">
  <font><sz val="11"/><name val="Calibri"/></font>
  <font><b/><sz val="13"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
  <font><i/><sz val="10"/><color rgb="FF78350F"/><name val="Calibri"/></font>
  <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
  <font><sz val="11"/><color rgb="FF1E293B"/><name val="Calibri"/></font>
</fonts>
<fills count="7">
  <fill><patternFill patternType="none"/></fill>
  <fill><patternFill patternType="gray125"/></fill>
  <fill><patternFill patternType="solid"><fgColor rgb="FF14532D"/></patternFill></fill>
  <fill><patternFill patternType="solid"><fgColor rgb="FFFEF3C7"/></patternFill></fill>
  <fill><patternFill patternType="solid"><fgColor rgb="FF166534"/></patternFill></fill>
  <fill><patternFill patternType="solid"><fgColor rgb="FFF0FDF4"/></patternFill></fill>
  <fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/></patternFill></fill>
</fills>
<borders count="3">
  <border><left/><right/><top/><bottom/><diagonal/></border>
  <border>
    <left style="thin"><color rgb="FF14532D"/></left>
    <right style="thin"><color rgb="FF14532D"/></right>
    <top style="thin"><color rgb="FF14532D"/></top>
    <bottom style="thin"><color rgb="FF14532D"/></bottom>
  </border>
  <border>
    <left style="thin"><color rgb="FFBBF7D0"/></left>
    <right style="thin"><color rgb="FFBBF7D0"/></right>
    <top style="thin"><color rgb="FFBBF7D0"/></top>
    <bottom style="thin"><color rgb="FFBBF7D0"/></bottom>
  </border>
</borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="6">
  <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
  <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
    <alignment horizontal="center" vertical="center"/></xf>
  <xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">
    <alignment horizontal="center" vertical="center" wrapText="1"/></xf>
  <xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
    <alignment horizontal="center" vertical="center" wrapText="1"/></xf>
  <xf numFmtId="0" fontId="4" fillId="5" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
  <xf numFmtId="0" fontId="4" fillId="6" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
</cellXfs>
</styleSheet>';

/* ================================================================
   Sheet 1 — Template Peminjaman
================================================================ */
$judul = 'TEMPLATE PENGAJUAN PEMINJAMAN MASSAL — Campus Facility Reservation';
ssAddA($judul);
$row1 = '<row r="1" ht="30" customHeight="1">' . cellXmlA('A1', $judul, 1) . '</row>';

$subtitle = 'Isi data mulai baris ke-4. Pilih ruangan dari dropdown kolom Nama Ruangan. '
          . 'Tanggal YYYY-MM-DD, jam HH:MM. Simpan sebagai CSV sebelum upload.';
ssAddA($subtitle);
$row2 = '<row r="2" ht="24" customHeight="1">' . cellXmlA('A2', $subtitle, 2) . '</row>';

$row3Cells = '';
foreach ($headers as $i => $h) { ssAddA($h); $row3Cells .= cellXmlA(colLetterA($i + 1) . '3', $h, 3); }
$row3 = '<row r="3" ht="30" customHeight="1">' . $row3Cells . '</row>';

// Kolom tanggal & jam dipaksa teks agar tidak diubah Excel
// DMF: kolom 4,5 (tanggal) & 6,7 (jam); non-DMF: kolom 3,4 (tanggal) & 5,6 (jam)
$textCols = $is_dmf ? [4, 5, 6, 7] : [3, 4, 5, 6];
$dataRows = '';
foreach ($examples as $ri => $ex) {
    $r    = $ri + 4;
    $sIdx = ($ri % 2 === 0) ? 4 : 5;
    $cells = '';
    foreach ($ex as $ci => $val) {
        $force = in_array($ci + 1, $textCols, true);
        $cells .= cellXmlA(colLetterA($ci + 1) . $r, $val, $sIdx, $force);
    }
    $dataRows .= "<row r=\"{$r}\">{$cells}</row>";
}

$colWidths = $is_dmf
    ? '<cols>
  <col min="1" max="1" width="28" customWidth="1"/>
  <col min="2" max="2" width="22" customWidth="1"/>
  <col min="3" max="3" width="30" customWidth="1"/>
  <col min="4" max="4" width="26" customWidth="1"/>
  <col min="5" max="5" width="26" customWidth="1"/>
  <col min="6" max="6" width="16" customWidth="1"/>
  <col min="7" max="7" width="16" customWidth="1"/>
  <col min="8" max="8" width="14" customWidth="1"/>
  <col min="9" max="9" width="32" customWidth="1"/>
</cols>'
    : '<cols>
  <col min="1" max="1" width="28" customWidth="1"/>
  <col min="2" max="2" width="30" customWidth="1"/>
  <col min="3" max="3" width="26" customWidth="1"/>
  <col min="4" max="4" width="26" customWidth="1"/>
  <col min="5" max="5" width="16" customWidth="1"/>
  <col min="6" max="6" width="16" customWidth="1"/>
  <col min="7" max="7" width="14" customWidth="1"/>
  <col min="8" max="8" width="32" customWidth="1"/>
</cols>';

$lastCol = colLetterA($numCols); // H
$merges  = "<mergeCells count=\"2\"><mergeCell ref=\"A1:{$lastCol}1\"/><mergeCell ref=\"A2:{$lastCol}2\"/></mergeCells>";

/* Data validation: Nama Ruangan → referensi range di sheet "Daftar"
   DMF: kolom C (Nama Ruangan ada di kolom ke-3), non-DMF: kolom B */
$validations = '';
if ($nRooms > 0) {
    $roomRef  = 'Daftar!$A$2:$A$' . ($nRooms + 1);
    $roomCol  = $is_dmf ? 'C' : 'B'; // DMF punya kolom Asal Ormawa di kolom B
    $validations = '<dataValidations count="1">'
        . '<dataValidation type="list" sqref="' . $roomCol . '4:' . $roomCol . '500" showDropDown="0" showErrorMessage="1" errorTitle="Input Tidak Valid" error="Pilih ruangan dari daftar.">'
        . '<formula1>' . htmlspecialchars($roomRef, ENT_XML1) . '</formula1></dataValidation>'
        . '</dataValidations>';
}

$sheet1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
' . $colWidths . '
<sheetData>' . $row1 . $row2 . $row3 . $dataRows . '</sheetData>
' . $merges . '
' . $validations . '
</worksheet>';

/* ================================================================
   Sheet 2 — Panduan
================================================================ */
$panduanRows = '';
foreach ($panduan as $ri => $row) {
    $r    = $ri + 1;
    $sIdx = ($ri === 0) ? 3 : (($ri % 2 === 0) ? 5 : 4);
    $cells  = cellXmlA("A{$r}", $row[0], $sIdx);
    $cells .= cellXmlA("B{$r}", $row[1], $sIdx);
    $panduanRows .= "<row r=\"{$r}\" ht=\"26\" customHeight=\"1\">{$cells}</row>";
}
$sheet2 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<cols><col min="1" max="1" width="34" customWidth="1"/><col min="2" max="2" width="78" customWidth="1"/></cols>
<sheetData>' . $panduanRows . '</sheetData>
</worksheet>';

/* ================================================================
   Sheet 3 — Daftar (sumber dropdown ruangan)
================================================================ */
ssAddA('Nama Ruangan');
$daftarRows = '<row r="1">' . cellXmlA('A1', 'Nama Ruangan', 3) . '</row>';
foreach ($rooms as $i => $rm) {
    $r = $i + 2;
    $daftarRows .= "<row r=\"{$r}\">" . cellXmlA("A{$r}", $rm['nama'], 0, true) . "</row>";
}
$sheet3 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<cols><col min="1" max="1" width="34" customWidth="1"/></cols>
<sheetData>' . $daftarRows . '</sheetData>
</worksheet>';

/* ================================================================
   Shared Strings XML
================================================================ */
$ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($ssList_a) . '" uniqueCount="' . count($ssList_a) . '">';
foreach ($ssList_a as $str) {
    $ssXml .= '<si><t xml:space="preserve">' . htmlspecialchars($str, ENT_XML1) . '</t></si>';
}
$ssXml .= '</sst>';

/* ================================================================
   Susun & kirim .xlsx (ZIP store manual)
================================================================ */
$xlsx = zipStore([
    '[Content_Types].xml'        => $contentTypes,
    '_rels/.rels'                => $rootRels,
    'xl/workbook.xml'            => $workbook,
    'xl/_rels/workbook.xml.rels' => $wbRels,
    'xl/styles.xml'              => $styles,
    'xl/sharedStrings.xml'       => $ssXml,
    'xl/worksheets/sheet1.xml'   => $sheet1,
    'xl/worksheets/sheet2.xml'   => $sheet2,
    'xl/worksheets/sheet3.xml'   => $sheet3,
]);

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="template_pengajuan_peminjaman_cfr.xlsx"');
header('Content-Length: ' . strlen($xlsx));
header('Cache-Control: max-age=0');
echo $xlsx;
exit;
