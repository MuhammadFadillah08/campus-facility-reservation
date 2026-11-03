<?php
require_once __DIR__ . '/includes/config.php';
$user = requireLogin();
$page_title = 'Dashboard';
$db = db();
$uid  = (int)$user['id'];
$role = $user['role'];

if ($role === 'admin_utama') {
    /* Total tidak menghitung 'dibatalkan'; 'selesai' dihitung sebagai disetujui
       → Total = Menunggu + Disetujui + Ditolak (selalu sinkron dengan laporan) */
    $r = $db->query("SELECT COUNT(IF(status<>'dibatalkan',1,NULL)) total, COUNT(IF(status='pending',1,NULL)) pending, COUNT(IF(status IN('disetujui','selesai'),1,NULL)) approved, COUNT(IF(status='ditolak',1,NULL)) rejected FROM peminjaman")->fetch_assoc();
    $rooms_total  = $db->query("SELECT COUNT(*) c FROM ruangan")->fetch_assoc()['c'];
    $rooms_avail  = $db->query("SELECT COUNT(*) c FROM ruangan WHERE tersedia=1")->fetch_assoc()['c'];
    $users_count  = $db->query("SELECT COUNT(*) c FROM users WHERE role='user'")->fetch_assoc()['c'];
    $recent = $db->query("SELECT p.*,r.nama ruangan_nama,r.tipe,u.nama user_nama FROM peminjaman p JOIN ruangan r ON p.ruangan_id=r.id JOIN users u ON p.user_id=u.id ORDER BY p.created_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
} elseif ($role === 'admin_departemen') {
    $dept = $db->real_escape_string($user['departemen']);
    $r = $db->query("SELECT COUNT(IF(p.status<>'dibatalkan',1,NULL)) total,COUNT(IF(p.status='pending',1,NULL)) pending,COUNT(IF(p.status IN('disetujui','selesai'),1,NULL)) approved,COUNT(IF(p.status='ditolak',1,NULL)) rejected FROM peminjaman p JOIN ruangan r ON p.ruangan_id=r.id WHERE r.departemen='$dept'")->fetch_assoc();
    $rooms_total = $db->query("SELECT COUNT(*) c FROM ruangan WHERE departemen='$dept'")->fetch_assoc()['c'];
    $rooms_avail = $db->query("SELECT COUNT(*) c FROM ruangan WHERE departemen='$dept' AND tersedia=1")->fetch_assoc()['c'];
    $recent = $db->query("SELECT p.*,r.nama ruangan_nama,r.tipe,u.nama user_nama FROM peminjaman p JOIN ruangan r ON p.ruangan_id=r.id JOIN users u ON p.user_id=u.id WHERE r.departemen='$dept' ORDER BY p.created_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
} elseif ($role === 'admin_dmf') {
    $r = $db->query("SELECT COUNT(IF(p.status<>'dibatalkan',1,NULL)) total,COUNT(IF(p.status='pending',1,NULL)) pending,COUNT(IF(p.status IN('disetujui','selesai'),1,NULL)) approved,COUNT(IF(p.status='ditolak',1,NULL)) rejected FROM peminjaman p JOIN ruangan r ON p.ruangan_id=r.id WHERE r.tipe='outdoor'")->fetch_assoc();
    $rooms_total = $db->query("SELECT COUNT(*) c FROM ruangan WHERE tipe='outdoor'")->fetch_assoc()['c'];
    $rooms_avail = $db->query("SELECT COUNT(*) c FROM ruangan WHERE tipe='outdoor' AND tersedia=1")->fetch_assoc()['c'];
    $recent = $db->query("SELECT p.*,r.nama ruangan_nama,r.tipe,u.nama user_nama FROM peminjaman p JOIN ruangan r ON p.ruangan_id=r.id JOIN users u ON p.user_id=u.id WHERE r.tipe='outdoor' ORDER BY p.created_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
} else {
    $r = $db->query("SELECT COUNT(IF(status<>'dibatalkan',1,NULL)) total,COUNT(IF(status='pending',1,NULL)) pending,COUNT(IF(status IN('disetujui','selesai'),1,NULL)) approved,COUNT(IF(status='ditolak',1,NULL)) rejected FROM peminjaman WHERE user_id=$uid")->fetch_assoc();
    $recent = $db->query("SELECT p.*,r.nama ruangan_nama,r.tipe,u.nama user_nama FROM peminjaman p JOIN ruangan r ON p.ruangan_id=r.id JOIN users u ON p.user_id=u.id WHERE p.user_id=$uid ORDER BY p.created_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
}

$hour = (int)(new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('H');
$greeting = $hour < 11 ? 'Selamat Pagi' : ($hour < 15 ? 'Selamat Siang' : ($hour < 18 ? 'Selamat Sore' : 'Selamat Malam'));

/* ── Ringkasan laporan bulan berjalan (khusus admin) ── */
$is_admin = in_array($role, ['admin_utama','admin_departemen','admin_dmf']);
if ($is_admin) {
    $bulan   = (int)date('n');
    $tahun   = (int)date('Y');
    $periode = 'bulanan';
    require __DIR__ . '/includes/report_data.php';
    $rep_total_all = $rep_count_peminjaman + $rep_count_eksternal + $rep_count_jadwal;
    $repPct = fn($n) => $rep_total_all > 0 ? round($n / $rep_total_all * 100) : 0;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="welcome-banner" style="display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap">
  <div style="flex:1;min-width:0">
    <div class="greeting"><?= $greeting ?>,</div>
    <h1><?= e($user['nama']) ?></h1>
    <p>
      <?php if ($role==='user'): ?><?= e($user['prodi']??'') ?> &middot; NIM: <?= e($user['nim']??'-') ?>
      <?php elseif($role==='admin_departemen'): ?>Admin Departemen <?= e($user['departemen']??'') ?> &middot; Fakultas Sains
      <?php elseif($role==='admin_dmf'): ?>Admin Dewan Mahasiswa Fakultas (DMF) &middot; Fakultas Sains
      <?php else: ?>Admin Utama Fakultas Sains &middot; Universitas Contoh<?php endif; ?>
    </p>
    <?php if ($role==='user'): ?>
      <a href="<?= base_url('user/rekomendasi.php') ?>" class="btn btn-primary me-2">🤖 Rekomendasi Ruangan</a>
      <a href="<?= base_url('user/booking.php') ?>" class="btn" style="background:rgba(255,255,255,.2);color:#fff">+ Ajukan Peminjaman</a>
    <?php else: ?>
      <a href="<?= base_url('admin/bookings.php') ?>" class="btn" style="background:rgba(255,255,255,.2);color:#fff">✅ Kelola Peminjaman</a>
    <?php endif; ?>
  </div>

  <!-- ── Live Clock WIB ── -->
  <div id="wib-clock" style="flex-shrink:0;background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.3);border-radius:16px;padding:14px 20px;text-align:center;backdrop-filter:blur(6px);min-width:140px">
    <div id="wib-time" style="font-size:28px;font-weight:800;letter-spacing:2px;color:#fff;font-variant-numeric:tabular-nums;line-height:1">00:00:00</div>
    <div style="font-size:11px;font-weight:700;color:rgba(255,255,255,.7);letter-spacing:1.5px;margin:3px 0 6px">WIB · UTC+7</div>
    <div id="wib-date" style="font-size:12.5px;font-weight:600;color:rgba(255,255,255,.9);line-height:1.4">—</div>
    <div id="wib-day"  style="font-size:11.5px;color:rgba(255,255,255,.7);margin-top:2px">—</div>
  </div>
</div>

<script>
(function(){
  var DAYS  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
  var MONTHS= ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Ags','Sep','Okt','Nov','Des'];
  function pad(n){ return String(n).padStart(2,'0'); }
  function tickWIB(){
    var now = new Date();
    /* WIB = UTC+7 */
    var utc  = now.getTime() + now.getTimezoneOffset()*60000;
    var wib  = new Date(utc + 7*3600000);
    var h=wib.getHours(), m=wib.getMinutes(), s=wib.getSeconds();
    var d=wib.getDate(), mo=wib.getMonth(), y=wib.getFullYear(), wd=wib.getDay();
    document.getElementById('wib-time').textContent = pad(h)+':'+pad(m)+':'+pad(s);
    document.getElementById('wib-date').textContent = pad(d)+' '+MONTHS[mo]+' '+y;
    document.getElementById('wib-day').textContent  = DAYS[wd];
    /* Ganti warna border jam malam/siang */
    var clock = document.getElementById('wib-clock');
    if(h>=6 && h<12)      clock.style.borderColor='rgba(253,224,71,.5)';   /* pagi - kuning */
    else if(h>=12 && h<18) clock.style.borderColor='rgba(255,255,255,.4)'; /* siang - putih */
    else if(h>=18 && h<21) clock.style.borderColor='rgba(251,191,36,.5)';  /* sore - oranye */
    else                   clock.style.borderColor='rgba(147,197,253,.4)'; /* malam - biru */
  }
  tickWIB();
  setInterval(tickWIB, 1000);
})();
</script>

<?php $dash_pdf_qs = http_build_query(['periode'=>'bulanan','bulan'=>(int)date('n'),'tahun'=>(int)date('Y'),'jenis'=>'semua']); ?>
<style>
.ov{background:#fff;border:1px solid #e9eef3;border-radius:18px;box-shadow:0 1px 3px rgba(0,0,0,.04);margin-bottom:24px;overflow:hidden}
.ov-head{display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:17px 22px 4px}
.ov-head .h{font-size:15.5px;font-weight:800;color:#0f172a;letter-spacing:-.01em}
.ov-head .s{font-size:12px;color:#94a3b8;font-weight:600}
.ov-kpis{display:flex;flex-wrap:wrap;padding:10px 12px 14px}
.ov-kpi{flex:1;min-width:118px;padding:10px 16px;position:relative}
.ov-kpi + .ov-kpi::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:1px;background:#eef2f7}
.ov-kpi .n{font-size:27px;font-weight:800;line-height:1;font-variant-numeric:tabular-nums}
.ov-kpi .l{font-size:11.5px;color:#64748b;font-weight:600;margin-top:5px;display:flex;align-items:center;gap:5px}
.ov-kpi .l small{color:#94a3b8;font-weight:500}
.ov-rep{border-top:1px solid #f1f5f9;background:#fafdfb;padding:16px 22px;display:flex;flex-wrap:wrap;align-items:center;gap:20px}
.ov-rep-left{flex:1;min-width:240px}
.ov-rep-title{font-size:11.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:9px;display:flex;align-items:center;gap:6px}
.ov-bar{display:flex;height:13px;border-radius:7px;overflow:hidden;background:#eef2f7}
.ov-bar span{height:100%;transition:width .45s}
.ov-legend{display:flex;flex-wrap:wrap;gap:16px;margin-top:10px;font-size:12px;color:#475569}
.ov-legend span{display:inline-flex;align-items:center;gap:6px}
.ov-legend i{width:10px;height:10px;border-radius:3px}
.ov-legend b{color:#1e293b}
.ov-empty{font-size:12.5px;color:#94a3b8;padding:4px 0}
.ov-actions{display:flex;flex-wrap:wrap;gap:9px}
.ov-btn{display:inline-flex;align-items:center;gap:7px;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:700;text-decoration:none;border:none;font-family:inherit;cursor:pointer;transition:transform .15s,box-shadow .15s,background .15s}
.ov-btn.prim{background:#16a34a;color:#fff;box-shadow:0 4px 12px rgba(22,163,74,.26)}
.ov-btn.prim:hover{transform:translateY(-2px);box-shadow:0 8px 18px rgba(22,163,74,.38)}
.ov-btn.ghost{background:#fff;color:#334155;border:1px solid #e2e8f0}
.ov-btn.ghost:hover{background:#f1f5f9}
@media(max-width:560px){
  .ov-head{padding:14px 16px 2px}
  .ov-kpis{padding:8px 6px 12px}
  .ov-kpi{flex:1 0 40%;min-width:0;padding:9px 12px}
  .ov-kpi + .ov-kpi::before{display:none}
  .ov-kpi .n{font-size:23px}
  .ov-rep{padding:14px 16px;gap:14px}
  .ov-rep-left{min-width:0}
  .ov-actions{width:100%}
  .ov-btn{flex:1;justify-content:center}
}
</style>
<?php if ($role === 'user'): ?>
<style>
.guide-wrap{background:#fff;border:1px solid #e9eef3;border-radius:18px;box-shadow:0 1px 3px rgba(0,0,0,.04);margin-bottom:24px;overflow:hidden}
.guide-head{padding:17px 22px 12px;border-bottom:1px solid #f1f5f9}
.guide-head .h{font-size:15.5px;font-weight:800;color:#0f172a;letter-spacing:-.01em}
.guide-head .sub{font-size:12.5px;color:#64748b;margin-top:3px}
.guide-steps{display:flex;flex-wrap:wrap;padding:18px 16px 20px;gap:12px}
.guide-step{flex:1;min-width:200px;background:#f8fafc;border:1.5px solid #e9eef3;border-radius:14px;padding:16px 18px;display:flex;gap:14px;align-items:flex-start;transition:box-shadow .15s,border-color .15s}
.guide-step:hover{box-shadow:0 4px 14px rgba(0,0,0,.07);border-color:#c7d7f0}
.guide-step.linked{cursor:pointer;text-decoration:none;color:inherit}
.guide-step .num{flex-shrink:0;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:800;color:#fff}
.guide-step .body{}
.guide-step .body .title{font-size:14px;font-weight:700;color:#1e293b;line-height:1.3}
.guide-step .body .desc{font-size:12px;color:#64748b;margin-top:5px;line-height:1.55}
.guide-step .body .cta{display:inline-flex;align-items:center;gap:5px;margin-top:9px;font-size:12px;font-weight:700;color:#16a34a;text-decoration:none}
.guide-step .body .cta:hover{text-decoration:underline}
.guide-note{padding:10px 22px 16px;font-size:12px;color:#94a3b8;border-top:1px solid #f1f5f9;background:#fafdfb}
@media(max-width:600px){.guide-steps{flex-direction:column;padding:14px 12px 16px}.guide-step{min-width:0}}
</style>
<!-- ══ Kotak 1: Panduan cara meminjam ruangan ══ -->
<div class="guide-wrap">
  <div class="guide-head">
    <div class="h">📖 Panduan Cara Meminjam Ruangan</div>
    <div class="sub">Ikuti langkah berikut untuk mengajukan peminjaman ruangan/gedung di Fakultas Sains</div>
  </div>
  <div class="guide-steps">
    <!-- Langkah 1 -->
    <div class="guide-step">
      <div class="num" style="background:#8b5cf6">1</div>
      <div class="body">
        <div class="title">🤖 Tentukan Ruangan (Opsional)</div>
        <div class="desc">Belum tahu ruangan yang cocok? Gunakan fitur Rekomendasi Ruangan untuk mendapatkan saran ruangan berdasarkan jumlah peserta dan kebutuhan kegiatan Anda.</div>
        <a href="<?= base_url('user/rekomendasi.php') ?>" class="cta">Coba Rekomendasi →</a>
      </div>
    </div>
    <!-- Langkah 2 -->
    <div class="guide-step">
      <div class="num" style="background:#16a34a">2</div>
      <div class="body">
        <div class="title">📝 Isi Form Pengajuan</div>
        <div class="desc">Lengkapi formulir peminjaman: nama kegiatan, asal organisasi, ruangan yang dituju, tanggal, jam, dan jumlah peserta. Pastikan data yang diisi sudah benar sebelum dikirim.</div>
        <a href="<?= base_url('user/booking.php') ?>" class="cta">Ajukan Sekarang →</a>
      </div>
    </div>
    <!-- Langkah 3 -->
    <div class="guide-step">
      <div class="num" style="background:#d97706">3</div>
      <div class="body">
        <div class="title">💬 Konfirmasi via WhatsApp</div>
        <div class="desc">Setelah form dikirim, konfirmasikan pengajuan Anda kepada admin melalui tombol WhatsApp yang muncul. Untuk ruangan outdoor, konfirmasi diarahkan ke grup WhatsApp DMF sesuai ormawa Anda.</div>
      </div>
    </div>
    <!-- Langkah 4 -->
    <div class="guide-step">
      <div class="num" style="background:#0891b2">4</div>
      <div class="body">
        <div class="title">⏳ Tunggu Persetujuan Admin</div>
        <div class="desc">Admin akan memproses pengajuan Anda. Pantau status peminjaman di menu <strong>Peminjaman Saya</strong>. Status akan berubah menjadi <em>Disetujui</em> atau <em>Ditolak</em> beserta alasannya.</div>
        <a href="<?= base_url('user/my-bookings.php') ?>" class="cta">Peminjaman Saya →</a>
      </div>
    </div>
  </div>
  <div class="guide-note">💡 Pengajuan baru dapat dilakukan kapan saja. Pastikan ruangan belum terbooking pada tanggal yang sama sebelum mengajukan.</div>
</div>

<!-- ══ Kotak 2: Panduan cara melihat ruangan kosong/terbooking ══ -->
<div class="guide-wrap">
  <div class="guide-head">
    <div class="h">🔍 Panduan Cara Melihat Ruangan Kosong / Terbooking</div>
    <div class="sub">Cek dulu ketersediaan ruangan agar pengajuan Anda tidak bentrok dengan jadwal lain</div>
  </div>
  <div class="guide-steps">
    <!-- Langkah 1 -->
    <div class="guide-step">
      <div class="num" style="background:#3b82f6">1</div>
      <div class="body">
        <div class="title">🏢 Buka Daftar Ruangan</div>
        <div class="desc">Masuk ke menu <strong>Daftar Ruangan</strong> untuk melihat semua ruangan/gedung yang tersedia beserta kapasitas dan fasilitasnya.</div>
        <a href="<?= base_url('user/rooms.php') ?>" class="cta">Buka Daftar Ruangan →</a>
      </div>
    </div>
    <!-- Langkah 2 -->
    <div class="guide-step">
      <div class="num" style="background:#16a34a">2</div>
      <div class="body">
        <div class="title">📅 Klik Tombol Kalender Booking</div>
        <div class="desc">Tekan tombol <strong>📅 Kalender Booking</strong> pada halaman Daftar Ruangan. Tampilan kalender akan terbuka mirip seperti Google Calendar.</div>
      </div>
    </div>
    <!-- Langkah 3 -->
    <div class="guide-step">
      <div class="num" style="background:#d97706">3</div>
      <div class="body">
        <div class="title">🗓️ Pilih Tanggal yang Diinginkan</div>
        <div class="desc">Klik tanggal pada kalender. Ruangan yang sudah <strong>terbooking</strong> akan ditandai pada tanggal tersebut, sehingga Anda tahu ruangan mana yang masih <strong>kosong</strong>.</div>
      </div>
    </div>
    <!-- Langkah 4 -->
    <div class="guide-step">
      <div class="num" style="background:#0891b2">4</div>
      <div class="body">
        <div class="title">✅ Tentukan Pilihan Anda</div>
        <div class="desc">Setelah memastikan ruangan kosong pada tanggal & jam yang Anda butuhkan, lanjutkan ke proses peminjaman pada panduan di atas.</div>
        <a href="<?= base_url('user/booking.php') ?>" class="cta">Lanjut Ajukan Peminjaman →</a>
      </div>
    </div>
  </div>
  <div class="guide-note">💡 Warna/tanda pada kalender menunjukkan tanggal yang sudah ada peminjaman. Tanggal tanpa tanda berarti ruangan masih tersedia.</div>
</div>
<?php else: ?>
<div class="ov">
  <div class="ov-head">
    <div class="h">📊 Ringkasan</div>
    <?php if($is_admin): ?><div class="s"><?= e($rep_scope_label) ?></div><?php endif; ?>
  </div>

  <!-- KPI strip -->
  <div class="ov-kpis">
    <div class="ov-kpi"><div class="n" style="color:#2563eb"><?= $r['total']??0 ?></div><div class="l">📋 Total Pengajuan</div></div>
    <div class="ov-kpi"><div class="n" style="color:#d97706"><?= $r['pending']??0 ?></div><div class="l">⏳ Menunggu</div></div>
    <div class="ov-kpi"><div class="n" style="color:#059669"><?= $r['approved']??0 ?></div><div class="l">✅ Disetujui</div></div>
    <div class="ov-kpi"><div class="n" style="color:#dc2626"><?= $r['rejected']??0 ?></div><div class="l">❌ Ditolak</div></div>
    <?php if ($is_admin): ?>
    <div class="ov-kpi"><div class="n" style="color:var(--primary)"><?= $rooms_total??0 ?></div><div class="l">🏢 Ruangan <small>(<?= $rooms_avail??0 ?>)</small></div></div>
    <?php if ($role==='admin_utama'): ?>
    <div class="ov-kpi"><div class="n" style="color:#7c3aed"><?= $users_count??0 ?></div><div class="l">👤 Mahasiswa</div></div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($is_admin): ?>
  <!-- Zona laporan -->
  <div class="ov-rep">
    <div class="ov-rep-left">
      <div class="ov-rep-title">📈 Aktivitas Ruangan · <?= e($rep_periode_label) ?></div>
      <?php if ($rep_total_all > 0): ?>
      <div class="ov-bar">
        <span style="width:<?= $repPct($rep_count_peminjaman) ?>%;background:#3b82f6"></span>
        <span style="width:<?= $repPct($rep_count_eksternal) ?>%;background:#8b5cf6"></span>
        <span style="width:<?= $repPct($rep_count_jadwal) ?>%;background:#d97706"></span>
      </div>
      <div class="ov-legend">
        <span><i style="background:#3b82f6"></i> Mahasiswa <b><?= $rep_count_peminjaman ?></b> (<?= $repPct($rep_count_peminjaman) ?>%)</span>
        <span><i style="background:#8b5cf6"></i> Eksternal <b><?= $rep_count_eksternal ?></b> (<?= $repPct($rep_count_eksternal) ?>%)</span>
        <span><i style="background:#d97706"></i> Jadwal <b><?= $rep_count_jadwal ?></b> (<?= $repPct($rep_count_jadwal) ?>%)</span>
      </div>
      <?php else: ?>
      <div class="ov-empty">Belum ada aktivitas pada <?= e($rep_periode_label) ?>.</div>
      <?php endif; ?>
    </div>
    <div class="ov-actions">
      <a href="<?= base_url('admin/reports.php') ?>" class="ov-btn prim">📄 Lihat Laporan</a>
      <a href="<?= base_url('admin/report_print.php') ?>?<?= e($dash_pdf_qs) ?>" target="_blank" rel="noopener" class="ov-btn ghost">🖨️ Download PDF</a>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <span class="card-title">📊 Peminjaman Terbaru</span>
    <a href="<?= $role==='user' ? base_url('user/my-bookings.php') : base_url('admin/bookings.php') ?>" class="btn btn-outline btn-sm">Lihat Semua &rarr;</a>
  </div>
  <?php if (empty($recent)): ?>
  <div class="empty-state"><div class="icon">📋</div><h3>Belum ada pengajuan</h3></div>
  <?php else: ?>
  <div class="table-responsive">
  <table class="table">
    <thead><tr><?php if($role!=='user'):?><th>Pemohon</th><?php endif;?><th>Nama Kegiatan</th><th>Ruangan</th><th>Tanggal</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $b): ?>
    <tr>
      <?php if($role!=='user'):?><td class="fw-semibold"><?= e($b['user_nama']) ?></td><?php endif; ?>
      <td><div class="fw-semibold" style="max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($b['nama_kegiatan']) ?></div></td>
      <td><?= e($b['ruangan_nama']) ?><br><small class="text-muted"><?= $b['tipe']==='outdoor'?'🌿 Outdoor':'🏢 Indoor' ?></small></td>
      <td><?= e(date('d-m-Y', strtotime($b['tanggal_mulai']))) ?><br><small class="text-muted"><?= e($b['jam_mulai']) ?>–<?= e($b['jam_selesai']) ?></small></td>
      <td><?= statusLabel($b['status']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
