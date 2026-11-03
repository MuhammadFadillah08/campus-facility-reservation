<?php
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('user');
$page_title = 'Rekomendasi Ruangan';
$db = db();
$rooms = $db->query("SELECT * FROM ruangan WHERE tersedia=1 ORDER BY kapasitas ASC")->fetch_all(MYSQLI_ASSOC);

/**
 * SISTEM PENDUKUNG KEPUTUSAN — Metode AHP + SAW
 * --------------------------------------------------------------
 * Perankingan ruangan memakai metode SAW (Simple Additive Weighting):
 *   1) Skor tiap kriteria pada skala 1-5 (rubrik)
 *   2) Normalisasi benefit terhadap skala maksimum: r_ij = x_ij / 5
 *      (dibagi nilai skala tertinggi, BUKAN max kolom, agar nilai V
 *      mencerminkan kecocokan absolut — alternatif yang tidak memenuhi
 *      kriteria tidak bisa bernilai 1,000 hanya karena semua pesaingnya
 *      sama-sama tidak memenuhi)
 *   3) Nilai preferensi: V_i = Σ (w_j * r_ij)   → V maks = 1,000
 *
 * Bobot 6 kriteria diperoleh dari AHP (matriks perbandingan berpasangan
 * 6×6, uji konsistensi λmax=6,061; CI=0,012; CR=0,010 ≤ 0,1 → konsisten):
 *   C1=0.400  C2=0.253  C3=0.097  C4=0.060  C5=0.154  C6=0.036  (Σ = 1)
 */
const SKALA_MAKS = 5;
const KRITERIA = [
    'tipe'       => ['kode'=>'C1','nama'=>'Tipe Ruangan',       'ikon'=>'🏢','bobot'=>0.400,'warna'=>'#2563eb'],
    'kapasitas'  => ['kode'=>'C2','nama'=>'Kapasitas',          'ikon'=>'👥','bobot'=>0.253,'warna'=>'#7c3aed'],
    'proyektor'  => ['kode'=>'C3','nama'=>'Proyektor',          'ikon'=>'📽️','bobot'=>0.097,'warna'=>'#0891b2'],
    'ac'         => ['kode'=>'C4','nama'=>'Pendingin (AC)',     'ikon'=>'❄️','bobot'=>0.060,'warna'=>'#059669'],
    'departemen' => ['kode'=>'C5','nama'=>'Departemen',         'ikon'=>'🏫','bobot'=>0.154,'warna'=>'#d97706'],
    'tambahan'   => ['kode'=>'C6','nama'=>'Kebutuhan Tambahan', 'ikon'=>'📝','bobot'=>0.036,'warna'=>'#db2777'],
];

// Label tampilan departemen (konsisten dengan halaman lain)
$dept_label = ['Area Outdoor Fakultas Sains' => 'Admin DMF'];

/**
 * Daftar chip "pilih cepat" untuk C6 — daftar TETAP (hardcoded), diselaraskan
 * dengan penulisan fasilitas ruangan agar cocok saat pencocokan skor C6.
 * Untuk menambah/menghapus label: edit array di bawah ini.
 * @var array<int,array{0:string,1:string}> [ikon, label]
 */
$chipList = [
    ['🔊','Sound Speaker'], ['🎤','Mic'], ['🪟','Meja'], ['🪑','Kursi'], ['🛋️','Meja Podium'],
    ['📋','Whiteboard'], ['📺','TV / Monitor'], ['🔌','Kabel HDMI'], ['⚡','Kabel Extension'],
    ['🎥','Kamera / Tripod'], ['💡','Lampu Tambahan'], ['🖨️','Printer'],
];

/** Skor kapasitas (skala 1-5) berdasarkan jumlah peserta yang diinput. */
function skorKapasitas(int $kap, int $butuh): array {
    if ($butuh < 1) $butuh = 1;
    if ($kap < $butuh)        return [1, false, 'Kapasitas kurang ('.$kap.' kursi, butuh '.$butuh.' orang)'];
    if ($kap <= 1.2 * $butuh) return [5, true,  'Kapasitas pas & efisien ('.$kap.' kursi untuk '.$butuh.' orang)'];
    if ($kap <= 1.5 * $butuh) return [4, true,  'Kapasitas mencukupi ('.$kap.' kursi untuk '.$butuh.' orang)'];
    if ($kap <= 2.5 * $butuh) return [3, false, 'Kapasitas agak besar ('.$kap.' kursi, butuh '.$butuh.' orang)'];
    return                           [2, false, 'Kapasitas terlalu besar ('.$kap.' kursi, butuh '.$butuh.' orang)'];
}

/**
 * Metode AHP–SAW: matriks keputusan → normalisasi benefit → nilai V.
 * Mengembalikan seluruh alternatif terurut + 4 teratas untuk ditampilkan.
 */
function ahpSaw(array $rooms, array $ans): array {
    // ── 1. Filter alternatif sesuai tipe kegiatan (indoor/outdoor) ──
    $alts = [];
    foreach ($rooms as $r) {
        if (!empty($ans['tipe']) && $r['tipe'] !== $ans['tipe']) continue;
        $alts[] = $r;
    }
    if (!$alts) return ['ranked'=>[], 'all'=>[], 'jumlah'=>0];

    // Jumlah peserta (input angka manual dari form)
    $butuh = max(1, (int)($ans['kapasitas'] ?? 1));

    // Daftar kebutuhan tambahan (dipisah koma), untuk skoring C6
    $items = array_values(array_filter(array_map('trim', explode(',', $ans['kebutuhan_lain'] ?? '')), function($s){ return $s !== ''; }));

    // ── 2. Matriks keputusan X: skor tiap kriteria (skala 1-5) ──
    $rows = [];
    foreach ($alts as $r) {
        $fas = array_map('strtolower', array_map('trim', explode(',', $r['fasilitas'] ?? '')));
        $det = []; // detail per kriteria: skor, ok, alasan

        // C1 — Tipe (sudah difilter → selalu sesuai)
        $det['tipe'] = [5, true, 'Tipe '.($r['tipe']==='indoor'?'Indoor':'Outdoor').' sesuai kebutuhan'];

        // C2 — Kapasitas
        $det['kapasitas'] = skorKapasitas((int)$r['kapasitas'], $butuh);

        // C3 — Proyektor (netral = 3 bila tidak dibutuhkan)
        if (($ans['proyektor'] ?? '') === 'ya') {
            $det['proyektor'] = in_array('proyektor', $fas)
                ? [5, true,  'Tersedia proyektor sesuai kebutuhan']
                : [1, false, 'Tidak ada proyektor (dibutuhkan)'];
        } else {
            $det['proyektor'] = [3, true, 'Tidak menjadi prioritas (netral)'];
        }

        // C4 — AC (netral = 3 bila tidak dibutuhkan)
        if (($ans['ac'] ?? '') === 'ya') {
            $det['ac'] = in_array('ac', $fas)
                ? [5, true,  'Ruangan ber-AC sesuai kebutuhan']
                : [1, false, 'Tidak ada AC (dibutuhkan)'];
        } else {
            $det['ac'] = [3, true, 'Tidak menjadi prioritas (netral)'];
        }

        // C5 — Departemen (netral = 3 bila "semua")
        if (!empty($ans['departemen']) && $ans['departemen'] !== 'semua') {
            $det['departemen'] = ($r['departemen'] === $ans['departemen'])
                ? [5, true,  'Sesuai preferensi departemen']
                : [1, false, 'Berbeda departemen ('.$r['departemen'].')'];
        } else {
            $det['departemen'] = [3, true, 'Tanpa preferensi departemen (netral)'];
        }

        // C6 — Kebutuhan tambahan (netral = 3 bila tidak diisi).
        // Skor dari proporsi item yang cocok dengan fasilitas: 0%=1 ... 100%=5
        if ($items) {
            $fasStr = strtolower($r['fasilitas'] ?? '');
            $cocok  = 0;
            foreach ($items as $it) {
                if (strpos($fasStr, strtolower($it)) !== false) $cocok++;
            }
            $skor6 = 1 + (int)round(($cocok / count($items)) * 4);
            $det['tambahan'] = [$skor6, $cocok === count($items),
                $cocok.' dari '.count($items).' kebutuhan tambahan tersedia di fasilitas'];
        } else {
            $det['tambahan'] = [3, true, 'Tidak ada kebutuhan tambahan (netral)'];
        }

        $x = [];
        foreach ($det as $k => $d) $x[$k] = $d[0];
        $rows[] = ['room'=>$r, 'x'=>$x, 'det'=>$det];
    }

    // ── 3. Normalisasi r_ij = x_ij / 5 (skala maks) dan V_i = Σ (w_j * r_ij) ──
    //    Pembagi memakai nilai skala tertinggi agar V mencerminkan kecocokan
    //    absolut, bukan relatif terhadap pesaing yang kebetulan sama buruk.
    $scored = [];
    foreach ($rows as $row) {
        $r_ = []; $wr = []; $V = 0.0;
        foreach (KRITERIA as $k => $kr) {
            $r_[$k] = $row['x'][$k] / SKALA_MAKS;
            $wr[$k] = $kr['bobot'] * $r_[$k];
            $V     += $wr[$k];
        }
        // Kunci acak sebagai penentu urutan saat nilai V seri (lihat langkah 4)
        $scored[] = ['room'=>$row['room'], 'x'=>$row['x'], 'r'=>$r_, 'wr'=>$wr, 'det'=>$row['det'],
                     'score'=>$V, '_rnd'=>random_int(0, PHP_INT_MAX)];
    }

    // ── 4. Urutkan dari V tertinggi ──
    //    Alternatif dengan nilai V SAMA (mis. beberapa ruangan sama-sama 0,520)
    //    diurutkan secara ACAK, bukan mengikuti urutan data, agar tidak ada
    //    ruangan yang selalu diuntungkan menempati peringkat lebih tinggi.
    //    Toleransi 1e-9 hanya untuk meredam galat pembulatan bilangan pecahan.
    usort($scored, function($a, $b) {
        if (abs($a['score'] - $b['score']) < 1e-9) return $a['_rnd'] <=> $b['_rnd'];
        return $b['score'] <=> $a['score'];
    });

    return [
        'ranked' => array_slice($scored, 0, 4),
        'all'    => $scored,
        'jumlah' => count($alts),
    ];
}

$saw = ['ranked'=>[], 'all'=>[], 'jumlah'=>0];
$answers = []; $submitted = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $answers = [
        'tipe'           => $_POST['tipe'] ?? '',
        'kapasitas'      => $_POST['kapasitas'] ?? '',
        'proyektor'      => $_POST['proyektor'] ?? '',
        'ac'             => $_POST['ac'] ?? '',
        'departemen'     => $_POST['departemen'] ?? 'semua',
        'kebutuhan_lain' => trim($_POST['kebutuhan_lain'] ?? ''),
    ];
    $submitted = true;
    $saw = ahpSaw($rooms, $answers);
}
$results = $saw['ranked'];
require_once __DIR__.'/../includes/header.php';
?>
<div class="section-title">🎯 Rekomendasi Ruangan</div>
<div class="section-sub">Sistem Pendukung Keputusan dengan metode <strong>AHP</strong> (pembobotan kriteria) &amp; <strong>SAW</strong> (perankingan alternatif)</div>

<style>
/* ===== Panel metode AHP-SAW ===== */
.spk-hero{background:linear-gradient(135deg,#0b2a55 0%,#003d7a 55%,#0a4f8f 100%);border-radius:16px;padding:20px 22px;margin-bottom:20px;color:#fff;box-shadow:0 4px 16px rgba(0,61,122,.25)}
.spk-hero-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.spk-hero-title{font-weight:800;font-size:15.5px;display:flex;align-items:center;gap:8px}
.spk-cr{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.25);padding:4px 12px;border-radius:20px;font-size:11.5px;font-weight:700;display:flex;align-items:center;gap:6px}
.spk-cr b{color:#6ee7b7}
.spk-steps{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.spk-step{flex:1;min-width:150px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);border-radius:11px;padding:10px 12px;display:flex;gap:9px;align-items:flex-start}
.spk-step-num{width:22px;height:22px;border-radius:50%;background:#fbbf24;color:#0b2a55;font-weight:900;font-size:11.5px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
.spk-step b{font-size:12.5px;display:block;margin-bottom:2px}
.spk-step span{font-size:11px;opacity:.78;line-height:1.45;display:block}
.spk-wbar{display:flex;height:14px;border-radius:8px;overflow:hidden;margin-bottom:9px;box-shadow:inset 0 1px 3px rgba(0,0,0,.25)}
.spk-legend{display:flex;gap:6px;flex-wrap:wrap}
.spk-leg{display:flex;align-items:center;gap:6px;background:rgba(255,255,255,.1);border-radius:18px;padding:3px 10px 3px 5px;font-size:11.5px}
.spk-leg i{width:17px;height:17px;border-radius:50%;font-style:normal;font-size:8.5px;font-weight:900;display:flex;align-items:center;justify-content:center;color:#fff}
.spk-leg b{font-weight:800}

/* ===== Layout ===== */
.rekomen-wrap{display:grid;grid-template-columns:<?= $submitted ? '330px 1fr' : '1fr' ?>;gap:22px;align-items:start}
@media(max-width:900px){.rekomen-wrap{grid-template-columns:1fr!important}}
/* Izinkan kolom grid menyusut agar tabel/konten lebar men-scroll di dalam wadahnya
   (tanpa ini, kolom 1fr memelar melewati layar & konten terpotong) */
.rekomen-wrap>*{min-width:0}
/* Detail SAW: tabel dibungkus panel BERTINGGI TETAP → scrollbar horizontal SELALU
   terjangkau tanpa harus scroll sampai dasar tabel; baris banyak di-scroll vertikal
   di DALAM panel. Cocok walau ada ratusan/ribuan ruangan. */
.saw-detail .twrap{overflow:auto;max-height:60vh;-webkit-overflow-scrolling:touch}
/* Header kolom menempel saat scroll vertikal di dalam panel */
.saw-detail th{position:sticky;top:0;z-index:2}
.saw-detail{max-width:100%;overflow:hidden}

/* ===== Badge kriteria pada form ===== */
.crit-badge{color:#fff;font-size:10px;font-weight:900;padding:3px 8px;border-radius:6px;letter-spacing:.04em;flex-shrink:0}
.q-label{font-size:13.5px;font-weight:700;display:flex;gap:7px;align-items:center;margin-bottom:10px;color:#1e293b}
.q-weight{font-size:10.5px;font-weight:600;color:#94a3b8;margin-left:auto}
.opt-card{border:2px solid #e2e8f0;background:#fff;border-radius:12px;padding:11px;cursor:pointer;text-align:center;transition:all .15s}
.opt-card:hover{border-color:#bfdbfe}
.opt-card.on{border-color:var(--primary);background:#eff6ff}

/* ===== Kartu hasil ranking ===== */
.rk-card{background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.07);border:1px solid #e2e8f0;margin-bottom:15px;overflow:hidden}
.rk-head{padding:13px 16px;display:flex;align-items:center;gap:12px;border-bottom:1px solid #f0f4f8}
.rk-rank{width:38px;height:38px;border-radius:11px;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:16px;flex-shrink:0;box-shadow:0 2px 6px rgba(0,0,0,.18)}
.rk-V{text-align:right;flex-shrink:0}
.rk-V small{font-size:10.5px;color:#94a3b8;display:block;font-weight:700;letter-spacing:.03em}
.rk-V b{font-size:21px;font-weight:900;line-height:1.1}
.rk-body{display:flex}
.rk-photo{width:118px;flex-shrink:0}
.rk-photo img{width:118px;height:100%;min-height:130px;object-fit:cover;display:block}
.rk-content{padding:13px 16px;flex:1;min-width:0}
.rk-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.rk-chip{padding:3px 9px;border-radius:20px;font-size:11.5px;font-weight:700}
.crit-row{display:grid;grid-template-columns:34px 1fr 110px 34px;gap:8px;align-items:center;padding:4px 0}
.crit-row .cr-code{color:#fff;font-size:9.5px;font-weight:900;padding:2px 0;border-radius:5px;text-align:center}
.crit-row .cr-info{min-width:0}
.crit-row .cr-name{font-size:11.5px;font-weight:700;color:#334155}
.crit-row .cr-why{font-size:10.5px;line-height:1.35}
.crit-row .cr-why.ok{color:#059669}
.crit-row .cr-why.warn{color:#d97706}
.crit-row .cr-bar{height:7px;background:#eef2f7;border-radius:4px;overflow:hidden}
.crit-row .cr-bar i{display:block;height:100%;border-radius:4px}
.crit-row .cr-x{font-size:11px;font-weight:800;color:#475569;text-align:right}
.rk-vbar{height:6px;background:#e2e8f0;border-radius:3px;margin:10px 0}
.rk-vbar i{display:block;height:100%;border-radius:3px}

/* ===== Detail perhitungan SAW ===== */
.saw-toggle{width:100%;background:#fff;border:1px dashed #cbd5e1;border-radius:12px;padding:11px;font-size:13px;font-weight:700;color:#475569;cursor:pointer;transition:all .15s;font-family:inherit}
.saw-toggle:hover{border-color:var(--primary);color:var(--primary)}
.saw-detail{display:none;background:#fff;border-radius:14px;border:1px solid #e2e8f0;box-shadow:0 2px 10px rgba(0,0,0,.07);padding:16px;margin-top:12px}
.saw-detail.open{display:block}
.saw-detail h4{font-size:12px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.05em;margin:0 0 8px}
.saw-detail .twrap{overflow-x:auto;margin-bottom:16px}
.saw-detail table{width:100%;border-collapse:collapse;font-size:12px;min-width:560px}
.saw-detail th{background:#f8fafc;color:#475569;font-weight:800;padding:7px 9px;border:1px solid #e8edf3;text-align:center;white-space:nowrap}
.saw-detail td{padding:6px 9px;border:1px solid #eef2f7;text-align:center;color:#334155}
.saw-detail td.alt{text-align:left;font-weight:700;white-space:nowrap}
.saw-detail tr.top td{background:#fffbeb}
.saw-note{font-size:11.5px;color:#64748b;line-height:1.55;background:#f8fafc;border-radius:8px;padding:9px 12px}

@media(max-width:560px){
  .spk-hero{padding:16px}
  .spk-step{min-width:100%}
  .rk-body{flex-direction:column}
  .rk-photo{width:100%}
  .rk-photo img{width:100%;height:140px;min-height:0}
  .crit-row{grid-template-columns:32px 1fr 64px 30px}
}
</style>

<!-- ===== Panel Metode: bobot AHP + alur ===== -->
<div class="spk-hero">
  <div class="spk-hero-top">
    <div class="spk-hero-title">⚖️ Metode AHP + SAW</div>
    <div class="spk-cr">🧪 Uji Konsistensi AHP : <b>CR = 0,010</b>&nbsp;≤ 0,10 → Konsisten</div>
  </div>
  <div class="spk-steps">
    <div class="spk-step"><div class="spk-step-num">1</div><div><b>AHP — Pembobotan</b><span>Bobot tiap kriteria dihitung dari matriks perbandingan berpasangan.</span></div></div>
    <div class="spk-step"><div class="spk-step-num">2</div><div><b>Skoring Alternatif</b><span>Setiap ruangan dinilai per kriteria pada skala 1–5 sesuai rubrik.</span></div></div>
    <div class="spk-step"><div class="spk-step-num">3</div><div><b>SAW — Perankingan</b><span>Normalisasi r = x / 5, lalu V = Σ (bobot × r). V tertinggi = terbaik.</span></div></div>
  </div>
  <div class="spk-wbar">
    <?php foreach (KRITERIA as $k => $kr): ?>
    <div style="width:<?= $kr['bobot']*100 ?>%;background:<?= $kr['warna'] ?>" title="<?= $kr['kode'] ?> <?= $kr['nama'] ?> — <?= number_format($kr['bobot']*100,1,',','.') ?>%"></div>
    <?php endforeach; ?>
  </div>
  <div class="spk-legend">
    <?php foreach (KRITERIA as $k => $kr): ?>
    <div class="spk-leg"><i style="background:<?= $kr['warna'] ?>"><?= $kr['kode'] ?></i><?= $kr['ikon'] ?> <?= $kr['nama'] ?> <b><?= number_format($kr['bobot']*100,1,',','.') ?>%</b></div>
    <?php endforeach; ?>
  </div>
</div>

<div class="rekomen-wrap">

<!-- ===== FORM INPUT KRITERIA ===== -->
<div class="card">
  <div class="card-header">
    <span class="card-title">📋 Input Kriteria</span>
    <?php if($submitted): ?><a href="<?= base_url('user/rekomendasi.php') ?>" class="btn btn-secondary btn-sm">🔄 Ulang</a><?php endif; ?>
  </div>
  <div class="card-body">
    <?php if(!$submitted): ?>
    <div style="background:#f8faff;border:1px solid #e3ecfb;border-radius:10px;padding:11px 13px;margin-bottom:16px;font-size:12.5px;color:#475569;line-height:1.6">
      💡 Isi kebutuhan Anda pada tiap kriteria (C1–C5). Sistem akan menghitung nilai preferensi <strong>V</strong> setiap ruangan dan menampilkan <strong>4 rekomendasi terbaik</strong> lengkap dengan rincian perhitungannya.
    </div>
    <?php endif; ?>
    <form method="post">
      <!-- C1 Tipe -->
      <div class="form-group">
        <label class="q-label">
          <span class="crit-badge" style="background:<?= KRITERIA['tipe']['warna'] ?>">C1</span>
          Kegiatan diadakan di mana?
          <span class="q-weight">bobot <?= number_format(KRITERIA['tipe']['bobot']*100,1,',','.') ?>%</span>
        </label>
        <div style="display:flex;gap:8px">
          <?php foreach([['indoor','🏢','Indoor','Kuliah, seminar, rapat'],['outdoor','🌿','Outdoor','Lapangan, taman, gazebo']] as [$v,$ic,$lb,$sb]): ?>
          <label class="opt-card <?= ($answers['tipe']??'')===$v?'on':'' ?>" style="flex:1">
            <input type="radio" name="tipe" value="<?= $v ?>" <?= ($answers['tipe']??'')===$v?'checked':'' ?> required style="display:none">
            <div style="font-size:24px"><?= $ic ?></div>
            <div style="font-weight:700;font-size:13px;margin-top:4px"><?= $lb ?></div>
            <div style="font-size:11px;color:#94a3b8"><?= $sb ?></div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <!-- C2 Kapasitas -->
      <div class="form-group">
        <label class="q-label">
          <span class="crit-badge" style="background:<?= KRITERIA['kapasitas']['warna'] ?>">C2</span>
          Perkiraan jumlah peserta?
          <span class="q-weight">bobot <?= number_format(KRITERIA['kapasitas']['bobot']*100,1,',','.') ?>%</span>
        </label>
        <div style="position:relative">
          <input type="number" name="kapasitas" class="form-control" min="1" max="5000" step="1" required
            inputmode="numeric" placeholder="contoh: 40"
            value="<?= e($answers['kapasitas'] ?? '') ?>" style="padding-right:64px">
          <span style="position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:12.5px;color:#94a3b8;font-weight:700;pointer-events:none">orang</span>
        </div>
        <div style="font-size:11.5px;color:#94a3b8;margin-top:5px">Ketik jumlah peserta — sistem menilai kesesuaian kapasitas tiap ruangan (pas = 5, terlalu kecil/besar = skor turun)</div>
      </div>
      <!-- C3 & C4 khusus indoor -->
      <div id="indoor-q" style="display:none">
        <div class="form-group">
          <label class="q-label">
            <span class="crit-badge" style="background:<?= KRITERIA['proyektor']['warna'] ?>">C3</span>
            Butuh proyektor/presentasi?
            <span class="q-weight">bobot <?= number_format(KRITERIA['proyektor']['bobot']*100,1,',','.') ?>%</span>
          </label>
          <div style="display:flex;gap:8px">
            <?php foreach([['ya','📽️','Ya, butuh'],['tidak','📝','Tidak perlu']] as [$v,$ic,$lb]): ?>
            <label class="opt-card <?= ($answers['proyektor']??'')===$v?'on':'' ?>" style="flex:1;padding:10px">
              <input type="radio" name="proyektor" value="<?= $v ?>" <?= ($answers['proyektor']??'')===$v?'checked':'' ?> style="display:none">
              <div style="font-size:22px"><?= $ic ?></div>
              <div style="font-weight:700;font-size:12.5px;margin-top:3px"><?= $lb ?></div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group">
          <label class="q-label">
            <span class="crit-badge" style="background:<?= KRITERIA['ac']['warna'] ?>">C4</span>
            Butuh ruangan ber-AC?
            <span class="q-weight">bobot <?= number_format(KRITERIA['ac']['bobot']*100,1,',','.') ?>%</span>
          </label>
          <div style="display:flex;gap:8px">
            <?php foreach([['ya','❄️','Ya, perlu'],['tidak','🌀','Tidak wajib']] as [$v,$ic,$lb]): ?>
            <label class="opt-card <?= ($answers['ac']??'')===$v?'on':'' ?>" style="flex:1;padding:10px">
              <input type="radio" name="ac" value="<?= $v ?>" <?= ($answers['ac']??'')===$v?'checked':'' ?> style="display:none">
              <div style="font-size:22px"><?= $ic ?></div>
              <div style="font-weight:700;font-size:12.5px;margin-top:3px"><?= $lb ?></div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <!-- C5 Departemen -->
      <div class="form-group">
        <label class="q-label">
          <span class="crit-badge" style="background:<?= KRITERIA['departemen']['warna'] ?>">C5</span>
          Preferensi departemen?
          <span class="q-weight">bobot <?= number_format(KRITERIA['departemen']['bobot']*100,1,',','.') ?>%</span>
        </label>
        <select name="departemen" class="form-control">
          <option value="semua" <?= ($answers['departemen']??'semua')==='semua'?'selected':'' ?>>🏛️ Semua / Tidak Ada Preferensi</option>
          <?php foreach(['Matematika','Fisika','Kimia','Biologi','Pendidikan IPA','Sains Terapan','Admin Fakultas Sains','Area Outdoor Fakultas Sains'] as $d): ?>
          <option value="<?= $d ?>" <?= ($answers['departemen']??'')===$d?'selected':'' ?>><?= e($dept_label[$d] ?? $d) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <!-- Kebutuhan lain (di luar kriteria SPK) -->
      <div class="form-group">
        <label class="q-label">
          <span class="crit-badge" style="background:<?= KRITERIA['tambahan']['warna'] ?>">C6</span>
          Kebutuhan lain-lain?
          <span style="font-size:11.5px;font-weight:400;color:#94a3b8;margin-left:2px">(opsional)</span>
          <span class="q-weight">bobot <?= number_format(KRITERIA['tambahan']['bobot']*100,1,',','.') ?>% — terendah</span>
        </label>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:9px" id="chip-wrap">
          <?php foreach($chipList as [$ic,$lb]): ?>
          <button type="button" class="req-chip" onclick="toggleChip(this)"
            data-chip="<?= e($lb) ?>"
            style="background:#f1f5f9;border:1.5px solid #e2e8f0;color:#475569;padding:5px 11px;border-radius:20px;font-size:12px;cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:5px">
            <span><?= $ic ?></span><span><?= e($lb) ?></span>
          </button>
          <?php endforeach; ?>
        </div>
        <textarea name="kebutuhan_lain" id="kebutuhan_lain" rows="3"
          placeholder="Tulis kebutuhan tambahan secara bebas... contoh: Sound Speaker, Mic, Meja Podium, Kursi, dll."
          style="width:100%;padding:10px 13px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;color:#1e293b;resize:vertical;font-family:inherit;outline:none;transition:border-color .15s;line-height:1.6;box-sizing:border-box"
          onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='#e2e8f0'"><?= e($answers['kebutuhan_lain'] ?? '') ?></textarea>
        <div style="font-size:11.5px;color:#94a3b8;margin-top:5px">💡 Klik chip untuk pilih cepat, atau ketik bebas di kotak di atas</div>
      </div>
      <button type="submit" class="btn btn-primary btn-block btn-lg">⚖️ Hitung Rekomendasi (AHP–SAW)</button>
    </form>
  </div>
</div>

<!-- ===== HASIL PERANKINGAN ===== -->
<?php if($submitted): ?>
<div>
  <div style="font-weight:800;font-size:16px;color:var(--primary);margin-bottom:4px">🏆 Hasil Perankingan SAW</div>
  <?php $kap_butuh = max(1, (int)$answers['kapasitas']); ?>
  <div style="font-size:13px;color:#64748b;margin-bottom:14px">
    <strong><?= $saw['jumlah'] ?></strong> alternatif tipe <strong><?= $answers['tipe']==='indoor'?'Indoor':'Outdoor' ?></strong> dievaluasi · menampilkan <strong><?= count($results) ?></strong> teratas
  </div>

  <!-- Ringkasan input kriteria -->
  <div style="background:#fff;border-radius:12px;padding:13px 16px;margin-bottom:18px;box-shadow:0 2px 8px rgba(0,0,0,.07);border:1px solid #e2e8f0">
    <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Kriteria yang Anda Pilih</div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <?php
      $sumChips = [
        ['tipe',       $answers['tipe']==='indoor'?'🏢 Indoor':'🌿 Outdoor'],
        ['kapasitas',  '👥 '.$kap_butuh.' orang'],
      ];
      if ($answers['tipe']==='indoor') {
        if (!empty($answers['proyektor'])) $sumChips[] = ['proyektor','📽️ Proyektor: '.($answers['proyektor']==='ya'?'Butuh':'Tidak')];
        if (!empty($answers['ac']))        $sumChips[] = ['ac','❄️ AC: '.($answers['ac']==='ya'?'Butuh':'Tidak')];
      }
      $sumChips[] = ['departemen','🏫 '.($answers['departemen']==='semua'?'Semua departemen':($dept_label[$answers['departemen']] ?? $answers['departemen']))];
      if (!empty($answers['kebutuhan_lain'])) $sumChips[] = ['tambahan','📝 '.mb_strimwidth($answers['kebutuhan_lain'],0,40,'…')];
      foreach ($sumChips as [$ck,$lbl]): $kr = KRITERIA[$ck]; ?>
      <span style="display:inline-flex;align-items:center;gap:6px;background:#f8fafc;border:1px solid #e8edf3;padding:4px 11px 4px 5px;border-radius:20px;font-size:12px;font-weight:700;color:#334155">
        <span style="background:<?= $kr['warna'] ?>;color:#fff;font-size:9px;font-weight:900;padding:2px 7px;border-radius:10px"><?= $kr['kode'] ?></span><?= e($lbl) ?>
      </span>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if(empty($results)): ?>
  <div class="card"><div class="empty-state"><div class="icon">😔</div><h3>Tidak ada ruangan yang cocok</h3><p>Coba ubah beberapa kriteria.</p></div></div>
  <?php else: ?>
  <?php
    $rankColor = ['#f59e0b','#64748b','#b45309','#94a3b8'];
    $rankBg    = ['linear-gradient(135deg,#f59e0b,#d97706)','linear-gradient(135deg,#94a3b8,#64748b)','linear-gradient(135deg,#d97c3f,#b45309)','linear-gradient(135deg,#cbd5e1,#94a3b8)'];
  ?>
  <?php foreach($results as $i=>$item): $r=$item['room']; $V=$item['score']; $pctV=(int)round($V*100); ?>
  <div class="rk-card">
    <div class="rk-head" style="background:<?= $i===0?'#fffbeb':'#fafbfc' ?>">
      <div class="rk-rank" style="background:<?= $rankBg[$i] ?>"><?= $i+1 ?></div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:800;font-size:14.5px"><?= e($r['nama']) ?></div>
        <div style="font-size:12px;color:#64748b"><?= e($r['gedung']) ?></div>
      </div>
      <?php if($i===0): ?><span style="background:#fef3c7;color:#b45309;padding:4px 10px;border-radius:20px;font-size:11.5px;font-weight:800;flex-shrink:0">⭐ Terbaik</span><?php endif; ?>
      <div class="rk-V">
        <small>NILAI V</small>
        <b style="color:<?= $rankColor[$i] ?>"><?= number_format($V,3,',','.') ?></b>
      </div>
    </div>
    <div class="rk-body">
      <div class="rk-photo">
        <?php $fp=UPLOAD_RUANGAN.($r['foto']??''); ?>
        <?php if($r['foto']&&file_exists($fp)): ?>
          <img src="<?= base_url('uploads/ruangan/'.e($r['foto'])) ?>" alt="<?= e($r['nama']) ?>">
        <?php else: ?>
          <div style="width:100%;height:100%;min-height:130px;background:<?= $r['tipe']==='outdoor'?'#ecfdf5':'#eff6ff' ?>;display:flex;align-items:center;justify-content:center;font-size:40px"><?= $r['tipe']==='outdoor'?'🌿':'🏢' ?></div>
        <?php endif; ?>
      </div>
      <div class="rk-content">
        <div class="rk-chips">
          <span class="rk-chip" style="background:#eff6ff;color:#1e40af">👥 <?= $r['kapasitas'] ?> orang</span>
          <span class="rk-chip" style="background:<?= $r['tipe']==='outdoor'?'#ecfdf5':'#eff6ff' ?>;color:<?= $r['tipe']==='outdoor'?'#065f46':'#1e40af' ?>"><?= $r['tipe']==='outdoor'?'🌿 Outdoor':'🏢 Indoor' ?></span>
          <span class="rk-chip" style="background:#f1f5f9;color:#475569">🏫 <?= e($dept_label[$r['departemen']] ?? $r['departemen']) ?></span>
        </div>
        <?php if($r['fasilitas']): ?>
        <div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:10px">
          <?php foreach(explode(',',$r['fasilitas']) as $f): ?><span style="background:#f8fafc;border:1px solid #e2e8f0;color:#475569;padding:2px 7px;border-radius:6px;font-size:11.5px"><?= e(trim($f)) ?></span><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <!-- Breakdown skor per kriteria -->
        <div style="background:#fafbfd;border:1px solid #eef2f7;border-radius:10px;padding:8px 12px;margin-bottom:10px">
          <?php foreach(KRITERIA as $k=>$kr): [$xs,$ok,$why]=$item['det'][$k]; ?>
          <div class="crit-row">
            <span class="cr-code" style="background:<?= $kr['warna'] ?>"><?= $kr['kode'] ?></span>
            <div class="cr-info">
              <div class="cr-name"><?= $kr['ikon'] ?> <?= $kr['nama'] ?></div>
              <div class="cr-why <?= $ok?'ok':'warn' ?>"><?= $ok?'✓':'⚠' ?> <?= e($why) ?></div>
            </div>
            <div class="cr-bar"><i style="width:<?= $xs*20 ?>%;background:<?= $kr['warna'] ?>"></i></div>
            <span class="cr-x"><?= $xs ?>/5</span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if(!empty($answers['kebutuhan_lain'])): ?>
        <div style="font-size:12px;color:#6d28d9;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:7px;padding:6px 10px;margin-bottom:8px;display:flex;gap:6px;align-items:flex-start">
          <span style="flex-shrink:0">📝</span><span><strong>Catatan kebutuhan:</strong> <?= e($answers['kebutuhan_lain']) ?></span>
        </div>
        <?php endif; ?>
        <div class="rk-vbar"><i style="width:<?= $pctV ?>%;background:<?= $rankBg[$i] ?>"></i></div>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <span style="font-size:11.5px;color:#94a3b8;font-weight:600">V = Σ (bobot × r) = <strong style="color:<?= $rankColor[$i] ?>"><?= number_format($V,3,',','.') ?></strong> dari maks. 1,000</span>
          <a href="<?= base_url('user/booking.php?room='.$r['id']) ?>" class="btn btn-primary btn-sm">📋 Pesan Ruangan Ini &rarr;</a>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- ===== Detail perhitungan SAW (transparansi metode) ===== -->
  <button type="button" class="saw-toggle" onclick="document.getElementById('saw-detail').classList.toggle('open');this.textContent=document.getElementById('saw-detail').classList.contains('open')?'🔼 Sembunyikan Detail Perhitungan SAW':'🔬 Lihat Detail Perhitungan SAW (Matriks Keputusan & Normalisasi)'">🔬 Lihat Detail Perhitungan SAW (Matriks Keputusan &amp; Normalisasi)</button>
  <div id="saw-detail" class="saw-detail">
    <h4>1 · Matriks Keputusan — Skor x<sub>ij</sub> (skala 1–5)</h4>
    <div class="twrap">
      <table>
        <tr><th style="text-align:left">Alternatif</th><?php foreach(KRITERIA as $kr): ?><th><span style="color:<?= $kr['warna'] ?>"><?= $kr['kode'] ?></span><br><small style="font-weight:600"><?= $kr['nama'] ?></small></th><?php endforeach; ?></tr>
        <?php foreach($saw['all'] as $idx=>$row): ?>
        <tr class="<?= $idx<4?'top':'' ?>">
          <td class="alt"><?= $idx<4 ? '<span style="color:'.$rankColor[$idx].';font-weight:900">#'.($idx+1).'</span> ' : '' ?><?= e($row['room']['nama']) ?></td>
          <?php foreach(KRITERIA as $k=>$_): ?><td><?= $row['x'][$k] ?></td><?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        <tr><td class="alt" style="background:#f1f5f9">x<sub>maks</sub> (pembagi)</td><?php foreach(KRITERIA as $k=>$_): ?><td style="background:#f1f5f9;font-weight:800"><?= SKALA_MAKS ?></td><?php endforeach; ?></tr>
      </table>
    </div>
    <h4>2 · Normalisasi r<sub>ij</sub> = x<sub>ij</sub> / 5 &nbsp;→&nbsp; Nilai Preferensi V<sub>i</sub> = Σ (w<sub>j</sub> × r<sub>ij</sub>)</h4>
    <div class="twrap">
      <table>
        <tr><th style="text-align:left">Alternatif</th><?php foreach(KRITERIA as $kr): ?><th><span style="color:<?= $kr['warna'] ?>"><?= $kr['kode'] ?></span><br><small style="font-weight:600">w = <?= number_format($kr['bobot'],3,',','.') ?></small></th><?php endforeach; ?><th style="background:#fef3c7;color:#b45309">Nilai V</th></tr>
        <?php foreach($saw['all'] as $idx=>$row): ?>
        <tr class="<?= $idx<4?'top':'' ?>">
          <td class="alt"><?= $idx<4 ? '<span style="color:'.$rankColor[$idx].';font-weight:900">#'.($idx+1).'</span> ' : '' ?><?= e($row['room']['nama']) ?></td>
          <?php foreach(KRITERIA as $k=>$_): ?><td><?= number_format($row['r'][$k],3,',','.') ?></td><?php endforeach; ?>
          <td style="font-weight:900;color:#b45309;background:#fffbeb"><?= number_format($row['score'],3,',','.') ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <div class="saw-note">
      <strong>Keterangan:</strong> Semua kriteria bersifat <em>benefit</em> (semakin besar semakin baik). Normalisasi dibagi nilai skala tertinggi (5) agar nilai V mencerminkan tingkat kecocokan absolut — V = 1,000 hanya jika seluruh kriteria terpenuhi sempurna. Keenam bobot diperoleh dari AHP (matriks perbandingan berpasangan 6×6) dengan rasio konsistensi CR = 0,010 ≤ 0,10 (konsisten); C6 Kebutuhan Tambahan memiliki bobot terendah (0,036). Baris berlatar kuning adalah 4 alternatif dengan nilai preferensi V tertinggi yang direkomendasikan. Apabila beberapa ruangan memperoleh nilai V yang sama, urutannya ditentukan secara acak agar tidak ada ruangan yang selalu diuntungkan menempati peringkat lebih tinggi.
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
</div>

<script>
function toggleIndoorQ(){
  const tipe=document.querySelector('input[name=tipe]:checked')?.value;
  const box=document.getElementById('indoor-q');
  if(box)box.style.display=tipe==='indoor'?'':'none';
}

// Toggle chip pilihan cepat: klik = aktif (tambah ke textarea), klik lagi = non-aktif (hapus dari textarea)
function toggleChip(btn){
  const ta=document.getElementById('kebutuhan_lain');
  const chip=btn.dataset.chip;
  const isActive=btn.classList.contains('chip-active');
  if(isActive){
    const regex=new RegExp('(,\\s*)?'+chip.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'(\\s*,)?','gi');
    ta.value=ta.value.replace(regex,'').replace(/^[,\s]+|[,\s]+$/g,'').replace(/,\s*,/g,',').trim();
    btn.classList.remove('chip-active');
    btn.style.background='#f1f5f9';btn.style.color='#475569';btn.style.borderColor='#e2e8f0';
  } else {
    const curr=ta.value.trim();
    ta.value=curr?curr+', '+chip:chip;
    btn.classList.add('chip-active');
    btn.style.background='var(--primary)';btn.style.color='#fff';btn.style.borderColor='var(--primary)';
  }
  ta.focus();
}

// Sinkronkan status chip dengan isi textarea (saat halaman reload setelah submit)
function syncChips(){
  const ta=document.getElementById('kebutuhan_lain');
  if(!ta) return;
  const val=ta.value.toLowerCase();
  document.querySelectorAll('.req-chip').forEach(function(btn){
    const chip=btn.dataset.chip.toLowerCase();
    if(val.indexOf(chip)>-1){
      btn.classList.add('chip-active');
      btn.style.background='var(--primary)';btn.style.color='#fff';btn.style.borderColor='var(--primary)';
    }
  });
}

document.querySelectorAll('input[type=radio]').forEach(r=>{
  r.addEventListener('change',function(){
    const n=this.name;
    document.querySelectorAll('input[name="'+n+'"]').forEach(x=>{
      const l=x.closest('label');if(!l)return;
      l.classList.toggle('on',x.checked);
    });
    if(n==='tipe')toggleIndoorQ();
  });
});
document.addEventListener('DOMContentLoaded',toggleIndoorQ);
document.addEventListener('DOMContentLoaded',syncChips);
</script>
<?php require_once __DIR__.'/../includes/footer.php'; ?>
