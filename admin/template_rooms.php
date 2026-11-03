<?php
/**
 * Generate & download template Excel untuk import ruangan massal
 * v2 — Gedung dari tabel gedung (dropdown dinamis), Departemen diperbarui
 * Pure PHP + ZipArchive — tanpa library eksternal (kompatibel PHP 7.4+)
 */
ob_start();
require_once __DIR__ . '/../includes/config.php';
requireRole('admin_utama','admin_departemen','admin_dmf');

$db   = db();
$user = requireLogin();

/* ================================================================
   Ambil data gedung dari DB
================================================================ */
$gedungRows = $db->query("SELECT nama, tipe FROM gedung ORDER BY tipe, nama");
$gedungList = $gedungRows ? $gedungRows->fetch_all(MYSQLI_ASSOC) : [];

// Fallback jika tabel kosong
if (empty($gedungList)) {
    $gedungList = [
        ['nama'=>'Gedung B18','tipe'=>'indoor'],
        ['nama'=>'Gedung B19','tipe'=>'indoor'],
        ['nama'=>'Gedung B20','tipe'=>'indoor'],
        ['nama'=>'Gedung B22','tipe'=>'indoor'],
        ['nama'=>'Gedung B24','tipe'=>'indoor'],
        ['nama'=>'Gedung B25','tipe'=>'indoor'],
        ['nama'=>'Area Outdoor Fakultas Sains','tipe'=>'outdoor'],
    ];
}

$gedungNames = array_column($gedungList, 'nama');

/* ================================================================
   Helper: konversi angka kolom → huruf (1→A, 28→AB, dst.)
================================================================ */
function colLetter(int $n): string {
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
$ss     = [];
$ssList = [];
function ssAdd(string $val): int {
    global $ss, $ssList;
    if (!isset($ss[$val])) {
        $ss[$val] = count($ssList);
        $ssList[] = $val;
    }
    return $ss[$val];
}

/* ================================================================
   Definisi header, contoh, panduan
================================================================ */
$headers = [
    'Nama Ruangan *', 'Gedung / Lokasi *', 'Departemen/Admin *', 'Tipe *',
    'Kapasitas (orang) *', 'Fasilitas', 'Deskripsi', 'Tersedia (Ya/Tidak)',
];

// Ambil contoh gedung pertama indoor & outdoor
$cthIndoor  = 'Gedung B20';
$cthOutdoor = 'Area Outdoor Fakultas Sains';
foreach ($gedungList as $g) {
    if ($g['tipe']==='indoor'  && $cthIndoor  === 'Gedung B20')  $cthIndoor  = $g['nama'];
    if ($g['tipe']==='outdoor' && $cthOutdoor === 'Area Outdoor Fakultas Sains') $cthOutdoor = $g['nama'];
}

$deptList    = ['Matematika','Fisika','Kimia','Biologi','Pendidikan IPA','Sains Terapan','Admin Fakultas Sains','Area Outdoor Fakultas Sains'];
$deptInline  = '"'.implode(',', $deptList).'"';
$tipeInline  = '"indoor,outdoor"';
$tersediaInline = '"Ya,Tidak"';

$examples = [
    ['Ruang 401',       $cthIndoor,  'Matematika',        'indoor',  40, 'Proyektor,AC,Whiteboard',       'Ruang kuliah lantai 4',   'Ya'],
    ['Lab Kimia Dasar', $cthIndoor,  'Kimia',             'indoor',  30, 'Peralatan Lab,AC,Lemari Asam',  'Lab kimia dasar',         'Ya'],
    ['Loket Fakultas Sains',     $cthOutdoor, 'Admin Fakultas Sains',       'outdoor', 10, 'Meja,Kursi',                    'Loket layanan Fakultas Sains',     'Ya'],
    ['Kanopi Utara',    $cthOutdoor, 'Area Outdoor Fakultas Sains','outdoor', 80, 'Kanopi,Bangku,Sound Speaker',   'Area outdoor sisi utara', 'Ya'],
];

$panduan = [
    ['Kolom',                  'Keterangan'],
    ['Nama Ruangan *',         'Wajib. Nama unik ruangan. Contoh: Ruang 401, Lab Biologi Lanjut'],
    ['Gedung / Lokasi *',      'Wajib. PILIH dari dropdown — daftar gedung sesuai data di Kelola Gedung/Outdoor'],
    ['Departemen/Admin *',     'Wajib. Pilih: Matematika / Fisika / Kimia / Biologi / Pendidikan IPA / Sains Terapan / Admin Fakultas Sains / Area Outdoor Fakultas Sains'],
    ['Tipe *',                 'Pilih: indoor (dalam gedung) atau outdoor (area terbuka)'],
    ['Kapasitas *',            'Wajib. Jumlah orang maksimal (angka bulat, minimal 1)'],
    ['Fasilitas',              'Opsional. Dipisah koma. Contoh: Proyektor,AC,Whiteboard'],
    ['Deskripsi',              'Opsional. Keterangan singkat ruangan'],
    ['Tersedia',               'Ya = aktif/bisa dipinjam | Tidak = nonaktif. Default: Ya'],
    ['Foto',                   '⚠ Tidak ada di template. Tambahkan lewat fitur Edit setelah import.'],
    ['',''],
    ['Catatan Penting',        'Daftar gedung pada kolom Gedung/Lokasi diambil langsung dari data Kelola Gedung/Outdoor. Jika gedung yang ingin Anda gunakan belum ada, tambahkan dulu di menu Kelola Ruangan → Kelola Gedung/Outdoor.'],
];

/* ── Builder ZIP murni-PHP (tanpa ekstensi zip) — jalan di hosting apa pun ── */
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
$fnBase = 'template_import_ruangan_cfr';

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
<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
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
<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>
<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>';

/* ================================================================
   XML: xl/workbook.xml  (sheet3 = Ref_Gedung, disembunyikan)
================================================================ */
$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets>
<sheet name="Template Import" sheetId="1" r:id="rId1"/>
<sheet name="Panduan" sheetId="2" r:id="rId2"/>
<sheet name="Ref_Gedung" sheetId="3" state="hidden" r:id="rId3"/>
</sheets>
</workbook>';

/* ================================================================
   XML: xl/styles.xml
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
    <alignment horizontal="center" vertical="center"/></xf>
  <xf numFmtId="0" fontId="4" fillId="5" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
  <xf numFmtId="0" fontId="4" fillId="6" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
</cellXfs>
</styleSheet>';

/* ================================================================
   Helper: buat XML cell
================================================================ */
function cellXml(string $ref, $val, int $style = 0): string {
    if (is_numeric($val) && $val !== '') {
        return "<c r=\"{$ref}\" s=\"{$style}\" t=\"n\"><v>" . htmlspecialchars((string)$val, ENT_XML1) . "</v></c>";
    }
    $si = ssAdd((string)$val);
    return "<c r=\"{$ref}\" s=\"{$style}\" t=\"s\"><v>{$si}</v></c>";
}

/* ================================================================
   Sheet 3 — Ref_Gedung (hidden, berisi daftar gedung dari DB)
================================================================ */
$refRows = '';
// Baris 1: header
ssAdd('Gedung');
$refRows .= '<row r="1">' . cellXml('A1','Gedung',3) . '</row>';
// Baris 2+: nama gedung
foreach ($gedungNames as $gi => $gn) {
    $rn = $gi + 2;
    $refRows .= "<row r=\"{$rn}\">" . cellXml("A{$rn}", $gn, 0) . "</row>";
}
$gedungCount = count($gedungNames);
$sheet3 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<cols><col min="1" max="1" width="30" customWidth="1"/></cols>
<sheetData>' . $refRows . '</sheetData>
</worksheet>';

/* ================================================================
   Sheet 1 — Template Import
================================================================ */
// Baris 1: judul
$subtitle1 = 'TEMPLATE IMPORT RUANGAN MASSAL — Campus Facility Reservation';
ssAdd($subtitle1);
$row1 = '<row r="1" ht="30" customHeight="1">' . cellXml('A1', $subtitle1, 1) . '</row>';

// Baris 2: subtitle
$subtitle2 = 'Isi data mulai baris ke-4. Kolom Gedung menggunakan dropdown dari data Kelola Gedung. Simpan sebagai CSV untuk import.';
ssAdd($subtitle2);
$row2 = '<row r="2" ht="28" customHeight="1">' . cellXml('A2', $subtitle2, 2) . '</row>';

// Baris 3: header kolom
$row3Cells = '';
foreach ($headers as $i => $h) {
    ssAdd($h);
    $row3Cells .= cellXml(colLetter($i + 1) . '3', $h, 3);
}
$row3 = '<row r="3" ht="22" customHeight="1">' . $row3Cells . '</row>';

// Baris 4–7: contoh data
$dataRows = '';
foreach ($examples as $ri => $ex) {
    $r    = $ri + 4;
    $sIdx = ($ri % 2 === 0) ? 4 : 5;
    $cells = '';
    foreach ($ex as $ci => $val) {
        $cells .= cellXml(colLetter($ci + 1) . $r, $val, $sIdx);
    }
    $dataRows .= "<row r=\"{$r}\">{$cells}</row>";
}

// Lebar kolom
$colWidths = '<cols>
  <col min="1" max="1" width="28" customWidth="1"/>
  <col min="2" max="2" width="28" customWidth="1"/>
  <col min="3" max="3" width="25" customWidth="1"/>
  <col min="4" max="4" width="12" customWidth="1"/>
  <col min="5" max="5" width="18" customWidth="1"/>
  <col min="6" max="6" width="34" customWidth="1"/>
  <col min="7" max="7" width="34" customWidth="1"/>
  <col min="8" max="8" width="18" customWidth="1"/>
</cols>';

// Merged cells
$merges = '<mergeCells count="2"><mergeCell ref="A1:H1"/><mergeCell ref="A2:H2"/></mergeCells>';

// Gedung end row untuk validasi
$gedungEndRow = $gedungCount + 1; // +1 karena baris 1 = header di sheet3
// Ref untuk gedung dropdown: Ref_Gedung!$A$2:$A$N
$gedungRef = 'Ref_Gedung!$A$2:$A$' . $gedungEndRow;

$validations = '<dataValidations count="4">
  <dataValidation type="list" sqref="B4:B200" showDropDown="0" showErrorMessage="1"
    errorTitle="Gedung Tidak Valid"
    error="Pilih dari daftar gedung yang tersedia. Jika gedung belum ada, tambahkan dulu di menu Kelola Gedung/Outdoor.">
    <formula1>' . htmlspecialchars($gedungRef, ENT_XML1) . '</formula1>
  </dataValidation>
  <dataValidation type="list" sqref="C4:C200" showDropDown="0" showErrorMessage="1"
    errorTitle="Departemen Tidak Valid"
    error="Pilih dari daftar departemen/admin yang tersedia.">
    <formula1>' . htmlspecialchars($deptInline, ENT_XML1) . '</formula1>
  </dataValidation>
  <dataValidation type="list" sqref="D4:D200" showDropDown="0" showErrorMessage="1"
    errorTitle="Tipe Tidak Valid"
    error="Pilih indoor atau outdoor.">
    <formula1>' . htmlspecialchars($tipeInline, ENT_XML1) . '</formula1>
  </dataValidation>
  <dataValidation type="list" sqref="H4:H200" showDropDown="0" showErrorMessage="1"
    errorTitle="Input Tidak Valid"
    error="Pilih Ya atau Tidak.">
    <formula1>' . htmlspecialchars($tersediaInline, ENT_XML1) . '</formula1>
  </dataValidation>
</dataValidations>';

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
    $cells  = cellXml("A{$r}", $row[0], $sIdx);
    $cells .= cellXml("B{$r}", $row[1], $sIdx);
    $panduanRows .= "<row r=\"{$r}\" ht=\"28\" customHeight=\"1\">{$cells}</row>";
}

// Tambah baris daftar gedung aktual di Panduan
$panduanRows .= '<row r="' . (count($panduan)+1) . '" ht="22"><'.'c r="A'.(count($panduan)+1).'" s="3" t="s"><v>'.ssAdd('Daftar Gedung Saat Ini').'</v></'.'c><'.'c r="B'.(count($panduan)+1).'" s="3" t="s"><v>'.ssAdd(implode(' | ', $gedungNames)).'</v></'.'c></row>';

$sheet2 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<cols>
  <col min="1" max="1" width="28" customWidth="1"/>
  <col min="2" max="2" width="70" customWidth="1"/>
</cols>
<sheetData>' . $panduanRows . '</sheetData>
</worksheet>';

/* ================================================================
   Shared Strings XML
================================================================ */
$ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($ssList) . '" uniqueCount="' . count($ssList) . '">';
foreach ($ssList as $str) {
    $ssXml .= '<si><t xml:space="preserve">' . htmlspecialchars($str, ENT_XML1) . '</t></si>';
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
    'xl/worksheets/sheet3.xml'   => $sheet3,
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
