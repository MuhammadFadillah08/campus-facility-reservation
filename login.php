<?php
require_once __DIR__ . '/includes/config.php';
if (auth()) redirect(base_url('dashboard.php'));

$error = '';

/* ── Buat tabel OTP jika belum ada ── */
db()->query("CREATE TABLE IF NOT EXISTS `otp_codes` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `email`      VARCHAR(150) NOT NULL,
    `otp_code`   VARCHAR(6)   NOT NULL,
    `expires_at` DATETIME     NOT NULL,
    `used`       TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── POST Handler ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email']   ?? ''));
    $password = $_POST['password'] ?? '';

    if (!$email) {
        $error = 'Email wajib diisi.';

    } elseif (str_ends_with($email, '@students.university.example') || str_ends_with($email, '@faculty.university.example')) {
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $db  = db();
        $s   = $db->prepare("DELETE FROM otp_codes WHERE email = ?");
        $s->bind_param('s', $email); $s->execute();
        $s   = $db->prepare("INSERT INTO otp_codes(email, otp_code, expires_at) VALUES(?, ?, NOW() + INTERVAL 10 MINUTE)");
        $s->bind_param('ss', $email, $otp); $s->execute();
        $_SESSION['otp_email']   = $email;
        $_SESSION['otp_sent_at'] = time();
        require_once __DIR__ . '/includes/mailer.php';
        $mail_result = sendOtpEmail($email, $otp);
        if (!$mail_result['ok']) {
            $del = db()->prepare("DELETE FROM otp_codes WHERE email=?");
            $del->bind_param('s', $email); $del->execute();
            unset($_SESSION['otp_email'], $_SESSION['otp_sent_at']);
            $error = 'Gagal mengirim kode OTP. Periksa konfigurasi atau coba beberapa saat lagi.';
        } else {
            redirect(base_url('user/verify_otp.php'));
        }

    } elseif (str_ends_with($email, '@university.example')) {
        if (!$password) {
            $error = 'Password wajib diisi untuk akun admin.';
        } else {
            $stmt = db()->prepare("SELECT * FROM users WHERE email = ? AND role != 'user' LIMIT 1");
            $stmt->bind_param('s', $email); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && password_verify($password, $row['password'])) {
                $_SESSION['user'] = $row;
                setRememberCookie($row);
                redirect(base_url('dashboard.php'));
            } else {
                $error = 'Email atau password admin salah.';
            }
        }

    } else {
        $error = 'Gunakan email @students.university.example atau @faculty.university.example (pengguna) atau @university.example (admin).';
    }
}

// Background photos — support jpg, jpeg, webp, png
function findImg(string $dir, string $base, string $urlBase): string {
    foreach (['jpg','jpeg','webp','png'] as $ext) {
        if (file_exists($dir.$base.'.'.$ext)) return $urlBase.$base.'.'.$ext;
    }
    return '';
}
$imgDir = __DIR__ . '/assets/images/';
$urlDir = base_url('assets/images/');
$bgImgs = [];
foreach (['campus-login-bg','campus-hero','campus-gallery-1','campus-gallery-2'] as $base) {
    $f = findImg($imgDir, $base, $urlDir);
    if ($f) $bgImgs[] = $f;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#052e16">
<title>Login &mdash; Campus Facility Reservation</title>
<meta name="description" content="Masuk ke Campus Facility Reservation — Sistem Peminjaman Gedung & Ruangan Fakultas Sains Universitas Contoh.">
<link rel="icon" type="image/svg+xml" href="<?= base_url('assets/images/favicon.svg') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap"></noscript>
<link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>">
<style>
/* ══════════════════════════════════════════════
   DESIGN TOKENS — selaras dengan landing page
══════════════════════════════════════════════ */
:root{
  --bg:#0b1f12;
  --bg-alt:#0e2617;
  --surface:rgba(255,255,255,.05);
  --surface-2:rgba(255,255,255,.085);
  --border:rgba(74,222,128,.15);
  --border-2:rgba(74,222,128,.42);
  --green:#16a34a;
  --green-2:#22c55e;
  --green-bright:#4ade80;
  --green-light:#86efac;
  --gold:#fbbf24;
  --ink:#f1fdf6;
  --ink-2:rgba(241,253,246,.80);
  --ink-3:rgba(241,253,246,.62);
  --ink-4:rgba(241,253,246,.46);
  --shadow:0 24px 70px rgba(0,0,0,.5);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:'Inter',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--ink);-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility;line-height:1.6;letter-spacing:-.003em}
h1,h2,h3,h4{font-family:'Plus Jakarta Sans','Inter',sans-serif;letter-spacing:-.022em}
::selection{background:rgba(74,222,128,.32);color:#fff}

/* ── WRAPPER ── */
.login-page{min-height:100vh;display:flex;align-items:stretch;position:relative;overflow:hidden;background:var(--bg)}

/* ── LEFT PANEL - background slideshow ── */
.login-left{flex:1;position:relative;display:flex;align-items:center;justify-content:center;min-height:100vh;overflow:hidden}
.slide-bg{position:absolute;inset:0;background-size:cover;background-position:center top;background-repeat:no-repeat;transition:opacity 1.4s ease;opacity:0}
.slide-bg.active{opacity:1}
<?php if (empty($bgImgs)): ?>
.slide-bg{opacity:1;background:linear-gradient(150deg,#042713 0%,#073a1c 30%,#0a4f26 55%,#063318 78%,#03190d 100%)}
.login-left::after{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 30% 25%,rgba(34,197,94,.22) 0%,transparent 55%),radial-gradient(ellipse at 75% 70%,rgba(251,191,36,.12) 0%,transparent 50%),radial-gradient(ellipse at 60% 15%,rgba(74,222,128,.14) 0%,transparent 40%);pointer-events:none;z-index:1}
<?php endif; ?>
.left-overlay{position:absolute;inset:0;background:<?= empty($bgImgs) ? 'transparent' : 'linear-gradient(160deg,rgba(2,25,10,.72) 0%,rgba(4,40,16,.56) 50%,rgba(2,25,10,.68) 100%)' ?>}
.left-geo{position:absolute;inset:0;overflow:hidden;pointer-events:none}
#login-canvas{position:absolute;inset:0;pointer-events:none}
.left-content{position:relative;z-index:2;padding:48px;max-width:480px;text-align:center}
@media(max-width:768px){.login-left{display:none}}

/* Owl animation */
.login-owl-wrap{margin-bottom:22px}
.login-owl{width:110px;height:110px;filter:drop-shadow(0 0 28px rgba(74,222,128,.45));animation:owl-float 4s ease-in-out infinite}
.login-owl-eye-l,.login-owl-eye-r{transform-origin:50% 50%;animation:owl-blink 5s ease-in-out infinite}
.login-owl-eye-r{animation-delay:.09s}
@keyframes owl-float{0%,100%{transform:translateY(0) rotate(-3deg)}50%{transform:translateY(-14px) rotate(3deg)}}
@keyframes owl-blink{0%,86%,100%{transform:scaleY(1)}91%,94%{transform:scaleY(.06)}}

.left-title{font-size:clamp(2rem,3vw,2.6rem);font-weight:800;color:#fff;line-height:1.12;margin-bottom:12px;text-shadow:0 2px 30px rgba(0,0,0,.35)}
.left-title span{background:linear-gradient(135deg,var(--green-bright),var(--green-2),var(--gold));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;display:block}
.left-sub{font-size:15px;color:var(--ink-2);margin-bottom:34px;line-height:1.7;max-width:400px;margin-left:auto;margin-right:auto}
.left-features{display:flex;flex-direction:column;gap:14px;text-align:left;max-width:340px;margin:0 auto}
.left-feat{display:flex;align-items:center;gap:14px;background:rgba(8,24,16,.5);border:1px solid var(--border);border-radius:14px;padding:13px 17px;backdrop-filter:blur(8px);transition:all .25s}
.left-feat:hover{border-color:var(--border-2);transform:translateY(-2px);background:rgba(8,24,16,.65)}
.feat-icon{font-size:22px;flex-shrink:0}
.feat-label{font-size:13.5px;font-weight:700;color:var(--ink);line-height:1.2}
.feat-sub{font-size:11.5px;color:var(--ink-3);margin-top:3px}
.left-footer{margin-top:34px;font-size:11.5px;color:var(--ink-4);line-height:1.6}

/* Slide dots */
.slide-dots{position:absolute;bottom:28px;left:50%;transform:translateX(-50%);display:flex;gap:6px;z-index:3}
.slide-dot{width:6px;height:6px;border-radius:3px;background:rgba(255,255,255,.3);transition:all .4s;cursor:pointer}
.slide-dot.active{width:22px;background:var(--green-bright)}

/* ── RIGHT PANEL - form — dark glassmorphism ── */
.login-right{width:500px;flex-shrink:0;background:linear-gradient(160deg,#0e2318 0%,#0a1c13 55%,#081710 100%);display:flex;align-items:center;justify-content:center;padding:40px 36px;position:relative;overflow:hidden;border-left:1px solid rgba(74,222,128,.2)}
@media(max-width:768px){.login-right{width:100%;min-height:100vh;border-left:none}}
.right-geo{position:absolute;bottom:-80px;right:-60px;width:380px;height:380px;border-radius:50%;background:radial-gradient(circle,rgba(22,163,74,.18),transparent 70%);pointer-events:none;animation:glow-shift 9s ease-in-out infinite}
.right-geo2{position:absolute;top:-60px;left:-40px;width:300px;height:300px;border-radius:50%;background:radial-gradient(circle,rgba(251,191,36,.1),transparent 70%);pointer-events:none;animation:glow-shift 11s ease-in-out infinite reverse}
@keyframes glow-shift{0%,100%{transform:scale(1);opacity:.85}50%{transform:scale(1.12);opacity:1}}

.login-box{width:100%;max-width:400px;position:relative;z-index:1;background:transparent!important;border-radius:0!important;overflow:visible!important;box-shadow:none!important;animation:slideUp .6s cubic-bezier(.4,0,.2,1)}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}

/* Top accent bar */
.login-box-accent{height:4px;background:linear-gradient(90deg,var(--green),var(--green-2),var(--gold));border-radius:4px 4px 0 0;margin-bottom:0;box-shadow:0 0 28px rgba(34,197,94,.5)}

/* Card — solid dark with subtle green tint, lebih kontras */
.login-card{
  background:linear-gradient(165deg,rgba(15,38,24,.92) 0%,rgba(10,28,18,.95) 100%);
  backdrop-filter:blur(24px) saturate(150%);
  border:1px solid rgba(74,222,128,.22);
  border-top:none;
  border-radius:0 0 22px 22px;
  padding:36px 32px;
  box-shadow:0 30px 80px rgba(0,0,0,.6),0 0 100px rgba(22,163,74,.08),inset 0 1px 0 rgba(255,255,255,.04);
  position:relative
}
.login-card::before{
  content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse 70% 50% at 50% 0%,rgba(74,222,128,.06) 0%,transparent 60%);
  border-radius:inherit;pointer-events:none
}

.login-logo{text-align:center;margin-bottom:26px;position:relative}
.login-logo-owl{width:70px;height:70px;filter:drop-shadow(0 0 18px rgba(74,222,128,.5));animation:owl-float 4s ease-in-out infinite;margin-bottom:14px}
.login-logo h2{font-size:1.65rem;font-weight:800;color:#fff;margin-bottom:6px;letter-spacing:-.02em}
.login-logo p{font-size:13.5px;color:var(--ink-2);line-height:1.55;max-width:280px;margin:0 auto}

/* Alert */
.login-alert{display:flex;align-items:flex-start;gap:10px;background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.38);border-radius:12px;padding:12px 14px;margin-bottom:18px;font-size:13px;color:#fca5a5;font-weight:500}

/* Form — selector di-scope ke .login-card agar mengalahkan style.css */
.login-card .form-group{margin-bottom:18px;position:relative}
.login-card .form-label{display:block;font-size:12px;font-weight:700;color:var(--green-light);margin-bottom:9px;text-transform:uppercase;letter-spacing:.8px}
.login-card .form-input{width:100%;background:rgba(255,255,255,.05);border:1.5px solid rgba(74,222,128,.22);border-radius:12px;padding:14px 16px;font-size:14.5px;color:#fff;outline:none;transition:all .25s cubic-bezier(.4,0,.2,1);font-family:inherit;font-weight:500}
.login-card .form-input::placeholder{color:rgba(241,253,246,.45);font-weight:400}
.login-card .form-input:focus{border-color:var(--green-bright);background:rgba(255,255,255,.08);box-shadow:0 0 0 4px rgba(74,222,128,.15),0 6px 20px rgba(0,0,0,.3)}
.login-card .form-input:hover:not(:focus){border-color:rgba(74,222,128,.34);background:rgba(255,255,255,.06)}
.input-hint{display:none;align-items:center;gap:5px;margin-top:8px;font-size:12px;font-weight:600}

/* Password group slide-in */
#password-group{overflow:hidden;max-height:0;opacity:0;transition:max-height .35s ease,opacity .3s ease;margin-bottom:0}
#password-group.visible{max-height:130px;opacity:1;margin-bottom:18px}

/* OTP info */
.otp-info{display:none;background:rgba(34,197,94,.12);border:1px solid var(--border-2);border-radius:10px;padding:12px 15px;font-size:12.5px;color:var(--green-light);margin-top:14px;line-height:1.6;font-weight:500}

/* Submit button */
.submit-btn{width:100%;background:linear-gradient(135deg,var(--green-2),var(--green));color:#fff;font-weight:700;font-size:15.5px;padding:15px;border:none;border-radius:13px;cursor:pointer;margin-top:10px;transition:all .25s cubic-bezier(.4,0,.2,1);font-family:inherit;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 8px 28px rgba(22,163,74,.45);letter-spacing:.01em;position:relative;overflow:hidden}
.submit-btn::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,transparent 0%,rgba(255,255,255,.15) 50%,transparent 100%);transform:translateX(-100%);transition:transform .6s}
.submit-btn:hover::before{transform:translateX(100%)}
.submit-btn:hover{transform:translateY(-2px);box-shadow:0 14px 38px rgba(34,197,94,.6);background:linear-gradient(135deg,#2ee268,var(--green-2))}
.submit-btn:active{transform:translateY(0)}

/* Bottom links — on dark bg — KONTRAS TINGGI agar terbaca */
.login-back-wrap{text-align:center;margin-top:24px}
.login-back{display:inline-flex;align-items:center;gap:7px;color:#fff;font-size:13.5px;font-weight:600;text-decoration:none;transition:all .25s cubic-bezier(.4,0,.2,1);padding:11px 22px;border-radius:11px;background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.3);backdrop-filter:blur(8px)}
.login-back:hover{color:#0b1f12;background:var(--green-bright);border-color:var(--green-bright);transform:translateY(-2px);box-shadow:0 8px 24px rgba(74,222,128,.35)}
.login-copy{text-align:center;color:var(--ink-2);font-size:12px;margin-top:14px;font-weight:500;letter-spacing:.01em}

/* Separator */
.login-sep{border:none;border-top:1px solid rgba(74,222,128,.15);margin:22px 0 16px;position:relative}
.login-sep-label{text-align:center;font-size:10.5px;color:var(--ink-4);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:12px;font-weight:700}

/* Dept chips */
.dept-chips{display:flex;gap:7px;flex-wrap:wrap;justify-content:center;margin-top:4px}
.dept-chip{font-size:10.5px;font-weight:600;background:rgba(74,222,128,.12);color:var(--green-light);padding:5px 11px;border-radius:8px;border:1px solid var(--border);transition:all .2s;letter-spacing:.02em}
.dept-chip:hover{background:rgba(74,222,128,.2);border-color:var(--border-2);color:#fff}
</style>
</head>
<body>
<div class="login-page">

  <!-- ══ LEFT: Visual Panel ══ -->
  <div class="login-left">
    <!-- Background slideshow -->
    <?php if (!empty($bgImgs)): ?>
      <?php foreach ($bgImgs as $i => $img): ?>
      <div class="slide-bg <?= $i===0?'active':'' ?>" style="background-image:url('<?= $img ?>')"></div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="slide-bg active"></div>
    <?php endif; ?>
    <div class="left-overlay"></div>
    <div class="left-geo">
      <svg width="500" height="500" style="position:absolute;top:-10%;right:-10%;opacity:.04" viewBox="0 0 500 500">
        <circle cx="250" cy="250" r="230" fill="none" stroke="#4ade80" stroke-width="2"/>
        <circle cx="250" cy="250" r="170" fill="none" stroke="#4ade80" stroke-width="1"/>
        <circle cx="250" cy="250" r="110" fill="none" stroke="#fbbf24" stroke-width="1"/>
      </svg>
    </div>
    <canvas id="login-canvas"></canvas>

    <div class="left-content">
      <!-- Animated Owl -->
      <div class="login-owl-wrap">
        <svg class="login-owl" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
          <circle cx="50" cy="50" r="46" fill="rgba(22,163,74,0.15)" stroke="rgba(74,222,128,0.3)" stroke-width="2"/>
          <ellipse cx="50" cy="73" rx="22" ry="23" fill="#fff"/>
          <circle cx="50" cy="41" r="20" fill="#fff"/>
          <polygon points="33,24 28,11 40,22" fill="#fff"/>
          <polygon points="67,24 72,11 60,22" fill="#fff"/>
          <circle class="login-owl-eye-l" cx="41" cy="39" r="9.5" fill="#16a34a"/>
          <circle class="login-owl-eye-r" cx="59" cy="39" r="9.5" fill="#16a34a"/>
          <circle cx="41" cy="39" r="6" fill="#fbbf24"/>
          <circle cx="59" cy="39" r="6" fill="#fbbf24"/>
          <circle cx="41" cy="39" r="3" fill="#0c1a0e"/>
          <circle cx="59" cy="39" r="3" fill="#0c1a0e"/>
          <circle cx="39.5" cy="37.5" r="1.4" fill="white"/>
          <circle cx="57.5" cy="37.5" r="1.4" fill="white"/>
          <polygon points="50,47 44,54 56,54" fill="#d97706"/>
          <ellipse cx="32" cy="73" rx="11" ry="18" fill="#f0fdf4" transform="rotate(-12,32,73)"/>
          <ellipse cx="68" cy="73" rx="11" ry="18" fill="#f0fdf4" transform="rotate(12,68,73)"/>
          <line x1="41" y1="94" x2="36" y2="100" stroke="#d97706" stroke-width="2.5" stroke-linecap="round"/>
          <line x1="41" y1="94" x2="41" y2="100" stroke="#d97706" stroke-width="2.5" stroke-linecap="round"/>
          <line x1="41" y1="94" x2="46" y2="100" stroke="#d97706" stroke-width="2.5" stroke-linecap="round"/>
          <line x1="59" y1="94" x2="54" y2="100" stroke="#d97706" stroke-width="2.5" stroke-linecap="round"/>
          <line x1="59" y1="94" x2="59" y2="100" stroke="#d97706" stroke-width="2.5" stroke-linecap="round"/>
          <line x1="59" y1="94" x2="64" y2="100" stroke="#d97706" stroke-width="2.5" stroke-linecap="round"/>
        </svg>
      </div>

      <div class="left-title">
        Campus Facility Reservation
        <span>Universitas Contoh</span>
      </div>
      <p class="left-sub">Sistem Informasi Peminjaman Gedung &amp; Ruangan Fakultas Sains</p>

      <div class="left-features">
        <div class="left-feat">
          <span class="feat-icon">📧</span>
          <div>
            <div class="feat-label">Pengguna</div>
            <div class="feat-sub">Login via OTP — email @students.university.example / @faculty.university.example</div>
          </div>
        </div>
        <div class="left-feat">
          <span class="feat-icon">🔒</span>
          <div>
            <div class="feat-label">Admin</div>
            <div class="feat-sub">Login via password — email @university.example (selain @faculty.university.example)</div>
          </div>
        </div>
        <div class="left-feat">
          <span class="feat-icon">🏛️</span>
          <div>
            <div class="feat-label">Ruangan Tersedia</div>
            <div class="feat-sub">Indoor, Outdoor, Lab, Aula — semua bisa dipesan</div>
          </div>
        </div>
      </div>
      <p class="left-footer">Fakultas Sains<br>Universitas Contoh © <?= date('Y') ?></p>
    </div>

    <!-- Slideshow dots -->
    <?php if (count($bgImgs) > 1): ?>
    <div class="slide-dots" id="slide-dots">
      <?php foreach ($bgImgs as $i => $img): ?>
      <div class="slide-dot <?= $i===0?'active':'' ?>" onclick="goSlide(<?= $i ?>)"></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- ══ RIGHT: Login Form ══ -->
  <div class="login-right">
    <div class="right-geo"></div>
    <div class="right-geo2"></div>

    <div class="login-box">
      <div class="login-box-accent"></div>
      <div class="login-card">

        <div class="login-logo">
          <svg class="login-logo-owl" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
            <circle cx="50" cy="50" r="46" fill="rgba(22,163,74,0.15)" stroke="rgba(74,222,128,0.3)" stroke-width="2"/>
            <ellipse cx="50" cy="73" rx="22" ry="23" fill="#fff"/>
            <circle cx="50" cy="41" r="20" fill="#fff"/>
            <polygon points="33,24 28,11 40,22" fill="#fff"/>
            <polygon points="67,24 72,11 60,22" fill="#fff"/>
            <circle cx="41" cy="39" r="9.5" fill="#16a34a"/>
            <circle cx="59" cy="39" r="9.5" fill="#16a34a"/>
            <circle cx="41" cy="39" r="6" fill="#fbbf24"/>
            <circle cx="59" cy="39" r="6" fill="#fbbf24"/>
            <circle cx="41" cy="39" r="3" fill="#0c1a0e"/>
            <circle cx="59" cy="39" r="3" fill="#0c1a0e"/>
            <circle cx="39.5" cy="37.5" r="1.4" fill="white"/>
            <circle cx="57.5" cy="37.5" r="1.4" fill="white"/>
            <polygon points="50,47 44,54 56,54" fill="#d97706"/>
          </svg>
          <h2>Masuk ke Sistem</h2>
          <p id="login-sub">Masukkan email kampus Anda untuk melanjutkan</p>
        </div>

        <?php if ($error): ?>
        <div class="login-alert">⚠️ <?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= base_url('login.php') ?>" id="login-form">
          <?= csrf_field() ?>
          <div class="form-group">
            <label class="form-label">📧 Email Kampus</label>
            <input type="email" name="email" id="email-input" class="form-input"
                   placeholder="email@students.university.example"
                   value="<?= e($_POST['email'] ?? '') ?>"
                   oninput="detectEmailType(this.value)"
                   autocomplete="email" required>
            <div id="email-hint" class="input-hint"></div>
          </div>

          <div id="password-group">
            <label class="form-label">🔒 Password Admin</label>
            <input type="password" name="password" id="password-input" class="form-input" placeholder="••••••••">
          </div>

          <button type="submit" id="submit-btn" class="submit-btn">
            <span id="submit-text">Lanjutkan</span>
            <span>→</span>
          </button>

          <div id="otp-info" class="otp-info">
            📬 Kode OTP 6 digit akan dikirim ke email kampus Anda. Berlaku 10 menit.
          </div>

        </form>

        <hr class="login-sep">
        <div class="login-sep-label">Tersedia di Departemen</div>
        <div class="dept-chips">
          <span class="dept-chip">Matematika</span>
          <span class="dept-chip">Fisika</span>
          <span class="dept-chip">Kimia</span>
          <span class="dept-chip">Biologi</span>
          <span class="dept-chip">Pend. IPA</span>
          <span class="dept-chip">Sains Terapan</span>
          <span class="dept-chip">Outdoor</span>
        </div>

      </div><!-- /login-card -->

      <div class="login-back-wrap">
        <a href="<?= base_url('index.php') ?>" class="login-back">← Kembali ke Beranda</a>
      </div>
      <p class="login-copy">&copy; <?= date('Y') ?> Fakultas Sains Universitas Contoh</p>
    </div>
  </div>

</div><!-- /login-page -->

<script>
/* ── Email detection ── */
function detectEmailType(val){
  const pwGroup   = document.getElementById('password-group');
  const pwInput   = document.getElementById('password-input');
  const otpInfo   = document.getElementById('otp-info');
  const submitTxt = document.getElementById('submit-text');
  const hint      = document.getElementById('email-hint');
  const sub       = document.getElementById('login-sub');

  const isStudent = val.endsWith('@students.university.example') || val.endsWith('@faculty.university.example');
  const isAdmin   = val.endsWith('@university.example') && !isStudent;

  if(isStudent){
    pwGroup.classList.remove('visible');
    pwInput.required = false;
    otpInfo.style.display = 'block';
    submitTxt.textContent = '📬 Kirim Kode OTP';
    sub.textContent = 'Login via OTP — verifikasi via OTP ke email Anda';
    hint.style.display='flex'; hint.style.color='#4ade80';
    hint.innerHTML='✅ Email terdeteksi — login via OTP';
  } else if(isAdmin){
    pwGroup.classList.add('visible');
    pwInput.required = true;
    otpInfo.style.display = 'none';
    submitTxt.textContent = 'Masuk sebagai Admin';
    sub.textContent = 'Login admin — masukkan password akun Anda';
    hint.style.display='flex'; hint.style.color='#fbbf24';
    hint.innerHTML='🔑 Email admin terdeteksi';
  } else {
    pwGroup.classList.remove('visible');
    pwInput.required = false;
    otpInfo.style.display = 'none';
    submitTxt.textContent = 'Lanjutkan';
    sub.textContent = 'Masukkan email kampus Anda untuk melanjutkan';
    hint.style.display = (val.length>5&&val.includes('@'))?'flex':'none';
    hint.style.color='#f87171';
    hint.innerHTML='⚠️ Gunakan email @students.university.example / @faculty.university.example atau @university.example';
  }
}
window.addEventListener('load',()=>{
  const v=document.getElementById('email-input').value;
  if(v) detectEmailType(v);
});

/* ── Slideshow ── */
<?php if (count($bgImgs) > 1): ?>
var _slides = document.querySelectorAll('.slide-bg');
var _dots   = document.querySelectorAll('.slide-dot');
var _cur = 0;
function goSlide(i){
  _slides[_cur].classList.remove('active');
  _dots[_cur].classList.remove('active');
  _cur = i;
  _slides[_cur].classList.add('active');
  _dots[_cur].classList.add('active');
}
setInterval(function(){ goSlide((_cur+1) % _slides.length); }, 5000);
<?php endif; ?>

/* ── Canvas particles ── */
(function(){
  var canvas = document.getElementById('login-canvas');
  if(!canvas) return;
  var ctx = canvas.getContext('2d');
  var W,H,pts=[],anim;
  function sz(){ W=canvas.width=canvas.offsetWidth; H=canvas.height=canvas.offsetHeight; }
  function P(){
    this.x=Math.random()*W; this.y=Math.random()*H;
    this.r=Math.random()*2.4+.6;
    this.vx=(Math.random()-.5)*.35; this.vy=(Math.random()-.5)*.35;
    this.a=Math.random()*.7+.2;
    this.gold=Math.random()<.25;
  }
  function init(){ sz(); pts=[]; var n=Math.floor(W*H/6000); for(var i=0;i<n;i++) pts.push(new P()); }
  function draw(){
    ctx.clearRect(0,0,W,H);
    pts.forEach(function(p){
      ctx.shadowBlur = p.r>1.6 ? 10 : 0;
      ctx.shadowColor = p.gold ? 'rgba(251,191,36,.5)' : 'rgba(74,222,128,.5)';
      ctx.beginPath(); ctx.arc(p.x,p.y,p.r,0,Math.PI*2);
      ctx.fillStyle = p.gold ? 'rgba(251,191,36,'+p.a*.8+')' : 'rgba(74,222,128,'+p.a+')';
      ctx.fill(); ctx.shadowBlur=0;
      p.x+=p.vx; p.y+=p.vy;
      if(p.x<0)p.x=W; if(p.x>W)p.x=0;
      if(p.y<0)p.y=H; if(p.y>H)p.y=0;
    });
    anim=requestAnimationFrame(draw);
  }
  window.addEventListener('resize',function(){ cancelAnimationFrame(anim); init(); draw(); });
  init(); draw();
})();
</script>
</body>
</html>
