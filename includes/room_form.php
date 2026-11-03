<?php
/**
 * Partial form: tambah/edit ruangan
 * Dipakai di modal ADD dan modal EDIT pada admin/rooms.php
 * Variabel tersedia dari parent: $user, $departemen_list
 */
$_depts = $departemen_list ?? ['Matematika','Fisika','Kimia','Biologi','Pendidikan IPA','Sains Terapan'];
$_orgs  = $organisasi_list ?? [
    ['value'=>'Admin Fakultas Sains',        'label'=>'Admin Fakultas Sains',                  'desc'=>'Admin Utama Fakultas Sains'],
    ['value'=>'Area Outdoor Fakultas Sains', 'label'=>'Dewan Mahasiswa Fakultas Sains','desc'=>'Organisasi mahasiswa pengelola area outdoor'],
];
$_locked_dept = isset($user) && in_array($user['role'], ['admin_departemen','admin_dmf']);
$_locked_tipe = isset($user) && $user['role'] === 'admin_dmf';
?>
<?php $_gedungs = $gedung_list_db ?? []; ?>
<div class="form-group">
  <label>Gedung / Outdoor <span style="color:var(--danger)">*</span></label>
  <?php if (empty($_gedungs)): ?>
    <input type="text" name="gedung" class="form-control" placeholder="Contoh: Gedung Matematika" required>
    <div class="form-text" style="color:#d97706">⚠️ Belum ada gedung. Tambahkan via tombol "Kelola Gedung/Outdoor".</div>
  <?php else: ?>
    <select name="gedung" id="sel-gedung" class="form-control" required>
      <option value="">-- Pilih Gedung/Outdoor --</option>
      <?php foreach ($_gedungs as $g): ?>
      <option value="<?= e($g['nama']) ?>" data-tipe="<?= e($g['tipe']) ?>">
        <?= $g['tipe']==='outdoor' ? '🌿' : '🏢' ?> <?= e($g['nama']) ?>
      </option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>
</div>
<div class="form-group">
  <label>Nama Ruangan <span style="color:var(--danger)">*</span></label>
  <input type="text" name="nama" class="form-control" placeholder="Contoh: Ruang 302" required>
  <div class="nama-auto-note" style="display:none;margin-top:4px;font-size:11.5px;color:#059669">🌿 Otomatis diisi dari nama gedung outdoor</div>
</div>
<div class="form-grid-2">
  <div class="form-group">
    <label>Departemen/Admin <span style="color:var(--danger)">*</span></label>
    <?php if ($_locked_dept): ?>
      <?php $fixedDept = isset($user) && $user['role']==='admin_dmf' ? 'Area Outdoor Fakultas Sains' : ($user['departemen'] ?? ''); ?>
      <?php $fixedLabel = isset($user) && $user['role']==='admin_dmf' ? 'Dewan Mahasiswa Fakultas Sains' : e($fixedDept); ?>
      <input type="text" class="form-control" value="<?= $fixedLabel ?>" disabled>
      <input type="hidden" name="departemen" value="<?= e($fixedDept) ?>">
    <?php else: ?>
      <select name="departemen" class="form-control" id="sel-departemen">
        <optgroup label="── Departemen Fakultas Sains ──">
          <?php foreach ($_depts as $d): ?>
            <option value="<?= e($d) ?>"><?= e($d) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <?php if (!empty($_orgs)): ?>
        <optgroup label="── Admin / Organisasi ──">
          <?php foreach ($_orgs as $o): ?>
            <option value="<?= e($o['value']) ?>" data-org="1"><?= e($o['label']) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <?php endif; ?>
      </select>
    <?php endif; ?>
  </div>
  <div class="form-group">
    <label>Tipe <span style="color:var(--danger)">*</span></label>
    <?php if ($_locked_tipe): ?>
      <input type="text" class="form-control" value="🌿 Outdoor" disabled>
      <input type="hidden" name="tipe" value="outdoor">
    <?php elseif (isset($user) && $user['role']==='admin_departemen'): ?>
      <input type="text" class="form-control" value="🏢 Indoor" disabled>
      <input type="hidden" name="tipe" value="indoor">
    <?php else: ?>
      <select name="tipe" class="form-control">
        <option value="indoor">🏢 Indoor</option>
        <option value="outdoor">🌿 Outdoor</option>
      </select>
    <?php endif; ?>
  </div>
</div>
<div class="form-group">
  <label>Kapasitas (orang) <span style="color:var(--danger)">*</span></label>
  <input type="number" name="kapasitas" class="form-control" min="1" value="30" required>
</div>
<div class="form-group">
  <label>Fasilitas <small class="text-muted">(pisahkan dengan koma)</small></label>
  <input type="text" name="fasilitas" class="form-control" placeholder="Proyektor, AC, Whiteboard, Sound System">
</div>
<div class="form-group">
  <label>Deskripsi</label>
  <textarea name="deskripsi" class="form-control" rows="2" placeholder="Deskripsi singkat tentang ruangan..."></textarea>
</div>
<!-- FOTO -->
<div class="form-group">
  <label>📷 Foto Ruangan / Gedung</label>
  <div id="edit-current-photo" style="margin-bottom:8px"></div>
  <input type="file" name="foto" class="form-control"
    accept="image/jpeg,image/png,image/webp"
    onchange="previewImgLocal(this)">
  <div id="foto-preview-wrap" class="img-preview-wrap" style="margin-top:8px"></div>
  <div class="form-text">Format: JPG, PNG, WebP. Maks 5MB. Foto tampil ke pengguna.</div>
</div>
<div class="form-check">
  <input type="checkbox" name="tersedia" id="cb-tersedia-<?= uniqid() ?>" value="1" checked>
  <label>Ruangan tersedia untuk dipinjam</label>
</div>
<script>
function previewImgLocal(input) {
  var file = input.files[0];
  if (!file) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    var wrap = document.getElementById('foto-preview-wrap');
    if (wrap) wrap.innerHTML = '<img src="' + e.target.result + '" class="img-preview" alt="Preview">';
  };
  reader.readAsDataURL(file);
}

/* ── DMF auto-note + auto-tipe ── */
(function () {
  /* Tunggu DOM siap (form bisa di-include dua kali: add & edit modal) */
  document.querySelectorAll('#sel-departemen').forEach(function (sel) {
    function onDeptChange() {
      var opt    = sel.options[sel.selectedIndex];
      var isOrg  = opt && opt.getAttribute('data-org') === '1';
      var note   = sel.closest('.form-group').querySelector('#dept-note');
      if (note) note.style.display = isOrg ? 'block' : 'none';

      /* Auto-switch Tipe ke Outdoor jika DMF dipilih */
      var form   = sel.closest('form');
      if (!form) return;
      var tipeEl = form.querySelector('[name=tipe]');
      if (tipeEl && tipeEl.tagName === 'SELECT') {
        if (isOrg) tipeEl.value = 'outdoor';
      }
    }
    sel.addEventListener('change', onDeptChange);
    onDeptChange(); /* jalankan saat pertama kali (untuk pre-selected edit) */
  });
})();

/* ── Auto-tipe + auto-nama + auto-dept berdasarkan gedung yang dipilih ── */
/* Gedung outdoor yang dikelola Admin Utama (Admin Fakultas Sains) */
var GEDUNG_ADMIN_FACULTY = ['Loket Fakultas Sains', 'Kantin Fakultas Sains', 'Parkiran Fakultas Sains'];

document.querySelectorAll('#sel-gedung').forEach(function(sel){
  function onGedungChange(){
    var opt  = sel.options[sel.selectedIndex];
    var tipe = opt && opt.getAttribute('data-tipe');
    var form = sel.closest('form');
    if (!form) return;

    /* Auto-tipe */
    if (tipe) {
      var tipeEl = form.querySelector('[name=tipe]');
      if (tipeEl && tipeEl.tagName === 'SELECT') tipeEl.value = tipe;
    }

    /* Auto-nama: hanya untuk outdoor */
    var namaEl   = form.querySelector('[name=nama]');
    var namaNote = form.querySelector('.nama-auto-note');
    if (namaEl) {
      if (tipe === 'outdoor' && opt && opt.value) {
        namaEl.value              = opt.value;
        namaEl.readOnly           = true;
        namaEl.style.background   = '#f0fdf4';
        namaEl.style.color        = '#065f46';
        namaEl._gedungAutoFill    = true;
        if (namaNote) namaNote.style.display = 'block';
      } else {
        if (namaEl._gedungAutoFill) namaEl.value = '';
        namaEl._gedungAutoFill    = false;
        namaEl.readOnly           = false;
        namaEl.style.background   = '';
        namaEl.style.color        = '';
        if (namaNote) namaNote.style.display = 'none';
      }
    }

    /* Auto-dept: hanya untuk outdoor */
    var deptEl = form.querySelector('[name=departemen]');
    if (deptEl && deptEl.tagName === 'SELECT' && tipe === 'outdoor' && opt && opt.value) {
      var isAdminFaculty = GEDUNG_ADMIN_FACULTY.indexOf(opt.value) !== -1;
      deptEl.value = isAdminFaculty ? 'Admin Fakultas Sains' : 'Area Outdoor Fakultas Sains';
      /* Trigger dept change agar auto-tipe DMF juga jalan */
      deptEl.dispatchEvent(new Event('change'));
    }
  }
  sel.addEventListener('change', onGedungChange);
});
</script>
