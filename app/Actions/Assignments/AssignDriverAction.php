<?php

namespace App\Actions\Assignments;

use App\Models\Request;
use App\Models\Assignment;
use App\Models\Vehicle;
use App\Enums\RequestStatus;
use Illuminate\Support\Facades\DB;
use Exception;

class AssignDriverAction
{
    public function execute(Request $request, int $driverId, int $vehicleId, ?string $notes = null, array $data = []): Assignment
    {
        return DB::transaction(function () use ($request, $driverId, $vehicleId, $notes, $data) {
            $allowed = [
                RequestStatus::SUBMITTED,
                RequestStatus::APPROVED_DEPARTMENT,
                RequestStatus::WAITING_DRIVER,
                RequestStatus::DRIVER_ASSIGNED,
            ];
            if ($request->status === RequestStatus::DRIVER_ASSIGNED) {
                $hasAssignments = Assignment::where('request_id', $request->id)->exists();
                if ($hasAssignments) {
                    throw new Exception("Request tidak dapat dijadwalkan dalam status ini.");
                }
            } elseif (!in_array($request->status, $allowed, true)) {
                throw new Exception("Request tidak dapat dijadwalkan dalam status ini.");
            }

            // Validate driver availability status
            $driver = \App\Models\User::findOrFail($driverId);
            if (($driver->availability_status ?? 'available') !== 'available') {
                throw new Exception("Driver {$driver->name} sedang tidak tersedia atau sedang bertugas.");
            }

            // Validate driver shift/work hours (status available dari jam sekian sampai sekian)
            if ($driver->availability_start && $driver->availability_end) {
                $reqTime = date('H:i:s', strtotime($request->start_time));
                if ($reqTime < $driver->availability_start || $reqTime > $driver->availability_end) {
                    throw new Exception("Waktu keberangkatan request ({$reqTime}) di luar jam kerja Driver {$driver->name} ({$driver->availability_start} - {$driver->availability_end}).");
                }
            }

            // ===== VALIDATE DRIVER TIME CONFLICT =====
            $this->validateDriverTimeConflict($driverId, $request);

            // ===== VALIDATE VEHICLE TIME CONFLICT =====
            $this->validateVehicleTimeConflict($vehicleId, $request);

            // Create assignment
            $priorityVal = $request->priority instanceof \App\Enums\RequestPriority 
                ? $request->priority->value 
                : ($request->priority->value ?? $request->priority);

            $isUrgent = in_array($priorityVal, ['Urgent', 'Critical'], true);
            $asgStatus = $isUrgent ? 'accepted' : 'pending_driver';

            $assignment = Assignment::create([
                'request_id' => $request->id,
                'driver_id' => $driverId,
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
                'status' => $asgStatus,
                'notes' => $notes,
            ]);

            $reqStatus = $isUrgent ? RequestStatus::DRIVER_ASSIGNED : RequestStatus::WAITING_DRIVER;
            $qrCodeToken = $request->qr_code_token;
            if ($isUrgent && !$qrCodeToken) {
                $qrCodeToken = 'REQ-' . time() . '-' . bin2hex(random_bytes(4));
            }

            // Update request fields and status
            $request->update([
                'status' => $reqStatus,
                'driver_id' => $driverId,
                'vehicle_id' => $vehicleId,
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
                'is_external' => false,
                'third_party_cost' => 0,
                'estimated_duration' => $data['estimated_duration'] ?? $request->estimated_duration,
                'priority' => $data['priority'] ?? $request->priority->value ?? 'Normal',
                'driver_response_status' => $asgStatus,
                'qr_code_token' => $qrCodeToken,
            ]);

            if ($isUrgent) {
                // Create OperationalTrip directly
                \App\Models\OperationalTrip::create([
                    'request_id' => $request->id,
                    'driver_id' => $driverId,
                    'vehicle_id' => $vehicleId,
                    'start_datetime' => $request->start_time,
                    'end_datetime' => $request->end_time,
                    'status' => 'scheduled',
                ]);
            }

            return $assignment;
        });
    }

    /**
     * Validate driver doesn't have conflicting assignment at the same time
     */
    private function validateDriverTimeConflict(int $driverId, Request $request): void
    {
        $driver = \App\Models\User::find($driverId);
        $driverName = $driver ? $driver->name : 'yang bersangkutan';
        $reqDate = date('Y-m-d', strtotime($request->start_time));

        $conflictingRequests = Request::where('driver_id', $driverId)
            ->where('id', '!=', $request->id)
            ->whereIn('status', [
                RequestStatus::WAITING_DRIVER,
                RequestStatus::DRIVER_ASSIGNED,
                RequestStatus::ON_GOING,
            ])
            ->whereDate('start_time', $reqDate)
            ->get();

        if ($conflictingRequests->isNotEmpty()) {
            throw new Exception(
                "Driver {$driverName} sudah memiliki assignment aktif pada tanggal yang sama. Silakan pilih driver lain yang tersedia."
            );
        }
    }

    /**
     * Validate vehicle doesn't have conflicting assignment at the same time
     */
    private function validateVehicleTimeConflict(int $vehicleId, Request $request): void
    {
        $vehicle = \App\Models\Vehicle::find($vehicleId);
        $vehiclePlate = $vehicle ? "{$vehicle->model} ({$vehicle->plate})" : 'yang bersangkutan';
        $reqDate = date('Y-m-d', strtotime($request->start_time));

        $conflictingRequests = Request::where('vehicle_id', $vehicleId)
            ->where('id', '!=', $request->id)
            ->whereIn('status', [
                RequestStatus::WAITING_DRIVER,
                RequestStatus::DRIVER_ASSIGNED,
                RequestStatus::ON_GOING,
            ])
            ->whereDate('start_time', $reqDate)
            ->get();

        if ($conflictingRequests->isNotEmpty()) {
            throw new Exception(
                "Kendaraan {$vehiclePlate} sudah memiliki assignment pada tanggal yang sama. Silakan pilih kendaraan lain yang tersedia."
            );
        }
    }
}
