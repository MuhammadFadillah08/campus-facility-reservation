<?php
/**
 * report_data.php — Sumber data laporan bulanan (dipakai reports.php & report_print.php)
 *
 * Input (harus didefinisikan sebelum include):
 *   $db    : mysqli
 *   $user  : array user login (punya 'role','departemen')
 *   $bulan : int (1-12)
 *   $tahun : int
 *
 * Output:
 *   $rep_start, $rep_end           : tanggal awal/akhir bulan (Y-m-d)
 *   $rep_scope_label               : label cakupan laporan
 *   $rep_peminjaman[]              : peminjaman mahasiswa pada bulan tsb
 *   $rep_eksternal[]               : peminjaman eksternal pada bulan tsb
 *   $rep_jadwal[]                  : jadwal kuliah aktif pada bulan tsb
 *   $bulanID[], $hariID[]          : label bulan & hari (Indonesia)
 */

$bulanID = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
            7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
$hariID  = [1=>'Senin',2=>'Selasa',3=>'Rabu',4=>'Kamis',5=>'Jumat',6=>'Sabtu',7=>'Minggu'];

if (!function_exists('repStatusText')) {
    function repStatusText(string $s): string {
        $map = ['pending'=>'Menunggu','disetujui'=>'Disetujui','ditolak'=>'Ditolak',
                'selesai'=>'Selesai','dibatalkan'=>'Dibatalkan','aktif'=>'Aktif'];
        return $map[$s] ?? ucfirst($s);
    }
}
if (!function_exists('repJenisPemohon')) {
    function repJenisPemohon(string $s): string {
        $map = ['umum'=>'Masyarakat Umum','instansi_luar'=>'Instansi Luar',
                // 'dosen'/'tendik' dipertahankan untuk kompatibilitas data lama sebelum migrasi
                'dosen'=>'Dosen','tendik'=>'Tenaga Kependidikan'];
        return $map[$s] ?? ucfirst($s);
    }
}
if (!function_exists('repPemohonLabel')) {
    /** Nama pemohon; untuk pengajuan oleh admin (mis. Admin DMF)
     *  ditambah asal organisasi → "Admin DMF (HMD KIMIA)". */
    function repPemohonLabel(array $r): string {
        $nama = (string)($r['pemohon'] ?? '');
        $org  = trim((string)($r['asal_organisasi'] ?? ''));
        if (($r['pemohon_role'] ?? 'user') !== 'user' && $org !== '' && $org !== '-') {
            $nama .= ' (' . $org . ')';
        }
        return $nama;
    }
}

/* Normalisasi periode (bulanan / tahunan) */
$periode = (($periode ?? 'bulanan') === 'tahunan') ? 'tahunan' : 'bulanan';
$bulan = max(1, min(12, (int)$bulan));
$tahun = (int)$tahun; if ($tahun < 2000 || $tahun > 2100) $tahun = (int)date('Y');
if ($periode === 'tahunan') {
    $rep_start         = sprintf('%04d-01-01', $tahun);
    $rep_end           = sprintf('%04d-12-31', $tahun);
    $rep_periode_label = 'Tahun ' . $tahun;
} else {
    $rep_start         = sprintf('%04d-%02d-01', $tahun, $bulan);
    $rep_end           = date('Y-m-t', strtotime($rep_start));
    $rep_periode_label = $bulanID[$bulan] . ' ' . $tahun;
}

/* Cakupan sesuai role + baris unit pada kop surat */
$scope_room      = '1=1';
$rep_scope_label = 'Seluruh Fakultas Sains';
$rep_kop_unit    = 'FAKULTAS MATEMATIKA DAN ILMU PENGETAHUAN ALAM';
if (($user['role'] ?? '') === 'admin_departemen') {
    $dept_e          = $db->real_escape_string($user['departemen'] ?? '');
    $scope_room      = "r.departemen='$dept_e'";
    $rep_scope_label = 'Departemen ' . ($user['departemen'] ?? '-');
    $rep_kop_unit    = 'DEPARTEMEN ' . strtoupper($user['departemen'] ?? '');
} elseif (($user['role'] ?? '') === 'admin_dmf') {
    $scope_room      = "r.tipe='outdoor'";
    $rep_scope_label = 'Area Outdoor Fakultas Sains (Dewan Mahasiswa Fakultas)';
    $rep_kop_unit    = 'DEWAN MAHASISWA FAKULTAS SAINS';
}

$s = $db->real_escape_string($rep_start);
$e = $db->real_escape_string($rep_end);

/* 1. Peminjaman mahasiswa */
$rep_peminjaman = $db->query(
    "SELECT p.*, r.nama AS ruangan_nama, r.gedung, r.tipe, r.departemen AS r_dept,
            u.nama AS pemohon, u.nim, u.prodi, u.role AS pemohon_role
     FROM peminjaman p
     JOIN ruangan r ON p.ruangan_id = r.id
     JOIN users   u ON p.user_id    = u.id
     WHERE p.tanggal_mulai BETWEEN '$s' AND '$e' AND $scope_room
     ORDER BY p.tanggal_mulai, p.jam_mulai"
)->fetch_all(MYSQLI_ASSOC);

/* 2. Peminjaman eksternal */
$rep_eksternal = $db->query(
    "SELECT pe.*, r.nama AS ruangan_nama, r.gedung, r.tipe, r.departemen AS r_dept
     FROM peminjaman_eksternal pe
     JOIN ruangan r ON pe.ruangan_id = r.id
     WHERE pe.tanggal_mulai BETWEEN '$s' AND '$e' AND $scope_room
     ORDER BY pe.tanggal_mulai, pe.jam_mulai"
)->fetch_all(MYSQLI_ASSOC);

/* 3. Jadwal kuliah aktif (semester mencakup bulan ini) */
$rep_jadwal = $db->query(
    "SELECT jk.*, r.nama AS ruangan_nama, r.gedung, r.tipe, r.departemen AS r_dept
     FROM jadwal_kuliah jk
     JOIN ruangan r ON jk.ruangan_id = r.id
     WHERE jk.tgl_mulai_semester <= '$e' AND jk.tgl_selesai_semester >= '$s' AND $scope_room
     ORDER BY jk.hari, jk.jam_mulai"
)->fetch_all(MYSQLI_ASSOC);

/* Ringkasan angka */
$rep_count_peminjaman = count($rep_peminjaman);
$rep_count_eksternal  = count($rep_eksternal);
$rep_count_jadwal     = count($rep_jadwal);
$rep_count_disetujui  = count(array_filter($rep_peminjaman, fn($r) => $r['status'] === 'disetujui'));
