<?php
/**
 * Generate & download template Excel untuk import jadwal kuliah massal
 * Pure PHP + ZipArchive — tanpa library eksternal (kompatibel PHP 7.4+)
 */
ob_start();
require_once __DIR__ . '/../includes/config.php';
requireRole('admin_utama','admin_departemen','admin_dmf');

/* ── Parameter semester (dari GET atau session) ── */
$p_sem     = $_GET['semester']    ?? ($_SESSION['sched_semester_aktif']['semester']     ?? 'Ganjil');
$p_ta      = $_GET['tahun']       ?? ($_SESSION['sched_semester_aktif']['tahun_ajaran'] ?? '');
$p_tgl_mul = $_GET['tgl_mulai']   ?? ($_SESSION['sched_semester_aktif']['tgl_mulai']    ?? '2025-09-01');
$p_tgl_sel = $_GET['tgl_selesai'] ?? ($_SESSION['sched_semester_aktif']['tgl_selesai']  ?? '2026-01-31');
if (!$p_ta) $p_ta = date('Y').'/'.((int)date('Y')+1);

/* ================================================================
   Helper: konversi angka kolom → huruf (1→A, 28→AB, dst.)
================================================================ */
function colLetterS(int $n): string {
    $r = '';
    while ($n > 0) {
        $r = chr(65 + (($n - 1) % 26)) . $r;
        $n = intdiv($n - 1, 26);
    }
    return $r;
}

/* ================================================================
   Shared Strings
================================================================ */
$ss_s    = [];
$ssList_s= [];

function ssAddS(string $val): int {
    global $ss_s, $ssList_s;
    if (!isset($ss_s[$val])) {
        $ss_s[$val]  = count($ssList_s);
        $ssList_s[]  = $val;
    }
    return $ss_s[$val];
}

/* ================================================================
   Definisi template
================================================================ */
$headers = [
    'Mata Kuliah *', 'Kode MK', 'Dosen', 'Prodi', 'Offering',
    'Nama Ruangan *', 'Hari *', 'Mode Waktu *',
    'Jam Mulai * (Jam ke-)', 'Jam Selesai * (Jam ke-)',
];
$examples = [
    ['Kalkulus I',   'MAT-101', 'Dr. Budi Santoso', 'S1 Matematika', 'A', 'Ruang 301',        'Senin', 'Reguler', 'Jam ke-1', 'Jam ke-2'],
    ['Fisika Dasar', 'FIS-101', 'Dr. Siti Rahayu',  'S1 Fisika',     'B', 'Ruang 201 Fisika', 'Rabu',  'Reguler', 'Jam ke-5', 'Jam ke-6'],
    ['Kimia Organik','KIM-201', 'Dr. Ahmad Fauzi',  'S1 Kimia',      'A', 'Lab Kimia Dasar',  'Kamis', 'Reguler', 'Jam ke-7', 'Jam ke-8'],
];
$panduan = [
    ['Kolom',                    'Keterangan'],
    ['Mata Kuliah *',            'Wajib. Nama mata kuliah. Contoh: Kalkulus I'],
    ['Kode MK',                  'Opsional. Kode mata kuliah. Contoh: MAT-101'],
    ['Dosen',                    'Opsional. Nama dosen pengampu. Contoh: Dr. Budi Santoso'],
    ['Prodi',                    'Opsional. Program studi. Contoh: S1 Matematika'],
    ['Offering',                 'Opsional. Nama/kode offering. Contoh: A, B, Reguler'],
    ['Nama Ruangan *',           'Wajib. Nama ruangan sesuai data sistem. Departemen otomatis mengikuti ruangan. Contoh: Ruang 301, Lab Kimia Dasar'],
    ['Hari *',                   'Wajib. Pilih: Senin / Selasa / Rabu / Kamis / Jumat / Sabtu / Minggu'],
    ['Mode Waktu *',             'Wajib. Pilih: Reguler / Puasa (Ramadhan). Menentukan tabel jam yang dipakai.'],
    ['Jam Mulai * (Jam ke-)',    'Wajib. Pilih Jam ke-1 s/d Jam ke-12 (jam kuliah dimulai).'],
    ['Jam Selesai * (Jam ke-)',  'Wajib. Pilih Jam ke-1 s/d Jam ke-12 (jam kuliah berakhir, ≥ Jam Mulai).'],
    ['',                         ''],
    ['🕐 Jam REGULER',           '1: 07:00–07:50 · 2: 07:50–08:40 · 3: 08:45–09:35 · 4: 09:35–10:25 · 5: 10:30–11:20 · 6: 11:20–12:10 · 7: 13:10–14:00 · 8: 14:00–14:50 · 9: 14:55–15:45 · 10: 15:45–16:35 · 11: 18:15–19:05 · 12: 19:05–19:55'],
    ['🌙 Jam PUASA (Ramadhan)',  '1: 07:30–08:05 · 2: 08:05–08:40 · 3: 08:45–09:20 · 4: 09:20–09:55 · 5: 10:00–10:35 · 6: 10:35–11:10 · 7: 11:10–11:45 · 8: 13:00–13:35 · 9: 13:35–14:10 · 10: 14:10–14:45 · 11: 15:15–15:45 · 12: 15:45–16:15'],
    ['',                         ''],
    ['ℹ️ Semester',              'Semester, Tahun Ajaran, dan periode tanggal diambil OTOMATIS dari "Semester Aktif" yang dipilih di halaman Jadwal Kuliah — tidak perlu diisi di file ini.'],
    ['⚠️ Penting',               'Nama Ruangan harus SAMA PERSIS dengan nama ruangan di sistem Campus Facility Reservation.'],
];

$numCols = count($headers); // 10

/* ================================================================
   Builder ZIP murni-PHP (metode store, tanpa ekstensi zip) — agar
   berjalan di hosting apa pun termasuk InfinityFree yang tak punya
   ZipArchive. Sama seperti template_ajukan.php.
================================================================ */
if (!function_exists('zipStoreTpl')) {
    function zipStoreTpl(array $files): string {
        $local = ''; $central = ''; $offset = 0;
        foreach ($files as $name => $content) {
            $crc = crc32($content); $len = strlen($content); $nameLen = strlen($name);
            $lh = "PK\x03\x04".pack('v',20).pack('v',0).pack('v',0).pack('v',0).pack('v',0)
                . pack('V',$crc).pack('V',$len).pack('V',$len).pack('v',$nameLen).pack('v',0).$name;
            $local .= $lh . $content;
            $central .= "PK\x01\x02".pack('v',20).pack('v',20).pack('v',0).pack('v',0).pack('v',0).pack('v',0)
                . pack('V',$crc).pack('V',$len).pack('V',$len).pack('v',$nameLen).pack('v',0).pack('v',0)
                . pack('v',0).pack('v',0).pack('V',0).pack('V',$offset).$name;
            $offset += strlen($lh) + $len;
        }
        $count = count($files);
        $eocd = "PK\x05\x06".pack('v',0).pack('v',0).pack('v',$count).pack('v',$count)
              . pack('V',strlen($central)).pack('V',strlen($local)).pack('v',0);
        return $local . $central . $eocd;
    }
}
$fnBase = 'template_jadwal_'.strtolower($p_sem).'_'.str_replace('/', '-', $p_ta).'_cfr';

/* ================================================================
   XML: [Content_Types].xml
================================================================ */
$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>';

/* ================================================================
   XML: _rels/.rels
================================================================ */
$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

/* ================================================================
   XML: xl/_rels/workbook.xml.rels
================================================================ */
$wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>';

/* ================================================================
   XML: xl/workbook.xml
================================================================ */
$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets>
<sheet name="Template Jadwal" sheetId="1" r:id="rId1"/>
<sheet name="Panduan" sheetId="2" r:id="rId2"/>
</sheets>
</workbook>';

/* ================================================================
   XML: xl/styles.xml  (same palette as template_rooms.php)
================================================================ */
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
   cellXml helper
================================================================ */
function cellXmlS(string $ref, $val, int $style = 0): string {
    if (is_numeric($val) && $val !== '') {
        return "<c r=\"{$ref}\" s=\"{$style}\" t=\"n\"><v>" . htmlspecialchars((string)$val, ENT_XML1) . "</v></c>";
    }
    $si = ssAddS((string)$val);
    return "<c r=\"{$ref}\" s=\"{$style}\" t=\"s\"><v>{$si}</v></c>";
}

/* ================================================================
   Sheet 1 — Template Jadwal
================================================================ */
// Baris 1: judul
$judul = 'TEMPLATE IMPORT JADWAL KULIAH — ' . strtoupper($p_sem) . ' ' . $p_ta . ' — Campus Facility Reservation';
ssAddS($judul);
$row1 = '<row r="1" ht="30" customHeight="1">'
      . cellXmlS('A1', $judul, 1)
      . '</row>';

// Baris 2: subtitle
$subtitle = 'Semester ' . $p_sem . ' ' . $p_ta
          . ' · Periode: ' . $p_tgl_mul . ' s/d ' . $p_tgl_sel
          . ' · Isi data mulai baris ke-4. Semester & tanggal otomatis dari Semester Aktif. Nama Ruangan harus sama persis dengan nama di sistem. Simpan sebagai CSV sebelum upload.';
ssAddS($subtitle);
$row2 = '<row r="2" ht="24" customHeight="1">'
      . cellXmlS('A2', $subtitle, 2)
      . '</row>';

// Baris 3: header kolom
$row3Cells = '';
foreach ($headers as $i => $h) {
    ssAddS($h);
    $row3Cells .= cellXmlS(colLetterS($i+1).'3', $h, 3);
}
$row3 = '<row r="3" ht="28" customHeight="1">'.$row3Cells.'</row>';

// Baris 4–6: contoh
$dataRows = '';
foreach ($examples as $ri => $ex) {
    $r    = $ri + 4;
    $sIdx = ($ri % 2 === 0) ? 4 : 5;
    $cells = '';
    foreach ($ex as $ci => $val) {
        $cells .= cellXmlS(colLetterS($ci+1).$r, $val, $sIdx);
    }
    $dataRows .= "<row r=\"{$r}\">{$cells}</row>";
}

// Lebar kolom (14 kolom)
$colWidths = '<cols>
  <col min="1"  max="1"  width="24" customWidth="1"/>
  <col min="2"  max="2"  width="14" customWidth="1"/>
  <col min="3"  max="3"  width="22" customWidth="1"/>
  <col min="4"  max="4"  width="18" customWidth="1"/>
  <col min="5"  max="5"  width="12" customWidth="1"/>
  <col min="6"  max="6"  width="24" customWidth="1"/>
  <col min="7"  max="7"  width="12" customWidth="1"/>
  <col min="8"  max="8"  width="20" customWidth="1"/>
  <col min="9"  max="9"  width="18" customWidth="1"/>
  <col min="10" max="10" width="18" customWidth="1"/>
</cols>';

// Merge judul dan subtitle
$lastCol = colLetterS($numCols); // N
$merges  = "<mergeCells count=\"2\">
  <mergeCell ref=\"A1:{$lastCol}1\"/>
  <mergeCell ref=\"A2:{$lastCol}2\"/>
</mergeCells>";

// Data validation (dropdown): Hari (G), Mode Waktu (H), Jam Mulai (I), Jam Selesai (J).
// Departemen & Semester tidak diisi manual (otomatis dari ruangan & semester aktif).
$hariList  = '"Senin,Selasa,Rabu,Kamis,Jumat,Sabtu,Minggu"';
$modeList  = '"Reguler,Puasa (Ramadhan)"';
$jamKe = [];
for ($i=1; $i<=12; $i++) $jamKe[] = 'Jam ke-'.$i;
$jamKeList = '"'.implode(',', $jamKe).'"';
$validations = '<dataValidations count="4">
  <dataValidation type="list" sqref="G4:G200" showDropDown="0" showErrorMessage="1" errorTitle="Input Tidak Valid" error="Pilih hari yang valid.">
    <formula1>'.htmlspecialchars($hariList,ENT_XML1).'</formula1>
  </dataValidation>
  <dataValidation type="list" sqref="H4:H200" showDropDown="0" showErrorMessage="1" errorTitle="Input Tidak Valid" error="Pilih Reguler atau Puasa (Ramadhan).">
    <formula1>'.htmlspecialchars($modeList,ENT_XML1).'</formula1>
  </dataValidation>
  <dataValidation type="list" sqref="I4:I200" showDropDown="0" showErrorMessage="1" errorTitle="Input Tidak Valid" error="Pilih Jam ke-1 s/d Jam ke-12.">
    <formula1>'.htmlspecialchars($jamKeList,ENT_XML1).'</formula1>
  </dataValidation>
  <dataValidation type="list" sqref="J4:J200" showDropDown="0" showErrorMessage="1" errorTitle="Input Tidak Valid" error="Pilih Jam ke-1 s/d Jam ke-12.">
    <formula1>'.htmlspecialchars($jamKeList,ENT_XML1).'</formula1>
  </dataValidation>
</dataValidations>';

$sheet1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
'.$colWidths.'
<sheetData>'.$row1.$row2.$row3.$dataRows.'</sheetData>
'.$merges.'
'.$validations.'
</worksheet>';

/* ================================================================
   Sheet 2 — Panduan
================================================================ */
$panduanRows = '';
foreach ($panduan as $ri => $row) {
    $r    = $ri + 1;
    $sIdx = ($ri === 0) ? 3 : (($ri % 2 === 0) ? 5 : 4);
    $cells  = cellXmlS("A{$r}", $row[0], $sIdx);
    $cells .= cellXmlS("B{$r}", $row[1], $sIdx);
    $panduanRows .= "<row r=\"{$r}\" ht=\"26\" customHeight=\"1\">{$cells}</row>";
}
$sheet2 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<cols>
  <col min="1" max="1" width="36" customWidth="1"/>
  <col min="2" max="2" width="72" customWidth="1"/>
</cols>
<sheetData>'.$panduanRows.'</sheetData>
</worksheet>';

/* ================================================================
   Shared Strings XML
================================================================ */
$ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($ssList_s).'" uniqueCount="'.count($ssList_s).'">';
foreach ($ssList_s as $str) {
    $ssXml .= '<si><t xml:space="preserve">'.htmlspecialchars($str,ENT_XML1).'</t></si>';
}
$ssXml .= '</sst>';

/* ================================================================
   Buat ZIP (xlsx)
================================================================ */
$xlsx = zipStoreTpl([
    '[Content_Types].xml'        => $contentTypes,
    '_rels/.rels'                => $rootRels,
    'xl/workbook.xml'            => $workbook,
    'xl/_rels/workbook.xml.rels' => $wbRels,
    'xl/styles.xml'              => $styles,
    'xl/sharedStrings.xml'       => $ssXml,
    'xl/worksheets/sheet1.xml'   => $sheet1,
    'xl/worksheets/sheet2.xml'   => $sheet2,
]);

/* ================================================================
   Kirim file ke browser
================================================================ */
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fnBase . '.xlsx"');
header('Content-Length: ' . strlen($xlsx));
header('Cache-Control: max-age=0');
echo $xlsx;
exit;
