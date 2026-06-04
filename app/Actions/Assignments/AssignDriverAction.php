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
    public function execute(Request $request, int $driverId, ?string $notes = null): Assignment
    {
        return DB::transaction(function () use ($request, $driverId, $notes) {
            // Lock request row to prevent race condition
            $request = Request::where('id', $request->id)->lockForUpdate()->first();

            if (!in_array($request->status, [RequestStatus::APPROVED_HRD, RequestStatus::APPROVED_HRD_GA], true)) {
                throw new Exception("Request must be approved by HRD first before assigning a driver.");
            }

            // Validate driver availability status
            $driver = \App\Models\User::findOrFail($driverId);
            if (($driver->availability_status ?? 'available') !== 'available') {
                throw new Exception("Driver yang dipilih sedang tidak tersedia atau sedang bertugas.");
            }

            // ===== VALIDATE DRIVER TIME CONFLICT =====
            $this->validateDriverTimeConflict($driverId, $request);

            // ===== VALIDATE VEHICLE TIME CONFLICT =====
            // Vehicle akan di-assign sebelum trip dimulai, tapi kita perlu cek jika sudah ada vehicle_id
            if ($request->vehicle_id) {
                $this->validateVehicleTimeConflict($request->vehicle_id, $request);
            }

            // Create assignment
            $assignment = Assignment::create([
                'request_id' => $request->id,
                'driver_id' => $driverId,
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
                'status' => 'pending_driver', // waiting for driver to accept/reject
                'notes' => $notes,
            ]);

            // Update request fields and status
            $request->update([
                'status' => RequestStatus::WAITING_DRIVER,
                'driver_id' => $driverId,
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
                'driver_response_status' => 'pending_driver',
            ]);

            return $assignment;
        });
    }

    /**
     * Validate driver doesn't have conflicting assignment at the same time
     */
    private function validateDriverTimeConflict(int $driverId, Request $request): void
    {
        // Skip conflict check if end_time is not set
        if (is_null($request->end_time)) {
            return;
        }

        $conflictingRequests = Request::where('driver_id', $driverId)
            ->whereIn('status', [
                RequestStatus::WAITING_DRIVER,
                RequestStatus::DRIVER_ASSIGNED,
                RequestStatus::ON_GOING,
            ])
            ->where(function ($query) use ($request) {
                // Check if time overlaps
                $query->whereBetween('start_time', [$request->start_time, $request->end_time])
                    ->orWhereBetween('end_time', [$request->start_time, $request->end_time])
                    ->orWhere(function ($q) use ($request) {
                        // Request completely contains existing request
                        $q->where('start_time', '<=', $request->start_time)
                          ->where('end_time', '>=', $request->end_time);
                    });
            })
            ->get();

        if ($conflictingRequests->isNotEmpty()) {
            $conflicts = $conflictingRequests->map(function ($r) {
                return "({$r->start_time->format('d-m-Y H:i')} - {$r->end_time->format('d-m-Y H:i')})";
            })->join(', ');
            
            throw new Exception(
                "Driver sudah memiliki assignment pada waktu yang sama: {$conflicts}. " .
                "Silakan pilih driver lain atau ubah jadwal."
            );
        }
    }

    /**
     * Validate vehicle doesn't have conflicting assignment at the same time
     */
    private function validateVehicleTimeConflict(int $vehicleId, Request $request): void
    {
        // Skip conflict check if end_time is not set
        if (is_null($request->end_time)) {
            return;
        }

        $conflictingRequests = Request::where('vehicle_id', $vehicleId)
            ->whereIn('status', [
                RequestStatus::WAITING_DRIVER,
                RequestStatus::DRIVER_ASSIGNED,
                RequestStatus::ON_GOING,
            ])
            ->where(function ($query) use ($request) {
                // Check if time overlaps
                $query->whereBetween('start_time', [$request->start_time, $request->end_time])
                    ->orWhereBetween('end_time', [$request->start_time, $request->end_time])
                    ->orWhere(function ($q) use ($request) {
                        // Request completely contains existing request
                        $q->where('start_time', '<=', $request->start_time)
                          ->where('end_time', '>=', $request->end_time);
                    });
            })
            ->get();

        if ($conflictingRequests->isNotEmpty()) {
            $conflicts = $conflictingRequests->map(function ($r) {
                return "({$r->start_time->format('d-m-Y H:i')} - {$r->end_time->format('d-m-Y H:i')})";
            })->join(', ');
            
            throw new Exception(
                "Kendaraan sudah memiliki assignment pada waktu yang sama: {$conflicts}. " .
                "Silakan pilih kendaraan lain atau ubah jadwal."
            );
        }
    }
}
