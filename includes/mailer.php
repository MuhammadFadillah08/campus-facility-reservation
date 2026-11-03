<?php
// ============================================================
//  HELPER PENGIRIMAN EMAIL OTP — Campus Facility Reservation
//  Menggunakan Brevo Transactional Email API (HTTPS/cURL)
//  Tidak membutuhkan PHP mail() — kompatibel dengan hosting gratis
// ============================================================

require_once __DIR__ . '/mail_config.php';

/**
 * Kirim email OTP ke mahasiswa via Brevo API.
 *
 * @param  string $toEmail  Alamat email tujuan
 * @param  string $otp      Kode OTP 6 digit
 * @return array{ok: bool, error: string}
 */
function sendOtpEmail(string $toEmail, string $otp): array
{
    $payload = [
        'sender'      => ['name' => SMTP_FROM_NAME, 'email' => SMTP_FROM],
        'to'          => [['email' => $toEmail]],
        'replyTo'     => ['email' => SMTP_FROM, 'name' => SMTP_FROM_NAME],
        'subject'     => '🔐 Kode OTP Login Campus Facility Reservation — ' . $otp,
        'htmlContent' => buildOtpHtml($otp, $toEmail),
        'textContent' => buildOtpText($otp),
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log('[CFR OTP] cURL error ke ' . $toEmail . ': ' . $curlError);
        return ['ok' => false, 'error' => 'Koneksi gagal: ' . $curlError];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['ok' => true, 'error' => ''];
    }

    $errMsg = $response ?: 'HTTP ' . $httpCode;
    error_log('[CFR OTP] Brevo API error ' . $httpCode . ' ke ' . $toEmail . ': ' . $errMsg);
    return ['ok' => false, 'error' => 'Brevo error ' . $httpCode . ': ' . $errMsg];
}

/* ─────────────────────────────────────────────
   Template email teks biasa (fallback)
───────────────────────────────────────────── */
function buildOtpText(string $otp): string
{
    return implode("\r\n", [
        'Campus Facility Reservation — Universitas Contoh',
        str_repeat('-', 46),
        '',
        'Kode OTP Anda: ' . $otp,
        '',
        'Kode ini berlaku selama 10 menit dan hanya',
        'dapat digunakan SATU KALI.',
        '',
        'JANGAN bagikan kode ini kepada siapapun,',
        'termasuk staf atau admin Fakultas Sains.',
        '',
        'Jika Anda tidak meminta kode ini,',
        'abaikan email ini.',
        '',
        str_repeat('-', 46),
        '© ' . date('Y') . ' Fakultas Sains Universitas Contoh',
    ]);
}

/* ─────────────────────────────────────────────
   Template email HTML (tampilan cantik)
───────────────────────────────────────────── */
function buildOtpHtml(string $otp, string $toEmail): string
{
    $year   = date('Y');
    $digits = str_split($otp);

    // Kotak per digit
    $boxes = implode('', array_map(
        static fn(string $d): string =>
            '<td style="padding:0 5px">
               <div style="width:50px;height:64px;background:#f0fdf4;border:2px solid #86efac;
                           border-radius:12px;font-size:30px;font-weight:900;color:#166534;
                           text-align:center;line-height:64px;font-family:monospace">'
               . htmlspecialchars($d, ENT_QUOTES) .
             '</div></td>',
        $digits
    ));

    return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f0fdf4;font-family:'Segoe UI',Arial,sans-serif">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;padding:40px 16px">
 <tr><td align="center">
  <table width="520" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 8px 32px rgba(22,101,52,.1)">

   <!-- Header -->
   <tr>
    <td style="background:linear-gradient(135deg,#052e16 0%,#166534 60%,#16a34a 100%);padding:36px 40px;text-align:center">
     <div style="width:68px;height:68px;background:rgba(255,255,255,.15);border-radius:16px;border:2px solid rgba(255,255,255,.3);display:inline-block;line-height:68px;font-size:26px;font-weight:900;color:#fff;margin-bottom:14px">CF</div>
     <h1 style="color:#fff;font-size:22px;margin:0 0 4px;font-weight:900;letter-spacing:-.3px">Campus Facility Reservation</h1>
     <p style="color:rgba(255,255,255,.7);font-size:13px;margin:0">Universitas Contoh</p>
    </td>
   </tr>

   <!-- Body -->
   <tr>
    <td style="padding:40px 40px 32px">

     <!-- Judul -->
     <h2 style="font-size:20px;font-weight:800;color:#1e293b;margin:0 0 8px">🔐 Kode Verifikasi OTP</h2>
     <p style="font-size:14px;color:#475569;line-height:1.65;margin:0 0 28px">
      Hai! Kami menerima permintaan login ke <strong>Campus Facility Reservation</strong>
      menggunakan email <strong>{$toEmail}</strong>.<br>
      Gunakan kode berikut untuk masuk. Kode berlaku <strong>10 menit</strong>
      dan hanya dapat digunakan <strong>satu kali</strong>.
     </p>

     <!-- Kotak OTP -->
     <table cellpadding="0" cellspacing="0" align="center" style="margin:0 auto 32px">
      <tr>{$boxes}</tr>
     </table>

     <!-- Divider -->
     <hr style="border:none;border-top:1px solid #e2e8f0;margin:0 0 24px">

     <!-- Peringatan -->
     <table cellpadding="0" cellspacing="0" width="100%">
      <tr>
       <td style="background:#fef9c3;border:1px solid #fcd34d;border-radius:12px;padding:14px 16px">
        <p style="font-size:13px;color:#78350f;margin:0">
         ⚠️ <strong>Perhatian:</strong> Jangan bagikan kode ini kepada siapapun,
         termasuk staf atau admin Fakultas Sains. Campus Facility Reservation <strong>tidak pernah meminta</strong>
         kode OTP Anda melalui telepon atau chat.
        </p>
       </td>
      </tr>
     </table>

     <!-- Info tambahan -->
     <p style="font-size:13px;color:#94a3b8;margin:24px 0 0;line-height:1.6">
      Jika Anda tidak melakukan permintaan ini, abaikan email ini.
      Akun Anda tetap aman selama kode belum dimasukkan.
     </p>

    </td>
   </tr>

   <!-- Footer -->
   <tr>
    <td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:20px 40px;text-align:center">
     <p style="font-size:12px;color:#94a3b8;margin:0;line-height:1.7">
      Email ini dikirim secara otomatis oleh sistem Campus Facility Reservation.<br>
      Harap tidak membalas email ini.<br><br>
      &copy; {$year} Fakultas Sains Universitas Contoh &mdash;
      Fakultas Sains
     </p>
    </td>
   </tr>

  </table>
 </td></tr>
</table>

</body>
</html>
HTML;
}
