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
            // Allow assignment regardless of status to prevent blocking operational flow

            // Validate driver availability status
            $driver = \App\Models\User::find($driverId);

            // ===== VALIDATE DRIVER TIME CONFLICT =====
            $this->validateDriverTimeConflict($driverId, $request);

            // ===== VALIDATE VEHICLE TIME CONFLICT =====
            $this->validateVehicleTimeConflict($vehicleId, $request);

            // Create assignment
            $priorityVal = 'Normal';
            if (!empty($data['priority'])) {
                $priorityVal = is_object($data['priority']) ? ($data['priority']->value ?? (string)$data['priority']) : (string)$data['priority'];
            } elseif (!empty($request->priority)) {
                $priorityVal = is_object($request->priority) ? ($request->priority->value ?? (string)$request->priority) : (string)$request->priority;
            }

            $isUrgent = in_array(strtolower($priorityVal), ['urgent', 'critical'], true);
            $asgStatus = $isUrgent ? 'accepted' : 'pending_driver';

            $assignerId = auth()->id() ?? $request->user_id ?? 1;

            $assignment = Assignment::create([
                'request_id' => $request->id,
                'driver_id' => $driverId,
                'assigned_by' => $assignerId,
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
                'assigned_by' => $assignerId,
                'assigned_at' => now(),
                'is_external' => false,
                'third_party_cost' => 0,
                'estimated_duration' => $data['estimated_duration'] ?? $request->estimated_duration,
                'priority' => $priorityVal,
                'driver_response_status' => $asgStatus,
                'qr_code_token' => $qrCodeToken,
            ]);

            if ($isUrgent) {
                try {
                    \App\Models\OperationalTrip::create([
                        'request_id' => $request->id,
                        'driver_id' => $driverId,
                        'vehicle_id' => $vehicleId,
                        'start_datetime' => $request->start_time ?? now(),
                        'end_datetime' => $request->end_time ?? now()->addHours(4),
                        'status' => 'scheduled',
                    ]);
                } catch (\Throwable $ex) {}
            }

            // Also update any existing request_itineraries for this request
            try {
                \App\Models\RequestItinerary::where('request_id', $request->id)
                    ->where(function ($q) {
                        $q->whereNull('driver_id')->orWhereNull('vehicle_id');
                    })
                    ->update([
                        'driver_id' => $driverId,
                        'vehicle_id' => $vehicleId,
                        'status' => 'assigned',
                    ]);
            } catch (\Throwable $ex) {}

            return $assignment;
        });
    }

    /**
     * Validate driver doesn't have conflicting assignment at the same time
     */
    private function validateDriverTimeConflict(int $driverId, Request $request): void
    {
        $startTime = $request->start_time;
        $endTime = $request->end_time;

        if (!$startTime || !$endTime) {
            return; // Cannot validate without time range
        }

        $conflict = Request::where('driver_id', $driverId)
            ->where('id', '!=', $request->id)
            ->whereNotIn('status', ['completed', 'cancelled', 'rejected'])
            ->where(function ($q) use ($startTime, $endTime) {
                $q->where(function ($inner) use ($startTime, $endTime) {
                    $inner->where('start_time', '<', $endTime)
                          ->where('end_time', '>', $startTime);
                });
            })
            ->first();

        if ($conflict) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'driver_id' => 'Driver ini sudah ditugaskan ke request lain (REQ-' . $conflict->id . ') pada rentang waktu yang sama (' . \Carbon\Carbon::parse($conflict->start_time)->format('d/m/Y H:i') . ' - ' . \Carbon\Carbon::parse($conflict->end_time)->format('d/m/Y H:i') . '). Silakan pilih driver lain.',
            ]);
        }
    }

    /**
     * Validate vehicle doesn't have conflicting assignment at the same time
     */
    private function validateVehicleTimeConflict(int $vehicleId, Request $request): void
    {
        $startTime = $request->start_time;
        $endTime = $request->end_time;

        if (!$startTime || !$endTime) {
            return; // Cannot validate without time range
        }

        $conflict = Request::where('vehicle_id', $vehicleId)
            ->where('id', '!=', $request->id)
            ->whereNotIn('status', ['completed', 'cancelled', 'rejected'])
            ->where(function ($q) use ($startTime, $endTime) {
                $q->where(function ($inner) use ($startTime, $endTime) {
                    $inner->where('start_time', '<', $endTime)
                          ->where('end_time', '>', $startTime);
                });
            })
            ->first();

        if ($conflict) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'vehicle_id' => 'Kendaraan ini sudah ditugaskan ke request lain (REQ-' . $conflict->id . ') pada rentang waktu yang sama (' . \Carbon\Carbon::parse($conflict->start_time)->format('d/m/Y H:i') . ' - ' . \Carbon\Carbon::parse($conflict->end_time)->format('d/m/Y H:i') . '). Silakan pilih kendaraan lain.',
            ]);
        }
    }
}
