<?php
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('admin_utama','admin_departemen','admin_dmf');
$page_title = 'Peminjaman Eksternal';
$db = db();

/* ── Helpers ── */
function extStatusLabel(string $s): string {
    return match($s) {
        'pending'    => '<span class="badge badge-warning">⏳ Menunggu</span>',
        'disetujui'  => '<span class="badge badge-success">✅ Disetujui</span>',
        'ditolak'    => '<span class="badge badge-danger">❌ Ditolak</span>',
        default      => '<span class="badge">' . e($s) . '</span>',
    };
}
function jenisLabel(string $j): string {
    return match($j) {
        'umum'          => '🏘️ Masyarakat Umum',
        'instansi_luar' => '🏢 Instansi Luar',
        default         => e($j),
    };
}

/* ── Pastikan kolom file_surat ada ── */
$db->query("ALTER TABLE peminjaman_eksternal ADD COLUMN IF NOT EXISTS file_surat VARCHAR(255) DEFAULT NULL");

/* ── Migrasi jenis_pemohon → hanya 2 pilihan ──
   Dosen & Tendik dihapus dari opsi; data lama dipetakan ke 'umum' SEBELUM
   ENUM dipersempit (pola sama seperti migrasi status di atas, agar nilai
   lama tidak ter-blank). Idempotent + try/catch. */
try {
    $jRes = $db->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='peminjaman_eksternal' AND COLUMN_NAME='jenis_pemohon'"
    );
    $jType = $jRes ? ($jRes->fetch_assoc()['COLUMN_TYPE'] ?? '') : '';
    if ($jType && (str_contains($jType, "'dosen'") || str_contains($jType, "'tendik'"))) {
        // 1) Perluas dulu bila ENUM belum memuat 'umum'/'instansi_luar' (jaga-jaga)
        if (!str_contains($jType, "'umum'") || !str_contains($jType, "'instansi_luar'")) {
            $db->query("ALTER TABLE peminjaman_eksternal
                        MODIFY COLUMN jenis_pemohon
                        ENUM('umum','dosen','tendik','instansi_luar')
                        NOT NULL DEFAULT 'umum'");
        }
        // 2) Petakan Dosen & Tendik → Masyarakat Umum
        $db->query("UPDATE peminjaman_eksternal SET jenis_pemohon='umum' WHERE jenis_pemohon IN('dosen','tendik')");
        // 3) Persempit ENUM ke dua pilihan final
        $db->query("ALTER TABLE peminjaman_eksternal
                    MODIFY COLUMN jenis_pemohon ENUM('umum','instansi_luar')
                    NOT NULL DEFAULT 'umum'");
    }
} catch (\Throwable $e) { /* abaikan — migrasi opsional */ }

/* ── Migrasi status peminjaman eksternal → hanya 3 status ──
   Status final: pending (Menunggu), disetujui, ditolak — selaras dengan alur
   validasi & penilaian prioritas konflik jadwal (K2 Status Pengajuan).
   Status lama dipetakan LEBIH DULU sebelum ENUM dipersempit; bila urutannya
   dibalik, nilai lama akan ter-blank menjadi ''. Idempotent + try/catch agar
   galat migrasi tidak menjatuhkan halaman (hosting dgn hak akses terbatas). */
try {
    $colRes = $db->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='peminjaman_eksternal' AND COLUMN_NAME='status'"
    );
    $colType = $colRes ? ($colRes->fetch_assoc()['COLUMN_TYPE'] ?? '') : '';
    if ($colType && str_contains($colType, "'aktif'")) {
        // 1) Bila ENUM belum memuat status baru, perluas dulu agar nilai baru bisa ditulis
        if (!str_contains($colType, "'pending'")) {
            $db->query("ALTER TABLE peminjaman_eksternal
                        MODIFY COLUMN status
                        ENUM('pending','disetujui','ditolak','aktif','selesai','dibatalkan')
                        NOT NULL DEFAULT 'pending'");
        }
        // 2) Petakan status lama → status baru (data lama tetap bermakna)
        $db->query("UPDATE peminjaman_eksternal SET status='disetujui' WHERE status IN('aktif','selesai')");
        $db->query("UPDATE peminjaman_eksternal SET status='ditolak'   WHERE status='dibatalkan'");
        $db->query("UPDATE peminjaman_eksternal SET status='pending'   WHERE status='' OR status IS NULL");
        // 3) Persempit ENUM ke tiga status final
        $db->query("ALTER TABLE peminjaman_eksternal
                    MODIFY COLUMN status ENUM('pending','disetujui','ditolak')
                    NOT NULL DEFAULT 'pending'");
    }
} catch (\Throwable $e) { /* abaikan — migrasi opsional */ }

/* ── Helper upload surat ── */
function uploadSuratFile(array $file, ?string $oldFile = null): array {
    // return ['ok'=>bool, 'filename'=>string, 'error'=>string]
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','pdf','docx'];
    $err = validateUpload($file, $allowed);
    if ($err !== '')
        return ['ok'=>false,'filename'=>'','error'=>$err];
    $newName = 'ext_'.time().'_'.substr(md5(uniqid()),0,8).'.'.$ext;
    if (!@move_uploaded_file($file['tmp_name'], UPLOAD_SURAT.$newName))
        return ['ok'=>false,'filename'=>'','error'=>'Gagal menyimpan file ke server.'];
    // Hapus file lama jika ada
    if ($oldFile && file_exists(UPLOAD_SURAT.$oldFile)) @unlink(UPLOAD_SURAT.$oldFile);
    return ['ok'=>true,'filename'=>$newName,'error'=>''];
}

/* ── POST handler ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id            = (int)($_POST['id'] ?? 0);
        $ruangan_id    = (int)($_POST['ruangan_id'] ?? 0);
        $nama_pemohon  = trim($_POST['nama_pemohon'] ?? '');
        $instansi      = trim($_POST['instansi'] ?? '');
        $kontak        = trim($_POST['kontak'] ?? '');
        $jenis         = in_array($_POST['jenis_pemohon'] ?? '', ['umum','instansi_luar'], true)
                         ? $_POST['jenis_pemohon'] : 'umum';
        $nama_kegiatan = trim($_POST['nama_kegiatan'] ?? '');
        $tgl_mulai     = $_POST['tanggal_mulai'] ?? '';
        $tgl_selesai   = $_POST['tanggal_selesai'] ?? '';
        $jam_mulai     = $_POST['jam_mulai'] ?? '';
        $jam_selesai   = $_POST['jam_selesai'] ?? '';
        $peserta       = max(1, (int)($_POST['jumlah_peserta'] ?? 1));
        $keterangan    = trim($_POST['keterangan'] ?? '');
        $status        = in_array($_POST['status'] ?? '', ['pending','disetujui','ditolak'], true)
                         ? $_POST['status'] : 'pending';
        $surat_delete  = ($_POST['surat_delete'] ?? '') === '1';

        // Validate room access
        $rStmt = $db->prepare("SELECT id,departemen,tipe FROM ruangan WHERE id=?");
        $rStmt->bind_param('i', $ruangan_id); $rStmt->execute();
        $ruangan = $rStmt->get_result()->fetch_assoc();

        if (!$ruangan || !canValidate($user, $ruangan)) {
            flash('error', 'Ruangan tidak valid atau Anda tidak berwenang mengelola ruangan ini.');
            redirect(base_url('admin/ext_bookings.php'));
        }

        /* ── Proses file upload ── */
        $file_surat_new = null; // null = tidak berubah, '' = hapus, 'nama.ext' = ganti
        $hasNewFile = !empty($_FILES['file_surat']['name']) && ($_FILES['file_surat']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

        if ($hasNewFile) {
            // Ambil file lama (edit) untuk diganti
            $oldSurat = '';
            if ($action === 'edit' && $id) {
                $oldR = $db->query("SELECT file_surat FROM peminjaman_eksternal WHERE id=$id");
                $oldRow = $oldR ? $oldR->fetch_assoc() : null;
                $oldSurat = $oldRow['file_surat'] ?? '';
            }
            $upResult = uploadSuratFile($_FILES['file_surat'], $oldSurat ?: null);
            if (!$upResult['ok']) {
                flash('error', $upResult['error']);
                redirect(base_url('admin/ext_bookings.php'));
            }
            $file_surat_new = $upResult['filename'];
        } elseif ($surat_delete && $action === 'edit' && $id) {
            // Hapus file tanpa upload baru
            $oldR = $db->query("SELECT file_surat FROM peminjaman_eksternal WHERE id=$id");
            $oldRow = $oldR ? $oldR->fetch_assoc() : null;
            if ($oldRow && $oldRow['file_surat'] && file_exists(UPLOAD_SURAT.$oldRow['file_surat']))
                @unlink(UPLOAD_SURAT.$oldRow['file_surat']);
            $file_surat_new = '';
        }

        if ($action === 'add') {
            $fileSuratVal = $file_surat_new ?? '';
            $s = $db->prepare(
                "INSERT INTO peminjaman_eksternal
                 (ruangan_id,nama_pemohon,instansi,kontak,jenis_pemohon,nama_kegiatan,
                  tanggal_mulai,tanggal_selesai,jam_mulai,jam_selesai,jumlah_peserta,
                  keterangan,status,file_surat,dibuat_oleh)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $s->bind_param('issssssssissssi',
                $ruangan_id,$nama_pemohon,$instansi,$kontak,$jenis,$nama_kegiatan,
                $tgl_mulai,$tgl_selesai,$jam_mulai,$jam_selesai,$peserta,
                $keterangan,$status,$fileSuratVal,$user['id']);
            $s->execute()
                ? flash('success','Peminjaman eksternal berhasil ditambahkan.')
                : flash('error','Gagal menyimpan: '.$db->error);
        } else {
            // Build UPDATE query — hanya update file_surat jika berubah
            if ($file_surat_new !== null) {
                $s = $db->prepare(
                    "UPDATE peminjaman_eksternal SET
                     ruangan_id=?,nama_pemohon=?,instansi=?,kontak=?,jenis_pemohon=?,
                     nama_kegiatan=?,tanggal_mulai=?,tanggal_selesai=?,jam_mulai=?,
                     jam_selesai=?,jumlah_peserta=?,keterangan=?,status=?,file_surat=?
                     WHERE id=?"
                );
                $s->bind_param('issssssssissssi',
                    $ruangan_id,$nama_pemohon,$instansi,$kontak,$jenis,$nama_kegiatan,
                    $tgl_mulai,$tgl_selesai,$jam_mulai,$jam_selesai,$peserta,
                    $keterangan,$status,$file_surat_new,$id);
            } else {
                $s = $db->prepare(
                    "UPDATE peminjaman_eksternal SET
                     ruangan_id=?,nama_pemohon=?,instansi=?,kontak=?,jenis_pemohon=?,
                     nama_kegiatan=?,tanggal_mulai=?,tanggal_selesai=?,jam_mulai=?,
                     jam_selesai=?,jumlah_peserta=?,keterangan=?,status=?
                     WHERE id=?"
                );
                $s->bind_param('isssssssssissi',
                    $ruangan_id,$nama_pemohon,$instansi,$kontak,$jenis,$nama_kegiatan,
                    $tgl_mulai,$tgl_selesai,$jam_mulai,$jam_selesai,$peserta,
                    $keterangan,$status,$id);
            }
            $s->execute()
                ? flash('success','Data berhasil diperbarui.')
                : flash('error','Gagal memperbarui: '.$db->error);
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Hapus file surat jika ada
        $oldR = $db->query("SELECT file_surat FROM peminjaman_eksternal WHERE id=$id");
        $oldRow = $oldR ? $oldR->fetch_assoc() : null;
        if ($oldRow && $oldRow['file_surat'] && file_exists(UPLOAD_SURAT.$oldRow['file_surat']))
            @unlink(UPLOAD_SURAT.$oldRow['file_surat']);
        $db->query("DELETE FROM peminjaman_eksternal WHERE id={$id}");
        flash('success','Data berhasil dihapus.');
    }
    redirect(base_url('admin/ext_bookings.php'));
}

/* ── Filters ── */
$filter_status = $_GET['status'] ?? '';
$filter_jenis  = $_GET['jenis']  ?? '';
$filter_search = trim($_GET['q'] ?? '');

/* ── Rooms dropdown (filtered by role) ── */
$rWhere = [];
if ($user['role'] === 'admin_departemen')
    $rWhere[] = "departemen='{$db->real_escape_string($user['departemen'])}'";
if ($user['role'] === 'admin_dmf')
    $rWhere[] = "tipe='outdoor'";
$rooms = $db->query(
    "SELECT id,nama,gedung,departemen,tipe FROM ruangan"
    .($rWhere ? " WHERE ".implode(' AND ',$rWhere) : '')
    ." ORDER BY departemen,nama"
)->fetch_all(MYSQLI_ASSOC);

/* ── Booking list query ── */
$where=[]; $params=[]; $types='';
if ($user['role']==='admin_departemen') { $where[]="r.departemen=?"; $params[]=$user['departemen']; $types.='s'; }
if ($user['role']==='admin_dmf')        { $where[]="r.tipe='outdoor'"; }
if ($filter_status) { $where[]="pe.status=?"; $params[]=$filter_status; $types.='s'; }
if ($filter_jenis)  { $where[]="pe.jenis_pemohon=?"; $params[]=$filter_jenis; $types.='s'; }
if ($filter_search) {
    $like="%{$filter_search}%";
    $where[]="(pe.nama_kegiatan LIKE ? OR pe.nama_pemohon LIKE ? OR pe.instansi LIKE ?)";
    $params[]=$like; $params[]=$like; $params[]=$like; $types.='sss';
}
$sql = "SELECT pe.*,COALESCE(pe.file_surat,'') file_surat,r.nama ruangan_nama,r.gedung,r.departemen,r.tipe,u.nama dibuat_nama
        FROM peminjaman_eksternal pe
        JOIN ruangan r ON pe.ruangan_id=r.id
        JOIN users u ON pe.dibuat_oleh=u.id"
     .($where ? " WHERE ".implode(' AND ',$where) : '')
     ." ORDER BY pe.tanggal_mulai DESC, pe.created_at DESC";
$stmt = $db->prepare($sql);
if ($params) $stmt->bind_param($types,...$params);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-center justify-between mb-4">
  <div>
    <div class="section-title">🏛️ Peminjaman Eksternal</div>
    <div class="section-sub">Peminjaman oleh pihak luar (Masyarakat umum dan Instansi luar)</div>
  </div>
  <button class="btn btn-primary" onclick="openModal('modal-add')">＋ Tambah Peminjaman</button>
</div>

<!-- Filter bar -->
<div class="filter-bar">
  <form method="get" class="d-flex gap-2 flex-wrap w-100">
    <input name="q" class="form-control search-input" placeholder="🔍 Cari kegiatan, nama, instansi..."
           value="<?= e($filter_search) ?>">
    <select name="status" class="form-control w-auto" onchange="this.closest('form').submit()">
      <option value="">Semua Status</option>
      <option value="pending"    <?= $filter_status==='pending'?'selected':'' ?>>⏳ Menunggu</option>
      <option value="disetujui"  <?= $filter_status==='disetujui'?'selected':'' ?>>✅ Disetujui</option>
      <option value="ditolak"    <?= $filter_status==='ditolak'?'selected':'' ?>>❌ Ditolak</option>
    </select>
    <select name="jenis" class="form-control w-auto" onchange="this.closest('form').submit()">
      <option value="">Semua Jenis</option>
      <option value="umum"          <?= $filter_jenis==='umum'?'selected':'' ?>>🏘️ Masyarakat Umum</option>
      <option value="instansi_luar" <?= $filter_jenis==='instansi_luar'?'selected':'' ?>>🏢 Instansi Luar</option>
    </select>
    <a href="<?= base_url('admin/ext_bookings.php') ?>" class="btn btn-secondary btn-sm">Reset</a>
  </form>
</div>

<!-- Table -->
<div class="card">
<?php if (empty($bookings)): ?>
  <div class="empty-state"><div class="icon">🏛️</div><h3>Belum ada peminjaman eksternal</h3><p>Klik "+ Tambah Peminjaman" untuk menambahkan data.</p></div>
<?php else: ?>
<div class="table-responsive">
<table class="table">
  <thead>
    <tr>
      <th>#</th><th>Pemohon</th><th>Kegiatan</th><th>Ruangan</th>
      <th>Tanggal &amp; Waktu</th><th>Peserta</th><th>Status</th><th>Aksi</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($bookings as $i => $b): ?>
  <tr>
    <td class="text-muted fs-sm"><?= $i+1 ?></td>
    <td>
      <div class="fw-semibold"><?= e($b['nama_pemohon']) ?></div>
      <div class="fs-xs text-muted"><?= jenisLabel($b['jenis_pemohon']) ?></div>
      <?php if ($b['instansi']): ?><div class="fs-xs text-muted"><?= e($b['instansi']) ?></div><?php endif; ?>
      <?php if ($b['kontak']):   ?><div class="fs-xs text-muted">📞 <?= e($b['kontak']) ?></div><?php endif; ?>
    </td>
    <td><div class="fw-semibold" style="max-width:200px"><?= e($b['nama_kegiatan']) ?></div></td>
    <td>
      <div><?= e($b['ruangan_nama']) ?></div>
      <div class="fs-xs text-muted"><?= e($b['gedung']) ?></div>
      <span class="badge <?= $b['tipe']==='outdoor'?'badge-outdoor':'badge-indoor' ?>" style="font-size:10.5px;margin-top:4px">
        <?= $b['tipe']==='outdoor'?'🌿 Outdoor':'🏢 Indoor' ?>
      </span>
    </td>
    <td>
      <div><?= e(date('d-m-Y',strtotime($b['tanggal_mulai']))) ?><?= $b['tanggal_selesai']!==$b['tanggal_mulai']?' – '.e(date('d-m-Y',strtotime($b['tanggal_selesai']))):'' ?></div>
      <div class="fs-xs text-muted"><?= e(substr($b['jam_mulai'],0,5)) ?> – <?= e(substr($b['jam_selesai'],0,5)) ?></div>
    </td>
    <td class="text-center"><?= $b['jumlah_peserta'] ?> org</td>
    <td><?= extStatusLabel($b['status']) ?></td>
    <td>
      <div class="d-flex gap-1 flex-wrap">
        <button class="btn btn-primary btn-sm" onclick="openDetail(<?= $b['id'] ?>)">Detail</button>
        <button class="btn btn-secondary btn-sm" onclick="openEdit(<?= $b['id'] ?>)">Edit</button>
        <form method="post" style="display:inline" onsubmit="return confirm('Hapus data ini?')">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $b['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
        </form>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
</div>

<!-- ═══ MODAL ADD ═══ -->
<div id="modal-add" class="modal-overlay">
  <div class="modal-box" style="max-width:620px">
    <div class="modal-header">
      <span class="modal-title">＋ Tambah Peminjaman Eksternal</span>
      <button class="modal-close" onclick="closeModal('modal-add')">✕</button>
    </div>
    <div class="modal-body">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add">
        <?= extBookingForm($rooms, []) ?>
        <div class="d-flex gap-2 mt-3">
          <button type="submit" class="btn btn-primary">💾 Simpan</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-add')">Batal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ MODAL EDIT ═══ -->
<div id="modal-edit" class="modal-overlay">
  <div class="modal-box" style="max-width:620px">
    <div class="modal-header">
      <span class="modal-title">✏️ Edit Peminjaman Eksternal</span>
      <button class="modal-close" onclick="closeModal('modal-edit')">✕</button>
    </div>
    <div class="modal-body">
      <form method="post" id="edit-form" enctype="multipart/form-data">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="surat_delete" value="0" id="surat-delete-flag">
        <?= extBookingForm($rooms, [], true) ?>
        <div class="d-flex gap-2 mt-3">
          <button type="submit" class="btn btn-primary">💾 Simpan Perubahan</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit')">Batal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ MODALS DETAIL ═══ -->
<?php foreach ($bookings as $b): ?>
<div id="detail-<?= $b['id'] ?>" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title">🏛️ Detail Peminjaman #<?= $b['id'] ?></span>
      <button class="modal-close" onclick="closeModal('detail-<?= $b['id'] ?>')">✕</button>
    </div>
    <div class="modal-body">
      <div style="margin-bottom:14px"><?= extStatusLabel($b['status']) ?></div>
      <?php
      $rows = [
        'Nama Pemohon'  => $b['nama_pemohon'],
        'Jenis'         => jenisLabel($b['jenis_pemohon']),
        'Instansi/Asal' => $b['instansi'] ?: '-',
        'Kontak'        => $b['kontak'] ?: '-',
        'Nama Kegiatan' => $b['nama_kegiatan'],
        'Ruangan'       => $b['ruangan_nama'].' — '.$b['gedung'],
        'Departemen'    => $b['departemen'],
        'Tipe'          => $b['tipe']==='outdoor'?'🌿 Outdoor':'🏢 Indoor',
        'Tanggal'       => date('d-m-Y',strtotime($b['tanggal_mulai'])).($b['tanggal_selesai']!==$b['tanggal_mulai']?' s/d '.date('d-m-Y',strtotime($b['tanggal_selesai'])):''),
        'Waktu'         => substr($b['jam_mulai'],0,5).' – '.substr($b['jam_selesai'],0,5),
        'Jumlah Peserta'=> $b['jumlah_peserta'].' orang',
        'Keterangan'    => $b['keterangan'] ?: '-',
        'Dibuat oleh'   => $b['dibuat_nama'],
        'Dibuat pada'   => date('d-m-Y H:i', strtotime($b['created_at'])),
      ];
      foreach ($rows as $k => $v): ?>
      <div class="detail-row">
        <span class="detail-label"><?= $k ?></span>
        <span class="detail-value"><?= e(strip_tags($v)) === $v ? e($v) : $v ?></span>
      </div>
      <?php endforeach; ?>
      <?php if (!empty($b['file_surat'])): ?>
      <?php
        $_ext  = strtolower(pathinfo($b['file_surat'], PATHINFO_EXTENSION));
        $_icon = match($_ext) {
            'pdf'  => '📄', 'docx' => '📝',
            'jpg','jpeg','png' => '🖼️', default => '📎'
        };
        $_url = base_url('uploads/surat/'.rawurlencode($b['file_surat']));
      ?>
      <div class="detail-row">
        <span class="detail-label">Surat</span>
        <span class="detail-value">
          <a href="<?= $_url ?>" target="_blank" rel="noopener"
             style="display:inline-flex;align-items:center;gap:6px;background:#f0fdf4;border:1.5px solid #86efac;color:#16a34a;padding:6px 14px;border-radius:8px;font-weight:600;font-size:13px;text-decoration:none">
            <?= $_icon ?> Lihat / Unduh Surat
            <span style="font-size:11px;color:#64748b;font-weight:400">.<?= $_ext ?></span>
          </a>
        </span>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php
/* ── Inline helper — render shared form fields ── */
function extBookingForm(array $rooms, array $d, bool $showStatus = false): string {
    /* ── Room picker ── */
    $selRuangan  = isset($d['ruangan_id']) ? (int)$d['ruangan_id'] : 0;
    $selRoomInfo = null;
    foreach ($rooms as $r) { if ((int)$r['id'] === $selRuangan) { $selRoomInfo = $r; break; } }
    $roomsJson = htmlspecialchars(json_encode(array_values($rooms)), ENT_QUOTES, 'UTF-8');
    $srchDisp  = $selRoomInfo ? 'none' : 'flex';
    $selDisp   = $selRoomInfo ? 'flex' : 'none';
    $selName   = $selRoomInfo ? e($selRoomInfo['nama']).' — '.e($selRoomInfo['gedung']) : '';
    $selDept   = $selRoomInfo ? e($selRoomInfo['departemen']) : '';

    $html  = '<div class="form-group"><label>Ruangan *</label>';
    $html .= '<div class="ext-room-picker" data-rooms="'.$roomsJson.'" style="position:relative">';
    /* Search bar */
    $html .= '<div class="ext-rp-search" style="display:'.$srchDisp.';align-items:center;gap:8px;border:2px solid #e2e8f0;border-radius:10px;padding:0 12px;background:#fff;min-height:44px;cursor:text;transition:border-color .2s,box-shadow .2s">';
    $html .= '<span style="color:#94a3b8;flex-shrink:0;font-size:15px">🔍</span>';
    $html .= '<input type="text" class="ext-rp-input" placeholder="Cari nama ruangan, gedung, departemen..." style="flex:1;border:none;outline:none;font-size:14px;background:transparent;padding:11px 0;font-family:inherit" autocomplete="off">';
    $html .= '<button type="button" class="ext-rp-clear" style="display:none;background:none;border:none;cursor:pointer;color:#94a3b8;font-size:14px;padding:3px 6px;line-height:1" title="Hapus pencarian">✕</button>';
    $html .= '</div>';
    /* Dropdown */
    $html .= '<div class="ext-rp-dropdown" style="display:none;position:absolute;left:0;right:0;top:calc(100% + 4px);background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 36px rgba(0,0,0,.13);z-index:800;max-height:260px;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch"></div>';
    /* Selected state */
    $html .= '<div class="ext-rp-selected" style="display:'.$selDisp.';align-items:center;gap:12px;padding:10px 14px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px">';
    $html .= '<div style="flex:1"><div class="ext-rp-sel-name" style="font-weight:700;font-size:14px;color:#166534">'.$selName.'</div>';
    $html .= '<div class="ext-rp-sel-dept" style="font-size:12px;color:#15803d">'.$selDept.'</div></div>';
    $html .= '<button type="button" class="ext-rp-change" style="background:#fff;border:1.5px solid #22c55e;color:#16a34a;padding:6px 12px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;white-space:nowrap;flex-shrink:0">🔄 Ganti</button>';
    $html .= '</div>';
    $html .= '<input type="hidden" name="ruangan_id" value="'.($selRuangan ?: '').'" required>';
    $html .= '</div></div>';

    /* ── Jenis Pemohon ── */
    $jenis = ['umum'=>'Masyarakat Umum','instansi_luar'=>'Instansi Luar'];
    $jOpts = '';
    foreach ($jenis as $v => $l) {
        $sel = isset($d['jenis_pemohon']) && $d['jenis_pemohon']===$v ? ' selected' : '';
        $jOpts .= "<option value=\"{$v}\"{$sel}>".e($l)."</option>";
    }
    $statuses = ['pending'=>'⏳ Menunggu','disetujui'=>'✅ Disetujui','ditolak'=>'❌ Ditolak'];
    $sOpts = '';
    foreach ($statuses as $v => $l) {
        $sel = (isset($d['status']) ? $d['status']===$v : $v==='pending') ? ' selected' : '';
        $sOpts .= "<option value=\"{$v}\"{$sel}>".e($l)."</option>";
    }
    $vNama    = isset($d['nama_pemohon'])   ? e($d['nama_pemohon'])              : '';
    $vInst    = isset($d['instansi'])       ? e($d['instansi'])                  : '';
    $vKontak  = isset($d['kontak'])         ? e($d['kontak'])                    : '';
    $vKegiatan= isset($d['nama_kegiatan'])  ? e($d['nama_kegiatan'])             : '';
    $vTglMul  = isset($d['tanggal_mulai'])  ? e($d['tanggal_mulai'])             : '';
    $vTglSel  = isset($d['tanggal_selesai'])? e($d['tanggal_selesai'])           : '';
    $vJamMul  = isset($d['jam_mulai'])      ? e(substr($d['jam_mulai'],0,5))     : '';
    $vJamSel  = isset($d['jam_selesai'])    ? e(substr($d['jam_selesai'],0,5))   : '';
    $vPeserta = isset($d['jumlah_peserta']) ? (int)$d['jumlah_peserta']          : 1;
    $vKet     = isset($d['keterangan'])     ? e($d['keterangan'])                : '';

    $html .= '<div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
    $html .= '<div class="form-group"><label>Nama Pemohon *</label><input type="text" name="nama_pemohon" class="form-control" value="'.$vNama.'" required></div>';
    $html .= '<div class="form-group"><label>Jenis Pemohon *</label><select name="jenis_pemohon" class="form-control">'.$jOpts.'</select></div>';
    $html .= '</div>';
    $html .= '<div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
    $html .= '<div class="form-group"><label>Instansi / Asal</label><input type="text" name="instansi" class="form-control" value="'.$vInst.'"></div>';
    $html .= '<div class="form-group"><label>Kontak (HP / Email)</label><input type="text" name="kontak" class="form-control" value="'.$vKontak.'"></div>';
    $html .= '</div>';
    $html .= '<div class="form-group"><label>Nama Kegiatan *</label><input type="text" name="nama_kegiatan" class="form-control" value="'.$vKegiatan.'" required></div>';
    $html .= '<div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
    $html .= '<div class="form-group"><label>Tanggal Mulai *</label><input type="date" name="tanggal_mulai" class="form-control" value="'.$vTglMul.'" required></div>';
    $html .= '<div class="form-group"><label>Tanggal Selesai *</label><input type="date" name="tanggal_selesai" class="form-control" value="'.$vTglSel.'" required></div>';
    $html .= '</div>';
    $html .= '<div class="form-row" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">';
    $html .= '<div class="form-group"><label>Jam Mulai *</label><input type="time" name="jam_mulai" class="form-control" value="'.$vJamMul.'" required></div>';
    $html .= '<div class="form-group"><label>Jam Selesai *</label><input type="time" name="jam_selesai" class="form-control" value="'.$vJamSel.'" required></div>';
    $html .= '<div class="form-group"><label>Jumlah Peserta *</label><input type="number" name="jumlah_peserta" class="form-control" value="'.$vPeserta.'" min="1" required></div>';
    $html .= '</div>';

    /* ── Deteksi ketersediaan ruangan (live, via check_availability.php) ── */
    $html .= '<div class="ext-avail" style="display:none;border-radius:10px;padding:11px 13px;margin-bottom:14px;font-size:12.5px;line-height:1.6"></div>';

    $html .= '<div class="form-group"><label>Keterangan</label><textarea name="keterangan" class="form-control" rows="2">'.$vKet.'</textarea></div>';

    /* ── Upload Surat (opsional) ── */
    $vSurat = isset($d['file_surat']) ? $d['file_surat'] : '';
    $extSurat = $vSurat ? strtolower(pathinfo($vSurat, PATHINFO_EXTENSION)) : '';
    $iconSurat = match($extSurat) {
        'pdf'=>'📄','docx'=>'📝','jpg','jpeg','png'=>'🖼️',default=>''
    };
    $html .= '<div class="form-group">';
    $html .= '<label style="display:flex;align-items:center;gap:6px">📎 Surat / Dokumen <span style="font-size:11.5px;color:#94a3b8;font-weight:400">(opsional · JPG, PNG, PDF, DOCX · maks 5 MB)</span></label>';
    if ($vSurat) {
        // Ada file lama — tampilkan info + opsi hapus
        $html .= '<div class="ext-surat-preview" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;margin-bottom:8px">';
        $html .= '<span style="font-size:20px">'.$iconSurat.'</span>';
        $html .= '<span style="flex:1;font-size:13px;font-weight:600;color:#166534;word-break:break-all">'.e($vSurat).'</span>';
        $html .= '<button type="button" onclick="extRemoveSurat(this)" style="background:#fee2e2;border:1.5px solid #fca5a5;color:#dc2626;padding:4px 10px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap">🗑️ Hapus</button>';
        $html .= '</div>';
        $html .= '<input type="hidden" class="ext-surat-old" value="'.e($vSurat).'">';
    }
    $html .= '<div class="ext-surat-upload-wrap">';
    $html .= '<label class="ext-surat-dropzone" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;border:2px dashed #cbd5e1;border-radius:10px;padding:20px;cursor:pointer;background:#fafafa;transition:border-color .2s,background .2s;text-align:center">';
    $html .= '<span style="font-size:26px">📂</span>';
    $html .= '<span style="font-size:13px;font-weight:600;color:#475569">Klik atau seret file ke sini</span>';
    $html .= '<span style="font-size:11.5px;color:#94a3b8">JPG · PNG · PDF · DOCX</span>';
    $html .= '<input type="file" name="file_surat" accept=".jpg,.jpeg,.png,.pdf,.docx" style="display:none" onchange="extSuratPreview(this)">';
    $html .= '</label>';
    $html .= '<div class="ext-surat-chosen" style="display:none;margin-top:8px;padding:9px 12px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:9px;display:none;align-items:center;gap:8px">';
    $html .= '<span class="ext-surat-chosen-icon" style="font-size:18px"></span>';
    $html .= '<span class="ext-surat-chosen-name" style="flex:1;font-size:13px;font-weight:600;color:#1e293b;word-break:break-all"></span>';
    $html .= '<button type="button" onclick="extSuratClear(this)" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:14px;padding:2px 6px">✕</button>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';

    if ($showStatus) {
        $html .= '<div class="form-group"><label>Status</label><select name="status" class="form-control">'.$sOpts.'</select></div>';
    }
    return $html;
}
?>

<script>
const extData = <?= json_encode(array_values($bookings)) ?>;

/* ══════════════════════════════════════════
   EXT ROOM PICKER
══════════════════════════════════════════ */
function escExtH(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function extRpRender(picker, q) {
  const rooms   = JSON.parse(picker.dataset.rooms || '[]');
  const drop    = picker.querySelector('.ext-rp-dropdown');
  const ql      = (q || '').trim().toLowerCase();
  const filtered = rooms.filter(r =>
    !ql || r.nama.toLowerCase().includes(ql) ||
    r.gedung.toLowerCase().includes(ql) ||
    r.departemen.toLowerCase().includes(ql)
  );
  if (!filtered.length) {
    drop.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:13px">🔍 Ruangan tidak ditemukan</div>';
    return;
  }
  const indoor  = filtered.filter(r => r.tipe !== 'outdoor');
  const outdoor = filtered.filter(r => r.tipe === 'outdoor');
  let html = '';
  const grp = (label, arr) => {
    if (!arr.length) return;
    html += `<div style="padding:6px 12px 4px;font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;background:#fff;border-bottom:1px solid #f1f5f9;position:sticky;top:0;z-index:1">${label}</div>`;
    arr.forEach(r => {
      html += `<div class="ext-rp-item" data-id="${r.id}" data-nama="${escExtH(r.nama)}" data-gedung="${escExtH(r.gedung)}" data-dept="${escExtH(r.departemen)}"
        style="padding:9px 14px;cursor:pointer;border-bottom:1px solid #f8fafc;display:flex;gap:10px;align-items:flex-start;transition:background .1s">
        <div style="font-size:18px;flex-shrink:0;margin-top:2px">${r.tipe==='outdoor'?'🌿':'🏢'}</div>
        <div>
          <div style="font-weight:700;font-size:13.5px;color:#1e293b">${escExtH(r.nama)}</div>
          <div style="font-size:12px;color:#64748b">📍 ${escExtH(r.gedung)} · 🏫 ${escExtH(r.departemen)}</div>
        </div>
      </div>`;
    });
  };
  grp('🏢 Indoor', indoor);
  grp('🌿 Outdoor', outdoor);
  drop.innerHTML = html;
}

function extRpSelect(picker, id, nama, gedung, dept) {
  picker.querySelector('.ext-rp-search').style.display   = 'none';
  picker.querySelector('.ext-rp-dropdown').style.display = 'none';
  const sel = picker.querySelector('.ext-rp-selected');
  sel.style.display = 'flex';
  sel.querySelector('.ext-rp-sel-name').textContent = nama + ' — ' + gedung;
  sel.querySelector('.ext-rp-sel-dept').textContent = dept;
  picker.querySelector('[name=ruangan_id]').value = id;
}

function extRpChange(picker) {
  picker.querySelector('.ext-rp-search').style.display   = 'flex';
  picker.querySelector('.ext-rp-selected').style.display = 'none';
  picker.querySelector('.ext-rp-dropdown').style.display = 'none';
  picker.querySelector('[name=ruangan_id]').value = '';
  const inp = picker.querySelector('.ext-rp-input');
  inp.value = '';
  picker.querySelector('.ext-rp-clear').style.display = 'none';
  inp.focus();
  extRpRender(picker, '');
  picker.querySelector('.ext-rp-dropdown').style.display = 'block';
}

/* ── Event delegation ── */
document.addEventListener('input', function(e) {
  if (!e.target.classList.contains('ext-rp-input')) return;
  const picker = e.target.closest('.ext-room-picker');
  if (!picker) return;
  picker.querySelector('.ext-rp-clear').style.display = e.target.value ? 'block' : 'none';
  extRpRender(picker, e.target.value);
  picker.querySelector('.ext-rp-dropdown').style.display = 'block';
});

document.addEventListener('focus', function(e) {
  if (!e.target.classList.contains('ext-rp-input')) return;
  const picker = e.target.closest('.ext-room-picker');
  if (!picker) return;
  extRpRender(picker, e.target.value);
  picker.querySelector('.ext-rp-dropdown').style.display = 'block';
}, true);

document.addEventListener('mousedown', function(e) {
  /* Pilih item */
  const item = e.target.closest('.ext-rp-item');
  if (item) {
    e.preventDefault();
    const picker = item.closest('.ext-room-picker');
    extRpSelect(picker, item.dataset.id, item.dataset.nama, item.dataset.gedung, item.dataset.dept);
    return;
  }
  /* Tombol Ganti */
  const chg = e.target.closest('.ext-rp-change');
  if (chg) { e.preventDefault(); extRpChange(chg.closest('.ext-room-picker')); return; }
  /* Tombol Clear */
  const clr = e.target.closest('.ext-rp-clear');
  if (clr) {
    e.preventDefault();
    const picker = clr.closest('.ext-room-picker');
    const inp = picker.querySelector('.ext-rp-input');
    inp.value = ''; clr.style.display = 'none';
    extRpRender(picker, ''); inp.focus();
    return;
  }
  /* Tutup dropdown jika klik di luar picker */
  document.querySelectorAll('.ext-rp-dropdown').forEach(d => {
    if (!d.closest('.ext-room-picker').contains(e.target)) d.style.display = 'none';
  });
});


/* Hover effect untuk item */
document.addEventListener('mouseover', function(e) {
  const item = e.target.closest('.ext-rp-item');
  if (item) item.style.background = '#f0fdf4';
});
document.addEventListener('mouseout', function(e) {
  const item = e.target.closest('.ext-rp-item');
  if (item) item.style.background = '';
});

/* ══════════════════════════════════════════
   MODAL HELPERS
══════════════════════════════════════════ */
function openModal(id) { document.getElementById(id).classList.add('show'); }
function closeModal(id){ document.getElementById(id).classList.remove('show'); }
function openDetail(id){ openModal('detail-'+id); }

/* ══════════════════════════════════════════
   SURAT UPLOAD HELPERS
══════════════════════════════════════════ */
const SURAT_ICONS = {pdf:'📄', docx:'📝', jpg:'🖼️', jpeg:'🖼️', png:'🖼️'};

function extSuratPreview(input) {
  const wrap  = input.closest('.ext-surat-upload-wrap');
  const zone  = wrap.querySelector('.ext-surat-dropzone');
  const chosen= wrap.querySelector('.ext-surat-chosen');
  if (!input.files[0]) return;
  const file = input.files[0];
  const ext  = file.name.split('.').pop().toLowerCase();
  const icon = SURAT_ICONS[ext] || '📎';
  const sizeMB = (file.size / 1048576).toFixed(2);
  chosen.querySelector('.ext-surat-chosen-icon').textContent = icon;
  chosen.querySelector('.ext-surat-chosen-name').textContent = file.name + ' (' + sizeMB + ' MB)';
  chosen.style.display = 'flex';
  zone.style.borderColor = '#86efac';
  zone.style.background  = '#f0fdf4';
}
function extSuratClear(btn) {
  const wrap  = btn.closest('.ext-surat-upload-wrap');
  const zone  = wrap.querySelector('.ext-surat-dropzone');
  const chosen= wrap.querySelector('.ext-surat-chosen');
  const input = wrap.querySelector('input[type=file]');
  input.value = '';
  chosen.style.display = 'none';
  zone.style.borderColor = '';
  zone.style.background  = '';
}
function extRemoveSurat(btn) {
  /* Tandai hapus file lama */
  const preview = btn.closest('.ext-surat-preview');
  const flag    = document.getElementById('surat-delete-flag');
  if (flag) flag.value = '1';
  preview.style.display = 'none';
}

/* Drag & drop untuk dropzone */
document.addEventListener('dragover', function(e){
  const zone = e.target.closest('.ext-surat-dropzone');
  if (zone) { e.preventDefault(); zone.style.borderColor='#22c55e'; zone.style.background='#f0fdf4'; }
});
document.addEventListener('dragleave', function(e){
  const zone = e.target.closest('.ext-surat-dropzone');
  if (zone) { zone.style.borderColor=''; zone.style.background=''; }
});
document.addEventListener('drop', function(e){
  const zone = e.target.closest('.ext-surat-dropzone');
  if (!zone) return;
  e.preventDefault();
  const input = zone.querySelector('input[type=file]');
  if (e.dataTransfer.files[0]) {
    const dt = new DataTransfer();
    dt.items.add(e.dataTransfer.files[0]);
    input.files = dt.files;
    extSuratPreview(input);
  }
});

function openEdit(id) {
    const b = extData.find(x => +x.id === +id);
    if (!b) return;
    const f = document.getElementById('edit-form');
    f.querySelector('[name=id]').value            = b.id;
    f.querySelector('[name=nama_pemohon]').value  = b.nama_pemohon;
    f.querySelector('[name=instansi]').value      = b.instansi || '';
    f.querySelector('[name=kontak]').value        = b.kontak || '';
    f.querySelector('[name=jenis_pemohon]').value = b.jenis_pemohon;
    f.querySelector('[name=nama_kegiatan]').value = b.nama_kegiatan;
    f.querySelector('[name=tanggal_mulai]').value = b.tanggal_mulai;
    f.querySelector('[name=tanggal_selesai]').value = b.tanggal_selesai;
    f.querySelector('[name=jam_mulai]').value     = (b.jam_mulai||'').slice(0,5);
    f.querySelector('[name=jam_selesai]').value   = (b.jam_selesai||'').slice(0,5);
    f.querySelector('[name=jumlah_peserta]').value= b.jumlah_peserta;
    f.querySelector('[name=keterangan]').value    = b.keterangan || '';
    f.querySelector('[name=status]').value        = b.status;
    /* Reset surat state */
    const suratFlag = document.getElementById('surat-delete-flag');
    if (suratFlag) suratFlag.value = '0';
    const suratPreview = f.querySelector('.ext-surat-preview');
    if (suratPreview) suratPreview.style.display = b.file_surat ? 'flex' : 'none';
    extSuratClear(f.querySelector('.ext-surat-upload-wrap button') || document.createElement('button'));
    /* Set room picker */
    const picker = f.querySelector('.ext-room-picker');
    if (picker) {
        const roomData = JSON.parse(picker.dataset.rooms || '[]');
        const rm = roomData.find(r => String(r.id) === String(b.ruangan_id));
        if (rm) extRpSelect(picker, rm.id, rm.nama, rm.gedung, rm.departemen);
        else    extRpChange(picker);
    }
    openModal('modal-edit');
}

document.querySelectorAll('.modal-overlay').forEach(m =>
    m.addEventListener('click', function(e){ if(e.target===this) this.classList.remove('show'); }));

/* ══ Deteksi ketersediaan ruangan (live) ══
   Memanggil check_availability.php setiap kali ruangan/tanggal/jam berubah,
   lalu menampilkan status tersedia / daftar bentrok. */
const EXT_AVAIL_URL = '<?= base_url('user/check_availability.php') ?>';
function extLabelStatus(s){
  return ({pending:'Menunggu',disetujui:'Disetujui',ditolak:'Ditolak',
           aktif:'Aktif',selesai:'Selesai',dibatalkan:'Dibatalkan'})[s] || s;
}
function extEscape(s){ return String(s==null?'':s)
  .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

async function extCheckAvail(form){
  const box = form.querySelector('.ext-avail');
  if (!box) return;
  const get = n => form.querySelector('[name='+n+']');
  const rid = get('ruangan_id') ? get('ruangan_id').value : '';
  const tm  = get('tanggal_mulai').value;
  let   ts  = get('tanggal_selesai').value || tm;
  const jm  = get('jam_mulai').value;
  const js  = get('jam_selesai').value;

  if (!rid || !tm || !jm || !js) { box.style.display = 'none'; return; }

  box.style.display = 'block';
  box.style.background = '#f8fafc'; box.style.border = '1.5px solid #e2e8f0'; box.style.color = '#64748b';
  box.innerHTML = '⏳ Memeriksa ketersediaan ruangan…';

  const idEl = get('id');                       // saat edit → jangan bentrok dgn diri sendiri
  const excl = idEl && idEl.value ? idEl.value : 0;
  const url  = EXT_AVAIL_URL + '?ruangan_id=' + encodeURIComponent(rid)
             + '&tanggal_mulai=' + encodeURIComponent(tm)
             + '&tanggal_selesai=' + encodeURIComponent(ts)
             + '&jam_mulai=' + encodeURIComponent(jm)
             + '&jam_selesai=' + encodeURIComponent(js)
             + '&exclude_ext=' + encodeURIComponent(excl);
  try {
    const res  = await fetch(url);
    const data = await res.json();
    if (data.available === null) { box.style.display='none'; return; }
    if (data.available) {
      box.style.background='#f0fdf4'; box.style.border='1.5px solid #86efac'; box.style.color='#166534';
      box.innerHTML = '✅ <strong>Ruangan tersedia</strong> pada tanggal & jam tersebut.';
    } else {
      box.style.background='#fef2f2'; box.style.border='1.5px solid #fca5a5'; box.style.color='#991b1b';
      let h = '⚠️ <strong>Bentrok dengan ' + data.conflicts.length + ' agenda lain:</strong><ul style="margin:6px 0 0;padding-left:18px">';
      data.conflicts.forEach(c => {
        const tgl = c.tanggal_mulai === c.tanggal_selesai
                  ? c.tanggal_mulai
                  : c.tanggal_mulai + ' s/d ' + c.tanggal_selesai;
        h += '<li><strong>' + extEscape(c.sumber) + '</strong> — ' + extEscape(c.nama_kegiatan)
           + '<br><span style="font-size:11.5px;opacity:.85">' + extEscape(tgl) + ' · '
           + extEscape((c.jam_mulai||'').slice(0,5)) + '–' + extEscape((c.jam_selesai||'').slice(0,5))
           + ' · ' + extEscape(extLabelStatus(c.status)) + '</span></li>';
      });
      h += '</ul><div style="margin-top:7px;font-size:11.5px">Tetap dapat disimpan — prioritas dinilai otomatis (AHP–SAW) pada menu Kelola Peminjaman.</div>';
      box.innerHTML = h;
    }
  } catch (e) {
    box.style.background='#fffbeb'; box.style.border='1.5px solid #fcd34d'; box.style.color='#92400e';
    box.innerHTML = '⚠️ Gagal memeriksa ketersediaan. Periksa koneksi lalu coba lagi.';
  }
}

/* Pasang pemantau pada kedua form (tambah & edit) */
document.querySelectorAll('#modal-add form, #modal-edit form').forEach(form => {
  ['tanggal_mulai','tanggal_selesai','jam_mulai','jam_selesai'].forEach(n => {
    const el = form.querySelector('[name='+n+']');
    if (el) el.addEventListener('change', () => extCheckAvail(form));
  });
  const rid = form.querySelector('[name=ruangan_id]');
  if (rid) {
    // input hidden → pantau perubahan nilai via MutationObserver + polling ringan saat dipilih
    let last = rid.value;
    setInterval(() => { if (rid.value !== last) { last = rid.value; extCheckAvail(form); } }, 400);
  }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
