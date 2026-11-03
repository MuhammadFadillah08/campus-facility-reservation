<?php
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('admin_utama','admin_departemen','admin_dmf');
$page_title = 'Jadwal Kuliah Reguler';
$db = db();

/* ── Auto-migrate tabel semester ── */
$db->query("CREATE TABLE IF NOT EXISTS semester (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    semester     VARCHAR(20)  NOT NULL,
    tahun_ajaran VARCHAR(10)  NOT NULL,
    tgl_mulai    DATE         NOT NULL,
    tgl_selesai  DATE         NOT NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sem (semester, tahun_ajaran)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Migrasi kolom semester (ENUM lama → VARCHAR) + pemulihan data ter-blank ──
   Semester "Antara" dulu ditolak ENUM('Ganjil','Genap') → tersimpan '' sehingga data
   tak muncul di daftar. Seluruh blok dibungkus try/catch agar galat migrasi TIDAK
   pernah menjatuhkan halaman (di PHP 8.1+ mysqli melempar exception saat query gagal). */
try {
    $semRes = $db->query(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jadwal_kuliah' AND COLUMN_NAME='semester'"
    );
    $semCol = $semRes ? $semRes->fetch_assoc() : null;
    if ($semCol && strtolower($semCol['DATA_TYPE'] ?? '') === 'enum') {
        $db->query("ALTER TABLE jadwal_kuliah MODIFY COLUMN semester VARCHAR(20) NOT NULL DEFAULT 'Ganjil'");
    }
    /* Pulihkan baris ter-blank ('' / NULL). Hanya 'Antara' yang bisa ter-blank
       (Ganjil/Genap valid di ENUM lama), jadi dipulihkan langsung TANPA JOIN —
       kompatibel dengan hosting yang membatasi UPDATE multi-tabel. Hanya jalan
       bila memang ada baris kosong (setelah pulih, tak dijalankan lagi). */
    $blkRes = $db->query("SELECT COUNT(*) c FROM jadwal_kuliah WHERE semester='' OR semester IS NULL");
    $blk    = $blkRes ? (int)($blkRes->fetch_assoc()['c'] ?? 0) : 0;
    if ($blk > 0) {
        $db->query("UPDATE jadwal_kuliah SET semester='Antara' WHERE semester='' OR semester IS NULL");
    }
} catch (\Throwable $e) {
    /* Abaikan — migrasi bersifat opsional; jangan sampai mengganggu halaman. */
}

/* ── Konstanta ── */
$HARI_LABELS = ['','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'];
$DEPT_LIST   = ['Matematika','Fisika','Kimia','Biologi','Pendidikan IPA','Sains Terapan','Area Outdoor Fakultas Sains'];

/* ── Tabel waktu kuliah ── */
$JAM_REGULER = [
    1=>['07:00','07:50'], 2=>['07:50','08:40'], 3=>['08:45','09:35'],
    4=>['09:35','10:25'], 5=>['10:30','11:20'], 6=>['11:20','12:10'],
    7=>['13:10','14:00'], 8=>['14:00','14:50'], 9=>['14:55','15:45'],
    10=>['15:45','16:35'],11=>['18:15','19:05'],12=>['19:05','19:55'],
];
$JAM_PUASA = [
    1=>['07:30','08:05'], 2=>['08:05','08:40'], 3=>['08:45','09:20'],
    4=>['09:20','09:55'], 5=>['10:00','10:35'], 6=>['10:35','11:10'],
    7=>['11:10','11:45'], 8=>['13:00','13:35'], 9=>['13:35','14:10'],
    10=>['14:10','14:45'],11=>['15:15','15:45'],12=>['15:45','16:15'],
];

/* ── Deteksi Ramadan via kalender Hijriah ── */
$isRamadan = false;
if (function_exists('cal_from_jd') && function_exists('gregoriantojd') && defined('CAL_ISLAMIC')) {
    $jd    = gregoriantojd((int)date('m'), (int)date('d'), (int)date('Y'));
    $hijri = cal_from_jd($jd, CAL_ISLAMIC);
    $isRamadan = ($hijri['month'] == 9);
}

/* ── Semester Aktif (dari session) ── */
$sched_aktif = $_SESSION['sched_semester_aktif'] ?? null;

/* ── Helper: deteksi Jam Ke dari jam_mulai tersimpan ── */
function detectJamKe(string $jam_mulai, array $reguler, array $puasa): array {
    $jm = substr($jam_mulai, 0, 5);
    foreach ($reguler as $k => $v) { if ($v[0] === $jm) return ['jam_ke'=>$k,'mode'=>'reguler']; }
    foreach ($puasa   as $k => $v) { if ($v[0] === $jm) return ['jam_ke'=>$k,'mode'=>'puasa']; }
    return ['jam_ke'=>0,'mode'=>'reguler'];
}

/* ── Helper: deteksi Jam Ke dari jam_selesai tersimpan ── */
function detectJamKeEnd(string $jam_selesai, array $reguler, array $puasa): array {
    $js = substr($jam_selesai, 0, 5);
    foreach ($reguler as $k => $v) { if ($v[1] === $js) return ['jam_ke'=>$k,'mode'=>'reguler']; }
    foreach ($puasa   as $k => $v) { if ($v[1] === $js) return ['jam_ke'=>$k,'mode'=>'puasa']; }
    return ['jam_ke'=>0,'mode'=>'reguler'];
}

/* ── Helper: tampilkan Jam Ke di tabel ── */
function getJamKe(string $jam_mulai, array $reguler, array $puasa): string {
    $jm = substr($jam_mulai, 0, 5);
    foreach ($reguler as $k => $v) { if ($v[0] === $jm) return 'Jam ke-'.$k; }
    foreach ($puasa   as $k => $v) { if ($v[0] === $jm) return 'Jam ke-'.$k.' 🌙'; }
    return '';
}

/* ── POST handler ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'set_semester') {
        $sem     = $_POST['semester']             ?? 'Ganjil';
        $ta      = trim($_POST['tahun_ajaran']    ?? '');
        $tgl_mul = $_POST['tgl_mulai_semester']   ?? '';
        $tgl_sel = $_POST['tgl_selesai_semester'] ?? '';
        /* Simpan / update di DB */
        $s = $db->prepare("INSERT INTO semester (semester,tahun_ajaran,tgl_mulai,tgl_selesai)
                           VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE tgl_mulai=VALUES(tgl_mulai),tgl_selesai=VALUES(tgl_selesai)");
        $s->bind_param('ssss', $sem, $ta, $tgl_mul, $tgl_sel);
        $s->execute();
        $_SESSION['sched_semester_aktif'] = [
            'semester'     => $sem,
            'tahun_ajaran' => $ta,
            'tgl_mulai'    => $tgl_mul,
            'tgl_selesai'  => $tgl_sel,
        ];
        redirect(base_url('admin/class_schedule.php'));
    } elseif ($action === 'select_semester') {
        $sid = (int)($_POST['semester_id'] ?? 0);
        $s = $db->prepare("SELECT * FROM semester WHERE id=?");
        $s->bind_param('i', $sid); $s->execute();
        $row = $s->get_result()->fetch_assoc();
        if ($row) {
            $_SESSION['sched_semester_aktif'] = [
                'semester'     => $row['semester'],
                'tahun_ajaran' => $row['tahun_ajaran'],
                'tgl_mulai'    => $row['tgl_mulai'],
                'tgl_selesai'  => $row['tgl_selesai'],
            ];
        }
        redirect(base_url('admin/class_schedule.php'));
    } elseif ($action === 'reset_semester') {
        unset($_SESSION['sched_semester_aktif']);
        redirect(base_url('admin/class_schedule.php'));
    } elseif ($action === 'add' || $action === 'edit') {
        $id            = (int)($_POST['id'] ?? 0);
        $ruangan_id    = (int)($_POST['ruangan_id'] ?? 0);
        $nama_matkul   = trim($_POST['nama_matkul'] ?? '');
        $kode_matkul   = trim($_POST['kode_matkul'] ?? '');
        $dosen         = trim($_POST['dosen'] ?? '');
        $prodi         = trim($_POST['prodi'] ?? '');
        $kelas         = trim($_POST['kelas'] ?? '');
        $hari          = (int)($_POST['hari'] ?? 0);
        $jam_mulai     = $_POST['jam_mulai'] ?? '';
        $jam_selesai   = $_POST['jam_selesai'] ?? '';
        /* Semester, tgl, tahun ajaran diambil dari sesi aktif — bukan dari form */
        $tgl_mulai    = $sched_aktif['tgl_mulai']    ?? '';
        $tgl_selesai  = $sched_aktif['tgl_selesai']  ?? '';
        $semester     = $sched_aktif['semester']     ?? 'Ganjil';
        $tahun_ajaran = $sched_aktif['tahun_ajaran'] ?? '';
        if (!$sched_aktif || !$tgl_mulai) {
            flash('error', 'Konfirmasi semester aktif terlebih dahulu.');
            redirect(base_url('admin/class_schedule.php'));
        }

        // Validate room access
        $rStmt = $db->prepare("SELECT id,departemen,tipe FROM ruangan WHERE id=?");
        $rStmt->bind_param('i',$ruangan_id); $rStmt->execute();
        $ruangan = $rStmt->get_result()->fetch_assoc();
        $dept_val = $ruangan['departemen'] ?? '';

        if (!$ruangan || !canValidate($user,$ruangan)) {
            flash('error','Ruangan tidak valid atau Anda tidak berwenang.');
        } elseif ($action === 'add') {
            $s = $db->prepare(
                "INSERT INTO jadwal_kuliah
                 (ruangan_id,nama_matkul,kode_matkul,dosen,prodi,kelas,departemen,
                  hari,jam_mulai,jam_selesai,tgl_mulai_semester,tgl_selesai_semester,
                  tahun_ajaran,semester,dibuat_oleh)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $s->bind_param('issssssissssssi',
                $ruangan_id,$nama_matkul,$kode_matkul,$dosen,$prodi,$kelas,$dept_val,
                $hari,$jam_mulai,$jam_selesai,$tgl_mulai,$tgl_selesai,
                $tahun_ajaran,$semester,$user['id']);
            $s->execute()
                ? flash('success','Jadwal berhasil ditambahkan.')
                : flash('error','Gagal: '.$db->error);
        } else {
            $s = $db->prepare(
                "UPDATE jadwal_kuliah SET
                 ruangan_id=?,nama_matkul=?,kode_matkul=?,dosen=?,prodi=?,kelas=?,
                 departemen=?,hari=?,jam_mulai=?,jam_selesai=?,tgl_mulai_semester=?,
                 tgl_selesai_semester=?,tahun_ajaran=?,semester=?
                 WHERE id=?"
            );
            $s->bind_param('issssssissssssi',
                $ruangan_id,$nama_matkul,$kode_matkul,$dosen,$prodi,$kelas,$dept_val,
                $hari,$jam_mulai,$jam_selesai,$tgl_mulai,$tgl_selesai,
                $tahun_ajaran,$semester,$id);
            $s->execute()
                ? flash('success','Jadwal berhasil diperbarui.')
                : flash('error','Gagal: '.$db->error);
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->query("DELETE FROM jadwal_kuliah WHERE id={$id}");
        flash('success','Jadwal berhasil dihapus.');
    }
    redirect(base_url('admin/class_schedule.php'));
}

/* ── Filters ── */
$filter_hari   = (int)($_GET['hari'] ?? 0);
$filter_dept   = $_GET['dept'] ?? '';
$filter_search = trim($_GET['q'] ?? '');

/* ── Rooms dropdown (filtered by role) ── */
$rWhere = [];
if ($user['role']==='admin_departemen') $rWhere[]="departemen='{$db->real_escape_string($user['departemen'])}'";
if ($user['role']==='admin_dmf')        $rWhere[]="tipe='outdoor'";
$rooms = $db->query(
    "SELECT id,nama,gedung,departemen,tipe FROM ruangan"
    .($rWhere?" WHERE ".implode(' AND ',$rWhere):'')." ORDER BY departemen,nama"
)->fetch_all(MYSQLI_ASSOC);

// Group rooms by department for JS filtering
$roomsByDept = [];
foreach ($rooms as $r) $roomsByDept[$r['departemen']][] = $r;

/* ── Schedule list query ── */
$where=[]; $params=[]; $types='';
if ($user['role']==='admin_departemen') { $where[]="r.departemen=?"; $params[]=$user['departemen']; $types.='s'; }
if ($user['role']==='admin_dmf')        { $where[]="r.tipe='outdoor'"; }
/* Auto-filter semester aktif dari session */
if ($sched_aktif) {
    $where[]="jk.semester=?";     $params[]=$sched_aktif['semester'];     $types.='s';
    $where[]="jk.tahun_ajaran=?"; $params[]=$sched_aktif['tahun_ajaran']; $types.='s';
}
if ($filter_hari)     { $where[]="jk.hari=?"; $params[]=$filter_hari; $types.='i'; }
if ($filter_dept)     { $where[]="jk.departemen=?"; $params[]=$filter_dept; $types.='s'; }
if ($filter_search)   {
    $like="%{$filter_search}%";
    $where[]="(jk.nama_matkul LIKE ? OR jk.dosen LIKE ? OR jk.kode_matkul LIKE ?)";
    $params[]=$like;$params[]=$like;$params[]=$like; $types.='sss';
}
$sql = "SELECT jk.*,r.nama ruangan_nama,r.gedung,r.departemen r_dept,r.tipe,u.nama dibuat_nama
        FROM jadwal_kuliah jk
        JOIN ruangan r ON jk.ruangan_id=r.id
        JOIN users u ON jk.dibuat_oleh=u.id"
     .($where?" WHERE ".implode(' AND ',$where):'')
     ." ORDER BY jk.hari,jk.jam_mulai";
$stmt=$db->prepare($sql);
if ($params) $stmt->bind_param($types,...$params);
$stmt->execute();
$schedules=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── Daftar semester tersimpan ── */
$saved_semesters = [];
$r_sav = $db->query("SELECT * FROM semester ORDER BY tahun_ajaran DESC, FIELD(semester,'Antara','Genap','Ganjil')");
if ($r_sav) $saved_semesters = $r_sav->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../includes/header.php';

/* ── Belum ada semester aktif → halaman pilih / tambah semester ── */
if (!$sched_aktif):
/* Warna badge per tipe semester */
$semBadge = function(string $s): string {
    return match($s) {
        'Ganjil' => 'background:#fef3c7;color:#92400e;border-color:#d97706',
        'Antara' => 'background:#dbeafe;color:#1e3a8a;border-color:#2563eb',
        default  => 'background:#dcfce7;color:#14532d;border-color:#16a34a',
    };
};
?>
<div style="max-width:560px;margin:40px auto">
  <div style="text-align:center;margin-bottom:22px">
    <div style="font-size:38px;margin-bottom:8px">📅</div>
    <div style="font-size:20px;font-weight:800;color:#166534;margin-bottom:5px">Pilih Semester Aktif</div>
    <div style="font-size:13px;color:#64748b;line-height:1.6">Pilih semester yang akan dikelola, atau tambahkan semester baru.</div>
  </div>

  <?php if ($saved_semesters): ?>
  <!-- ── Semester Tersimpan ── -->
  <div style="background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.06);margin-bottom:14px">
    <div style="background:#f8fafc;padding:11px 18px;border-bottom:1px solid #e2e8f0;font-weight:700;font-size:13px;color:#374151">
      📋 Semester Tersimpan
    </div>
    <?php foreach ($saved_semesters as $sv): ?>
    <form method="post" style="display:flex;align-items:center;justify-content:space-between;
          padding:12px 18px;border-bottom:1px solid #f1f5f9;gap:12px;flex-wrap:wrap">
      <input type="hidden" name="action" value="select_semester">
      <input type="hidden" name="semester_id" value="<?= $sv['id'] ?>">
      <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:0">
        <span style="<?= $semBadge($sv['semester']) ?>;border:1.5px solid;
                     font-weight:800;font-size:13px;padding:4px 13px;border-radius:20px;white-space:nowrap">
          <?= e($sv['semester']) ?> <?= e($sv['tahun_ajaran']) ?>
        </span>
        <span style="font-size:12px;color:#64748b;white-space:nowrap">
          📆 <?= date('d M Y', strtotime($sv['tgl_mulai'])) ?> – <?= date('d M Y', strtotime($sv['tgl_selesai'])) ?>
        </span>
      </div>
      <button type="submit" class="btn btn-primary btn-sm" style="white-space:nowrap">Pilih →</button>
    </form>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ── Tambah Semester Baru ── -->
  <details <?= !$saved_semesters ? 'open' : '' ?> style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.05)">
    <summary style="cursor:pointer;padding:13px 18px;background:#f0fdf4;font-weight:700;font-size:13.5px;
                    color:#166534;display:flex;align-items:center;justify-content:space-between;
                    user-select:none;list-style:none;border-bottom:1.5px solid #bbf7d0">
      <span>➕ Tambah Semester Baru</span>
      <span style="font-size:11px;opacity:.7">▼</span>
    </summary>
    <div style="padding:18px">
      <form method="post">
        <input type="hidden" name="action" value="set_semester">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
          <div class="form-group" style="margin:0">
            <label style="font-weight:700;color:#374151;font-size:13px">Semester *</label>
            <select name="semester" class="form-control" required>
              <option value="Ganjil">Ganjil</option>
              <option value="Genap">Genap</option>
              <option value="Antara">Antara</option>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-weight:700;color:#374151;font-size:13px">Tahun Ajaran *</label>
            <select name="tahun_ajaran" class="form-control" required>
              <option value="">— Pilih —</option>
              <?php $cy=(int)date('Y'); for($y=$cy-1;$y<=$cy+3;$y++): $ta=$y.'/'.($y+1); ?>
              <option value="<?= $ta ?>" <?= $y===$cy?'selected':'' ?>><?= $ta ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px">
          <div class="form-group" style="margin:0">
            <label style="font-weight:700;color:#374151;font-size:13px">Tgl Mulai *</label>
            <input type="date" name="tgl_mulai_semester" class="form-control" required>
          </div>
          <div class="form-group" style="margin:0">
            <label style="font-weight:700;color:#374151;font-size:13px">Tgl Selesai *</label>
            <input type="date" name="tgl_selesai_semester" class="form-control" required>
          </div>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;padding:12px;font-weight:700">
          💾 Simpan &amp; Aktifkan Semester
        </button>
      </form>
    </div>
  </details>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<!-- ══ Semester Banner ══ -->
<div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:12px;padding:12px 18px;margin-bottom:16px;
            display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span style="font-size:12.5px;color:#64748b;font-weight:600">📅 Semester Aktif:</span>
    <span style="font-size:15px;font-weight:800;color:#166534;background:#dcfce7;
                 padding:4px 14px;border-radius:20px;border:1.5px solid #86efac">
      <?= e($sched_aktif['semester']) ?> <?= e($sched_aktif['tahun_ajaran']) ?>
    </span>
    <span style="font-size:12px;color:#16a34a;font-weight:600">
      📆 <?= date('d-m-Y', strtotime($sched_aktif['tgl_mulai'])) ?> s/d
      <?= date('d-m-Y', strtotime($sched_aktif['tgl_selesai'])) ?>
    </span>
  </div>
  <form method="post" style="margin:0">
    <input type="hidden" name="action" value="reset_semester">
    <button type="submit" class="btn btn-secondary btn-sm" style="font-size:12px">🔄 Ganti Semester</button>
  </form>
</div>

<div class="d-flex align-center justify-between mb-4">
  <div>
    <div class="section-title">📚 Jadwal Kuliah Reguler</div>
    <div class="section-sub">Kelola jadwal kuliah rutin per semester</div>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= base_url('admin/template_schedule.php')
        .'?semester='.urlencode($sched_aktif['semester'])
        .'&tahun='.urlencode($sched_aktif['tahun_ajaran'])
        .'&tgl_mulai='.urlencode($sched_aktif['tgl_mulai'])
        .'&tgl_selesai='.urlencode($sched_aktif['tgl_selesai'])
    ?>" class="btn btn-secondary">📥 Unduh Template</a>
    <button class="btn btn-secondary" onclick="openModal('modal-import')">📤 Import CSV</button>
    <button class="btn btn-primary" onclick="openModal('modal-add')">＋ Tambah Jadwal</button>
  </div>
</div>

<!-- Filter bar -->
<div class="filter-bar">
  <form method="get" class="d-flex gap-2 flex-wrap w-100">
    <input name="q" class="form-control search-input" placeholder="🔍 Cari mata kuliah, dosen, kode..."
           value="<?= e($filter_search) ?>">
    <select name="hari" class="form-control w-auto" onchange="this.closest('form').submit()">
      <option value="">Semua Hari</option>
      <?php for ($h=1;$h<=7;$h++): ?>
      <option value="<?= $h ?>" <?= $filter_hari===$h?'selected':'' ?>><?= $HARI_LABELS[$h] ?></option>
      <?php endfor; ?>
    </select>
    <?php if ($user['role']==='admin_utama'): ?>
    <select name="dept" class="form-control w-auto" onchange="this.closest('form').submit()">
      <option value="">Semua Departemen</option>
      <?php foreach ($DEPT_LIST as $d): ?>
      <option value="<?= e($d) ?>" <?= $filter_dept===$d?'selected':'' ?>><?= e($d) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <a href="<?= base_url('admin/class_schedule.php') ?>" class="btn btn-secondary btn-sm">Reset</a>
  </form>
</div>

<!-- Table -->
<div class="card">
<?php if (empty($schedules)): ?>
  <div class="empty-state"><div class="icon">📚</div><h3>Belum ada jadwal kuliah</h3><p>Klik "+ Tambah Jadwal" atau "Import CSV" untuk menambahkan data.</p></div>
<?php else: ?>
<div class="table-responsive">
<table class="table">
  <thead>
    <tr>
      <th>#</th><th>Mata Kuliah</th><th>Ruangan</th><th>Hari &amp; Waktu</th>
      <th>Prodi / Offering</th><th>Dosen</th><th>Semester</th><th>Aksi</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($schedules as $i => $s): ?>
  <tr>
    <td class="text-muted fs-sm"><?= $i+1 ?></td>
    <td>
      <div class="fw-semibold"><?= e($s['nama_matkul']) ?></div>
      <?php if ($s['kode_matkul']): ?><div class="fs-xs text-muted"><?= e($s['kode_matkul']) ?></div><?php endif; ?>
    </td>
    <td>
      <div><?= e($s['ruangan_nama']) ?></div>
      <div class="fs-xs text-muted"><?= e($s['gedung']) ?></div>
      <div class="fs-xs text-muted"><?= e($s['departemen']) ?></div>
    </td>
    <td>
      <div class="fw-semibold"><?= $HARI_LABELS[(int)$s['hari']] ?? '-' ?></div>
      <?php $jamKeLabel = getJamKe($s['jam_mulai'],$JAM_REGULER,$JAM_PUASA); ?>
      <?php if ($jamKeLabel): ?><div class="fw-semibold fs-sm" style="color:#16a34a"><?= $jamKeLabel ?></div><?php endif; ?>
      <div class="fs-xs text-muted"><?= e(substr($s['jam_mulai'],0,5)) ?> – <?= e(substr($s['jam_selesai'],0,5)) ?></div>
    </td>
    <td>
      <div><?= e($s['prodi'] ?: '-') ?></div>
      <?php if ($s['kelas']): ?><div class="fs-xs text-muted">Offering <?= e($s['kelas']) ?></div><?php endif; ?>
    </td>
    <td><?= e($s['dosen'] ?: '-') ?></td>
    <td>
      <span class="badge badge-<?= $s['semester']==='Ganjil'?'warning':($s['semester']==='Antara'?'info':'success') ?>">
        <?= e($s['semester']) ?>
      </span>
      <?php if ($s['tahun_ajaran']): ?><div class="fs-xs text-muted"><?= e($s['tahun_ajaran']) ?></div><?php endif; ?>
    </td>
    <td>
      <div class="d-flex gap-1 flex-wrap">
        <button class="btn btn-primary btn-sm" onclick="openDetail(<?= $s['id'] ?>)">Detail</button>
        <button class="btn btn-secondary btn-sm" onclick="openEditSched(<?= $s['id'] ?>)">Edit</button>
        <form method="post" style="display:inline" onsubmit="return confirm('Hapus jadwal ini?')">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $s['id'] ?>">
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
  <div class="modal-box" style="max-width:640px">
    <div class="modal-header">
      <span class="modal-title">＋ Tambah Jadwal Kuliah</span>
      <button class="modal-close" onclick="closeModal('modal-add')">✕</button>
    </div>
    <div class="modal-body">
      <form method="post">
        <input type="hidden" name="action" value="add">
        <?= schedForm($rooms, $HARI_LABELS, [], $isRamadan, $JAM_REGULER, $JAM_PUASA) ?>
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
  <div class="modal-box" style="max-width:640px">
    <div class="modal-header">
      <span class="modal-title">✏️ Edit Jadwal Kuliah</span>
      <button class="modal-close" onclick="closeModal('modal-edit')">✕</button>
    </div>
    <div class="modal-body">
      <form method="post" id="edit-form-sched">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="">
        <?= schedForm($rooms, $HARI_LABELS, [], $isRamadan, $JAM_REGULER, $JAM_PUASA) ?>
        <div class="d-flex gap-2 mt-3">
          <button type="submit" class="btn btn-primary">💾 Simpan Perubahan</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit')">Batal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ MODAL IMPORT ═══ -->
<div id="modal-import" class="modal-overlay">
  <div class="modal-box" style="max-width:560px">
    <div class="modal-header">
      <span class="modal-title">📤 Import Jadwal dari CSV</span>
      <button class="modal-close" onclick="closeModal('modal-import')">✕</button>
    </div>
    <div class="modal-body">
      <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:10px;padding:14px 16px;margin-bottom:16px">
        <div style="font-weight:700;color:#15803d;font-size:13.5px;margin-bottom:10px">📋 Cara pakai:</div>
        <ol style="margin:0;padding-left:20px;font-size:13px;color:#374151;line-height:2">
          <li>Klik <strong>Unduh Template</strong> → buka file <strong>.xlsx</strong> di Excel/LibreOffice</li>
          <li>Isi data jadwal mulai <strong>baris ke-4</strong> (gunakan dropdown di kolom Departemen/Hari/Semester)</li>
          <li>Kolom <strong>Nama Ruangan</strong> harus sama persis dengan nama ruangan di sistem</li>
          <li>Simpan sebagai <strong>CSV</strong>: <em>File → Simpan Sebagai → CSV</em><br>
              <span style="font-size:12px;color:#16a34a">(koma <em>atau</em> titik koma, keduanya didukung otomatis)</span></li>
          <li>Upload file <strong>.csv</strong> di form bawah</li>
        </ol>
      </div>
      <div id="import-result" style="display:none;margin-bottom:12px"></div>
      <form id="import-form" enctype="multipart/form-data">
        <div class="form-group">
          <label>Pilih File CSV *</label>
          <input type="file" name="file_import" id="file-import" class="form-control" accept=".csv" required>
          <div class="fs-xs text-muted mt-1">Maks 5MB · format .csv</div>
        </div>
        <div class="d-flex gap-2 mt-3">
          <button type="button" class="btn btn-primary" onclick="doImportSched()">📤 Mulai Import</button>
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-import')">Batal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══ MODALS DETAIL ═══ -->
<?php foreach ($schedules as $s): ?>
<div id="detail-<?= $s['id'] ?>" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title">📚 Detail Jadwal #<?= $s['id'] ?></span>
      <button class="modal-close" onclick="closeModal('detail-<?= $s['id'] ?>')">✕</button>
    </div>
    <div class="modal-body">
      <?php
      $rows = [
        'Mata Kuliah'        => $s['nama_matkul'],
        'Kode MK'            => $s['kode_matkul'] ?: '-',
        'Dosen'              => $s['dosen'] ?: '-',
        'Prodi'              => $s['prodi'] ?: '-',
        'Offering'           => $s['kelas'] ?: '-',
        'Departemen'         => $s['departemen'],
        'Ruangan'            => $s['ruangan_nama'].' — '.$s['gedung'],
        'Hari'               => $HARI_LABELS[(int)$s['hari']] ?? '-',
        'Waktu'              => substr($s['jam_mulai'],0,5).' – '.substr($s['jam_selesai'],0,5),
        'Periode Semester'   => $s['tgl_mulai_semester'].' s/d '.$s['tgl_selesai_semester'],
        'Semester'           => $s['semester'],
        'Tahun Ajaran'       => $s['tahun_ajaran'] ?: '-',
        'Dibuat oleh'        => $s['dibuat_nama'],
        'Dibuat pada'        => $s['created_at'],
      ];
      foreach ($rows as $k => $v): ?>
      <div class="detail-row">
        <span class="detail-label"><?= $k ?></span>
        <span class="detail-value"><?= e($v) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php
/* ── Inline helper: schedule form fields ── */
function schedForm(array $rooms, array $hariLabels, array $d, bool $isRamadan = false, array $jamReg = [], array $jamPuasa = []): string {
    $f = function(string $n) use ($d): string { return isset($d[$n]) ? e($d[$n]) : ''; };

    /* ── Room search picker ── */
    $selRuangan  = isset($d['ruangan_id']) ? (int)$d['ruangan_id'] : 0;
    $selRoomInfo = null;
    foreach ($rooms as $r) { if ((int)$r['id'] === $selRuangan) { $selRoomInfo = $r; break; } }
    $roomsJson   = htmlspecialchars(json_encode(array_values($rooms)), ENT_QUOTES, 'UTF-8');
    $srchDisp    = $selRoomInfo ? 'none' : 'flex';
    $selDisp     = $selRoomInfo ? 'flex' : 'none';
    $selName     = $selRoomInfo ? e($selRoomInfo['nama']).' — '.e($selRoomInfo['gedung']) : '';
    $selDept     = $selRoomInfo ? e($selRoomInfo['departemen']) : '';

    $html  = '<div class="form-group"><label>Ruangan *</label>';
    $html .= '<div class="sched-room-picker" data-rooms="'.$roomsJson.'" style="position:relative">';
    $html .= '<div class="sched-rp-search" style="display:'.$srchDisp.';align-items:center;gap:8px;border:2px solid #e2e8f0;border-radius:10px;padding:0 12px;background:#fff;min-height:44px;cursor:text">';
    $html .= '<span style="color:#94a3b8;flex-shrink:0;font-size:15px">🔍</span>';
    $html .= '<input type="text" class="sched-rp-input" placeholder="Cari nama ruangan, gedung, departemen..." style="flex:1;border:none;outline:none;font-size:14px;background:transparent;padding:11px 0;font-family:inherit" autocomplete="off">';
    $html .= '<button type="button" class="sched-rp-clear" style="display:none;background:none;border:none;cursor:pointer;color:#94a3b8;font-size:14px;padding:3px 6px;line-height:1">✕</button>';
    $html .= '</div>';
    $html .= '<div class="sched-rp-dropdown" style="display:none;position:absolute;left:0;right:0;top:calc(100% + 4px);background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 36px rgba(0,0,0,.13);z-index:800;max-height:260px;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch"></div>';
    $html .= '<div class="sched-rp-selected" style="display:'.$selDisp.';align-items:center;gap:12px;padding:10px 14px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px">';
    $html .= '<div style="flex:1"><div class="sched-rp-sel-name" style="font-weight:700;font-size:14px;color:#166534">'.$selName.'</div><div class="sched-rp-sel-dept" style="font-size:12px;color:#15803d">'.$selDept.'</div></div>';
    $html .= '<button type="button" class="sched-rp-change" style="background:#fff;border:1.5px solid #22c55e;color:#16a34a;padding:6px 12px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;white-space:nowrap">🔄 Ganti</button>';
    $html .= '</div>';
    $html .= '<input type="hidden" name="ruangan_id" value="'.($selRuangan ?: '').'" required>';
    $html .= '</div></div>';

    /* ── Mata Kuliah & Kode MK ── */
    $html .= '<div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
    $html .= '<div class="form-group"><label>Mata Kuliah *</label><input type="text" name="nama_matkul" class="form-control" value="'.$f('nama_matkul').'" required></div>';
    $html .= '<div class="form-group"><label>Kode MK</label><input type="text" name="kode_matkul" class="form-control" value="'.$f('kode_matkul').'"></div>';
    $html .= '</div>';
    $html .= '<div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
    $html .= '<div class="form-group"><label>Dosen</label><input type="text" name="dosen" class="form-control" value="'.$f('dosen').'"></div>';
    $html .= '<div class="form-group"><label>Prodi</label><input type="text" name="prodi" class="form-control" value="'.$f('prodi').'"></div>';
    $html .= '</div>';

    /* ── Offering / Hari / Semester ── */
    $hOpts = '<option value="">— Pilih Hari —</option>';
    for ($h=1; $h<=7; $h++) {
        $sel = isset($d['hari']) && (int)$d['hari']===$h ? ' selected' : '';
        $hOpts .= "<option value=\"{$h}\"{$sel}>{$hariLabels[$h]}</option>";
    }
    $html .= '<div class="form-row" style="display:grid;grid-template-columns:1fr 2fr;gap:12px">';
    $html .= '<div class="form-group"><label>Offering</label><input type="text" name="kelas" class="form-control" value="'.$f('kelas').'" placeholder="A"></div>';
    $html .= '<div class="form-group"><label>Hari *</label><select name="hari" class="form-control" required>'.$hOpts.'</select></div>';
    $html .= '</div>';

    /* ── Mode Jadwal ── */
    $detMul  = !empty($d['jam_mulai'])   ? detectJamKe($d['jam_mulai'],    $jamReg, $jamPuasa) : ['jam_ke'=>0,'mode'=>$isRamadan?'puasa':'reguler'];
    $detSel  = !empty($d['jam_selesai']) ? detectJamKeEnd($d['jam_selesai'],$jamReg, $jamPuasa) : ['jam_ke'=>0,'mode'=>$isRamadan?'puasa':'reguler'];
    $selMode = isset($d['jam_mulai']) ? $detMul['mode'] : ($isRamadan ? 'puasa' : 'reguler');
    $jMul    = $detMul['jam_ke'];
    $jSel    = $detSel['jam_ke'];
    $existJm = isset($d['jam_mulai'])   ? e(substr($d['jam_mulai'],0,5))   : '';
    $existJs = isset($d['jam_selesai']) ? e(substr($d['jam_selesai'],0,5)) : '';

    $chkReg   = $selMode==='reguler' ? ' checked' : '';
    $chkPuasa = $selMode==='puasa'   ? ' checked' : '';
    /* ── Panel Waktu Jadwal (Mode + Jam Ke + Tahun Ajaran) ── */
    $html .= '<div class="sched-time-panel" style="border:1.5px solid #e5e9f0;border-radius:14px;padding:16px;background:#f8fafc;margin-bottom:14px">';
    $html .= '  <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;flex-wrap:wrap">';
    $html .= '    <span style="font-weight:700;font-size:13.5px;color:#334155">⏰ Waktu Jadwal <span style="font-weight:400;color:#94a3b8;font-size:11.5px">· sesuai pedoman kampus</span></span>';
    if ($isRamadan) $html .= '    <span style="font-size:11.5px;color:#d97706;font-weight:600;background:#fef3c7;padding:3px 10px;border-radius:20px">🌙 Ramadan aktif</span>';
    $html .= '  </div>';
    $html .= '  <div class="sched-mode-toggle" style="display:flex;gap:10px;margin-bottom:14px">';
    $html .= '    <label class="sched-mode-pill" style="flex:1;display:flex;align-items:center;justify-content:center;gap:7px;padding:11px;border:1.5px solid #e2e8f0;border-radius:10px;cursor:pointer;font-weight:600;font-size:13.5px;color:#475569;background:#fff;transition:all .15s"><input type="radio" name="sched_mode" value="reguler" class="sched-mode" style="accent-color:#16a34a"'.$chkReg.'> ☀️ Reguler</label>';
    $html .= '    <label class="sched-mode-pill" style="flex:1;display:flex;align-items:center;justify-content:center;gap:7px;padding:11px;border:1.5px solid #e2e8f0;border-radius:10px;cursor:pointer;font-weight:600;font-size:13.5px;color:#475569;background:#fff;transition:all .15s"><input type="radio" name="sched_mode" value="puasa" class="sched-mode" style="accent-color:#16a34a"'.$chkPuasa.'> 🌙 Puasa (Ramadan)</label>';
    $html .= '  </div>';

    /* ── Jam Ke Mulai → Selesai ── */
    $jOptsMul = '<option value="">— Mulai Jam Ke —</option>';
    $jOptsSel = '<option value="">— Selesai Jam Ke —</option>';
    for ($j=1; $j<=12; $j++) {
        $sM = ($jMul===$j) ? ' selected' : '';
        $sS = ($jSel===$j) ? ' selected' : '';
        $jOptsMul .= "<option value=\"{$j}\"{$sM}>Jam ke-{$j}</option>";
        $jOptsSel .= "<option value=\"{$j}\"{$sS}>Jam ke-{$j}</option>";
    }
    $previewText = '';
    if ($jMul > 0) {
        $tbl = $selMode==='puasa' ? $jamPuasa : $jamReg;
        $jEnd = ($jSel > 0) ? $jSel : $jMul;
        if (isset($tbl[$jMul], $tbl[$jEnd])) {
            $dur = $jEnd - $jMul + 1;
            $previewText = '🕐 '.$tbl[$jMul][0].' – '.$tbl[$jEnd][1].' ('.$dur.' jam ke)';
        }
    }

    $html .= '  <div class="sched-grid2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">';
    $html .= '    <div class="form-group" style="margin:0"><label style="font-size:12.5px">Mulai Jam Ke *</label><select name="jam_ke_mulai" class="form-control sched-jam-mul" required>'.$jOptsMul.'</select></div>';
    $html .= '    <div class="form-group" style="margin:0"><label style="font-size:12.5px">Selesai Jam Ke *</label><select name="jam_ke_selesai" class="form-control sched-jam-sel" required>'.$jOptsSel.'</select></div>';
    $html .= '  </div>';
    $_prevBorder = $previewText ? '#86efac' : '#e2e8f0';
    $_prevInner  = $previewText ?: '<span style="color:#cbd5e1;font-weight:500">🕐 Pilih jam ke …</span>';
    $html .= '  <div class="sched-time-preview" style="font-size:13.5px;color:#16a34a;font-weight:700;background:#fff;border:1.5px solid '.$_prevBorder.';border-radius:10px;min-height:44px;padding:6px 12px;display:flex;align-items:center;justify-content:center;text-align:center">'.$_prevInner.'</div>';
    $html .= '</div>';

    /* Hidden jam fields — diisi oleh JS */
    $html .= '<input type="hidden" name="jam_mulai"   value="'.$existJm.'">';
    $html .= '<input type="hidden" name="jam_selesai" value="'.$existJs.'">';

    return $html;
}
?>

<style>
/* Mode toggle pills */
.sched-mode-pill:hover{border-color:#cbd5e1}
.sched-mode-pill:has(input:checked){border-color:#16a34a;background:#f0fdf4!important;color:#166534;box-shadow:0 0 0 3px rgba(22,163,74,.1)}
.sched-mode-pill input{margin:0}
.sched-time-panel select.form-control{min-height:44px}
@media(max-width:560px){
  .sched-grid2{grid-template-columns:1fr!important}
  .sched-mode-toggle{flex-direction:column}
  .sched-time-panel{padding:13px}
}
</style>

<script>
const schedData    = <?= json_encode(array_values($schedules)) ?>;
const importUrl    = '<?= base_url('admin/import_schedule.php') ?>';

/* ── Tabel waktu kuliah ── */
const SCHED_TIMES = {
  reguler: {
    1:['07:00','07:50'], 2:['07:50','08:40'], 3:['08:45','09:35'],
    4:['09:35','10:25'], 5:['10:30','11:20'], 6:['11:20','12:10'],
    7:['13:10','14:00'], 8:['14:00','14:50'], 9:['14:55','15:45'],
    10:['15:45','16:35'],11:['18:15','19:05'],12:['19:05','19:55']
  },
  puasa: {
    1:['07:30','08:05'], 2:['08:05','08:40'], 3:['08:45','09:20'],
    4:['09:20','09:55'], 5:['10:00','10:35'], 6:['10:35','11:10'],
    7:['11:10','11:45'], 8:['13:00','13:35'], 9:['13:35','14:10'],
    10:['14:10','14:45'],11:['15:15','15:45'],12:['15:45','16:15']
  }
};

function escSchedH(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

/* Deteksi Jam Ke dari jam_mulai (start) */
function detectJamKeJS(jm) {
  for (const mode in SCHED_TIMES)
    for (const k in SCHED_TIMES[mode])
      if (SCHED_TIMES[mode][k][0]===jm) return {mode,jam_ke:+k};
  return {mode:'reguler',jam_ke:0};
}
/* Deteksi Jam Ke dari jam_selesai (end) */
function detectJamKeEndJS(js) {
  for (const mode in SCHED_TIMES)
    for (const k in SCHED_TIMES[mode])
      if (SCHED_TIMES[mode][k][1]===js) return {mode,jam_ke:+k};
  return {mode:'reguler',jam_ke:0};
}

/* Update hidden jam_mulai/selesai + preview */
function updateSchedTime(form) {
  const modeEl  = form.querySelector('.sched-mode:checked');
  const jamMulEl= form.querySelector('.sched-jam-mul');
  const jamSelEl= form.querySelector('.sched-jam-sel');
  const preview = form.querySelector('.sched-time-preview');
  if (!modeEl || !jamMulEl || !jamSelEl) return;

  const mode  = modeEl.value;
  const tbl   = SCHED_TIMES[mode];
  const jMul  = parseInt(jamMulEl.value) || 0;
  let   jSel  = parseInt(jamSelEl.value) || 0;

  /* Disable opsi selesai < mulai */
  Array.from(jamSelEl.options).forEach(opt => {
    if (!opt.value) return;
    opt.disabled = jMul > 0 && parseInt(opt.value) < jMul;
    opt.style.color = opt.disabled ? '#ccc' : '';
  });
  if (jSel && jSel < jMul) { jamSelEl.value = jMul; jSel = jMul; }

  let filled = false;
  if (jMul && tbl && tbl[jMul]) {
    const jEnd = jSel || jMul;
    if (tbl[jEnd]) {
      const jm = tbl[jMul][0], js = tbl[jEnd][1];
      form.querySelector('[name=jam_mulai]').value   = jm;
      form.querySelector('[name=jam_selesai]').value = js;
      if (preview) preview.innerHTML = '🕐 ' + jm + ' – ' + js + ' <span style="font-weight:500;color:#15803d">(' + (jEnd - jMul + 1) + ' jam ke)</span>';
      filled = true;
    }
  }
  if (!filled) {
    form.querySelector('[name=jam_mulai]').value   = '';
    form.querySelector('[name=jam_selesai]').value = '';
    if (preview) preview.innerHTML = '<span style="color:#cbd5e1;font-weight:500">🕐 Pilih jam ke …</span>';
  }
  if (preview) { preview.style.borderColor = filled ? '#86efac' : '#e2e8f0'; preview.style.background = filled ? '#f0fdf4' : '#fff'; }
}

/* Event listeners */
document.querySelectorAll('.sched-jam-mul,.sched-jam-sel,.sched-mode').forEach(el =>
  el.addEventListener('change', () => updateSchedTime(el.closest('form'))));
/* Init pada load */
document.querySelectorAll('form').forEach(f => {
  if (f.querySelector('.sched-jam-mul')) updateSchedTime(f);
});

/* ══════════════════════════════════════════════
   ROOM PICKER — class schedule
══════════════════════════════════════════════ */
function schedRpRender(picker, q) {
  const rooms   = JSON.parse(picker.dataset.rooms || '[]');
  const drop    = picker.querySelector('.sched-rp-dropdown');
  const ql      = (q||'').trim().toLowerCase();
  const filtered= rooms.filter(r =>
    !ql || r.nama.toLowerCase().includes(ql) ||
    r.gedung.toLowerCase().includes(ql) ||
    r.departemen.toLowerCase().includes(ql)
  );
  if (!filtered.length) {
    drop.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:13px">🔍 Ruangan tidak ditemukan</div>';
    return;
  }
  const indoor  = filtered.filter(r=>r.tipe!=='outdoor');
  const outdoor = filtered.filter(r=>r.tipe==='outdoor');
  let html = '';
  const grp = (label, arr) => {
    if (!arr.length) return;
    html += `<div style="padding:6px 12px 4px;font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;background:#fff;border-bottom:1px solid #f1f5f9;position:sticky;top:0">${label}</div>`;
    arr.forEach(r => {
      html += `<div class="sched-rp-item" data-id="${r.id}" data-nama="${escSchedH(r.nama)}" data-gedung="${escSchedH(r.gedung)}" data-dept="${escSchedH(r.departemen)}"
        style="padding:9px 14px;cursor:pointer;border-bottom:1px solid #f8fafc;display:flex;gap:10px;align-items:flex-start">
        <div style="font-size:18px;flex-shrink:0;margin-top:2px">${r.tipe==='outdoor'?'🌿':'🏢'}</div>
        <div><div style="font-weight:700;font-size:13.5px;color:#1e293b">${escSchedH(r.nama)}</div>
        <div style="font-size:12px;color:#64748b">📍 ${escSchedH(r.gedung)} · 🏫 ${escSchedH(r.departemen)}</div></div>
      </div>`;
    });
  };
  grp('🏢 Indoor', indoor);
  grp('🌿 Outdoor', outdoor);
  drop.innerHTML = html;
}

function schedRpSelect(picker, id, nama, gedung, dept) {
  picker.querySelector('.sched-rp-search').style.display   = 'none';
  picker.querySelector('.sched-rp-dropdown').style.display = 'none';
  const sel = picker.querySelector('.sched-rp-selected');
  sel.style.display = 'flex';
  sel.querySelector('.sched-rp-sel-name').textContent = nama + ' — ' + gedung;
  sel.querySelector('.sched-rp-sel-dept').textContent = dept;
  picker.querySelector('[name=ruangan_id]').value = id;
}
function schedRpChange(picker) {
  picker.querySelector('.sched-rp-search').style.display    = 'flex';
  picker.querySelector('.sched-rp-selected').style.display  = 'none';
  picker.querySelector('.sched-rp-dropdown').style.display  = 'none';
  picker.querySelector('[name=ruangan_id]').value = '';
  const inp = picker.querySelector('.sched-rp-input');
  inp.value = ''; picker.querySelector('.sched-rp-clear').style.display = 'none';
  inp.focus();
  schedRpRender(picker, '');
  picker.querySelector('.sched-rp-dropdown').style.display = 'block';
}

/* Event delegation untuk room picker */
document.addEventListener('input', function(e) {
  if (!e.target.classList.contains('sched-rp-input')) return;
  const picker = e.target.closest('.sched-room-picker');
  picker.querySelector('.sched-rp-clear').style.display = e.target.value ? 'block' : 'none';
  schedRpRender(picker, e.target.value);
  picker.querySelector('.sched-rp-dropdown').style.display = 'block';
});
document.addEventListener('focus', function(e) {
  if (!e.target.classList.contains('sched-rp-input')) return;
  const picker = e.target.closest('.sched-room-picker');
  schedRpRender(picker, e.target.value);
  picker.querySelector('.sched-rp-dropdown').style.display = 'block';
}, true);
document.addEventListener('mousedown', function(e) {
  const item = e.target.closest('.sched-rp-item');
  if (item) {
    e.preventDefault();
    const picker = item.closest('.sched-room-picker');
    schedRpSelect(picker, item.dataset.id, item.dataset.nama, item.dataset.gedung, item.dataset.dept);
    return;
  }
  const chg = e.target.closest('.sched-rp-change');
  if (chg) { e.preventDefault(); schedRpChange(chg.closest('.sched-room-picker')); return; }
  const clr = e.target.closest('.sched-rp-clear');
  if (clr) {
    e.preventDefault();
    const picker = clr.closest('.sched-room-picker');
    const inp = picker.querySelector('.sched-rp-input');
    inp.value = ''; clr.style.display = 'none';
    schedRpRender(picker,''); inp.focus();
    return;
  }
  /* Tutup dropdown jika klik di luar */
  document.querySelectorAll('.sched-rp-dropdown').forEach(d => {
    if (!d.closest('.sched-room-picker').contains(e.target)) d.style.display = 'none';
  });
});


function openModal(id) { document.getElementById(id).classList.add('show'); }
function closeModal(id){ document.getElementById(id).classList.remove('show'); }
function openDetail(id){ openModal('detail-'+id); }

function openEditSched(id) {
    const s = schedData.find(x => +x.id === +id);
    if (!s) return;
    const f = document.getElementById('edit-form-sched');
    f.querySelector('[name=id]').value              = s.id;
    f.querySelector('[name=ruangan_id]').value      = s.ruangan_id;
    f.querySelector('[name=nama_matkul]').value     = s.nama_matkul;
    f.querySelector('[name=kode_matkul]').value     = s.kode_matkul || '';
    f.querySelector('[name=dosen]').value           = s.dosen || '';
    f.querySelector('[name=prodi]').value           = s.prodi || '';
    f.querySelector('[name=kelas]').value           = s.kelas || '';
    f.querySelector('[name=hari]').value = s.hari;
    /* Mode + Jam Ke range */
    const detMul = detectJamKeJS((s.jam_mulai||'').slice(0,5));
    const detSel = detectJamKeEndJS((s.jam_selesai||'').slice(0,5));
    const modeRad = f.querySelector(`.sched-mode[value="${detMul.mode}"]`);
    if (modeRad) modeRad.checked = true;
    const jMulEl = f.querySelector('.sched-jam-mul');
    const jSelEl = f.querySelector('.sched-jam-sel');
    if (jMulEl) jMulEl.value = detMul.jam_ke || '';
    if (jSelEl) jSelEl.value = detSel.jam_ke  || detMul.jam_ke || '';
    updateSchedTime(f);
    /* Room picker */
    const picker = f.querySelector('.sched-room-picker');
    if (picker) {
      const roomData = JSON.parse(picker.dataset.rooms || '[]');
      const rm = roomData.find(r => String(r.id)===String(s.ruangan_id));
      if (rm) schedRpSelect(picker, rm.id, rm.nama, rm.gedung, rm.departemen);
      else schedRpChange(picker);
    }
    /* semester/tahun_ajaran/tgl diperoleh dari session — tidak perlu diset di form */
    openModal('modal-edit');
}

async function doImportSched() {
    const fi = document.getElementById('file-import');
    if (!fi.files[0]) { alert('Pilih file CSV terlebih dahulu.'); return; }
    const fd = new FormData(document.getElementById('import-form'));
    const btn = document.querySelector('#modal-import .btn-primary');
    btn.disabled = true; btn.textContent = '⏳ Mengimpor...';
    try {
        const res  = await fetch(importUrl, { method:'POST', body:fd });
        const data = await res.json();
        const div  = document.getElementById('import-result');
        div.style.display = 'block';
        if (data.ok) {
            let html = `<div class="alert alert-success">✅ Berhasil diimpor: <strong>${data.inserted}</strong> jadwal. Dilewati: ${data.skipped}.</div>`;
            if (data.errors && data.errors.length) {
                html += '<div class="alert alert-danger" style="max-height:180px;overflow-y:auto;font-size:12px"><strong>⚠️ Baris bermasalah:</strong><ul style="margin:6px 0 0 16px">';
                data.errors.forEach(e => { html += `<li>${e}</li>`; });
                html += '</ul></div>';
            }
            div.innerHTML = html;
            if (data.inserted > 0) setTimeout(()=>location.reload(), 1500);
        } else {
            div.innerHTML = `<div class="alert alert-danger">❌ ${data.msg}</div>`;
        }
    } catch(err) {
        document.getElementById('import-result').innerHTML =
            `<div class="alert alert-danger">❌ Terjadi error: ${err.message}</div>`;
        document.getElementById('import-result').style.display = 'block';
    } finally {
        btn.disabled = false; btn.textContent = '📤 Mulai Import';
    }
}

document.querySelectorAll('.modal-overlay').forEach(m =>
    m.addEventListener('click', function(e){ if(e.target===this) this.classList.remove('show'); }));
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
