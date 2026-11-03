<?php
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$user = auth();
$BASE = base_url();

$role_colors = [
    'admin_utama'     => '#f59e0b',
    'admin_departemen'=> '#10b981',
    'admin_dmf'       => '#8b5cf6',
    'user'            => '#3b82f6',
];
$role_labels = [
    'admin_utama'     => 'Admin Utama',
    'admin_departemen'=> 'Admin ' . ($user['departemen'] ?? 'Departemen'),
    'admin_dmf'       => 'Admin DMF',
    'user'            => 'Mahasiswa',
];

/* ── Notifications ── */
$db = db();
$uid_h = (int)$user['id'];
$unread_count = (int)$db->query("SELECT COUNT(*) FROM notifikasi WHERE user_id=$uid_h AND dibaca=0")->fetch_row()[0];
$notifs = $db->query("SELECT * FROM notifikasi WHERE user_id=$uid_h ORDER BY created_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);

/* ── Pending badge for admin sidebar ── */
$pending_count = 0;
if (in_array($user['role'], ['admin_utama','admin_departemen','admin_dmf'])) {
    $whereP = "p.status='pending'";
    if ($user['role']==='admin_departemen') {
        $dept_he = $db->real_escape_string($user['departemen'] ?? '');
        $whereP .= " AND r.departemen='$dept_he'";
    } elseif ($user['role']==='admin_dmf') {
        $whereP .= " AND r.tipe='outdoor'";
    }
    $pending_count = (int)$db->query("SELECT COUNT(*) FROM peminjaman p JOIN ruangan r ON p.ruangan_id=r.id WHERE $whereP")->fetch_row()[0];
}

$nav = [];
if ($user['role'] === 'user') {
    $nav = [
        ['icon'=>'🏠','label'=>'Dashboard',          'href'=>base_url('dashboard.php'),         'id'=>'dashboard'],
        ['icon'=>'🤖','label'=>'Rekomendasi Ruangan', 'href'=>base_url('user/rekomendasi.php'),  'id'=>'rekomendasi'],
        ['icon'=>'📋','label'=>'Ajukan Peminjaman',   'href'=>base_url('user/booking.php'),      'id'=>'booking'],
        ['icon'=>'📁','label'=>'Peminjaman Saya',     'href'=>base_url('user/my-bookings.php'),  'id'=>'my-bookings'],
        ['icon'=>'🏢','label'=>'Daftar Ruangan',      'href'=>base_url('user/rooms.php'),        'id'=>'rooms'],
    ];
} elseif ($user['role'] === 'admin_utama') {
    $nav = [
        ['icon'=>'🏠','label'=>'Dashboard',            'href'=>base_url('dashboard.php'),              'id'=>'dashboard'],
        ['icon'=>'✅','label'=>'Kelola Peminjaman',     'href'=>base_url('admin/bookings.php'),         'id'=>'bookings'],
        ['icon'=>'📋','label'=>'Ajukan Peminjaman',     'href'=>base_url('admin/ajukan.php'),           'id'=>'ajukan'],
        ['icon'=>'🏛️','label'=>'Peminjaman Eksternal', 'href'=>base_url('admin/ext_bookings.php'),     'id'=>'ext_bookings'],
        ['icon'=>'📚','label'=>'Jadwal Kuliah',         'href'=>base_url('admin/class_schedule.php'),   'id'=>'class_schedule'],
        ['icon'=>'🏢','label'=>'Kelola Ruangan',        'href'=>base_url('admin/rooms.php'),            'id'=>'rooms'],
        ['icon'=>'👥','label'=>'Kelola Pengguna',       'href'=>base_url('admin/users.php'),            'id'=>'users'],
        ['icon'=>'📊','label'=>'Laporan',               'href'=>base_url('admin/reports.php'),          'id'=>'reports'],
        ['icon'=>'👁️','label'=>'Lihat Semua Ruangan',  'href'=>base_url('user/rooms.php'),             'id'=>'view-rooms'],
    ];
} elseif ($user['role'] === 'admin_departemen') {
    $nav = [
        ['icon'=>'🏠','label'=>'Dashboard',            'href'=>base_url('dashboard.php'),              'id'=>'dashboard'],
        ['icon'=>'✅','label'=>'Kelola Peminjaman',     'href'=>base_url('admin/bookings.php'),         'id'=>'bookings'],
        ['icon'=>'📋','label'=>'Ajukan Peminjaman',     'href'=>base_url('admin/ajukan.php'),           'id'=>'ajukan'],
        ['icon'=>'🏛️','label'=>'Peminjaman Eksternal', 'href'=>base_url('admin/ext_bookings.php'),     'id'=>'ext_bookings'],
        ['icon'=>'📚','label'=>'Jadwal Kuliah',         'href'=>base_url('admin/class_schedule.php'),   'id'=>'class_schedule'],
        ['icon'=>'🏢','label'=>'Kelola Ruangan',        'href'=>base_url('admin/rooms.php'),            'id'=>'rooms'],
        ['icon'=>'👥','label'=>'Kelola Pengguna',       'href'=>base_url('admin/users.php'),            'id'=>'users'],
        ['icon'=>'📊','label'=>'Laporan',               'href'=>base_url('admin/reports.php'),          'id'=>'reports'],
        ['icon'=>'👁️','label'=>'Lihat Semua Ruangan',  'href'=>base_url('user/rooms.php'),             'id'=>'view-rooms'],
    ];
} elseif ($user['role'] === 'admin_dmf') {
    $nav = [
        ['icon'=>'🏠','label'=>'Dashboard',            'href'=>base_url('dashboard.php'),              'id'=>'dashboard'],
        ['icon'=>'✅','label'=>'Kelola Peminjaman',     'href'=>base_url('admin/bookings.php'),         'id'=>'bookings'],
        ['icon'=>'📋','label'=>'Ajukan Peminjaman',     'href'=>base_url('admin/ajukan.php'),           'id'=>'ajukan'],
        ['icon'=>'🏛️','label'=>'Peminjaman Eksternal', 'href'=>base_url('admin/ext_bookings.php'),     'id'=>'ext_bookings'],
        ['icon'=>'🌿','label'=>'Kelola Area Outdoor',   'href'=>base_url('admin/rooms.php'),            'id'=>'rooms'],
        ['icon'=>'👥','label'=>'Kelola Pengguna',       'href'=>base_url('admin/users.php'),            'id'=>'users'],
        ['icon'=>'📊','label'=>'Laporan',               'href'=>base_url('admin/reports.php'),          'id'=>'reports'],
        ['icon'=>'👁️','label'=>'Lihat Semua Ruangan',  'href'=>base_url('user/rooms.php'),             'id'=>'view-rooms'],
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#052e16">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<script>
/* ── Proteksi CSRF sisi-klien ──────────────────────────────
   Otomatis menyertakan token ke setiap form POST & request fetch,
   sehingga tidak perlu menambah field token di tiap form manual. */
(function(){
  var meta=document.querySelector('meta[name=csrf-token]');
  var TOKEN=meta?meta.getAttribute('content'):'';
  // 1) Patch fetch → tambahkan header X-CSRF-Token untuk method non-GET
  var _fetch=window.fetch;
  window.fetch=function(input,init){
    init=init||{};
    var method=(init.method||(typeof input==='object'&&input&&input.method)||'GET').toUpperCase();
    if(method!=='GET'&&method!=='HEAD'){
      var h=new Headers(init.headers||(typeof input==='object'&&input&&input.headers)||{});
      if(!h.has('X-CSRF-Token'))h.set('X-CSRF-Token',TOKEN);
      init.headers=h;
    }
    return _fetch.call(this,input,init);
  };
  // Helper: pastikan form POST punya field token
  function ensureToken(f){
    if(!f||f.tagName!=='FORM')return;
    if((f.method||'get').toLowerCase()!=='post')return;
    if(!f.querySelector('input[name=csrf_token]')){
      var i=document.createElement('input');
      i.type='hidden';i.name='csrf_token';i.value=TOKEN;
      f.appendChild(i);
    }
  }
  // 2) Sisipkan token saat form di-submit lewat tombol/Enter
  document.addEventListener('submit',function(e){ ensureToken(e.target); },true);
  // 3) Patch form.submit() programatik — TIDAK memicu event 'submit',
  //    jadi harus dicegat langsung (mis. tombol hapus & filter status)
  var _nativeSubmit=HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit=function(){
    ensureToken(this);
    return _nativeSubmit.apply(this,arguments);
  };
})();
</script>
<title><?= e($page_title ?? 'Campus Facility Reservation') ?></title>
<link rel="icon" type="image/svg+xml" href="<?= base_url('assets/images/favicon.svg') ?>">
<link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>?v=<?= @filemtime(__DIR__.'/../assets/css/style.css') ?: '1' ?>">
<style>
/* ── Notification Bell ── */
.notif-wrap{position:relative;display:inline-flex;align-items:center}
.notif-bell{background:none;border:none;cursor:pointer;font-size:20px;padding:4px 6px;border-radius:8px;position:relative;line-height:1;transition:background .15s}
.notif-bell:hover{background:rgba(0,0,0,.06)}
.notif-badge{position:absolute;top:-2px;right:-2px;background:#dc2626;color:#fff;font-size:10px;font-weight:700;min-width:17px;height:17px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 3px;line-height:1;border:2px solid #fff}
.notif-dropdown{position:absolute;top:calc(100% + 8px);right:0;width:320px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.13);z-index:9999;overflow:hidden;display:none;max-height:420px;flex-direction:column}
.notif-dropdown.show{display:flex}
.notif-header{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #f1f5f9;font-weight:700;font-size:14px;flex-shrink:0}
.notif-mark-all{background:none;border:none;color:#3b82f6;font-size:12px;cursor:pointer;padding:3px 7px;border-radius:6px}
.notif-mark-all:hover{background:#eff6ff}
.notif-list{overflow-y:auto;flex:1}
.notif-empty{padding:32px 16px;text-align:center;color:#94a3b8;font-size:14px}
.notif-item{display:flex;gap:10px;padding:10px 14px;cursor:pointer;border-bottom:1px solid #f8fafc;transition:background .12s;align-items:flex-start}
.notif-item:last-child{border-bottom:none}
.notif-item:hover{background:#f8fafc}
.notif-item.unread{background:#eff6ff}
.notif-item.unread:hover{background:#dbeafe}
.notif-dot-wrap{flex-shrink:0;position:relative;margin-top:2px}
.notif-icon{font-size:18px;line-height:1}
.notif-unread-dot{position:absolute;top:-2px;right:-2px;width:8px;height:8px;background:#3b82f6;border-radius:50%;border:1.5px solid #fff}
.notif-content{flex:1;min-width:0}
.notif-title{font-size:13px;font-weight:600;color:#1e293b;line-height:1.3}
.notif-msg{font-size:12px;color:#64748b;margin-top:2px;line-height:1.4;word-break:break-word}
.notif-time{font-size:11px;color:#94a3b8;margin-top:4px}
/* ── Sidebar pending badge ── */
.badge-nav{background:#dc2626;color:#fff;font-size:10px;font-weight:700;min-width:18px;height:18px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;padding:0 4px;margin-left:auto;line-height:1}
/* ── Notif dropdown: responsif di layar kecil agar tidak terpotong ── */
@media(max-width:420px){
  .notif-dropdown{width:calc(100vw - 20px);max-width:340px;right:-6px}
}
</style>
</head>
<body>
<div class="layout">
<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="sidebar-logo-icon" style="background:linear-gradient(135deg,#22c55e,#15803d);border-radius:11px">
      <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" width="40" height="40">
        <circle cx="50" cy="50" r="48" fill="transparent" stroke="rgba(255,255,255,0.30)" stroke-width="2"/>
        <ellipse cx="50" cy="72" rx="21" ry="24" fill="#ffffff"/>
        <circle cx="50" cy="40" r="19" fill="#ffffff"/>
        <polygon points="33,24 28,12 40,22" fill="#ffffff"/>
        <polygon points="67,24 72,12 60,22" fill="#ffffff"/>
        <circle cx="41" cy="38" r="9" fill="#16a34a"/>
        <circle cx="59" cy="38" r="9" fill="#16a34a"/>
        <circle cx="41" cy="38" r="5.5" fill="#fbbf24"/>
        <circle cx="59" cy="38" r="5.5" fill="#fbbf24"/>
        <circle cx="41" cy="38" r="2.8" fill="#0c1a0e"/>
        <circle cx="59" cy="38" r="2.8" fill="#0c1a0e"/>
        <circle cx="39.5" cy="36.5" r="1.2" fill="white"/>
        <circle cx="57.5" cy="36.5" r="1.2" fill="white"/>
        <polygon points="50,46 44,53 56,53" fill="#d97706"/>
        <ellipse cx="33" cy="72" rx="10" ry="18" fill="rgba(255,255,255,0.72)" transform="rotate(-10,33,72)"/>
        <ellipse cx="67" cy="72" rx="10" ry="18" fill="rgba(255,255,255,0.72)" transform="rotate(10,67,72)"/>
      </svg>
    </div>
    <div class="sidebar-logo-text">
      <div class="sidebar-logo-title">Campus Facility Reservation</div>
      <div class="sidebar-logo-sub">Universitas Contoh</div>
    </div>
  </div>
  <div class="sidebar-user">
    <div class="sidebar-avatar" style="background:<?= $role_colors[$user['role']] ?>">
      <?= strtoupper(mb_substr($user['nama'], 0, 1)) ?>
    </div>
    <div class="sidebar-user-info">
      <div class="name"><?= e(mb_strimwidth($user['nama'], 0, 22, '…')) ?></div>
      <div class="role" style="color:<?= $role_colors[$user['role']] ?>"><?= $role_labels[$user['role']] ?></div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <div class="sidebar-section-label">Menu</div>
    <?php foreach ($nav as $item):
      $active = (basename($item['href']) === basename($_SERVER['PHP_SELF'])) ? 'active' : '';
    ?>
    <a href="<?= $item['href'] ?>" class="nav-item <?= $active ?>">
      <span class="nav-icon"><?= $item['icon'] ?></span>
      <span><?= $item['label'] ?></span>
      <?php if ($item['id'] === 'bookings' && $pending_count > 0): ?>
        <span class="badge-nav"><?= $pending_count > 99 ? '99+' : $pending_count ?></span>
      <?php endif; ?>
      <?php if ($active): ?><span class="dot"></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <form method="post" action="<?= base_url('logout.php') ?>">
      <button type="submit" class="btn-logout">
        <span>🚪</span><span>Keluar</span>
      </button>
    </form>
  </div>
</aside>
<!-- SIDEBAR OVERLAY (mobile) -->
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<!-- MAIN CONTENT -->
<div class="main-content">
<div class="topbar">
  <button class="sidebar-toggle" id="sidebar-toggle" onclick="toggleSidebar()" aria-label="Buka menu" aria-controls="sidebar"><span></span><span></span><span></span></button>
  <div class="topbar-title"><?= e($page_title ?? 'Dashboard') ?></div>
  <div style="display:flex;align-items:center;gap:14px">
    <div class="topbar-breadcrumb" style="margin:0">Fakultas Sains &middot; <?= date('d-m-Y') ?></div>
    <!-- ── Notification Bell ── -->
    <div class="notif-wrap" id="notif-wrap">
      <button class="notif-bell" onclick="toggleNotif(event)" title="Notifikasi">
        🔔<?php if ($unread_count > 0): ?><span class="notif-badge"><?= $unread_count > 99 ? '99+' : $unread_count ?></span><?php endif; ?>
      </button>
      <div class="notif-dropdown" id="notif-dropdown">
        <div class="notif-header">
          <span>🔔 Notifikasi</span>
          <?php if ($unread_count > 0): ?>
          <button class="notif-mark-all" id="notif-mark-all-btn" onclick="markAllRead()">Tandai semua dibaca</button>
          <?php endif; ?>
        </div>
        <div class="notif-list" id="notif-list">
          <?php if (empty($notifs)): ?>
          <div class="notif-empty">🔕 Belum ada notifikasi</div>
          <?php else: ?>
          <?php
          $notifIcons = ['info'=>'ℹ️','success'=>'✅','warning'=>'⚠️','danger'=>'❌'];
          foreach ($notifs as $notif):
            $nIcon = $notifIcons[$notif['tipe']] ?? 'ℹ️';
            $nLink = $notif['link'] ? htmlspecialchars($notif['link'], ENT_QUOTES, 'UTF-8') : '';
          ?>
          <div class="notif-item <?= $notif['dibaca'] ? '' : 'unread' ?>"
               id="notif-item-<?= $notif['id'] ?>"
               onclick="clickNotif(<?= $notif['id'] ?>,'<?= $nLink ?>')">
            <div class="notif-dot-wrap">
              <span class="notif-icon"><?= $nIcon ?></span>
              <?php if (!$notif['dibaca']): ?><span class="notif-unread-dot"></span><?php endif; ?>
            </div>
            <div class="notif-content">
              <div class="notif-title"><?= e($notif['judul']) ?></div>
              <?php if ($notif['pesan']): ?><div class="notif-msg"><?= e(mb_strimwidth($notif['pesan'], 0, 80, '…')) ?></div><?php endif; ?>
              <div class="notif-time"><?= timeAgo($notif['created_at']) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <!-- ── /Notification Bell ── -->
  </div>
</div>
<div class="page-body">
<?php
$msg_success = flash('success');
$msg_error   = flash('error');
if ($msg_success) echo "<div class='alert alert-success'>✅ " . e($msg_success) . "</div>";
if ($msg_error)   echo "<div class='alert alert-danger'>⚠️ " . e($msg_error) . "</div>";
?>
<script>
(function(){
  var _notifUnread = <?= (int)$unread_count ?>;
  var _markReadUrl = '<?= base_url('notif_mark_read.php') ?>';

  window.toggleNotif = function(e){
    e.stopPropagation();
    var dd = document.getElementById('notif-dropdown');
    dd.classList.toggle('show');
  };

  document.addEventListener('click', function(e){
    var wrap = document.getElementById('notif-wrap');
    if(wrap && !wrap.contains(e.target)){
      var dd = document.getElementById('notif-dropdown');
      if(dd) dd.classList.remove('show');
    }
  });

  window.clickNotif = function(id, link){
    // Mark as read visually
    var el = document.getElementById('notif-item-' + id);
    if(el){
      el.classList.remove('unread');
      var dot = el.querySelector('.notif-unread-dot');
      if(dot) dot.remove();
    }
    // Update badge count
    if(_notifUnread > 0){
      _notifUnread--;
      var badge = document.querySelector('.notif-bell .notif-badge');
      if(_notifUnread === 0){ if(badge) badge.remove(); }
      else if(badge){ badge.textContent = _notifUnread > 99 ? '99+' : _notifUnread; }
    }
    // POST mark read
    fetch(_markReadUrl, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'id='+id
    });
    // Navigate
    if(link){ window.location.href = link; }
    else {
      var dd = document.getElementById('notif-dropdown');
      if(dd) dd.classList.remove('show');
    }
  };

  window.markAllRead = function(){
    fetch(_markReadUrl, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'id=0'
    }).then(function(){
      document.querySelectorAll('.notif-item.unread').forEach(function(el){
        el.classList.remove('unread');
        var dot = el.querySelector('.notif-unread-dot');
        if(dot) dot.remove();
      });
      var badge = document.querySelector('.notif-bell .notif-badge');
      if(badge) badge.remove();
      var btn = document.getElementById('notif-mark-all-btn');
      if(btn) btn.remove();
      _notifUnread = 0;
    });
  };
})();

// ── Responsive Sidebar Toggle ──
(function(){
  var sb=document.getElementById('sidebar'),ov=document.getElementById('sidebar-overlay'),tg=document.getElementById('sidebar-toggle');
  if(!sb||!ov||!tg) return;
  var BREAK=992;
  // Mobile: drawer open/close
  function openSb(){sb.classList.add('open');ov.classList.add('show');tg.classList.add('active');document.body.style.overflow='hidden';}
  function closeSb(){sb.classList.remove('open');ov.classList.remove('show');tg.classList.remove('active');document.body.style.overflow='';}
  // Desktop: collapse/expand
  function collapseSb(){sb.classList.add('collapsed');}
  function expandSb(){sb.classList.remove('collapsed');}
  window.toggleSidebar=function(){
    if(window.innerWidth>BREAK){
      // Desktop: toggle width collapse
      sb.classList.contains('collapsed')?expandSb():collapseSb();
    } else {
      // Mobile: drawer
      sb.classList.contains('open')?closeSb():openSb();
    }
  };
  ov.addEventListener('click',closeSb);
  document.querySelectorAll('.sidebar-nav .nav-item').forEach(function(a){
    a.addEventListener('click',function(){if(window.innerWidth<=BREAK)closeSb();});
  });
  document.addEventListener('keydown',function(e){
    if(e.key==='Escape'){window.innerWidth>BREAK?expandSb():closeSb();}
  });
  window.addEventListener('resize',function(){
    if(window.innerWidth>BREAK){
      closeSb();          // clear mobile open state
    } else {
      expandSb();         // clear desktop collapsed state when going mobile
    }
  });
})();
</script>
