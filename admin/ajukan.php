<?php
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('admin_utama', 'admin_departemen', 'admin_dmf');
/* Cegah cache → script ketersediaan selalu versi terbaru */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$page_title = 'Ajukan Peminjaman';
$db = db();
ensureColumn($db, 'peminjaman', 'asal_organisasi', "VARCHAR(100) NOT NULL DEFAULT '-'");

$preselect      = (int)($_GET['room'] ?? 0);
$preselect_date = $_GET['tanggal_cek']   ?? '';
$preselect_jam1 = $_GET['jam_mulai_cek'] ?? '';
$preselect_jam2 = $_GET['jam_selesai_cek'] ?? '';
$errors  = [];
$success = false;

/* ================================================================
   POST Handler — admin mengajukan peminjaman untuk dirinya sendiri
================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $room_id   = (int)($_POST['ruangan_id'] ?? 0);
    $nama_keg  = trim($_POST['nama_kegiatan']   ?? '');
    $tgl_mulai = $_POST['tanggal_mulai']         ?? '';
    $tgl_sel   = $_POST['tanggal_selesai']       ?? '';
    $jam_mul   = $_POST['jam_mulai']             ?? '';
    $jam_sel   = $_POST['jam_selesai']           ?? '';
    $peserta   = max(1, (int)($_POST['jumlah_peserta'] ?? 1));
    $ket       = trim($_POST['keterangan']       ?? '');
    $asal_org  = ($user['role'] === 'admin_dmf') ? (trim($_POST['asal_organisasi'] ?? '') ?: '-') : '-';

    if (!$tgl_sel) $tgl_sel = $tgl_mulai;

    /* Validasi */
    if (!$room_id)   $errors[] = 'Pilih ruangan terlebih dahulu.';
    if (!$nama_keg)  $errors[] = 'Nama kegiatan wajib diisi.';
    if (!$tgl_mulai) $errors[] = 'Tanggal mulai wajib diisi.';
    if ($tgl_sel < $tgl_mulai) $errors[] = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
    if (!$jam_mul || !$jam_sel) {
        $errors[] = 'Jam mulai dan jam selesai wajib diisi.';
    } elseif ($tgl_mulai === $tgl_sel && $jam_sel <= $jam_mul) {
        $errors[] = 'Jam selesai harus lebih dari jam mulai pada hari yang sama.';
    }

    /* Pastikan ruangan ada */
    $room = null;
    if ($room_id) {
        $rStmt = $db->prepare("SELECT id,nama,gedung,departemen,tipe FROM ruangan WHERE id=?");
        $rStmt->bind_param('i', $room_id);
        $rStmt->execute();
        $room = $rStmt->get_result()->fetch_assoc();
        if (!$room) $errors[] = 'Ruangan tidak ditemukan.';
    }

    /* Upload surat (opsional untuk admin) */
    $file_name = '';
    if (empty($errors) && !empty($_FILES['file_surat']['name']) && ($_FILES['file_surat']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $file = $_FILES['file_surat'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $uploadErr = validateUpload($file, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']);
        if ($uploadErr !== '') {
            $errors[] = $uploadErr;
        } else {
            $file_name = 'surat_' . $user['id'] . '_' . time() . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], UPLOAD_SURAT . $file_name)) {
                $errors[] = 'Gagal upload file surat.';
                $file_name = '';
            }
        }
    }

    /* Simpan ke DB */
    if (empty($errors)) {
        $uid = (int)$user['id'];
        $s = $db->prepare(
            "INSERT INTO peminjaman
               (user_id,ruangan_id,nama_kegiatan,asal_organisasi,tanggal_mulai,tanggal_selesai,
                jam_mulai,jam_selesai,jumlah_peserta,keterangan,file_surat,status)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,'pending')"
        );
        $s->bind_param('iisssssssss',
            $uid, $room_id, $nama_keg, $asal_org, $tgl_mulai, $tgl_sel,
            $jam_mul, $jam_sel, $peserta, $ket, $file_name
        );

        if ($s->execute()) {
            /* ── Notifikasi ke admin validator yang relevan ── */
            if ($room['tipe'] === 'outdoor') {
                $adminRows = $db->query("SELECT id FROM users WHERE role IN ('admin_utama','admin_dmf') AND id<>$uid")->fetch_all(MYSQLI_ASSOC);
            } else {
                $dept_e = $db->real_escape_string($room['departemen']);
                $adminRows = $db->query("SELECT id FROM users WHERE (role='admin_utama' OR (role='admin_departemen' AND departemen='$dept_e')) AND id<>$uid")->fetch_all(MYSQLI_ASSOC);
            }
            $notif_link = base_url('admin/bookings.php');
            $pesan_notif = $nama_keg . ' — ' . $room['nama'] . ' oleh ' . $user['nama'] . ' (admin)';
            foreach ($adminRows as $adm) {
                pushNotif($db, (int)$adm['id'],
                    'Pengajuan Peminjaman Baru (Admin)',
                    $pesan_notif,
                    'info',
                    $notif_link
                );
            }
            flash('success', 'Pengajuan peminjaman berhasil dikirim! Mohon tunggu validasi.');
            redirect(base_url('admin/bookings.php'));
        } else {
            $errors[] = 'Gagal menyimpan pengajuan: ' . $db->error;
            /* Bersihkan file yang sudah terupload bila insert gagal */
            if ($file_name && file_exists(UPLOAD_SURAT . $file_name)) @unlink(UPLOAD_SURAT . $file_name);
        }
    }
}

/* ── Data ruangan (sesuai cakupan peran admin) ── */
$rWhere = ['tersedia=1'];
if ($user['role'] === 'admin_departemen')
    $rWhere[] = "departemen='{$db->real_escape_string($user['departemen'])}'";
if ($user['role'] === 'admin_dmf')
    $rWhere[] = "tipe='outdoor'";
$rooms = $db->query(
    "SELECT * FROM ruangan WHERE " . implode(' AND ', $rWhere) . " ORDER BY tipe,departemen,nama"
)->fetch_all(MYSQLI_ASSOC);
$roomMap = [];
foreach ($rooms as $rm) $roomMap[$rm['id']] = ['dept' => $rm['departemen'], 'tipe' => $rm['tipe']];

require_once __DIR__ . '/../includes/header.php';
?>
<style>
/* ── Room Search Picker ── */
.room-picker{position:relative}
.room-picker-search{display:flex;align-items:center;gap:8px;border:1.5px solid #e2e8f0;border-radius:10px;padding:0 12px;background:#fff;transition:border-color .2s,box-shadow .2s;min-height:44px;cursor:text}
.room-picker-search:focus-within{border-color:var(--primary-light);box-shadow:0 0 0 3px rgba(22,163,74,.12)}
.room-picker-search-icon{font-size:15px;flex-shrink:0;color:#94a3b8;pointer-events:none}
.room-picker-search-field{flex:1;border:none;outline:none;font-size:14px;color:#1e293b;background:transparent;font-family:inherit;padding:11px 0}
.room-picker-search-field::placeholder{color:#94a3b8}
.room-picker-clear-btn{background:none;border:none;cursor:pointer;color:#94a3b8;font-size:14px;padding:4px 6px;border-radius:6px;line-height:1;transition:all .15s;flex-shrink:0}
.room-picker-clear-btn:hover{background:#fee2e2;color:#dc2626}
.room-picker-dropdown{position:absolute;top:calc(100% + 5px);left:0;right:0;background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 36px rgba(0,0,0,.13);z-index:600;max-height:340px;overflow-y:auto}
.room-picker-group-hd{padding:8px 14px 5px;font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.07em;background:#fff;border-bottom:1px solid #f1f5f9;position:sticky;top:0;z-index:1}
.room-picker-item{padding:10px 14px;cursor:pointer;transition:background .12s;border-bottom:1px solid #f8fafc;display:flex;align-items:flex-start;gap:10px}
.room-picker-item:last-child{border-bottom:none}
.room-picker-item:hover,.room-picker-item.rp-focus{background:#f0fdf4}
.room-picker-item.rp-focus .rpi-name{color:var(--primary)}
.rpi-icon{font-size:20px;flex-shrink:0;margin-top:1px}
.rpi-body{flex:1;min-width:0}
.rpi-name{font-weight:700;font-size:13.5px;color:#1e293b;margin-bottom:4px;line-height:1.3}
.rpi-meta{display:flex;gap:5px;flex-wrap:wrap;align-items:center}
.rp-badge{padding:2px 8px;border-radius:20px;font-size:11.5px;font-weight:600;white-space:nowrap}
.rp-badge.kap{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.rp-badge.dept{background:#fdf4ff;border:1px solid #e9d5ff;color:#7c3aed}
.rp-badge.indoor{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
.rp-badge.outdoor{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
.rp-badge.gedung{background:#f8fafc;border:1px solid #e2e8f0;color:#475569}
.rp-empty{padding:24px 16px;text-align:center;color:#94a3b8}
.rp-empty-ic{font-size:30px;margin-bottom:6px}
mark.rp-hl{background:#fef08a;border-radius:2px;color:inherit;padding:0}
.room-picker-selected{display:flex;align-items:center;gap:12px;padding:12px 14px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px}
.rps-icon{font-size:24px;flex-shrink:0}
.rps-body{flex:1;min-width:0}
.rps-name{font-weight:700;font-size:14px;color:#166534;margin-bottom:2px}
.rps-meta{font-size:12px;color:#15803d}
.rps-change{flex-shrink:0;background:#fff;border:1.5px solid #22c55e;color:#16a34a;padding:7px 13px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;transition:all .15s}
.rps-change:hover{background:#22c55e;color:#fff}
/* ── Organisasi Picker (combobox kreatif) ── */
.org-picker{position:relative}
.org-picker-field{display:flex;align-items:center;gap:9px;border:1.5px solid #e2e8f0;border-radius:10px;padding:0 6px 0 13px;background:#fff;transition:border-color .2s,box-shadow .2s;min-height:46px;cursor:text}
.org-picker-field:focus-within{border-color:var(--primary-light);box-shadow:0 0 0 3px rgba(22,163,74,.12)}
.org-picker-icon{font-size:18px;flex-shrink:0;pointer-events:none}
.org-picker-input{flex:1;border:none;outline:none;font-size:14px;color:#1e293b;background:transparent;font-family:inherit;padding:12px 0;font-weight:600;min-width:0}
.org-picker-input::placeholder{color:#94a3b8;font-weight:400}
.org-picker-caret{background:none;border:none;cursor:pointer;color:#94a3b8;font-size:13px;padding:8px 9px;border-radius:6px;transition:transform .25s,color .15s;flex-shrink:0;line-height:1}
.org-picker.open .org-picker-caret{transform:rotate(180deg);color:var(--primary)}
.org-picker-dropdown{position:absolute;top:calc(100% + 6px);left:0;right:0;background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;box-shadow:0 14px 42px rgba(0,0,0,.16);z-index:600;max-height:330px;overflow-y:auto;padding:6px;animation:orgPop .14s ease}
@keyframes orgPop{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.org-cat-hd{padding:9px 12px 5px;font-size:10.5px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em}
.org-item{display:flex;align-items:center;gap:11px;padding:9px 12px;cursor:pointer;border-radius:10px;transition:background .12s}
.org-item:hover,.org-item.org-focus{background:#f0fdf4}
.org-item-ic{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.org-item-ic.umum{background:#f1f5f9}
.org-item-ic.lembaga{background:#dbeafe}
.org-item-ic.hmd{background:#ede9fe}
.org-item-ic.hmps{background:#fce7f3}
.org-item-ic.ukm{background:#fef3c7}
.org-item-body{flex:1;min-width:0}
.org-item-name{font-weight:700;font-size:13.5px;color:#1e293b;line-height:1.25}
.org-item-sub{font-size:11px;color:#94a3b8;margin-top:1px}
.org-item-check{color:#16a34a;font-weight:800;font-size:15px;opacity:0;flex-shrink:0}
.org-item.org-selected{background:#ecfdf5}
.org-item.org-selected .org-item-check{opacity:1}
.org-custom{display:flex;align-items:center;gap:9px;padding:11px 12px;cursor:pointer;border-radius:10px;background:#fffbeb;border:1px dashed #fcd34d;margin:5px 4px 2px;font-size:13px;color:#92400e;font-weight:600}
.org-custom:hover{background:#fef3c7}
.org-empty{padding:22px 14px;text-align:center;color:#94a3b8;font-size:13px}
mark.org-hl{background:#fef08a;border-radius:2px;padding:0;color:inherit}
</style>

<div class="d-flex align-center justify-between mb-4 flex-wrap gap-2">
  <div>
    <div class="section-title">📋 Ajukan Peminjaman</div>
    <div class="section-sub">Ajukan peminjaman gedung/ruangan Fakultas Sains untuk kegiatan Anda sebagai admin. Pengajuan akan masuk daftar validasi.</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= base_url('admin/template_ajukan.php') ?>" class="btn btn-secondary">📥 Unduh Template</a>
    <button type="button" class="btn btn-secondary" onclick="openModal('modal-import')">📤 Import CSV</button>
  </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <strong>⚠️ Perbaiki kesalahan:</strong>
  <ul style="margin:6px 0 0 16px">
    <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (empty($rooms)): ?>
<div class="card"><div class="empty-state"><div class="icon">🏢</div><h3>Tidak ada ruangan tersedia</h3><p>Belum ada ruangan dalam cakupan Anda yang bisa diajukan.</p></div></div>
<?php else: ?>

<form method="post" enctype="multipart/form-data">

  <!-- ── Data Pemohon (admin) ── -->
  <div class="card mb-4">
    <div class="card-header"><span class="card-title">👤 Data Pemohon</span></div>
    <div class="card-body">
      <div class="form-grid-2">
        <div class="form-group">
          <label>Nama Admin</label>
          <input type="text" class="form-control" value="<?= e($user['nama']) ?>" disabled>
        </div>
        <div class="form-group">
          <label>Email</label>
          <input type="text" class="form-control" value="<?= e($user['email']) ?>" disabled>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Data Kegiatan & Ruangan ── -->
  <div class="card mb-4">
    <div class="card-header"><span class="card-title">🎯 Data Kegiatan &amp; Ruangan</span></div>
    <div class="card-body">
      <div class="form-group">
        <label>Nama Kegiatan *</label>
        <input type="text" name="nama_kegiatan" class="form-control"
               placeholder="Contoh: Rapat Koordinasi Departemen"
               value="<?= e($_POST['nama_kegiatan'] ?? '') ?>" required>
      </div>
      <?php if ($user['role'] === 'admin_dmf'): ?>
      <div class="form-group">
        <label>Asal Organisasi <span style="font-size:12px;color:#64748b;font-weight:400">(isi <strong>-</strong> jika bukan dari organisasi)</span></label>
        <div class="org-picker" id="org-picker">
          <div class="org-picker-field" id="org-field" onclick="document.getElementById('org-input').focus()">
            <span class="org-picker-icon" id="org-icon">🏢</span>
            <input type="text" name="asal_organisasi" id="org-input" class="org-picker-input"
                   autocomplete="off" spellcheck="false"
                   placeholder="Pilih atau ketik asal organisasi..."
                   value="<?= e($_POST['asal_organisasi'] ?? '') ?>">
            <button type="button" class="org-picker-caret" id="org-caret" tabindex="-1" title="Buka daftar">▾</button>
          </div>
          <div class="org-picker-dropdown" id="org-dropdown" style="display:none"></div>
        </div>
        <div class="form-text">Jika tidak dari organisasi atau tidak ada dalam daftar, isi dengan tanda <strong>-</strong> atau ketik manual.</div>
      </div>
      <?php endif; ?>
      <div class="form-group">
        <label>Pilih Ruangan <span style="color:#dc2626">*</span></label>
        <div class="room-picker" id="room-picker">
          <div class="room-picker-search" id="rp-search-wrap" onclick="document.getElementById('rp-input').focus()">
            <span class="room-picker-search-icon">🔍</span>
            <input type="text" id="rp-input" class="room-picker-search-field"
                   placeholder="Cari nama ruangan, gedung, departemen, atau kapasitas..."
                   autocomplete="off" spellcheck="false">
            <button type="button" id="rp-clear" class="room-picker-clear-btn" style="display:none" title="Hapus pencarian">✕</button>
          </div>
          <div class="room-picker-dropdown" id="rp-dropdown" style="display:none"></div>
          <div class="room-picker-selected" id="rp-selected" style="display:none"></div>
        </div>
        <select name="ruangan_id" id="room-select" style="display:none" required>
          <option value="">-- Pilih Ruangan --</option>
          <?php foreach ($rooms as $r): ?>
          <option value="<?= $r['id'] ?>" data-dept="<?= e($r['departemen']) ?>" data-tipe="<?= e($r['tipe']) ?>"><?= e($r['nama']) ?></option>
          <?php endforeach; ?>
        </select>
        <div id="room-info" class="alert alert-info mt-2" style="display:none"></div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label>Tanggal Mulai *</label>
          <input type="date" id="tgl-mulai" name="tanggal_mulai" class="form-control"
                 value="<?= e($_POST['tanggal_mulai'] ?? $preselect_date) ?>"
                 min="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
          <label>Tanggal Selesai *</label>
          <input type="date" id="tgl-selesai" name="tanggal_selesai" class="form-control"
                 value="<?= e($_POST['tanggal_selesai'] ?? $preselect_date) ?>"
                 min="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
          <label>Jam Mulai *</label>
          <input type="time" id="jam-mulai" name="jam_mulai" class="form-control"
                 value="<?= e($_POST['jam_mulai'] ?? $preselect_jam1) ?>" inputmode="numeric" required>
        </div>
        <div class="form-group">
          <label>Jam Selesai *</label>
          <input type="time" id="jam-selesai" name="jam_selesai" class="form-control"
                 value="<?= e($_POST['jam_selesai'] ?? $preselect_jam2) ?>" inputmode="numeric" required>
          <div id="jam-selesai-note" style="display:none;color:#dc2626;font-size:12.5px;margin-top:5px;font-weight:500"></div>
        </div>
      </div>
      <div id="avail-check" style="display:none;margin-bottom:16px"></div>
      <div class="form-group">
        <label>Jumlah Peserta *</label>
        <input type="number" name="jumlah_peserta" class="form-control"
               min="1" value="<?= e($_POST['jumlah_peserta'] ?? 1) ?>" required>
      </div>
      <div class="form-group">
        <label>Keterangan Tambahan</label>
        <textarea name="keterangan" class="form-control" rows="3"
                  placeholder="Deskripsi kegiatan, kebutuhan khusus..."><?= e($_POST['keterangan'] ?? '') ?></textarea>
      </div>
    </div>
  </div>

  <!-- ── Surat Peminjaman (opsional) ── -->
  <div class="card mb-4">
    <div class="card-header"><span class="card-title">📎 Surat Peminjaman <span style="font-size:12px;color:#94a3b8;font-weight:400">(opsional)</span></span></div>
    <div class="card-body">
      <div class="form-group">
        <label>Upload File Surat</label>
        <input type="file" id="input-surat" name="file_surat" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
        <div class="form-text">Format: PDF, DOC, DOCX, JPG, PNG. Maksimal 5MB. Lampirkan bila diperlukan.</div>
      </div>
    </div>
  </div>

  <button type="submit" class="btn btn-primary btn-lg btn-block">📤 Kirim Pengajuan Peminjaman</button>
</form>

<script>
const roomMap  = <?= json_encode($roomMap) ?>;
const roomsAll = <?= json_encode(array_values($rooms)) ?>;
const baseUrl  = '<?= base_url() ?>';
const PRE_ID   = <?= (int)($preselect ?: ($_POST['ruangan_id'] ?? 0)) ?>;

const deptLabel = {'Area Outdoor Fakultas Sains':'Admin DMF','Admin Fakultas Sains':'Admin Fakultas Sains'};
function deptDisplay(d){ return deptLabel[d] || d; }

function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function highlight(text, q){
  if(!q) return escHtml(text);
  const re = new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi');
  return escHtml(text).replace(re,'<mark class="rp-hl">$1</mark>');
}

/* ── Room Picker ── */
const rpInput    = document.getElementById('rp-input');
const rpDropdown = document.getElementById('rp-dropdown');
const rpSelected = document.getElementById('rp-selected');
const rpClear    = document.getElementById('rp-clear');
const rpSearch   = document.getElementById('rp-search-wrap');
const hiddenSel  = document.getElementById('room-select');
let   rpFocusIdx = -1;

function rpRenderDropdown(q){
  q = (q||'').trim();
  const ql = q.toLowerCase();
  const filtered = roomsAll.filter(r =>
    !ql ||
    r.nama.toLowerCase().includes(ql) ||
    r.gedung.toLowerCase().includes(ql) ||
    r.departemen.toLowerCase().includes(ql) ||
    String(r.kapasitas).includes(ql) ||
    r.tipe.toLowerCase().includes(ql)
  );
  if(!filtered.length){
    rpDropdown.innerHTML = '<div class="rp-empty"><div class="rp-empty-ic">🔍</div>Ruangan tidak ditemukan.<br><span style="font-size:12px">Coba kata kunci lain</span></div>';
    rpFocusIdx = -1; return;
  }
  const indoor  = filtered.filter(r => r.tipe==='indoor');
  const outdoor = filtered.filter(r => r.tipe==='outdoor');
  let html = '', idx = 0;
  function renderItems(arr){
    arr.forEach(r=>{
      html += `<div class="room-picker-item" data-id="${r.id}" data-idx="${idx++}" onmousedown="rpSelect(${r.id},event)">
        <div class="rpi-icon">${r.tipe==='indoor'?'🏢':'🌿'}</div>
        <div class="rpi-body">
          <div class="rpi-name">${highlight(r.nama, q)}</div>
          <div class="rpi-meta">
            <span class="rp-badge gedung">📍 ${highlight(r.gedung, q)}</span>
            <span class="rp-badge kap">👥 ${r.kapasitas} orang</span>
            <span class="rp-badge dept">🏫 ${highlight(deptDisplay(r.departemen), q)}</span>
            <span class="rp-badge ${r.tipe}">${r.tipe==='indoor'?'Indoor':'Outdoor'}</span>
          </div>
        </div>
      </div>`;
    });
  }
  if(indoor.length){ html += `<div class="room-picker-group-hd">🏢 Indoor (${indoor.length})</div>`; renderItems(indoor); }
  if(outdoor.length){ html += `<div class="room-picker-group-hd">🌿 Outdoor / DMF (${outdoor.length})</div>`; renderItems(outdoor); }
  rpDropdown.innerHTML = html;
  rpFocusIdx = -1;
}

function rpOpen(){ rpRenderDropdown(rpInput.value); rpDropdown.style.display = 'block'; }
function rpClose(){ rpDropdown.style.display = 'none'; rpFocusIdx = -1; }

function rpSelect(id, e){
  if(e) e.preventDefault();
  const room = roomsAll.find(r=>r.id==id);
  if(!room) return;
  hiddenSel.value = id;
  rpSearch.style.display  = 'none';
  rpDropdown.style.display= 'none';
  rpSelected.style.display= 'flex';
  rpSelected.innerHTML = `
    <div class="rps-icon">${room.tipe==='indoor'?'🏢':'🌿'}</div>
    <div class="rps-body">
      <div class="rps-name">${escHtml(room.nama)}</div>
      <div class="rps-meta">📍 ${escHtml(room.gedung)} &nbsp;·&nbsp; 👥 ${room.kapasitas} orang &nbsp;·&nbsp; 🏫 ${escHtml(deptDisplay(room.departemen))}</div>
    </div>
    <button type="button" class="rps-change" onclick="rpChange()">🔄 Ganti</button>`;
  checkAvail();
  updateRoomInfo(hiddenSel);
}

function rpChange(){
  hiddenSel.value = '';
  rpSelected.style.display = 'none';
  rpSearch.style.display   = 'flex';
  rpInput.value = '';
  rpClear.style.display = 'none';
  checkAvail();
  const ri = document.getElementById('room-info'); if (ri) ri.style.display = 'none';
  rpInput.focus();
  rpOpen();
}

rpInput.addEventListener('input', function(){
  rpClear.style.display = this.value ? 'block' : 'none';
  rpRenderDropdown(this.value);
  rpDropdown.style.display = 'block';
});
rpInput.addEventListener('focus', rpOpen);
rpClear.addEventListener('click', function(){
  rpInput.value=''; rpClear.style.display='none';
  rpRenderDropdown(''); rpInput.focus();
});

rpInput.addEventListener('keydown', function(e){
  const items = rpDropdown.querySelectorAll('.room-picker-item');
  if(!items.length) return;
  if(e.key==='ArrowDown'){ e.preventDefault(); if(rpFocusIdx < items.length-1) rpFocusIdx++; rpUpdateFocus(items); }
  else if(e.key==='ArrowUp'){ e.preventDefault(); if(rpFocusIdx > 0) rpFocusIdx--; rpUpdateFocus(items); }
  else if(e.key==='Enter'){ e.preventDefault(); if(rpFocusIdx>=0 && items[rpFocusIdx]) rpSelect(items[rpFocusIdx].dataset.id); }
  else if(e.key==='Escape'){ rpClose(); }
});
function rpUpdateFocus(items){
  items.forEach((el,i)=>{
    el.classList.toggle('rp-focus', i===rpFocusIdx);
    if(i===rpFocusIdx) el.scrollIntoView({block:'nearest'});
  });
}

document.addEventListener('click', function(e){
  if(!document.getElementById('room-picker').contains(e.target)) rpClose();
});

/* ══ Organisasi Picker (combobox kreatif) — hanya untuk admin DMF ══ */
if (document.getElementById('org-picker')) {
  <?php
  $_catMeta = dmfCatMeta();
  $_orgData = [['v'=>'-','icon'=>$_catMeta['umum']['icon'],'cat'=>'umum','color'=>$_catMeta['umum']['color']]];
  foreach (dmfOrmawaList() as $_o) {
      $_cm = $_catMeta[$_o['cat']] ?? ['icon'=>'🏷️','color'=>'#f1f5f9'];
      $_orgData[] = ['v'=>$_o['name'],'icon'=>$_cm['icon'],'cat'=>$_o['cat'],'color'=>$_cm['color']];
  }
  $_orgCats = [];
  foreach ($_catMeta as $_k=>$_cm) {
      $_orgCats[$_k] = ($_k==='umum')
          ? ['label'=>'Umum / Pribadi','plain'=>'Bukan dari organisasi']
          : ['label'=>$_cm['icon'].' '.$_cm['label'], 'plain'=>$_cm['label']];
  }
  $_orgOrder = array_keys($_catMeta);
  ?>
  const ORG_DATA  = <?= json_encode($_orgData, JSON_UNESCAPED_UNICODE) ?>;
  const ORG_CATS  = <?= json_encode($_orgCats, JSON_UNESCAPED_UNICODE) ?>;
  const ORG_ORDER = <?= json_encode($_orgOrder, JSON_UNESCAPED_UNICODE) ?>;

  const orgPicker   = document.getElementById('org-picker');
  const orgInput    = document.getElementById('org-input');
  const orgDropdown = document.getElementById('org-dropdown');
  const orgCaret    = document.getElementById('org-caret');
  const orgIcon     = document.getElementById('org-icon');
  let   orgFocusIdx = -1;

  const orgEsc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const orgHl  = (t,q)=>{ if(!q) return orgEsc(t); const re=new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'); return orgEsc(t).replace(re,'<mark class="org-hl">$1</mark>'); };
  const orgIconFor = v => { const o=ORG_DATA.find(x=>x.v.toLowerCase()===String(v||'').trim().toLowerCase()); return o?o.icon:'🏢'; };

  function orgRender(q){
    q=(q||'').trim(); const ql=q.toLowerCase();
    const sel=orgInput.value.trim().toLowerCase();
    const matches=ORG_DATA.filter(o=> !ql || o.v.toLowerCase().includes(ql) || ((ORG_CATS[o.cat]||{}).plain||'').toLowerCase().includes(ql));
    let html='', idx=0;
    ORG_ORDER.forEach(cat=>{
      const items=matches.filter(o=>o.cat===cat);
      if(!items.length) return;
      html+=`<div class="org-cat-hd">${(ORG_CATS[cat]||{}).label||cat}</div>`;
      items.forEach(o=>{
        const isSel = sel===o.v.toLowerCase();
        const name  = o.v==='-' ? 'Tidak ada / Pribadi' : o.v;
        const sub   = o.v==='-' ? 'Untuk kegiatan pribadi / bukan organisasi' : ((ORG_CATS[o.cat]||{}).plain||'');
        html+=`<div class="org-item${isSel?' org-selected':''}" data-v="${orgEsc(o.v)}" data-idx="${idx++}" onmousedown="orgSelect(this.dataset.v,event)">
          <div class="org-item-ic" style="background:${o.color||'#f1f5f9'}">${o.icon}</div>
          <div class="org-item-body">
            <div class="org-item-name">${orgHl(name,q)}</div>
            <div class="org-item-sub">${orgEsc(sub)}</div>
          </div>
          <span class="org-item-check">✓</span>
        </div>`;
      });
    });
    const exact = ORG_DATA.some(o=>o.v.toLowerCase()===ql);
    if(q && q!=='-' && !exact){
      html+=`<div class="org-custom" onmousedown="orgUseCustom(event)">➕ Gunakan: “<strong>${orgEsc(q)}</strong>”</div>`;
    }
    if(!html) html=`<div class="org-empty">🔍 Tidak ditemukan. Ketik untuk pakai nama sendiri.</div>`;
    orgDropdown.innerHTML=html;
    orgFocusIdx=-1;
  }

  function orgOpen(){ orgRender(''); orgDropdown.style.display='block'; orgPicker.classList.add('open'); }
  function orgClose(){ orgDropdown.style.display='none'; orgPicker.classList.remove('open'); orgFocusIdx=-1; }
  window.orgSelect = function(v,e){ if(e)e.preventDefault(); orgInput.value=v; orgIcon.textContent=orgIconFor(v); orgClose(); };
  window.orgUseCustom = function(e){ if(e)e.preventDefault(); orgIcon.textContent=orgIconFor(orgInput.value); orgClose(); };

  orgInput.addEventListener('focus', orgOpen);
  orgInput.addEventListener('input', function(){
    orgIcon.textContent=orgIconFor(this.value);
    orgRender(this.value); orgDropdown.style.display='block'; orgPicker.classList.add('open');
  });
  orgCaret.addEventListener('click', function(e){
    e.stopPropagation();
    if(orgDropdown.style.display==='block'){ orgClose(); }
    else { orgOpen(); orgInput.focus(); }
  });
  orgInput.addEventListener('keydown', function(e){
    const items=orgDropdown.querySelectorAll('.org-item');
    if(e.key==='ArrowDown'){ e.preventDefault(); if(!items.length) return; if(orgFocusIdx<items.length-1) orgFocusIdx++; orgUpdateFocus(items); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); if(orgFocusIdx>0) orgFocusIdx--; orgUpdateFocus(items); }
    else if(e.key==='Enter'){ if(orgFocusIdx>=0 && items[orgFocusIdx]){ e.preventDefault(); orgSelect(items[orgFocusIdx].dataset.v); } }
    else if(e.key==='Escape'){ orgClose(); }
  });
  function orgUpdateFocus(items){
    items.forEach((el,i)=>{ el.classList.toggle('org-focus', i===orgFocusIdx); if(i===orgFocusIdx) el.scrollIntoView({block:'nearest'}); });
  }
  document.addEventListener('click', function(e){
    if(!orgPicker.contains(e.target)) orgClose();
  });
  orgIcon.textContent = orgIconFor(orgInput.value);
}

function updateRoomInfo(sel) {
  const info = document.getElementById('room-info');
  if (!info) return;
  const id = sel.value;
  if (!id || !roomMap[id]) { info.style.display = 'none'; return; }
  const { dept, tipe } = roomMap[id];
  info.style.display = 'flex';
  info.textContent = 'ℹ️ Akan divalidasi oleh: ' +
    (tipe === 'outdoor' ? 'Admin DMF' : 'Admin Departemen ' + dept) +
    ' dan Admin Utama Fakultas Sains.';
}

/* ── Availability Check ── */
let avTimer = null;
let avReqId = 0;
function resetAvailBox() {
  const box = document.getElementById('avail-check');
  clearTimeout(avTimer);
  avReqId++;
  if (box) { box.style.display = 'none'; box.innerHTML = ''; }
}
function checkAvail() {
  const rid = hiddenSel.value;
  const tm  = document.getElementById('tgl-mulai').value;
  const ts  = document.getElementById('tgl-selesai').value;
  const jm  = document.getElementById('jam-mulai').value;
  const js  = document.getElementById('jam-selesai').value;
  const box = document.getElementById('avail-check');

  resetAvailBox();
  const myReq = avReqId;

  if (!rid || !tm || !ts || !jm || !js) return;
  if (tm === ts && js <= jm) return;

  box.style.display = 'block';
  box.innerHTML = '<div class="alert alert-info" style="margin-bottom:0">⏳ Memeriksa ketersediaan ruangan...</div>';

  avTimer = setTimeout(async () => {
    try {
      const url = baseUrl + '/user/check_availability.php?ruangan_id=' + rid +
        '&tanggal_mulai='   + encodeURIComponent(tm) +
        '&tanggal_selesai=' + encodeURIComponent(ts) +
        '&jam_mulai='       + encodeURIComponent(jm) +
        '&jam_selesai='     + encodeURIComponent(js);
      const res  = await fetch(url);
      const data = await res.json();
      if (myReq !== avReqId) return;
      if (data.available === null) { box.style.display = 'none'; box.innerHTML = ''; return; }
      if (data.available) {
        box.innerHTML = '<div class="alert alert-success" style="margin-bottom:0">✅ Ruangan tersedia pada waktu yang dipilih!</div>';
      } else {
        let html = '<div class="alert alert-warning" style="margin-bottom:0"><strong>⚠️ Ruangan sudah dibooking pada waktu ini!</strong><ul style="margin:8px 0 0 16px">';
        data.conflicts.forEach(c => {
          const s = c.status === 'disetujui' ? '✅ Disetujui' : '⏳ Menunggu';
          html += '<li>' + escHtml(c.nama_kegiatan) + ' — ' + c.tanggal_mulai + ' ' + c.jam_mulai +
                  ' s/d ' + c.tanggal_selesai + ' ' + c.jam_selesai + ' [' + s + ']</li>';
        });
        html += '</ul><p style="margin-top:8px;font-size:13px">Anda tetap dapat mengajukan, namun mungkin tidak disetujui.</p></div>';
        box.innerHTML = html;
      }
    } catch(e) {
      if (myReq === avReqId) { box.style.display = 'none'; box.innerHTML = ''; }
    }
  }, 500);
}

/* ── Validasi Jam Selesai > Jam Mulai ── */
function validateJam() {
  const tglMulaiEl = document.getElementById('tgl-mulai');
  const tglSelEl   = document.getElementById('tgl-selesai');
  const jamMulaiEl = document.getElementById('jam-mulai');
  const jamSelEl   = document.getElementById('jam-selesai');
  const note       = document.getElementById('jam-selesai-note');

  const tm = tglMulaiEl.value, ts = tglSelEl.value;
  const jm = jamMulaiEl.value, js = jamSelEl.value;

  if (tm) tglSelEl.min = tm;

  if (tm && ts && tm === ts && jm) {
    const [hh, mm] = jm.split(':').map(Number);
    const minTotal = hh * 60 + mm + 1;
    const minHH = String(Math.floor(minTotal / 60) % 24).padStart(2,'0');
    const minMM = String(minTotal % 60).padStart(2,'0');
    jamSelEl.min = minHH + ':' + minMM;
    if (js && js <= jm) {
      jamSelEl.style.borderColor = '#dc2626';
      jamSelEl.style.boxShadow   = '0 0 0 3px rgba(220,38,38,.12)';
      note.innerHTML = '⚠️ Jam selesai harus lebih dari <strong>' + jm + '</strong>';
      note.style.display = 'block';
    } else {
      jamSelEl.style.borderColor = '';
      jamSelEl.style.boxShadow   = '';
      note.style.display = 'none';
    }
  } else {
    jamSelEl.min               = '';
    jamSelEl.style.borderColor = '';
    jamSelEl.style.boxShadow   = '';
    note.style.display         = 'none';
  }
}

['tgl-mulai','tgl-selesai','jam-mulai','jam-selesai'].forEach(id => {
  const el = document.getElementById(id);
  if (!el) return;
  ['change','input'].forEach(ev =>
    el.addEventListener(ev, function(){ validateJam(); checkAvail(); }));
});

document.querySelector('form').addEventListener('submit', function(e) {
  const tm = document.getElementById('tgl-mulai').value;
  const ts = document.getElementById('tgl-selesai').value;
  const jm = document.getElementById('jam-mulai').value;
  const js = document.getElementById('jam-selesai').value;
  if (ts < tm) { e.preventDefault(); document.getElementById('tgl-selesai').focus(); return; }
  if (tm === ts && jm && js && js <= jm) {
    e.preventDefault();
    document.getElementById('jam-selesai').focus();
    document.getElementById('jam-selesai-note').style.display = 'block';
  }
});

window.addEventListener('load', () => {
  if (PRE_ID) rpSelect(PRE_ID);
  setTimeout(checkAvail, 100);
});
window.addEventListener('pageshow', (e) => { if (e.persisted) checkAvail(); });
</script>
<?php endif; ?>

<!-- ═══ MODAL IMPORT CSV ═══ -->
<div id="modal-import" class="modal-overlay">
  <div class="modal-box" style="max-width:560px">
    <div class="modal-header">
      <span class="modal-title">📤 Import Pengajuan dari CSV</span>
      <button class="modal-close" onclick="closeModal('modal-import')">✕</button>
    </div>
    <div class="modal-body">
      <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;padding:14px 16px;margin-bottom:16px">
        <div style="font-weight:700;color:#15803d;font-size:13.5px;margin-bottom:10px">📋 Cara pakai:</div>
        <ol style="margin:0;padding-left:20px;font-size:13px;color:#374151;line-height:2">
          <li>Klik <strong>Unduh Template</strong> → buka file <strong>.xlsx</strong> di Excel/LibreOffice</li>
          <li>Isi data pengajuan mulai <strong>baris ke-4</strong> (gunakan dropdown di kolom Departemen)</li>
          <li>Kolom <strong>Nama Ruangan</strong> harus sama persis dengan nama ruangan di sistem</li>
          <li>Tanggal format <strong>YYYY-MM-DD</strong>, jam format <strong>HH:MM</strong></li>
          <li>Simpan sebagai <strong>CSV</strong>: <em>File → Simpan Sebagai → CSV</em><br>
              <span style="font-size:12px;color:#16a34a">(koma <em>atau</em> titik koma, keduanya didukung otomatis)</span></li>
          <li>Upload file <strong>.csv</strong> di form bawah</li>
        </ol>
      </div>
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12.5px;color:#1e40af">
        ℹ️ Semua pengajuan dicatat atas nama <strong><?= e($user['nama']) ?></strong> dengan status <strong>Menunggu</strong>.
      </div>
      <div id="import-result" style="display:none;margin-bottom:12px"></div>
      <form id="import-form" enctype="multipart/form-data">
        <div class="form-group">
          <label>Pilih File CSV *</label>
          <input type="file" name="file_import" id="file-import" class="form-control" accept=".csv" required>
          <div class="fs-xs text-muted mt-1">Maks 5MB · format .csv</div>
        </div>
        <div class="d-flex gap-2 mt-3">
          <button type="button" class="btn btn-primary" onclick="doImportAjukan()">📤 Mulai Import</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-import')">Batal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const importAjukanUrl = '<?= base_url('admin/import_ajukan.php') ?>';
function openModal(id) { document.getElementById(id).classList.add('show'); }
function closeModal(id){ document.getElementById(id).classList.remove('show'); }

async function doImportAjukan() {
    const fi = document.getElementById('file-import');
    if (!fi.files[0]) { alert('Pilih file CSV terlebih dahulu.'); return; }
    const fd  = new FormData(document.getElementById('import-form'));
    const btn = document.querySelector('#modal-import .btn-primary');
    btn.disabled = true; btn.textContent = '⏳ Mengimpor...';
    try {
        const res  = await fetch(importAjukanUrl, { method:'POST', body:fd });
        const data = await res.json();
        const div  = document.getElementById('import-result');
        div.style.display = 'block';
        if (data.ok) {
            let html = `<div class="alert alert-success">✅ Berhasil diajukan: <strong>${data.inserted}</strong> peminjaman. Dilewati: ${data.skipped}.</div>`;
            if (data.errors && data.errors.length) {
                html += '<div class="alert alert-danger" style="max-height:180px;overflow-y:auto;font-size:12px"><strong>⚠️ Baris bermasalah:</strong><ul style="margin:6px 0 0 16px">';
                data.errors.forEach(e => { html += `<li>${e}</li>`; });
                html += '</ul></div>';
            }
            div.innerHTML = html;
            if (data.inserted > 0) setTimeout(()=>{ location.href = '<?= base_url('admin/bookings.php') ?>'; }, 1600);
        } else {
            div.innerHTML = `<div class="alert alert-danger">❌ ${data.msg}</div>`;
        }
    } catch(err) {
        const div = document.getElementById('import-result');
        div.style.display = 'block';
        div.innerHTML = `<div class="alert alert-danger">❌ Terjadi error: ${err.message}</div>`;
    } finally {
        btn.disabled = false; btn.textContent = '📤 Mulai Import';
    }
}

/* Tutup modal saat klik backdrop */
document.querySelectorAll('.modal-overlay').forEach(m =>
    m.addEventListener('click', function(e){ if(e.target===this) this.classList.remove('show'); }));
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
