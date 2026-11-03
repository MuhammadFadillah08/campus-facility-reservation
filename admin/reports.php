<?php
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('admin_utama','admin_departemen','admin_dmf');
$page_title = 'Laporan';
$db = db();

$bulan   = (int)($_GET['bulan'] ?? date('n'));
$tahun   = (int)($_GET['tahun'] ?? date('Y'));
$jenis   = $_GET['jenis'] ?? 'semua';        // semua | peminjaman | eksternal | jadwal
$periode = $_GET['periode'] ?? 'bulanan';    // bulanan | tahunan

require __DIR__ . '/../includes/report_data.php';

/* Default nama & jabatan penanda tangan (bisa diubah admin sebelum download) */
$ttd_jabatan_default = match($user['role']) {
    'admin_departemen' => 'Admin Departemen ' . ($user['departemen'] ?? ''),
    'admin_dmf'        => 'Admin Dewan Mahasiswa Fakultas (DMF)',
    default            => 'Admin Utama Fakultas Sains',
};
$ttd_nama_default = $user['nama'] ?? '';

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.rpt-head{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:18px}
.rpt-panel{background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,.05);overflow:hidden;margin-bottom:24px}
.rpt-sec{padding:16px 20px}
.rpt-sec + .rpt-sec{border-top:1px dashed #e5e7eb}
.rpt-sec-label{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#16a34a;margin-bottom:13px}
.rpt-sec-label .muted{font-weight:500;text-transform:none;letter-spacing:0;color:#94a3b8;font-size:11.5px}
.rpt-row{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end}
.rpt-field{display:flex;flex-direction:column;gap:5px}
.rpt-field.grow{flex:1;min-width:190px}
.rpt-field label{font-size:11.5px;font-weight:700;color:#64748b}
.rpt-input,.rpt-select{border:1.5px solid #e2e8f0;border-radius:9px;padding:9px 12px;font-size:13.5px;color:#1e293b;background:#f8fafc;outline:none;transition:border .15s,box-shadow .15s;font-family:inherit}
.rpt-select{cursor:pointer;min-width:140px;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Cpath d='M3 4.5 6 8l3-3.5'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 11px center;padding-right:30px}
.rpt-input:focus,.rpt-select:focus{border-color:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.12);background:#fff}
.rpt-input:disabled,.rpt-select:disabled{opacity:.5;cursor:not-allowed}
.rpt-chip{margin-left:auto;align-self:center;background:#16a34a;color:#fff;font-size:12.5px;font-weight:700;padding:7px 14px;border-radius:20px;white-space:nowrap}
.rpt-dl{display:inline-flex;align-items:center;gap:9px;background:#16a34a;color:#fff;border:none;border-radius:11px;padding:12px 22px;font-size:14px;font-weight:700;cursor:pointer;white-space:nowrap;box-shadow:0 4px 12px rgba(22,163,74,.28);transition:transform .15s,box-shadow .15s;font-family:inherit}
.rpt-dl:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(22,163,74,.4)}
.rpt-dl:active{transform:translateY(0)}
@media(max-width:560px){
  .rpt-sec{padding:14px 14px}
  .rpt-row{gap:11px}
  .rpt-field,.rpt-field.grow{flex:1 1 100%;min-width:0}
  .rpt-select,.rpt-input{width:100%;min-width:0}
  .rpt-chip{margin-left:0;width:100%;text-align:center}
  .rpt-dl{width:100%;justify-content:center}
}
</style>

<!-- ══ JUDUL ══ -->
<div class="rpt-head">
  <div>
    <div class="section-title">📊 Laporan</div>
    <div class="section-sub">Rekap peminjaman ruangan, peminjaman eksternal, dan jadwal kuliah — <strong><?= e($rep_scope_label) ?></strong></div>
  </div>
</div>

<!-- ══ PANEL KONTROL LAPORAN ══ -->
<div class="rpt-panel">

  <!-- Filter -->
  <form method="get" class="rpt-sec">
    <div class="rpt-sec-label">⚙️ Pengaturan Laporan</div>
    <div class="rpt-row">
      <div class="rpt-field">
        <label>Periode</label>
        <select name="periode" id="f-periode" class="rpt-select" onchange="this.form.submit()">
          <option value="bulanan" <?= $periode==='bulanan'?'selected':'' ?>>📅 Bulanan</option>
          <option value="tahunan" <?= $periode==='tahunan'?'selected':'' ?>>🗓️ Tahunan</option>
        </select>
      </div>
      <div class="rpt-field">
        <label>Bulan</label>
        <select name="bulan" id="f-bulan" class="rpt-select" onchange="this.form.submit()" <?= $periode==='tahunan'?'disabled':'' ?>>
          <?php foreach($bulanID as $bn=>$bl): ?>
          <option value="<?= $bn ?>" <?= $bulan===$bn?'selected':'' ?>><?= e($bl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rpt-field">
        <label>Tahun</label>
        <select name="tahun" class="rpt-select" onchange="this.form.submit()">
          <?php for($y=(int)date('Y')+1;$y>=2023;$y--): ?>
          <option value="<?= $y ?>" <?= $tahun===$y?'selected':'' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="rpt-field grow">
        <label>Jenis Laporan</label>
        <select name="jenis" class="rpt-select" style="width:100%" onchange="this.form.submit()">
          <option value="semua"       <?= $jenis==='semua'?'selected':'' ?>>Semua Laporan</option>
          <option value="peminjaman"  <?= $jenis==='peminjaman'?'selected':'' ?>>Peminjaman Mahasiswa</option>
          <option value="eksternal"   <?= $jenis==='eksternal'?'selected':'' ?>>Peminjaman Eksternal</option>
          <option value="jadwal"      <?= $jenis==='jadwal'?'selected':'' ?>>Jadwal Kuliah</option>
        </select>
      </div>
      <span class="rpt-chip">🗓️ <?= e($rep_periode_label) ?></span>
    </div>
  </form>

  <!-- Tanda tangan + tombol download -->
  <div class="rpt-sec">
    <div class="rpt-sec-label">✍️ Penanda Tangan <span class="muted">— muncul di blok tanda tangan PDF</span></div>
    <div class="rpt-row">
      <div class="rpt-field grow">
        <label>Nama Penanda Tangan</label>
        <input type="text" id="ttd-nama" class="rpt-input" value="<?= e($ttd_nama_default) ?>" placeholder="Nama lengkap penanggung jawab">
      </div>
      <div class="rpt-field grow">
        <label>Jabatan</label>
        <input type="text" id="ttd-jabatan" class="rpt-input" value="<?= e($ttd_jabatan_default) ?>" placeholder="Jabatan penanda tangan">
      </div>
      <button type="button" onclick="downloadReport()" class="rpt-dl">🖨️ Cetak / Download PDF</button>
    </div>
  </div>

</div>

<script>
function downloadReport(){
  const p = new URLSearchParams({
    periode: <?= json_encode($periode) ?>,
    bulan:   <?= json_encode((string)$bulan) ?>,
    tahun:   <?= json_encode((string)$tahun) ?>,
    jenis:   <?= json_encode($jenis) ?>,
    ttd_nama:    document.getElementById('ttd-nama').value || '',
    ttd_jabatan: document.getElementById('ttd-jabatan').value || ''
  });
  window.open('<?= base_url('admin/report_print.php') ?>?' + p.toString(), '_blank', 'noopener');
}
/* Saat periode tahunan, bulan tidak relevan */
document.getElementById('f-periode').addEventListener('change', function(){
  document.getElementById('f-bulan').disabled = (this.value === 'tahunan');
});
</script>

<!-- ══ RINGKASAN ══ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:22px">
  <?php
    $cards = [
      ['📋','Peminjaman Mahasiswa', $rep_count_peminjaman, '#3b82f6'],
      ['✅','Disetujui',            $rep_count_disetujui,  '#16a34a'],
      ['🏛️','Peminjaman Eksternal', $rep_count_eksternal,  '#8b5cf6'],
      ['📚','Jadwal Kuliah Aktif',  $rep_count_jadwal,     '#d97706'],
    ];
    foreach($cards as $c):
  ?>
  <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:14px">
    <div style="width:44px;height:44px;border-radius:11px;background:<?= $c[3] ?>18;display:flex;align-items:center;justify-content:center;font-size:21px"><?= $c[0] ?></div>
    <div>
      <div style="font-size:24px;font-weight:800;color:<?= $c[3] ?>;line-height:1"><?= $c[2] ?></div>
      <div style="font-size:12px;color:#6b7280;margin-top:3px"><?= e($c[1]) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($jenis==='semua' || $jenis==='peminjaman'): ?>
<!-- ══ PEMINJAMAN MAHASISWA ══ -->
<div class="card mb-4">
  <div class="card-header"><span class="card-title">📋 Peminjaman Ruangan oleh Mahasiswa</span></div>
  <div class="table-responsive"><table class="table">
    <thead><tr>
      <th style="width:36px">No</th><th>Pemohon</th><th>Kegiatan</th><th>Ruangan</th>
      <th>Tanggal</th><th>Waktu</th><th style="text-align:center">Peserta</th><th>Status</th>
    </tr></thead>
    <tbody>
      <?php if(empty($rep_peminjaman)): ?>
      <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:24px">Tidak ada data pada periode ini.</td></tr>
      <?php else: foreach($rep_peminjaman as $i=>$r): ?>
      <tr>
        <td><?= $i+1 ?></td>
        <td>
          <div class="fw-semibold"><?= e(repPemohonLabel($r)) ?></div>
          <div class="fs-sm text-muted"><?= e($r['nim'] ?: '-') ?> · <?= e($r['prodi'] ?: '-') ?></div>
        </td>
        <td><?= e($r['nama_kegiatan']) ?></td>
        <td><?= e($r['ruangan_nama']) ?><div class="fs-sm text-muted"><?= e($r['gedung']) ?></div></td>
        <td class="fs-sm"><?= e(date('d-m-Y', strtotime($r['tanggal_mulai']))) ?><?php if($r['tanggal_selesai']!==$r['tanggal_mulai']): ?><br>s/d <?= e(date('d-m-Y', strtotime($r['tanggal_selesai']))) ?><?php endif; ?></td>
        <td class="fs-sm"><?= e(substr($r['jam_mulai'],0,5)) ?>–<?= e(substr($r['jam_selesai'],0,5)) ?></td>
        <td style="text-align:center"><?= (int)$r['jumlah_peserta'] ?></td>
        <td><?= statusLabel($r['status']) ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($jenis==='semua' || $jenis==='eksternal'): ?>
<!-- ══ PEMINJAMAN EKSTERNAL ══ -->
<div class="card mb-4">
  <div class="card-header"><span class="card-title">🏛️ Peminjaman Eksternal</span></div>
  <div class="table-responsive"><table class="table">
    <thead><tr>
      <th style="width:36px">No</th><th>Pemohon / Instansi</th><th>Jenis</th><th>Kegiatan</th><th>Ruangan</th>
      <th>Tanggal</th><th>Waktu</th><th>Status</th>
    </tr></thead>
    <tbody>
      <?php if(empty($rep_eksternal)): ?>
      <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:24px">Tidak ada data pada periode ini.</td></tr>
      <?php else: foreach($rep_eksternal as $i=>$r): ?>
      <tr>
        <td><?= $i+1 ?></td>
        <td>
          <div class="fw-semibold"><?= e($r['nama_pemohon']) ?></div>
          <div class="fs-sm text-muted"><?= e($r['instansi'] ?: '-') ?></div>
        </td>
        <td class="fs-sm"><?= e(repJenisPemohon($r['jenis_pemohon'])) ?></td>
        <td><?= e($r['nama_kegiatan']) ?></td>
        <td><?= e($r['ruangan_nama']) ?><div class="fs-sm text-muted"><?= e($r['gedung']) ?></div></td>
        <td class="fs-sm"><?= e(date('d-m-Y', strtotime($r['tanggal_mulai']))) ?><?php if($r['tanggal_selesai']!==$r['tanggal_mulai']): ?><br>s/d <?= e(date('d-m-Y', strtotime($r['tanggal_selesai']))) ?><?php endif; ?></td>
        <td class="fs-sm"><?= e(substr($r['jam_mulai'],0,5)) ?>–<?= e(substr($r['jam_selesai'],0,5)) ?></td>
        <td><span class="badge badge-success"><?= e(repStatusText($r['status'])) ?></span></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($jenis==='semua' || $jenis==='jadwal'): ?>
<!-- ══ JADWAL KULIAH ══ -->
<div class="card mb-4">
  <div class="card-header"><span class="card-title">📚 Jadwal Kuliah Aktif (semester berjalan)</span></div>
  <div class="table-responsive"><table class="table">
    <thead><tr>
      <th style="width:36px">No</th><th>Hari</th><th>Mata Kuliah</th><th>Dosen</th><th>Kelas/Prodi</th>
      <th>Ruangan</th><th>Waktu</th><th>Semester</th>
    </tr></thead>
    <tbody>
      <?php if(empty($rep_jadwal)): ?>
      <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:24px">Tidak ada data pada periode ini.</td></tr>
      <?php else: foreach($rep_jadwal as $i=>$r): ?>
      <tr>
        <td><?= $i+1 ?></td>
        <td class="fs-sm"><?= e($hariID[(int)$r['hari']] ?? '-') ?></td>
        <td><?= e($r['nama_matkul']) ?><?php if($r['kode_matkul']): ?><div class="fs-sm text-muted"><?= e($r['kode_matkul']) ?></div><?php endif; ?></td>
        <td class="fs-sm"><?= e($r['dosen'] ?: '-') ?></td>
        <td class="fs-sm"><?= e($r['kelas'] ?: '-') ?><?php if($r['prodi']): ?><div class="text-muted"><?= e($r['prodi']) ?></div><?php endif; ?></td>
        <td><?= e($r['ruangan_nama']) ?><div class="fs-sm text-muted"><?= e($r['gedung']) ?></div></td>
        <td class="fs-sm"><?= e(substr($r['jam_mulai'],0,5)) ?>–<?= e(substr($r['jam_selesai'],0,5)) ?></td>
        <td class="fs-sm"><?= e($r['semester']) ?> <?= e($r['tahun_ajaran'] ?: '') ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
