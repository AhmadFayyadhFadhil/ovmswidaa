<?php

namespace App\Actions\Assignments;

use App\Models\Assignment;
use App\Models\OperationalTrip;
use App\Enums\RequestStatus;
use Illuminate\Support\Facades\DB;
use Exception;

class DriverRespondAction
{
    public function execute(Assignment $assignment, string $response, ?int $vehicleId = null, ?string $rejectReason = null, ?string $startPhotoPath = null, ?string $endPhotoPath = null)
    {
        return DB::transaction(function () use ($assignment, $response, $vehicleId, $rejectReason, $startPhotoPath, $endPhotoPath) {
            $assignment = Assignment::where('id', $assignment->id)->lockForUpdate()->first();

            if ($assignment->status !== 'pending_driver') {
                throw new Exception("This assignment has already been responded to.");
            }

            if ($response === 'rejected') {
                if (empty($rejectReason)) {
                    throw new Exception("Reject reason is required when rejecting an assignment.");
                }

                $assignment->update([
                    'status' => 'rejected',
                    'reject_reason' => $rejectReason,
                    'start_photo' => $startPhotoPath,
                    'end_photo' => $endPhotoPath,
                ]);

                // Revert request status to SUBMITTED so GA can re-assign
                $assignment->request()->update([
                    'status' => RequestStatus::SUBMITTED,
                    'driver_id' => null,
                    'assigned_by' => null,
                    'assigned_at' => null,
                    'rejected_reason' => $rejectReason,
                    'driver_response_status' => 'rejected',
                    'qr_code_token' => null,
                ]);

                // Set driver availability back to available
                $assignment->driver()->update(['availability_status' => 'available']);

                return $assignment;
            }

            if ($response === 'accepted') {
                $vehicleId = $vehicleId ?? $assignment->request->vehicle_id;
                
                if (empty($vehicleId)) {
                    throw new Exception("Vehicle ID is required when accepting an assignment.");
                }

                // Verify vehicle status
                $vehicle = \App\Models\Vehicle::findOrFail($vehicleId);
                if ($vehicle->status !== 'Available') {
                    throw new Exception("Kendaraan yang dipilih sedang tidak tersedia atau sedang digunakan.");
                }

                $assignment->update([
                    'status' => 'accepted',
                    'start_photo' => $startPhotoPath,
                    'end_photo' => $endPhotoPath,
                ]);

                // Create the operational trip schedule
                $trip = OperationalTrip::create([
                    'request_id' => $assignment->request_id,
                    'driver_id' => $assignment->driver_id,
                    'vehicle_id' => $vehicleId,
                    'start_datetime' => $assignment->request->start_time,
                    'end_datetime' => $assignment->request->end_time,
                    'status' => 'scheduled',
                ]);

                // Check if ALL assignments for this request are now accepted
                $request = $assignment->request;
                $pendingCount = $request->assignments()
                    ->where('status', 'pending_driver')
                    ->count();

                if ($pendingCount === 0) {
                    // All drivers accepted → advance directly to DRIVER_ASSIGNED (Approved)
                    $request->update([
                        'status' => RequestStatus::DRIVER_ASSIGNED,
                        'vehicle_id' => $vehicleId,
                        'driver_response_status' => 'accepted',
                        'qr_code_token' => 'REQ-' . time() . '-' . bin2hex(random_bytes(4)),
                    ]);
                } else {
                    // Still waiting for other drivers, just update vehicle_id
                    $request->update([
                        'vehicle_id' => $vehicleId,
                    ]);
                }
                
                return $trip;
            }

            throw new Exception("Invalid response type.");
        });
    }
}
