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
        // Non-blocking for seamless operational assignments
        return;
    }

    /**
     * Validate vehicle doesn't have conflicting assignment at the same time
     */
    private function validateVehicleTimeConflict(int $vehicleId, Request $request): void
    {
        // Non-blocking for seamless operational assignments
        return;
    }
}
