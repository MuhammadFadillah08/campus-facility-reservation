<?php
// ============================================================
//  KONFIGURASI
//  Salin includes/config.local.example.php menjadi includes/config.local.php
//  lalu isi kredensial Anda (file itu diabaikan git). Tanpa file tersebut,
//  dipakai bawaan XAMPP lokal (root, tanpa password).
// ============================================================
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_NAME')) define('DB_NAME', 'campus_facility_reservation');
if (!defined('DB_PORT')) define('DB_PORT', 3306);

define('SITE_NAME', 'Campus Facility Reservation');

// Base URL otomatis — bekerja di XAMPP (localhost/campus-facility-reservation) maupun hosting root
function base_url(string $path = ''): string {
    static $base = null;
    if ($base === null) {
        $host = $_SERVER['SERVER_NAME'] ?? '';
        if ($host === 'localhost' || $host === '127.0.0.1') {
            // XAMPP lokal: project ada di subfolder (misal /campus-facility-reservation)
            $doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
            $file_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME']));
            $rel      = str_replace($doc_root, '', $file_dir);
            $parts    = array_values(array_filter(explode('/', $rel)));
            $base     = !empty($parts) ? '/' . $parts[0] : '';
        } else {
            // Hosting (InfinityFree, dll): project ada di root domain
            $base = '';
        }
    }
    $path = ltrim($path, '/');
    return $base . ($path ? '/' . $path : '');
}

// Path filesystem untuk upload
define('UPLOAD_RUANGAN', __DIR__ . '/../uploads/ruangan/');
define('UPLOAD_SURAT',   __DIR__ . '/../uploads/surat/');
define('MAX_FILE_SIZE',  5 * 1024 * 1024);

// "Ingat saya" — login tersimpan otomatis sampai user menekan Keluar
define('REMEMBER_COOKIE', 'cfr_remember');
define('REMEMBER_DAYS',   30);

function db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        if ($conn->connect_error) {
            die('<div style="font-family:sans-serif;padding:30px;background:#fee2e2;color:#991b1b;border-radius:10px;margin:20px;border:1px solid #fca5a5">
                <h3 style="margin:0 0 10px">❌ Koneksi Database Gagal</h3>
                <p><strong>Error:</strong> ' . htmlspecialchars($conn->connect_error) . '</p>
                <hr style="border-color:#fca5a5;margin:12px 0">
                <p>Pastikan:</p>
                <ol>
                  <li>XAMPP sudah dijalankan dan <strong>Apache + MySQL</strong> aktif (hijau)</li>
                  <li>Database <code>campus_facility_reservation</code> sudah dibuat di phpMyAdmin</li>
                  <li>File SQL sudah diimport dari folder <code>database/campus_facility_reservation.sql</code></li>
                  <li>Konfigurasi di <code>includes/config.php</code> sudah benar</li>
                </ol>
            </div>');
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
tryRememberLogin();   // pulihkan login otomatis dari cookie bila sesi kosong
csrf_verify();        // tolak request POST tanpa token CSRF yang valid

/* Cegah halaman dinamis tersimpan di cache browser → hindari data usang
   saat tombol Back / revisit (mis. data yang sudah dihapus masih tampil). */
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function auth(): ?array {
    return $_SESSION['user'] ?? null;
}

function requireLogin(): array {
    $user = auth();
    if (!$user) {
        header('Location: ' . base_url('login.php'));
        exit;
    }
    return $user;
}

function requireRole(string ...$roles): array {
    $user = requireLogin();
    if (!in_array($user['role'], $roles)) {
        header('Location: ' . base_url('dashboard.php'));
        exit;
    }
    return $user;
}

function canValidate(array $user, array $ruangan): bool {
    if ($user['role'] === 'admin_utama') return true;
    if ($user['role'] === 'admin_departemen' && $ruangan['departemen'] === $user['departemen']) return true;
    if ($user['role'] === 'admin_dmf' && $ruangan['tipe'] === 'outdoor') return true;
    return false;
}

function e(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function flash(string $key, string $msg = ''): string {
    if ($msg) { $_SESSION['flash'][$key] = $msg; return ''; }
    $v = $_SESSION['flash'][$key] ?? '';
    unset($_SESSION['flash'][$key]);
    return $v;
}

function redirect(string $url): void {
    header("Location: $url"); exit;
}

/* ============================================================
   Proteksi CSRF (Cross-Site Request Forgery)
   Token disimpan di sesi; setiap form POST & request fetch harus
   menyertakannya (lewat field tersembunyi atau header X-CSRF-Token).
   ============================================================ */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
/** Field tersembunyi untuk disisipkan di dalam <form>. */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}
/** Verifikasi token pada setiap request POST; tolak bila tidak valid. */
function csrf_verify(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $real = $_SESSION['csrf_token'] ?? '';
    if ($real !== '' && is_string($sent) && $sent !== '' && hash_equals($real, $sent)) {
        return; // valid
    }
    http_response_code(419);
    $isAjax = !empty($_SERVER['HTTP_X_CSRF_TOKEN'])
           || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
           || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if ($isAjax) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'msg'=>'Sesi keamanan kedaluwarsa. Muat ulang halaman lalu coba lagi.']);
    } else {
        echo '<!doctype html><meta charset="utf-8"><div style="font-family:system-ui,sans-serif;max-width:460px;margin:60px auto;padding:24px;background:#fef2f2;border:1px solid #fca5a5;border-radius:12px;color:#991b1b">'
           . '<h2 style="margin:0 0 8px">⚠️ Sesi Keamanan Kedaluwarsa</h2>'
           . '<p style="line-height:1.6">Permintaan ditolak demi keamanan (proteksi CSRF). Silakan kembali, muat ulang halaman, lalu coba lagi.</p>'
           . '<a href="javascript:history.back()" style="color:#991b1b;font-weight:700;text-decoration:none">← Kembali</a></div>';
    }
    exit;
}

/* ============================================================
   Validasi upload file berdasarkan MIME asli (bukan hanya ekstensi).
   Mencegah file berbahaya (mis. skrip .php) yang disamarkan dengan
   ekstensi gambar/dokumen. Mengembalikan '' bila valid, atau pesan error.
   ============================================================ */
function validateUpload(array $file, array $allowedExt): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'File gagal diupload.';
    }
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return 'Format file tidak didukung (' . e($ext) . ').';
    }
    if (($file['size'] ?? 0) > MAX_FILE_SIZE) {
        return 'Ukuran file melebihi batas maksimal (5MB).';
    }
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        return 'File upload tidak valid.';
    }
    // MIME asli yang diizinkan per ekstensi. Office (doc/docx) sengaja longgar
    // karena finfo sering mendeteksinya sebagai zip/ole/octet-stream.
    $mimeMap = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword','application/x-msword','application/x-ole-storage','application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip','application/octet-stream'],
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
    if ($finfo) finfo_close($finfo);
    $allowedMimes = $mimeMap[$ext] ?? [];
    if ($mime && $allowedMimes && !in_array($mime, $allowedMimes, true)) {
        return 'Isi file tidak cocok dengan ekstensinya (' . e($mime) . '). Upload ditolak demi keamanan.';
    }
    return '';
}

/* ============================================================
   "Ingat Saya" — login persisten via cookie + token di DB
   ============================================================ */
function ensureRememberColumns(mysqli $db): void {
    ensureColumn($db, 'users', 'remember_token',   "VARCHAR(64) DEFAULT NULL");
    ensureColumn($db, 'users', 'remember_expires', "DATETIME DEFAULT NULL");
}
function rememberCookieSecure(): bool {
    return (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');
}
/** Buat & simpan token "ingat saya" + kirim cookie 30 hari. */
function setRememberCookie(array $user): void {
    if (empty($user['id'])) return;
    $db = db();
    ensureRememberColumns($db);
    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);
    $exp   = date('Y-m-d H:i:s', time() + REMEMBER_DAYS * 86400);
    $uid   = (int)$user['id'];
    $s = $db->prepare("UPDATE users SET remember_token=?, remember_expires=? WHERE id=?");
    $s->bind_param('ssi', $hash, $exp, $uid); $s->execute();
    setcookie(REMEMBER_COOKIE, $uid . ':' . $token, [
        'expires'  => time() + REMEMBER_DAYS * 86400,
        'path'     => '/',
        'secure'   => rememberCookieSecure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[REMEMBER_COOKIE] = $uid . ':' . $token;
}
/** Hapus token di DB + hapus cookie (dipakai saat logout). */
function clearRememberCookie(): void {
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        $uid = (int)explode(':', $_COOKIE[REMEMBER_COOKIE], 2)[0];
        if ($uid) {
            $db = db();
            ensureRememberColumns($db);
            $db->query("UPDATE users SET remember_token=NULL, remember_expires=NULL WHERE id=$uid");
        }
    }
    setcookie(REMEMBER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => rememberCookieSecure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[REMEMBER_COOKIE]);
}
/** Pulihkan sesi dari cookie bila valid (dipanggil tiap request). */
function tryRememberLogin(): void {
    if (isset($_SESSION['user'])) return;            // sudah login
    if (empty($_COOKIE[REMEMBER_COOKIE])) return;    // tak ada cookie
    $parts = explode(':', $_COOKIE[REMEMBER_COOKIE], 2);
    if (count($parts) !== 2) return;
    $uid = (int)$parts[0]; $token = $parts[1];
    if (!$uid || $token === '') return;
    $db = db();
    ensureRememberColumns($db);
    $s = $db->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $s->bind_param('i', $uid); $s->execute();
    $user = $s->get_result()->fetch_assoc();
    if (!$user || empty($user['remember_token']) || empty($user['remember_expires'])) return;
    if (strtotime($user['remember_expires']) < time()) return;                 // kedaluwarsa
    if (!hash_equals($user['remember_token'], hash('sha256', $token))) return;  // token salah
    // Valid → pulihkan sesi & putar token (perpanjang masa berlaku)
    $_SESSION['user'] = $user;
    setRememberCookie($user);
}

/**
 * Pastikan sebuah kolom ada (idempotent, portable MySQL/MariaDB).
 * Dipakai untuk migrasi ringan tanpa file SQL terpisah.
 */
function ensureColumn(mysqli $db, string $table, string $column, string $definition): void {
    $t = $db->real_escape_string($table);
    $c = $db->real_escape_string($column);
    $exists = (int)($db->query(
        "SELECT COUNT(*) c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c'"
    )->fetch_assoc()['c'] ?? 0);
    if (!$exists) {
        $db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

/**
 * Pastikan kolom VARCHAR minimal selebar $minLen — perlebar bila kolom yang ada
 * lebih sempit. Idempotent. Dipakai untuk migrasi ringan (mis. no_wa 20→50).
 */
function ensureColumnWidth(mysqli $db, string $table, string $column, int $minLen, string $definition): void {
    $t = $db->real_escape_string($table);
    $c = $db->real_escape_string($column);
    $row = $db->query(
        "SELECT CHARACTER_MAXIMUM_LENGTH len FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c'"
    )->fetch_assoc();
    if ($row && $row['len'] !== null && (int)$row['len'] < $minLen) {
        $db->query("ALTER TABLE `$table` MODIFY COLUMN `$column` $definition");
    }
}

/**
 * Tabel key-value sederhana untuk menyimpan pengaturan aplikasi (idempotent).
 */
function ensureSettingsTable(mysqli $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS app_settings (
        k VARCHAR(64) PRIMARY KEY,
        v TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function getSetting(string $key, ?string $default = null): ?string {
    $db = db();
    ensureSettingsTable($db);
    $k = $db->real_escape_string($key);
    $r = $db->query("SELECT v FROM app_settings WHERE k='$k' LIMIT 1");
    if ($r && ($row = $r->fetch_assoc())) return $row['v'];
    return $default;
}
function setSetting(string $key, string $value): void {
    $db = db();
    ensureSettingsTable($db);
    $s = $db->prepare("INSERT INTO app_settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
    $s->bind_param('ss', $key, $value);
    $s->execute();
}

/**
 * Kategori organisasi → ikon, label & warna (dipakai combobox asal organisasi).
 */
function dmfCatDefault(): array {
    return [
        'umum'    => ['icon'=>'👤', 'label'=>'Umum / Pribadi',                'color'=>'#f1f5f9'],
        'lembaga' => ['icon'=>'🏛️', 'label'=>'Lembaga Fakultas',              'color'=>'#dbeafe'],
        'hmd'     => ['icon'=>'🎓', 'label'=>'Himpunan Mahasiswa Departemen', 'color'=>'#ede9fe'],
        'hmps'    => ['icon'=>'📚', 'label'=>'Himpunan Mahasiswa Prodi',      'color'=>'#fce7f3'],
        'ukm'     => ['icon'=>'✨', 'label'=>'Sub Divisi BEM Fakultas Sains',          'color'=>'#fef3c7'],
    ];
}

/** Palet warna untuk kategori baru (dipilih bergiliran). */
function dmfCatPalette(): array {
    return ['#dbeafe','#ede9fe','#fce7f3','#fef3c7','#dcfce7','#ffe4e6','#cffafe','#fae8ff','#fef9c3','#e0e7ff'];
}

/**
 * Daftar kategori organisasi — dibaca dari DB (dapat ditambah/hapus admin DMF);
 * fallback ke default. 'umum' selalu dijamin ada (kategori khusus untuk "-").
 * @return array<string,array{icon:string,label:string,color:string}>
 */
function dmfCatMeta(): array {
    $json = getSetting('dmf_cat_list');
    if ($json) {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $key => $c) {
                if (!is_array($c)) continue;
                $key = trim((string)$key);
                if ($key === '') continue;
                $out[$key] = [
                    'icon'  => (string)($c['icon'] ?? '🏷️'),
                    'label' => trim((string)($c['label'] ?? $key)),
                    'color' => (string)($c['color'] ?? '#f1f5f9'),
                ];
            }
            if (!isset($out['umum'])) $out = ['umum'=>dmfCatDefault()['umum']] + $out;
            if ($out) return $out;
        }
    }
    return dmfCatDefault();
}

/** Daftar default organisasi (dipakai bila admin belum menambah via web). */
function dmfOrmawaDefault(): array {
    return [
        ['name'=>'BEM Fakultas Sains',         'cat'=>'lembaga'],
        ['name'=>'DMF',          'cat'=>'lembaga'],
        ['name'=>'HMD MATEMATIKA',    'cat'=>'hmd'],
        ['name'=>'HMD BIOLOGI',       'cat'=>'hmd'],
        ['name'=>'HMD KIMIA',         'cat'=>'hmd'],
        ['name'=>'HMD FISIKA',        'cat'=>'hmd'],
        ['name'=>'HMD PIPA',          'cat'=>'hmd'],
        ['name'=>'HMPS BIOTEKNOLOGI', 'cat'=>'hmps'],
        ['name'=>'HMPS GIZI',         'cat'=>'hmps'],
        ['name'=>'UKM RISET',            'cat'=>'ukm'],
        ['name'=>'UKM LINGKUNGAN',                'cat'=>'ukm'],
        ['name'=>'UKM OLAHRAGA',              'cat'=>'ukm'],
        ['name'=>'UKM SENI',           'cat'=>'ukm'],
        ['name'=>'UKM TEKNOLOGI',              'cat'=>'ukm'],
        ['name'=>'UKM BAHASA',             'cat'=>'ukm'],
    ];
}

/**
 * Daftar organisasi (master) berikut kategorinya — dibaca dari DB, dapat
 * ditambah/dihapus admin DMF via web; fallback ke default.
 * @return array<int,array{name:string,cat:string}>
 */
function dmfOrmawaList(): array {
    $json = getSetting('dmf_ormawa_list');
    if ($json) {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $validCats = array_keys(dmfCatMeta());
            // Kategori cadangan = kategori non-umum pertama yang tersedia
            $fallbackCat = '';
            foreach ($validCats as $vc) { if ($vc !== 'umum') { $fallbackCat = $vc; break; } }
            $out = [];
            foreach ($decoded as $item) {
                if (!is_array($item)) continue;
                $name = trim((string)($item['name'] ?? ''));
                $cat  = (string)($item['cat'] ?? '');
                if ($name === '' || $name === '-') continue;
                if (!in_array($cat, $validCats, true) || $cat === 'umum') $cat = $fallbackCat ?: 'ukm';
                $out[] = ['name'=>$name, 'cat'=>$cat];
            }
            if ($out) return $out;
        }
    }
    return dmfOrmawaDefault();
}

/**
 * Daftar lengkap (master) nama seluruh ormawa DMF — sumber tunggal kebenaran.
 * Dipakai untuk membangun & memvalidasi peta grup, tidak terpengaruh assignment.
 */
function dmfAllOrmawa(): array {
    return array_map(fn($o)=>$o['name'], dmfOrmawaList());
}

/** Peta default 4 grup (dipakai bila admin belum menyetel via web). */
function dmfGroupDefault(): array {
    return [
        1 => ['HMPS GIZI', 'UKM TEKNOLOGI', 'HMD KIMIA'],
        2 => ['UKM SENI', 'HMD BIOLOGI', 'BEM Fakultas Sains', 'UKM RISET'],
        3 => ['HMD PIPA', 'UKM BAHASA', 'HMD MATEMATIKA', 'UKM OLAHRAGA'],
        4 => ['DMF', 'UKM LINGKUNGAN', 'HMPS BIOTEKNOLOGI', 'HMD FISIKA'],
    ];
}

/**
 * Peta 4 Grup WhatsApp DMF → daftar ormawa anggotanya (1-based).
 * Dibaca dari pengaturan DB (dapat diubah admin via web); fallback ke default.
 * Konfirmasi peminjaman outdoor diarahkan ke grup sesuai asal ormawa pemohon.
 */
function dmfGroupMap(): array {
    $json = getSetting('dmf_group_map');
    if ($json) {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $master = dmfAllOrmawa();
            $map = [1 => [], 2 => [], 3 => [], 4 => []];
            foreach ($decoded as $gi => $list) {
                $gi = (int)$gi;
                if ($gi >= 1 && $gi <= 4 && is_array($list)) {
                    foreach ($list as $name) {
                        $name = trim((string)$name);
                        // Hanya simpan ormawa yang masih terdaftar (buang yang sudah dihapus)
                        if ($name !== '' && in_array($name, $master, true)) $map[$gi][] = $name;
                    }
                }
            }
            // Ormawa baru yang belum ter-assign → masukkan ke Grup 1 agar tak hilang
            $assigned = array_merge(...array_values($map));
            foreach ($master as $o) {
                if (!in_array($o, $assigned, true)) $map[1][] = $o;
            }
            return $map;
        }
    }
    return dmfGroupDefault();
}

/**
 * Tentukan indeks grup (1..4) dari asal ormawa. Pencocokan case-insensitive.
 * "Tidak ada / Pribadi" ('-'/kosong) maupun organisasi manual di luar daftar
 * otomatis diarahkan ke GRUP 1.
 */
function dmfGroupIndexForOrmawa(string $ormawa): int {
    $o = strtoupper(trim($ormawa));
    if ($o === '' || $o === '-') return 1;   // pribadi / tidak ada → Grup 1
    foreach (dmfGroupMap() as $idx => $list) {
        foreach ($list as $name) {
            if (strtoupper($name) === $o) return $idx;
        }
    }
    return 1;                                 // tidak terdaftar / ketik manual → Grup 1
}

/**
 * Daftar ruangan OUTDOOR yang tetap WAJIB melampirkan surat (seperti indoor).
 * Cocokkan berdasarkan nama ruangan (case-insensitive).
 */
function outdoorWajibSurat(): array {
    return ['Kanopi Taman Sains', 'Kantin Fakultas Sains', 'Loket Fakultas Sains', 'Parkiran Fakultas Sains'];
}
/** True bila ruangan wajib lampiran surat: semua indoor + outdoor tertentu di atas. */
function roomWajibSurat(string $tipe, string $nama): bool {
    if ($tipe !== 'outdoor') return true;     // indoor selalu wajib
    $n = strtolower(trim($nama));
    foreach (outdoorWajibSurat() as $o) {
        if (strtolower(trim($o)) === $n) return true;
    }
    return false;
}

/**
 * Normalisasi nomor WhatsApp ke format internasional (62...) untuk wa.me.
 * 08xxxx → 628xxxx, 8xxxx → 628xxxx, +62/62 tetap. Kosong jika tidak valid.
 */
function waNormalize(string $no): string {
    $d = preg_replace('/\D+/', '', $no);
    if ($d === '') return '';
    if (str_starts_with($d, '0'))       $d = '62' . substr($d, 1);
    elseif (str_starts_with($d, '62'))  { /* sudah benar */ }
    elseif (str_starts_with($d, '8'))   $d = '62' . $d;
    return $d;
}

/**
 * Push a notification to a user.
 */
function pushNotif(mysqli $db, int $user_id, string $judul, string $pesan, string $tipe = 'info', string $link = ''): void {
    $s = $db->prepare("INSERT INTO notifikasi (user_id, judul, pesan, tipe, link) VALUES (?, ?, ?, ?, ?)");
    $s->bind_param('issss', $user_id, $judul, $pesan, $tipe, $link);
    $s->execute();
}

/**
 * Human-readable time ago in Indonesian.
 */
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)      return 'baru saja';
    if ($diff < 3600)    return floor($diff / 60) . ' menit lalu';
    if ($diff < 86400)   return floor($diff / 3600) . ' jam lalu';
    if ($diff < 604800)  return floor($diff / 86400) . ' hari lalu';
    return date('d-m-Y', strtotime($datetime));
}

function statusLabel(string $s): string {
    return match($s) {
        'pending'               => '<span class="badge badge-warning">⏳ Menunggu</span>',
        'disetujui'             => '<span class="badge badge-success">✅ Disetujui</span>',
        'ditolak','dibatalkan'  => '<span class="badge badge-danger">❌ Ditolak</span>',
        'selesai'               => '<span class="badge badge-secondary">🏁 Selesai</span>',
        default                 => '<span class="badge">' . e($s) . '</span>',
    };
}
