<?php
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('admin_utama', 'admin_departemen', 'admin_dmf');
$isUtama = $user['role'] === 'admin_utama';   // hanya admin utama yang punya akses penuh
$page_title = 'Kelola Pengguna';
$db = db();
ensureColumn($db, 'users', 'no_wa', "VARCHAR(50) DEFAULT NULL AFTER email");
ensureColumnWidth($db, 'users', 'no_wa', 50, "VARCHAR(50) DEFAULT NULL");
ensureColumn($db, 'users', 'wa_groups', "TEXT DEFAULT NULL AFTER no_wa");
// Normalisasi penamaan lama: "DMF" → "DMF" (idempotent)
$db->query("UPDATE users SET nama=REPLACE(nama,'DMF','DMF') WHERE nama LIKE '%DMF%'");

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'';
    if ($action==='add'||$action==='edit') {
        $id   =(int)($_POST['id']??0);
        // ── Pembatasan tupoksi: non-utama tidak boleh menambah & hanya boleh mengedit akun sendiri ──
        if (!$isUtama && $action==='add') { flash('error','Anda tidak berwenang menambah pengguna.'); redirect(base_url('admin/users.php')); }
        if (!$isUtama && $action==='edit' && $id!==(int)$user['id']) { flash('error','Anda hanya dapat mengedit akun Anda sendiri.'); redirect(base_url('admin/users.php')); }

        $email=trim($_POST['email']??'');
        $nama =trim($_POST['nama']??'');
        if ($isUtama) {
            $role =$_POST['role']??'user';
            if (!in_array($role,['admin_utama','admin_departemen','admin_dmf','user'])) $role='user';
            $dept =$role==='admin_departemen'?trim($_POST['departemen']??''):null;
            $nim  =$role==='user'?trim($_POST['nim']??''):null;
            $prodi=$role==='user'?trim($_POST['prodi']??''):null;
        } else {
            // Akun sendiri: role & departemen dikunci ke nilai lama (tidak bisa diubah)
            $cur =$db->query("SELECT role,departemen FROM users WHERE id=$id")->fetch_assoc();
            $role =$cur['role']??'user';
            $dept =$cur['departemen'];
            $nim  =null; $prodi=null;
        }
        // Nomor WA hanya untuk admin utama/departemen (DMF pakai 4 grup, bukan no_wa)
        $no_wa=in_array($role,['admin_utama','admin_departemen'])?(trim($_POST['no_wa']??'')?:null):null;
        // 4 link Grup WhatsApp khusus Admin DMF (disimpan 1 baris per grup, urut Grup 1..4)
        $wa_groups=null;
        if ($role==='admin_dmf') {
            $g=$_POST['wa_group']??[];
            $lines=[];
            for ($i=1;$i<=4;$i++) $lines[]=trim($g[$i]??'');
            $joined=implode("\n",$lines);
            $wa_groups=(trim($joined)==='')?null:$joined;
        }
        if ($action==='add') {
            $pass=password_hash($_POST['password']??'password',PASSWORD_DEFAULT);
            $s=$db->prepare("INSERT INTO users(email,password,nama,role,departemen,nim,prodi,no_wa,wa_groups)VALUES(?,?,?,?,?,?,?,?,?)");
            $s->bind_param('sssssssss',$email,$pass,$nama,$role,$dept,$nim,$prodi,$no_wa,$wa_groups);
            $s->execute()?flash('success',"Pengguna \"$nama\" ditambahkan."):flash('error','Email sudah terdaftar.');
        } else {
            $s=$db->prepare("UPDATE users SET email=?,nama=?,role=?,departemen=?,nim=?,prodi=?,no_wa=?,wa_groups=? WHERE id=?");
            $s->bind_param('ssssssssi',$email,$nama,$role,$dept,$nim,$prodi,$no_wa,$wa_groups,$id); $s->execute();
            if (!empty($_POST['password'])){$p=password_hash($_POST['password'],PASSWORD_DEFAULT);$ps=$db->prepare("UPDATE users SET password=? WHERE id=?");$ps->bind_param('si',$p,$id);$ps->execute();}
            // Segarkan sesi bila admin (non-utama) mengedit akunnya sendiri
            if ($id===(int)$user['id']){$_SESSION['user']['nama']=$nama;$_SESSION['user']['email']=$email;}
            flash('success','Data pengguna diperbarui.');
        }
        redirect(base_url('admin/users.php'));
    }
    if ($action==='save_landing_wa') {
        // Kontak WhatsApp pada landing page — hanya Admin Utama
        if (!$isUtama) { flash('error','Hanya Admin Utama yang dapat mengubah kontak landing page.'); redirect(base_url('admin/users.php')); }
        setSetting('landing_wa_indoor',  trim($_POST['landing_wa_indoor']  ?? ''));
        setSetting('landing_wa_outdoor', trim($_POST['landing_wa_outdoor'] ?? ''));
        flash('success','Kontak WhatsApp landing page berhasil disimpan.');
        redirect(base_url('admin/users.php'));
    }
    if ($action==='save_group_map') {
        // Simpan peta grup WhatsApp DMF (routing per asal organisasi)
        if ($user['role']!=='admin_dmf') {
            flash('error','Anda tidak berwenang mengubah pengaturan grup.');
            redirect(base_url('admin/users.php'));
        }
        $assign = $_POST['ormawa_group'] ?? [];
        $map = [1=>[],2=>[],3=>[],4=>[]];
        foreach (dmfAllOrmawa() as $o) {
            $g = (int)($assign[$o] ?? 1);
            if ($g<1 || $g>4) $g=1;
            $map[$g][] = $o;
        }
        setSetting('dmf_group_map', json_encode($map, JSON_UNESCAPED_UNICODE));
        flash('success','Pengaturan grup WhatsApp DMF berhasil diperbarui.');
        redirect(base_url('admin/users.php'));
    }
    if ($action==='add_ormawa') {
        if ($user['role']!=='admin_dmf') { flash('error','Anda tidak berwenang.'); redirect(base_url('admin/users.php')); }
        $nama_org = trim($_POST['ormawa_nama'] ?? '');
        $nama_org = str_replace(['[',']'],'',$nama_org);           // cegah pecah array name
        $cat_org  = $_POST['ormawa_cat'] ?? 'ukm';
        $validCats= array_keys(dmfCatMeta());
        if (!in_array($cat_org,$validCats,true) || $cat_org==='umum') $cat_org='ukm';
        if ($nama_org==='' || $nama_org==='-') {
            flash('error','Nama organisasi tidak boleh kosong.');
        } else {
            $list = dmfOrmawaList();
            $exists = false;
            foreach ($list as $o) if (strcasecmp($o['name'],$nama_org)===0) { $exists=true; break; }
            if ($exists) {
                flash('error',"Organisasi \"$nama_org\" sudah ada dalam daftar.");
            } else {
                $list[] = ['name'=>$nama_org,'cat'=>$cat_org];
                setSetting('dmf_ormawa_list', json_encode(array_values($list), JSON_UNESCAPED_UNICODE));
                flash('success',"Organisasi \"$nama_org\" berhasil ditambahkan.");
            }
        }
        redirect(base_url('admin/users.php'));
    }
    if ($action==='delete_ormawa') {
        if ($user['role']!=='admin_dmf') { flash('error','Anda tidak berwenang.'); redirect(base_url('admin/users.php')); }
        $nama_org = trim($_POST['ormawa_nama'] ?? '');
        $list = dmfOrmawaList();
        $new  = array_values(array_filter($list, fn($o)=>strcasecmp($o['name'],$nama_org)!==0));
        if (count($new) === count($list)) {
            flash('error',"Organisasi \"$nama_org\" tidak ditemukan.");
        } elseif (empty($new)) {
            flash('error','Minimal harus ada satu organisasi dalam daftar.');
        } else {
            setSetting('dmf_ormawa_list', json_encode($new, JSON_UNESCAPED_UNICODE));
            flash('success',"Organisasi \"$nama_org\" berhasil dihapus.");
        }
        redirect(base_url('admin/users.php'));
    }
    if ($action==='add_cat') {
        if ($user['role']!=='admin_dmf') { flash('error','Anda tidak berwenang.'); redirect(base_url('admin/users.php')); }
        $cat_label = trim($_POST['cat_label'] ?? '');
        $cat_label = str_replace(['[',']'],'',$cat_label);
        $cat_icon  = trim($_POST['cat_icon'] ?? '');
        if ($cat_icon==='') $cat_icon='🏷️';
        // Ambil hanya 1 karakter emoji pertama (cegah teks panjang)
        $cat_icon  = function_exists('mb_substr') ? mb_substr($cat_icon,0,2) : substr($cat_icon,0,4);
        if ($cat_label==='') {
            flash('error','Nama kategori tidak boleh kosong.');
            redirect(base_url('admin/users.php'));
        }
        $cats = dmfCatMeta();
        foreach ($cats as $c) if (strcasecmp($c['label'],$cat_label)===0) {
            flash('error',"Kategori \"$cat_label\" sudah ada.");
            redirect(base_url('admin/users.php'));
        }
        // Bangun key slug unik dari label
        $base = preg_replace('/[^a-z0-9]+/','_', strtolower($cat_label));
        $base = trim($base,'_'); if ($base==='') $base='kat';
        $key=$base; $n=2; while(isset($cats[$key])){ $key=$base.'_'.$n; $n++; }
        $palette = dmfCatPalette();
        $color   = $palette[count($cats) % count($palette)];
        $cats[$key] = ['icon'=>$cat_icon,'label'=>$cat_label,'color'=>$color];
        setSetting('dmf_cat_list', json_encode($cats, JSON_UNESCAPED_UNICODE));
        flash('success',"Kategori \"$cat_label\" berhasil ditambahkan.");
        redirect(base_url('admin/users.php'));
    }
    if ($action==='delete_cat') {
        if ($user['role']!=='admin_dmf') { flash('error','Anda tidak berwenang.'); redirect(base_url('admin/users.php')); }
        $key = trim($_POST['cat_key'] ?? '');
        if ($key==='umum') { flash('error','Kategori dasar tidak dapat dihapus.'); redirect(base_url('admin/users.php')); }
        $cats = dmfCatMeta();
        if (!isset($cats[$key])) { flash('error','Kategori tidak ditemukan.'); redirect(base_url('admin/users.php')); }
        // Tolak hapus bila masih dipakai organisasi
        foreach (dmfOrmawaList() as $o) if ($o['cat']===$key) {
            flash('error',"Kategori \"{$cats[$key]['label']}\" masih dipakai organisasi. Pindahkan dulu organisasinya.");
            redirect(base_url('admin/users.php'));
        }
        unset($cats[$key]);
        setSetting('dmf_cat_list', json_encode($cats, JSON_UNESCAPED_UNICODE));
        flash('success','Kategori berhasil dihapus.');
        redirect(base_url('admin/users.php'));
    }
    if ($action==='delete') {
        if (!$isUtama){flash('error','Anda tidak berwenang menghapus pengguna.');redirect(base_url('admin/users.php'));}
        $id=(int)$_POST['id'];
        if ($id===(int)$user['id']){flash('error','Tidak bisa hapus akun sendiri.');redirect(base_url('admin/users.php'));}
        $db->query("DELETE FROM users WHERE id=$id");
        flash('success','Pengguna dihapus.');
        redirect(base_url('admin/users.php'));
    }
}

$all_users=$db->query("SELECT * FROM users ORDER BY role,nama")->fetch_all(MYSQLI_ASSOC);
// Bagian "Akun Admin": utama lihat semua admin; non-utama hanya akun sendiri (sesuai tupoksi)
$admin_list = $isUtama
    ? array_filter($all_users, fn($u)=>$u['role']!=='user')
    : array_filter($all_users, fn($u)=>(int)$u['id']===(int)$user['id']);
$role_labels=['admin_utama'=>'Admin Utama','admin_departemen'=>'Admin Departemen','admin_dmf'=>'Admin DMF','user'=>'Mahasiswa'];
$role_colors=['admin_utama'=>'#f59e0b','admin_departemen'=>'#10b981','admin_dmf'=>'#8b5cf6','user'=>'#3b82f6'];
$dept_list=['Matematika','Fisika','Kimia','Biologi','Pendidikan IPA','Sains Terapan'];

// Peta grup WhatsApp DMF saat ini → mapping ormawa ke nomor grup (untuk editor)
$cur_group_map  = dmfGroupMap();
$ormawa_to_group = [];
foreach ($cur_group_map as $gi=>$list) foreach ($list as $o) $ormawa_to_group[$o]=$gi;
$can_edit_groups = ($user['role']==='admin_dmf');
$cur_ormawa = dmfOrmawaList();   // daftar organisasi + kategori
$cat_meta   = dmfCatMeta();      // kategori → ikon & label

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-center justify-between mb-4">
  <div><div class="section-title">👥 Kelola Pengguna</div>
    <div class="section-sub"><?= $isUtama ? 'Manajemen akun admin dan mahasiswa' : 'Kelola akun Anda dan lihat mahasiswa terdaftar' ?></div></div>
  <?php if($isUtama): ?>
  <button class="btn btn-primary" onclick="document.getElementById('modal-add-user').classList.add('show')">+ Tambah Pengguna</button>
  <?php endif; ?>
</div>

<div class="card mb-4">
  <div class="card-header"><span class="card-title"><?= $isUtama ? '⚙️ Akun Admin' : '⚙️ Akun Saya' ?></span></div>
  <div class="table-responsive"><table class="table">
    <thead><tr><th>Nama</th><th>Email</th><th>WhatsApp</th><th>Role</th><th>Departemen</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach($admin_list as $u): ?>
    <tr>
      <td class="fw-semibold"><?= e($u['nama']) ?></td>
      <td class="text-muted fs-sm"><?= e($u['email']) ?></td>
      <td class="fs-sm">
        <?php
        $u_groups = ($u['role']==='admin_dmf' && !empty($u['wa_groups']))
                  ? array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $u['wa_groups']))))
                  : [];
        ?>
        <?php if($u['role']==='admin_dmf'): ?>
          <?php if($u_groups): ?>
            <span style="display:inline-flex;align-items:center;gap:4px;color:#16a34a;font-weight:600">📲 <?= count($u_groups) ?>/4 grup WA</span>
          <?php else: ?>
            <span style="color:#cbd5e1">— grup belum diisi —</span>
          <?php endif; ?>
        <?php elseif(!empty($u['no_wa'])): ?>
          <a href="https://wa.me/<?= e(waNormalize($u['no_wa'])) ?>" target="_blank"
             style="display:inline-flex;align-items:center;gap:4px;color:#16a34a;font-weight:600;text-decoration:none">
            📱 <?= e($u['no_wa']) ?>
          </a>
        <?php else: ?>
          <span style="color:#cbd5e1">— belum diisi —</span>
        <?php endif; ?>
      </td>
      <td><span class="badge" style="background:<?= $role_colors[$u['role']] ?>22;color:<?= $role_colors[$u['role']] ?>"><?= $role_labels[$u['role']] ?></span></td>
      <td><?= e($u['departemen']??'—') ?></td>
      <td class="d-flex gap-2">
        <button class="btn btn-primary btn-sm" onclick='openEditUser(<?= htmlspecialchars(json_encode($u),ENT_QUOTES) ?>)'>Edit</button>
        <?php if($isUtama && (int)$u['id']!==(int)$user['id']): ?>
        <form method="post" onsubmit="return confirm('Hapus?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn btn-danger btn-sm">Hapus</button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php if($isUtama): ?>
<div class="card mb-4">
  <div class="card-header"><span class="card-title">💬 Kontak WhatsApp Landing Page</span></div>
  <div class="card-body">
    <div class="form-text" style="margin-bottom:14px;line-height:1.65">
      Nomor tujuan tombol <strong>“Butuh Bantuan? Chat dengan Kami”</strong> di halaman depan website.
      Pengunjung — termasuk <strong>masyarakat umum, dosen, tendik, dan instansi di luar kampus</strong> —
      dapat langsung menghubungi admin sesuai kebutuhan peminjaman.
      Kosongkan salah satu kolom untuk menyembunyikan kontak tersebut; kosongkan keduanya untuk menonaktifkan widget.
    </div>
    <form method="post">
      <input type="hidden" name="action" value="save_landing_wa">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px">
        <div class="form-group" style="margin-bottom:0">
          <label style="font-weight:700;font-size:13px;display:block;margin-bottom:6px">🏢 WhatsApp Peminjaman Indoor</label>
          <input type="text" name="landing_wa_indoor" class="form-control" placeholder="08xxxxxxxxxx"
                 value="<?= e(getSetting('landing_wa_indoor','') ?? '') ?>">
          <div class="form-text">Ruang kuliah, laboratorium, aula &amp; seminar — dilayani Admin Fakultas Sains</div>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label style="font-weight:700;font-size:13px;display:block;margin-bottom:6px">🌿 WhatsApp Peminjaman Outdoor</label>
          <input type="text" name="landing_wa_outdoor" class="form-control" placeholder="08xxxxxxxxxx"
                 value="<?= e(getSetting('landing_wa_outdoor','') ?? '') ?>">
          <div class="form-text">Lapangan, taman, gazebo &amp; area terbuka — dilayani Admin DMF</div>
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:15px">💾 Simpan Kontak</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if($can_edit_groups): ?>
<style>
.org-mgr-list{display:flex;flex-direction:column;gap:8px;margin-bottom:16px;max-height:44vh;overflow-y:auto;padding-right:3px}
.org-mgr-item{display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid #eef2f7;border-radius:10px;padding:9px 11px}
.org-mgr-ic{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.org-mgr-ic.lembaga{background:#dbeafe}.org-mgr-ic.hmd{background:#ede9fe}.org-mgr-ic.hmps{background:#fce7f3}.org-mgr-ic.ukm{background:#fef3c7}
.org-mgr-body{flex:1;min-width:0}
.org-mgr-name{font-weight:700;font-size:13px;color:#1e293b;line-height:1.2}
.org-mgr-cat{font-size:11px;color:#94a3b8;margin-top:1px}
.org-mgr-del{flex-shrink:0;background:#fff;border:1px solid #fca5a5;color:#dc2626;width:30px;height:30px;border-radius:8px;cursor:pointer;font-size:13px;transition:all .15s}
.org-mgr-del:hover{background:#fee2e2}
.org-add-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:13px 14px}
.org-add-bar .form-group{margin-bottom:0}
.cat-mgr-list{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:12px}
.cat-chip{display:inline-flex;align-items:center;gap:7px;border:1px solid rgba(0,0,0,.08);border-radius:20px;padding:4px 6px 4px 12px;font-size:12px;font-weight:700;color:#334155}
.cat-chip-del{background:rgba(255,255,255,.7);border:none;color:#dc2626;width:20px;height:20px;border-radius:50%;cursor:pointer;font-size:11px;line-height:1;transition:all .15s;flex-shrink:0}
.cat-chip-del:hover{background:#fee2e2}
.cat-add-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;background:#faf5ff;border:1px solid #e9d5ff;border-radius:12px;padding:13px 14px}
.cat-add-bar .form-group{margin-bottom:0}
/* Tombol "Kelola Organisasi" — minimalis & profesional */
.btn-org-mgr{display:inline-flex;align-items:center;gap:9px;background:#fff;border:1.5px solid #c4b5fd;color:#6d28d9;padding:0 15px;height:42px;border-radius:10px;font-weight:700;font-size:13.5px;cursor:pointer;transition:all .18s;font-family:inherit}
.btn-org-mgr:hover{background:#f5f3ff;border-color:#a78bfa;transform:translateY(-1px);box-shadow:0 5px 16px rgba(124,58,237,.2)}
.btn-org-mgr .boi{width:26px;height:26px;border-radius:7px;background:#ede9fe;display:flex;align-items:center;justify-content:center;font-size:15px}
.btn-org-mgr .bob{background:#7c3aed;color:#fff;border-radius:20px;font-size:11px;font-weight:800;padding:1px 8px;min-width:20px;text-align:center}
.gm-preview{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:18px}
.gm-grp{border:1.5px solid #e2e8f0;border-radius:12px;padding:12px 13px;background:#fff}
.gm-grp-hd{font-weight:800;font-size:13px;margin-bottom:8px;display:flex;align-items:center;gap:7px}
.gm-grp-cnt{background:#f1f5f9;color:#475569;border-radius:20px;padding:0 8px;font-size:11px;font-weight:700}
.gm-pills{display:flex;flex-wrap:wrap;gap:5px}
.gm-pill{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;border-radius:20px;padding:2px 10px;font-size:11.5px;font-weight:600;white-space:nowrap}
.gm-empty-pill{color:#cbd5e1;font-size:12px;font-style:italic}
.gm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}
.gm-row{display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid #eef2f7;border-radius:10px;padding:8px 11px}
.gm-orm{flex:1;font-weight:700;font-size:13px;color:#1e293b;min-width:0}
.gm-select{width:auto;flex-shrink:0;min-width:96px}
</style>
<div class="card mb-4">
  <div class="card-header"><span class="card-title">🔀 Pengaturan Grup WhatsApp DMF</span></div>
  <div class="card-body">
    <div class="form-text" style="margin-bottom:14px">
      Atur ormawa mana yang masuk ke <strong>Grup 1–4</strong>. Saat pemohon memilih asal organisasi,
      konfirmasi WhatsApp otomatis diarahkan ke grup sesuai pengaturan ini. Bisa diubah kapan saja —
      cukup pindahkan grup lalu klik <strong>Simpan Pengaturan</strong>.
    </div>
    <div class="gm-preview" id="gm-preview"></div>
    <form method="post">
      <input type="hidden" name="action" value="save_group_map">
      <div class="gm-grid">
        <?php foreach (dmfAllOrmawa() as $o): $g=$ormawa_to_group[$o]??1; ?>
        <div class="gm-row">
          <span class="gm-orm"><?= e($o) ?></span>
          <select name="ormawa_group[<?= e($o) ?>]" class="form-control gm-select" data-orm="<?= e($o) ?>" onchange="gmRenderPreview()">
            <?php for($i=1;$i<=4;$i++): ?>
            <option value="<?= $i ?>" <?= $g===$i?'selected':'' ?>>Grup <?= $i ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="d-flex gap-2 mt-3 flex-wrap align-center">
        <button type="submit" class="btn btn-primary">💾 Simpan Pengaturan</button>
        <button type="button" class="btn-org-mgr" onclick="document.getElementById('modal-org-mgr').classList.add('show')">
          <span class="boi">🏢</span>
          <span>Kelola Organisasi</span>
          <span class="bob"><?= count($cur_ormawa) ?></span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ MODAL KELOLA ORGANISASI ═══ -->
<div id="modal-org-mgr" class="modal-overlay">
<div class="modal-box" style="max-width:560px">
  <div class="modal-header">
    <span class="modal-title">🏢 Kelola Organisasi</span>
    <button class="modal-close" onclick="document.getElementById('modal-org-mgr').classList.remove('show')">✕</button>
  </div>
  <div class="modal-body">
    <div class="form-text" style="margin-bottom:14px">
      Organisasi di sini otomatis muncul pada pilihan <strong>Asal Organisasi</strong> saat mahasiswa
      atau admin DMF mengajukan peminjaman.
    </div>
    <div class="org-mgr-list">
      <?php foreach ($cur_ormawa as $o):
        $ci = $cat_meta[$o['cat']] ?? ['icon'=>'🏷️','label'=>$o['cat'],'color'=>'#f1f5f9']; ?>
      <div class="org-mgr-item">
        <div class="org-mgr-ic" style="background:<?= e($ci['color'] ?? '#f1f5f9') ?>"><?= $ci['icon'] ?></div>
        <div class="org-mgr-body">
          <div class="org-mgr-name"><?= e($o['name']) ?></div>
          <div class="org-mgr-cat"><?= e($ci['label']) ?></div>
        </div>
        <form method="post" onsubmit="return confirm('Hapus organisasi &quot;<?= e($o['name']) ?>&quot; dari daftar?')">
          <input type="hidden" name="action" value="delete_ormawa">
          <input type="hidden" name="ormawa_nama" value="<?= e($o['name']) ?>">
          <button type="submit" class="org-mgr-del" title="Hapus organisasi">🗑️</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <form method="post" class="org-add-bar">
      <input type="hidden" name="action" value="add_ormawa">
      <div class="form-group" style="flex:1;min-width:180px">
        <label style="font-size:12.5px;font-weight:700">Nama Organisasi Baru</label>
        <input type="text" name="ormawa_nama" class="form-control" placeholder="Contoh: HMD INFORMATIKA" required>
      </div>
      <div class="form-group" style="min-width:160px">
        <label style="font-size:12.5px;font-weight:700">Kategori</label>
        <select name="ormawa_cat" class="form-control">
          <?php foreach ($cat_meta as $ck=>$cm): if($ck==='umum') continue; ?>
          <option value="<?= e($ck) ?>"><?= $cm['icon'] ?> <?= e($cm['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">➕ Tambah</button>
    </form>

    <!-- ── Kelola Kategori ── -->
    <div style="margin-top:20px;padding-top:16px;border-top:1px dashed #e2e8f0">
      <div style="font-weight:800;font-size:13.5px;color:#1e293b;margin-bottom:4px">🏷️ Kategori Organisasi</div>
      <div class="form-text" style="margin-bottom:12px">Kelompok untuk mengategorikan organisasi. Tambah kategori baru bila perlu.</div>
      <div class="cat-mgr-list">
        <?php foreach ($cat_meta as $ck=>$cm): if($ck==='umum') continue; ?>
        <div class="cat-chip" style="background:<?= e($cm['color']) ?>">
          <span><?= $cm['icon'] ?> <?= e($cm['label']) ?></span>
          <form method="post" onsubmit="return confirm('Hapus kategori &quot;<?= e($cm['label']) ?>&quot;?')" style="display:inline">
            <input type="hidden" name="action" value="delete_cat">
            <input type="hidden" name="cat_key" value="<?= e($ck) ?>">
            <button type="submit" class="cat-chip-del" title="Hapus kategori">✕</button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <form method="post" class="cat-add-bar">
        <input type="hidden" name="action" value="add_cat">
        <div class="form-group" style="width:70px">
          <label style="font-size:12.5px;font-weight:700">Ikon</label>
          <input type="text" name="cat_icon" class="form-control" maxlength="4" placeholder="🏷️" style="text-align:center;font-size:18px">
        </div>
        <div class="form-group" style="flex:1;min-width:170px">
          <label style="font-size:12.5px;font-weight:700">Nama Kategori Baru</label>
          <input type="text" name="cat_label" class="form-control" placeholder="Contoh: Komunitas Eksternal" required>
        </div>
        <button type="submit" class="btn btn-secondary">➕ Tambah Kategori</button>
      </form>
    </div>
  </div>
</div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><span class="card-title">👤 Mahasiswa Terdaftar</span></div>
  <div class="table-responsive"><table class="table">
    <thead><tr><th>Nama</th><th>Email</th><th>NIM</th><th>Prodi</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach(array_filter($all_users,fn($u)=>$u['role']==='user') as $u): ?>
    <tr>
      <td class="fw-semibold"><?= e($u['nama']) ?></td>
      <td class="text-muted fs-sm"><?= e($u['email']) ?></td>
      <td><?= e($u['nim']??'—') ?></td>
      <td><?= e($u['prodi']??'—') ?></td>
      <td class="d-flex gap-2">
        <?php if($isUtama): ?>
        <button class="btn btn-primary btn-sm" onclick='openEditUser(<?= htmlspecialchars(json_encode($u),ENT_QUOTES) ?>)'>Edit</button>
        <form method="post" onsubmit="return confirm('Hapus?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn btn-danger btn-sm">Hapus</button></form>
        <?php else: ?>
        <span class="text-muted fs-sm">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<!-- ADD MODAL -->
<div id="modal-add-user" class="modal-overlay">
<div class="modal-box"><div class="modal-header"><span class="modal-title">➕ Tambah Pengguna</span><button class="modal-close" onclick="document.getElementById('modal-add-user').classList.remove('show')">✕</button></div>
<form method="post"><input type="hidden" name="action" value="add">
<div class="modal-body" id="add-user-body">
  <?php include __DIR__.'/../includes/user_form.php'; ?>
  <div class="form-group"><label>Password *</label><input type="password" name="password" class="form-control" placeholder="Min 6 karakter" required></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="document.getElementById('modal-add-user').classList.remove('show')">Batal</button><button type="submit" class="btn btn-primary">Tambah</button></div>
</form></div></div>

<!-- EDIT MODAL -->
<div id="modal-edit-user" class="modal-overlay">
<div class="modal-box"><div class="modal-header"><span class="modal-title">✏️ Edit Pengguna</span><button class="modal-close" onclick="document.getElementById('modal-edit-user').classList.remove('show')">✕</button></div>
<form method="post" id="edit-user-form"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="eu-id">
<div class="modal-body">
  <?php include __DIR__.'/../includes/user_form.php'; ?>
  <div class="form-group"><label>Password Baru <small class="text-muted">(kosongkan jika tidak diubah)</small></label><input type="password" name="password" class="form-control" placeholder="Isi untuk ganti password"></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="document.getElementById('modal-edit-user').classList.remove('show')">Batal</button><button type="submit" class="btn btn-primary">Simpan</button></div>
</form></div></div>

<script>
const IS_UTAMA = <?= $isUtama ? 'true' : 'false' ?>;
function openEditUser(u){
  document.getElementById('modal-edit-user').classList.add('show');
  const f=document.getElementById('edit-user-form');
  document.getElementById('eu-id').value=u.id;
  const set=(n,v)=>{const el=f.querySelector('[name='+n+']');if(el)el.value=v||'';};
  set('email',u.email);set('nama',u.nama);set('nim',u.nim);set('prodi',u.prodi);set('departemen',u.departemen);set('no_wa',u.no_wa);
  // Isi 4 link grup WA (khusus DMF)
  const groups=(u.wa_groups||'').split(/\r\n|\r|\n/);
  for(let i=1;i<=4;i++){const el=f.querySelector('[name="wa_group['+i+']"]');if(el)el.value=(groups[i-1]||'').trim();}
  const roleEl=f.querySelector('[name=role]');if(roleEl){roleEl.value=u.role;roleEl.dispatchEvent(new Event('change'));}
  // Non-utama: role & departemen dikunci (hanya boleh edit akun sendiri sesuai tupoksi)
  if(!IS_UTAMA){
    const r=f.querySelector('[name=role]'); if(r) r.disabled=true;
    const d=f.querySelector('[name=departemen]'); if(d) d.disabled=true;
  }
}
function toggleUserFields(role){
  document.querySelectorAll('.user-field').forEach(el=>el.style.display=role==='user'?'':'none');
  document.querySelectorAll('.dept-field').forEach(el=>el.style.display=role==='admin_departemen'?'':'none');
  document.querySelectorAll('.dmf-field').forEach(el=>el.style.display=role==='admin_dmf'?'':'none');
  // no_wa hanya untuk admin utama & departemen (DMF pakai grup)
  document.querySelectorAll('.admin-field').forEach(el=>el.style.display=(role==='admin_utama'||role==='admin_departemen')?'':'none');
}
document.querySelectorAll('[name=role]').forEach(el=>el.addEventListener('change',e=>toggleUserFields(e.target.value)));
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');}));
window.addEventListener('load',()=>document.querySelectorAll('[name=role]').forEach(el=>toggleUserFields(el.value)));

/* ── Preview langsung peta Grup WhatsApp DMF ── */
function gmRenderPreview(){
  const prev=document.getElementById('gm-preview');
  if(!prev) return;
  const groups={1:[],2:[],3:[],4:[]};
  document.querySelectorAll('.gm-select').forEach(s=>{ (groups[s.value]||groups[1]).push(s.dataset.orm); });
  const colors={1:'#3b82f6',2:'#16a34a',3:'#f59e0b',4:'#8b5cf6'};
  let html='';
  for(let g=1;g<=4;g++){
    const pills=groups[g].length
      ? groups[g].map(o=>`<span class="gm-pill">${o}</span>`).join('')
      : '<span class="gm-empty-pill">— belum ada ormawa —</span>';
    html+=`<div class="gm-grp" style="border-color:${colors[g]}55">
      <div class="gm-grp-hd" style="color:${colors[g]}">📲 Grup ${g} <span class="gm-grp-cnt">${groups[g].length}</span></div>
      <div class="gm-pills">${pills}</div>
    </div>`;
  }
  prev.innerHTML=html;
}
window.addEventListener('load', gmRenderPreview);
</script>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
