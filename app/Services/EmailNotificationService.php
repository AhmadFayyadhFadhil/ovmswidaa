<?php

namespace App\Services;

use App\Models\Request as VehicleRequest;
use App\Models\User;
use App\Mail\RequestNotificationMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailNotificationService
{
    /**
     * Get the base frontend URL for CTA links in emails.
     */
    public static function getFrontendUrl(): string
    {
        return rtrim(env('FRONTEND_URL', env('APP_URL', 'http://ovmsdev.widatra.com:8282')), '/');
    }

    /**
     * Helper to prepare common request payload for email templates.
     */
    private static function buildCommonData(VehicleRequest $request, string $recipientName, string $badgeText, string $badgeColor, string $subjectTitle, string $messageBody, ?string $extraNote = null, ?string $assignmentInfo = null): array
    {
        $request->loadMissing(['user', 'department', 'passengers.user', 'assignments.driver', 'assignments.vehicle']);

        $requesterName  = $request->user ? $request->user->name : 'Karyawan';
        $departmentName = $request->department ? $request->department->name : ($request->department_id ? "Dept #{$request->department_id}" : 'Umum');

        // Destination string
        $destination = 'Pabrik / Kantor';
        if ($request->destination_city && $request->destination_place) {
            $destination = "{$request->destination_city} — {$request->destination_place}";
        } elseif ($request->destination_city) {
            $destination = $request->destination_city;
        } elseif ($request->destination) {
            $destination = $request->destination;
        }

        // Schedule string
        $startStr = $request->start_time ? $request->start_time->format('d M Y, H:i') : ($request->created_at ? $request->created_at->format('d M Y, H:i') : '-');
        $endStr   = $request->end_time ? $request->end_time->format('d M Y, H:i') : null;
        $scheduleStr = $endStr ? "{$startStr} WIB s/d {$endStr} WIB" : "{$startStr} WIB";

        // Passengers
        $passengersList = '';
        if ($request->passengers && $request->passengers->isNotEmpty()) {
            $names = $request->passengers->map(function ($p) {
                return $p->user ? $p->user->name : ($p->name ?? 'Penumpang');
            })->filter()->values()->toArray();
            $passengersList = implode(', ', $names);
        }

        // Trip Type & Priority
        $priority = strtoupper($request->priority ?? 'NORMAL');
        $tripType = $request->is_multiday ? 'Multi-Day (Beberapa Hari)' : 'Same Day (Satu Hari)';

        // Purpose
        $purpose = $request->purpose ?? $request->trip_purpose ?? 'Perjalanan Dinas Operasional';

        $actionUrl = self::getFrontendUrl();

        return [
            'subjectTitle'   => $subjectTitle,
            'badgeText'      => $badgeText,
            'badgeColor'     => $badgeColor,
            'recipientName'  => $recipientName,
            'messageBody'    => $messageBody,
            'requestId'      => $request->id,
            'requesterName'  => $requesterName,
            'departmentName' => $departmentName,
            'destination'    => $destination,
            'purpose'        => $purpose,
            'scheduleStr'    => $scheduleStr,
            'priority'       => $priority,
            'tripType'       => $tripType,
            'passengersList' => $passengersList,
            'assignmentInfo' => $assignmentInfo,
            'extraNote'      => $extraNote,
            'actionUrl'      => $actionUrl,
        ];
    }

    /**
     * Safely send an email without throwing exceptions.
     */
    private static function sendSafe(?string $recipientEmail, array $data): bool
    {
        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            Log::info("EmailNotificationService: Skipped sending to empty/invalid email '{$recipientEmail}'");
            return false;
        }

        try {
            Mail::to($recipientEmail)->send(new RequestNotificationMail($data));
            Log::info("EmailNotificationService: Successfully sent '{$data['subjectTitle']}' to {$recipientEmail}");
            return true;
        } catch (\Throwable $e) {
            Log::warning("EmailNotificationService: Failed sending email to {$recipientEmail}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 1. TRIGGER: Request Submitted (New Request Created).
     */
    public static function sendRequestSubmitted(VehicleRequest $request): void
    {
        $request->loadMissing(['user', 'department']);
        $requester = $request->user;
        $isUrgent  = in_array(strtolower((string) $request->priority), ['urgent', 'critical'], true);

        // A. Send Confirmation to Requester
        if ($requester && $requester->email) {
            $data = self::buildCommonData(
                $request,
                $requester->name,
                $isUrgent ? 'URGENT DIBUAT' : 'PERMOHONAN DIBUAT',
                $isUrgent ? '#dc2626' : '#2563eb',
                "[OVMS] Konfirmasi Pengajuan Permohonan Armada #REQ-{$request->id}",
                "Permohonan peminjaman armada kendaraan Anda telah berhasil diajukan ke sistem OVMS dan sedang menunggu persetujuan dari Kepala Departemen.",
                $request->notes ?? null
            );
            self::sendSafe($requester->email, $data);
        }

        // B. Send Notification to Approver (Department Head)
        $approver = null;
        if ($request->department_id) {
            $approver = User::where('department_id', $request->department_id)
                ->where('is_department_head', true)
                ->first();
        }

        if (!$approver && $request->department_id) {
            $approver = User::where('department_id', $request->department_id)
                ->whereHas('roles', function ($q) {
                    $q->whereIn('name', ['approver', 'Approver', 'manager', 'Manager']);
                })->first();
        }

        if ($approver && $approver->email && $approver->id !== ($requester->id ?? null)) {
            $data = self::buildCommonData(
                $request,
                $approver->name,
                'MENUNGGU PERSETUJUAN',
                '#d97706',
                "[OVMS] Menunggu Persetujuan: Permohonan Armada #REQ-{$request->id} dari " . ($requester->name ?? 'Staf'),
                "Terdapat pengajuan permohonan kendaraan dinas baru dari staf departemen Anda yang membutuhkan peninjauan dan persetujuan Anda di OVMS.",
                $request->notes ?? null
            );
            $data['actionUrl'] = self::getFrontendUrl() . '/approver/requests';
            self::sendSafe($approver->email, $data);
        }

        // C. If Urgent/Critical, also alert GA Coordinator & Admins immediately
        if ($isUrgent) {
            self::sendUrgentAlertToGA($request);
        }
    }

    /**
     * 2. TRIGGER: Urgent / Critical Request Alert to GA & Admins.
     */
    public static function sendUrgentAlertToGA(VehicleRequest $request): void
    {
        $gaUsers = User::whereHas('roles', function ($q) {
            $q->whereIn('name', ['gahrd', 'GA Coordinator', 'admin', 'Administrator']);
        })->get();

        foreach ($gaUsers as $ga) {
            if ($ga->email) {
                $data = self::buildCommonData(
                    $request,
                    $ga->name,
                    '🚨 PERMOHONAN URGENT',
                    '#dc2626',
                    "🚨 [URGENT OVMS] Permohonan Mendesak #REQ-{$request->id} — " . ($request->user->name ?? 'Pemohon'),
                    "PERINGATAN PRIORITAS TINGGI: Terdapat permohonan armada mendesak (Urgent/Critical) yang memerlukan perhatian dan penugasan armada secepatnya.",
                    $request->urgency_reason ?? $request->notes ?? 'Prioritas Urgent'
                );
                $data['actionUrl'] = self::getFrontendUrl() . '/gahrd/requests';
                self::sendSafe($ga->email, $data);
            }
        }
    }

    /**
     * 3. TRIGGER: Department Approved (Approved by Dept Head -> ready for GA assignment).
     */
    public static function sendDepartmentApproved(VehicleRequest $request): void
    {
        $request->loadMissing(['user', 'department']);
        $requester = $request->user;

        // A. Notify Requester
        if ($requester && $requester->email) {
            $data = self::buildCommonData(
                $request,
                $requester->name,
                'DISETUJUI DEPARTEMEN',
                '#059669',
                "[OVMS] Pengajuan #REQ-{$request->id} Telah Disetujui Kepala Departemen",
                "Kabar baik! Pengajuan permohonan kendaraan dinas Anda telah DISETUJUI oleh Kepala Departemen dan saat ini diteruskan ke tim GA & HRD untuk penugasan unit armada dan driver.",
                $request->notes ?? null
            );
            self::sendSafe($requester->email, $data);
        }

        // B. Notify GAHRD Coordinators
        $gaUsers = User::whereHas('roles', function ($q) {
            $q->whereIn('name', ['gahrd', 'GA Coordinator']);
        })->get();

        foreach ($gaUsers as $ga) {
            if ($ga->email) {
                $data = self::buildCommonData(
                    $request,
                    $ga->name,
                    'SIAP PENUGASAN ARMADA',
                    '#2563eb',
                    "[OVMS] Permohonan #REQ-{$request->id} Siap Ditugaskan Armada & Driver",
                    "Permohonan kendaraan #REQ-{$request->id} telah disetujui oleh Kepala Departemen dan siap untuk dialokasikan unit kendaraan dan driver di dashboard GA.",
                    $request->notes ?? null
                );
                $data['actionUrl'] = self::getFrontendUrl() . '/gahrd/requests';
                self::sendSafe($ga->email, $data);
            }
        }
    }

    /**
     * 4. TRIGGER: Driver & Vehicle Assigned by GAHRD.
     */
    public static function sendDriverAssigned(VehicleRequest $request, $assignment = null): void
    {
        $request->loadMissing(['user', 'department', 'assignments.driver', 'assignments.vehicle']);
        $requester = $request->user;

        // Extract Vehicle & Driver info
        $driverName = 'Driver Operasional';
        $driverPhone = '';
        $driverEmail = null;
        $vehicleInfo = 'Unit Armada Widatra';

        if ($assignment) {
            if ($assignment->driver) {
                $driverName  = $assignment->driver->name;
                $driverPhone = $assignment->driver->phone ? " (HP: {$assignment->driver->phone})" : '';
                $driverEmail = $assignment->driver->email;
            }
            if ($assignment->vehicle) {
                $vehicleInfo = "{$assignment->vehicle->name} [{$assignment->vehicle->plate_number}]";
            }
        } elseif ($request->assignments && $request->assignments->isNotEmpty()) {
            $firstAssign = $request->assignments->first();
            if ($firstAssign->driver) {
                $driverName  = $firstAssign->driver->name;
                $driverPhone = $firstAssign->driver->phone ? " (HP: {$firstAssign->driver->phone})" : '';
                $driverEmail = $firstAssign->driver->email;
            }
            if ($firstAssign->vehicle) {
                $vehicleInfo = "{$firstAssign->vehicle->name} [{$firstAssign->vehicle->plate_number}]";
            }
        }

        $assignmentStr = "{$vehicleInfo} &bull; Driver: {$driverName}{$driverPhone}";

        // A. Notify Requester
        if ($requester && $requester->email) {
            $data = self::buildCommonData(
                $request,
                $requester->name,
                'ARMADA & DRIVER SIAP',
                '#059669',
                "🚗 [OVMS] Armada & Driver Telah Ditugaskan untuk Perjalanan #REQ-{$request->id}",
                "Unit kendaraan dan driver untuk perjalanan dinas Anda telah berhasil dialokasikan oleh tim GA & HRD. Silakan bersiap sesuai dengan jadwal keberangkatan.",
                $request->notes ?? null,
                $assignmentStr
            );
            self::sendSafe($requester->email, $data);
        }

        // B. Send Duty Assignment Letter to Driver
        if ($driverEmail) {
            $data = self::buildCommonData(
                $request,
                $driverName,
                '📋 SURAT TUGAS DRIVER',
                '#1e40af',
                "📋 [SURAT TUGAS OVMS] Penugasan Perjalanan Dinas #REQ-{$request->id}",
                "Halo Pak {$driverName}, Anda telah ditugaskan untuk melayani perjalanan dinas #REQ-{$request->id}. Mohon pastikan kondisi kendaraan dalam keadaan prima dan standby tepat waktu.",
                $request->notes ?? null,
                $assignmentStr
            );
            $data['actionUrl'] = self::getFrontendUrl() . '/driver/dashboard';
            self::sendSafe($driverEmail, $data);
        }
    }

    /**
     * 5. TRIGGER: Request Rejected.
     */
    public static function sendRequestRejected(VehicleRequest $request, ?string $reason = null): void
    {
        $request->loadMissing(['user', 'department']);
        $requester = $request->user;

        if ($requester && $requester->email) {
            $data = self::buildCommonData(
                $request,
                $requester->name,
                'PERMOHONAN DITOLAK',
                '#dc2626',
                "❌ [OVMS] Pemberitahuan Penolakan Permohonan Armada #REQ-{$request->id}",
                "Mohon maaf, pengajuan permohonan kendaraan dinas Anda #REQ-{$request->id} TIDAK DAPAT DISETUJUI.",
                $reason ? "Alasan Penolakan: {$reason}" : 'Alasan penolakan tidak dicantumkan.'
            );
            self::sendSafe($requester->email, $data);
        }
    }

    /**
     * 6. TRIGGER: Request Cancelled.
     */
    public static function sendRequestCancelled(VehicleRequest $request, ?string $reason = null): void
    {
        $request->loadMissing(['user', 'department', 'assignments.driver']);
        $requesterName = $request->user ? $request->user->name : 'Pemohon';

        // Notify GAHRD Coordinators
        $gaUsers = User::whereHas('roles', function ($q) {
            $q->whereIn('name', ['gahrd', 'GA Coordinator']);
        })->get();

        foreach ($gaUsers as $ga) {
            if ($ga->email) {
                $data = self::buildCommonData(
                    $request,
                    $ga->name,
                    'PERMOHONAN DIBATALKAN',
                    '#64748b',
                    "⚠️ [OVMS] Permohonan Armada #REQ-{$request->id} Telah Dibatalkan",
                    "Pemberitahuan: Permohonan kendaraan #REQ-{$request->id} dari {$requesterName} telah DIBATALKAN. Jadwal armada/driver terkait telah dibebaskan kembali.",
                    $reason ? "Alasan Pembatalan: {$reason}" : 'Dibatalkan oleh pengguna.'
                );
                self::sendSafe($ga->email, $data);
            }
        }

        // Notify Driver if assigned
        if ($request->assignments && $request->assignments->isNotEmpty()) {
            foreach ($request->assignments as $asg) {
                if ($asg->driver && $asg->driver->email) {
                    $data = self::buildCommonData(
                        $request,
                        $asg->driver->name,
                        'TUGAS DIBATALKAN',
                        '#64748b',
                        "⚠️ [OVMS] Penugasan Perjalanan #REQ-{$request->id} Dibatalkan",
                        "Pemberitahuan: Penugasan perjalanan dinas #REQ-{$request->id} telah DIBATALKAN. Anda tidak perlu melakukan perjalanan ini.",
                        $reason ? "Alasan Pembatalan: {$reason}" : 'Dibatalkan oleh sistem.'
                    );
                    self::sendSafe($asg->driver->email, $data);
                }
            }
        }
    }
}
