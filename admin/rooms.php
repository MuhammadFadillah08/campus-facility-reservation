<?php
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('admin_utama','admin_departemen','admin_dmf');
$page_title = 'Kelola Ruangan';
$db = db();

// Buat tabel gedung jika belum ada
$db->query("CREATE TABLE IF NOT EXISTS `gedung` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nama` VARCHAR(150) NOT NULL,
    `tipe` ENUM('indoor','outdoor') NOT NULL DEFAULT 'indoor',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_nama` (`nama`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Auto-seed dari ruangan yang sudah ada jika tabel masih kosong
$_gcount = $db->query("SELECT COUNT(*) c FROM gedung")->fetch_assoc()['c'];
if ($_gcount == 0) {
    $db->query("INSERT IGNORE INTO gedung(nama,tipe) SELECT gedung,tipe FROM ruangan GROUP BY gedung");
}

// Handle gedung CRUD
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'add_gedung') {
        $gNama = trim($_POST['gedung_nama'] ?? '');
        $gTipe = in_array($_POST['gedung_tipe'] ?? '', ['indoor','outdoor']) ? $_POST['gedung_tipe'] : 'indoor';
        if ($gNama) {
            $s = $db->prepare("INSERT IGNORE INTO gedung(nama,tipe) VALUES(?,?)");
            $s->bind_param('ss', $gNama, $gTipe);
            $s->execute();
            flash('success', $db->affected_rows > 0 ? "Gedung \"$gNama\" berhasil ditambahkan." : "Gedung \"$gNama\" sudah ada.");
        }
        redirect(base_url('admin/rooms.php'));
    }
    if ($act === 'delete_gedung') {
        $gId = (int)($_POST['gedung_id'] ?? 0);
        $gRow = $db->query("SELECT nama, tipe FROM gedung WHERE id=$gId")->fetch_assoc();
        if ($gRow) {
            // admin_dmf tidak boleh hapus gedung indoor
            if ($user['role']==='admin_dmf' && $gRow['tipe']==='indoor') {
                flash('error', 'Admin DMF tidak diizinkan menghapus gedung Indoor.');
                redirect(base_url('admin/rooms.php'));
            }
            $gName = $db->real_escape_string($gRow['nama']);
            $cnt = (int)$db->query("SELECT COUNT(*) c FROM ruangan WHERE gedung='$gName'")->fetch_assoc()['c'];
            if ($cnt > 0) flash('error', "Tidak bisa dihapus — masih ada $cnt ruangan menggunakan gedung ini.");
            else { $db->query("DELETE FROM gedung WHERE id=$gId"); flash('success','Gedung berhasil dihapus.'); }
        }
        redirect(base_url('admin/rooms.php'));
    }
    if ($act === 'delete_all') {
        $_delWhere = '1=1';
        if ($user['role']==='admin_departemen') $_delWhere = "departemen='".$db->real_escape_string($user['departemen'])."'";
        if ($user['role']==='admin_dmf')        $_delWhere = "tipe='outdoor'";
        $allFotos = $db->query("SELECT foto FROM ruangan WHERE $_delWhere AND foto!='' AND foto IS NOT NULL")->fetch_all(MYSQLI_ASSOC);
        foreach ($allFotos as $_fr) {
            if ($_fr['foto'] && file_exists(UPLOAD_RUANGAN.$_fr['foto'])) @unlink(UPLOAD_RUANGAN.$_fr['foto']);
        }
        $db->query("DELETE FROM ruangan WHERE $_delWhere");
        $deleted = $db->affected_rows;
        // Hapus gedung juga jika admin_utama (semua scope) atau sesuai tipe jika DMF
        if ($user['role'] === 'admin_utama') {
            $db->query("DELETE FROM gedung");
        } elseif ($user['role'] === 'admin_dmf') {
            $db->query("DELETE FROM gedung WHERE tipe='outdoor'");
        }
        // admin_departemen tidak hapus gedung (gedung bisa dipakai departemen lain)
        flash('success', "Berhasil menghapus <strong>$deleted</strong> ruangan beserta data gedung terkait.");
        redirect(base_url('admin/rooms.php'));
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action']??'';
    if ($action==='add'||$action==='edit') {
        $id        = (int)($_POST['id']??0);
        $nama      = trim($_POST['nama']??'');
        $gedung    = trim($_POST['gedung']??'');
        $dept      = $_POST['departemen']??'';
        $tipe      = in_array($_POST['tipe']??'',['indoor','outdoor'])?$_POST['tipe']:'indoor';
        $kap       = (int)($_POST['kapasitas']??30);
        $fas       = trim($_POST['fasilitas']??'');
        $desk      = trim($_POST['deskripsi']??'');
        $tersedia  = isset($_POST['tersedia'])?1:0;
        if ($user['role']==='admin_departemen'){$dept=$user['departemen'];$tipe='indoor';}
        if ($user['role']==='admin_dmf')       {$dept='Area Outdoor Fakultas Sains';$tipe='outdoor';}

        $foto = $_POST['existing_foto']??'';
        if (!empty($_FILES['foto']['name'])&&$_FILES['foto']['error']===UPLOAD_ERR_OK) {
            $file=$_FILES['foto'];
            $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
            $uploadErr=validateUpload($file,['jpg','jpeg','png','webp']);
            if ($uploadErr!==''){flash('error',$uploadErr);redirect(base_url('admin/rooms.php'));}
            $fname='room_'.time().'_'.mt_rand(1000,9999).'.'.$ext;
            if (!move_uploaded_file($file['tmp_name'],UPLOAD_RUANGAN.$fname)){flash('error','Gagal upload foto.');redirect(base_url('admin/rooms.php'));}
            if ($foto&&file_exists(UPLOAD_RUANGAN.$foto)) @unlink(UPLOAD_RUANGAN.$foto);
            $foto=$fname;
        }
        if ($action==='add') {
            $cekDuplikat = $db->prepare("SELECT id FROM ruangan WHERE nama=? AND departemen=? LIMIT 1");
            $cekDuplikat->bind_param('ss', $nama, $dept); $cekDuplikat->execute();
            if ($cekDuplikat->get_result()->num_rows > 0) {
                flash('error', "Gagal: Ruangan \"$nama\" sudah ada di departemen/admin yang sama. Gunakan nama yang berbeda.");
                redirect(base_url('admin/rooms.php'));
            }
            $s=$db->prepare("INSERT INTO ruangan(nama,gedung,departemen,tipe,kapasitas,fasilitas,deskripsi,foto,tersedia)VALUES(?,?,?,?,?,?,?,?,?)");
            $s->bind_param('ssssisssi',$nama,$gedung,$dept,$tipe,$kap,$fas,$desk,$foto,$tersedia);
            $s->execute(); flash('success',"Ruangan \"$nama\" berhasil ditambahkan.");
        } else {
            $cekDuplikat = $db->prepare("SELECT id FROM ruangan WHERE nama=? AND departemen=? AND id!=? LIMIT 1");
            $cekDuplikat->bind_param('ssi', $nama, $dept, $id); $cekDuplikat->execute();
            if ($cekDuplikat->get_result()->num_rows > 0) {
                flash('error', "Gagal: Ruangan \"$nama\" sudah ada di departemen/admin yang sama. Gunakan nama yang berbeda.");
                redirect(base_url('admin/rooms.php'));
            }
            $s=$db->prepare("UPDATE ruangan SET nama=?,gedung=?,departemen=?,tipe=?,kapasitas=?,fasilitas=?,deskripsi=?,foto=?,tersedia=? WHERE id=?");
            $s->bind_param('ssssisssii',$nama,$gedung,$dept,$tipe,$kap,$fas,$desk,$foto,$tersedia,$id);
            $s->execute(); flash('success','Ruangan berhasil diperbarui.');
        }
        redirect(base_url('admin/rooms.php'));
    }
    if ($action==='delete') {
        $id=(int)$_POST['id'];
        $row=$db->query("SELECT foto FROM ruangan WHERE id=$id")->fetch_assoc();
        if ($row&&$row['foto']&&file_exists(UPLOAD_RUANGAN.$row['foto'])) @unlink(UPLOAD_RUANGAN.$row['foto']);
        $db->query("DELETE FROM ruangan WHERE id=$id");
        flash('success','Ruangan berhasil dihapus.');
        redirect(base_url('admin/rooms.php'));
    }
}

$where='1=1';
if ($user['role']==='admin_departemen') $where="departemen='".$db->real_escape_string($user['departemen'])."'";
if ($user['role']==='admin_dmf')        $where="tipe='outdoor'";
$rooms=$db->query("SELECT * FROM ruangan WHERE $where ORDER BY departemen,nama")->fetch_all(MYSQLI_ASSOC);
$departemen_list=['Matematika','Fisika','Kimia','Biologi','Pendidikan IPA','Sains Terapan'];
$organisasi_list=[
    ['value'=>'Admin Fakultas Sains',        'label'=>'Admin Fakultas Sains',                 'desc'=>'Admin Utama Fakultas Sains'],
    ['value'=>'Area Outdoor Fakultas Sains', 'label'=>'Dewan Mahasiswa Fakultas Sains','desc'=>'Organisasi mahasiswa pengelola area outdoor']
];
$gedung_list_db = $db->query("SELECT * FROM gedung ORDER BY tipe, nama")->fetch_all(MYSQLI_ASSOC);
// Singkatan label departemen untuk tampilan kartu
$dept_display = ['Admin Fakultas Sains'=>'Fakultas Sains', 'Area Outdoor Fakultas Sains'=>'DMF'];

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-center justify-between mb-4" style="flex-wrap:wrap;gap:10px">
  <div>
    <div class="section-title">🏢 Kelola Ruangan</div>
    <div class="section-sub"><?= $user['role']==='admin_utama'?'Semua ruangan Fakultas Sains':($user['role']==='admin_departemen'?'Departemen '.$user['departemen']:'Area Outdoor Fakultas Sains') ?></div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="<?= base_url('admin/template_rooms.php') ?>" class="btn btn-secondary" style="font-size:13px">
      📥 Unduh Template Excel
    </a>
    <button class="btn btn-secondary" style="font-size:13px"
            onclick="document.getElementById('modal-import').classList.add('show')">
      📤 Import Excel
    </button>
    <button class="btn btn-secondary" style="font-size:13px"
            onclick="document.getElementById('modal-gedung').classList.add('show')">
      🏗️ Kelola Gedung/Outdoor
    </button>
    <button class="btn btn-danger" style="font-size:13px"
            onclick="document.getElementById('delete-all-confirm-input').value='';checkDeleteAllInput();document.getElementById('modal-delete-all').classList.add('show')">
      🗑️ Hapus Semua
    </button>
    <button class="btn btn-primary" onclick="document.getElementById('modal-add').classList.add('show')">
      + Tambah Ruangan
    </button>
  </div>
</div>

<!-- ══ SEARCH & FILTER BAR ══ -->
<style>
.rpill{padding:6px 14px;border-radius:20px;border:1.5px solid #e5e7eb;background:#f9fafb;font-size:12.5px;cursor:pointer;color:#374151;transition:all .15s;white-space:nowrap}
.rpill.active{background:#16a34a;border-color:#16a34a;color:#fff;font-weight:600}
.rpill:hover:not(.active){background:#f0fdf4;border-color:#86efac;color:#166534}
#room-search-wrap:focus-within{border-color:#16a34a!important;box-shadow:0 0 0 3px rgba(22,163,74,.1)}
/* ── Rata bawah kartu ruangan ── */
.room-grid .room-card{display:flex;flex-direction:column}
.room-grid .room-card .room-card-body{flex:1;display:flex;flex-direction:column}
.room-grid .room-card .room-card-body .d-flex.gap-2.mt-3{margin-top:auto;padding-top:10px}
</style>
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;margin-bottom:20px;display:flex;flex-wrap:wrap;align-items:center;gap:10px">

  <!-- Kotak search -->
  <div id="room-search-wrap" style="flex:1;min-width:220px;display:flex;align-items:center;gap:8px;background:#f9fafb;border:1.5px solid #e5e7eb;border-radius:8px;padding:0 12px;transition:border .2s,box-shadow .2s">
    <span style="color:#9ca3af;font-size:15px;flex-shrink:0">🔍</span>
    <input type="text" id="room-search"
           placeholder="Cari nama, gedung, departemen, fasilitas..."
           style="flex:1;border:none;background:transparent;padding:9px 0;font-size:13.5px;outline:none;color:#111827"
           autocomplete="off" oninput="filterRooms()">
    <button id="room-search-clear"
            style="display:none;background:none;border:none;cursor:pointer;color:#9ca3af;font-size:13px;padding:2px 4px;line-height:1"
            onclick="clearRoomSearch()" title="Hapus pencarian">✕</button>
  </div>

  <!-- Tipe pills -->
  <div style="display:flex;gap:6px;flex-shrink:0">
    <button class="rpill active" data-tipe="" onclick="setRoomTipe(this)">Semua</button>
    <button class="rpill" data-tipe="indoor" onclick="setRoomTipe(this)">🏢 Indoor</button>
    <button class="rpill" data-tipe="outdoor" onclick="setRoomTipe(this)">🌿 Outdoor</button>
  </div>

  <!-- Filter departemen / organisasi -->
  <select id="room-dept-filter" onchange="filterRooms()"
          style="border:1.5px solid #e5e7eb;border-radius:8px;padding:8px 12px;font-size:13px;background:#f9fafb;color:#374151;cursor:pointer;outline:none;max-width:220px">
    <option value="">Semua Departemen/Org</option>
    <?php foreach($departemen_list as $d): ?>
    <option value="<?= strtolower(e($d)) ?>"><?= e($d) ?></option>
    <?php endforeach; ?>
    <?php foreach($organisasi_list as $o): ?>
    <option value="<?= strtolower(e($o['value'])) ?>">🌿 <?= e($o['label']) ?></option>
    <?php endforeach; ?>
  </select>

  <!-- Jumlah hasil -->
  <span id="room-result-count" style="font-size:12.5px;color:#6b7280;white-space:nowrap;margin-left:auto;font-weight:500"></span>
</div>

<div class="room-grid">
<?php foreach($rooms as $r): ?>
<div class="room-card"
     data-nama="<?= strtolower(e($r['nama'])) ?>"
     data-gedung="<?= strtolower(e($r['gedung'])) ?>"
     data-dept="<?= strtolower(e($r['departemen'])) ?>"
     data-tipe="<?= e($r['tipe']) ?>"
     data-fas="<?= strtolower(e($r['fasilitas'])) ?>">
  <?php $fotoPath=UPLOAD_RUANGAN.$r['foto']; ?>
  <?php if($r['foto']&&file_exists($fotoPath)): ?>
    <img src="<?= base_url('uploads/ruangan/'.e($r['foto'])) ?>" alt="<?= e($r['nama']) ?>" style="width:100%;height:170px;object-fit:cover;display:block">
  <?php else: ?>
    <div class="room-card-img <?= $r['tipe']==='outdoor'?'outdoor-placeholder':'' ?>" style="height:170px"><?= $r['tipe']==='outdoor'?'🌿':'🏢' ?></div>
  <?php endif; ?>
  <div class="room-card-body">
    <div class="d-flex align-center justify-between">
      <div class="room-card-name"><?= e($r['nama']) ?></div>
      <span class="<?= $r['tersedia']?'room-status-available':'room-status-unavailable' ?>"><?= $r['tersedia']?'✅':'🔴' ?></span>
    </div>
    <div class="room-card-building"><?= e($r['gedung']) ?></div>
    <div class="room-card-meta"><span>👥 <?= $r['kapasitas'] ?></span><span>🏫 <?= e($dept_display[$r['departemen']] ?? $r['departemen']) ?></span><span class="badge <?= $r['tipe']==='outdoor'?'badge-outdoor':'badge-indoor' ?>" style="font-size:10px"><?= $r['tipe']==='outdoor'?'🌿 Outdoor':'🏢 Indoor' ?></span></div>
    <?php if($r['fasilitas']): ?><div class="room-card-facilities"><?php foreach(explode(',',$r['fasilitas']) as $f): ?><span class="facility-tag"><?= e(trim($f)) ?></span><?php endforeach; ?></div><?php endif; ?>
    <div class="d-flex gap-2 mt-3">
      <button class="btn btn-primary btn-sm" onclick="openEdit(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)">✏️ Edit</button>
      <form method="post" style="display:inline" onsubmit="return confirm('Hapus ruangan ini?')">
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">🗑️ Hapus</button>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php if(empty($rooms)): ?><div class="empty-state" style="grid-column:1/-1"><div class="icon">🏢</div><h3>Belum ada ruangan</h3></div><?php endif; ?>
<div id="room-no-results" style="display:none;grid-column:1/-1;text-align:center;padding:48px 20px;color:#6b7280">
  <div style="font-size:42px;margin-bottom:12px">🔍</div>
  <div style="font-weight:700;font-size:15px;color:#374151;margin-bottom:6px">Ruangan tidak ditemukan</div>
  <div style="font-size:13px">Coba ubah kata kunci atau pilih filter yang berbeda</div>
  <button onclick="resetRoomSearch()" style="margin-top:14px;padding:7px 18px;background:#16a34a;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">🔄 Reset Pencarian</button>
</div>
</div>

<!-- ADD MODAL -->
<div id="modal-add" class="modal-overlay">
<div class="modal-box"><div class="modal-header"><span class="modal-title">➕ Tambah Ruangan</span><button class="modal-close" onclick="document.getElementById('modal-add').classList.remove('show')">✕</button></div>
<form method="post" enctype="multipart/form-data" id="add-form">
<input type="hidden" name="action" value="add">
<div class="modal-body"><?php include __DIR__.'/../includes/room_form.php'; ?></div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="document.getElementById('modal-add').classList.remove('show')">Batal</button><button type="submit" class="btn btn-primary">Simpan</button></div>
</form></div></div>

<!-- EDIT MODAL -->
<div id="modal-edit" class="modal-overlay">
<div class="modal-box"><div class="modal-header"><span class="modal-title">✏️ Edit Ruangan</span><button class="modal-close" onclick="document.getElementById('modal-edit').classList.remove('show')">✕</button></div>
<form method="post" enctype="multipart/form-data" id="edit-form">
<input type="hidden" name="action" value="edit">
<input type="hidden" name="id" id="edit-id">
<input type="hidden" name="existing_foto" id="edit-existing-foto">
<div class="modal-body" id="edit-modal-body"><?php include __DIR__.'/../includes/room_form.php'; ?></div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="document.getElementById('modal-edit').classList.remove('show')">Batal</button><button type="submit" class="btn btn-primary">Simpan Perubahan</button></div>
</form></div></div>

<!-- ══ MODAL IMPORT EXCEL ══ -->
<div id="modal-import" class="modal-overlay">
<div class="modal-box" style="max-width:520px">
  <div class="modal-header">
    <span class="modal-title">📤 Import Ruangan dari Excel</span>
    <button class="modal-close" onclick="document.getElementById('modal-import').classList.remove('show')">✕</button>
  </div>
  <div class="modal-body">

    <!-- Panduan singkat -->
    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:13px 15px;margin-bottom:16px;font-size:13px;color:#166534;line-height:1.75">
      <strong>📋 Cara pakai:</strong><br>
      1. Klik <strong>Unduh Template Excel</strong> → buka file .xlsx di Excel/LibreOffice<br>
      2. Isi data ruangan mulai <strong>baris ke-4</strong> (gunakan dropdown di kolom Departemen/Tipe/Tersedia)<br>
      3. Kolom <strong>Foto</strong> tidak ada — tambahkan lewat <strong>Edit</strong> setelah import<br>
      4. Simpan sebagai <strong>CSV</strong>: <em>File → Simpan Sebagai → CSV</em> (koma <em>atau</em> titik koma, keduanya didukung otomatis)<br>
      5. Upload file <strong>.csv</strong> di form di bawah
    </div>

    <?php if ($user['role']==='admin_departemen'): ?>
    <div style="background:#fef9c3;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12.5px;color:#78350f">
      ⚠️ Sebagai Admin Departemen <strong><?= e($user['departemen']) ?></strong>, kolom Departemen dan Tipe di file
      akan otomatis disesuaikan (Indoor — <?= e($user['departemen']) ?>).
    </div>
    <?php elseif ($user['role']==='admin_dmf'): ?>
    <div style="background:#fef9c3;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12.5px;color:#78350f">
      ⚠️ Sebagai Admin DMF, kolom Departemen dan Tipe akan otomatis disesuaikan (Outdoor — Area Outdoor Fakultas Sains).
    </div>
    <?php endif; ?>

    <div class="form-group">
      <label style="font-weight:700">Pilih File Excel <span style="color:var(--danger)">*</span></label>
      <input type="file" id="import-file-input" class="form-control" accept=".csv">
      <div class="form-text">Format: <strong>.csv</strong> — ekspor dari Excel/LibreOffice. Maksimal 5MB.</div>
    </div>

    <!-- Progress / hasil -->
    <div id="import-status" style="display:none"></div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary"
            onclick="document.getElementById('modal-import').classList.remove('show')">Batal</button>
    <button type="button" class="btn btn-primary" id="import-btn" onclick="doImport()">
      📤 Mulai Import
    </button>
  </div>
</div>
</div>

<!-- MODAL KELOLA GEDUNG -->
<div id="modal-gedung" class="modal-overlay">
<div class="modal-box" style="max-width:560px">
  <div class="modal-header">
    <span class="modal-title">🏗️ Kelola Gedung / Outdoor</span>
    <button class="modal-close" onclick="document.getElementById('modal-gedung').classList.remove('show')">✕</button>
  </div>
  <div class="modal-body">
      <p style="font-size:13px;color:#64748b;margin-bottom:14px">Gedung yang ditambahkan akan muncul sebagai pilihan saat menambah/edit ruangan. Tipe menentukan apakah gedung Indoor atau Outdoor.</p>
      <form method="post" id="form-add-gedung">
      <div class="form-grid-2" style="margin-bottom:10px">
        <div class="form-group">
          <label>Nama Gedung/Outdoor <span style="color:var(--danger)">*</span></label>
          <input type="text" name="gedung_nama" class="form-control" placeholder="Contoh: Gedung Kimia A" required>
        </div>
        <div class="form-group">
          <label>Tipe <span style="color:var(--danger)">*</span></label>
          <select name="gedung_tipe" class="form-control">
            <option value="indoor">🏢 Indoor</option>
            <option value="outdoor">🌿 Outdoor</option>
          </select>
        </div>
      </div>
      <input type="hidden" name="action" value="add_gedung">
      <button type="submit" class="btn btn-primary btn-sm" style="margin-bottom:16px">+ Tambah Gedung</button>
      </form>
      <hr style="border-color:#e5e7eb;margin:4px 0 14px">
      <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Daftar Gedung/Outdoor</div>
      <div style="max-height:260px;overflow-y:auto">
        <?php if (empty($gedung_list_db)): ?>
        <div style="text-align:center;padding:20px;color:#94a3b8;font-size:13px">Belum ada gedung terdaftar.</div>
        <?php else: ?>
        <table class="table" style="font-size:13px">
          <thead><tr><th>Nama Gedung</th><th>Tipe</th><th style="width:60px">Hapus</th></tr></thead>
          <tbody>
          <?php foreach ($gedung_list_db as $g): ?>
          <tr>
            <td style="font-weight:600"><?= e($g['nama']) ?></td>
            <td><?= $g['tipe']==='outdoor' ? '<span style="color:#065f46;background:#d1fae5;padding:2px 8px;border-radius:10px;font-size:11.5px;font-weight:700">🌿 Outdoor</span>' : '<span style="color:#1e40af;background:#dbeafe;padding:2px 8px;border-radius:10px;font-size:11.5px;font-weight:700">🏢 Indoor</span>' ?></td>
            <td>
              <?php if (!($user['role']==='admin_dmf' && $g['tipe']==='indoor')): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Hapus gedung ini?')">
                <input type="hidden" name="action" value="delete_gedung">
                <input type="hidden" name="gedung_id" value="<?= $g['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" style="font-size:11px;padding:3px 8px">🗑️</button>
              </form>
              <?php else: ?>
              <span title="Admin DMF tidak dapat menghapus gedung Indoor"
                    style="color:#cbd5e1;font-size:18px;display:inline-block;text-align:center;width:32px">🔒</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" onclick="document.getElementById('modal-gedung').classList.remove('show')">Tutup</button>
    </div>
</div></div>

<!-- MODAL HAPUS SEMUA RUANGAN -->
<div id="modal-delete-all" class="modal-overlay">
<div class="modal-box" style="max-width:480px">
  <div class="modal-header" style="background:#fef2f2;border-bottom:1px solid #fecaca">
    <span class="modal-title" style="color:#dc2626">⚠️ Hapus Semua Ruangan</span>
    <button class="modal-close" onclick="document.getElementById('modal-delete-all').classList.remove('show')">✕</button>
  </div>
  <div class="modal-body">
    <?php
      $_scopeLabel = 'admin_utama' === $user['role']
        ? 'semua ruangan Fakultas Sains'
        : ($user['role']==='admin_departemen'
            ? 'semua ruangan Departemen '.$user['departemen']
            : 'semua ruangan Outdoor');
    ?>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:13px;color:#991b1b;line-height:1.8">
      ⚠️ <strong>Tindakan ini tidak bisa dibatalkan!</strong><br>
      Akan menghapus <strong><?= $_scopeLabel ?></strong>
      <?php if (count($rooms) > 0): ?>
        (<strong><?= count($rooms) ?> ruangan</strong>)
      <?php endif; ?>
      beserta semua foto yang telah diupload.
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label style="font-weight:700;font-size:13px">Ketik <code style="background:#fee2e2;color:#dc2626;padding:1px 6px;border-radius:4px">HAPUS SEMUA</code> untuk konfirmasi:</label>
      <input type="text" id="delete-all-confirm-input" class="form-control"
             placeholder="HAPUS SEMUA" autocomplete="off"
             oninput="checkDeleteAllInput()"
             style="margin-top:6px;border-color:#fca5a5">
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary"
            onclick="document.getElementById('modal-delete-all').classList.remove('show')">Batal</button>
    <form method="post" id="delete-all-form" style="display:inline">
      <input type="hidden" name="action" value="delete_all">
      <button type="button" id="delete-all-btn" class="btn btn-danger"
              onclick="submitDeleteAll()" disabled
              style="opacity:.45;cursor:not-allowed">
        🗑️ Hapus Semua
      </button>
    </form>
  </div>
</div>
</div>



<script>
function openEdit(room) {
  const m=document.getElementById('modal-edit');
  m.classList.add('show');
  document.getElementById('edit-id').value=room.id;
  document.getElementById('edit-existing-foto').value=room.foto||'';
  const f=document.getElementById('edit-form');
  const set=(n,v)=>{const el=f.querySelector('[name='+n+']');if(el)el.value=v||'';};
  set('nama',room.nama);set('gedung',room.gedung);set('departemen',room.departemen);
  set('tipe',room.tipe);set('kapasitas',room.kapasitas);set('fasilitas',room.fasilitas||'');set('deskripsi',room.deskripsi||'');
  // Trigger auto-tipe setelah set gedung
  const gedungSel = f.querySelector('[name=gedung]');
  if (gedungSel && gedungSel.tagName === 'SELECT') {
      gedungSel.dispatchEvent(new Event('change'));
  }
  const cb=f.querySelector('[name=tersedia]');if(cb)cb.checked=room.tersedia==1;
  const prev=document.getElementById('edit-current-photo');
  if(prev){if(room.foto){prev.innerHTML='<img src="<?= base_url('uploads/ruangan/') ?>'+room.foto+'" class="img-preview"><div class="form-text">Foto saat ini. Upload baru untuk mengganti.</div>';}else{prev.innerHTML='<div class="form-text text-muted">Belum ada foto.</div>';}}
}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');}));
function previewImg(input,previewId){const file=input.files[0];if(!file)return;const r=new FileReader();r.onload=e=>{const p=document.getElementById(previewId);if(p)p.innerHTML='<img src="'+e.target.result+'" class="img-preview">';};r.readAsDataURL(file);}

/* ── Import Excel ── */
async function doImport() {
  const fileInput = document.getElementById('import-file-input');
  const statusBox = document.getElementById('import-status');
  const btn       = document.getElementById('import-btn');

  if (!fileInput.files.length) {
    statusBox.style.display = 'block';
    statusBox.innerHTML = '<div class="alert alert-danger">⚠️ Pilih file Excel terlebih dahulu.</div>';
    return;
  }

  btn.disabled    = true;
  btn.textContent = '⏳ Sedang memproses...';
  statusBox.style.display = 'block';
  statusBox.innerHTML     = '<div class="alert alert-info" style="margin:0">⏳ Memproses file, mohon tunggu...</div>';

  const fd = new FormData();
  fd.append('file_import', fileInput.files[0]);

  try {
    const res  = await fetch('<?= base_url('admin/import_rooms.php') ?>', { method:'POST', body:fd });
    const data = await res.json();

    if (!data.ok) {
      statusBox.innerHTML = `<div class="alert alert-danger">❌ ${escH(data.msg)}</div>`;
    } else {
      let html = `<div class="alert alert-success" style="margin-bottom:8px">
        ✅ <strong>${data.inserted}</strong> ruangan berhasil diimport.
        ${data.skipped ? `<br>⚠️ <strong>${data.skipped}</strong> baris dilewati.` : ''}
      </div>`;

      if (data.errors.length) {
        html += `<details style="margin-top:8px">
          <summary style="cursor:pointer;font-size:13px;font-weight:700;color:#dc2626">
            ⚠️ ${data.errors.length} baris dengan error (klik untuk lihat)
          </summary>
          <ul style="margin:8px 0 0 16px;font-size:12.5px;color:#7f1d1d">
            ${data.errors.map(e=>`<li>${escH(e)}</li>`).join('')}
          </ul>
        </details>`;
      }

      if (data.inserted > 0) {
        html += `<div style="margin-top:12px;text-align:right">
          <button class="btn btn-primary btn-sm" onclick="location.reload()">🔄 Muat Ulang Halaman</button>
        </div>`;
      }
      statusBox.innerHTML = html;
    }
  } catch(e) {
    statusBox.innerHTML = '<div class="alert alert-danger">❌ Gagal terhubung ke server.</div>';
  } finally {
    btn.disabled    = false;
    btn.textContent = '📤 Mulai Import';
  }
}
function escH(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

/* ── Hapus Semua Ruangan ── */
function checkDeleteAllInput() {
  var val = (document.getElementById('delete-all-confirm-input').value || '').trim();
  var btn = document.getElementById('delete-all-btn');
  if (!btn) return;
  var ok = (val === 'HAPUS SEMUA');
  btn.disabled = !ok;
  btn.style.opacity  = ok ? '1'   : '.45';
  btn.style.cursor   = ok ? 'pointer' : 'not-allowed';
}
function submitDeleteAll() {
  var val = (document.getElementById('delete-all-confirm-input').value || '').trim();
  if (val !== 'HAPUS SEMUA') return;
  document.getElementById('delete-all-form').submit();
}

/* ══ PENCARIAN RUANGAN ══ */
var _roomTipe = '';
function filterRooms() {
  var q    = document.getElementById('room-search').value.toLowerCase().trim();
  var dept = document.getElementById('room-dept-filter').value;
  var cards = document.querySelectorAll('.room-card');
  var count = 0, total = cards.length;

  cards.forEach(function(card) {
    var matchQ    = !q || [card.dataset.nama,card.dataset.gedung,card.dataset.dept,card.dataset.fas].some(function(v){return (v||'').includes(q);});
    var matchTipe = !_roomTipe || card.dataset.tipe === _roomTipe;
    var matchDept = !dept || (card.dataset.dept||'').includes(dept);
    var show = matchQ && matchTipe && matchDept;
    card.style.display = show ? '' : 'none';
    if (show) count++;
  });

  // Tombol clear
  var clr = document.getElementById('room-search-clear');
  if (clr) clr.style.display = q ? 'inline' : 'none';

  // Badge jumlah
  var badge = document.getElementById('room-result-count');
  if (badge) {
    if (!q && !_roomTipe && !dept) {
      badge.textContent = total + ' ruangan';
    } else {
      badge.textContent = count + ' dari ' + total + ' ruangan';
      badge.style.color = count === 0 ? '#dc2626' : '#16a34a';
    }
  }

  // Pesan tidak ditemukan
  var noRes = document.getElementById('room-no-results');
  if (noRes) noRes.style.display = (count === 0 && total > 0) ? 'block' : 'none';
}
function setRoomTipe(btn) {
  document.querySelectorAll('.rpill').forEach(function(p){p.classList.remove('active');});
  btn.classList.add('active');
  _roomTipe = btn.dataset.tipe;
  filterRooms();
}
function clearRoomSearch() {
  document.getElementById('room-search').value = '';
  filterRooms();
}
function resetRoomSearch() {
  document.getElementById('room-search').value = '';
  document.getElementById('room-dept-filter').value = '';
  _roomTipe = '';
  document.querySelectorAll('.rpill').forEach(function(p){p.classList.remove('active');});
  var first = document.querySelector('.rpill[data-tipe=""]');
  if (first) first.classList.add('active');
  filterRooms();
}
// Jalankan saat halaman pertama kali dimuat
document.addEventListener('DOMContentLoaded', filterRooms);
</script>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
