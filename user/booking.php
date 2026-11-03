<?php
require_once __DIR__ . '/../includes/config.php';
$user = requireRole('user');
/* Cegah halaman (beserta JS inline) di-cache browser/proxy → script ketersediaan selalu versi terbaru */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$page_title = 'Ajukan Peminjaman';
$db = db();
ensureColumn($db, 'users', 'no_wa', "VARCHAR(50) DEFAULT NULL AFTER email");
ensureColumnWidth($db, 'users', 'no_wa', 50, "VARCHAR(50) DEFAULT NULL");
ensureColumn($db, 'users', 'wa_groups', "TEXT DEFAULT NULL AFTER no_wa");
ensureColumn($db, 'peminjaman', 'asal_organisasi', "VARCHAR(150) DEFAULT NULL AFTER nama_kegiatan");

/* State interstitial WhatsApp setelah submit berhasil */
$wa_url = '';
$wa_admin_label = '';
$wa_room_name   = '';
$wa_links       = [];   // daftar tujuan WA (DMF bisa 2 nomor, lainnya 1)
$wa_group_url   = '';   // khusus DMF: bila kolom WA berisi link Grup WhatsApp
$wa_group_no    = 0;    // khusus DMF: nomor grup (1..4) hasil routing asal ormawa
$wa_text        = '';   // isi pesan konfirmasi (untuk disalin pada mode grup)

$preselect      = (int)($_GET['room']           ?? 0);
$preselect_date = $_GET['tanggal_cek']           ?? '';
$preselect_jam1 = $_GET['jam_mulai_cek']         ?? '';
$preselect_jam2 = $_GET['jam_selesai_cek']       ?? '';
$errors = [];

/* ================================================================
   Peta NIM → Departemen + Prodi
   Kode 5 digit = substr(NIM, 2, 5)
   Contoh: NIM 2403126 → kode 03126 → Matematika / S1 Matematika
================================================================ */
$PRODI_MAP = [
    '03116' => ['departemen' => 'Matematika',     'prodi' => 'S1 Pendidikan Matematika'],
    '03126' => ['departemen' => 'Matematika',     'prodi' => 'S1 Matematika'],
    '03146' => ['departemen' => 'Matematika',     'prodi' => 'S1 Sains Aktuaria'],
    '03216' => ['departemen' => 'Fisika',         'prodi' => 'S1 Pendidikan Fisika'],
    '03226' => ['departemen' => 'Fisika',         'prodi' => 'S1 Fisika'],
    '03316' => ['departemen' => 'Kimia',          'prodi' => 'S1 Pendidikan Kimia'],
    '03326' => ['departemen' => 'Kimia',          'prodi' => 'S1 Kimia'],
    '03416' => ['departemen' => 'Biologi',        'prodi' => 'S1 Pendidikan Biologi'],
    '03426' => ['departemen' => 'Biologi',        'prodi' => 'S1 Biologi'],
    '03436' => ['departemen' => 'Sains Terapan',  'prodi' => 'S1 Bioteknologi'],
    '03446' => ['departemen' => 'Biologi',        'prodi' => 'S1 Gizi'],
    '03516' => ['departemen' => 'Pendidikan IPA', 'prodi' => 'S1 Pendidikan IPA'],
];

/* ── Deteksi departemen & prodi dari email ── */
function detectProdiFromEmail(string $email, array $map): array {
    $local = explode('@', $email)[0];          // nama.mahasiswa.2403126
    $parts = explode('.', $local);
    $nim_digits = '';
    foreach (array_reverse($parts) as $p) {
        if (ctype_digit($p) && strlen($p) >= 7) { $nim_digits = $p; break; }
    }
    if (!$nim_digits) return ['departemen' => '', 'prodi' => '', 'nim_email' => ''];
    $code = substr($nim_digits, 2, 5);         // ambil 5 digit kode prodi
    $info = $map[$code] ?? ['departemen' => '', 'prodi' => ''];
    return ['departemen' => $info['departemen'], 'prodi' => $info['prodi'], 'nim_email' => $nim_digits];
}

$detected = detectProdiFromEmail($user['email'], $PRODI_MAP);
$auto_dept  = $detected['departemen'];
$auto_prodi = $detected['prodi'];

/* ── Jika DB belum punya departemen/prodi, langsung update ── */
if ((!$user['departemen'] || !$user['prodi']) && $auto_dept) {
    $upd = $db->prepare("UPDATE users SET departemen=?, prodi=? WHERE id=?");
    $upd->bind_param('ssi', $auto_dept, $auto_prodi, $user['id']);
    $upd->execute();
    $_SESSION['user']['departemen'] = $auto_dept;
    $_SESSION['user']['prodi']      = $auto_prodi;
    $user['departemen'] = $auto_dept;
    $user['prodi']      = $auto_prodi;
}

/* ================================================================
   POST Handler
================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $room_id   = (int)$_POST['ruangan_id'];
    $nama_keg  = trim($_POST['nama_kegiatan']  ?? '');
    $asal_org  = trim($_POST['asal_organisasi'] ?? '');
    if ($asal_org === '') $asal_org = '-';
    $tgl_mulai = $_POST['tanggal_mulai']        ?? '';
    $tgl_sel   = $_POST['tanggal_selesai']      ?? '';
    $jam_mul   = $_POST['jam_mulai']            ?? '';
    $jam_sel   = $_POST['jam_selesai']          ?? '';
    $peserta   = (int)$_POST['jumlah_peserta'];
    $ket       = trim($_POST['keterangan']      ?? '');
    $nim_input = trim($_POST['nim_pemohon']     ?? '');

    /* Deteksi tipe & nama ruangan sebelum validasi */
    $room_tipe = ''; $room_nama = '';
    if ($room_id) {
        $rTipeRow  = $db->query("SELECT tipe,nama FROM ruangan WHERE id=$room_id")->fetch_assoc();
        $room_tipe = $rTipeRow['tipe'] ?? '';
        $room_nama = $rTipeRow['nama'] ?? '';
    }
    $is_outdoor  = ($room_tipe === 'outdoor');
    $wajib_surat = roomWajibSurat($room_tipe, $room_nama);   // indoor + outdoor tertentu

    /* Validasi */
    if (!$room_id)   $errors[] = 'Pilih ruangan terlebih dahulu.';
    if (!$nama_keg)  $errors[] = 'Nama kegiatan wajib diisi.';
    if (!$tgl_mulai) $errors[] = 'Tanggal mulai wajib diisi.';
    if ($tgl_sel < $tgl_mulai) $errors[] = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
    if ($tgl_mulai && $tgl_sel && $jam_mul && $jam_sel) {
        if ($tgl_mulai === $tgl_sel && $jam_sel <= $jam_mul)
            $errors[] = 'Jam selesai harus lebih dari jam mulai pada hari yang sama.';
    }
    if (!$nim_input) {
        $errors[] = 'NIM wajib diisi.';
    } elseif (!ctype_digit($nim_input) || strlen($nim_input) < 7 || strlen($nim_input) > 12) {
        $errors[] = 'NIM tidak valid (harus 7–12 digit angka).';
    }
    /* Surat wajib untuk indoor + ruangan outdoor tertentu (Kanopi Taman Sains, Kantin, Loket, Parkiran) */
    if ($wajib_surat) {
        if (empty($_FILES['file_surat']['name']) || $_FILES['file_surat']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File surat peminjaman wajib diupload.';
        }
    }

    /* Upload file surat bila dilampirkan (untuk semua ruangan yang menerimanya) */
    $file_name = '';
    if (empty($errors) && !empty($_FILES['file_surat']['name'])) {
        $file = $_FILES['file_surat'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $uploadErr = validateUpload($file, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']);
        if ($uploadErr !== '') {
            $errors[] = $uploadErr;
        } else {
            $file_name = 'surat_' . $user['id'] . '_' . time() . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], UPLOAD_SURAT . $file_name)) {
                $errors[] = 'Gagal upload file surat.';
            }
        }
    }

    /* Simpan ke DB */
    if (empty($errors)) {
        $uid = (int)$user['id'];

        /* Update NIM & departemen di tabel users */
        $upd2 = $db->prepare("UPDATE users SET nim=?, departemen=?, prodi=? WHERE id=?");
        $upd2->bind_param('sssi', $nim_input, $auto_dept, $auto_prodi, $uid);
        $upd2->execute();
        $_SESSION['user']['nim']        = $nim_input;
        $_SESSION['user']['departemen'] = $auto_dept;
        $_SESSION['user']['prodi']      = $auto_prodi;

        /* Insert peminjaman */
        $s = $db->prepare(
            "INSERT INTO peminjaman
               (user_id,ruangan_id,nama_kegiatan,asal_organisasi,tanggal_mulai,tanggal_selesai,
                jam_mulai,jam_selesai,jumlah_peserta,keterangan,file_surat,status)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,'pending')"
        );
        $s->bind_param('iisssssssss',
            $uid, $room_id, $nama_keg, $asal_org, $tgl_mulai, $tgl_sel,
            $jam_mul, $jam_sel, $peserta, $ket, $file_name
        );
        $s->execute();
        $new_pid = $db->insert_id;

        /* ── Kirim notifikasi ke admin yang relevan ── */
        $rInfo = $db->query("SELECT nama, gedung, departemen, tipe FROM ruangan WHERE id=$room_id")->fetch_assoc();
        if ($rInfo) {
            if ($rInfo['tipe'] === 'outdoor') {
                $adminRows = $db->query("SELECT id FROM users WHERE role IN ('admin_utama','admin_dmf')")->fetch_all(MYSQLI_ASSOC);
            } else {
                $dept_e = $db->real_escape_string($rInfo['departemen']);
                $adminRows = $db->query("SELECT id FROM users WHERE role='admin_utama' OR (role='admin_departemen' AND departemen='$dept_e')")->fetch_all(MYSQLI_ASSOC);
            }
            $notif_link = base_url('admin/bookings.php');
            $pesan_notif = $nama_keg . ' — ' . e($rInfo['nama']) . ' oleh ' . $user['nama'];
            foreach ($adminRows as $adm) {
                pushNotif($db, (int)$adm['id'],
                    'Pengajuan Peminjaman Baru',
                    $pesan_notif,
                    'info',
                    $notif_link
                );
            }

            /* ── Cari nomor WA admin penanggung jawab (untuk konfirmasi WhatsApp) ──
               Khusus DMF (outdoor): kumpulkan hingga 2 nomor → konfirmasi ke keduanya
               sekaligus. Admin lain cukup 1 nomor. Sumber tetap kolom no_wa yang
               dikelola admin; satu kolom no_wa boleh memuat 2 nomor (pisah , ; / atau baris). */
            $max_wa  = ($rInfo['tipe'] === 'outdoor') ? 2 : 1;
            $wa_rows = [];
            if ($rInfo['tipe'] === 'outdoor') {
                $wa_admin_label = 'Admin DMF';
                $wa_rows = $db->query("SELECT nama,no_wa FROM users WHERE role='admin_dmf' AND no_wa IS NOT NULL AND no_wa<>'' ORDER BY id")->fetch_all(MYSQLI_ASSOC);

                /* ── Routing 4 Grup WhatsApp DMF berdasarkan asal ormawa pemohon ── */
                $dmfRow   = $db->query("SELECT no_wa, wa_groups FROM users WHERE role='admin_dmf' ORDER BY id LIMIT 1")->fetch_assoc();
                $grpLinks = ($dmfRow && isset($dmfRow['wa_groups']) && $dmfRow['wa_groups'] !== '')
                          ? preg_split('/\r\n|\r|\n/', $dmfRow['wa_groups'])
                          : [];
                $pickGrp = function (int $i) use ($grpLinks) {   // link grup ke-$i (1-based) bila link grup valid
                    $lk = trim($grpLinks[$i - 1] ?? '');
                    return preg_match('~https?://chat\.whatsapp\.com/\S+~i', $lk, $m) ? $m[0] : '';
                };
                $grpIdx = dmfGroupIndexForOrmawa($asal_org);     // 1..4, atau 0 bila tak cocok
                if (!$grpIdx) $grpIdx = 1;                        // "-" / manual / tak terdaftar → Grup 1
                if (($lk = $pickGrp($grpIdx)) !== '') {
                    $wa_group_url = $lk; $wa_group_no = $grpIdx;
                } else {                                          // bila link grup itu kosong → grup terisi pertama
                    for ($i = 1; $i <= 4; $i++) { if (($lk = $pickGrp($i)) !== '') { $wa_group_url = $lk; $wa_group_no = $i; break; } }
                }
                /* Fallback lama: satu link grup tunggal yang ditaruh di kolom no_wa */
                if ($wa_group_url === '') {
                    foreach ($wa_rows as $wr) {
                        if (preg_match('~https?://chat\.whatsapp\.com/\S+~i', $wr['no_wa'], $gm)) { $wa_group_url = $gm[0]; break; }
                    }
                }
            } else {
                $dept_e3 = $db->real_escape_string($rInfo['departemen']);
                $wa_rows = $db->query("SELECT nama,no_wa FROM users WHERE role='admin_departemen' AND departemen='$dept_e3' AND no_wa IS NOT NULL AND no_wa<>'' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
                $wa_admin_label = 'Admin Departemen ' . $rInfo['departemen'];
            }
            if (empty($wa_rows) && $wa_group_url === '') {
                $wa_rows = $db->query("SELECT nama,no_wa FROM users WHERE role='admin_utama' AND no_wa IS NOT NULL AND no_wa<>'' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
                if ($wa_rows) $wa_admin_label = 'Admin Utama Fakultas Sains';
            }

            /* Pecah tiap no_wa (boleh berisi >1 nomor), normalisasi, buang duplikat, batasi $max_wa.
               Dilewati bila sudah mode grup (link grup bukan nomor). */
            $wa_numbers = [];
            if (!$wa_group_url) {
                foreach ($wa_rows as $wr) {
                    foreach (preg_split('/[,;\/\n]+/', $wr['no_wa']) as $piece) {
                        $n = waNormalize(trim($piece));
                        if ($n && !in_array($n, array_column($wa_numbers, 'no'), true)) {
                            $wa_numbers[] = ['no' => $n, 'nama' => $wr['nama']];
                            if (count($wa_numbers) >= $max_wa) break 2;
                        }
                    }
                }
            }

            if ($wa_group_url || !empty($wa_numbers)) {
                $f_tgl_mulai = date('d-m-Y', strtotime($tgl_mulai));
                $f_tgl_sel   = date('d-m-Y', strtotime($tgl_sel));
                $f_periode   = ($f_tgl_mulai === $f_tgl_sel) ? $f_tgl_mulai : "$f_tgl_mulai s.d. $f_tgl_sel";
                $wa_room_name = $rInfo['nama'];

                /* DMF (outdoor) = sesama mahasiswa → sapaan dibedakan.
                   Penutup menyebut surat HANYA bila surat memang diunggah. */
                $is_dmf = ($rInfo['tipe'] === 'outdoor');
                $sapaan = $is_dmf ? 'Saudara/i' : 'Bapak/Ibu';
                $penutup_validasi = ($file_name !== '')
                    ? "Surat permohonan resmi telah saya unggah melalui sistem Campus Facility Reservation. Besar harapan saya $sapaan berkenan untuk memvalidasi pengajuan ini."
                    : "Demikian permohonan ini saya sampaikan. Besar harapan saya $sapaan berkenan meninjau dan memberikan persetujuan atas pengajuan peminjaman ini.";

                $lines = [
                    "Assalamualaikum Wr. Wb.",
                    "Yth. " . $sapaan . " " . $wa_admin_label,
                    "",
                    "Dengan hormat,",
                    "Saya yang mengajukan peminjaman ruangan/gedung di bawah ini bermaksud menyampaikan permohonan dengan rincian sebagai berikut:",
                    "",
                    "*DATA PEMOHON*",
                    "Nama          : " . $user['nama'],
                    "NIM           : " . $nim_input,
                    "Program Studi : " . ($auto_prodi ?: ($user['prodi'] ?? '-')),
                    "Departemen    : " . ($auto_dept ?: ($user['departemen'] ?? '-')),
                    "Asal Organisasi: " . $asal_org,
                    "Email         : " . $user['email'],
                    "",
                    "*DETAIL PEMINJAMAN*",
                    "Ruangan/Gedung : " . $rInfo['nama'] . " (" . $rInfo['gedung'] . ")",
                    "Nama Kegiatan  : " . $nama_keg,
                    "Tanggal        : " . $f_periode,
                    "Waktu          : " . $jam_mul . " - " . $jam_sel . " WIB",
                    "Jumlah Peserta : " . $peserta . " orang",
                    "Keterangan     : " . ($ket !== '' ? $ket : '-'),
                    "",
                    $penutup_validasi,
                    "",
                    "Atas perhatian dan kesediaan " . $sapaan . ", saya sampaikan terima kasih.",
                    "",
                    "Wassalamualaikum Wr. Wb.",
                    "Hormat saya,",
                    $user['nama'],
                ];
                $wa_text = implode("\n", $lines);

                if ($wa_group_url) {
                    /* Mode grup (khusus DMF): link grup tidak bisa membawa pesan otomatis,
                       jadi pesan disalin manual lalu ditempel di grup. */
                    $wa_url = $wa_group_url; // penanda ada tujuan
                } else {
                    $multi = count($wa_numbers) > 1;
                    foreach ($wa_numbers as $i => $wn) {
                        $wa_links[] = [
                            'url'   => 'https://wa.me/' . $wn['no'] . '?text=' . rawurlencode($wa_text),
                            'label' => $multi ? ('Nomor ' . ($i + 1)) : '',
                            'nama'  => $wn['nama'],
                        ];
                    }
                    $wa_url = $wa_links[0]['url']; // penanda minimal 1 link (kompat alur lama)
                }
            }
        }

        if ($wa_url) {
            /* Tampilkan halaman antara → arahkan ke WhatsApp admin */
            flash('success', 'Pengajuan berhasil dikirim! Silakan kirim konfirmasi ke admin via WhatsApp.');
        } else {
            /* Tidak ada nomor WA admin → alur lama */
            flash('success', 'Pengajuan berhasil dikirim! Mohon tunggu validasi admin.');
            redirect(base_url('user/my-bookings.php'));
        }
    }
}

/* ── Data ruangan ── */
$rooms   = $db->query("SELECT * FROM ruangan WHERE tersedia=1 ORDER BY tipe,departemen,nama")->fetch_all(MYSQLI_ASSOC);
$roomMap = [];
foreach ($rooms as $rm) $roomMap[$rm['id']] = ['dept' => $rm['departemen'], 'tipe' => $rm['tipe']];

/* Nilai NIM di form: dari POST, atau dari DB, atau kosong */
$nim_val = $_POST['nim_pemohon'] ?? ($user['nim'] ?? '');

require_once __DIR__ . '/../includes/header.php';
?>
<style>
/* ── Room Search Picker ── */
.room-picker{position:relative}
.room-picker-search{display:flex;align-items:center;gap:8px;border:1.5px solid #e2e8f0;border-radius:10px;padding:0 12px;background:#fff;transition:border-color .2s,box-shadow .2s;min-height:44px;cursor:text}
.room-picker-search:focus-within{border-color:var(--primary-light);box-shadow:0 0 0 3px rgba(22,163,74,.12)}
.room-picker-search-icon{font-size:15px;flex-shrink:0;color:#94a3b8;pointer-events:none}
.room-picker-search-field{flex:1;border:none;outline:none;font-size:14px;color:#1e293b;background:transparent;font-family:inherit;padding:11px 0}
.room-picker-search-field::placeholder{color:#94a3b8}
.room-picker-clear-btn{background:none;border:none;cursor:pointer;color:#94a3b8;font-size:14px;padding:4px 6px;border-radius:6px;line-height:1;transition:all .15s;flex-shrink:0}
.room-picker-clear-btn:hover{background:#fee2e2;color:#dc2626}
.room-picker-dropdown{position:absolute;top:calc(100% + 5px);left:0;right:0;background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 36px rgba(0,0,0,.13);z-index:600;max-height:340px;overflow-y:auto}
.room-picker-group-hd{padding:8px 14px 5px;font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.07em;background:#fff;border-bottom:1px solid #f1f5f9;position:sticky;top:0;z-index:1}
.room-picker-item{padding:10px 14px;cursor:pointer;transition:background .12s;border-bottom:1px solid #f8fafc;display:flex;align-items:flex-start;gap:10px}
.room-picker-item:last-child{border-bottom:none}
.room-picker-item:hover,.room-picker-item.rp-focus{background:#f0fdf4}
.room-picker-item.rp-focus .rpi-name{color:var(--primary)}
.rpi-icon{font-size:20px;flex-shrink:0;margin-top:1px}
.rpi-body{flex:1;min-width:0}
.rpi-name{font-weight:700;font-size:13.5px;color:#1e293b;margin-bottom:4px;line-height:1.3}
.rpi-meta{display:flex;gap:5px;flex-wrap:wrap;align-items:center}
.rp-badge{padding:2px 8px;border-radius:20px;font-size:11.5px;font-weight:600;white-space:nowrap}
.rp-badge.kap{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.rp-badge.dept{background:#fdf4ff;border:1px solid #e9d5ff;color:#7c3aed}
.rp-badge.indoor{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
.rp-badge.outdoor{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
.rp-badge.gedung{background:#f8fafc;border:1px solid #e2e8f0;color:#475569}
.rp-empty{padding:24px 16px;text-align:center;color:#94a3b8}
.rp-empty-ic{font-size:30px;margin-bottom:6px}
mark.rp-hl{background:#fef08a;border-radius:2px;color:inherit;padding:0}
/* Selected state */
.room-picker-selected{display:flex;align-items:center;gap:12px;padding:12px 14px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px}
.rps-icon{font-size:24px;flex-shrink:0}
.rps-body{flex:1;min-width:0}
.rps-name{font-weight:700;font-size:14px;color:#166534;margin-bottom:2px}
.rps-meta{font-size:12px;color:#15803d}
.rps-change{flex-shrink:0;background:#fff;border:1.5px solid #22c55e;color:#16a34a;padding:7px 13px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;transition:all .15s}
.rps-change:hover{background:#22c55e;color:#fff}
/* ── Organisasi Picker (combobox kreatif) ── */
.org-picker{position:relative}
.org-picker-field{display:flex;align-items:center;gap:9px;border:1.5px solid #e2e8f0;border-radius:10px;padding:0 6px 0 13px;background:#fff;transition:border-color .2s,box-shadow .2s;min-height:46px;cursor:text}
.org-picker-field:focus-within{border-color:var(--primary-light);box-shadow:0 0 0 3px rgba(22,163,74,.12)}
.org-picker-icon{font-size:18px;flex-shrink:0;pointer-events:none}
.org-picker-input{flex:1;border:none;outline:none;font-size:14px;color:#1e293b;background:transparent;font-family:inherit;padding:12px 0;font-weight:600;min-width:0}
.org-picker-input::placeholder{color:#94a3b8;font-weight:400}
.org-picker-caret{background:none;border:none;cursor:pointer;color:#94a3b8;font-size:13px;padding:8px 9px;border-radius:6px;transition:transform .25s,color .15s;flex-shrink:0;line-height:1}
.org-picker.open .org-picker-caret{transform:rotate(180deg);color:var(--primary)}
.org-picker-dropdown{position:absolute;top:calc(100% + 6px);left:0;right:0;background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;box-shadow:0 14px 42px rgba(0,0,0,.16);z-index:600;max-height:330px;overflow-y:auto;padding:6px;animation:orgPop .14s ease}
@keyframes orgPop{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.org-cat-hd{padding:9px 12px 5px;font-size:10.5px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em}
.org-item{display:flex;align-items:center;gap:11px;padding:9px 12px;cursor:pointer;border-radius:10px;transition:background .12s}
.org-item:hover,.org-item.org-focus{background:#f0fdf4}
.org-item-ic{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0}
.org-item-ic.umum{background:#f1f5f9}
.org-item-ic.lembaga{background:#dbeafe}
.org-item-ic.hmd{background:#ede9fe}
.org-item-ic.hmps{background:#fce7f3}
.org-item-ic.ukm{background:#fef3c7}
.org-item-body{flex:1;min-width:0}
.org-item-name{font-weight:700;font-size:13.5px;color:#1e293b;line-height:1.25}
.org-item-sub{font-size:11px;color:#94a3b8;margin-top:1px}
.org-item-check{color:#16a34a;font-weight:800;font-size:15px;opacity:0;flex-shrink:0}
.org-item.org-selected{background:#ecfdf5}
.org-item.org-selected .org-item-check{opacity:1}
.org-custom{display:flex;align-items:center;gap:9px;padding:11px 12px;cursor:pointer;border-radius:10px;background:#fffbeb;border:1px dashed #fcd34d;margin:5px 4px 2px;font-size:13px;color:#92400e;font-weight:600}
.org-custom:hover{background:#fef3c7}
.org-empty{padding:22px 14px;text-align:center;color:#94a3b8;font-size:13px}
mark.org-hl{background:#fef08a;border-radius:2px;padding:0;color:inherit}
/* ── Interstitial WhatsApp ── */
.wa-done-card{max-width:560px;margin:30px auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.08);overflow:hidden}
.wa-done-head{background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;padding:28px 24px;text-align:center}
.wa-done-head .ic{font-size:46px;line-height:1;margin-bottom:8px}
.wa-done-head h2{margin:0;font-size:19px;font-weight:800}
.wa-done-head p{margin:6px 0 0;font-size:13.5px;opacity:.92}
.wa-done-body{padding:24px}
.wa-done-info{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 16px;font-size:13.5px;color:#166534;line-height:1.7;margin-bottom:18px}
.wa-btn{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;padding:15px;background:#25D366;color:#fff;font-size:15.5px;font-weight:800;border:none;border-radius:12px;text-decoration:none;box-shadow:0 6px 18px rgba(37,211,102,.35);transition:transform .15s,box-shadow .15s}
.wa-btn:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(37,211,102,.45)}
.wa-btn:active{transform:translateY(0)}
.wa-sub-link{display:block;text-align:center;margin-top:14px;color:#64748b;font-size:13px;text-decoration:none;font-weight:600}
.wa-sub-link:hover{color:#16a34a}
</style>

<?php if ($wa_url): ?>
<!-- ══════════ HALAMAN ANTARA: KONFIRMASI WHATSAPP ══════════ -->
<div class="wa-done-card">
  <div class="wa-done-head">
    <div class="ic">✅</div>
    <h2>Pengajuan Berhasil Dikirim!</h2>
    <p>Pengajuan Anda sudah tersimpan dan menunggu validasi.</p>
  </div>
  <div class="wa-done-body">
    <?php if ($wa_group_url): ?>
      <!-- ── Mode GRUP WhatsApp (khusus Admin DMF) ── -->
      <div class="wa-done-info">
        📱 Konfirmasi ke <strong>Admin DMF</strong> dilakukan melalui <strong>Grup WhatsApp</strong>
        (pengajuan <strong><?= e($wa_room_name) ?></strong>).
        <br><br><strong>3 langkah singkat:</strong>
        <ol style="margin:6px 0 0 18px;padding:0;line-height:1.9">
          <li>Tekan <strong>📋 Salin Pesan</strong> (pesan tersalin otomatis).</li>
          <li>Tekan <strong>💬 Buka Grup WhatsApp DMF</strong>.</li>
          <li>Di dalam grup, <strong>tempel (paste)</strong> pesan lalu kirim.</li>
        </ol>
      </div>
      <textarea id="wa-msg-box" readonly rows="8"
        style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-size:12.5px;font-family:inherit;color:#334155;background:#f8fafc;resize:vertical;margin-bottom:10px"><?= e($wa_text) ?></textarea>
      <button type="button" class="wa-btn" style="background:#0ea5e9;box-shadow:0 6px 18px rgba(14,165,233,.35);margin-bottom:10px" onclick="salinPesan(this)">
        <span style="font-size:18px">📋</span> Salin Pesan
      </button>
      <a href="<?= e($wa_group_url) ?>" target="_blank" rel="noopener" class="wa-btn" id="wa-send-btn">
        <span style="font-size:20px">💬</span> Buka Grup WhatsApp DMF
      </a>

    <?php else: ?>
      <!-- ── Mode NOMOR (admin departemen/utama, atau DMF tanpa link grup) ── -->
      <div class="wa-done-info">
        📱 Langkah terakhir: kirim <strong>konfirmasi otomatis</strong> ke
        <strong><?= e($wa_admin_label) ?></strong> melalui WhatsApp.
        Pesan sudah dibuat otomatis lengkap dengan data pengajuan
        <strong><?= e($wa_room_name) ?></strong> — Anda tinggal menekan kirim.
        <?php if (count($wa_links) > 1): ?>
        <br><br>⚠️ Khusus <strong>Admin DMF</strong> terdapat
        <strong><?= count($wa_links) ?> nomor</strong>. Tekan tombol di bawah —
        <strong>kedua chat WhatsApp akan terbuka sekaligus</strong>, tinggal tekan kirim di masing-masing.
        <?php endif; ?>
      </div>
      <?php if (count($wa_links) > 1): ?>
        <!-- DMF: satu tombol → buka kedua chat WhatsApp sekaligus -->
        <button type="button" class="wa-btn" id="wa-send-btn" onclick="kirimWaSemua()">
          <span style="font-size:20px">💬</span> Kirim Konfirmasi ke <?= count($wa_links) ?> Nomor (Admin DMF)
        </button>
        <div id="wa-fallback" style="display:none;margin-top:12px;font-size:13px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 14px"></div>
        <div style="margin-top:10px;font-size:12.5px;color:#64748b;text-align:center">
          Atau kirim manual ke:
          <?php foreach ($wa_links as $i => $wl): ?><a href="<?= e($wl['url']) ?>" target="_blank" rel="noopener" style="color:#16a34a;font-weight:600;text-decoration:underline">Nomor <?= $i + 1 ?></a><?= $i < count($wa_links) - 1 ? ' · ' : '' ?><?php endforeach; ?>
        </div>
      <?php else: ?>
        <a href="<?= e($wa_links[0]['url']) ?>" target="_blank" rel="noopener" class="wa-btn" id="wa-send-btn">
          <span style="font-size:20px">💬</span> Kirim Konfirmasi via WhatsApp
        </a>
      <?php endif; ?>
    <?php endif; ?>
    <a href="<?= base_url('user/my-bookings.php') ?>" class="wa-sub-link">Lewati &amp; lihat Peminjaman Saya →</a>
  </div>
</div>
<script>
(function(){
  var waUrls = <?= json_encode(array_column($wa_links, 'url')) ?>;

  /* Satu klik → buka semua chat WhatsApp sekaligus.
     window.open di dalam handler klik = user gesture → tab pertama pasti lolos;
     bila browser memblokir tab berikutnya, tampilkan link cadangan untuk diklik manual. */
  window.kirimWaSemua = function(){
    var blocked = [];
    waUrls.forEach(function(u){
      var w = window.open(u, '_blank');
      if (!w) blocked.push(u);
    });
    if (blocked.length){
      var fb = document.getElementById('wa-fallback');
      if (fb){
        fb.style.display = 'block';
        fb.innerHTML = '⚠️ Browser memblokir sebagian tab. Klik untuk membuka: ' +
          blocked.map(function(u){ return '<a href="'+u+'" target="_blank" rel="noopener" style="color:#16a34a;font-weight:700">Nomor '+(waUrls.indexOf(u)+1)+'</a>'; }).join(' · ');
      }
    }
  };

  /* Salin pesan ke clipboard (mode Grup WhatsApp DMF) */
  window.salinPesan = function(btn){
    var ta = document.getElementById('wa-msg-box');
    if (!ta) return;
    var txt = ta.value, done = false;
    if (navigator.clipboard && window.isSecureContext){
      navigator.clipboard.writeText(txt).then(function(){ tandaiSalin(btn); }).catch(function(){ fallbackSalin(ta, btn); });
    } else {
      fallbackSalin(ta, btn);
    }
    function fallbackSalin(ta, btn){
      ta.removeAttribute('readonly');
      ta.select(); ta.setSelectionRange(0, 999999);
      try { document.execCommand('copy'); } catch(e){}
      ta.setAttribute('readonly', 'readonly');
      tandaiSalin(btn);
    }
    function tandaiSalin(btn){
      if (!btn) return;
      var html = btn.innerHTML;
      btn.innerHTML = '<span style="font-size:18px">✅</span> Pesan Tersalin!';
      setTimeout(function(){ btn.innerHTML = html; }, 1800);
    }
  };

  /* Auto-buka hanya bila 1 nomor (perilaku lama). Untuk 2 nomor / mode grup, biarkan user menekan tombol. */
  if (waUrls.length === 1){
    setTimeout(function(){ try { window.open(waUrls[0], '_blank', 'noopener'); } catch(e){} }, 700);
  }
})();
</script>

<?php else: ?>
<div class="section-title">📋 Formulir Peminjaman</div>
<div class="section-sub">Isi formulir berikut untuk mengajukan peminjaman gedung/ruangan Fakultas Sains</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
  <strong>⚠️ Perbaiki kesalahan:</strong>
  <ul style="margin:6px 0 0 16px">
    <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">

  <!-- ── Data Pemohon ── -->
  <div class="card mb-4">
    <div class="card-header"><span class="card-title">👤 Data Pemohon</span></div>
    <div class="card-body">
      <div class="form-grid-2">

        <div class="form-group">
          <label>Email Kampus</label>
          <input type="text" class="form-control" value="<?= e($user['email']) ?>" disabled>
        </div>

        <div class="form-group">
          <label>Nama Lengkap</label>
          <input type="text" class="form-control" value="<?= e($user['nama']) ?>" disabled>
        </div>

        <div class="form-group">
          <label>Departemen</label>
          <input type="text" class="form-control"
                 value="<?= e($auto_dept ?: ($user['departemen'] ?? '—')) ?>"
                 disabled
                 style="background:#f0fdf4;color:#166534;font-weight:600">
          <input type="hidden" name="departemen_detected" value="<?= e($auto_dept) ?>">
        </div>

        <div class="form-group">
          <label>Program Studi</label>
          <input type="text" class="form-control"
                 value="<?= e($auto_prodi ?: ($user['prodi'] ?? '—')) ?>"
                 disabled
                 style="background:#f0fdf4;color:#166534;font-weight:600">
          <input type="hidden" name="prodi_detected" value="<?= e($auto_prodi) ?>">
        </div>

        <div class="form-group" style="grid-column:1/-1">
          <label>NIM <span style="color:#dc2626">*</span></label>
          <input type="text" name="nim_pemohon" class="form-control"
                 placeholder="Masukkan NIM Anda (contoh: 2403126xxxxx)"
                 value="<?= e($nim_val) ?>"
                 inputmode="numeric"
                 maxlength="12"
                 required
                 <?= $nim_val ? '' : 'autofocus' ?>>
          <div class="form-text">NIM sesuai kartu mahasiswa (7–12 digit angka).</div>
        </div>

      </div>

      <?php if (!$auto_dept): ?>
      <div class="alert alert-warning" style="margin-top:12px">
        ⚠️ Departemen tidak terdeteksi otomatis dari email Anda.
        Pastikan email kampus sudah benar atau hubungi admin Fakultas Sains.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Data Kegiatan & Ruangan ── -->
  <div class="card mb-4">
    <div class="card-header"><span class="card-title">🎯 Data Kegiatan &amp; Ruangan</span></div>
    <div class="card-body">
      <div class="form-group">
        <label>Nama Kegiatan *</label>
        <input type="text" name="nama_kegiatan" class="form-control"
               placeholder="Contoh: Seminar Nasional Matematika 2026"
               value="<?= e($_POST['nama_kegiatan'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>Asal Organisasi *</label>
        <div class="org-picker" id="org-picker">
          <div class="org-picker-field" id="org-field" onclick="document.getElementById('org-input').focus()">
            <span class="org-picker-icon" id="org-icon">🏢</span>
            <input type="text" name="asal_organisasi" id="org-input" class="org-picker-input"
                   autocomplete="off" spellcheck="false"
                   placeholder="Pilih dari daftar atau ketik sendiri…"
                   value="<?= e($_POST['asal_organisasi'] ?? '') ?>" required>
            <button type="button" class="org-picker-caret" id="org-caret" tabindex="-1" title="Buka daftar">▾</button>
          </div>
          <div class="org-picker-dropdown" id="org-dropdown" style="display:none"></div>
        </div>
        <div class="form-text">Pilih organisasi dari daftar, jika organisasi tidak ada dalam daftar bisa ketik manual. Jika bukan dari organisasi, isi tanda <strong>-</strong></div>
      </div>
      <div class="form-group">
        <label>Pilih Ruangan <span style="color:#dc2626">*</span></label>
        <div class="room-picker" id="room-picker">
          <!-- Kotak pencarian -->
          <div class="room-picker-search" id="rp-search-wrap" onclick="document.getElementById('rp-input').focus()">
            <span class="room-picker-search-icon">🔍</span>
            <input type="text" id="rp-input" class="room-picker-search-field"
                   placeholder="Cari nama ruangan, gedung, departemen, atau kapasitas..."
                   autocomplete="off" spellcheck="false">
            <button type="button" id="rp-clear" class="room-picker-clear-btn" style="display:none" title="Hapus pencarian">✕</button>
          </div>
          <!-- Dropdown hasil -->
          <div class="room-picker-dropdown" id="rp-dropdown" style="display:none"></div>
          <!-- Tampilan ruangan terpilih -->
          <div class="room-picker-selected" id="rp-selected" style="display:none"></div>
        </div>
        <!-- Hidden select — untuk submit form & fungsi checkAvail() -->
        <select name="ruangan_id" id="room-select" style="display:none" required>
          <option value="">-- Pilih Ruangan --</option>
          <?php foreach ($rooms as $r): ?>
          <option value="<?= $r['id'] ?>"
                  data-dept="<?= e($r['departemen']) ?>"
                  data-tipe="<?= e($r['tipe']) ?>">
            <?= e($r['nama']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div id="room-info" class="alert alert-info mt-2" style="display:none"></div>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label>Tanggal Mulai *</label>
          <input type="date" id="tgl-mulai" name="tanggal_mulai" class="form-control"
                 value="<?= e($_POST['tanggal_mulai'] ?? $preselect_date) ?>"
                 min="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
          <label>Tanggal Selesai *</label>
          <input type="date" id="tgl-selesai" name="tanggal_selesai" class="form-control"
                 value="<?= e($_POST['tanggal_selesai'] ?? $preselect_date) ?>"
                 min="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
          <label>Jam Mulai *</label>
          <input type="time" id="jam-mulai" name="jam_mulai" class="form-control"
                 value="<?= e($_POST['jam_mulai'] ?? $preselect_jam1) ?>"
                 inputmode="numeric"
                 required>
        </div>
        <div class="form-group">
          <label>Jam Selesai *</label>
          <input type="time" id="jam-selesai" name="jam_selesai" class="form-control"
                 value="<?= e($_POST['jam_selesai'] ?? $preselect_jam2) ?>"
                 inputmode="numeric"
                 required>
          <div id="jam-selesai-note" style="display:none;color:#dc2626;font-size:12.5px;margin-top:5px;font-weight:500"></div>
        </div>
      </div>
      <div id="avail-check" style="display:none;margin-bottom:16px"></div>
      <div class="form-group">
        <label>Jumlah Peserta *</label>
        <input type="number" name="jumlah_peserta" class="form-control"
               min="1" value="<?= e($_POST['jumlah_peserta'] ?? 1) ?>" required>
      </div>
      <div class="form-group">
        <label>Keterangan Tambahan</label>
        <textarea name="keterangan" class="form-control" rows="3"
                  placeholder="Deskripsi kegiatan, kebutuhan khusus..."><?= e($_POST['keterangan'] ?? '') ?></textarea>
      </div>
    </div>
  </div>

  <!-- ── Surat Peminjaman (hanya indoor) ── -->
  <div class="card mb-4" id="card-surat">
    <div class="card-header"><span class="card-title">📎 Surat Peminjaman</span></div>
    <div class="card-body">
      <div class="form-group">
        <label>Upload File Surat *</label>
        <input type="file" id="input-surat" name="file_surat" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
        <div class="form-text">Format: PDF, DOC, DOCX, JPG, PNG. Maksimal 5MB.</div>
      </div>
      <div class="alert alert-info">
        ℹ️ Gunakan surat resmi berkop organisasi/instansi yang ditandatangani pihak berwenang.
      </div>
    </div>
  </div>

  <button type="submit" class="btn btn-primary btn-lg btn-block">📤 Kirim Pengajuan Peminjaman</button>
</form>

<script>
console.log('%c[booking] avail-reset v3 aktif','color:#16a34a;font-weight:bold');
const roomMap  = <?= json_encode($roomMap) ?>;
const roomsAll = <?= json_encode(array_values($rooms)) ?>;
const baseUrl  = '<?= base_url() ?>';
const PRE_ID   = <?= (int)($preselect ?: ($_POST['ruangan_id'] ?? 0)) ?>;

/* ── Label departemen khusus ── */
const deptLabel = {'Area Outdoor Fakultas Sains':'Admin DMF','Admin Fakultas Sains':'Admin Fakultas Sains'};
function deptDisplay(d){ return deptLabel[d] || d; }

/* ── Daftar ruangan outdoor yang tetap WAJIB surat (seperti indoor) ── */
const SURAT_OUTDOOR = <?= json_encode(outdoorWajibSurat(), JSON_UNESCAPED_UNICODE) ?>;
function suratRequiredFor(room){
  if (!room) return true;                       // default tampilkan saat belum pilih
  if (room.tipe !== 'outdoor') return true;     // indoor selalu wajib
  const n = String(room.nama||'').trim().toLowerCase();
  return SURAT_OUTDOOR.some(s => String(s).trim().toLowerCase() === n);
}

/* ── Tampilkan/sembunyikan kartu surat sesuai kewajiban ── */
function toggleSuratCard(required) {
  const card  = document.getElementById('card-surat');
  const input = document.getElementById('input-surat');
  if (!card) return;
  if (required) {
    card.style.display = '';
    if (input) input.required = true;
  } else {
    card.style.display = 'none';
    if (input) { input.required = false; input.value = ''; }
  }
}

/* ── Utility ── */
function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function highlight(text, q){
  if(!q) return escHtml(text);
  const re = new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi');
  return escHtml(text).replace(re,'<mark class="rp-hl">$1</mark>');
}

/* ── Room Picker ── */
const rpInput    = document.getElementById('rp-input');
const rpDropdown = document.getElementById('rp-dropdown');
const rpSelected = document.getElementById('rp-selected');
const rpClear    = document.getElementById('rp-clear');
const rpSearch   = document.getElementById('rp-search-wrap');
const hiddenSel  = document.getElementById('room-select');
let   rpFocusIdx = -1;

function rpRenderDropdown(q){
  q = (q||'').trim();
  const ql = q.toLowerCase();
  const filtered = roomsAll.filter(r =>
    !ql ||
    r.nama.toLowerCase().includes(ql) ||
    r.gedung.toLowerCase().includes(ql) ||
    r.departemen.toLowerCase().includes(ql) ||
    String(r.kapasitas).includes(ql) ||
    r.tipe.toLowerCase().includes(ql)
  );
  if(!filtered.length){
    rpDropdown.innerHTML = '<div class="rp-empty"><div class="rp-empty-ic">🔍</div>Ruangan tidak ditemukan.<br><span style="font-size:12px">Coba kata kunci lain</span></div>';
    rpFocusIdx = -1; return;
  }
  const indoor  = filtered.filter(r => r.tipe==='indoor');
  const outdoor = filtered.filter(r => r.tipe==='outdoor');
  let html = '', idx = 0;
  function renderItems(arr){
    arr.forEach(r=>{
      html += `<div class="room-picker-item" data-id="${r.id}" data-idx="${idx++}" onmousedown="rpSelect(${r.id},event)">
        <div class="rpi-icon">${r.tipe==='indoor'?'🏢':'🌿'}</div>
        <div class="rpi-body">
          <div class="rpi-name">${highlight(r.nama, q)}</div>
          <div class="rpi-meta">
            <span class="rp-badge gedung">📍 ${highlight(r.gedung, q)}</span>
            <span class="rp-badge kap">👥 ${r.kapasitas} orang</span>
            <span class="rp-badge dept">🏫 ${highlight(deptDisplay(r.departemen), q)}</span>
            <span class="rp-badge ${r.tipe}">${r.tipe==='indoor'?'Indoor':'Outdoor'}</span>
          </div>
        </div>
      </div>`;
    });
  }
  if(indoor.length){ html += `<div class="room-picker-group-hd">🏢 Indoor (${indoor.length})</div>`; renderItems(indoor); }
  if(outdoor.length){ html += `<div class="room-picker-group-hd">🌿 Outdoor / DMF (${outdoor.length})</div>`; renderItems(outdoor); }
  rpDropdown.innerHTML = html;
  rpFocusIdx = -1;
}

function rpOpen(){
  rpRenderDropdown(rpInput.value);
  rpDropdown.style.display = 'block';
}
function rpClose(){
  rpDropdown.style.display = 'none';
  rpFocusIdx = -1;
}

function rpSelect(id, e){
  if(e) e.preventDefault();
  const room = roomsAll.find(r=>r.id==id);
  if(!room) return;
  hiddenSel.value = id;
  rpSearch.style.display  = 'none';
  rpDropdown.style.display= 'none';
  rpSelected.style.display= 'flex';
  rpSelected.innerHTML = `
    <div class="rps-icon">${room.tipe==='indoor'?'🏢':'🌿'}</div>
    <div class="rps-body">
      <div class="rps-name">${escHtml(room.nama)}</div>
      <div class="rps-meta">📍 ${escHtml(room.gedung)} &nbsp;·&nbsp; 👥 ${room.kapasitas} orang &nbsp;·&nbsp; 🏫 ${escHtml(deptDisplay(room.departemen))}</div>
    </div>
    <button type="button" class="rps-change" onclick="rpChange()">🔄 Ganti</button>`;
  toggleSuratCard(suratRequiredFor(room));
  checkAvail();                /* reset + cek ulang — dijamin jalan duluan */
  updateRoomInfo(hiddenSel);   /* update banner (tidak memblok checkAvail) */
}

function rpChange(){
  hiddenSel.value = '';
  rpSelected.style.display = 'none';
  rpSearch.style.display   = 'flex';
  rpInput.value = '';
  rpClear.style.display = 'none';
  checkAvail();          // RESET DULUAN: ruangan kosong → box ketersediaan hilang
  toggleSuratCard(true);   // reset → tampilkan surat (belum tahu ruangan)
  const ri = document.getElementById('room-info'); if (ri) ri.style.display = 'none';
  rpInput.focus();
  rpOpen();
}

/* Input events */
rpInput.addEventListener('input', function(){
  rpClear.style.display = this.value ? 'block' : 'none';
  rpRenderDropdown(this.value);
  rpDropdown.style.display = 'block';
});
rpInput.addEventListener('focus', rpOpen);
rpClear.addEventListener('click', function(){
  rpInput.value=''; rpClear.style.display='none';
  rpRenderDropdown(''); rpInput.focus();
});

/* Keyboard navigation */
rpInput.addEventListener('keydown', function(e){
  const items = rpDropdown.querySelectorAll('.room-picker-item');
  if(!items.length) return;
  if(e.key==='ArrowDown'){
    e.preventDefault();
    if(rpFocusIdx < items.length-1) rpFocusIdx++;
    rpUpdateFocus(items);
  } else if(e.key==='ArrowUp'){
    e.preventDefault();
    if(rpFocusIdx > 0) rpFocusIdx--;
    rpUpdateFocus(items);
  } else if(e.key==='Enter'){
    e.preventDefault();
    if(rpFocusIdx>=0 && items[rpFocusIdx]){
      rpSelect(items[rpFocusIdx].dataset.id);
    }
  } else if(e.key==='Escape'){
    rpClose();
  }
});
function rpUpdateFocus(items){
  items.forEach((el,i)=>{
    el.classList.toggle('rp-focus', i===rpFocusIdx);
    if(i===rpFocusIdx) el.scrollIntoView({block:'nearest'});
  });
}

/* Close when clicking outside */
document.addEventListener('click', function(e){
  if(!document.getElementById('room-picker').contains(e.target)) rpClose();
});

/* ══ Organisasi Picker (combobox kreatif) — daftar & kategori dari pengaturan DB ══ */
<?php
$_catMeta = dmfCatMeta();
$_orgData = [['v'=>'-','icon'=>$_catMeta['umum']['icon'],'cat'=>'umum','color'=>$_catMeta['umum']['color']]];
foreach (dmfOrmawaList() as $_o) {
    $_cm = $_catMeta[$_o['cat']] ?? ['icon'=>'🏷️','color'=>'#f1f5f9'];
    $_orgData[] = ['v'=>$_o['name'],'icon'=>$_cm['icon'],'cat'=>$_o['cat'],'color'=>$_cm['color']];
}
$_orgCats = [];
foreach ($_catMeta as $_k=>$_cm) {
    $_orgCats[$_k] = ($_k==='umum')
        ? ['label'=>'Umum / Pribadi','plain'=>'Bukan dari organisasi']
        : ['label'=>$_cm['icon'].' '.$_cm['label'], 'plain'=>$_cm['label']];
}
$_orgOrder = array_keys($_catMeta);
?>
const ORG_DATA  = <?= json_encode($_orgData, JSON_UNESCAPED_UNICODE) ?>;
const ORG_CATS  = <?= json_encode($_orgCats, JSON_UNESCAPED_UNICODE) ?>;
const ORG_ORDER = <?= json_encode($_orgOrder, JSON_UNESCAPED_UNICODE) ?>;

const orgPicker   = document.getElementById('org-picker');
const orgInput    = document.getElementById('org-input');
const orgDropdown = document.getElementById('org-dropdown');
const orgCaret    = document.getElementById('org-caret');
const orgIcon     = document.getElementById('org-icon');
let   orgFocusIdx = -1;

function orgEsc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function orgHl(t,q){ if(!q) return orgEsc(t); const re=new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'); return orgEsc(t).replace(re,'<mark class="org-hl">$1</mark>'); }
function orgIconFor(v){ const o=ORG_DATA.find(x=>x.v.toLowerCase()===String(v||'').trim().toLowerCase()); return o?o.icon:'🏢'; }

function orgRender(q){
  q=(q||'').trim(); const ql=q.toLowerCase();
  const sel=orgInput.value.trim().toLowerCase();
  const matches=ORG_DATA.filter(o=> !ql || o.v.toLowerCase().includes(ql) || ((ORG_CATS[o.cat]||{}).plain||'').toLowerCase().includes(ql));
  let html='', idx=0;
  ORG_ORDER.forEach(cat=>{
    const items=matches.filter(o=>o.cat===cat);
    if(!items.length) return;
    html+=`<div class="org-cat-hd">${(ORG_CATS[cat]||{}).label||cat}</div>`;
    items.forEach(o=>{
      const isSel = sel===o.v.toLowerCase();
      const name  = o.v==='-' ? 'Tidak ada / Pribadi' : o.v;
      const sub   = o.v==='-' ? 'Untuk kegiatan pribadi / bukan organisasi' : ((ORG_CATS[o.cat]||{}).plain||'');
      html+=`<div class="org-item${isSel?' org-selected':''}" data-v="${orgEsc(o.v)}" data-idx="${idx++}" onmousedown="orgSelect(this.dataset.v,event)">
        <div class="org-item-ic" style="background:${o.color||'#f1f5f9'}">${o.icon}</div>
        <div class="org-item-body">
          <div class="org-item-name">${orgHl(name,q)}</div>
          <div class="org-item-sub">${orgEsc(sub)}</div>
        </div>
        <span class="org-item-check">✓</span>
      </div>`;
    });
  });
  const exact = ORG_DATA.some(o=>o.v.toLowerCase()===ql);
  if(q && q!=='-' && !exact){
    html+=`<div class="org-custom" onmousedown="orgUseCustom(event)">➕ Gunakan: “<strong>${orgEsc(q)}</strong>”</div>`;
  }
  if(!html) html=`<div class="org-empty">🔍 Tidak ditemukan. Ketik untuk pakai nama sendiri.</div>`;
  orgDropdown.innerHTML=html;
  orgFocusIdx=-1;
}

function orgOpen(){ orgRender(''); orgDropdown.style.display='block'; orgPicker.classList.add('open'); }
function orgClose(){ orgDropdown.style.display='none'; orgPicker.classList.remove('open'); orgFocusIdx=-1; }
function orgSelect(v,e){ if(e)e.preventDefault(); orgInput.value=v; orgIcon.textContent=orgIconFor(v); orgClose(); }
function orgUseCustom(e){ if(e)e.preventDefault(); orgIcon.textContent=orgIconFor(orgInput.value); orgClose(); }

orgInput.addEventListener('focus', orgOpen);
orgInput.addEventListener('input', function(){
  orgIcon.textContent=orgIconFor(this.value);
  orgRender(this.value); orgDropdown.style.display='block'; orgPicker.classList.add('open');
});
orgCaret.addEventListener('click', function(e){
  e.stopPropagation();
  if(orgDropdown.style.display==='block'){ orgClose(); }
  else { orgOpen(); orgInput.focus(); }
});
orgInput.addEventListener('keydown', function(e){
  const items=orgDropdown.querySelectorAll('.org-item');
  if(e.key==='ArrowDown'){ e.preventDefault(); if(!items.length) return; if(orgFocusIdx<items.length-1) orgFocusIdx++; orgUpdateFocus(items); }
  else if(e.key==='ArrowUp'){ e.preventDefault(); if(orgFocusIdx>0) orgFocusIdx--; orgUpdateFocus(items); }
  else if(e.key==='Enter'){ if(orgFocusIdx>=0 && items[orgFocusIdx]){ e.preventDefault(); orgSelect(items[orgFocusIdx].dataset.v); } }
  else if(e.key==='Escape'){ orgClose(); }
});
function orgUpdateFocus(items){
  items.forEach((el,i)=>{ el.classList.toggle('org-focus', i===orgFocusIdx); if(i===orgFocusIdx) el.scrollIntoView({block:'nearest'}); });
}
document.addEventListener('click', function(e){
  if(!orgPicker.contains(e.target)) orgClose();
});
/* Set ikon awal sesuai nilai yang sudah ada (mis. setelah POST gagal) */
orgIcon.textContent = orgIconFor(orgInput.value);


/* ── Room Info Banner ── (hanya update banner; TIDAK memanggil checkAvail,
   agar error apa pun di sini tidak pernah menghentikan pengecekan ketersediaan) */
function updateRoomInfo(sel) {
  const info = document.getElementById('room-info');
  if (!info) return;
  const id = sel.value;
  if (!id || !roomMap[id]) { info.style.display = 'none'; return; }
  const { dept, tipe } = roomMap[id];
  info.style.display = 'flex';
  info.textContent = 'ℹ️ Akan divalidasi oleh: ' +
    (tipe === 'outdoor' ? 'Admin DMF' : 'Admin Departemen ' + dept) +
    ' dan Admin Utama Fakultas Sains.';
}

/* ── Availability Check ──
   Satu sumber kebenaran: setiap perubahan field apa pun (ruangan/tanggal/jam)
   memanggil checkAvail(). Token `avReqId` mencegah hasil fetch lama (in-flight)
   menimpa tampilan setelah field diubah. Box selalu di-reset sesuai state terbaru. */
let avTimer = null;
let avReqId = 0;
function resetAvailBox() {
  const box = document.getElementById('avail-check');
  clearTimeout(avTimer);
  avReqId++;                       /* batalkan hasil fetch lama yang masih jalan */
  if (box) { box.style.display = 'none'; box.innerHTML = ''; }
}
function checkAvail() {
  const rid = hiddenSel.value;
  const tm  = document.getElementById('tgl-mulai').value;
  const ts  = document.getElementById('tgl-selesai').value;
  const jm  = document.getElementById('jam-mulai').value;
  const js  = document.getElementById('jam-selesai').value;
  const box = document.getElementById('avail-check');

  /* Reset dulu — token baru dibuat di sini, hasil lama otomatis diabaikan */
  resetAvailBox();
  const myReq = avReqId;

  /* Field belum lengkap / jam tidak logis → tetap kosong (sudah di-reset) */
  if (!rid || !tm || !ts || !jm || !js) return;
  if (tm === ts && js <= jm) return;

  /* Tampilkan loading langsung */
  box.style.display = 'block';
  box.innerHTML = '<div class="alert alert-info" style="margin-bottom:0">⏳ Memeriksa ketersediaan ruangan...</div>';

  avTimer = setTimeout(async () => {
    try {
      const url = baseUrl + '/user/check_availability.php?ruangan_id=' + rid +
        '&tanggal_mulai='   + encodeURIComponent(tm) +
        '&tanggal_selesai=' + encodeURIComponent(ts) +
        '&jam_mulai='       + encodeURIComponent(jm) +
        '&jam_selesai='     + encodeURIComponent(js);
      const res  = await fetch(url);
      const data = await res.json();
      if (myReq !== avReqId) return;                 /* state sudah berubah → abaikan */
      if (data.available === null) { box.style.display = 'none'; box.innerHTML = ''; return; }
      if (data.available) {
        box.innerHTML = '<div class="alert alert-success" style="margin-bottom:0">✅ Ruangan tersedia pada waktu yang dipilih!</div>';
      } else {
        let html = '<div class="alert alert-warning" style="margin-bottom:0"><strong>⚠️ Ruangan sudah dibooking pada waktu ini!</strong><ul style="margin:8px 0 0 16px">';
        data.conflicts.forEach(c => {
          const s = c.status === 'disetujui' ? '✅ Disetujui' : '⏳ Menunggu';
          html += '<li>' + escHtml(c.nama_kegiatan) + ' — ' + c.tanggal_mulai + ' ' + c.jam_mulai +
                  ' s/d ' + c.tanggal_selesai + ' ' + c.jam_selesai + ' [' + s + ']</li>';
        });
        html += '</ul><p style="margin-top:8px;font-size:13px">Anda tetap dapat mengajukan, namun mungkin tidak disetujui admin.</p></div>';
        box.innerHTML = html;
      }
    } catch(e) {
      if (myReq === avReqId) { box.style.display = 'none'; box.innerHTML = ''; }
    }
  }, 500);
}

/* ── Validasi Jam Selesai > Jam Mulai ── */
function validateJam() {
  const tglMulaiEl = document.getElementById('tgl-mulai');
  const tglSelEl   = document.getElementById('tgl-selesai');
  const jamMulaiEl = document.getElementById('jam-mulai');
  const jamSelEl   = document.getElementById('jam-selesai');
  const note       = document.getElementById('jam-selesai-note');

  const tm = tglMulaiEl.value, ts = tglSelEl.value;
  const jm = jamMulaiEl.value, js = jamSelEl.value;

  /* Tanggal selesai tidak boleh < tanggal mulai */
  if (tm) tglSelEl.min = tm;

  /* Jam: hanya berlaku jika tanggal sama */
  if (tm && ts && tm === ts && jm) {
    const [hh, mm] = jm.split(':').map(Number);
    const minTotal = hh * 60 + mm + 1;
    const minHH = String(Math.floor(minTotal / 60) % 24).padStart(2,'0');
    const minMM = String(minTotal % 60).padStart(2,'0');
    jamSelEl.min = minHH + ':' + minMM;

    if (js && js <= jm) {
      jamSelEl.style.borderColor = '#dc2626';
      jamSelEl.style.boxShadow   = '0 0 0 3px rgba(220,38,38,.12)';
      note.innerHTML = '⚠️ Jam selesai harus lebih dari <strong>' + jm + '</strong>';
      note.style.display = 'block';
    } else {
      jamSelEl.style.borderColor = '';
      jamSelEl.style.boxShadow   = '';
      note.style.display = 'none';
    }
  } else {
    jamSelEl.min               = '';
    jamSelEl.style.borderColor = '';
    jamSelEl.style.boxShadow   = '';
    note.style.display         = 'none';
  }
}

/* React ke perubahan tanggal/jam secara langsung (input + change).
   `input` membuat notif tersedia/bentrok muncul & reset seketika saat
   nilai dipilih, tanpa menunggu field kehilangan fokus. */
['tgl-mulai','tgl-selesai','jam-mulai','jam-selesai'].forEach(id => {
  const el = document.getElementById(id);
  if (!el) return;
  ['change','input'].forEach(ev =>
    el.addEventListener(ev, function(){ validateJam(); checkAvail(); }));
});

/* Cegah submit jika jam tidak valid */
document.querySelector('form').addEventListener('submit', function(e) {
  const tm = document.getElementById('tgl-mulai').value;
  const ts = document.getElementById('tgl-selesai').value;
  const jm = document.getElementById('jam-mulai').value;
  const js = document.getElementById('jam-selesai').value;
  if (ts < tm) {
    e.preventDefault();
    document.getElementById('tgl-selesai').focus();
    return;
  }
  if (tm === ts && jm && js && js <= jm) {
    e.preventDefault();
    document.getElementById('jam-selesai').focus();
    document.getElementById('jam-selesai-note').style.display = 'block';
  }
});

window.addEventListener('load', () => {
  if (PRE_ID) {
    rpSelect(PRE_ID);
  }
  setTimeout(checkAvail, 100);
});

/* Jika halaman dipulihkan dari back/forward cache (bfcache),
   event 'load' tidak jalan → sinkronkan ulang box ketersediaan. */
window.addEventListener('pageshow', (e) => { if (e.persisted) checkAvail(); });
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
