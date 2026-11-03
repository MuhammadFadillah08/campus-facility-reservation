<?php
/**
 * Partial form: tambah/edit pengguna
 * Dipakai di modal ADD dan modal EDIT pada admin/users.php
 * Variabel dari parent: $dept_list
 */
$_dept_list = $dept_list ?? ['Matematika','Fisika','Kimia','Biologi','Pendidikan IPA','Sains Terapan'];
?>
<div class="form-group">
  <label>Nama Lengkap <span style="color:var(--danger)">*</span></label>
  <input type="text" name="nama" class="form-control" placeholder="Nama lengkap" required>
</div>
<div class="form-group">
  <label>Email Kampus <span style="color:var(--danger)">*</span></label>
  <input type="email" name="email" class="form-control" placeholder="nama@university.example" required>
</div>
<div class="form-group">
  <label>Role <span style="color:var(--danger)">*</span></label>
  <select name="role" class="form-control" onchange="toggleUserFields(this.value)">
    <option value="user">👤 Mahasiswa</option>
    <option value="admin_departemen">🏫 Admin Departemen</option>
    <option value="admin_dmf">🌿 Admin DMF</option>
    <option value="admin_utama">👑 Admin Utama</option>
  </select>
</div>
<!-- WhatsApp field (admin utama & departemen) -->
<div class="admin-field" style="display:none">
  <div class="form-group">
    <label>Nomor WhatsApp <span class="text-muted" style="font-weight:400">(untuk konfirmasi pengajuan)</span></label>
    <input type="text" name="no_wa" class="form-control" maxlength="50" placeholder="Contoh: 081234567890">
    <div class="form-text">Pemohon akan diarahkan ke WhatsApp ini setelah mengirim pengajuan ruangan/gedung terkait.</div>
  </div>
</div>
<!-- 4 Grup WhatsApp (khusus Admin DMF, routing per asal ormawa) -->
<div class="dmf-field" style="display:none">
  <div class="form-group">
    <label>Link Grup WhatsApp DMF <span class="text-muted" style="font-weight:400">(routing per asal ormawa)</span></label>
    <div class="form-text" style="margin-bottom:10px">Tempel link grup (<code>https://chat.whatsapp.com/...</code>) untuk tiap grup.
      Konfirmasi pemohon otomatis diarahkan ke grup sesuai <strong>asal organisasi</strong> yang dipilih saat mengajukan.</div>
    <?php foreach (dmfGroupMap() as $gi => $list): ?>
    <div style="margin-bottom:9px">
      <label style="font-size:12.5px;font-weight:700;color:#7c3aed">Grup <?= $gi ?>
        <span class="text-muted" style="font-weight:400">— <?= e(implode(', ', $list)) ?></span></label>
      <input type="text" name="wa_group[<?= $gi ?>]" class="form-control" placeholder="https://chat.whatsapp.com/...">
    </div>
    <?php endforeach; ?>
  </div>
</div>
<!-- Dept field (admin_departemen only) -->
<div class="dept-field" style="display:none">
  <div class="form-group">
    <label>Departemen</label>
    <select name="departemen" class="form-control">
      <?php foreach ($_dept_list as $d): ?>
        <option value="<?= e($d) ?>"><?= e($d) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>
<!-- User fields (mahasiswa only) -->
<div class="user-field">
  <div class="form-group">
    <label>NIM</label>
    <input type="text" name="nim" class="form-control" placeholder="NIM mahasiswa">
  </div>
  <div class="form-group">
    <label>Program Studi</label>
    <input type="text" name="prodi" class="form-control" placeholder="Contoh: S1 Matematika">
  </div>
</div>
