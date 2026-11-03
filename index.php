<?php
require_once __DIR__ . '/includes/config.php';
if (auth()) redirect(base_url('dashboard.php'));

$db = db();
function dbCount(mysqli $db, string $sql): int {
    $r = $db->query($sql);
    return ($r && $row = $r->fetch_assoc()) ? (int)$row['c'] : 0;
}
function dbAll(mysqli $db, string $sql): array {
    $r = $db->query($sql);
    return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}
$stats = [
    'ruangan' => dbCount($db, "SELECT COUNT(*) c FROM ruangan WHERE tersedia=1"),
    'gedung'  => dbCount($db, "SELECT COUNT(*) c FROM gedung WHERE tipe='indoor'"),
    'dept'    => dbCount($db, "SELECT COUNT(*) c FROM gedung WHERE tipe='outdoor'"),
    'selesai' => dbCount($db, "SELECT COUNT(*) c FROM peminjaman WHERE status IN('disetujui','selesai')"),
];
$showcase = dbAll($db, "SELECT * FROM ruangan WHERE tersedia=1 ORDER BY tipe, departemen, nama LIMIT 6");
$preview  = dbAll($db, "SELECT nama, gedung, tersedia FROM ruangan ORDER BY tersedia DESC LIMIT 4");

// Check photo assets — support jpg, jpeg, webp, png
function findImg(string $dir, string $base, string $urlBase): string {
    foreach (['jpg','jpeg','webp','png'] as $ext) {
        if (file_exists($dir.$base.'.'.$ext)) return $urlBase.$base.'.'.$ext;
    }
    return '';
}
$imgDir  = __DIR__ . '/assets/images/';
$imgBase = base_url('assets/images/');
$heroImg = findImg($imgDir, 'campus-hero', $imgBase);
$gallery = [];
for ($i = 1; $i <= 9; $i++) {
    $g = findImg($imgDir, "campus-gallery-$i", $imgBase);
    if ($g) $gallery[] = $g;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#052e16">
<title>Campus Facility Reservation — Sistem Peminjaman Gedung &amp; Ruangan</title>
<meta name="description" content="Booking gedung dan ruangan Fakultas Sains Universitas Contoh secara online.">
<link rel="icon" type="image/svg+xml" href="<?= base_url('assets/images/favicon.svg') ?>">
<?php if ($heroImg): ?>
<link rel="preload" as="image" href="<?= $heroImg ?>" fetchpriority="high">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap"></noscript>
<link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>" fetchpriority="high">
<style>
/* ══════════════════════════════════════════════
   DESIGN TOKENS — palet hijau Fakultas Sains tersinkron
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
  --ink:#f1fdf6;            /* teks utama  — kontras tinggi */
  --ink-2:rgba(241,253,246,.80);  /* teks sekunder */
  --ink-3:rgba(241,253,246,.62);  /* teks tersier */
  --ink-4:rgba(241,253,246,.46);  /* teks redup  */
  --shadow:0 24px 70px rgba(0,0,0,.5);
  --shadow-sm:0 10px 34px rgba(0,0,0,.32);
}

/* ══════════════════════════════════════════════
   RESET & BASE
══════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;scroll-padding-top:84px;-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility}
body{font-family:'Inter',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--ink);overflow-x:hidden;font-size:clamp(15px,.5vw + 13.2px,17px);line-height:1.65;letter-spacing:-.003em}
h1,h2,h3,h4{font-family:'Plus Jakarta Sans','Inter',sans-serif;letter-spacing:-.022em}
::selection{background:rgba(74,222,128,.32);color:#fff}

/* ══════════════════════════════════════════════
   NAVBAR  — optimized 1920px
══════════════════════════════════════════════ */
.lp-nav{position:fixed;top:0;left:0;right:0;z-index:1000;padding:0 clamp(20px,3vw,60px);height:80px;display:flex;align-items:center;transition:all .35s cubic-bezier(.4,0,.2,1);background:rgba(7,19,13,0.72);backdrop-filter:blur(20px) saturate(140%);border-bottom:1px solid rgba(255,255,255,.05)}
.lp-nav.scrolled{background:rgba(6,16,11,0.96);box-shadow:0 8px 40px rgba(0,0,0,.45);height:70px;border-bottom-color:var(--border)}
.lp-nav-inner{width:100%;max-width:1500px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:20px}
.lp-logo{display:flex;align-items:center;gap:13px;text-decoration:none}
.lp-logo-owl{width:46px;height:46px;flex-shrink:0;transition:transform .35s}
.lp-logo:hover .lp-logo-owl{transform:rotate(-6deg) scale(1.05)}
.lp-logo-name{font-size:18px;font-weight:800;color:#fff;letter-spacing:-.01em;line-height:1.1}
.lp-logo-sub{font-size:11.5px;color:var(--ink-3);font-weight:500}
.lp-nav-links{display:flex;align-items:center;gap:4px}
.lp-nav-links a{color:var(--ink-2);text-decoration:none;font-size:15px;padding:9px 18px;border-radius:11px;transition:all .2s;font-weight:600}
.lp-nav-links a:hover{color:#fff;background:rgba(255,255,255,.08)}
.lp-btn-masuk{background:linear-gradient(135deg,var(--green-2),var(--green))!important;color:#fff!important;font-weight:700!important;padding:11px 24px!important;border-radius:12px!important;box-shadow:0 4px 18px rgba(22,163,74,.45);font-size:15px!important;margin-left:8px}
.lp-btn-masuk:hover{transform:translateY(-2px);box-shadow:0 8px 26px rgba(34,197,94,.55)!important;background:linear-gradient(135deg,#2ee268,var(--green-2))!important}
.lp-nav-mobile-toggle{background:none;border:none;color:#fff;font-size:22px;cursor:pointer;padding:6px;display:none}
@media(max-width:768px){.lp-nav-links{display:none}.lp-nav-mobile-toggle{display:block}}

/* ══════════════════════════════════════════════
   HERO
══════════════════════════════════════════════ */
.lp-hero{position:relative;min-height:100vh;display:flex;align-items:center;overflow:hidden;background:linear-gradient(150deg,#042713 0%,#073a1c 30%,#0a4f26 55%,#063318 78%,#03190d 100%)}
.hero-bg{position:absolute;inset:0;background-size:cover;background-position:center top;background-repeat:no-repeat;<?= $heroImg ? "background-image:url('$heroImg');" : '' ?>}
.hero-overlay{position:absolute;inset:0;background:<?= $heroImg ? 'linear-gradient(160deg,rgba(2,20,8,.65) 0%,rgba(5,40,15,.50) 40%,rgba(2,20,8,.60) 100%)' : 'transparent' ?>}
/* Fallback visual layers when no photo */
.hero-glow-1{position:absolute;top:-10%;left:-5%;width:55%;height:80%;background:radial-gradient(ellipse,rgba(34,197,94,.18) 0%,transparent 65%);pointer-events:none;animation:glow-pulse 7s ease-in-out infinite}
.hero-glow-2{position:absolute;bottom:-15%;right:-5%;width:50%;height:70%;background:radial-gradient(ellipse,rgba(251,191,36,.1) 0%,transparent 60%);pointer-events:none;animation:glow-pulse 9s ease-in-out infinite .5s}
.hero-glow-3{position:absolute;top:30%;left:35%;width:40%;height:55%;background:radial-gradient(ellipse,rgba(74,222,128,.09) 0%,transparent 60%);pointer-events:none;animation:glow-pulse 11s ease-in-out infinite 1s}
@keyframes glow-pulse{0%,100%{opacity:.7;transform:scale(1)}50%{opacity:1;transform:scale(1.08)}}
.hero-geo{position:absolute;inset:0;overflow:hidden;pointer-events:none}
.hero-geo svg{position:absolute;opacity:.12}
#hero-canvas{position:absolute;inset:0;pointer-events:none}
.lp-hero-inner{position:relative;z-index:2;width:100%;max-width:1500px;margin:0 auto;padding:clamp(100px,10vh,140px) clamp(24px,4vw,80px) 80px;display:grid;grid-template-columns:1fr 500px;gap:clamp(40px,5vw,80px);align-items:center}
@media(max-width:1100px){.lp-hero-inner{grid-template-columns:1fr;padding:110px 32px 70px}}

/* Hero Left */
.hero-badge{display:inline-flex;align-items:center;gap:9px;background:rgba(22,163,74,.18);border:1px solid var(--border-2);padding:8px 18px;border-radius:100px;font-size:13.5px;color:var(--green-light);font-weight:600;letter-spacing:.01em;margin-bottom:28px;animation:fadeInDown .7s ease both;backdrop-filter:blur(6px)}
.hero-badge .dot{width:8px;height:8px;background:var(--green-bright);border-radius:50%;box-shadow:0 0 10px var(--green-bright);animation:pulse-dot 2s ease infinite}
.lp-hero h1{font-size:clamp(2.6rem,5vw,5.2rem);font-weight:800;color:#fff;line-height:1.06;margin-bottom:24px;animation:fadeInUp .8s ease .1s both;text-shadow:0 2px 30px rgba(0,0,0,.35)}
.lp-hero h1 .highlight{background:linear-gradient(135deg,var(--green-bright),var(--green-2),var(--gold));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;display:block}
.lp-hero-desc{font-size:clamp(16px,1.2vw,20px);color:var(--ink-2);line-height:1.75;margin-bottom:36px;max-width:580px;animation:fadeInUp .8s ease .2s both}
.hero-btns{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:40px;animation:fadeInUp .8s ease .3s both}
.btn-hero-primary{display:inline-flex;align-items:center;gap:10px;background:linear-gradient(135deg,var(--green-2),var(--green));color:#fff;font-weight:700;font-size:clamp(15px,1.1vw,18px);padding:clamp(13px,1.2vh,18px) clamp(24px,2vw,36px);border-radius:14px;text-decoration:none;transition:all .25s cubic-bezier(.4,0,.2,1);box-shadow:0 6px 28px rgba(22,163,74,.5)}
.btn-hero-primary:hover{transform:translateY(-3px);box-shadow:0 12px 42px rgba(34,197,94,.62);background:linear-gradient(135deg,#2ee268,var(--green-2))}
.btn-hero-secondary{display:inline-flex;align-items:center;gap:10px;background:var(--surface-2);color:var(--ink);font-weight:600;font-size:clamp(14px,1vw,17px);padding:clamp(13px,1.2vh,18px) clamp(20px,1.8vw,30px);border-radius:14px;text-decoration:none;border:1px solid rgba(255,255,255,.16);transition:all .25s;backdrop-filter:blur(8px)}
.btn-hero-secondary:hover{background:rgba(255,255,255,.16);border-color:var(--border-2);transform:translateY(-2px)}
.hero-trust{display:flex;gap:24px;flex-wrap:wrap;animation:fadeInUp .8s ease .4s both}
.hero-trust span{display:flex;align-items:center;gap:6px;font-size:clamp(13px,.9vw,15px);color:var(--ink-3);font-weight:500}

/* Hero Right - Floating Card */
.hero-right{animation:fadeInRight .9s ease .2s both}
@media(max-width:960px){.hero-right{display:none}}
.hero-card{background:rgba(8,24,16,.55);backdrop-filter:blur(22px) saturate(140%);border:1px solid var(--border);border-radius:20px;padding:24px;box-shadow:var(--shadow)}
.hero-card-header{display:flex;align-items:center;gap:10px;margin-bottom:16px;font-size:14.5px;font-weight:700;color:var(--ink)}
.hero-room-item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 14px;border-radius:12px;margin-bottom:8px;background:var(--surface);border:1px solid rgba(255,255,255,.06);transition:all .2s}
.hero-room-item:hover{background:var(--surface-2);border-color:var(--border)}
.hero-room-name{font-size:13.5px;font-weight:600;color:var(--ink)}
.hero-room-info{font-size:12px;color:var(--ink-3);margin-top:2px}
.badge-ok{display:inline-flex;align-items:center;gap:4px;background:rgba(74,222,128,.16);color:var(--green-bright);font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap}
.badge-full{display:inline-flex;align-items:center;gap:4px;background:rgba(239,68,68,.16);color:#f87171;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;white-space:nowrap}
.hero-card-footer{margin-top:14px;padding-top:12px;border-top:1px solid rgba(255,255,255,.08);font-size:11.5px;color:var(--ink-4);text-align:center}

/* ══════════════════════════════════════════════
   OWL MASCOT
══════════════════════════════════════════════ */
.owl-wrap{display:flex;justify-content:center;margin-bottom:8px;animation:fadeInDown .8s ease both}
.owl-svg{width:90px;height:90px;filter:drop-shadow(0 0 20px rgba(74,222,128,.3));animation:owl-float 4s ease-in-out infinite}
.owl-eye-l,.owl-eye-r{transform-origin:50% 50%;animation:owl-blink 5s ease-in-out infinite}
.owl-eye-r{animation-delay:.08s}
@keyframes owl-float{0%,100%{transform:translateY(0) rotate(-2deg)}50%{transform:translateY(-10px) rotate(2deg)}}
@keyframes owl-blink{0%,88%,100%{transform:scaleY(1)}93%,96%{transform:scaleY(.08)}}
@keyframes pulse-dot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.7)}}

/* ══════════════════════════════════════════════
   PHOTO GALLERY STRIP
══════════════════════════════════════════════ */
.gallery-strip{background:linear-gradient(90deg,#0d2117,#0f2a1a,#0d2117);padding:14px 0;overflow:hidden;border-top:1px solid rgba(74,222,128,.18);border-bottom:1px solid rgba(74,222,128,.18);position:relative}
.gallery-strip::before,.gallery-strip::after{content:'';position:absolute;top:0;bottom:0;width:120px;z-index:2;pointer-events:none}
.gallery-strip::before{left:0;background:linear-gradient(90deg,#0d2117,transparent)}
.gallery-strip::after{right:0;background:linear-gradient(270deg,#0d2117,transparent)}
.gallery-track{display:flex;gap:14px;animation:marquee 38s linear infinite;width:max-content}
.gallery-track:hover{animation-play-state:paused}
.gallery-photo{width:240px;height:140px;border-radius:12px;object-fit:cover;flex-shrink:0;opacity:.72;filter:saturate(1.05);transition:all .35s cubic-bezier(.4,0,.2,1);border:1px solid rgba(255,255,255,.08)}
.gallery-photo:hover{opacity:1;transform:scale(1.04);border-color:var(--border-2);box-shadow:0 12px 34px rgba(0,0,0,.45)}
.gallery-placeholder{width:240px;height:140px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:36px;border:1px solid var(--border)}
@keyframes marquee{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}

/* ══════════════════════════════════════════════
   STATS
══════════════════════════════════════════════ */
.lp-stats{background:linear-gradient(135deg,#042d14,#0a5222);padding:clamp(44px,5vh,74px) clamp(24px,4vw,80px);position:relative;overflow:hidden;border-bottom:1px solid var(--border)}
.lp-stats::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 50% 50%,rgba(74,222,128,.1) 0%,transparent 70%);pointer-events:none}
.lp-stats-inner{max-width:1500px;margin:0 auto;display:grid;grid-template-columns:repeat(4,1fr);gap:32px;text-align:center;position:relative;z-index:1}
@media(max-width:640px){.lp-stats-inner{grid-template-columns:repeat(2,1fr)}}
.stat-item{animation:fadeInUp .7s ease both}
.stat-item+.stat-item{border-left:1px solid rgba(255,255,255,.08)}
@media(max-width:640px){.stat-item+.stat-item{border-left:none}}
.stat-num{font-size:clamp(2.6rem,4vw,4.2rem);font-weight:800;color:var(--green-bright);line-height:1;margin-bottom:10px;text-shadow:0 0 34px rgba(74,222,128,.45)}
.stat-num span{font-size:clamp(1.5rem,2.5vw,2.4rem)}
.stat-label{font-size:clamp(13px,.9vw,16px);color:var(--ink-2);font-weight:500;letter-spacing:.01em}

/* ══════════════════════════════════════════════
   SECTIONS BASE  — dark harmonized, 1920px
══════════════════════════════════════════════ */
.lp-section{
  padding:clamp(70px,8vh,110px) clamp(24px,4vw,80px);
  background:linear-gradient(160deg,#0f2a1a 0%,#0d2317 40%,#102b1c 100%);
  position:relative;overflow:hidden;
  border-top:1px solid rgba(74,222,128,.07)
}
.lp-section::after{
  content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse 70% 50% at 50% 0%,rgba(22,163,74,.09) 0%,transparent 60%);
  pointer-events:none;z-index:0
}
.lp-section-alt{
  background:linear-gradient(160deg,#142f1e 0%,#112819 40%,#153222 100%);
  position:relative;overflow:hidden;
  border-top:1px solid rgba(74,222,128,.07)
}
.lp-section-alt::after{
  content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse 60% 50% at 25% 50%,rgba(34,197,94,.08) 0%,transparent 65%);
  pointer-events:none;z-index:0
}
.lp-container{max-width:1500px;margin:0 auto}
.section-header{text-align:center;margin-bottom:clamp(40px,5vh,72px)}
.eyebrow{display:inline-flex;align-items:center;gap:6px;background:rgba(74,222,128,.12);color:var(--green-bright);font-size:clamp(11px,.8vw,13px);font-weight:700;padding:6px 16px;border-radius:20px;text-transform:uppercase;letter-spacing:1.2px;margin-bottom:18px;border:1px solid var(--border-2)}
.section-header h2{font-size:clamp(2rem,3vw,3.4rem);font-weight:800;color:#fff;line-height:1.14;margin-bottom:18px}
.section-header p{font-size:clamp(15px,1.1vw,18px);color:var(--ink-2);max-width:640px;margin:0 auto;line-height:1.75}
.reveal{opacity:0;transform:translateY(44px);transition:opacity .75s ease,transform .75s ease}
.reveal.visible{opacity:1;transform:none}
/* section canvas */
.sec-canvas{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:0}
.sec-inner{position:relative;z-index:1}

/* ══════════════════════════════════════════════
   SERVICES GRID
══════════════════════════════════════════════ */
.services-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:clamp(14px,1.5vw,24px)}
@media(max-width:1100px){.services-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:580px){.services-grid{grid-template-columns:1fr}}
.service-card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:clamp(22px,2.5vw,36px);transition:all .32s cubic-bezier(.4,0,.2,1);cursor:default;position:relative;overflow:hidden}
.service-card::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(74,222,128,.07),transparent);opacity:0;transition:opacity .32s}
.service-card:hover{border-color:var(--border-2);transform:translateY(-6px);box-shadow:var(--shadow),0 0 40px rgba(74,222,128,.07)}
.service-card:hover::before{opacity:1}
.service-card.featured{border-color:var(--border-2);background:rgba(22,163,74,.09)}
.service-icon{font-size:clamp(2.2rem,2.5vw,3rem);margin-bottom:16px;display:block}
.service-card h3{font-size:clamp(15px,1.1vw,18px);font-weight:700;color:#fff;margin-bottom:10px}
.service-card p{font-size:clamp(13px,.95vw,15.5px);color:var(--ink-2);line-height:1.7}

/* ══════════════════════════════════════════════
   STEPS
══════════════════════════════════════════════ */
.steps-row{display:grid;grid-template-columns:repeat(5,1fr);gap:clamp(12px,1.5vw,24px);position:relative}
@media(max-width:900px){.steps-row{grid-template-columns:1fr;max-width:400px;margin:0 auto}}
.steps-row::before{content:'';position:absolute;top:44px;left:10%;right:10%;height:2px;background:linear-gradient(90deg,transparent,var(--border-2),transparent);pointer-events:none}
@media(max-width:900px){.steps-row::before{display:none}}
.step{text-align:center;position:relative;z-index:1}
.step-num{width:clamp(64px,5.5vw,90px);height:clamp(64px,5.5vw,90px);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:clamp(1.4rem,2vw,2rem);font-weight:800;margin:0 auto 18px;border:2px solid var(--border-2);background:rgba(22,163,74,.12);color:var(--green-bright);transition:all .32s cubic-bezier(.4,0,.2,1);position:relative;box-shadow:0 4px 20px rgba(0,0,0,.3)}
.step:hover .step-num{background:rgba(22,163,74,.24);border-color:var(--green-bright);transform:scale(1.1) translateY(-2px);box-shadow:0 8px 32px rgba(74,222,128,.25)}
.step h3{font-size:clamp(14px,1.1vw,17px);font-weight:700;color:#fff;margin-bottom:8px}
.step p{font-size:clamp(12px,.9vw,14.5px);color:var(--ink-3);line-height:1.6}

/* ══════════════════════════════════════════════
   ROOM GRID
══════════════════════════════════════════════ */
.room-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:clamp(14px,1.5vw,22px)}
@media(max-width:1100px){.room-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:580px){.room-grid{grid-template-columns:1fr}}
.room-card{background:var(--surface);border:1px solid var(--border);border-radius:18px;overflow:hidden;transition:all .32s cubic-bezier(.4,0,.2,1)}
.room-card:hover{transform:translateY(-6px);border-color:var(--border-2);box-shadow:var(--shadow),0 0 30px rgba(74,222,128,.07)}
.room-card-img{width:100%;height:clamp(140px,12vw,200px);object-fit:cover;display:block}
.room-card-placeholder{height:clamp(140px,12vw,200px);display:flex;align-items:center;justify-content:center;font-size:clamp(2.5rem,3vw,4rem);background:linear-gradient(135deg,rgba(22,163,74,.12),rgba(10,40,20,.5))}
.room-card-placeholder.outdoor{background:linear-gradient(135deg,rgba(34,197,94,.12),rgba(4,100,60,.2))}
.room-card-body{padding:clamp(14px,1.5vw,22px)}
.room-card-name{font-size:clamp(14px,1.05vw,17px);font-weight:700;color:#fff;margin-bottom:5px}
.room-card-building{font-size:clamp(11.5px,.85vw,14px);color:var(--ink-3);margin-bottom:12px}
.room-card-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.type-badge{font-size:clamp(10.5px,.78vw,13px);font-weight:700;padding:4px 12px;border-radius:10px}
.type-indoor{background:rgba(59,130,246,.16);color:#93c5fd}
.type-outdoor{background:rgba(74,222,128,.16);color:var(--green-light)}
.room-capacity{font-size:clamp(11px,.82vw,13.5px);color:var(--ink-3)}
.facility-tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
.facility-tag{font-size:clamp(10.5px,.75vw,12.5px);background:var(--surface-2);color:var(--ink-3);padding:3px 10px;border-radius:7px;border:1px solid rgba(255,255,255,.07)}

/* ══════════════════════════════════════════════
   CTA SECTION
══════════════════════════════════════════════ */
.lp-cta{padding:100px 32px;background:linear-gradient(135deg,#052e16 0%,#0a4a20 50%,#052e16 100%);position:relative;overflow:hidden;text-align:center}
.lp-cta::before{content:'';position:absolute;top:-50%;left:-10%;width:60%;height:200%;background:radial-gradient(ellipse,rgba(74,222,128,.07) 0%,transparent 70%);pointer-events:none}
.lp-cta::after{content:'';position:absolute;bottom:-50%;right:-10%;width:50%;height:200%;background:radial-gradient(ellipse,rgba(251,191,36,.05) 0%,transparent 70%);pointer-events:none}
.lp-cta h2{font-size:clamp(1.8rem,3.5vw,2.8rem);font-weight:800;color:#fff;line-height:1.14;margin-bottom:16px;position:relative;z-index:1}
.lp-cta p{font-size:clamp(15px,1.1vw,17px);color:var(--ink-2);line-height:1.7;margin-bottom:36px;max-width:520px;margin-left:auto;margin-right:auto;position:relative;z-index:1}
.btn-cta{display:inline-flex;align-items:center;gap:10px;background:linear-gradient(135deg,var(--green-2),var(--green));color:#fff;font-weight:700;font-size:17px;padding:16px 36px;border-radius:14px;text-decoration:none;transition:all .3s cubic-bezier(.4,0,.2,1);box-shadow:0 6px 28px rgba(22,163,74,.5);position:relative;z-index:1}
.btn-cta:hover{transform:translateY(-3px);box-shadow:0 12px 42px rgba(34,197,94,.62);background:linear-gradient(135deg,#2ee268,var(--green-2))}
.cta-features{display:flex;justify-content:center;gap:24px;flex-wrap:wrap;margin-top:28px;position:relative;z-index:1}
.cta-features span{font-size:13px;color:var(--ink-3);display:flex;align-items:center;gap:6px;font-weight:500}

/* ══════════════════════════════════════════════
   FOOTER
══════════════════════════════════════════════ */
.lp-footer{
  background:linear-gradient(180deg,#0e2318 0%,#0b1c14 60%,#091810 100%);
  border-top:2px solid rgba(74,222,128,.2);
  padding:64px 32px 0;
  position:relative;
  overflow:hidden
}
.lp-footer::before{
  content:'';position:absolute;top:-60px;left:-10%;
  width:50%;height:300px;
  background:radial-gradient(ellipse,rgba(22,163,74,.1) 0%,transparent 65%);
  pointer-events:none
}
.lp-footer::after{
  content:'';position:absolute;bottom:0;right:-5%;
  width:40%;height:250px;
  background:radial-gradient(ellipse,rgba(74,222,128,.06) 0%,transparent 65%);
  pointer-events:none
}
.lp-footer-inner{max-width:1160px;margin:0 auto;display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:40px;padding-bottom:52px;position:relative;z-index:1}
@media(max-width:860px){.lp-footer-inner{grid-template-columns:1fr 1fr}}
@media(max-width:520px){.lp-footer-inner{grid-template-columns:1fr}}
.footer-brand p{font-size:13.5px;color:var(--ink-2);line-height:1.75;margin-top:14px;max-width:280px}
.footer-social{display:flex;gap:10px;margin-top:18px}
.footer-social a{width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,.07);border:1px solid rgba(74,222,128,.18);display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:16px;transition:all .25s}
.footer-social a:hover{background:rgba(22,163,74,.25);border-color:var(--border-2);transform:translateY(-3px);box-shadow:0 6px 20px rgba(22,163,74,.25)}
.footer-col h4{font-size:12px;font-weight:700;color:var(--green-bright);margin-bottom:18px;text-transform:uppercase;letter-spacing:1.5px}
.footer-col ul{list-style:none}
.footer-col li{margin-bottom:10px}
.footer-col a{font-size:13.5px;color:var(--ink-2);text-decoration:none;transition:all .2s;display:inline-flex;align-items:center;gap:4px}
.footer-col a:hover{color:var(--green-bright);padding-left:4px}
.footer-bottom{
  border-top:1px solid rgba(74,222,128,.12);
  padding:22px 0;
  max-width:1160px;
  margin:0 auto;
  display:flex;
  justify-content:space-between;
  align-items:center;
  flex-wrap:wrap;
  gap:8px;
  position:relative;z-index:1
}
.footer-bottom p{font-size:12.5px;color:var(--ink-3)}

/* ══════════════════════════════════════════════
   MOBILE NAV DROPDOWN
══════════════════════════════════════════════ */
#mobile-nav{display:none;position:fixed;top:72px;left:0;right:0;background:rgba(6,16,11,.97);z-index:999;padding:16px 24px;border-bottom:1px solid var(--border);backdrop-filter:blur(16px) saturate(140%)}
#mobile-nav a{display:block;color:var(--ink-2);padding:12px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:15px;font-weight:600;text-decoration:none}
#mobile-nav a:last-child{background:linear-gradient(135deg,var(--green-2),var(--green));color:#fff;font-weight:700;text-align:center;padding:13px;border-radius:12px;margin-top:12px;border:none}

/* ══════════════════════════════════════════════
   ANIMATIONS
══════════════════════════════════════════════ */
@keyframes fadeInUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:none}}
@keyframes fadeInDown{from{opacity:0;transform:translateY(-20px)}to{opacity:1;transform:none}}
@keyframes fadeInRight{from{opacity:0;transform:translateX(40px)}to{opacity:1;transform:none}}
@keyframes shimmer{0%{background-position:-400px 0}100%{background-position:400px 0}}
</style>
</head>
<body>

<!-- ══ NAVBAR ══ -->
<nav class="lp-nav" id="lp-nav">
  <div class="lp-nav-inner">
    <a href="<?= base_url() ?>" class="lp-logo">
      <svg class="lp-logo-owl" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
        <circle cx="50" cy="50" r="48" fill="rgba(22,163,74,0.2)" stroke="rgba(74,222,128,0.4)" stroke-width="2"/>
        <ellipse cx="50" cy="72" rx="21" ry="24" fill="#e8f5e9"/>
        <circle cx="50" cy="40" r="19" fill="#e8f5e9"/>
        <polygon points="33,24 28,12 40,22" fill="#e8f5e9"/>
        <polygon points="67,24 72,12 60,22" fill="#e8f5e9"/>
        <circle cx="41" cy="38" r="9" fill="#16a34a"/>
        <circle cx="59" cy="38" r="9" fill="#16a34a"/>
        <circle cx="41" cy="38" r="5.5" fill="#fbbf24"/>
        <circle cx="59" cy="38" r="5.5" fill="#fbbf24"/>
        <circle cx="41" cy="38" r="2.8" fill="#0c1a0e"/>
        <circle cx="59" cy="38" r="2.8" fill="#0c1a0e"/>
        <circle cx="39.5" cy="36.5" r="1.2" fill="white"/>
        <circle cx="57.5" cy="36.5" r="1.2" fill="white"/>
        <polygon points="50,46 44,53 56,53" fill="#d97706"/>
        <ellipse cx="33" cy="72" rx="10" ry="18" fill="#d4edda" transform="rotate(-10,33,72)"/>
        <ellipse cx="67" cy="72" rx="10" ry="18" fill="#d4edda" transform="rotate(10,67,72)"/>
      </svg>
      <div>
        <div class="lp-logo-name">Campus Facility Reservation</div>
        <div class="lp-logo-sub">Universitas Contoh</div>
      </div>
    </a>
    <nav class="lp-nav-links">
      <a href="#layanan">Layanan</a>
      <a href="#cara-booking">Panduan</a>
      <a href="#ruangan">Ruangan</a>
      <a href="<?= base_url('login.php') ?>" class="lp-btn-masuk">Masuk ke Sistem →</a>
    </nav>
    <button class="lp-nav-mobile-toggle" onclick="toggleMobileNav()" aria-label="Menu">☰</button>
  </div>
</nav>

<!-- Mobile Nav -->
<div id="mobile-nav">
  <a href="#layanan" onclick="toggleMobileNav()">Layanan</a>
  <a href="#cara-booking" onclick="toggleMobileNav()">Panduan</a>
  <a href="#ruangan" onclick="toggleMobileNav()">Ruangan</a>
  <a href="<?= base_url('login.php') ?>">Masuk ke Sistem →</a>
</div>

<!-- ══ HERO ══ -->
<section class="lp-hero">
  <div class="hero-bg"></div>
  <div class="hero-overlay"></div>
  <!-- Ambient glow (visible when no photo) -->
  <div class="hero-glow-1"></div>
  <div class="hero-glow-2"></div>
  <div class="hero-glow-3"></div>
  <!-- Geometric decorations -->
  <div class="hero-geo">
    <svg width="600" height="600" style="top:-10%;right:-8%" viewBox="0 0 600 600">
      <circle cx="300" cy="300" r="280" fill="none" stroke="rgba(74,222,128,0.08)" stroke-width="1"/>
      <circle cx="300" cy="300" r="200" fill="none" stroke="rgba(74,222,128,0.05)" stroke-width="1"/>
      <circle cx="300" cy="300" r="120" fill="none" stroke="rgba(251,191,36,0.06)" stroke-width="1"/>
    </svg>
    <svg width="400" height="400" style="bottom:-5%;left:-5%" viewBox="0 0 400 400">
      <polygon points="200,20 380,380 20,380" fill="none" stroke="rgba(74,222,128,0.05)" stroke-width="1"/>
      <polygon points="200,60 340,360 60,360" fill="none" stroke="rgba(74,222,128,0.04)" stroke-width="1"/>
    </svg>
  </div>
  <canvas id="hero-canvas"></canvas>

  <div class="lp-hero-inner">
    <!-- Left -->
    <div>
      <!-- Animated Owl -->
      <div class="owl-wrap">
        <svg class="owl-svg" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
          <circle cx="50" cy="50" r="46" fill="rgba(22,163,74,0.12)" stroke="rgba(74,222,128,0.25)" stroke-width="2"/>
          <ellipse cx="50" cy="73" rx="22" ry="23" fill="#fff"/>
          <circle cx="50" cy="41" r="20" fill="#fff"/>
          <polygon points="33,24 28,11 40,22" fill="#fff"/>
          <polygon points="67,24 72,11 60,22" fill="#fff"/>
          <circle class="owl-eye-l" cx="41" cy="39" r="9.5" fill="#16a34a"/>
          <circle class="owl-eye-r" cx="59" cy="39" r="9.5" fill="#16a34a"/>
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

      <div class="hero-badge"><span class="dot"></span> Fakultas Sains Universitas Contoh</div>
      <h1>Booking Gedung &amp; Ruangan<span class="highlight">Fakultas Sains Jadi Mudah</span></h1>
      <p class="lp-hero-desc">Ajukan peminjaman ruang kelas, laboratorium, aula, dan area outdoor Fakultas Sains secara online. Cek ketersediaan real-time, dapatkan persetujuan cepat.</p>
      <div class="hero-btns">
        <a href="<?= base_url('login.php') ?>" class="btn-hero-primary">🚀 Mulai Booking Sekarang</a>
        <a href="#cara-booking" class="btn-hero-secondary">📖 Lihat Panduan</a>
      </div>
      <div class="hero-trust">
        <span>✅ Proses 100% Online</span>
        <span>⚡ Validasi Real-time</span>
        <span>📱 Akses Kapanpun</span>
      </div>
    </div>

    <!-- Right: Floating Card -->
    <div class="hero-right">
      <div class="hero-card">
        <div class="hero-card-header">
          <span style="font-size:18px">🏢</span>
          Ketersediaan Ruangan Hari Ini
        </div>
        <?php foreach ($preview as $p): ?>
        <div class="hero-room-item">
          <div>
            <div class="hero-room-name"><?= e($p['nama']) ?></div>
            <div class="hero-room-info">📍 <?= e($p['gedung']) ?></div>
          </div>
          <?php if ($p['tersedia']): ?>
            <span class="badge-ok">✅ Tersedia</span>
          <?php else: ?>
            <span class="badge-full">🔴 Penuh</span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <div class="hero-card-footer">🔄 Diperbarui real-time · Login untuk cek detail</div>
      </div>
    </div>
  </div>
</section>

<!-- ══ PHOTO GALLERY STRIP ══ -->
<?php if (!empty($gallery)): ?>
<div class="gallery-strip">
  <div class="gallery-track" id="gallery-track">
    <?php for ($r = 0; $r < 2; $r++): foreach ($gallery as $img): ?>
      <img class="gallery-photo" src="<?= $img ?>" alt="Fakultas Sains" loading="lazy" decoding="async">
    <?php endforeach; endfor; ?>
  </div>
</div>
<?php else: ?>
<!-- Gallery placeholder when no photos uploaded yet -->
<div class="gallery-strip">
  <div class="gallery-track">
    <?php
    $placeholders = ['🏫','🏛️','🔬','🌿','🎓','🏢','📚','⚗️'];
    $colors = ['rgba(22,163,74,.12)','rgba(16,185,129,.12)','rgba(251,191,36,.1)','rgba(59,130,246,.1)'];
    for ($i = 0; $i < 16; $i++):
    ?>
    <div class="gallery-placeholder" style="background:<?= $colors[$i%4] ?>">
      <?= $placeholders[$i%8] ?>
    </div>
    <?php endfor; ?>
  </div>
</div>
<?php endif; ?>

<!-- ══ STATS ══ -->
<div class="lp-stats">
  <div class="lp-stats-inner">
    <?php
    $statsData = [
        ['num'=>$stats['ruangan'], 'suf'=>'+', 'label'=>'Ruangan Tersedia'],
        ['num'=>$stats['gedung'],  'suf'=>'',  'label'=>'Gedung Fakultas Sains'],
        ['num'=>$stats['dept'],    'suf'=>'',  'label'=>'Area Outdoor'],
        ['num'=>$stats['selesai'], 'suf'=>'+', 'label'=>'Peminjaman Selesai'],
    ];
    foreach ($statsData as $i => $s):
    ?>
    <div class="stat-item reveal" style="transition-delay:<?= $i*.1 ?>s">
      <div class="stat-num" data-target="<?= $s['num'] ?>" data-suf="<?= $s['suf'] ?>">0<?= $s['suf'] ?></div>
      <div class="stat-label"><?= $s['label'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ══ LAYANAN ══ -->
<section id="layanan" class="lp-section">
  <canvas class="sec-canvas" id="sc-layanan"></canvas>
  <div class="sec-inner">
  <div class="lp-container">
    <div class="section-header reveal">
      <div class="eyebrow">🏛️ Layanan Kami</div>
      <h2>Semua Kebutuhan Ruangan<br>Ada di Campus Facility Reservation</h2>
      <p>Dari ruang kelas kecil hingga aula besar, dari indoor ber-AC hingga area outdoor — semua bisa dipesan lewat satu sistem.</p>
    </div>
    <div class="services-grid">
      <?php
      $services = [
        ['🏛️','Ruang Kelas & Seminar','Ruang kelas 30–200 orang, dilengkapi proyektor dan AC. Ideal untuk kuliah tamu, seminar, dan rapat departemen.',''],
        ['🔬','Laboratorium','Lab fisika, kimia, biologi, dan komputer yang bisa dipinjam untuk penelitian, praktikum, dan workshop.','featured'],
        ['🎭','Aula & Auditorium','Kapasitas 200–500 orang untuk wisuda departemen, seminar nasional, dan festival mahasiswa.',''],
        ['🌿','Area Outdoor & Lapangan','Lapangan, taman, dan area terbuka untuk olahraga, pensi, dan pameran.',''],
        ['🤖','Rekomendasi Cerdas AI','Decision tree membantu memilih ruangan paling sesuai kapasitas dan fasilitas Anda.',''],
        ['⚡','Cek Ketersediaan Real-time','Lihat ruangan mana yang kosong pada tanggal dan jam tertentu sebelum mengajukan.',''],
      ];
      foreach ($services as $i => [$icon, $title, $desc, $extra]): ?>
      <div class="service-card <?= $extra ?> reveal" style="transition-delay:<?= ($i%3)*.08 ?>s">
        <span class="service-icon"><?= $icon ?></span>
        <h3><?= $title ?></h3>
        <p><?= $desc ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  </div><!-- /sec-inner -->
</section>

<!-- ══ HOW IT WORKS ══ -->
<section id="cara-booking" class="lp-section lp-section-alt">
  <canvas class="sec-canvas" id="sc-cara"></canvas>
  <div class="sec-inner">
  <div class="lp-container">
    <div class="section-header reveal">
      <div class="eyebrow">📖 Panduan</div>
      <h2>5 Langkah Mudah<br>Booking Ruangan Fakultas Sains</h2>
      <p>Proses peminjaman sepenuhnya online dan transparan. Tidak perlu antri, tidak perlu datang ke kantor.</p>
    </div>
    <div class="steps-row">
      <?php
      $steps = [
        ['1','🔑','Login','Masuk dengan email kampus (@students.university.example / @faculty.university.example)'],
        ['2','🏢','Pilih Ruangan','Cek ketersediaan sesuai tanggal & jam yang diinginkan'],
        ['3','📝','Isi Formulir','Lengkapi data kegiatan dan upload surat peminjaman'],
        ['4','✅','Validasi Admin','Admin departemen memproses permohonan Anda'],
        ['5','🎉','Gunakan!','Setelah disetujui, gunakan ruangan sesuai jadwal'],
      ];
      foreach ($steps as $i => [$num, $icon, $title, $desc]): ?>
      <div class="step reveal" style="transition-delay:<?= $i*.1 ?>s">
        <div class="step-num"><?= $icon ?></div>
        <h3><?= $title ?></h3>
        <p><?= $desc ?></p>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="text-align:center;margin-top:50px">
      <a href="<?= base_url('login.php') ?>" class="btn-hero-primary">Mulai Sekarang →</a>
    </div>
  </div>
  </div><!-- /sec-inner -->
</section>

<!-- ══ ROOM SHOWCASE ══ -->
<section id="ruangan" class="lp-section">
  <canvas class="sec-canvas" id="sc-ruangan"></canvas>
  <div class="sec-inner">
  <div class="lp-container">
    <div class="section-header reveal">
      <div class="eyebrow">🏢 Ruangan Tersedia</div>
      <h2>Temukan Ruangan<br>yang Kamu Butuhkan</h2>
      <p>Beragam pilihan ruangan dari seluruh departemen Fakultas Sains — indoor maupun outdoor.</p>
    </div>
    <div class="room-grid">
      <?php foreach ($showcase as $i => $rm):
        $hasPhoto = $rm['foto'] && file_exists(UPLOAD_RUANGAN . $rm['foto']);
        $facilities = array_slice(explode(',', $rm['fasilitas'] ?? ''), 0, 3);
      ?>
      <div class="room-card reveal" style="transition-delay:<?= ($i%3)*.08 ?>s">
        <?php if ($hasPhoto): ?>
          <img class="room-card-img" src="<?= base_url('uploads/ruangan/'.e($rm['foto'])) ?>" alt="<?= e($rm['nama']) ?>" loading="lazy" decoding="async">
        <?php else: ?>
          <div class="room-card-placeholder <?= $rm['tipe']==='outdoor'?'outdoor':'' ?>"><?= $rm['tipe']==='outdoor'?'🌿':'🏢' ?></div>
        <?php endif; ?>
        <div class="room-card-body">
          <div class="room-card-name"><?= e($rm['nama']) ?></div>
          <div class="room-card-building">📍 <?= e($rm['gedung']) ?> · <?= e($rm['departemen']) ?></div>
          <div class="room-card-meta">
            <span class="type-badge <?= $rm['tipe']==='outdoor'?'type-outdoor':'type-indoor' ?>"><?= $rm['tipe']==='outdoor'?'🌿 Outdoor':'🏢 Indoor' ?></span>
            <span class="room-capacity">👥 <?= $rm['kapasitas'] ?> orang</span>
          </div>
          <?php if ($rm['fasilitas']): ?>
          <div class="facility-tags">
            <?php foreach ($facilities as $f): if(trim($f)): ?>
              <span class="facility-tag"><?= e(trim($f)) ?></span>
            <?php endif; endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="text-align:center;margin-top:44px">
      <a href="<?= base_url('login.php') ?>" class="btn-hero-secondary" style="font-size:15px;padding:13px 32px;display:inline-flex;align-items:center;gap:8px">Lihat Semua Ruangan →</a>
    </div>
  </div>
  </div><!-- /sec-inner -->
</section>

<!-- ══ CTA ══ -->
<section class="lp-cta reveal">
  <canvas class="sec-canvas" id="sc-cta"></canvas>
  <div class="sec-inner">
  <h2>Siap Booking Ruangan<br>Fakultas Sains Sekarang?</h2>
  <p>Login dengan akun email kampus dan mulai ajukan peminjaman dalam hitungan menit. Gratis, mudah, dan transparan.</p>
  <a href="<?= base_url('login.php') ?>" class="btn-cta">🚀 Masuk &amp; Mulai Booking</a>
  <div class="cta-features">
    <span>✅ Tidak Dipungut Biaya</span>
    <span>⚡ Notifikasi Real-time</span>
    <span>🔒 Aman dengan Email Kampus</span>
    <span>🏫 Fakultas Sains Official</span>
  </div>
  </div><!-- /sec-inner -->
</section>

<!-- ══ FOOTER ══ -->
<footer class="lp-footer">
  <div class="lp-footer-inner">
    <div class="footer-brand">
      <a href="<?= base_url() ?>" class="lp-logo">
        <svg width="36" height="36" viewBox="0 0 100 100"><circle cx="50" cy="50" r="48" fill="rgba(22,163,74,0.2)" stroke="rgba(74,222,128,0.3)" stroke-width="2"/><ellipse cx="50" cy="72" rx="21" ry="23" fill="#e8f5e9"/><circle cx="50" cy="40" r="19" fill="#e8f5e9"/><polygon points="33,24 28,12 40,22" fill="#e8f5e9"/><polygon points="67,24 72,12 60,22" fill="#e8f5e9"/><circle cx="41" cy="38" r="9" fill="#16a34a"/><circle cx="59" cy="38" r="9" fill="#16a34a"/><circle cx="41" cy="38" r="5" fill="#fbbf24"/><circle cx="59" cy="38" r="5" fill="#fbbf24"/><circle cx="41" cy="38" r="2.5" fill="#0c1a0e"/><circle cx="59" cy="38" r="2.5" fill="#0c1a0e"/><polygon points="50,46 44,53 56,53" fill="#d97706"/></svg>
        <div>
          <div class="lp-logo-name">Campus Facility Reservation</div>
          <div class="lp-logo-sub">Universitas Contoh</div>
        </div>
      </a>
      <p>Sistem Informasi Peminjaman Gedung dan Ruangan Fakultas Sains. Mempermudah proses booking ruangan secara digital dan transparan.</p>
      <div class="footer-social">
        <a href="#" title="Website Kampus">🌐</a>
        <a href="#" title="Instagram Fakultas Sains">📸</a>
        <a href="#" title="Email">📧</a>
      </div>
    </div>
    <div class="footer-col">
      <h4>Layanan</h4>
      <ul>
        <li><a href="<?= base_url('login.php') ?>">Booking Ruangan</a></li>
        <li><a href="<?= base_url('login.php') ?>">Cek Ketersediaan</a></li>
        <li><a href="<?= base_url('login.php') ?>">Rekomendasi AI</a></li>
        <li><a href="<?= base_url('login.php') ?>">Status Peminjaman</a></li>
      </ul>
    </div>
    <div class="footer-col">
      <h4>Area Outdoor</h4>
      <ul>
        <li><a href="#">Matematika</a></li>
        <li><a href="#">Fisika</a></li>
        <li><a href="#">Kimia</a></li>
        <li><a href="#">Biologi</a></li>
        <li><a href="#">Pendidikan IPA</a></li>
        <li><a href="#">Sains Terapan</a></li>
      </ul>
    </div>
    <div class="footer-col">
      <h4>Informasi</h4>
      <ul>
        <li><a href="#cara-booking">Cara Booking</a></li>
        <li><a href="<?= base_url('login.php') ?>">Login Mahasiswa</a></li>
        <li><a href="<?= base_url('login.php') ?>">Login Admin</a></li>
        <li><a href="#">Hubungi Kami</a></li>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">
    <p>&copy; <?= date('Y') ?> Campus Facility Reservation &mdash; Universitas Contoh. Seluruh hak dilindungi.</p>
    <p>🏫 Dikembangkan untuk mendukung kegiatan akademik Fakultas Sains</p>
  </div>
</footer>

<script>
/* ── Mobile nav ── */
function toggleMobileNav(){
  const nav = document.getElementById('mobile-nav');
  nav.style.display = nav.style.display === 'block' ? 'none' : 'block';
}
document.addEventListener('click', function(e){
  const nav = document.getElementById('mobile-nav');
  const btn = document.querySelector('.lp-nav-mobile-toggle');
  if (nav && btn && !nav.contains(e.target) && !btn.contains(e.target)) nav.style.display = 'none';
});

/* ── Navbar scroll shrink ── */
window.addEventListener('scroll', function(){
  document.getElementById('lp-nav').classList.toggle('scrolled', window.scrollY > 20);
});

/* ── Smooth scroll ── */
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', function(e){
    const t = document.querySelector(this.getAttribute('href'));
    if (t) { e.preventDefault(); t.scrollIntoView({behavior:'smooth'}); }
  });
});

/* ── Canvas particle animation (defer + skip mobile) ── */
(window.requestIdleCallback || function(cb){ setTimeout(cb, 200); })(function(){
  if(window.innerWidth < 768) return; // skip on mobile — hemat resource
  (function(){
    const canvas = document.getElementById('hero-canvas');
    if(!canvas) return;
    const ctx = canvas.getContext('2d');
    let W, H, particles = [], animId;
    function resize(){ W = canvas.width = canvas.offsetWidth; H = canvas.height = canvas.offsetHeight; }
    function Particle(){
      this.x = Math.random()*W; this.y = Math.random()*H;
      this.r = Math.random()*2.8+.8;
      this.vx = (Math.random()-.5)*.4; this.vy = (Math.random()-.5)*.4;
      this.alpha = Math.random()*.75+.25;
      const rnd = Math.random();
      if      (rnd<.55) this.color = `rgba(74,222,128,${this.alpha})`;
      else if (rnd<.80) this.color = `rgba(251,191,36,${this.alpha*.7})`;
      else              this.color = `rgba(134,239,172,${this.alpha*.6})`;
    }
    function init(){
      resize(); particles = [];
      // Kurangi jumlah partikel — lebih ringan
      for(let i=0;i<Math.floor(W*H/8000);i++) particles.push(new Particle());
    }
    function draw(){
      ctx.clearRect(0,0,W,H);
      for(let i=0;i<particles.length;i++){
        for(let j=i+1;j<particles.length;j++){
          const dx=particles[i].x-particles[j].x, dy=particles[i].y-particles[j].y;
          const dist=Math.sqrt(dx*dx+dy*dy);
          if(dist<120){
            ctx.beginPath();
            ctx.strokeStyle=`rgba(74,222,128,${.12*(1-dist/120)})`;
            ctx.lineWidth=.7;
            ctx.moveTo(particles[i].x,particles[i].y);
            ctx.lineTo(particles[j].x,particles[j].y);
            ctx.stroke();
          }
        }
      }
      particles.forEach(p=>{
        ctx.shadowBlur = p.r > 1.8 ? 8 : 0;
        ctx.shadowColor = 'rgba(74,222,128,.6)';
        ctx.beginPath(); ctx.arc(p.x,p.y,p.r,0,Math.PI*2);
        ctx.fillStyle=p.color; ctx.fill();
        ctx.shadowBlur = 0;
        p.x+=p.vx; p.y+=p.vy;
        if(p.x<-5)p.x=W+5; if(p.x>W+5)p.x=-5;
        if(p.y<-5)p.y=H+5; if(p.y>H+5)p.y=-5;
      });
      animId = requestAnimationFrame(draw);
    }
    window.addEventListener('resize', ()=>{ cancelAnimationFrame(animId); init(); draw(); });
    init(); draw();
  })();
});

/* ── Scroll reveal (IntersectionObserver) ── */
const observer = new IntersectionObserver(entries=>{
  entries.forEach(e=>{ if(e.isIntersecting) e.target.classList.add('visible'); });
},{threshold:.12});
document.querySelectorAll('.reveal').forEach(el=>observer.observe(el));

/* ── Counter animation ── */
function animateCounter(el){
  const target = parseInt(el.dataset.target)||0;
  const suf = el.dataset.suf||'';
  const dur = 1600, step = 16;
  let current = 0;
  const inc = target / (dur/step);
  const timer = setInterval(()=>{
    current = Math.min(current+inc, target);
    el.textContent = Math.floor(current) + suf;
    if(current >= target) clearInterval(timer);
  }, step);
}
const counterObs = new IntersectionObserver(entries=>{
  entries.forEach(e=>{
    if(e.isIntersecting){
      e.target.querySelectorAll('[data-target]').forEach(animateCounter);
      counterObs.unobserve(e.target);
    }
  });
},{threshold:.3});
document.querySelector('.lp-stats')&&counterObs.observe(document.querySelector('.lp-stats'));

/* ── Section canvas particles (ambient, per-section) ── */
(function(){
  function initSec(id, density){
    var canvas = document.getElementById(id);
    if (!canvas) return;
    var ctx = canvas.getContext('2d'), W, H, pts = [], animId, alive = false;

    function resize(){ W = canvas.width = canvas.offsetWidth; H = canvas.height = canvas.offsetHeight; }

    function Pt(){
      this.x  = Math.random()*W; this.y  = Math.random()*H;
      this.r  = Math.random()*1.7 + .3;
      this.vx = (Math.random()-.5)*.30; this.vy = (Math.random()-.5)*.30;
      this.a  = Math.random()*.42 + .08;
      var rnd = Math.random();
      this.col = rnd < .60 ? 'rgba(74,222,128,'+this.a+')'
               : rnd < .82 ? 'rgba(251,191,36,'+(this.a*.60)+')'
               :              'rgba(134,239,172,'+(this.a*.52)+')';
    }

    function init(){
      resize();
      pts = [];
      var n = Math.max(16, Math.floor(W * H / (density || 9000)));
      for (var i = 0; i < n; i++) pts.push(new Pt());
    }

    function draw(){
      if (!alive) return;
      ctx.clearRect(0, 0, W, H);
      pts.forEach(function(p){
        ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, Math.PI*2);
        ctx.fillStyle = p.col; ctx.fill();
        p.x += p.vx; p.y += p.vy;
        if (p.x < -4) p.x = W+4; if (p.x > W+4) p.x = -4;
        if (p.y < -4) p.y = H+4; if (p.y > H+4) p.y = -4;
      });
      animId = requestAnimationFrame(draw);
    }

    function start(){ if (alive) return; alive = true; init(); draw(); }
    function stop(){  alive = false; cancelAnimationFrame(animId); }

    /* Start/stop as section enters/leaves viewport — saves CPU */
    var obs = new IntersectionObserver(function(en){
      en.forEach(function(e){ e.isIntersecting ? start() : stop(); });
    }, { threshold: .04 });
    obs.observe(canvas.parentElement);

    window.addEventListener('resize', function(){ if (alive){ stop(); init(); start(); } });
  }

  initSec('sc-layanan', 9500);
  initSec('sc-cara',    9500);
  initSec('sc-ruangan', 9500);
  initSec('sc-cta',     7000);
})();
</script>

<?php
/* ══════════════════════════════════════════════════════════════
   WIDGET KONTAK WHATSAPP — nomor diatur Admin Utama
   (Kelola Pengguna → Kontak WhatsApp Landing Page)
   ══════════════════════════════════════════════════════════════ */
$wa_indoor  = waNormalize((string)(getSetting('landing_wa_indoor',  '') ?? ''));
$wa_outdoor = waNormalize((string)(getSetting('landing_wa_outdoor', '') ?? ''));
if ($wa_indoor || $wa_outdoor):
  $msg_indoor = rawurlencode(
      "Halo, Admin Fakultas Sains 🙏\n\n"
    . "Saya ingin mengajukan/menanyakan peminjaman ruangan *INDOOR* "
    . "(ruang kuliah, laboratorium, aula, atau ruang seminar) di Fakultas Sains Universitas Contoh.\n\n"
    . "• Nama: \n• Asal (Mahasiswa / Umum / Instansi): \n• Nama kegiatan: \n"
    . "• Tanggal & waktu: \n• Perkiraan jumlah peserta: \n\n"
    . "Mohon informasi ketersediaan ruangan beserta prosedur peminjamannya. Terima kasih."
  );
  $msg_outdoor = rawurlencode(
      "Halo, Admin DMF 🙏\n\n"
    . "Saya ingin mengajukan/menanyakan peminjaman area *OUTDOOR* "
    . "(lapangan, taman, gazebo, atau area terbuka) di Fakultas Sains Universitas Contoh.\n\n"
    . "• Nama: \n• Asal (Mahasiswa / Umum / Instansi): \n• Nama kegiatan: \n"
    . "• Tanggal & waktu: \n• Perkiraan jumlah peserta: \n\n"
    . "Mohon informasi ketersediaan area beserta prosedur peminjamannya. Terima kasih."
  );
?>
<style>
/* ── Widget WhatsApp ── */
.wa-fab-wrap{position:fixed;right:22px;bottom:22px;z-index:9990;display:flex;align-items:center;gap:12px}
.wa-fab-label{background:#fff;color:#1e293b;font-size:13.5px;padding:10px 16px;border-radius:30px;box-shadow:0 4px 20px rgba(0,0,0,.18);white-space:nowrap;animation:waFade .6s ease .8s both;cursor:pointer;user-select:none}
.wa-fab-label b{color:#128c4b}
.wa-fab{width:58px;height:58px;border-radius:50%;border:none;background:#25d366;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 22px rgba(37,211,102,.45);transition:transform .2s,box-shadow .2s;position:relative;flex-shrink:0}
.wa-fab:hover{transform:scale(1.07);box-shadow:0 8px 28px rgba(37,211,102,.55)}
.wa-fab::before{content:'';position:absolute;inset:-5px;border-radius:50%;border:2px solid rgba(37,211,102,.5);animation:waPulse 2.2s ease-out infinite}
.wa-fab svg{width:30px;height:30px;transition:opacity .18s,transform .18s;position:absolute}
.wa-fab .wa-ic-x{opacity:0;transform:rotate(-90deg) scale(.5);font-size:24px;font-weight:700;font-style:normal;position:absolute;transition:opacity .18s,transform .18s;line-height:1}
.wa-fab.open svg{opacity:0;transform:rotate(90deg) scale(.5)}
.wa-fab.open .wa-ic-x{opacity:1;transform:rotate(0) scale(1)}
@keyframes waPulse{0%{transform:scale(1);opacity:.9}70%{transform:scale(1.35);opacity:0}100%{transform:scale(1.35);opacity:0}}
@keyframes waFade{from{opacity:0;transform:translateX(10px)}to{opacity:1;transform:translateX(0)}}
.wa-panel{position:fixed;right:22px;bottom:94px;z-index:9991;width:360px;max-width:calc(100vw - 28px);background:#fff;border-radius:18px;box-shadow:0 14px 50px rgba(0,0,0,.3);overflow:hidden;opacity:0;transform:translateY(14px) scale(.97);pointer-events:none;transition:opacity .22s ease,transform .22s ease;font-family:'Inter',system-ui,sans-serif}
.wa-panel.open{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}
.wa-panel-hd{background:linear-gradient(135deg,#052e16 0%,#128c4b 70%,#16a34a 100%);color:#fff;padding:18px 18px 16px;display:flex;gap:12px;align-items:flex-start;position:relative}
.wa-hd-ic{width:42px;height:42px;border-radius:50%;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.wa-hd-ic svg{width:24px;height:24px}
.wa-hd-title{font-weight:800;font-size:16.5px;letter-spacing:.01em;font-family:'Plus Jakarta Sans','Inter',sans-serif}
.wa-hd-sub{font-size:12.5px;opacity:.88;line-height:1.55;margin-top:3px}
.wa-panel-x{position:absolute;top:10px;right:10px;background:rgba(255,255,255,.14);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:13px;line-height:1;transition:background .15s}
.wa-panel-x:hover{background:rgba(255,255,255,.28)}
.wa-panel-bd{padding:14px 14px 16px;background:#f6faf7}
.wa-reply{display:flex;align-items:center;gap:7px;font-size:11.5px;color:#64748b;margin-bottom:11px;padding:0 3px}
.wa-dot{width:8px;height:8px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.2);flex-shrink:0}
.wa-contact{display:flex;align-items:center;gap:12px;background:#fff;border:1.5px solid #e6efe9;border-radius:13px;padding:12px 13px;margin-bottom:9px;text-decoration:none;transition:border-color .16s,box-shadow .16s,transform .16s}
.wa-contact:hover{border-color:#25d366;box-shadow:0 5px 18px rgba(37,211,102,.16);transform:translateY(-1px)}
.wa-c-ic{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:21px;flex-shrink:0}
.wa-c-bd{flex:1;min-width:0}
.wa-c-title{display:block;font-weight:800;font-size:13.5px;color:#0f172a;line-height:1.25}
.wa-c-sub{display:block;font-size:11.5px;color:#64748b;margin-top:2px;line-height:1.45}
.wa-c-admin{display:inline-block;font-size:10.5px;font-weight:700;color:#128c4b;margin-top:4px}
.wa-c-go{width:32px;height:32px;border-radius:50%;background:#25d366;color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.wa-c-go svg{width:17px;height:17px}
.wa-public{background:#fffbeb;border:1px solid #fde68a;border-radius:11px;padding:11px 13px;font-size:11.5px;color:#78350f;line-height:1.65;margin-top:3px}
.wa-public b{color:#92400e}
@media(max-width:480px){
  .wa-fab-wrap{right:14px;bottom:14px}
  .wa-fab-label{font-size:12px;padding:8px 13px}
  .wa-panel{right:14px;bottom:84px}
}
</style>

<div class="wa-fab-wrap">
  <div class="wa-fab-label" id="wa-fab-label">Butuh Bantuan? <b>Chat dengan Kami</b></div>
  <button type="button" class="wa-fab" id="wa-fab" aria-label="Buka kontak WhatsApp" aria-expanded="false">
    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.297-.497.1-.198.05-.371-.025-.52-.074-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
    <i class="wa-ic-x">✕</i>
  </button>
</div>

<div class="wa-panel" id="wa-panel" role="dialog" aria-label="Kontak WhatsApp Campus Facility Reservation">
  <div class="wa-panel-hd">
    <div class="wa-hd-ic">
      <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.297-.497.1-.198.05-.371-.025-.52-.074-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
    </div>
    <div>
      <div class="wa-hd-title">Mulai Percakapan</div>
      <div class="wa-hd-sub">Hai! 👋 Pilih layanan di bawah untuk terhubung langsung dengan admin melalui <b>WhatsApp</b>.</div>
    </div>
    <button type="button" class="wa-panel-x" id="wa-panel-x" aria-label="Tutup">✕</button>
  </div>
  <div class="wa-panel-bd">
    <div class="wa-reply"><span class="wa-dot"></span> Tim kami biasanya membalas sesuai dengan jam kerja.</div>

    <?php if ($wa_indoor): ?>
    <a class="wa-contact" href="https://wa.me/<?= e($wa_indoor) ?>?text=<?= $msg_indoor ?>" target="_blank" rel="noopener">
      <span class="wa-c-ic" style="background:#eff6ff">🏢</span>
      <span class="wa-c-bd">
        <span class="wa-c-title">Peminjaman Ruangan Indoor</span>
        <span class="wa-c-sub">Ruang kuliah · Laboratorium · Aula · Ruang seminar</span>
        <span class="wa-c-admin">Dilayani Admin Fakultas Sains</span>
      </span>
      <span class="wa-c-go"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.297-.497.1-.198.05-.371-.025-.52-.074-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg></span>
    </a>
    <?php endif; ?>

    <?php if ($wa_outdoor): ?>
    <a class="wa-contact" href="https://wa.me/<?= e($wa_outdoor) ?>?text=<?= $msg_outdoor ?>" target="_blank" rel="noopener">
      <span class="wa-c-ic" style="background:#ecfdf5">🌿</span>
      <span class="wa-c-bd">
        <span class="wa-c-title">Peminjaman Area Outdoor</span>
        <span class="wa-c-sub">Lapangan · Taman · Gazebo · Area terbuka</span>
        <span class="wa-c-admin">Dilayani Admin DMF</span>
      </span>
      <span class="wa-c-go"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.297-.497.1-.198.05-.371-.025-.52-.074-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg></span>
    </a>
    <?php endif; ?>

    <div class="wa-public">
      🌐 <b>Terbuka untuk Umum &amp; Instansi Luar</b><br>
      Bukan mahasiswa kampus ini? Tidak masalah — peminjaman ruangan &amp; area bagi masyarakat umum,
      dosen, tenaga kependidikan, maupun instansi di luar Universitas Contoh
      dilayani langsung melalui kontak di atas. Admin kami siap membantu seluruh proses pengajuan Anda.
    </div>
  </div>
</div>

<script>
(function(){
  var fab   = document.getElementById('wa-fab'),
      label = document.getElementById('wa-fab-label'),
      panel = document.getElementById('wa-panel'),
      xBtn  = document.getElementById('wa-panel-x');
  function toggle(open){
    var willOpen = (typeof open === 'boolean') ? open : !panel.classList.contains('open');
    panel.classList.toggle('open', willOpen);
    fab.classList.toggle('open', willOpen);
    fab.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    label.style.display = willOpen ? 'none' : '';
  }
  fab.addEventListener('click', function(e){ e.stopPropagation(); toggle(); });
  label.addEventListener('click', function(e){ e.stopPropagation(); toggle(true); });
  xBtn.addEventListener('click', function(){ toggle(false); });
  document.addEventListener('click', function(e){
    if (panel.classList.contains('open') && !panel.contains(e.target)) toggle(false);
  });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') toggle(false); });
})();
</script>
<?php endif; ?>
</body>
</html>
