<?php
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('user');
$page_title = 'Peminjaman Saya';
$db = db();
$filter_status=$_GET['status']??'';
$uid=(int)$user['id'];
$where="p.user_id=$uid";
if ($filter_status) $where.=" AND p.status='".$db->real_escape_string($filter_status)."'";
$bookings=$db->query("SELECT p.*,r.nama ruangan_nama,r.gedung,r.tipe,r.departemen,r.foto ruangan_foto FROM peminjaman p JOIN ruangan r ON p.ruangan_id=r.id WHERE $where ORDER BY p.created_at DESC")->fetch_all(MYSQLI_ASSOC);
$statusMap=['pending'=>['label'=>'Menunggu Validasi','color'=>'#d97706','bg'=>'#fef3c7','icon'=>'⏳'],'disetujui'=>['label'=>'Disetujui','color'=>'#059669','bg'=>'#d1fae5','icon'=>'✅'],'ditolak'=>['label'=>'Ditolak','color'=>'#dc2626','bg'=>'#fee2e2','icon'=>'❌'],'dibatalkan'=>['label'=>'Dibatalkan','color'=>'#7c3aed','bg'=>'#ede9fe','icon'=>'🚫'],'selesai'=>['label'=>'Selesai','color'=>'#6b7280','bg'=>'#f3f4f6','icon'=>'🏁']];
require_once __DIR__.'/../includes/header.php';
?>
<?php if(!empty($_SESSION['flash_success'])): ?>
<div class="alert alert-success mb-3" style="display:flex;align-items:center;gap:8px">✅ <?= e($_SESSION['flash_success']) ?></div>
<?php unset($_SESSION['flash_success']); endif; ?>
<?php if(!empty($_SESSION['flash_error'])): ?>
<div class="alert alert-danger mb-3" style="display:flex;align-items:center;gap:8px">❌ <?= e($_SESSION['flash_error']) ?></div>
<?php unset($_SESSION['flash_error']); endif; ?>
<div class="d-flex align-center justify-between mb-4">
  <div><div class="section-title">📁 Peminjaman Saya</div><div class="section-sub">Riwayat dan status semua pengajuan peminjaman</div></div>
  <a href="<?= base_url('user/booking.php') ?>" class="btn btn-primary">+ Ajukan Baru</a>
</div>
<div class="d-flex gap-2 mb-4 flex-wrap">
  <?php foreach([''=>'📋 Semua','pending'=>'⏳ Menunggu','disetujui'=>'✅ Disetujui','ditolak'=>'❌ Ditolak','dibatalkan'=>'🚫 Dibatalkan','selesai'=>'🏁 Selesai'] as $val=>$label): ?>
  <a href="?status=<?= $val ?>" class="btn btn-sm <?= $filter_status===$val?'btn-primary':'btn-secondary' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>
<?php if(empty($bookings)): ?>
<div class="card"><div class="empty-state"><div class="icon">📋</div><h3>Belum ada pengajuan</h3><a href="<?= base_url('user/booking.php') ?>" class="btn btn-primary mt-3">+ Ajukan Pertama</a></div></div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:14px">
<?php foreach($bookings as $b):
  $sc=$statusMap[$b['status']]??$statusMap['pending'];
  $fotoPath=UPLOAD_RUANGAN.($b['ruangan_foto']??'');
?>
<div class="card" style="cursor:pointer" onclick="openDetail_<?= $b['id'] ?>()">
  <div style="display:flex;align-items:center;gap:16px;padding:16px 20px">
    <div style="width:70px;height:70px;border-radius:12px;overflow:hidden;flex-shrink:0;background:<?= $b['tipe']==='outdoor'?'#ecfdf5':'#eff6ff' ?>;display:flex;align-items:center;justify-content:center;font-size:32px">
      <?php if($b['ruangan_foto']&&file_exists($fotoPath)): ?>
        <img src="<?= base_url('uploads/ruangan/'.e($b['ruangan_foto'])) ?>" style="width:100%;height:100%;object-fit:cover">
      <?php else: ?><?= $b['tipe']==='outdoor'?'🌿':'🏢' ?><?php endif; ?>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-weight:700;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($b['nama_kegiatan']) ?></div>
      <div style="font-size:13px;color:#64748b;margin-top:2px"><?= e($b['ruangan_nama']) ?> &middot; <?= e($b['gedung']) ?></div>
      <div style="font-size:12px;color:#94a3b8;margin-top:2px">📅 <?= e(date('d-m-Y', strtotime($b['tanggal_mulai']))) ?> &middot; ⏰ <?= e($b['jam_mulai']) ?>–<?= e($b['jam_selesai']) ?> &middot; 👥 <?= $b['jumlah_peserta'] ?> peserta</div>
    </div>
    <div style="flex-shrink:0;text-align:right">
      <span style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:20px;font-size:12.5px;font-weight:700;background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>"><?= $sc['icon'] ?> <?= $sc['label'] ?></span>
      <?php if (in_array($b['status'],['ditolak','dibatalkan']) && $b['alasan_penolakan']): ?>
      <div style="font-size:11.5px;color:#dc2626;margin-top:5px;max-width:160px;line-height:1.4;word-break:break-word">
        💬 <?= e(mb_strimwidth($b['alasan_penolakan'], 0, 55, '…')) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<!-- Detail Modal -->
<div id="modal-detail-<?= $b['id'] ?>" class="modal-overlay">
<div class="modal-box"><div class="modal-header"><span class="modal-title">📋 Detail Peminjaman</span><button class="modal-close" onclick="closeDetail_<?= $b['id'] ?>()">✕</button></div>
<div class="modal-body">
  <?php if($b['ruangan_foto']&&file_exists($fotoPath)): ?>
    <img src="<?= base_url('uploads/ruangan/'.e($b['ruangan_foto'])) ?>" style="width:100%;height:180px;object-fit:cover;border-radius:10px;margin-bottom:16px">
  <?php endif; ?>
  <div style="margin-bottom:12px"><?= statusLabel($b['status']) ?></div>
  <?php foreach(['Nama Kegiatan'=>$b['nama_kegiatan'],'Ruangan'=>$b['ruangan_nama'].' — '.$b['gedung'],'Tipe'=>$b['tipe']==='outdoor'?'🌿 Outdoor':'🏢 Indoor','Departemen'=>$b['departemen'],'Tanggal'=>date('d-m-Y',strtotime($b['tanggal_mulai'])).' s/d '.date('d-m-Y',strtotime($b['tanggal_selesai'])),'Waktu'=>$b['jam_mulai'].' – '.$b['jam_selesai'],'Peserta'=>$b['jumlah_peserta'].' orang','Keterangan'=>($b['keterangan']??'—'),'File Surat'=>$b['file_surat'],'Diajukan'=>date('d-m-Y H:i',strtotime($b['created_at']))] as $k=>$v): ?>
  <div class="detail-row"><span class="detail-label"><?= $k ?></span><span class="detail-value"><?= e($v) ?></span></div>
  <?php endforeach; ?>
  <?php if($b['file_surat']): ?><div class="detail-row"><span class="detail-label">Download</span><span class="detail-value"><a href="<?= base_url('uploads/surat/'.e($b['file_surat'])) ?>" target="_blank" class="btn btn-outline btn-sm">📎 Lihat Surat</a></span></div><?php endif; ?>
  <?php if($b['validated_at']): ?>
  <div class="alert alert-<?= $b['status']==='disetujui'?'success':'danger' ?> mt-3">
    <?= $b['status']==='disetujui'?'✅ Disetujui':'❌ Ditolak' ?> pada <?= date('d-m-Y H:i',strtotime($b['validated_at'])) ?>
  </div>
  <?php endif; ?>
  <?php if(in_array($b['status'],['ditolak','dibatalkan']) && $b['alasan_penolakan']): ?>
  <div class="alert alert-danger mt-2" style="border-left:4px solid #dc2626">
    <strong>❌ Alasan Penolakan:</strong><br>
    <?= e($b['alasan_penolakan']) ?>
  </div>
  <?php endif; ?>
  <?php if($b['status']==='pending'): ?>
  <div class="mt-3" style="border-top:1px solid #f1f5f9;padding-top:14px">
    <button onclick="confirmCancel_<?= $b['id'] ?>()" class="btn btn-sm" style="background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;width:100%">🚫 Batalkan Pengajuan</button>
  </div>
  <?php endif; ?>
</div></div></div>
<!-- Modal Konfirmasi Batal -->
<?php if($b['status']==='pending'): ?>
<div id="modal-cancel-<?= $b['id'] ?>" class="modal-overlay">
  <div class="modal-box" style="max-width:380px">
    <div class="modal-header"><span class="modal-title">⚠️ Batalkan Pengajuan?</span><button class="modal-close" onclick="closeCancel_<?= $b['id'] ?>()">✕</button></div>
    <div class="modal-body">
      <p style="color:#374151;margin-bottom:4px">Apakah kamu yakin ingin membatalkan pengajuan ini?</p>
      <p style="font-weight:600;color:#111827;margin-bottom:16px">"<?= e($b['nama_kegiatan']) ?>"</p>
      <p style="font-size:13px;color:#6b7280;margin-bottom:20px">Pengajuan yang sudah dibatalkan tidak bisa dipulihkan kembali.</p>
      <div style="display:flex;gap:8px">
        <button onclick="closeCancel_<?= $b['id'] ?>()" class="btn btn-secondary" style="flex:1">Tidak, Kembali</button>
        <form method="POST" action="<?= base_url('user/cancel_booking.php') ?>" style="flex:1">
          <input type="hidden" name="id" value="<?= $b['id'] ?>">
          <button type="submit" class="btn" style="width:100%;background:#dc2626;color:#fff;border:none">Ya, Batalkan</button>
        </form>
      </div>
    </div>
  </div>
</div>
<script>
function confirmCancel_<?= $b['id'] ?>(){document.getElementById('modal-cancel-<?= $b['id'] ?>').classList.add('show');}
function closeCancel_<?= $b['id'] ?>(){document.getElementById('modal-cancel-<?= $b['id'] ?>').classList.remove('show');}
</script>
<?php endif; ?>
<script>function openDetail_<?= $b['id'] ?>(){document.getElementById('modal-detail-<?= $b['id'] ?>').classList.add('show');}function closeDetail_<?= $b['id'] ?>(){document.getElementById('modal-detail-<?= $b['id'] ?>').classList.remove('show');}</script>
<?php endforeach; ?>
</div>
<?php endif; ?>
<script>document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');}));</script>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
