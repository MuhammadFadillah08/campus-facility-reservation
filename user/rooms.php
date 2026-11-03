<?php
require_once __DIR__ . '/../includes/config.php';
$user = requireLogin();
$page_title = 'Daftar Ruangan Fakultas Sains';
$db = db();

$filter_tipe     = $_GET['tipe'] ?? '';
$filter_dept     = $_GET['dept'] ?? '';
$search          = trim($_GET['q'] ?? '');
$tanggal_cek     = $_GET['tanggal_cek'] ?? '';
$jam_mulai_cek   = $_GET['jam_mulai_cek'] ?? '';
$jam_selesai_cek = $_GET['jam_selesai_cek'] ?? '';
$only_available  = isset($_GET['only_available']);
$cek_aktif       = $tanggal_cek && $jam_mulai_cek && $jam_selesai_cek;

$where = ['1=1'];
if ($filter_tipe) $where[] = "tipe='"        . $db->real_escape_string($filter_tipe) . "'";
if ($filter_dept) $where[] = "departemen='"  . $db->real_escape_string($filter_dept) . "'";
if ($search)      $where[] = "(nama LIKE '%" . $db->real_escape_string($search) . "%' OR gedung LIKE '%" . $db->real_escape_string($search) . "%')";

if ($cek_aktif) {
    $d   = $db->real_escape_string($tanggal_cek);
    $t1  = $db->real_escape_string($jam_mulai_cek);
    $t2  = $db->real_escape_string($jam_selesai_cek);
    $dow = (int)date('N', strtotime($tanggal_cek)); // 1=Senin…7=Minggu, sesuai kolom hari

    /* ── Conflict: peminjaman ── */
    $sql_pmj_count = "SELECT COUNT(*) FROM peminjaman p
        WHERE p.ruangan_id=ruangan.id AND p.status IN('pending','disetujui')
        AND CONCAT(p.tanggal_mulai,' ',p.jam_mulai) < CONCAT('$d',' ','$t2')
        AND CONCAT(p.tanggal_selesai,' ',p.jam_selesai) > CONCAT('$d',' ','$t1')";

    /* ── Conflict: jadwal kuliah ── */
    $sql_kul_count = "SELECT COUNT(*) FROM jadwal_kuliah jk
        WHERE jk.ruangan_id=ruangan.id AND jk.hari=$dow
        AND jk.tgl_mulai_semester <= '$d' AND jk.tgl_selesai_semester >= '$d'
        AND jk.jam_mulai < '$t2' AND jk.jam_selesai > '$t1'";

    $booking_subq = "(($sql_pmj_count) + ($sql_kul_count)) as booking_conflict";

    /* ── Slot label gabungan — dua scalar subquery, tidak pakai derived table UNION ── */
    $sql_pmj_slots = "SELECT GROUP_CONCAT(
        CONCAT(TIME_FORMAT(p.jam_mulai,'%H:%i'),'–',TIME_FORMAT(p.jam_selesai,'%H:%i'),
               ' [',IF(p.status='disetujui','📋 Disetujui','📋 Menunggu'),']')
        ORDER BY p.jam_mulai SEPARATOR ', ')
        FROM peminjaman p
        WHERE p.ruangan_id=ruangan.id AND p.status IN('pending','disetujui')
        AND CONCAT(p.tanggal_mulai,' ',p.jam_mulai) < CONCAT('$d',' ','$t2')
        AND CONCAT(p.tanggal_selesai,' ',p.jam_selesai) > CONCAT('$d',' ','$t1')";

    $sql_kul_slots = "SELECT GROUP_CONCAT(
        CONCAT(TIME_FORMAT(jk.jam_mulai,'%H:%i'),'–',TIME_FORMAT(jk.jam_selesai,'%H:%i'),
               ' [📚 Kuliah: ',jk.nama_matkul,']')
        ORDER BY jk.jam_mulai SEPARATOR ', ')
        FROM jadwal_kuliah jk
        WHERE jk.ruangan_id=ruangan.id AND jk.hari=$dow
        AND jk.tgl_mulai_semester <= '$d' AND jk.tgl_selesai_semester >= '$d'
        AND jk.jam_mulai < '$t2' AND jk.jam_selesai > '$t1'";

    /* CONCAT_WS otomatis skip nilai NULL — aman jika salah satu tidak ada konflik */
    $conflict_subq = "NULLIF(CONCAT_WS(', ', ($sql_pmj_slots), ($sql_kul_slots)), '') as conflict_slots";
} else {
    $booking_subq  = "0 as booking_conflict";
    $conflict_subq = "NULL as conflict_slots";
}

$rooms     = $db->query("SELECT *, $booking_subq, $conflict_subq FROM ruangan WHERE " . implode(' AND ', $where) . " ORDER BY departemen, nama")->fetch_all(MYSQLI_ASSOC);
$all_depts = $db->query("SELECT DISTINCT departemen FROM ruangan ORDER BY departemen")->fetch_all(MYSQLI_ASSOC);

/* Label display untuk departemen khusus */
$dept_label = [
    'Area Outdoor Fakultas Sains' => 'Admin DMF',
    'Admin Fakultas Sains'        => 'Admin Fakultas Sains',
];

if ($cek_aktif && $only_available) {
    $rooms = array_values(array_filter($rooms, fn($r) => $r['tersedia'] && $r['booking_conflict'] == 0));
}

$total         = count($rooms);
$avail_count   = $cek_aktif ? count(array_filter($rooms, fn($r) =>  $r['tersedia'] && $r['booking_conflict'] == 0)) : null;
$booked_count  = $cek_aktif ? count(array_filter($rooms, fn($r) =>  $r['tersedia'] && $r['booking_conflict'] > 0))  : null;
$unavail_count = $cek_aktif ? count(array_filter($rooms, fn($r) => !$r['tersedia']))                                 : null;

/* ── Peminjaman mahasiswa — 365 hari ke depan ── */
$jadwal_booking = $db->query("
    SELECT p.id, p.nama_kegiatan, p.tanggal_mulai, p.tanggal_selesai,
           p.jam_mulai, p.jam_selesai, p.status, p.jumlah_peserta,
           p.asal_organisasi,
           u.nama AS user_nama,
           r.nama AS ruangan_nama, r.gedung, r.tipe, r.departemen
    FROM peminjaman p
    JOIN ruangan r ON p.ruangan_id = r.id
    JOIN users u ON p.user_id = u.id
    WHERE p.status IN ('pending','disetujui')
      AND p.tanggal_selesai >= CURDATE()
      AND p.tanggal_mulai  <= DATE_ADD(CURDATE(), INTERVAL 365 DAY)
    ORDER BY p.tanggal_mulai ASC, p.jam_mulai ASC
    LIMIT 500
")->fetch_all(MYSQLI_ASSOC);

/* ── Peminjaman eksternal — 365 hari ke depan ── */
$jadwal_eksternal = $db->query("
    SELECT pe.id, pe.nama_kegiatan, pe.tanggal_mulai, pe.tanggal_selesai,
           pe.jam_mulai, pe.jam_selesai, pe.status, pe.jumlah_peserta,
           pe.nama_pemohon, pe.instansi, pe.jenis_pemohon,
           r.nama AS ruangan_nama, r.gedung, r.tipe, r.departemen
    FROM peminjaman_eksternal pe
    JOIN ruangan r ON pe.ruangan_id = r.id
    WHERE pe.status IN ('pending','disetujui')
      AND pe.tanggal_selesai >= CURDATE()
      AND pe.tanggal_mulai  <= DATE_ADD(CURDATE(), INTERVAL 365 DAY)
    ORDER BY pe.tanggal_mulai ASC, pe.jam_mulai ASC
    LIMIT 500
")->fetch_all(MYSQLI_ASSOC);

/* ── Jadwal Kuliah — expand ke tanggal aktual 30 hari ke depan ── */
$jadwal_kuliah_raw = $db->query("
    SELECT jk.ruangan_id, jk.nama_matkul, jk.kode_matkul, jk.dosen,
           jk.prodi, jk.kelas, jk.hari,
           TIME_FORMAT(jk.jam_mulai,'%H:%i')    AS jam_mulai,
           TIME_FORMAT(jk.jam_selesai,'%H:%i')  AS jam_selesai,
           jk.tgl_mulai_semester, jk.tgl_selesai_semester,
           jk.tahun_ajaran, jk.semester,
           r.nama AS ruangan_nama, r.gedung, r.tipe, r.departemen
    FROM jadwal_kuliah jk
    JOIN ruangan r ON jk.ruangan_id = r.id
    WHERE jk.tgl_selesai_semester >= CURDATE()
      AND jk.tgl_mulai_semester   <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY jk.hari, jk.jam_mulai
")->fetch_all(MYSQLI_ASSOC);

$kuliah_entries = [];
$curDate  = new DateTime('today');
$endLimit = (clone $curDate)->modify('+365 days');

foreach ($jadwal_kuliah_raw as $jk) {
    $semStart  = new DateTime($jk['tgl_mulai_semester']);
    $semEnd    = new DateTime($jk['tgl_selesai_semester']);
    $rangeStart = $curDate  > $semStart ? clone $curDate  : clone $semStart;
    $rangeEnd   = $endLimit < $semEnd   ? clone $endLimit : clone $semEnd;
    $targetN    = (int)$jk['hari']; // 1=Senin…7=Minggu (ISO N)

    // Lompat ke hari pertama yang sesuai dalam range
    $d    = clone $rangeStart;
    $diff = ($targetN - (int)$d->format('N') + 7) % 7;
    if ($diff > 0) $d->modify("+{$diff} days");

    while ($d <= $rangeEnd) {
        $kuliah_entries[] = [
            'tanggal'       => $d->format('Y-m-d'),
            'ruangan_nama'  => $jk['ruangan_nama'],
            'gedung'        => $jk['gedung'],
            'tipe'          => $jk['tipe'],
            'departemen'    => $jk['departemen'],
            'jam_mulai'     => $jk['jam_mulai'],
            'jam_selesai'   => $jk['jam_selesai'],
            'nama_kegiatan' => $jk['nama_matkul'].($jk['kode_matkul'] ? ' ('.$jk['kode_matkul'].')' : ''),
            'keterangan'    => trim(($jk['kelas'] ? 'Kelas '.$jk['kelas'] : '').($jk['dosen'] ? ' · '.$jk['dosen'] : '')),
            'prodi'         => $jk['prodi'] ?? '',
            'sumber'        => 'kuliah',
            'status'        => 'kuliah',
        ];
        $d->modify('+7 days');
    }
}

/* Badge hanya hitung 30 hari ke depan agar angka tetap relevan */
$_end30 = date('Y-m-d', strtotime('+30 days'));
$total_close = count(array_filter($jadwal_booking,  fn($p) => $p['tanggal_mulai']  <= $_end30))
             + count(array_filter($kuliah_entries,   fn($e) => $e['tanggal']        <= $_end30))
             + count(array_filter($jadwal_eksternal, fn($p) => $p['tanggal_mulai']  <= $_end30));

require_once __DIR__.'/../includes/header.php';
?>
<div class="section-title">🏢 Daftar Ruangan Fakultas Sains</div>
<div class="section-sub">Semua gedung dan ruangan di lingkungan Fakultas Sains Universitas Contoh</div>

<div class="filter-bar" style="flex-direction:column;gap:12px;align-items:stretch">
  <form method="get" id="filter-form">
    <div class="d-flex gap-2 flex-wrap w-100">
      <input name="q" class="form-control search-input" placeholder="🔍 Cari ruangan atau gedung..." value="<?= e($search) ?>">
      <select name="tipe" class="form-control w-auto" onchange="this.closest('form').submit()">
        <option value="">🏛️ Semua Tipe</option>
        <option value="indoor"   <?= $filter_tipe==='indoor'  ?'selected':'' ?>>🏢 Indoor</option>
        <option value="outdoor"  <?= $filter_tipe==='outdoor' ?'selected':'' ?>>🌿 Outdoor</option>
      </select>
      <select name="dept" class="form-control w-auto" onchange="this.closest('form').submit()">
        <option value="">Semua Departemen</option>
        <?php foreach($all_depts as $d):
            $dVal   = $d['departemen'];
            $dLabel = $dept_label[$dVal] ?? $dVal;
        ?><option value="<?= e($dVal) ?>" <?= $filter_dept===$dVal?'selected':'' ?>><?= e($dLabel) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="avail-check-bar">
      <span class="avail-check-label">📅 Check Ketersediaan:</span>
      <input type="date" name="tanggal_cek" class="form-control" style="width:160px" value="<?= e($tanggal_cek) ?>" min="<?= date('Y-m-d') ?>" title="Tanggal yang ingin dicek">
      <input type="time" name="jam_mulai_cek" class="form-control" style="width:120px" value="<?= e($jam_mulai_cek) ?>" title="Jam Mulai">
      <span style="color:var(--text-muted);font-size:16px;font-weight:700;line-height:36px">–</span>
      <input type="time" name="jam_selesai_cek" class="form-control" style="width:120px" value="<?= e($jam_selesai_cek) ?>" title="Jam Selesai">
      <button type="submit" class="btn btn-primary btn-sm">Check</button>
      <button type="button" class="btn btn-sm" style="background:#fff;border:1.5px solid var(--primary-light);color:var(--primary);font-weight:600;white-space:nowrap" onclick="openKalenderModal()">
        📅 Kalender Booking
      </button>
      <?php if (defined('ORMAWA_SHEET_URL') && ORMAWA_SHEET_URL !== ''): ?>
      <button type="button" class="btn btn-sm" style="background:#fff;border:1.5px solid var(--primary-light);color:var(--primary);font-weight:600;white-space:nowrap" onclick="window.open(<?= e(json_encode(ORMAWA_SHEET_URL)) ?>,'_blank','noopener')">
        🔄 Check Sinkronisasi ORMAWA Fakultas Sains
      </button>
      <?php endif; ?>
      <?php if($cek_aktif||$search||$filter_tipe||$filter_dept): ?>
      <a href="<?= base_url('user/rooms.php') ?>" class="btn btn-secondary btn-sm">Reset</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if($cek_aktif): ?>
<div class="avail-summary">
  <div class="avail-summary-title">📊 Ketersediaan pada <strong><?= e(date('d-m-Y', strtotime($tanggal_cek))) ?></strong> pukul <strong><?= e($jam_mulai_cek) ?> – <?= e($jam_selesai_cek) ?></strong></div>
  <div class="avail-summary-stats">
    <button type="button" class="avail-stat avail-stat-total avail-fbtn avail-fbtn-all active"
            data-filter="" onclick="filterByAvail('',this)">
      📋 <?= $total ?> Semua
    </button>
    <button type="button" class="avail-stat avail-stat-ok avail-fbtn"
            data-filter="tersedia" onclick="filterByAvail('tersedia',this)">
      ✅ <?= $avail_count ?> Tersedia
    </button>
    <button type="button" class="avail-stat avail-stat-booked avail-fbtn"
            data-filter="dibooking" onclick="filterByAvail('dibooking',this)">
      🔴 <?= $booked_count ?> Dibooking
    </button>
    <button type="button" class="avail-stat avail-stat-off avail-fbtn"
            data-filter="nonaktif" onclick="filterByAvail('nonaktif',this)">
      ⛔ <?= $unavail_count ?> Tidak Aktif
    </button>
  </div>
</div>
<?php endif; ?>

<div class="room-grid">
<?php foreach($rooms as $r):
    $disabled  = !$r['tersedia'];
    $booked    = $cek_aktif && $r['booking_conflict'] > 0;
    $available = !$disabled && !$booked;
    $bookUrl   = base_url('user/booking.php?room='.$r['id']);
    if ($tanggal_cek)     $bookUrl .= '&tanggal_cek='    . urlencode($tanggal_cek);
    if ($jam_mulai_cek)   $bookUrl .= '&jam_mulai_cek='  . urlencode($jam_mulai_cek);
    if ($jam_selesai_cek) $bookUrl .= '&jam_selesai_cek='. urlencode($jam_selesai_cek);
?>
<div class="room-card<?= $booked?' room-card-booked':'' ?>"
     data-avail="<?= $cek_aktif ? ($booked ? 'dibooking' : ($disabled ? 'nonaktif' : 'tersedia')) : 'tersedia' ?>"
     style="cursor:pointer"
     onclick="openRoomDetail(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)">
  <?php $fp=UPLOAD_RUANGAN.$r['foto']; ?>
  <?php if($r['foto']&&file_exists($fp)): ?>
    <img src="<?= base_url('uploads/ruangan/'.e($r['foto'])) ?>" alt="<?= e($r['nama']) ?>" style="width:100%;height:170px;object-fit:cover;display:block">
  <?php else: ?>
    <div class="room-card-img <?= $r['tipe']==='outdoor'?'outdoor-placeholder':'' ?>"><?= $r['tipe']==='outdoor'?'🌿':'🏢' ?></div>
  <?php endif; ?>
  <?php if($booked): ?><div class="room-booked-ribbon">🔴 Sudah Dibooking</div><?php endif; ?>
  <div class="room-card-body">
    <div class="d-flex align-center justify-between">
      <div class="room-card-name"><?= e($r['nama']) ?></div>
      <?php if($cek_aktif): ?>
        <?php if($booked): ?>
          <span class="room-status-unavailable">🔴 Dibooking</span>
        <?php elseif($disabled): ?>
          <span class="room-status-unavailable" style="background:#f3f4f6;color:#6b7280">⛔ Nonaktif</span>
        <?php else: ?>
          <span class="room-status-available">✅ Tersedia</span>
        <?php endif; ?>
      <?php else: ?>
        <span class="<?= $r['tersedia']?'room-status-available':'room-status-unavailable' ?>"><?= $r['tersedia']?'✅ Tersedia':'🔴 Tidak Tersedia' ?></span>
      <?php endif; ?>
    </div>
    <div class="room-card-building"><?= e($r['gedung']) ?></div>
    <div class="room-card-meta">
      <span>👥 <?= $r['kapasitas'] ?> orang</span>
      <span>🏫 <?= e($dept_label[$r['departemen']] ?? $r['departemen']) ?></span>
      <span class="badge <?= $r['tipe']==='outdoor'?'badge-outdoor':'badge-indoor' ?>" style="font-size:10.5px"><?= $r['tipe']==='outdoor'?'🌿 Outdoor':'🏢 Indoor' ?></span>
    </div>
    <?php if($r['fasilitas']): ?><div class="room-card-facilities"><?php foreach(explode(',',$r['fasilitas']) as $f): ?><span class="facility-tag"><?= e(trim($f)) ?></span><?php endforeach; ?></div><?php endif; ?>
    <?php if($booked && $r['conflict_slots']): ?>
    <div class="conflict-info">📌 Jadwal bentrok: <?= e($r['conflict_slots']) ?></div>
    <?php endif; ?>
    <div class="room-card-actions">
    <?php if($available && $user['role']==='user'): ?>
      <a href="<?= $bookUrl ?>" class="btn btn-primary btn-block" onclick="event.stopPropagation()">📋 Pesan</a>
    <?php elseif($booked): ?>
      <div class="btn btn-block" style="cursor:not-allowed;background:#fee2e2;color:#991b1b;border-radius:10px;padding:10px;text-align:center;font-weight:700;font-size:13.5px">🔴 Sedang Dibooking</div>
    <?php elseif($disabled): ?>
      <div class="btn btn-secondary btn-block" style="cursor:not-allowed;opacity:.6">⛔ Tidak Tersedia</div>
    <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php if(empty($rooms)): ?>
<div class="empty-state" style="grid-column:1/-1">
  <div class="icon">🔍</div>
  <h3>Tidak ada ruangan</h3>
</div>
<?php endif; ?>
<div id="avail-no-results" style="display:none;grid-column:1/-1;text-align:center;padding:40px 20px;color:#6b7280">
  <div style="font-size:38px;margin-bottom:10px">🔍</div>
  <div id="avail-no-results-msg" style="font-weight:700;font-size:15px;color:#374151;margin-bottom:6px"></div>
  <button onclick="filterByAvail('',document.querySelector('.avail-fbtn-all'))"
          style="margin-top:12px;padding:7px 18px;background:#16a34a;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">
    🔄 Tampilkan Semua
  </button>
</div>
</div>

<!-- ══ Modal Jadwal Booking ══ -->
<div id="jadwal-modal" class="modal-overlay">
<div class="modal-box" style="max-width:720px;max-height:90vh;display:flex;flex-direction:column">
  <div class="modal-header" style="flex-shrink:0">
    <span class="modal-title">🚫 Ruangan Terpakai — Perkuliahan &amp; Booking</span>
    <button class="modal-close" onclick="document.getElementById('jadwal-modal').classList.remove('show')">✕</button>
  </div>
  <!-- Filter bar modal -->
  <div style="padding:14px 20px 10px;border-bottom:1px solid #f1f5f9;flex-shrink:0;display:flex;flex-wrap:wrap;gap:10px;align-items:center">
    <input type="text" id="jm-search" placeholder="🔍 Cari ruangan atau nama kegiatan..."
           style="flex:1;min-width:200px;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none"
           oninput="renderJadwal()" onfocus="this.style.borderColor='var(--primary-light)'" onblur="this.style.borderColor='#e2e8f0'">
    <div style="display:flex;gap:6px;flex-shrink:0">
      <button type="button" class="jm-tab active" data-range="today"   onclick="setJmTab(this,'today')">Hari Ini</button>
      <button type="button" class="jm-tab"        data-range="week"    onclick="setJmTab(this,'week')">7 Hari</button>
      <button type="button" class="jm-tab"        data-range="month"   onclick="setJmTab(this,'month')">30 Hari</button>
      <button type="button" class="jm-tab"        data-range="all"     onclick="setJmTab(this,'all')">All Time</button>
    </div>
    <div id="jm-count" style="font-size:12.5px;color:#64748b;flex-shrink:0"></div>
  </div>
  <!-- Source filter buttons -->
  <div class="jm-src-wrap jm-src-all-active" style="padding:8px 20px 12px;border-bottom:1px solid #f1f5f9;flex-shrink:0;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <span style="font-size:11.5px;color:#94a3b8;font-weight:600;flex-shrink:0">Filter:</span>
    <button type="button" class="jm-src-btn" data-src="kuliah"
            onclick="toggleJmSource('kuliah',this)"
            style="background:#fff7ed;color:#c2410c;border:1.5px solid #fdba74;padding:5px 13px;border-radius:20px;font-size:12px;font-weight:700;cursor:pointer">
      📚 Kuliah<span id="cnt-kuliah" style="font-weight:400;margin-left:2px"></span>
    </button>
    <button type="button" class="jm-src-btn" data-src="booking"
            onclick="toggleJmSource('booking',this)"
            style="background:#eff6ff;color:#1d4ed8;border:1.5px solid #93c5fd;padding:5px 13px;border-radius:20px;font-size:12px;font-weight:700;cursor:pointer">
      📋 Booking<span id="cnt-booking" style="font-weight:400;margin-left:2px"></span>
    </button>
    <button type="button" class="jm-src-btn" data-src="eksternal"
            onclick="toggleJmSource('eksternal',this)"
            style="background:#faf5ff;color:#7c3aed;border:1.5px solid #c4b5fd;padding:5px 13px;border-radius:20px;font-size:12px;font-weight:700;cursor:pointer">
      🌐 Eksternal<span id="cnt-eksternal" style="font-weight:400;margin-left:2px"></span>
    </button>
  </div>
  <!-- Isi jadwal -->
  <div id="jm-body" style="flex:1;overflow-y:auto;padding:16px 20px"></div>
</div>
</div>

<!-- Detail Modal -->
<div id="room-detail-modal" class="modal-overlay">
<div class="modal-box" style="max-width:600px">
  <div class="modal-header">
    <span class="modal-title" id="rd-title">Detail Ruangan</span>
    <button class="modal-close" onclick="document.getElementById('room-detail-modal').classList.remove('show')">✕</button>
  </div>
  <div class="modal-body">
    <div id="rd-img-wrap" style="margin-bottom:16px;border-radius:10px;overflow:hidden"></div>
    <div id="rd-avail"></div>
    <div id="rd-fields"></div>
    <div id="rd-btn-wrap" style="margin-top:16px"></div>
  </div>
</div>
</div>

<!-- ══ Modal Kalender Booking ══ -->
<div id="kalender-modal" class="modal-overlay">
<div class="modal-box cal-modal-box" style="max-width:1040px;max-height:92vh;display:flex;flex-direction:column">
  <div class="modal-header" style="flex-shrink:0">
    <span class="modal-title">📅 Kalender Booking Ruangan</span>
    <button class="modal-close" onclick="document.getElementById('kalender-modal').classList.remove('show')">✕</button>
  </div>
  <div class="cal-body">
    <!-- KIRI: Kalender -->
    <div class="cal-left">
      <!-- Navigasi bulan -->
      <div class="cal-nav">
        <button type="button" class="cal-arrow" onclick="calShift(-1)" title="Bulan sebelumnya">‹</button>
        <div class="cal-title" id="cal-title">—</div>
        <button type="button" class="cal-arrow" onclick="calShift(1)" title="Bulan berikutnya">›</button>
        <button type="button" class="cal-today-btn" onclick="calGoToday()">Hari Ini</button>
      </div>
      <!-- Filter (sumber + penanggung jawab) — sekaligus legend warna -->
      <div id="cal-filter-top" class="cal-filter-top"></div>
      <!-- Hari (header) -->
      <div class="cal-grid-head">
        <div>Sen</div><div>Sel</div><div>Rab</div><div>Kam</div><div>Jum</div><div>Sab</div><div>Min</div>
      </div>
      <!-- Grid tanggal -->
      <div id="cal-grid" class="cal-grid"></div>
    </div>
    <!-- KANAN: Detail tanggal terpilih -->
    <div id="cal-detail" class="cal-detail"></div>
  </div>
</div>
</div>

<style>
/* ── Availability filter buttons ── */
.avail-fbtn{cursor:pointer;border:2px solid transparent;transition:all .18s;background:none;font-family:inherit;font-size:inherit}
.avail-fbtn:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.12);opacity:1!important}
.avail-fbtn:not(.active){opacity:.55}
.avail-fbtn.active{border-color:rgba(0,0,0,.18)!important;box-shadow:0 2px 8px rgba(0,0,0,.14);opacity:1!important;transform:none}
/* ── Jadwal tabs ── */
.jm-tab{background:#f1f5f9;border:1.5px solid #e2e8f0;color:#475569;padding:6px 13px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;transition:all .15s}
.jm-tab.active,.jm-tab:hover{background:var(--primary);border-color:var(--primary);color:#fff}
/* ── Sumber filter buttons ── */
.jm-src-btn{transition:opacity .2s,transform .15s,box-shadow .15s;outline:none;font-family:inherit;opacity:.45}
.jm-src-btn:hover{opacity:1;transform:scale(1.04)}
.jm-src-btn.active{opacity:1;box-shadow:0 2px 10px rgba(0,0,0,.22);transform:scale(1.06)}
.jm-src-all-active .jm-src-btn{opacity:1}
.jm-date-hd{display:flex;align-items:center;gap:10px;margin:18px 0 10px;font-size:13px;font-weight:800;color:#334155}
.jm-date-hd:first-child{margin-top:0}
.jm-date-line{flex:1;height:1px;background:#e2e8f0}
.jm-date-badge{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;padding:3px 10px;border-radius:20px;font-size:12px;white-space:nowrap}
.jm-date-badge.today-badge{background:#166534;color:#fff;border-color:#166534}
.jm-item{display:flex;align-items:flex-start;gap:12px;padding:11px 14px;background:#fff;border:1px solid #f1f5f9;border-radius:10px;margin-bottom:8px;transition:box-shadow .15s}
.jm-item:hover{box-shadow:0 2px 12px rgba(0,0,0,.08);border-color:#e2e8f0}
.jm-item-icon{font-size:22px;flex-shrink:0;margin-top:1px}
.jm-item-body{flex:1;min-width:0}
.jm-item-room{font-weight:700;font-size:13.5px;color:#1e293b;margin-bottom:3px}
.jm-item-kegiatan{font-size:13px;color:#475569;margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.jm-item-meta{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.jm-badge{padding:2px 9px;border-radius:20px;font-size:11.5px;font-weight:600;white-space:nowrap}
.jm-badge.time{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
.jm-badge.disetujui{background:#dcfce7;border:1px solid #86efac;color:#166534}
.jm-badge.pending{background:#fef9c3;border:1px solid #fde047;color:#854d0e}
.jm-badge.kuliah{background:#fff7ed;border:1px solid #fdba74;color:#c2410c}
.jm-badge.dept{background:#f5f3ff;border:1px solid #ddd6fe;color:#6d28d9}
.jm-badge.peserta{background:#f8fafc;border:1px solid #e2e8f0;color:#475569}
.jm-badge.prodi{background:#f0f9ff;border:1px solid #bae6fd;color:#0369a1}
.jm-multiday{font-size:11px;color:#94a3b8;margin-left:2px}
.jm-legend{display:flex;gap:10px;flex-wrap:wrap;padding:10px 20px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#64748b}
.jm-legend-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:5px;flex-shrink:0}
.jm-src-label{font-size:11px;font-weight:700;padding:1px 6px;border-radius:4px;margin-left:4px}
.jm-src-kuliah{background:#fff7ed;color:#c2410c;border:1px solid #fdba74}
.jm-src-booking{background:#eff6ff;color:#1d4ed8;border:1px solid #93c5fd}
.jm-src-eksternal{background:#faf5ff;color:#7c3aed;border:1px solid #c4b5fd}
.jm-badge.eksternal{background:#faf5ff;border:1px solid #c4b5fd;color:#6d28d9}
.jm-empty{text-align:center;padding:40px 20px;color:#94a3b8}
.jm-empty-ic{font-size:40px;margin-bottom:10px}
/* ── Kalender Booking ── */
.cal-body{display:flex;flex:1;min-height:0;overflow:hidden}
.cal-left{flex:1 1 auto;min-width:0;display:flex;flex-direction:column;overflow-y:auto}
.cal-nav{display:flex;align-items:center;justify-content:center;gap:12px;padding:14px 14px 8px;flex-shrink:0}
.cal-arrow{width:34px;height:34px;border-radius:8px;border:1.5px solid #e2e8f0;background:#fff;color:#334155;font-size:20px;line-height:1;cursor:pointer;font-weight:700;transition:all .15s}
.cal-arrow:hover{background:var(--primary);border-color:var(--primary);color:#fff}
.cal-title{font-weight:800;font-size:15.5px;color:#1e293b;min-width:160px;text-align:center}
.cal-today-btn{margin-left:6px;padding:7px 14px;border-radius:8px;border:1.5px solid var(--primary-light);background:#fff;color:var(--primary);font-weight:600;font-size:12.5px;cursor:pointer;transition:all .15s}
.cal-today-btn:hover{background:var(--primary);color:#fff;border-color:var(--primary)}
.cal-grid-head{display:grid;grid-template-columns:repeat(7,1fr);gap:5px;padding:0 16px;flex-shrink:0}
.cal-grid-head div{text-align:center;font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;padding:4px 0}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:5px;padding:6px 16px 14px;flex-shrink:0}
.cal-cell{min-height:64px;border:1px solid #f1f5f9;border-radius:9px;padding:4px 5px;cursor:pointer;background:#fff;transition:border-color .12s,background .12s;display:flex;flex-direction:column;gap:2px;overflow:hidden;text-align:left}
.cal-cell:hover{border-color:var(--primary-light);background:#f0fdf4}
.cal-cell.out{background:#fafafa}
.cal-cell.out .cal-cell-num{color:#cbd5e1}
.cal-cell.today{border-color:var(--primary)}
.cal-cell.today .cal-cell-num{background:var(--primary);color:#fff;border-radius:50%;width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center}
.cal-cell.selected{background:#dcfce7;border-color:#16a34a;box-shadow:0 0 0 1px #16a34a inset}
.cal-cell-top{display:flex;align-items:center;justify-content:space-between}
.cal-cell-num{font-size:12px;font-weight:700;color:#334155;line-height:1.4}
.cal-count{font-size:9.5px;background:#fb923c;color:#fff;border-radius:8px;padding:0 5px;font-weight:800;line-height:16px}
.cal-chip{font-size:9.5px;line-height:1.4;padding:1px 4px;border-radius:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:3px}
.cal-chip-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.cal-more{font-size:9px;color:#94a3b8;font-weight:700;padding-left:2px}
.cal-detail{width:340px;flex-shrink:0;overflow-y:auto;overflow-x:hidden;padding:14px 18px;border-left:1px solid #f1f5f9;background:#fcfdfd}
.cal-detail-title{font-weight:800;font-size:13.5px;color:#15803d;margin-bottom:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
/* ── Filter bar di atas kalender (sekaligus legend warna) ── */
.cal-filter-top{display:flex;flex-wrap:wrap;gap:7px;align-items:center;justify-content:center;padding:2px 14px 12px;flex-shrink:0}
.cal-fchip{display:inline-flex;align-items:center;gap:6px;cursor:pointer;border:1.5px solid #e2e8f0;background:#fff;color:#475569;border-radius:20px;padding:5px 13px;font-size:12px;font-weight:700;transition:all .15s;font-family:inherit;white-space:nowrap;line-height:1.2}
.cal-fchip .dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.cal-fchip b{font-weight:800;opacity:.85}
.cal-fchip:hover{transform:translateY(-1px);box-shadow:0 3px 11px rgba(0,0,0,.09)}
.cal-fchip.active.src-all{background:var(--primary);border-color:var(--primary);color:#fff}
.cal-fchip.active.src-kuliah{background:#fff7ed;border-color:#fb923c;color:#c2410c}
.cal-fchip.active.src-booking{background:#eff6ff;border-color:#60a5fa;color:#1d4ed8}
.cal-fchip.active.src-eksternal{background:#faf5ff;border-color:#a855f7;color:#7c3aed}
.cal-fdept{font-size:12px;padding:6px 12px;border:1.5px solid #e2e8f0;border-radius:20px;background:#fff;color:#334155;font-family:inherit;outline:none;cursor:pointer;font-weight:600;max-width:100%}
.cal-fdept:focus{border-color:var(--primary-light);box-shadow:0 0 0 3px rgba(22,163,74,.1)}
/* ── Item detail kalender — rapi, tanpa scroll horizontal ── */
.cal-detail-item{min-width:0}
.cal-detail-item .jm-item-room{white-space:normal;word-break:break-word;display:flex;flex-wrap:wrap;align-items:center;gap:4px 6px}
.cal-detail-item .jm-item-kegiatan{white-space:normal;overflow:visible;text-overflow:clip;word-break:break-word}
.cal-detail-item .jm-item-meta{gap:5px 6px}
.cal-detail-item .jm-badge{white-space:normal;word-break:break-word;line-height:1.45}
.jm-badge.multiday{background:#fef9c3;border:1px solid #fde047;color:#854d0e}
@media(max-width:820px){
  .cal-body{flex-direction:column;overflow-y:auto}
  .cal-left{overflow:visible}
  .cal-detail{width:auto;border-left:none;border-top:1px solid #f1f5f9;overflow-y:visible}
}
@media(max-width:560px){.cal-cell{min-height:54px}.cal-chip{display:none}.cal-title{min-width:120px;font-size:14px}}
</style>
<script>
const userRole      = '<?= $user['role'] ?>';
const baseUrl       = '<?= base_url() ?>';
const cekAktif      = <?= $cek_aktif ? 'true' : 'false' ?>;
const tanggalCek    = '<?= e($tanggal_cek) ?>';
const jamMulaiCek   = '<?= e($jam_mulai_cek) ?>';
const jamSelesaiCek = '<?= e($jam_selesai_cek) ?>';

/** Konversi Y-m-d → d-m-Y untuk tampilan ke user */
function fmtDate(s) {
  if (!s) return '';
  const p = String(s).split('-');
  return p.length === 3 ? p[2]+'-'+p[1]+'-'+p[0] : s;
}
/** Buang detik dari jam: "20:42:00" → "20:42" */
function fmtJam(t){ return t ? String(t).slice(0,5) : ''; }
const fmtTanggalCek = fmtDate(tanggalCek);

function openRoomDetail(r) {
  document.getElementById('rd-title').textContent = r.nama + ' — ' + r.gedung;
  const iw = document.getElementById('rd-img-wrap');
  if (r.foto) {
    iw.innerHTML = '<img src="' + baseUrl + '/uploads/ruangan/' + r.foto + '" style="width:100%;height:220px;object-fit:cover" alt="">';
  } else {
    iw.innerHTML = '<div style="width:100%;height:100px;background:' + (r.tipe==='outdoor'?'#ecfdf5':'#eff6ff') + ';display:flex;align-items:center;justify-content:center;font-size:60px;border-radius:10px">' + (r.tipe==='outdoor'?'🌿':'🏢') + '</div>';
  }

  const avDiv = document.getElementById('rd-avail');
  avDiv.style.marginBottom = '12px';
  if (cekAktif) {
    const bc = parseInt(r.booking_conflict) || 0;
    if (!r.tersedia || r.tersedia == 0) {
      avDiv.innerHTML = '<div class="alert alert-warning" style="margin-bottom:0">⛔ Ruangan ini dinonaktifkan oleh admin dan tidak dapat dipesan.</div>';
    } else if (bc > 0) {
      avDiv.innerHTML = '<div class="alert alert-danger" style="margin-bottom:0">🔴 <strong>Sudah dibooking</strong> pada ' + fmtTanggalCek + ' pukul ' + jamMulaiCek + '–' + jamSelesaiCek + (r.conflict_slots ? '<br><small>Jadwal bentrok: ' + r.conflict_slots + '</small>' : '') + '</div>';
    } else {
      avDiv.innerHTML = '<div class="alert alert-success" style="margin-bottom:0">✅ <strong>Tersedia</strong> pada ' + fmtTanggalCek + ' pukul ' + jamMulaiCek + '–' + jamSelesaiCek + '</div>';
    }
  } else {
    avDiv.innerHTML = '';
  }

  const fields = [
    ['Nama', r.nama], ['Gedung', r.gedung], ['Departemen', r.departemen],
    ['Tipe', r.tipe==='outdoor' ? '🌿 Outdoor' : '🏢 Indoor'],
    ['Kapasitas', r.kapasitas + ' orang'],
    ['Fasilitas', r.fasilitas || '—'],
    ['Deskripsi', r.deskripsi || '—'],
    ['Status', r.tersedia == 1 ? '✅ Aktif' : '⛔ Dinonaktifkan']
  ];
  document.getElementById('rd-fields').innerHTML = fields.map(([k,v]) =>
    '<div class="detail-row"><span class="detail-label">' + k + '</span><span class="detail-value">' + v + '</span></div>'
  ).join('');

  const bw = document.getElementById('rd-btn-wrap');
  const bc = parseInt(r.booking_conflict) || 0;
  const isAvail = r.tersedia == 1 && (!cekAktif || bc === 0);
  let bookUrl = baseUrl + '/user/booking.php?room=' + r.id;
  if (tanggalCek)    bookUrl += '&tanggal_cek='    + encodeURIComponent(tanggalCek);
  if (jamMulaiCek)   bookUrl += '&jam_mulai_cek='  + encodeURIComponent(jamMulaiCek);
  if (jamSelesaiCek) bookUrl += '&jam_selesai_cek='+ encodeURIComponent(jamSelesaiCek);
  bw.innerHTML = (isAvail && userRole === 'user') ?
    '<a href="' + bookUrl + '" class="btn btn-primary btn-block">📋 Pesan Ruangan Ini</a>' : '';

  document.getElementById('room-detail-modal').classList.add('show');
}
document.querySelectorAll('.modal-overlay').forEach(m => m.addEventListener('click', function(e){
  if (e.target === this) this.classList.remove('show');
}));

/* ══ Filter ketersediaan (Tersedia / Dibooking / Tidak Aktif) ══ */
function filterByAvail(status, btn) {
  // Aktifkan tombol yang diklik
  document.querySelectorAll('.avail-fbtn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');

  var cards  = document.querySelectorAll('.room-grid .room-card');
  var count  = 0;
  cards.forEach(function(card) {
    var show = !status || card.dataset.avail === status;
    card.style.display = show ? '' : 'none';
    if (show) count++;
  });

  // Tampilkan pesan kosong bila 0 hasil
  var noRes = document.getElementById('avail-no-results');
  var noMsg = document.getElementById('avail-no-results-msg');
  if (noRes && count === 0 && cards.length > 0) {
    var labels = {tersedia:'Tidak ada ruangan tersedia pada waktu ini.',
                  dibooking:'Tidak ada ruangan yang sedang dibooking.',
                  nonaktif:'Tidak ada ruangan nonaktif.'};
    if (noMsg) noMsg.textContent = labels[status] || 'Tidak ada ruangan.';
    noRes.style.display = 'block';
  } else if (noRes) {
    noRes.style.display = 'none';
  }
}

/* ══ Jadwal Ruangan Terpakai (Kuliah + Booking) ══ */
const peminjamData  = <?= json_encode(array_map(fn($p)=>array_merge($p,['sumber'=>'booking']),   $jadwal_booking),  JSON_UNESCAPED_UNICODE) ?>;
const kuliahData    = <?= json_encode($kuliah_entries, JSON_UNESCAPED_UNICODE) ?>;
const eksternalData = <?= json_encode(array_map(fn($p)=>array_merge($p,['sumber'=>'eksternal']), $jadwal_eksternal), JSON_UNESCAPED_UNICODE) ?>;
// Gabungkan semua sumber; kuliah pakai 'tanggal', booking/eksternal pakai 'tanggal_mulai'
const allJadwal = [
  ...peminjamData.map(p=>({...p, tanggal: p.tanggal_mulai})),
  ...kuliahData,
  ...eksternalData.map(p=>({...p, tanggal: p.tanggal_mulai})),
];
let jmRange        = 'today';
let jmSourceFilter = null;  // null = semua, 'kuliah'/'booking'/'eksternal' = filter eksklusif

function escJ(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function setJmTab(btn, range){
  document.querySelectorAll('.jm-tab').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  jmRange = range;
  renderJadwal();
}

function toggleJmSource(src, btn){
  const wrap    = btn.closest('.jm-src-wrap') || btn.parentElement;
  const allBtns = document.querySelectorAll('.jm-src-btn');
  if(jmSourceFilter === src){
    /* Klik lagi → kembali tampil semua */
    jmSourceFilter = null;
    allBtns.forEach(b=>b.classList.remove('active'));
    wrap.classList.add('jm-src-all-active');
  } else {
    /* Pilih sumber ini saja */
    jmSourceFilter = src;
    allBtns.forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    wrap.classList.remove('jm-src-all-active');
  }
  renderJadwal();
}

function openJadwalModal(){
  document.getElementById('jadwal-modal').classList.add('show');
  renderJadwal();
}

function getTodayStr(){
  const d=new Date(); d.setHours(0,0,0,0);
  return d.toISOString().slice(0,10);
}

function renderJadwal(){
  const q        = (document.getElementById('jm-search').value||'').toLowerCase().trim();
  const todayStr = getTodayStr();

  let limitStr = todayStr;
  if(jmRange==='week'){
    const t=new Date(todayStr); t.setDate(t.getDate()+6);
    limitStr = t.toISOString().slice(0,10);
  } else if(jmRange==='month'){
    const t=new Date(todayStr); t.setDate(t.getDate()+29);
    limitStr = t.toISOString().slice(0,10);
  } else if(jmRange==='all'){
    limitStr = '9999-12-31'; // Tanpa batas atas
  }

  /* 1. Filter tanggal + pencarian (tanpa sumber) — untuk menghitung badge */
  const baseFilter = p=>{
    const tgl = p.tanggal;
    if(tgl < todayStr || tgl > limitStr) return false;
    if((p.sumber==='booking'||p.sumber==='eksternal') && p.tanggal_selesai < todayStr) return false;
    if(!q) return true;
    return (p.ruangan_nama||'').toLowerCase().includes(q) ||
           (p.gedung||'').toLowerCase().includes(q) ||
           (p.nama_kegiatan||'').toLowerCase().includes(q) ||
           (p.departemen||'').toLowerCase().includes(q) ||
           (p.keterangan||'').toLowerCase().includes(q);
  };
  const preFiltered = allJadwal.filter(baseFilter);

  /* 2. Terapkan filter sumber eksklusif */
  const filtered = preFiltered.filter(p => !jmSourceFilter || p.sumber === jmSourceFilter);

  // Sort: tanggal ASC, lalu jam_mulai ASC
  filtered.sort((a,b)=>{
    if(a.tanggal!==b.tanggal) return a.tanggal>b.tanggal?1:-1;
    return (a.jam_mulai||'')>(b.jam_mulai||'')?1:-1;
  });

  /* Update badge count pada tombol filter sumber (selalu dari preFiltered) */
  const nKuliah    = preFiltered.filter(p=>p.sumber==='kuliah').length;
  const nBooking   = preFiltered.filter(p=>p.sumber==='booking').length;
  const nEksternal = preFiltered.filter(p=>p.sumber==='eksternal').length;
  const cK = document.getElementById('cnt-kuliah');
  const cB = document.getElementById('cnt-booking');
  const cE = document.getElementById('cnt-eksternal');
  if(cK) cK.textContent = nKuliah    ? ` (${nKuliah})`    : '';
  if(cB) cB.textContent = nBooking   ? ` (${nBooking})`   : '';
  if(cE) cE.textContent = nEksternal ? ` (${nEksternal})` : '';

  /* Status bar */
  const srcLabel = {kuliah:'📚 kuliah', booking:'📋 booking', eksternal:'🌐 eksternal'};
  const parts = [];
  ['kuliah','booking','eksternal'].forEach(s=>{
    const n = filtered.filter(p=>p.sumber===s).length;
    if(n) parts.push(`${srcLabel[s]} ${n}`);
  });
  document.getElementById('jm-count').textContent = filtered.length
    ? `${filtered.length} slot terpakai  (${parts.join(' · ')})`
    : '';

  if(!filtered.length){
    const emptyMsg = q ? 'Tidak ada ruangan yang cocok dengan pencarian.' :
      (jmRange==='today' ? '✅ Tidak ada ruangan terpakai hari ini. Semua ruangan bebas!' :
       'Tidak ada ruangan terpakai dalam periode ini.');
    document.getElementById('jm-body').innerHTML =
      `<div class="jm-empty"><div class="jm-empty-ic">✅</div>${emptyMsg}</div>`;
    return;
  }

  // Kelompokkan per tanggal
  const byDate = {};
  filtered.forEach(p=>{ if(!byDate[p.tanggal]) byDate[p.tanggal]=[]; byDate[p.tanggal].push(p); });

  const days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];

  let html = '';
  Object.keys(byDate).sort().forEach(dateStr=>{
    const d   = new Date(dateStr+'T00:00:00');
    const isT = dateStr===todayStr;
    const isTomorrow=(()=>{const t=new Date(todayStr);t.setDate(t.getDate()+1);return dateStr===t.toISOString().slice(0,10);})();
    const jumlah = byDate[dateStr].length;
    const dateLabel = isT ? `Hari Ini — ${days[d.getDay()]}` :
      (isTomorrow ? `Besok — ${days[d.getDay()]}` :
       `${days[d.getDay()]}, ${fmtDate(dateStr)}`);
    html += `<div class="jm-date-hd">
      <div class="jm-date-line"></div>
      <div class="jm-date-badge${isT?' today-badge':''}">${dateLabel}</div>
      <span style="font-size:11.5px;color:#94a3b8;flex-shrink:0">${jumlah} slot</span>
      <div class="jm-date-line"></div>
    </div>`;

    byDate[dateStr].forEach(p=>{
      if(p.sumber==='kuliah'){
        html += `<div class="jm-item" style="border-left:3px solid #fb923c">
          <div class="jm-item-icon">${p.tipe==='outdoor'?'🌿':'🏢'}</div>
          <div class="jm-item-body">
            <div class="jm-item-room">
              ${escJ(p.ruangan_nama)}
              <span style="font-weight:400;color:#64748b;font-size:12px">— ${escJ(p.gedung)}</span>
              <span class="jm-src-label jm-src-kuliah">📚 Kuliah</span>
            </div>
            <div class="jm-item-kegiatan">📖 ${escJ(p.nama_kegiatan)}</div>
            <div class="jm-item-meta">
              <span class="jm-badge time">⏰ ${p.jam_mulai}–${p.jam_selesai}</span>
              <span class="jm-badge kuliah">📚 Perkuliahan Rutin</span>
              <span class="jm-badge dept">🏫 ${escJ(p.departemen)}</span>
              ${p.prodi  ? `<span class="jm-badge prodi">🎓 ${escJ(p.prodi)}</span>`:''}
              ${p.keterangan ? `<span class="jm-badge peserta">👤 ${escJ(p.keterangan)}</span>`:''}
            </div>
          </div>
        </div>`;
      } else if(p.sumber==='booking') {
        const isMulti  = p.tanggal_mulai !== p.tanggal_selesai;
        const multiLbl = isMulti ? `<span class="jm-multiday">s/d ${fmtDate(p.tanggal_selesai)}</span>` : '';
        const stCls    = p.status==='disetujui' ? 'disetujui' : 'pending';
        const stLbl    = p.status==='disetujui' ? '✅ Disetujui' : '⏳ Menunggu';
        const bOrmawa  = p.asal_organisasi && p.asal_organisasi !== '-' ? p.asal_organisasi : '';
        const bPemohon = p.user_nama ? (bOrmawa ? `${escJ(p.user_nama)} (${escJ(bOrmawa)})` : escJ(p.user_nama)) : '';
        html += `<div class="jm-item" style="border-left:3px solid #60a5fa">
          <div class="jm-item-icon">${p.tipe==='outdoor'?'🌿':'🏢'}</div>
          <div class="jm-item-body">
            <div class="jm-item-room">
              ${escJ(p.ruangan_nama)}
              <span style="font-weight:400;color:#64748b;font-size:12px">— ${escJ(p.gedung)}</span>
              <span class="jm-src-label jm-src-booking">📋 Booking</span>
            </div>
            <div class="jm-item-kegiatan">📌 ${escJ(p.nama_kegiatan)}</div>
            <div class="jm-item-meta">
              <span class="jm-badge time">⏰ ${p.jam_mulai}–${p.jam_selesai} ${multiLbl}</span>
              <span class="jm-badge ${stCls}">${stLbl}</span>
              <span class="jm-badge dept">🏫 ${escJ(p.departemen)}</span>
              ${bPemohon ? `<span class="jm-badge peserta">👤 ${bPemohon}</span>`:''}
              ${p.jumlah_peserta ? `<span class="jm-badge peserta">👥 ${p.jumlah_peserta} orang</span>`:''}
            </div>
          </div>
        </div>`;
      } else {
        /* ── Peminjaman Eksternal ── */
        const jenisMap = {umum:'🏘️ Masyarakat Umum', instansi_luar:'🏢 Instansi Luar'};
        const jenisLbl = jenisMap[p.jenis_pemohon] || escJ(p.jenis_pemohon||'Eksternal');
        const isMulti  = p.tanggal_mulai !== p.tanggal_selesai;
        const multiLbl = isMulti ? `<span class="jm-multiday">s/d ${fmtDate(p.tanggal_selesai)}</span>` : '';
        html += `<div class="jm-item" style="border-left:3px solid #a855f7">
          <div class="jm-item-icon">${p.tipe==='outdoor'?'🌿':'🏢'}</div>
          <div class="jm-item-body">
            <div class="jm-item-room">
              ${escJ(p.ruangan_nama)}
              <span style="font-weight:400;color:#64748b;font-size:12px">— ${escJ(p.gedung)}</span>
              <span class="jm-src-label jm-src-eksternal">🌐 Eksternal</span>
            </div>
            <div class="jm-item-kegiatan">📌 ${escJ(p.nama_kegiatan)}</div>
            <div class="jm-item-meta">
              <span class="jm-badge time">⏰ ${p.jam_mulai}–${p.jam_selesai} ${multiLbl}</span>
              <span class="jm-badge eksternal">${jenisLbl}</span>
              <span class="jm-badge dept">🏫 ${escJ(p.departemen)}</span>
              ${p.nama_pemohon ? `<span class="jm-badge peserta">👤 ${escJ(p.nama_pemohon)}</span>`:''}
              ${p.instansi     ? `<span class="jm-badge prodi">🏢 ${escJ(p.instansi)}</span>`:''}
              ${p.jumlah_peserta ? `<span class="jm-badge peserta">👥 ${p.jumlah_peserta} orang</span>`:''}
            </div>
          </div>
        </div>`;
      }
    });
  });

  document.getElementById('jm-body').innerHTML = html;
}

/* ══════════════════════════════════════════════════════════
   KALENDER BOOKING — tampilan bulanan ala Google Calendar
   ══════════════════════════════════════════════════════════ */
const CAL_MONTHS = ['Januari','Februari','Maret','April','Mei','Juni',
                    'Juli','Agustus','September','Oktober','November','Desember'];
const CAL_DAYS   = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
const CAL_SRC    = {
  kuliah:    {dot:'#fb923c', bg:'#fff7ed', label:'📚 Kuliah'},
  booking:   {dot:'#60a5fa', bg:'#eff6ff', label:'📋 Booking'},
  eksternal: {dot:'#a855f7', bg:'#faf5ff', label:'🌐 Eksternal'},
};
let calY, calM, calSelected = null;
let calSrcFilter = '';   // '' = semua sumber, atau 'kuliah'/'booking'/'eksternal'
let calDeptFilter = '';  // '' = semua penanggung jawab, atau nama departemen
const CAL_DEPTS = <?php
  $cal_depts = [];
  foreach ($all_depts as $d) { $v=$d['departemen']; $cal_depts[]=['value'=>$v,'label'=>$dept_label[$v]??$v]; }
  echo json_encode($cal_depts, JSON_UNESCAPED_UNICODE);
?>;

/** Tanggal lokal → 'YYYY-MM-DD' (hindari pergeseran zona waktu toISOString) */
function toYMD(d){
  return d.getFullYear() + '-' +
    String(d.getMonth()+1).padStart(2,'0') + '-' +
    String(d.getDate()).padStart(2,'0');
}
function todayYMD(){ return toYMD(new Date()); }

/** Semua agenda yang aktif pada satu tanggal (booking/eksternal dihitung sepanjang rentangnya) */
function entriesOnDate(ds){
  return allJadwal.filter(p => {
    if (p.sumber === 'kuliah') return p.tanggal === ds;
    return (p.tanggal_mulai || p.tanggal) <= ds && (p.tanggal_selesai || p.tanggal) >= ds;
  }).sort((a,b)=>(a.jam_mulai||'')>(b.jam_mulai||'')?1:-1);
}

function openKalenderModal(){
  const t = new Date();
  calY = t.getFullYear();
  calM = t.getMonth();
  calSelected = todayYMD();
  calSrcFilter = ''; calDeptFilter = '';
  renderCalendar();
  selectCalDay(calSelected, true);
  document.getElementById('kalender-modal').classList.add('show');
}
function calSetSrc(s){ calSrcFilter = (calSrcFilter===s)?'':s; selectCalDay(calSelected, true); }
function calSetDept(d){ calDeptFilter = d; selectCalDay(calSelected, true); }

/** Render filter bar di atas kalender (chip sumber + dropdown penanggung jawab) */
function renderCalFilter(){
  const box = document.getElementById('cal-filter-top');
  if(!box) return;
  const dayEnts = calSelected ? entriesOnDate(calSelected) : [];
  const scoped  = calDeptFilter ? dayEnts.filter(p=>p.departemen===calDeptFilter) : dayEnts;
  const cAll = scoped.length;
  const cK = scoped.filter(p=>p.sumber==='kuliah').length;
  const cB = scoped.filter(p=>p.sumber==='booking').length;
  const cE = scoped.filter(p=>p.sumber==='eksternal').length;
  const chip = (val,cls,dot,label,cnt)=>{
    const on = (val==='') ? !calSrcFilter : (calSrcFilter===val);
    return `<button type="button" class="cal-fchip ${cls}${on?' active':''}" onclick="calSetSrc('${val}')">`
         + (dot?`<span class="dot" style="background:${dot}"></span>`:'')
         + `${label} <b>${cnt}</b></button>`;
  };
  box.innerHTML =
      chip('','src-all','','🗂️ Semua',cAll)
    + chip('kuliah','src-kuliah','#fb923c','Kuliah',cK)
    + chip('booking','src-booking','#60a5fa','Booking',cB)
    + chip('eksternal','src-eksternal','#a855f7','Eksternal',cE)
    + `<select class="cal-fdept" onchange="calSetDept(this.value)">
        <option value="">🏫 Semua Penanggung Jawab</option>
        ${CAL_DEPTS.map(dd=>`<option value="${escJ(dd.value)}"${calDeptFilter===dd.value?' selected':''}>${escJ(dd.label)}</option>`).join('')}
      </select>`;
}
function calShift(delta){
  calM += delta;
  if (calM < 0)  { calM = 11; calY--; }
  if (calM > 11) { calM = 0;  calY++; }
  renderCalendar();
}
function calGoToday(){
  const t = new Date();
  calY = t.getFullYear(); calM = t.getMonth();
  renderCalendar();
  selectCalDay(todayYMD(), true);
}

function renderCalendar(){
  document.getElementById('cal-title').textContent = CAL_MONTHS[calM] + ' ' + calY;
  const today = todayYMD();
  const first = new Date(calY, calM, 1);
  const startOffset = (first.getDay() + 6) % 7;          // 0 = Senin
  const startDate = new Date(calY, calM, 1 - startOffset);

  let html = '';
  for (let i = 0; i < 42; i++) {
    const d  = new Date(startDate); d.setDate(startDate.getDate() + i);
    const ds = toYMD(d);
    const inMonth = d.getMonth() === calM;
    const ents = entriesOnDate(ds);
    const cnt  = ents.length;

    let cls = 'cal-cell';
    if (!inMonth)          cls += ' out';
    if (ds === today)      cls += ' today';
    if (ds === calSelected) cls += ' selected';

    let chips = '';
    ents.slice(0, 2).forEach(p => {
      const c = CAL_SRC[p.sumber] || {dot:'#94a3b8', bg:'#f1f5f9'};
      chips += `<div class="cal-chip" style="background:${c.bg}"><span class="cal-chip-dot" style="background:${c.dot}"></span>${escJ(p.ruangan_nama)}</div>`;
    });
    if (cnt > 2) chips += `<div class="cal-more">+${cnt-2} lainnya</div>`;

    html += `<div class="${cls}" onclick="selectCalDay('${ds}')">
      <div class="cal-cell-top">
        <span class="cal-cell-num">${d.getDate()}</span>
        ${cnt ? `<span class="cal-count">${cnt}</span>` : ''}
      </div>
      ${chips}
    </div>`;
  }
  document.getElementById('cal-grid').innerHTML = html;
}

function selectCalDay(ds, keepMonth){
  calSelected = ds;
  // Pastikan bulan yang tampil sesuai tanggal terpilih (jika klik dari luar bulan)
  const d = new Date(ds + 'T00:00:00');
  if (!keepMonth && (d.getMonth() !== calM || d.getFullYear() !== calY)) {
    calY = d.getFullYear(); calM = d.getMonth();
  }
  renderCalendar();

  renderCalFilter();   // filter bar di atas kalender (kotak merah)

  const dayEnts = entriesOnDate(ds);
  const isToday = ds === todayYMD();
  const dayLabel = `${CAL_DAYS[d.getDay()]}, ${fmtDate(ds)}`;

  // ── Terapkan filter sumber + penanggung jawab ──
  let ents = dayEnts.slice();
  if (calDeptFilter)  ents = ents.filter(p => p.departemen === calDeptFilter);
  if (calSrcFilter)   ents = ents.filter(p => p.sumber === calSrcFilter);

  let html = `<div class="cal-detail-title">📌 ${isToday ? 'Hari Ini — ' : ''}${dayLabel}
      <span style="margin-left:auto;font-size:12px;font-weight:600;color:#64748b">${ents.length} ruangan terpakai</span></div>`;

  if (!dayEnts.length) {
    html += `<div class="jm-empty"><div class="jm-empty-ic">✅</div>Tidak ada ruangan terpakai pada tanggal ini. Semua ruangan bebas!</div>`;
    document.getElementById('cal-detail').innerHTML = html;
    return;
  }

  if (!ents.length) {
    html += `<div class="jm-empty"><div class="jm-empty-ic">🔍</div>Tidak ada agenda yang cocok dengan filter.
      <div style="margin-top:10px"><button type="button" class="cal-fchip" onclick="calSrcFilter='';calDeptFilter='';selectCalDay(calSelected,true)">🔄 Reset Filter</button></div></div>`;
    document.getElementById('cal-detail').innerHTML = html;
    return;
  }

  ents.forEach(p => {
    const c = CAL_SRC[p.sumber] || {dot:'#94a3b8', label:p.sumber};
    let extra = '';
    if (p.sumber === 'kuliah') {
      extra = `<span class="jm-badge kuliah">📚 Perkuliahan</span>` +
              (p.prodi ? `<span class="jm-badge prodi">🎓 ${escJ(p.prodi)}</span>` : '') +
              (p.keterangan ? `<span class="jm-badge peserta">👤 ${escJ(p.keterangan)}</span>` : '');
    } else if (p.sumber === 'booking') {
      const stCls  = p.status === 'disetujui' ? 'disetujui' : 'pending';
      const stLbl  = p.status === 'disetujui' ? '✅ Disetujui' : '⏳ Menunggu';
      const cOrmawa = p.asal_organisasi && p.asal_organisasi !== '-' ? p.asal_organisasi : '';
      const cPemohon = p.user_nama ? (cOrmawa ? `${escJ(p.user_nama)} (${escJ(cOrmawa)})` : escJ(p.user_nama)) : '';
      extra = `<span class="jm-badge ${stCls}">${stLbl}</span>` +
              (cPemohon ? `<span class="jm-badge peserta">👤 ${cPemohon}</span>` : '') +
              (p.jumlah_peserta ? `<span class="jm-badge peserta">👥 ${p.jumlah_peserta} orang</span>` : '');
    } else {
      const jenisMap = {umum:'🏘️ Umum', instansi_luar:'🏢 Instansi Luar'};
      extra = `<span class="jm-badge eksternal">${jenisMap[p.jenis_pemohon] || '🌐 Eksternal'}</span>` +
              (p.nama_pemohon ? `<span class="jm-badge peserta">👤 ${escJ(p.nama_pemohon)}</span>` : '');
    }
    const isMulti  = (p.tanggal_mulai && p.tanggal_selesai && p.tanggal_mulai !== p.tanggal_selesai);
    const multiLbl = isMulti ? `<span class="jm-badge multiday">📆 ${fmtDate(p.tanggal_mulai)} s/d ${fmtDate(p.tanggal_selesai)}</span>` : '';
    html += `<div class="jm-item cal-detail-item" style="border-left:3px solid ${c.dot}">
      <div class="jm-item-icon">${p.tipe==='outdoor'?'🌿':'🏢'}</div>
      <div class="jm-item-body">
        <div class="jm-item-room">${escJ(p.ruangan_nama)}
          <span style="font-weight:400;color:#64748b;font-size:12px">— ${escJ(p.gedung)}</span>
          <span class="jm-src-label jm-src-${p.sumber}">${c.label}</span>
        </div>
        <div class="jm-item-kegiatan">📌 ${escJ(p.nama_kegiatan)}</div>
        <div class="jm-item-meta">
          <span class="jm-badge time">⏰ ${fmtJam(p.jam_mulai)}–${fmtJam(p.jam_selesai)}</span>
          ${multiLbl}
          <span class="jm-badge dept">🏫 ${escJ(p.departemen)}</span>
          ${extra}
        </div>
      </div>
    </div>`;
  });
  document.getElementById('cal-detail').innerHTML = html;
}
</script>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
