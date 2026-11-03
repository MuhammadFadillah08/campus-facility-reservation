<?php
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('admin_utama','admin_departemen','admin_dmf');
$db = db();

$bulan   = (int)($_GET['bulan'] ?? date('n'));
$tahun   = (int)($_GET['tahun'] ?? date('Y'));
$jenis   = $_GET['jenis'] ?? 'semua';
$periode = $_GET['periode'] ?? 'bulanan';

require __DIR__ . '/../includes/report_data.php';

$show_p  = ($jenis==='semua' || $jenis==='peminjaman');
$show_e  = ($jenis==='semua' || $jenis==='eksternal');
$show_j  = ($jenis==='semua' || $jenis==='jadwal');

/* Tanggal cetak (Indonesia) */
$tgl_cetak = (int)date('j') . ' ' . $bulanID[(int)date('n')] . ' ' . date('Y');

/* Nama & jabatan penanda tangan — dari input admin, fallback ke akun login */
$pj_jabatan = trim($_GET['ttd_jabatan'] ?? '') ?: (match($user['role']) {
    'admin_departemen' => 'Admin Departemen ' . ($user['departemen'] ?? ''),
    'admin_dmf'        => 'Admin Dewan Mahasiswa Fakultas (DMF)',
    default            => 'Admin Utama Fakultas Sains',
});
$pj_nama = trim($_GET['ttd_nama'] ?? '') ?: ($user['nama'] ?? '');

/* Logo (opsional) — taruh assets/images/logo.png untuk logo resmi */
$logo_file = __DIR__ . '/../assets/images/logo.png';
$has_logo  = file_exists($logo_file);
$logo_url  = base_url('assets/images/logo.png');

/* Helper periode tanggal */
function repTgl(string $mulai, string $selesai): string {
    $m = date('d-m-Y', strtotime($mulai));
    if ($selesai && $selesai !== $mulai) return $m . ' s.d. ' . date('d-m-Y', strtotime($selesai));
    return $m;
}
function repJam(string $a, string $b): string {
    return substr($a,0,5) . '–' . substr($b,0,5) . ' WIB';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laporan <?= e($bulanID[$bulan]) ?> <?= $tahun ?> — Campus Facility Reservation</title>
<style>
  * { box-sizing:border-box; }
  body { font-family:"Times New Roman", Georgia, serif; color:#000; margin:0; background:#e5e7eb; }
  .sheet { background:#fff; max-width:880px; margin:18px auto; padding:38px 44px; }

  /* ── KOP SURAT ── */
  .kop { display:flex; align-items:center; gap:16px; border-bottom:3px solid #000; padding-bottom:8px; }
  .kop-logo { width:88px; flex-shrink:0; display:flex; align-items:center; justify-content:center; }
  .kop-logo img { width:88px; height:88px; object-fit:contain; }
  .kop-logo .seal { width:84px; height:84px; border:2.5px solid #1a1a1a; border-radius:50%;
                    display:flex; align-items:center; justify-content:center; font-size:30px; font-weight:bold;
                    color:#1a1a1a; position:relative; }
  .kop-logo .seal::after { content:''; position:absolute; inset:5px; border:1px solid #1a1a1a; border-radius:50%; }
  .kop-text { flex:1; text-align:center; line-height:1.3; padding-right:88px; }
  .kop-text .l1 { font-size:13.5px; }
  .kop-text .l2 { font-size:15px; }
  .kop-text .l3 { font-size:16px; font-weight:bold; }
  .kop-text .l4 { font-size:11.5px; margin-top:2px; }

  /* ── JUDUL ── */
  .judul { text-align:center; margin:22px 0 4px; }
  .judul h1 { font-size:16px; font-weight:bold; text-transform:uppercase; text-decoration:underline; margin:0; letter-spacing:.5px; }
  .judul .periode { font-size:13px; margin-top:5px; }

  .meta { font-size:12.5px; margin:16px 0 6px; }
  .meta table td { padding:1px 0; vertical-align:top; }
  .meta table td:first-child { width:150px; }

  /* ── SECTION ── */
  .sec-title { font-size:13px; font-weight:bold; margin:18px 0 7px; padding:4px 8px; background:#f0f0f0; border-left:4px solid #14532d; }

  table.data { width:100%; border-collapse:collapse; font-size:11px; margin-bottom:6px; }
  table.data th, table.data td { border:1px solid #555; padding:4px 6px; text-align:left; vertical-align:top; }
  table.data th { background:#dde7dd; font-weight:bold; text-align:center; }
  table.data td.c { text-align:center; }
  table.data .empty { text-align:center; color:#666; font-style:italic; padding:12px; }

  .ringkasan { font-size:12px; margin:14px 0; border:1px solid #999; padding:10px 14px; background:#fafafa; }
  .ringkasan b { display:inline-block; min-width:30px; }

  /* ── TTD ── */
  .ttd { margin-top:34px; display:flex; justify-content:flex-end; }
  .ttd-box { width:300px; text-align:center; font-size:12.5px; line-height:1.5; }
  .ttd-space { height:64px; }
  .ttd-name { font-weight:bold; text-decoration:underline; }

  .footnote { margin-top:26px; font-size:10px; color:#555; border-top:1px solid #ccc; padding-top:6px; text-align:center; }

  /* ── TOOLBAR (layar saja) ── */
  .toolbar { position:sticky; top:0; background:#14532d; padding:12px 18px; display:flex; gap:10px; align-items:center; justify-content:center; box-shadow:0 2px 10px rgba(0,0,0,.2); z-index:10; }
  .toolbar button, .toolbar a { font-family:Arial, sans-serif; font-size:13.5px; font-weight:bold; border:none; border-radius:8px; padding:10px 18px; cursor:pointer; text-decoration:none; }
  .tb-print { background:#fff; color:#14532d; }
  .tb-close { background:rgba(255,255,255,.18); color:#fff; }
  .toolbar .hint { color:#dcfce7; font-size:12px; font-family:Arial, sans-serif; }

  @media print {
    body { background:#fff; }
    .sheet { margin:0; max-width:none; padding:0; }
    .no-print { display:none !important; }
    @page { size:A4; margin:1.4cm 1.2cm; }
    table.data { font-size:10px; }
    .sec-title { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    table.data th { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  }

  /* ── Tampilan layar di HP ── */
  @media (max-width:640px) {
    .sheet { margin:0; padding:20px 16px; }
    .kop { gap:10px; }
    .kop-logo, .kop-logo img, .kop-logo .seal { width:54px; }
    .kop-logo .seal { height:54px; font-size:20px; }
    .kop-text { padding-right:0; }
    .kop-text .l1 { font-size:11px; }
    .kop-text .l2 { font-size:13px; }
    .kop-text .l3 { font-size:13px; }
    .kop-text .l4 { font-size:10px; }
    .judul h1 { font-size:14px; }
    /* Tabel bisa di-scroll horizontal di layar sempit */
    table.data { display:block; overflow-x:auto; -webkit-overflow-scrolling:touch; white-space:nowrap; font-size:11px; }
    .toolbar { flex-wrap:wrap; gap:8px; padding:10px 12px; }
    .toolbar .hint { width:100%; text-align:center; order:3; }
    .ttd { justify-content:center; }
    .ttd-box { width:100%; max-width:300px; }
  }
</style>
</head>
<body>

<div class="toolbar no-print">
  <button class="tb-print" onclick="window.print()">⬇️ Download / Cetak PDF</button>
  <a class="tb-close" href="<?= base_url('admin/reports.php') ?>?<?= e(http_build_query(['bulan'=>$bulan,'tahun'=>$tahun,'jenis'=>$jenis])) ?>">✕ Tutup</a>
  <span class="hint">Pada dialog cetak, pilih <b>"Simpan sebagai PDF"</b> sebagai tujuan.</span>
</div>

<div class="sheet">

  <!-- KOP SURAT -->
  <div class="kop">
    <div class="kop-logo">
      <!-- Selalu coba muat dari URL; jika gagal (belum di-upload/404) → tampilkan emblem "CF" -->
      <img id="kop-logo-img" src="<?= e($logo_url) ?>?v=<?= @filemtime($logo_file) ?: time() ?>" alt="Logo Kampus"
           onerror="this.style.display='none';var s=document.getElementById('kop-logo-seal');if(s)s.style.display='flex';">
      <div id="kop-logo-seal" class="seal" style="display:none">CF</div>
    </div>
    <div class="kop-text">
      <div class="l1">KEMENTERIAN CONTOH</div>
      <div class="l2">UNIVERSITAS CONTOH</div>
      <div class="l3"><?= e($rep_kop_unit) ?></div>
      <div class="l4">Jl. Contoh No. 1, Kota Contoh 12345</div>
      <div class="l4">Telepon: (021) 000-0000</div>
      <div class="l4">Laman: www.faculty.university.example</div>
    </div>
  </div>

  <!-- JUDUL -->
  <div class="judul">
    <h1>Laporan <?= $periode==='tahunan'?'Tahunan':'Bulanan' ?> Penggunaan Ruangan dan Gedung</h1>
    <div class="periode">Periode: <strong><?= e($rep_periode_label) ?></strong></div>
  </div>

  <!-- META -->
  <div class="meta">
    <table>
      <tr><td>Cakupan Laporan</td><td>: <?= e($rep_scope_label) ?></td></tr>
      <tr><td>Rentang Tanggal</td><td>: <?= e(date('d-m-Y', strtotime($rep_start))) ?> s.d. <?= e(date('d-m-Y', strtotime($rep_end))) ?></td></tr>
      <tr><td>Tanggal Cetak</td><td>: <?= e($tgl_cetak) ?></td></tr>
    </table>
  </div>

  <?php if ($show_p): ?>
  <!-- PEMINJAMAN MAHASISWA -->
  <div class="sec-title">A. Peminjaman Ruangan oleh Mahasiswa</div>
  <table class="data">
    <thead><tr>
      <th style="width:26px">No</th><th>Nama Pemohon (NIM)</th><th>Nama Kegiatan</th>
      <th>Ruangan / Gedung</th><th>Tanggal</th><th>Waktu</th><th style="width:48px">Peserta</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php if(empty($rep_peminjaman)): ?>
      <tr><td colspan="8" class="empty">Tidak ada peminjaman mahasiswa pada periode ini.</td></tr>
    <?php else: foreach($rep_peminjaman as $i=>$r): ?>
      <tr>
        <td class="c"><?= $i+1 ?></td>
        <td><?= e(repPemohonLabel($r)) ?><?php if($r['nim']): ?> (<?= e($r['nim']) ?>)<?php endif; ?></td>
        <td><?= e($r['nama_kegiatan']) ?></td>
        <td><?= e($r['ruangan_nama']) ?> / <?= e($r['gedung']) ?></td>
        <td><?= e(repTgl($r['tanggal_mulai'],$r['tanggal_selesai'])) ?></td>
        <td><?= e(repJam($r['jam_mulai'],$r['jam_selesai'])) ?></td>
        <td class="c"><?= (int)$r['jumlah_peserta'] ?></td>
        <td><?= e(repStatusText($r['status'])) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($show_e): ?>
  <!-- PEMINJAMAN EKSTERNAL -->
  <div class="sec-title"><?= $show_p ? 'B' : 'A' ?>. Peminjaman Eksternal (Pihak Luar)</div>
  <table class="data">
    <thead><tr>
      <th style="width:26px">No</th><th>Nama Pemohon</th><th>Instansi</th><th>Jenis</th>
      <th>Nama Kegiatan</th><th>Ruangan / Gedung</th><th>Tanggal</th><th>Waktu</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php if(empty($rep_eksternal)): ?>
      <tr><td colspan="9" class="empty">Tidak ada peminjaman eksternal pada periode ini.</td></tr>
    <?php else: foreach($rep_eksternal as $i=>$r): ?>
      <tr>
        <td class="c"><?= $i+1 ?></td>
        <td><?= e($r['nama_pemohon']) ?></td>
        <td><?= e($r['instansi'] ?: '-') ?></td>
        <td><?= e(repJenisPemohon($r['jenis_pemohon'])) ?></td>
        <td><?= e($r['nama_kegiatan']) ?></td>
        <td><?= e($r['ruangan_nama']) ?> / <?= e($r['gedung']) ?></td>
        <td><?= e(repTgl($r['tanggal_mulai'],$r['tanggal_selesai'])) ?></td>
        <td><?= e(repJam($r['jam_mulai'],$r['jam_selesai'])) ?></td>
        <td><?= e(repStatusText($r['status'])) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($show_j): ?>
  <!-- JADWAL KULIAH -->
  <div class="sec-title"><?= ($show_p && $show_e) ? 'C' : (($show_p || $show_e) ? 'B' : 'A') ?>. Jadwal Kuliah Aktif (Semester Berjalan)</div>
  <table class="data">
    <thead><tr>
      <th style="width:26px">No</th><th>Hari</th><th>Mata Kuliah (Kode)</th><th>Dosen</th>
      <th>Kelas / Prodi</th><th>Ruangan / Gedung</th><th>Waktu</th><th>Semester</th>
    </tr></thead>
    <tbody>
    <?php if(empty($rep_jadwal)): ?>
      <tr><td colspan="8" class="empty">Tidak ada jadwal kuliah aktif pada periode ini.</td></tr>
    <?php else: foreach($rep_jadwal as $i=>$r): ?>
      <tr>
        <td class="c"><?= $i+1 ?></td>
        <td><?= e($hariID[(int)$r['hari']] ?? '-') ?></td>
        <td><?= e($r['nama_matkul']) ?><?php if($r['kode_matkul']): ?> (<?= e($r['kode_matkul']) ?>)<?php endif; ?></td>
        <td><?= e($r['dosen'] ?: '-') ?></td>
        <td><?= e($r['kelas'] ?: '-') ?><?php if($r['prodi']): ?> / <?= e($r['prodi']) ?><?php endif; ?></td>
        <td><?= e($r['ruangan_nama']) ?> / <?= e($r['gedung']) ?></td>
        <td><?= e(repJam($r['jam_mulai'],$r['jam_selesai'])) ?></td>
        <td><?= e($r['semester']) ?> <?= e($r['tahun_ajaran'] ?: '') ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <!-- RINGKASAN -->
  <div class="ringkasan">
    <div style="font-weight:bold;margin-bottom:5px">Ringkasan Periode <?= e($rep_periode_label) ?>:</div>
    <?php if($show_p): ?>• Peminjaman Mahasiswa: <b><?= $rep_count_peminjaman ?></b> pengajuan (disetujui: <?= $rep_count_disetujui ?>)<br><?php endif; ?>
    <?php if($show_e): ?>• Peminjaman Eksternal: <b><?= $rep_count_eksternal ?></b> kegiatan<br><?php endif; ?>
    <?php if($show_j): ?>• Jadwal Kuliah Aktif: <b><?= $rep_count_jadwal ?></b> sesi mingguan<?php endif; ?>
  </div>

  <!-- TANDA TANGAN -->
  <div class="ttd">
    <div class="ttd-box">
      Kota Contoh, <?= e($tgl_cetak) ?><br>
      <?= e($pj_jabatan) ?>,
      <div class="ttd-space"></div>
      <span class="ttd-name"><?= e($pj_nama) ?></span>
    </div>
  </div>

  <div class="footnote">
    Dokumen ini dihasilkan secara otomatis oleh Sistem Informasi Manajemen Peminjaman Gedung (CFR) Fakultas Sains Universitas Contoh.
  </div>

</div>

<script>
/* Buka dialog cetak otomatis SETELAH logo selesai dimuat (sukses/gagal),
   agar logo ikut tercetak di PDF. Ada fallback timeout bila lambat. */
(function(){
  var printed = false;
  function go(){ if(printed) return; printed = true; setTimeout(function(){ window.print(); }, 350); }
  var img = document.getElementById('kop-logo-img');
  if (img && !img.complete) {
    img.addEventListener('load',  go);
    img.addEventListener('error', go);
    setTimeout(go, 3000);                 // fallback maksimal 3 detik
  } else {
    window.addEventListener('load', go);
    setTimeout(go, 1500);
  }
})();
</script>
</body>
</html>
