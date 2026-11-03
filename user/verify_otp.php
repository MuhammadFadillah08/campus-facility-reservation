<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/mailer.php';
if (auth())  redirect(base_url('dashboard.php'));
if (!isset($_SESSION['otp_email'])) redirect(base_url('login.php'));

$db    = db();
$email = $_SESSION['otp_email'];
$error = '';

/* ── Kirim ulang OTP ── */
if (isset($_GET['resend'])) {
    $sent_at = $_SESSION['otp_sent_at'] ?? 0;
    if (time() - $sent_at < 60) {
        $error = 'Tunggu ' . (60 - (time() - $sent_at)) . ' detik sebelum kirim ulang.';
    } else {
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $s = $db->prepare("DELETE FROM otp_codes WHERE email=?");
        $s->bind_param('s', $email); $s->execute();

        // expires_at pakai NOW() MySQL agar timezone konsisten
        $s = $db->prepare("INSERT INTO otp_codes(email,otp_code,expires_at) VALUES(?,?,NOW() + INTERVAL 10 MINUTE)");
        $s->bind_param('ss', $email, $otp); $s->execute();

        $_SESSION['otp_sent_at'] = time();

        $mail_result = sendOtpEmail($email, $otp);
        if (!$mail_result['ok']) {
            $error = 'Gagal mengirim ulang kode OTP. Coba beberapa saat lagi.';
        } else {
            redirect(base_url('user/verify_otp.php?sent=1'));
        }
    }
}

/* ── Verifikasi kode OTP ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Gabungkan 6 digit dari input terpisah
    $digits = '';
    for ($i = 1; $i <= 6; $i++) {
        $digits .= preg_replace('/\D/', '', $_POST["d$i"] ?? '');
    }

    if (strlen($digits) < 6) {
        $error = 'Masukkan semua 6 digit kode OTP.';
    } else {
        $stmt = $db->prepare(
            "SELECT * FROM otp_codes
             WHERE email=? AND otp_code=? AND used=0 AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->bind_param('ss', $email, $digits);
        $stmt->execute();
        $otp_row = $stmt->get_result()->fetch_assoc();

        if ($otp_row) {
            // Tandai OTP sudah dipakai
            $u = $db->prepare("UPDATE otp_codes SET used=1 WHERE id=?");
            $u->bind_param('i', $otp_row['id']); $u->execute();

            // Cari atau buat akun mahasiswa
            $stmt2 = $db->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
            $stmt2->bind_param('s', $email); $stmt2->execute();
            $user = $stmt2->get_result()->fetch_assoc();

            if (!$user) {
                // Buat akun baru otomatis
                $raw_name = explode('@', $email)[0];
                $nama     = ucwords(preg_replace('/[._\-0-9]+/', ' ', $raw_name));
                $nama     = trim($nama) ?: 'Mahasiswa';
                $pwd_hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $role     = 'user';

                $ins = $db->prepare("INSERT INTO users(email,password,nama,role) VALUES(?,?,?,?)");
                $ins->bind_param('ssss', $email, $pwd_hash, $nama, $role);
                $ins->execute();
                $new_id = $db->insert_id;

                $stmt3 = $db->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
                $stmt3->bind_param('i', $new_id); $stmt3->execute();
                $user = $stmt3->get_result()->fetch_assoc();
            }

            // Login
            $_SESSION['user'] = $user;
            setRememberCookie($user);
            unset($_SESSION['otp_email'], $_SESSION['otp_sent_at']);

            flash('success', 'Selamat datang, ' . $user['nama'] . '! Anda berhasil masuk.');
            redirect(base_url('dashboard.php'));

        } else {
            $error = 'Kode OTP salah atau sudah kedaluwarsa. Periksa kembali atau minta kode baru.';
        }
    }
}

/* ── Helper: sensor email ── */
function maskEmail(string $email): string {
    [$local, $domain] = explode('@', $email);
    $visible = min(3, strlen($local));
    return substr($local, 0, $visible) . str_repeat('*', max(0, strlen($local) - $visible)) . '@' . $domain;
}

$masked = maskEmail($email);

// Ambil sisa waktu expire langsung dari DB (MySQL) — bebas masalah timezone PHP vs MySQL
$sent_at = $_SESSION['otp_sent_at'] ?? 0;
$exp_row = $db->query("SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS sisa
                        FROM otp_codes WHERE email='" . $db->real_escape_string($email) . "'
                        AND used=0 ORDER BY id DESC LIMIT 1");
$expires_s = 0;
if ($exp_row && ($exp_data = $exp_row->fetch_assoc())) {
    $expires_s = max(0, (int)$exp_data['sisa']);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#052e16">
<title>Verifikasi OTP &mdash; Campus Facility Reservation</title>
<link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>">
<style>
/* ── OTP Page ── */
body{background:#f0fdf4;min-height:100vh;display:flex;flex-direction:column}
.otp-wrap{flex:1;display:flex;align-items:center;justify-content:center;padding:24px}
.otp-card{background:#fff;border-radius:20px;box-shadow:0 20px 60px rgba(22,101,52,.12);width:100%;max-width:460px;overflow:hidden}
.otp-card-top{height:5px;background:linear-gradient(90deg,#14532d,#22c55e,#f59e0b,#14532d)}
.otp-card-body{padding:40px 36px}
.otp-logo{text-align:center;margin-bottom:28px}
.otp-logo-icon{width:64px;height:64px;background:linear-gradient(135deg,#14532d,#16a34a);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;font-size:28px;font-weight:900;color:#fff;box-shadow:0 6px 20px rgba(22,101,52,.3);margin-bottom:12px}
.otp-logo h1{font-size:1.05rem;font-weight:800;color:#1e293b;margin-bottom:4px}
.otp-logo p{font-size:12px;color:#64748b}
.otp-title{font-size:1.25rem;font-weight:800;color:#1e293b;text-align:center;margin-bottom:6px}
.otp-sub{font-size:13px;color:#64748b;text-align:center;line-height:1.6;margin-bottom:24px}
.otp-email-badge{display:inline-flex;align-items:center;gap:7px;background:#f0fdf4;border:1px solid #86efac;border-radius:20px;padding:7px 14px;font-size:13px;font-weight:600;color:#166534;margin:0 auto 24px;display:flex;justify-content:center}
/* 6 kotak digit */
.otp-digits{display:flex;gap:10px;justify-content:center;margin-bottom:24px}
.otp-digit{width:52px;height:62px;border:2px solid #e2e8f0;border-radius:12px;font-size:24px;font-weight:800;text-align:center;color:#1e293b;background:#fff;outline:none;transition:border-color .15s,box-shadow .15s;font-family:inherit}
.otp-digit:focus{border-color:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.15)}
.otp-digit.filled{border-color:#166534;background:#f0fdf4}
.otp-digit.error{border-color:#dc2626;background:#fef2f2;animation:shake .4s ease}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-6px)}75%{transform:translateX(6px)}}
/* Timer */
.otp-timer{text-align:center;margin-bottom:20px}
.otp-timer .timer-num{font-size:1.5rem;font-weight:900;color:#166534}
.otp-timer .timer-label{font-size:12px;color:#64748b;margin-top:2px}
.otp-timer-bar{height:4px;background:#e2e8f0;border-radius:2px;margin:10px 0;overflow:hidden}
.otp-timer-bar-fill{height:100%;background:linear-gradient(90deg,#16a34a,#4ade80);border-radius:2px;transition:width 1s linear}
/* Resend */
.otp-resend{text-align:center;font-size:13px;color:#64748b;margin-top:6px}
.otp-resend a{color:#166534;font-weight:700;text-decoration:underline;cursor:pointer}
.otp-resend a.disabled{color:#94a3b8;pointer-events:none;text-decoration:none}
/* Back link */
.otp-back{text-align:center;margin-top:20px}
.otp-back a{font-size:13px;color:#64748b;transition:.18s}
.otp-back a:hover{color:#166534}
/* Navbar kecil */
.otp-topbar{height:56px;background:var(--sidebar-bg);display:flex;align-items:center;padding:0 24px;gap:12px}
.otp-topbar-logo{display:flex;align-items:center;gap:10px;color:#fff;font-size:14px;font-weight:700}
.otp-topbar-logo-icon{width:32px;height:32px;background:#fff;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:900;color:var(--primary);font-size:15px}
</style>
</head>
<body>

<!-- Mini Topbar -->
<div class="otp-topbar">
  <div class="otp-topbar-logo">
    <div class="otp-topbar-logo-icon">F</div>
    <span>Campus Facility Reservation &mdash; Universitas Contoh</span>
  </div>
  <div style="margin-left:auto;font-size:12px;color:rgba(255,255,255,.4)">auth.example.edu</div>
</div>

<div class="otp-wrap">
  <div>
    <div class="otp-card">
      <div class="otp-card-top"></div>
      <div class="otp-card-body">

        <!-- Logo Kampus/Fakultas Sains -->
        <div class="otp-logo">
          <div class="otp-logo-icon">CF</div>
          <h1>Universitas Contoh</h1>
          <p>Fakultas Sains &mdash; Sistem Peminjaman Ruangan</p>
        </div>

        <div class="otp-title">🔐 Verifikasi Identitas Kampus</div>
        <p class="otp-sub">Kode OTP 6 digit telah dikirim ke email kampus Anda.<br>Periksa kotak masuk atau folder <em>spam</em>.</p>

        <!-- Email badge -->
        <div class="otp-email-badge">📧 <?= e($masked) ?></div>

        <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom:20px;display:block">⚠️ <?= e($error) ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['sent'])): ?>
        <div class="alert alert-success" style="margin-bottom:20px;display:block">✅ Kode OTP baru telah dikirim!</div>
        <?php endif; ?>

        <!-- Form OTP -->
        <form method="post" id="otp-form">
          <?= csrf_field() ?>
          <div class="otp-digits">
            <?php for ($i = 1; $i <= 6; $i++): ?>
            <input type="text" name="d<?= $i ?>" id="d<?= $i ?>"
                   class="otp-digit" maxlength="1" inputmode="numeric" pattern="[0-9]"
                   autocomplete="off" required>
            <?php endfor; ?>
          </div>

          <!-- Timer -->
          <div class="otp-timer">
            <div class="timer-num" id="timer-num"><?= gmdate('i:s', $expires_s) ?></div>
            <div class="timer-label">Kode kedaluwarsa dalam</div>
            <div class="otp-timer-bar">
              <div class="otp-timer-bar-fill" id="timer-bar"
                   style="width:<?= ($expires_s / 600 * 100) ?>%"></div>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-block btn-lg" id="verify-btn">
            ✅ Verifikasi Kode
          </button>
        </form>

        <!-- Resend -->
        <div class="otp-resend" id="resend-wrap" style="margin-top:16px">
          Tidak menerima kode?
          <a id="resend-link" href="<?= base_url('user/verify_otp.php?resend=1') ?>"
             class="<?= (time() - $sent_at < 60) ? 'disabled' : '' ?>">
            Kirim ulang
          </a>
          <span id="resend-timer" style="<?= (time() - $sent_at < 60) ? '' : 'display:none' ?>;font-size:12px;color:#94a3b8">
            (tunggu <span id="resend-countdown"><?= max(0, 60 - (time() - $sent_at)) ?></span>d)
          </span>
        </div>

      </div><!-- /card-body -->
    </div><!-- /card -->

    <div class="otp-back">
      <a href="<?= base_url('login.php') ?>">← Kembali ke Halaman Login</a>
    </div>
    <p style="text-align:center;font-size:11.5px;color:#94a3b8;margin-top:10px">&copy; <?= date('Y') ?> Fakultas Sains Universitas Contoh</p>
  </div>
</div>

<script>
/* ── Auto-focus & auto-tab antar digit ── */
const digits = document.querySelectorAll('.otp-digit');

digits.forEach((input, idx) => {
  input.addEventListener('input', e => {
    const val = e.target.value.replace(/\D/g, '');
    e.target.value = val.slice(-1);
    if (val && idx < digits.length - 1) digits[idx + 1].focus();
    updateFilled();
  });
  input.addEventListener('keydown', e => {
    if (e.key === 'Backspace' && !e.target.value && idx > 0) {
      digits[idx - 1].focus();
    }
    if (e.key === 'ArrowLeft'  && idx > 0) digits[idx - 1].focus();
    if (e.key === 'ArrowRight' && idx < digits.length - 1) digits[idx + 1].focus();
  });
  // Paste seluruh kode sekaligus
  input.addEventListener('paste', e => {
    e.preventDefault();
    const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
    text.split('').slice(0, 6).forEach((ch, i) => { if (digits[i]) digits[i].value = ch; });
    const last = Math.min(text.length, 6) - 1;
    if (digits[last]) digits[last].focus();
    updateFilled();
  });
});

function updateFilled() {
  digits.forEach(d => d.classList.toggle('filled', d.value !== ''));
}

// Auto-focus kotak pertama
digits[0].focus();

// Kelas error
<?php if ($error): ?>
digits.forEach(d => { d.classList.add('error'); d.value = ''; });
setTimeout(() => { digits.forEach(d => d.classList.remove('error')); digits[0].focus(); }, 500);
<?php endif; ?>

/* ── Countdown Timer ── */
let remaining = <?= $expires_s ?>;
const timerNum = document.getElementById('timer-num');
const timerBar = document.getElementById('timer-bar');
const verifyBtn = document.getElementById('verify-btn');

const tick = setInterval(() => {
  remaining--;
  if (remaining <= 0) {
    clearInterval(tick);
    timerNum.textContent = '00:00';
    timerNum.style.color = '#dc2626';
    timerBar.style.width = '0%';
    timerBar.style.background = '#dc2626';
    verifyBtn.disabled = true;
    verifyBtn.textContent = '⏰ Kode kedaluwarsa — minta kode baru';
    verifyBtn.style.background = '#9ca3af';
    document.getElementById('resend-link').classList.remove('disabled');
    document.getElementById('resend-timer').style.display = 'none';
    return;
  }
  const m = String(Math.floor(remaining / 60)).padStart(2, '0');
  const s = String(remaining % 60).padStart(2, '0');
  timerNum.textContent = m + ':' + s;
  timerBar.style.width = (remaining / 600 * 100) + '%';
  if (remaining <= 60) { timerNum.style.color = '#dc2626'; timerBar.style.background = '#dc2626'; }
  else if (remaining <= 120) { timerNum.style.color = '#d97706'; timerBar.style.background = '#f59e0b'; }
}, 1000);

/* ── Resend countdown ── */
let resendCd = <?= max(0, 60 - (time() - $sent_at)) ?>;
const resendLink    = document.getElementById('resend-link');
const resendTimer   = document.getElementById('resend-timer');
const resendCdSpan  = document.getElementById('resend-countdown');

if (resendCd > 0) {
  const rt = setInterval(() => {
    resendCd--;
    resendCdSpan.textContent = resendCd;
    if (resendCd <= 0) {
      clearInterval(rt);
      resendLink.classList.remove('disabled');
      resendTimer.style.display = 'none';
    }
  }, 1000);
}

/* ── Auto-submit saat semua digit terisi ── */
document.getElementById('otp-form').addEventListener('input', () => {
  const allFilled = [...digits].every(d => d.value !== '');
  if (allFilled) {
    setTimeout(() => document.getElementById('otp-form').submit(), 300);
  }
});
</script>
</body>
</html>
