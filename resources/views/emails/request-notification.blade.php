<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $subjectTitle ?? 'Notifikasi OVMS - PT Widarta Bhakti' }}</title>
  <style>
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
    body { margin: 0; padding: 0; width: 100% !important; background-color: #f1f5f9; font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif; color: #1e293b; }
    .wrapper { width: 100%; table-layout: fixed; background-color: #f1f5f9; padding: 30px 0; }
    .main-table { background-color: #ffffff; margin: 0 auto; width: 100%; max-width: 620px; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); }
    .header-bar { background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%); padding: 24px 30px; text-align: left; }
    .header-title { color: #ffffff; font-size: 18px; font-weight: 700; margin: 0; letter-spacing: 0.5px; }
    .header-subtitle { color: #93c5fd; font-size: 12px; margin: 4px 0 0 0; text-transform: uppercase; letter-spacing: 1px; }
    .content-area { padding: 30px; }
    .greeting { font-size: 16px; font-weight: 600; color: #0f172a; margin-bottom: 8px; }
    .message-box { background-color: #f8fafc; border-left: 4px solid {{ $badgeColor ?? '#2563eb' }}; padding: 14px 16px; border-radius: 4px; margin-bottom: 24px; font-size: 14px; line-height: 1.5; color: #334155; }
    .badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; text-transform: uppercase; color: #ffffff; background-color: {{ $badgeColor ?? '#2563eb' }}; margin-bottom: 12px; }
    .details-table { width: 100%; border-collapse: collapse; margin-bottom: 28px; font-size: 13px; }
    .details-table td { padding: 10px 12px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    .details-label { width: 38%; color: #64748b; font-weight: 600; }
    .details-value { width: 62%; color: #0f172a; font-weight: 500; }
    .cta-container { text-align: center; margin-bottom: 24px; }
    .cta-btn { display: inline-block; background-color: #1d4ed8; color: #ffffff !important; font-size: 14px; font-weight: 600; text-decoration: none; padding: 12px 28px; border-radius: 8px; box-shadow: 0 2px 6px rgba(29, 78, 216, 0.3); }
    .footer { background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px 30px; text-align: center; font-size: 11px; color: #94a3b8; line-height: 1.5; }
  </style>
</head>
<body>
  <div class="wrapper">
    <table class="main-table" align="center" cellpadding="0" cellspacing="0">
      <!-- HEADER -->
      <tr>
        <td class="header-bar">
          <p class="header-title">ORDER VEHICLE MANAGEMENT SYSTEM</p>
          <p class="header-subtitle">PT WIDARTA BHAKTI — PASURUAN PLANT</p>
        </td>
      </tr>

      <!-- CONTENT -->
      <tr>
        <td class="content-area">
          <div class="badge">{{ $badgeText ?? 'PEMBERITAHUAN' }}</div>
          
          <div class="greeting">Halo, {{ $recipientName ?? 'Bapak/Ibu' }}!</div>
          
          <div class="message-box">
            {{ $messageBody }}
          </div>

          <table class="details-table" cellpadding="0" cellspacing="0">
            <tr>
              <td class="details-label">Nomor Tiket</td>
              <td class="details-value"><strong>#REQ-{{ $requestId }}</strong></td>
            </tr>
            <tr>
              <td class="details-label">Pemohon</td>
              <td class="details-value">{{ $requesterName }} ({{ $departmentName }})</td>
            </tr>
            <tr>
              <td class="details-label">Tujuan Perjalanan</td>
              <td class="details-value">{{ $destination }}</td>
            </tr>
            <tr>
              <td class="details-label">Keperluan</td>
              <td class="details-value">{{ $purpose }}</td>
            </tr>
            <tr>
              <td class="details-label">Jadwal Keberangkatan</td>
              <td class="details-value">{{ $scheduleStr }}</td>
            </tr>
            <tr>
              <td class="details-label">Prioritas & Tipe</td>
              <td class="details-value">{{ $priority }} &bull; {{ $tripType }}</td>
            </tr>
            @if(!empty($passengersList))
            <tr>
              <td class="details-label">Penumpang</td>
              <td class="details-value">{{ $passengersList }}</td>
            </tr>
            @endif
            @if(!empty($assignmentInfo))
            <tr>
              <td class="details-label">Armada & Driver</td>
              <td class="details-value"><strong>{{ $assignmentInfo }}</strong></td>
            </tr>
            @endif
            @if(!empty($extraNote))
            <tr>
              <td class="details-label">Catatan Tambahan</td>
              <td class="details-value" style="color: #475569;">{{ $extraNote }}</td>
            </tr>
            @endif
            @if(!empty($itinerariesList))
            <tr>
              <td class="details-label">Rencana Perjalanan ({{ count($itinerariesList) }} Hari)</td>
              <td class="details-value">
                <table style="width: 100%; border-collapse: collapse; margin-top: 4px; font-size: 12px;">
                  @foreach($itinerariesList as $it)
                  <tr style="border-bottom: 1px dashed #cbd5e1;">
                    <td style="padding: 5px 0; font-weight: 600; color: #1e3a8a; width: 42%;">{{ $it['day'] }}</td>
                    <td style="padding: 5px 0; color: #334155;">{{ $it['destination'] }} @if(!empty($it['activities'])) &bull; <em>{{ $it['activities'] }}</em> @endif</td>
                  </tr>
                  @endforeach
                </table>
              </td>
            </tr>
            @endif
          </table>

          @if(!empty($actionUrl))
          <div class="cta-container">
            <a href="{{ $actionUrl }}" target="_blank" class="cta-btn">Buka Permohonan di OVMS &rarr;</a>
          </div>
          @endif
        </td>
      </tr>

      <!-- FOOTER -->
      <tr>
        <td class="footer">
          Email ini dikirim secara otomatis oleh <strong>Order Vehicle Management System (OVMS)</strong>.<br>
          PT Widarta Bhakti &bull; Jl. Raya Pandaan - Bangil KM. 4, Pasuruan, Jawa Timur.<br>
          Mohon tidak membalas email ini secara langsung.
        </td>
      </tr>
    </table>
  </div>
</body>
</html>
