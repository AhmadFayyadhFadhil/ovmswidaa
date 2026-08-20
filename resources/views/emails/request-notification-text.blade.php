ORDER VEHICLE MANAGEMENT SYSTEM (OVMSWIDA)
PT WIDARTA BHAKTI - PASURUAN PLANT
==================================================

[{{ $badgeText ?? 'PEMBERITAHUAN' }}]
Halo, {{ $recipientName ?? 'Bapak/Ibu' }}!

{{ $messageBody }}

RINCIAN PERMOHONAN:
--------------------------------------------------
- Nomor Tiket        : #REQ-{{ $requestId }}
- Pemohon             : {{ $requesterName }} ({{ $departmentName }})
- Tujuan              : {{ $destination }}
- Keperluan           : {{ $purpose }}
- Jadwal              : {{ $scheduleStr }}
- Prioritas & Tipe    : {{ $priority }} - {{ $tripType }}
@if(!empty($passengersList))
- Penumpang           : {{ $passengersList }}
@endif
@if(!empty($assignmentInfo))
- Armada & Driver     : {{ $assignmentInfo }}
@endif
@if(!empty($extraNote))
- Catatan             : {{ $extraNote }}
@endif
@if(!empty($itinerariesList))

RENCANA JADWAL ITINERARY:
@foreach($itinerariesList as $it)
* {{ $it['day'] }}: {{ $it['destination'] }} @if(!empty($it['activities']))({{ $it['activities'] }})@endif
@endforeach
@endif

@if(!empty($actionUrl))
Buka Permohonan di OVMS: {{ $actionUrl }}
@endif

--------------------------------------------------
Email ini dikirim secara otomatis oleh Order Vehicle Management System (OVMS).
PT Widarta Bhakti - Jl. Raya Pandaan - Bangil KM. 4, Pasuruan, Jawa Timur.
Mohon tidak membalas email ini secara langsung.
