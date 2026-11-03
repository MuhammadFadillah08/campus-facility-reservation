<?php
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('admin_utama','admin_departemen','admin_dmf');
$page_title = 'Kelola Peminjaman';
$db = db();
ensureColumn($db, 'peminjaman', 'asal_organisasi', "VARCHAR(150) DEFAULT NULL AFTER nama_kegiatan");

/* ── POST handler ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pid    = (int)($_POST['id'] ?? 0);

    $stmt = $db->prepare("SELECT p.*,r.departemen,r.tipe FROM peminjaman p JOIN ruangan r ON p.ruangan_id=r.id WHERE p.id=?");
    $stmt->bind_param('i',$pid); $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    if ($booking && canValidate($user,['departemen'=>$booking['departemen'],'tipe'=>$booking['tipe']])) {
        $notif_link = base_url('user/my-bookings.php');

        if ($action === 'approve') {
            $s = $db->prepare("UPDATE peminjaman SET status='disetujui',validated_by=?,validated_at=NOW(),alasan_penolakan=NULL WHERE id=?");
            $s->bind_param('ii',$user['id'],$pid); $s->execute();
            pushNotif($db, (int)$booking['user_id'],
                'Peminjaman Disetujui ✅',
                'Pengajuan "' . $booking['nama_kegiatan'] . '" telah disetujui oleh admin.',
                'success',
                $notif_link
            );
            flash('success','Peminjaman berhasil disetujui.');

        } elseif ($action === 'reject') {
            // Berlaku untuk status pending maupun disetujui
            $alasan = trim($_POST['alasan'] ?? '');
            $s = $db->prepare("UPDATE peminjaman SET status='ditolak',validated_by=?,validated_at=NOW(),alasan_penolakan=? WHERE id=?");
            $s->bind_param('isi',$user['id'],$alasan,$pid); $s->execute();
            $pesan_tolak = 'Pengajuan "' . $booking['nama_kegiatan'] . '" telah ditolak.' . ($alasan ? ' Alasan: ' . $alasan : '');
            pushNotif($db, (int)$booking['user_id'],
                'Peminjaman Ditolak ❌',
                $pesan_tolak,
                'danger',
                $notif_link
            );
            flash('success','Peminjaman berhasil ditolak.');

        } elseif ($action === 'complete') {
            $db->query("UPDATE peminjaman SET status='selesai' WHERE id=$pid");
            flash('success','Peminjaman ditandai selesai.');

        } elseif ($action === 'delete') {
            if (($_POST['confirm_text'] ?? '') === 'HAPUS') {
                // Hapus file surat jika ada
                if (!empty($booking['file_surat'])) {
                    $filePath = __DIR__ . '/../uploads/surat/' . $booking['file_surat'];
                    if (file_exists($filePath)) unlink($filePath);
                }
                $db->query("DELETE FROM peminjaman WHERE id=$pid");
                flash('success', 'Data peminjaman "' . $booking['nama_kegiatan'] . '" berhasil dihapus.');
            } else {
                flash('error', 'Konfirmasi tidak sesuai. Ketik HAPUS untuk menghapus.');
            }
        }

    } else {
        flash('error','Anda tidak berwenang.');
    }
    redirect(base_url('admin/bookings.php'));
}

/* ── Daftar GEDUNG INDOOR (per gedung) + apakah ada OUTDOOR (satu opsi) ── */
$gedung_indoor=[]; $has_outdoor=false;
if ($user['role']==='admin_departemen') {
    $gStmt=$db->prepare("SELECT DISTINCT gedung FROM ruangan WHERE tipe='indoor' AND departemen=? AND gedung<>'' ORDER BY gedung");
    $gStmt->bind_param('s',$user['departemen']); $gStmt->execute();
    $gedung_indoor=array_column($gStmt->get_result()->fetch_all(MYSQLI_ASSOC),'gedung');
} elseif ($user['role']==='admin_dmf') {
    $has_outdoor=true;   // Admin DMF hanya mengelola area outdoor
} else { // admin_utama
    $gedung_indoor=array_column($db->query("SELECT DISTINCT gedung FROM ruangan WHERE tipe='indoor' AND gedung<>'' ORDER BY gedung")->fetch_all(MYSQLI_ASSOC),'gedung');
    $has_outdoor=(int)$db->query("SELECT COUNT(*) c FROM ruangan WHERE tipe='outdoor'")->fetch_assoc()['c']>0;
}

/* ── Filter & query ── */
$filter_status = $_GET['status'] ?? '';
$filter_search = trim($_GET['q'] ?? '');
$filter_gedung = trim($_GET['gedung'] ?? '');   // nama gedung indoor, atau '__outdoor__'
$filter_tanggal = trim($_GET['tanggal'] ?? '');  // format YYYY-MM-DD
$where=[]; $params=[]; $types='';

if ($user['role']==='admin_departemen') { $where[]="r.departemen=?"; $params[]=$user['departemen']; $types.='s'; }
elseif ($user['role']==='admin_dmf')    { $where[]="r.tipe='outdoor'"; }
$where[] = "p.status != 'dibatalkan'";
if ($filter_status) { $where[]="p.status=?"; $params[]=$filter_status; $types.='s'; }
if ($filter_gedung==='__outdoor__') {
    $where[]="r.tipe='outdoor'";
} elseif ($filter_gedung) {
    $where[]="r.gedung=? AND r.tipe='indoor'"; $params[]=$filter_gedung; $types.='s';
}
if ($filter_tanggal && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_tanggal)) {
    // Tampilkan peminjaman yang rentang tanggalnya mencakup tanggal terpilih
    $where[]="p.tanggal_mulai<=? AND p.tanggal_selesai>=?";
    $params[]=$filter_tanggal; $params[]=$filter_tanggal; $types.='ss';
} else {
    $filter_tanggal = '';   // buang nilai tidak valid
}
if ($filter_search) {
    $like="%$filter_search%";
    $where[]="(p.nama_kegiatan LIKE ? OR u.nama LIKE ? OR u.nim LIKE ?)";
    $params[]=$like; $params[]=$like; $params[]=$like; $types.='sss';
}

$sql = "SELECT p.*,r.nama ruangan_nama,r.gedung,r.departemen,r.tipe,r.kapasitas,u.nama user_nama,u.nim,u.prodi
        FROM peminjaman p
        JOIN ruangan r ON p.ruangan_id=r.id
        JOIN users u ON p.user_id=u.id"
     . ($where ? " WHERE ".implode(' AND ',$where) : '')
     . " ORDER BY p.created_at DESC";
$stmt=$db->prepare($sql);
if ($params) $stmt->bind_param($types,...$params);
$stmt->execute();
$bookings=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ══ Cek bentrok jadwal untuk setiap peminjaman (pending & disetujui) ══ */
function checkConflicts(mysqli $db, array $b): array {
    $rid = (int)$b['ruangan_id'];
    $d1  = $db->real_escape_string($b['tanggal_mulai']);
    $d2  = $db->real_escape_string($b['tanggal_selesai']);
    $t1  = $db->real_escape_string($b['jam_mulai']);
    $t2  = $db->real_escape_string($b['jam_selesai']);
    $id  = (int)$b['id'];
    $list = [];

    // 1. Peminjaman mahasiswa lain (pending/disetujui) — sertakan created_at untuk cek prioritas
    $r = $db->query("SELECT p.nama_kegiatan,p.tanggal_mulai,p.tanggal_selesai,
           p.jam_mulai,p.jam_selesai,p.status,p.created_at,p.jumlah_peserta,u.nama AS pemohon,'peminjaman' AS sumber
           FROM peminjaman p JOIN users u ON p.user_id=u.id
           WHERE p.ruangan_id=$rid AND p.id!=$id AND p.status IN('pending','disetujui')
           AND CONCAT(p.tanggal_mulai,' ',p.jam_mulai)<CONCAT('$d2',' ','$t2')
           AND CONCAT(p.tanggal_selesai,' ',p.jam_selesai)>CONCAT('$d1',' ','$t1')
           ORDER BY p.created_at ASC");
    if ($r) foreach ($r->fetch_all(MYSQLI_ASSOC) as $row) $list[] = $row;

    // 2. Peminjaman eksternal (menunggu/disetujui) — status asli dipakai agar
    //    penilaian K2 (Status Pengajuan) mencerminkan kondisi sebenarnya:
    //    disetujui = 4, menunggu (pending) = 2. Yang ditolak tidak dihitung.
    $r = $db->query("SELECT pe.nama_kegiatan,pe.tanggal_mulai,pe.tanggal_selesai,
           pe.jam_mulai,pe.jam_selesai,pe.status,pe.created_at,pe.jumlah_peserta,pe.nama_pemohon AS pemohon,'eksternal' AS sumber
           FROM peminjaman_eksternal pe
           WHERE pe.ruangan_id=$rid AND pe.status IN('pending','disetujui')
           AND CONCAT(pe.tanggal_mulai,' ',pe.jam_mulai)<CONCAT('$d2',' ','$t2')
           AND CONCAT(pe.tanggal_selesai,' ',pe.jam_selesai)>CONCAT('$d1',' ','$t1')
           ORDER BY pe.created_at ASC");
    if ($r) foreach ($r->fetch_all(MYSQLI_ASSOC) as $row) $list[] = $row;

    // 3. Jadwal kuliah — iterasi setiap hari dalam range booking (maks 7 hari unik)
    $start = new DateTime($b['tanggal_mulai']);
    $stop  = new DateTime($b['tanggal_selesai']);
    $cur   = clone $start; $seen = [];
    while ($cur <= $stop) {
        $dow = (int)$cur->format('N');
        $tgl = $cur->format('Y-m-d');
        if (!in_array($dow, $seen)) {
            $seen[] = $dow;
            $etgl   = $db->real_escape_string($tgl);
            $r = $db->query("SELECT jk.nama_matkul AS nama_kegiatan,'$etgl' AS tanggal_mulai,
                   '$etgl' AS tanggal_selesai,jk.jam_mulai,jk.jam_selesai,'kuliah' AS status,
                   NULLIF(CONCAT_WS(' / ',NULLIF(TRIM(IFNULL(jk.kelas,'')),''),
                          NULLIF(TRIM(IFNULL(jk.dosen,'')),'')),'') AS pemohon,'kuliah' AS sumber
                   FROM jadwal_kuliah jk
                   WHERE jk.ruangan_id=$rid AND jk.hari=$dow
                   AND jk.tgl_mulai_semester<='$etgl' AND jk.tgl_selesai_semester>='$etgl'
                   AND jk.jam_mulai<'$t2' AND jk.jam_selesai>'$t1'");
            if ($r) foreach ($r->fetch_all(MYSQLI_ASSOC) as $row) $list[] = $row;
        }
        $cur->modify('+1 day');
    }
    return $list;
}

/* ══════════════════════════════════════════════════════════════════════
   PENYELESAIAN BENTROK JADWAL DENGAN METODE AHP–SAW
   ----------------------------------------------------------------------
   Ketika satu ruangan diperebutkan beberapa agenda pada waktu yang sama,
   admin TIDAK memilih secara subjektif/asal. Setiap agenda yang bentrok
   diberi SKOR PRIORITAS melalui gabungan dua metode SPK:
     • AHP  → menetapkan bobot tiap kriteria (pairwise comparison)
     • SAW  → menghitung nilai preferensi (V) tiap agenda lalu merangking
   Kriteria penilaian (semua BENEFIT — makin tinggi makin diprioritaskan):
     K1 Jenis Agenda      : Jadwal Kuliah > Peminjaman Mhs > Eksternal
     K2 Status Pengajuan  : Disetujui/Aktif > Pending
     K3 Waktu Pengajuan   : first come first served (input lebih awal unggul)
     K4 Rasio Peserta     : rasio jumlah peserta thd kapasitas ruangan
                            (mendekati penuh = pemanfaatan ruang lebih pas → unggul)
   Bobot hasil AHP (uji konsistensi CR = 0,044 < 0,1 → KONSISTEN):
     K1 = 0,558   K2 = 0,263   K3 = 0,122   K4 = 0,057
   (Catatan: yang diganti hanya indikator K4 — dari jumlah peserta absolut
    menjadi rasio peserta/kapasitas. Struktur & bobot AHP tetap, sebab posisi
    K4 sebagai kriteria paling rendah tidak berubah.)
   Catatan: jadwal kuliah dirancang SELALU memperoleh nilai tertinggi
   (V = 1,000) karena hanya jadwal kuliah yang bernilai 5 pada K1; secara
   matematis agenda non-kuliah maksimal hanya mencapai V ≈ 0,888.
   ══════════════════════════════════════════════════════════════════════ */
const AHP_KRITERIA = ['k1'=>'Jenis Agenda','k2'=>'Status Pengajuan','k3'=>'Waktu Pengajuan','k4'=>'Rasio Peserta/Kapasitas'];
const AHP_BOBOT    = ['k1'=>0.558,'k2'=>0.263,'k3'=>0.122,'k4'=>0.057];
const AHP_CR       = 0.044;

/** Skor mentah K1 — Jenis Agenda (skala 1–5). Hanya kuliah yang bernilai 5. */
function rawJenis(string $sumber): int {
    return match($sumber) { 'kuliah'=>5, 'peminjaman'=>4, 'eksternal'=>3, default=>3 };
}
/** Skor mentah K2 — Status Pengajuan (skala 1–5). */
function rawStatus(string $sumber, string $status): int {
    if ($sumber === 'kuliah') return 5;
    return match($status) { 'disetujui','aktif'=>4, 'pending'=>2, default=>2 };
}
/** Skor mentah K4 — Rasio Peserta/Kapasitas ruangan (skala 1–5).
 *  Makin mendekati penuh = pemanfaatan ruang makin pas → skor makin tinggi.
 *  Contoh ruang kapasitas 100: 100 peserta (rasio 1,00)→5; 75 (0,75)→4. */
function rawRasio(string $sumber, int $peserta, int $kapasitas): int {
    if ($sumber === 'kuliah') return 5;          // kuliah = kegiatan akademik penuh
    if ($kapasitas <= 0) return 1;               // hindari bagi nol
    $rasio = min($peserta / $kapasitas, 1.0);    // di-cap 1,0 (over-capacity = penuh)
    return match(true) {
        $rasio >= 0.90 => 5,                     // 90–100% penuh
        $rasio >= 0.70 => 4,                     // 70–89%
        $rasio >= 0.50 => 3,                     // 50–69%
        $rasio >= 0.30 => 2,                     // 30–49%
        default        => 1,                     // < 30%
    };
}

/**
 * Skoring AHP–SAW untuk satu kelompok bentrok.
 * @param array $current   booking yang sedang divalidasi (baris dari $bookings)
 * @param array $conflicts hasil checkConflicts()
 * @return array ['parties'=>[...terurut ranking...], 'current_rank'=>int,
 *                'current_V'=>float, 'winner'=>array, 'total'=>int]
 */
function ahpSawResolve(array $current, array $conflicts): array {
    // Kapasitas ruangan yang diperebutkan (sama untuk semua pihak → konstanta grup)
    $kapasitas = (int)($current['kapasitas'] ?? 0);

    // 1) Daftar agenda yang berebut ruangan = booking ini + semua konflik
    $parties = [[
        'key'=>'current','sumber'=>'peminjaman','nama'=>$current['nama_kegiatan'],
        'pemohon'=>$current['user_nama'] ?? '', 'status'=>$current['status'],
        'created'=>$current['created_at'], 'peserta'=>(int)($current['jumlah_peserta'] ?? 0),
        'is_current'=>true,
    ]];
    foreach ($conflicts as $i => $c) {
        $parties[] = [
            'key'=>'c'.$i, 'sumber'=>$c['sumber'], 'nama'=>$c['nama_kegiatan'],
            'pemohon'=>$c['pemohon'] ?? '', 'status'=>$c['status'],
            'created'=>$c['created_at'] ?? null, 'peserta'=>(int)($c['jumlah_peserta'] ?? 0),
            'is_current'=>false,
        ];
    }

    // 2) Rentang waktu pengajuan (untuk K3 first-come), abaikan kuliah
    $ts = [];
    foreach ($parties as $p)
        if ($p['sumber'] !== 'kuliah' && !empty($p['created'])) $ts[] = strtotime($p['created']);
    $minTs = $ts ? min($ts) : 0;
    $maxTs = $ts ? max($ts) : 0;

    // 3) Matriks keputusan — skor mentah tiap kriteria (skala 1–5)
    foreach ($parties as &$p) {
        $p['raw'] = [
            'k1' => rawJenis($p['sumber']),
            'k2' => rawStatus($p['sumber'], $p['status']),
            'k4' => rawRasio($p['sumber'], $p['peserta'], $kapasitas),
        ];
        if ($p['sumber'] === 'kuliah' || empty($p['created']) || $maxTs === $minTs) {
            $p['raw']['k3'] = 5;                              // terjadwal/awal → tertinggi
        } else {                                             // 5 = paling awal, 1 = paling akhir
            $t = strtotime($p['created']);
            $p['raw']['k3'] = 1 + 4 * ($maxTs - $t) / ($maxTs - $minTs);
        }
    }
    unset($p);

    // 4) Normalisasi SAW (benefit: r_ij = x_ij / max kolom)
    $maxCol = ['k1'=>0,'k2'=>0,'k3'=>0,'k4'=>0];
    foreach ($parties as $p) foreach ($maxCol as $k=>$_) $maxCol[$k] = max($maxCol[$k], $p['raw'][$k]);

    // 5) Nilai preferensi V_i = Σ (w_j × r_ij)
    foreach ($parties as &$p) {
        $V = 0; $p['norm'] = [];
        foreach (AHP_BOBOT as $k=>$w) {
            $r = $maxCol[$k] > 0 ? $p['raw'][$k] / $maxCol[$k] : 0;
            $p['norm'][$k] = $r;
            $V += $w * $r;
        }
        $p['V'] = round($V, 4);
    }
    unset($p);

    // 6) Perankingan — V tertinggi = peringkat 1 (tie-break: kuliah dulu, lalu input awal)
    usort($parties, function($a,$b){
        if ($a['V'] !== $b['V']) return $b['V'] <=> $a['V'];
        if ($a['sumber']==='kuliah') return -1;
        if ($b['sumber']==='kuliah') return 1;
        return strtotime($a['created'] ?? 'now') <=> strtotime($b['created'] ?? 'now');
    });
    $rank=0; $currentRank=0; $currentV=0; $winner=null;
    foreach ($parties as &$p) {
        $p['rank'] = ++$rank;
        if ($p['is_current']) { $currentRank=$rank; $currentV=$p['V']; }
        if ($rank === 1) $winner = $p;
    }
    unset($p);

    return ['parties'=>$parties,'current_rank'=>$currentRank,
            'current_V'=>$currentV,'winner'=>$winner,'total'=>count($parties)];
}

foreach ($bookings as &$b) {
    $b['_conflicts'] = in_array($b['status'], ['pending','disetujui'])
        ? checkConflicts($db, $b) : [];
    // Jalankan skoring AHP–SAW bila ada bentrok
    $b['_ahp']   = $b['_conflicts'] ? ahpSawResolve($b, $b['_conflicts']) : null;
    // Booking ini "kalah prioritas" jika bukan peringkat 1 hasil AHP–SAW
    $b['_loses'] = $b['_ahp'] ? ($b['_ahp']['current_rank'] > 1) : false;
}
unset($b);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex align-center justify-between mb-4">
  <div>
    <div class="section-title">✅ Kelola Peminjaman</div>
    <div class="section-sub">
      <?= $user['role']==='admin_utama' ? 'Semua peminjaman Fakultas Sains'
        : ($user['role']==='admin_departemen' ? 'Peminjaman Gedung '.$user['departemen']
        : 'Peminjaman Area Outdoor') ?>
    </div>
  </div>
</div>

<!-- Filter bar -->
<style>
/* Placeholder "Pilih Tanggal" untuk input date kosong (native menampilkan "dd/mm/yyyy") */
.date-wrap{position:relative;display:inline-flex}
.date-wrap.empty:not(:focus-within)::before{
  content:'📅 Pilih Tanggal';position:absolute;left:13px;top:50%;transform:translateY(-50%);
  color:#94a3b8;font-size:14px;pointer-events:none;white-space:nowrap;z-index:1;
}
.date-wrap.empty:not(:focus-within) input::-webkit-datetime-edit{opacity:0}
</style>
<div class="filter-bar">
  <form method="get" class="d-flex gap-2 flex-wrap w-100">
    <input name="q" class="form-control search-input"
           placeholder="🔍 Cari nama kegiatan, pemohon, NIM..."
           value="<?= e($filter_search) ?>">
    <select name="status" class="form-control w-auto" onchange="this.closest('form').submit()">
      <option value="">Semua Status</option>
      <option value="pending"   <?= $filter_status==='pending'?'selected':'' ?>>⏳ Menunggu</option>
      <option value="disetujui" <?= $filter_status==='disetujui'?'selected':'' ?>>✅ Disetujui</option>
      <option value="ditolak"   <?= $filter_status==='ditolak'?'selected':'' ?>>❌ Ditolak</option>
      <option value="selesai"   <?= $filter_status==='selesai'?'selected':'' ?>>🏁 Selesai</option>
    </select>
    <select name="gedung" class="form-control w-auto" onchange="this.closest('form').submit()">
      <option value="">🏢 Semua Gedung</option>
      <?php foreach ($gedung_indoor as $g): ?>
      <option value="<?= e($g) ?>" <?= $filter_gedung===$g?'selected':'' ?>><?= e($g) ?></option>
      <?php endforeach; ?>
      <?php if ($has_outdoor): ?>
      <option value="__outdoor__" <?= $filter_gedung==='__outdoor__'?'selected':'' ?>>🌿 Outdoor</option>
      <?php endif; ?>
    </select>
    <span class="date-wrap<?= $filter_tanggal?'':' empty' ?>">
      <input type="date" name="tanggal" class="form-control w-auto" title="Filter per tanggal"
             value="<?= e($filter_tanggal) ?>" onchange="this.closest('form').submit()">
    </span>
    <a href="<?= base_url('admin/bookings.php') ?>" class="btn btn-secondary btn-sm">Reset</a>
  </form>
</div>

<!-- Tabel -->
<div class="card">
<?php if (empty($bookings)): ?>
  <div class="empty-state"><div class="icon">📋</div><h3>Tidak ada pengajuan</h3></div>
<?php else: ?>
<div class="table-responsive">
<table class="table">
  <thead>
    <tr><th>#</th><th>Pemohon &amp; Waktu Pengajuan</th><th>Kegiatan</th><th>Ruangan</th><th>Tanggal &amp; Waktu</th><th>Status</th><th>Aksi</th></tr>
  </thead>
  <tbody>
  <?php foreach ($bookings as $i => $b): ?>
  <tr>
    <td class="text-muted fs-sm"><?= $i+1 ?></td>
    <td>
      <div class="fw-semibold"><?= e($b['user_nama']) ?></div>
      <div class="fs-xs text-muted"><?= e($b['nim'] ?? '') ?></div>
      <div class="fs-xs text-muted"><?= e($b['prodi'] ?? '') ?></div>
      <div class="fs-xs" style="margin-top:4px;display:inline-flex;align-items:center;gap:4px;
           background:#f1f5f9;border:1px solid #e2e8f0;border-radius:5px;padding:2px 7px;
           color:#475569;white-space:nowrap"
           title="Waktu pengajuan: <?= e(date('d-m-Y H:i:s', strtotime($b['created_at']))) ?>">
        🕐 <?= e(date('d-m-Y H:i', strtotime($b['created_at']))) ?>
      </div>
    </td>
    <td>
      <div class="fw-semibold" style="max-width:180px"><?= e($b['nama_kegiatan']) ?></div>
      <?php if (!empty($b['asal_organisasi']) && $b['asal_organisasi'] !== '-'): ?>
      <div style="margin-top:4px">
        <span style="display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;
                     padding:2px 9px;border-radius:20px;white-space:nowrap;
                     background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7">
          🏛️ <?= e($b['asal_organisasi']) ?>
        </span>
      </div>
      <?php endif; ?>
      <div class="fs-xs text-muted">👥 <?= $b['jumlah_peserta'] ?> peserta</div>
    </td>
    <td>
      <div><?= e($b['ruangan_nama']) ?></div>
      <div class="fs-xs text-muted"><?= e($b['gedung']) ?></div>
      <span class="badge <?= $b['tipe']==='outdoor'?'badge-outdoor':'badge-indoor' ?>"
            style="font-size:10.5px;margin-top:4px">
        <?= $b['tipe']==='outdoor'?'🌿 Outdoor':'🏢 Indoor' ?>
      </span>
    </td>
    <td>
      <div><?= e(date('d-m-Y', strtotime($b['tanggal_mulai']))) ?></div>
      <div class="fs-xs text-muted"><?= e($b['jam_mulai']) ?>–<?= e($b['jam_selesai']) ?></div>
    </td>
    <td>
      <?= statusLabel($b['status']) ?>
      <?php if (!empty($b['_conflicts'])): ?>
      <div style="margin-top:5px">
        <span style="display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;
                     padding:2px 8px;border-radius:6px;white-space:nowrap;
                     <?= $b['_loses']
                         ? 'background:#fef2f2;color:#dc2626;border:1px solid #fecaca'
                         : 'background:#f0fdf4;color:#16a34a;border:1px solid #86efac' ?>">
          ⚠️ <?= count($b['_conflicts']) ?> Bentrok
          &nbsp;·&nbsp;
          <?php if ($b['_ahp']): ?>
            <?= $b['_loses'] ? '⬇️ Kalah (V '.number_format($b['_ahp']['current_V'],3).')'
                             : '⬆️ Unggul (V '.number_format($b['_ahp']['current_V'],3).')' ?>
          <?php endif; ?>
        </span>
      </div>
      <?php endif; ?>
      <?php if ($b['alasan_penolakan']): ?>
      <div class="fs-xs text-muted mt-1" style="max-width:140px;line-height:1.4"
           title="<?= e($b['alasan_penolakan']) ?>">
        💬 <?= e(mb_strimwidth($b['alasan_penolakan'], 0, 35, '…')) ?>
      </div>
      <?php endif; ?>
    </td>
    <td style="white-space:nowrap">
      <button class="btn btn-primary btn-sm" onclick="openDetail(<?= $b['id'] ?>)">Detail</button>
      <?php if (canValidate($user,['departemen'=>$b['departemen'],'tipe'=>$b['tipe']])): ?>
      <button type="button" class="btn btn-sm js-delete-booking"
              data-id="<?= $b['id'] ?>"
              data-name="<?= e($b['nama_kegiatan']) ?>"
              style="background:#fff;color:#dc2626;border:1px solid #fca5a5;margin-left:4px"
              title="Hapus data peminjaman">🗑️</button>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
</div>

<!-- ═══ MODALS DETAIL ═══ -->
<?php foreach ($bookings as $b): ?>
<div id="modal-<?= $b['id'] ?>" class="modal-overlay">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title">📋 Detail Peminjaman #<?= $b['id'] ?></span>
      <button class="modal-close" onclick="closeModal(<?= $b['id'] ?>)">✕</button>
    </div>
    <div class="modal-body">

      <!-- Status badge -->
      <div style="margin-bottom:14px"><?= statusLabel($b['status']) ?></div>

      <!-- ⚠️ Penyelesaian Bentrok Jadwal dengan AHP–SAW -->
      <?php if (!empty($b['_conflicts'])):
        $ahp      = $b['_ahp'];
        $srcIcon  = ['peminjaman'=>'📋','eksternal'=>'🌐','kuliah'=>'🎓'];
        $srcLabel = ['peminjaman'=>'Peminjaman Mhs','eksternal'=>'Eksternal','kuliah'=>'Jadwal Kuliah'];
        $stLabel  = fn($s) => match($s) {
            'pending'           => '⏳ Menunggu',
            'disetujui','aktif' => '✅ Disetujui',   // 'aktif' = data lama sebelum migrasi
            'kuliah'            => '🎓 Kuliah Rutin',
            default             => e($s)
        };
        $win = $ahp['winner'];
        // Rekomendasi berdasar peringkat AHP–SAW booking ini
        if ($ahp['current_rank'] === 1) {
            $recBg='#f0fdf4'; $recBorder='#86efac'; $recColor='#14532d'; $recIcon='🟢';
            $recText='Dapat <strong>Disetujui</strong> — agenda ini memperoleh nilai prioritas tertinggi '
                    .'(V = '.number_format($ahp['current_V'],3).') hasil perhitungan AHP–SAW.';
        } elseif ($win['sumber'] === 'kuliah') {
            $recBg='#faf5ff'; $recBorder='#d8b4fe'; $recColor='#6b21a8'; $recIcon='🎓';
            $recText='Sebaiknya <strong>Ditolak</strong> — bentrok dengan <strong>Jadwal Kuliah</strong> '
                    .'(“'.e($win['nama']).'”, V = '.number_format($win['V'],3).') yang merupakan kegiatan '
                    .'akademik resmi berprioritas tertinggi.';
        } else {
            $recBg='#fef2f2'; $recBorder='#fecaca'; $recColor='#991b1b'; $recIcon='🔴';
            $recText='Sebaiknya <strong>Ditolak</strong> — agenda “'.e($win['nama']).'” '
                    .'(V = '.number_format($win['V'],3).') memiliki nilai prioritas lebih tinggi '
                    .'daripada agenda ini (V = '.number_format($ahp['current_V'],3).').';
        }
      ?>
      <div style="border:1.5px solid <?= $recBorder ?>;border-radius:10px;margin-bottom:16px;overflow:hidden">

        <!-- Header -->
        <div style="background:<?= $recBg ?>;padding:12px 16px;border-bottom:1px solid <?= $recBorder ?>">
          <div style="font-weight:700;color:<?= $recColor ?>;font-size:13.5px;margin-bottom:2px">
            ⚠️ Konflik Jadwal — <?= count($b['_conflicts']) ?> bentrok terdeteksi
          </div>
          <div style="font-size:12px;color:<?= $recColor ?>">
            Penyelesaian prioritas dihitung otomatis dengan metode <strong>AHP–SAW</strong>.
          </div>
        </div>

        <!-- Dasar ilmiah: bobot kriteria AHP -->
        <div style="background:#f8fafc;padding:9px 16px;border-bottom:1px solid #e2e8f0;font-size:11px;color:#475569;line-height:1.7">
          <strong>📊 Bobot Kriteria (AHP, CR = <?= number_format(AHP_CR,3) ?> &lt; 0,1 → konsisten):</strong><br>
          K1 Jenis Agenda <b><?= number_format(AHP_BOBOT['k1'],3) ?></b> ·
          K2 Status <b><?= number_format(AHP_BOBOT['k2'],3) ?></b> ·
          K3 Waktu Pengajuan <b><?= number_format(AHP_BOBOT['k3'],3) ?></b> ·
          K4 Rasio Peserta/Kapasitas <b><?= number_format(AHP_BOBOT['k4'],3) ?></b>
        </div>

        <!-- Tabel perankingan SAW -->
        <div style="padding:12px 16px;overflow-x:auto">
          <table style="width:100%;border-collapse:collapse;font-size:12px">
            <thead>
              <tr style="background:#f1f5f9;color:#334155;text-align:left">
                <th style="padding:6px 8px;border-bottom:2px solid #cbd5e1">#</th>
                <th style="padding:6px 8px;border-bottom:2px solid #cbd5e1">Agenda</th>
                <th style="padding:6px 8px;border-bottom:2px solid #cbd5e1;text-align:center">Jenis</th>
                <th style="padding:6px 8px;border-bottom:2px solid #cbd5e1;text-align:center">Status</th>
                <th style="padding:6px 8px;border-bottom:2px solid #cbd5e1;text-align:center">Nilai V</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ahp['parties'] as $p):
                $isCur = $p['is_current'];
                $rowBg = $isCur ? '#eff6ff' : ($p['rank']===1 ? '#f0fdf4' : '#fff');
                $icon  = $srcIcon[$p['sumber']]  ?? '📌';
                $jenis = $srcLabel[$p['sumber']] ?? $p['sumber'];
              ?>
              <tr style="background:<?= $rowBg ?>;border-bottom:1px solid #e2e8f0">
                <td style="padding:7px 8px;font-weight:700;color:<?= $p['rank']===1 ? '#16a34a' : '#64748b' ?>">
                  <?= $p['rank']===1 ? '🏆 1' : $p['rank'] ?>
                </td>
                <td style="padding:7px 8px">
                  <div style="font-weight:600;color:#1e293b">
                    <?= e($p['nama']) ?>
                    <?php if ($isCur): ?><span style="background:#3b82f6;color:#fff;font-size:9.5px;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:4px">INI</span><?php endif; ?>
                  </div>
                  <?php if (!empty($p['pemohon'])): ?>
                  <div style="font-size:11px;color:#64748b">👤 <?= e($p['pemohon']) ?></div>
                  <?php endif; ?>
                </td>
                <td style="padding:7px 8px;text-align:center;white-space:nowrap"><?= $icon ?> <?= $jenis ?></td>
                <td style="padding:7px 8px;text-align:center;white-space:nowrap;font-size:11px"><?= $stLabel($p['status']) ?></td>
                <td style="padding:7px 8px;text-align:center;font-weight:700;color:<?= $p['rank']===1 ? '#16a34a' : '#475569' ?>">
                  <?= number_format($p['V'],3) ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div style="font-size:10.5px;color:#94a3b8;margin-top:6px;line-height:1.5">
            V = Σ (bobot × nilai ternormalisasi). Nilai V tertinggi = prioritas utama atas ruangan.
            Jadwal kuliah selalu bernilai tertinggi (V = 1,000) sebagai kegiatan akademik resmi.
          </div>
        </div>

        <!-- Rekomendasi -->
        <div style="background:<?= $recBg ?>;border-top:1px solid <?= $recBorder ?>;
                    padding:10px 16px;font-size:12.5px;color:<?= $recColor ?>">
          <?= $recIcon ?> <strong>Rekomendasi AHP–SAW:</strong> <?= $recText ?>
        </div>

      </div>
      <?php endif; ?>

      <!-- Info detail -->
      <?php
      $details = [
        'Nama Kegiatan' => $b['nama_kegiatan'],
        'Asal Organisasi' => ($b['asal_organisasi'] ?? '') ?: '-',
        'Pemohon'       => $b['user_nama'],
        'NIM'           => $b['nim'],
        'Prodi'         => $b['prodi'],
        'Ruangan'       => $b['ruangan_nama'].' — '.$b['gedung'],
        'Departemen'    => $b['departemen'],
        'Tipe'          => $b['tipe']==='outdoor' ? '🌿 Outdoor' : '🏢 Indoor',
        'Tanggal'       => date('d-m-Y',strtotime($b['tanggal_mulai'])).' s/d '.date('d-m-Y',strtotime($b['tanggal_selesai'])),
        'Waktu'         => $b['jam_mulai'].' – '.$b['jam_selesai'],
        'Peserta'       => $b['jumlah_peserta'].' orang',
        'Keterangan'    => ($b['keterangan'] ?? '-'),
        'Diajukan'      => date('d-m-Y H:i', strtotime($b['created_at'])),
      ];
      foreach ($details as $k => $v): if (!$v) continue; ?>
      <div class="detail-row">
        <span class="detail-label"><?= $k ?></span>
        <span class="detail-value"><?= e($v) ?></span>
      </div>
      <?php endforeach; ?>

      <!-- File surat -->
      <?php if ($b['file_surat']): ?>
      <div class="detail-row">
        <span class="detail-label">File Surat</span>
        <span class="detail-value">
          <a href="<?= base_url('uploads/surat/'.e($b['file_surat'])) ?>"
             target="_blank" class="btn btn-outline btn-sm">📎 Lihat Surat</a>
        </span>
      </div>
      <?php endif; ?>

      <!-- Info validasi -->
      <?php if ($b['validated_at']): ?>
      <div class="alert alert-<?= $b['status']==='disetujui'?'success':'danger' ?> mt-3">
        <?= $b['status']==='disetujui' ? '✅ Disetujui' : '❌ Ditolak' ?>
        pada <?= e(date('d-m-Y H:i', strtotime($b['validated_at']))) ?>
      </div>
      <?php endif; ?>

      <!-- Alasan penolakan -->
      <?php if ($b['alasan_penolakan']): ?>
      <div class="alert alert-danger mt-2" style="border-left:4px solid #dc2626">
        <strong>❌ Alasan Penolakan:</strong><br>
        <?= e($b['alasan_penolakan']) ?>
      </div>
      <?php endif; ?>

      <?php if (canValidate($user,['departemen'=>$b['departemen'],'tipe'=>$b['tipe']])
                && in_array($b['status'],['pending','disetujui','ditolak'])): ?>
      <hr class="divider">

      <!-- ── PENDING: Setujui / Tolak ── -->
      <?php if ($b['status'] === 'pending'): ?>
      <div class="d-flex gap-2 flex-wrap">
        <form method="post">
          <input type="hidden" name="action" value="approve">
          <input type="hidden" name="id" value="<?= $b['id'] ?>">
          <button type="submit" class="btn btn-success"
                  onclick="return confirm('Setujui peminjaman ini?')">✅ Setujui</button>
        </form>
        <button class="btn btn-danger" onclick="showForm('reject-form-<?= $b['id'] ?>')">
          ❌ Tolak
        </button>
      </div>
      <div id="reject-form-<?= $b['id'] ?>" style="display:none;margin-top:14px">
        <form method="post">
          <input type="hidden" name="action" value="reject">
          <input type="hidden" name="id" value="<?= $b['id'] ?>">
          <div class="form-group">
            <label style="font-weight:600">❌ Alasan Penolakan</label>
            <textarea name="alasan" class="form-control" rows="3"
                      placeholder="Tuliskan alasan penolakan (opsional)..."></textarea>
          </div>
          <div class="d-flex gap-2 mt-2">
            <button type="submit" class="btn btn-danger">Konfirmasi Tolak</button>
            <button type="button" class="btn btn-secondary"
                    onclick="hideForm('reject-form-<?= $b['id'] ?>')">Batal</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <!-- ── DISETUJUI: Tandai Selesai / Tolak ── -->
      <?php if ($b['status'] === 'disetujui'): ?>
      <div class="d-flex gap-2 flex-wrap">
        <form method="post">
          <input type="hidden" name="action" value="complete">
          <input type="hidden" name="id" value="<?= $b['id'] ?>">
          <button type="submit" class="btn btn-secondary"
                  onclick="return confirm('Tandai peminjaman ini sebagai selesai?')">🏁 Tandai Selesai</button>
        </form>
        <button class="btn btn-danger" onclick="showForm('reject-form-<?= $b['id'] ?>')">
          ❌ Tolak
        </button>
      </div>
      <div id="reject-form-<?= $b['id'] ?>" style="display:none;margin-top:14px">
        <form method="post">
          <input type="hidden" name="action" value="reject">
          <input type="hidden" name="id" value="<?= $b['id'] ?>">
          <div class="form-group">
            <label style="font-weight:600">❌ Alasan Penolakan</label>
            <textarea name="alasan" class="form-control" rows="3"
                      placeholder="Tuliskan alasan penolakan (opsional)..."></textarea>
          </div>
          <div class="d-flex gap-2 mt-2">
            <button type="submit" class="btn btn-danger"
                    onclick="return confirm('Tolak peminjaman yang sudah disetujui ini?')">Konfirmasi Tolak</button>
            <button type="button" class="btn btn-secondary"
                    onclick="hideForm('reject-form-<?= $b['id'] ?>')">Batal</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <!-- ── DITOLAK: Setujui Kembali ── -->
      <?php if ($b['status'] === 'ditolak'): ?>
      <div class="d-flex gap-2 flex-wrap">
        <form method="post">
          <input type="hidden" name="action" value="approve">
          <input type="hidden" name="id" value="<?= $b['id'] ?>">
          <button type="submit" class="btn btn-success"
                  onclick="return confirm('Setujui kembali peminjaman ini?')">✅ Setujui</button>
        </form>
      </div>
      <?php endif; ?>

      <?php endif; // canValidate ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- ═══ MODAL HAPUS PERMANEN (shared) ═══ -->
<div id="modal-delete-booking" class="modal-overlay">
<div class="modal-box" style="max-width:440px">
  <div class="modal-header" style="background:#fef2f2;border-bottom:1px solid #fecaca">
    <span class="modal-title" style="color:#dc2626">🗑️ Hapus Data Peminjaman</span>
    <button type="button" class="modal-close" id="delete-booking-close">✕</button>
  </div>
  <div class="modal-body">
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:13px;color:#991b1b;line-height:1.8">
      ⚠️ <strong>Tindakan ini tidak bisa dibatalkan!</strong><br>
      Data peminjaman <strong id="delete-booking-name"></strong> akan dihapus permanen beserta file surat yang diunggah.
    </div>
    <div class="form-group" style="margin-bottom:0">
      <label style="font-weight:700;font-size:13px">Ketik <code style="background:#fee2e2;color:#dc2626;padding:1px 6px;border-radius:4px">HAPUS</code> untuk konfirmasi:</label>
      <input type="text" id="delete-booking-confirm-input" class="form-control"
             placeholder="HAPUS" autocomplete="off"
             style="margin-top:6px;border-color:#fca5a5">
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" id="delete-booking-cancel">Batal</button>
    <form method="post" id="delete-booking-form" style="display:inline">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" id="delete-booking-id">
      <input type="hidden" name="confirm_text" id="delete-booking-confirm-text">
      <button type="button" id="delete-booking-btn" class="btn btn-danger" disabled
              style="opacity:.45;cursor:not-allowed">
        🗑️ Hapus Permanen
      </button>
    </form>
  </div>
</div>
</div>

<script>
function openDetail(id) { document.getElementById('modal-'+id).classList.add('show'); }
function closeModal(id)  { document.getElementById('modal-'+id).classList.remove('show'); }
function showForm(id)    { document.getElementById(id).style.display = 'block'; }
function hideForm(id)    { document.getElementById(id).style.display = 'none'; }

(function () {
  var modal      = document.getElementById('modal-delete-booking');
  var inputId    = document.getElementById('delete-booking-id');
  var inputText  = document.getElementById('delete-booking-confirm-text');
  var confirmBox = document.getElementById('delete-booking-confirm-input');
  var nameEl     = document.getElementById('delete-booking-name');
  var confirmBtn = document.getElementById('delete-booking-btn');
  var form       = document.getElementById('delete-booking-form');

  function setBtn(ok) {
    confirmBtn.disabled = !ok;
    confirmBtn.style.opacity = ok ? '1' : '.45';
    confirmBtn.style.cursor  = ok ? 'pointer' : 'not-allowed';
  }
  function openModal(id, name) {
    // Tutup modal lain yang sedang terbuka agar tidak saling menutupi
    document.querySelectorAll('.modal-overlay.show').forEach(function (m) {
      m.classList.remove('show');
    });
    inputId.value      = id;
    inputText.value    = '';
    confirmBox.value   = '';
    nameEl.textContent = '"' + name + '"';
    setBtn(false);
    modal.classList.add('show');
    setTimeout(function () { confirmBox.focus(); }, 100);
  }
  function closeModalDel() { modal.classList.remove('show'); }

  // Tombol 🗑️ di kolom Aksi (event delegation — aman untuk semua baris)
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-delete-booking');
    if (!btn) return;
    e.preventDefault();
    openModal(btn.getAttribute('data-id'), btn.getAttribute('data-name'));
  });

  // Validasi ketikan "HAPUS"
  confirmBox.addEventListener('input', function () {
    setBtn(this.value.trim() === 'HAPUS');
  });

  // Submit hanya jika ketikan benar
  confirmBtn.addEventListener('click', function () {
    if (confirmBox.value.trim() !== 'HAPUS') return;
    inputText.value = 'HAPUS';
    form.submit();
  });

  // Tombol Batal & X
  document.getElementById('delete-booking-cancel').addEventListener('click', closeModalDel);
  document.getElementById('delete-booking-close').addEventListener('click', closeModalDel);

  // Klik backdrop untuk menutup
  modal.addEventListener('click', function (e) { if (e.target === this) closeModalDel(); });
})();

// Close modal lain saat klik backdrop
document.querySelectorAll('.modal-overlay').forEach(function (m) {
  if (m.id === 'modal-delete-booking') return;
  m.addEventListener('click', function (e) { if (e.target === this) this.classList.remove('show'); });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
